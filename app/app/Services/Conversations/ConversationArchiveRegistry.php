<?php

namespace App\Services\Conversations;

use App\Services\Conversations\Adapters\AdapterDetection;
use App\Services\Conversations\Adapters\ConversationArchiveAdapter;
use App\Services\Conversations\Archive\ArchiveSource;

/**
 * Resolves which adapter can read a given archive.
 *
 * Detection runs every registered adapter and picks the highest-confidence
 * supported match. When nothing supports the archive but something recognized
 * it, that adapter's reason is surfaced instead of a generic failure: knowing
 * that a Takeout export is in HTML rather than JSON is actionable, and
 * "unrecognized archive" is not.
 */
final class ConversationArchiveRegistry
{
    /** @var array<int, ConversationArchiveAdapter> */
    private array $adapters;

    /**
     * @param  array<int, ConversationArchiveAdapter>  $adapters
     */
    public function __construct(array $adapters)
    {
        $this->adapters = $adapters;
    }

    /**
     * @return array<int, ConversationArchiveAdapter>
     */
    public function adapters(): array
    {
        return $this->adapters;
    }

    /**
     * @return array<int, string>
     */
    public function providers(): array
    {
        return array_map(
            static fn (ConversationArchiveAdapter $adapter) => $adapter->provider(),
            $this->adapters,
        );
    }

    public function forProvider(string $provider): ?ConversationArchiveAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->provider() === $provider) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Pick an adapter for an archive.
     *
     * @param  string|null  $provider  Force a specific provider instead of detecting.
     * @return array{0: ConversationArchiveAdapter|null, 1: AdapterDetection}
     */
    public function detect(ArchiveSource $source, ?string $provider = null): array
    {
        if ($provider !== null) {
            $adapter = $this->forProvider($provider);

            if ($adapter === null) {
                return [null, AdapterDetection::unsupported("No adapter is registered for provider \"{$provider}\".")];
            }

            $detection = $adapter->detect($source);

            if (! $detection->supported) {
                return [null, $detection->recognized
                    ? $detection
                    : AdapterDetection::unsupported(
                        "The {$adapter->displayName()} adapter did not recognize this archive. "
                        . 'Check that the path points at the export you meant, or omit the provider option to auto-detect.',
                    )];
            }

            return [$adapter, $detection];
        }

        $best = null;
        $bestDetection = null;
        $recognizedReason = null;

        foreach ($this->adapters as $adapter) {
            $detection = $adapter->detect($source);

            if ($detection->supported) {
                if ($bestDetection === null || $detection->confidence > $bestDetection->confidence) {
                    $best = $adapter;
                    $bestDetection = $detection;
                }

                continue;
            }

            if ($detection->recognized && $detection->reason !== null && $recognizedReason === null) {
                $recognizedReason = $detection->reason;
            }
        }

        if ($best !== null && $bestDetection !== null) {
            return [$best, $bestDetection];
        }

        if ($recognizedReason !== null) {
            return [null, AdapterDetection::unsupported($recognizedReason)];
        }

        return [null, AdapterDetection::unsupported(
            'No registered adapter recognized this archive. Supported exports are ChatGPT (conversations.json), '
            . 'Claude (conversations.json), and Google Takeout Gemini Apps activity in JSON format.',
        )];
    }
}
