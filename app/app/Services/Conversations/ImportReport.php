<?php

namespace App\Services\Conversations;

/**
 * Mutable tally of what one import run did.
 *
 * The counters exist so an import produces a statement a person can check
 * rather than a spinner that eventually stops. The distinction between new,
 * updated, and unchanged is the part that matters: it is what makes repeated
 * imports legible instead of frightening, and it is the observable proof that
 * the idempotency rules are working.
 */
final class ImportReport
{
    public int $conversationsSeen = 0;

    public int $conversationsNew = 0;

    public int $conversationsUpdated = 0;

    public int $conversationsUnchanged = 0;

    public int $conversationsSkipped = 0;

    public int $messagesSeen = 0;

    public int $messagesNew = 0;

    public int $messagesUpdated = 0;

    public int $messagesUnchanged = 0;

    public int $messagesSkipped = 0;

    public int $rawRecordsStored = 0;

    public int $rawRecordsAlreadyPresent = 0;

    public int $redactedMessages = 0;

    /** @var array<int, string> */
    private array $warnings = [];

    private int $suppressedWarnings = 0;

    public function warn(string $message): void
    {
        if ($message === '') {
            return;
        }

        if (in_array($message, $this->warnings, true)) {
            return;
        }

        // A systematically malformed archive would otherwise produce one warning
        // per record. The count of what was suppressed is still reported.
        if (count($this->warnings) >= 200) {
            $this->suppressedWarnings++;

            return;
        }

        $this->warnings[] = $message;
    }

    /**
     * @param  array<int, string>  $messages
     */
    public function warnAll(array $messages): void
    {
        foreach ($messages as $message) {
            if (is_string($message)) {
                $this->warn($message);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        if ($this->suppressedWarnings === 0) {
            return $this->warnings;
        }

        return [...$this->warnings, "{$this->suppressedWarnings} further distinct warning(s) were suppressed."];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'conversations_seen' => $this->conversationsSeen,
            'conversations_new' => $this->conversationsNew,
            'conversations_updated' => $this->conversationsUpdated,
            'conversations_unchanged' => $this->conversationsUnchanged,
            'conversations_skipped' => $this->conversationsSkipped,
            'messages_seen' => $this->messagesSeen,
            'messages_new' => $this->messagesNew,
            'messages_updated' => $this->messagesUpdated,
            'messages_unchanged' => $this->messagesUnchanged,
            'messages_skipped' => $this->messagesSkipped,
            'raw_records_stored' => $this->rawRecordsStored,
            'raw_records_already_present' => $this->rawRecordsAlreadyPresent,
            'redacted_messages' => $this->redactedMessages,
        ];
    }

    /**
     * Lines an operator can read without decoding a JSON blob.
     *
     * @return array<int, string>
     */
    public function summaryLines(): array
    {
        return [
            "{$this->conversationsSeen} conversations detected",
            "{$this->messagesSeen} messages normalized",
            "{$this->conversationsNew} new conversations",
            "{$this->conversationsUnchanged} already known",
            "{$this->conversationsUpdated} conversations changed",
            "{$this->rawRecordsStored} raw source records stored",
            "{$this->conversationsSkipped} records skipped",
        ];
    }
}
