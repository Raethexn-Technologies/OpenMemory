<?php

namespace App\Services\Conversations\Json;

use App\Services\Conversations\Archive\ArchiveException;
use Generator;

/**
 * Streams the top-level elements of a JSON array without loading the whole file.
 *
 * Every provider archive in scope stores history as one enormous JSON array:
 * ChatGPT's conversations.json, Claude's conversations.json, and Takeout's
 * MyActivity.json are all arrays whose elements are independently meaningful.
 * A heavy user's export reaches hundreds of megabytes, and json_decode on the
 * whole file would expand that several times over in PHP array overhead. Peak
 * memory would scale with the size of a person's entire history, which is the
 * difference between an importer that works for real users and one that only
 * works for demo fixtures.
 *
 * This reader scans the array structurally and yields the raw text of one
 * element at a time, so peak memory scales with the largest single conversation
 * instead of the archive. The scan is string-aware and escape-aware: a brace or
 * bracket inside a JSON string never changes nesting depth.
 *
 * The reader deliberately does not validate the elements it yields. Each element
 * is handed to json_decode separately, so one malformed conversation fails on its
 * own and is reported, rather than aborting an otherwise good import.
 */
final class JsonArrayStreamReader
{
    private const CHUNK_BYTES = 262144;

    private string $buffer = '';

    private int $position = 0;

    private bool $exhausted = false;

    /**
     * Offset where the element currently being scanned began, or null between
     * elements. ensure() uses it to enforce the per-element ceiling while the
     * element is still being read, so a hostile file cannot grow the buffer
     * unbounded before the size check runs.
     */
    private ?int $elementStart = null;

    /**
     * @param  resource  $stream
     */
    public function __construct(
        private $stream,
        private readonly int $maxElementBytes = 67108864,
    ) {}

    /**
     * Yield the raw JSON text of each top-level array element in order.
     *
     * @return Generator<int, string>
     */
    public function elements(): Generator
    {
        $this->skipByteOrderMark();
        $this->skipWhitespace();

        $opening = $this->peek();

        if ($opening === null) {
            throw new ArchiveException('JSON stream is empty; expected an array.');
        }

        if ($opening !== '[') {
            throw new ArchiveException(sprintf(
                'Expected a JSON array at the top level, found "%s".',
                $opening,
            ));
        }

        $this->advance(1);

        $index = 0;

        while (true) {
            $this->skipWhitespace();
            $char = $this->peek();

            if ($char === null) {
                throw new ArchiveException('JSON array ended without a closing bracket.');
            }

            if ($char === ']') {
                $this->advance(1);

                return;
            }

            if ($char === ',') {
                $this->advance(1);

                continue;
            }

            yield $index++ => $this->readValue();
        }
    }

    /**
     * Decode the elements, skipping and reporting any that do not parse.
     *
     * @param  callable(string, int): void  $onMalformed  Receives the raw text and its index.
     * @return Generator<int, array<mixed>>
     */
    public function decodedElements(callable $onMalformed): Generator
    {
        foreach ($this->elements() as $index => $raw) {
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                $onMalformed($raw, $index);

                continue;
            }

            yield $index => $decoded;
        }
    }

    /**
     * Read exactly one complete JSON value starting at the current position.
     *
     * The three value shapes are scanned separately because their terminators
     * differ. Collapsing them into one depth counter is where naive scanners
     * break: a bare scalar has no closing delimiter of its own, so it ends on
     * the array's own comma or bracket.
     */
    private function readValue(): string
    {
        $start = $this->position;
        $this->elementStart = $start;

        try {
            $first = $this->peek();

            if ($first === null) {
                throw new ArchiveException('JSON value ended unexpectedly.');
            }

            match (true) {
                $first === '{' || $first === '[' => $this->scanStructure(),
                $first === '"' => $this->scanString(),
                default => $this->scanScalar(),
            };

            // ensure() catches an oversized element while it is still being
            // pulled off the stream. This second check catches the case where
            // the element was already fully buffered, so the ceiling holds
            // whether or not a refill happened mid-scan.
            if ($this->position - $start > $this->maxElementBytes) {
                throw new ArchiveException(sprintf(
                    'A single JSON array element exceeds the %d byte limit.',
                    $this->maxElementBytes,
                ));
            }

            $value = substr($this->buffer, $start, $this->position - $start);
        } finally {
            $this->elementStart = null;
        }

        $this->compact();

        return trim($value);
    }

    /**
     * Scan a balanced object or array, ignoring delimiters inside strings.
     */
    private function scanStructure(): void
    {
        $depth = 0;

        while (true) {
            if (! $this->ensure(1)) {
                throw new ArchiveException('JSON value ended unexpectedly.');
            }

            $char = $this->buffer[$this->position];

            if ($char === '"') {
                $this->scanString();

                continue;
            }

            $this->position++;

            if ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;

                if ($depth === 0) {
                    return;
                }

                if ($depth < 0) {
                    throw new ArchiveException('Unbalanced JSON structure in array element.');
                }
            }
        }
    }

    /**
     * Scan one JSON string literal, honouring backslash escapes.
     */
    private function scanString(): void
    {
        if (! $this->ensure(1) || $this->buffer[$this->position] !== '"') {
            throw new ArchiveException('Expected the start of a JSON string.');
        }

        $this->position++;
        $escaped = false;

        while (true) {
            if (! $this->ensure(1)) {
                throw new ArchiveException('JSON string was not terminated.');
            }

            $char = $this->buffer[$this->position];
            $this->position++;

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                return;
            }
        }
    }

    /**
     * Scan a bare scalar: a number, true, false, or null.
     *
     * These carry no terminator, so the scan stops at the first character that
     * can only belong to the enclosing array.
     */
    private function scanScalar(): void
    {
        while ($this->ensure(1)) {
            $char = $this->buffer[$this->position];

            if ($char === ',' || $char === ']' || $char === '}' || $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
                return;
            }

            $this->position++;
        }

        throw new ArchiveException('JSON array ended without a closing bracket.');
    }

    private function peek(): ?string
    {
        return $this->ensure(1) ? $this->buffer[$this->position] : null;
    }

    private function advance(int $count): void
    {
        $this->position += $count;
        $this->compact();
    }

    private function skipWhitespace(): void
    {
        while ($this->ensure(1)) {
            $char = $this->buffer[$this->position];

            if ($char !== ' ' && $char !== "\n" && $char !== "\r" && $char !== "\t") {
                return;
            }

            $this->position++;
        }
    }

    private function skipByteOrderMark(): void
    {
        if ($this->ensure(3) && substr($this->buffer, $this->position, 3) === "\xEF\xBB\xBF") {
            $this->advance(3);
        }
    }

    /**
     * Guarantee at least $count bytes are available from the current position.
     */
    private function ensure(int $count): bool
    {
        while (strlen($this->buffer) - $this->position < $count) {
            if ($this->elementStart !== null && $this->position - $this->elementStart > $this->maxElementBytes) {
                throw new ArchiveException(sprintf(
                    'A single JSON array element exceeds the %d byte limit.',
                    $this->maxElementBytes,
                ));
            }

            if ($this->exhausted) {
                return false;
            }

            $chunk = fread($this->stream, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                $this->exhausted = true;

                return strlen($this->buffer) - $this->position >= $count;
            }

            $this->buffer .= $chunk;
        }

        return true;
    }

    /**
     * Drop consumed bytes so the buffer does not grow with the file.
     */
    private function compact(): void
    {
        if ($this->position > self::CHUNK_BYTES) {
            $this->buffer = substr($this->buffer, $this->position);
            $this->position = 0;
        }
    }
}
