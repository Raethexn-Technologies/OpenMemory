<?php

namespace App\Console\Commands;

use App\Services\Conversations\Archive\ArchiveException;
use App\Services\Conversations\ConversationImportService;
use App\Services\Conversations\NormalizedConversation;
use Illuminate\Console\Command;

/**
 * Imports a provider conversation archive from a local path.
 *
 * Import is a command rather than a web upload on purpose. These archives run to
 * hundreds of megabytes and hold the most sensitive file most people own; moving
 * one through a browser upload would add a copy, a temporary file, and a request
 * body for no benefit. The command reads the archive where it already sits, and
 * the browser is used for reviewing what was imported.
 */
class ImportConversationArchive extends Command
{
    protected $signature = 'memory:import-archive
        {path : Path to the export ZIP, extracted folder, or a single conversations.json}
        {--user= : Owner identity for the imported history. Defaults to OPENMEMORY_LOCAL_USER_ID.}
        {--provider= : Force a provider (chatgpt, claude, gemini) instead of detecting one.}
        {--dry-run : Parse and report without writing anything.}
        {--limit= : Stop after this many conversations. Useful for a first look at a large archive.}
        {--no-raw : Skip storing the preserved provider JSON for each conversation.}
        {--json : Emit the report as JSON.}';

    protected $description = 'Import ChatGPT, Claude, or Gemini conversation history into the local corpus';

    public function handle(ConversationImportService $importer): int
    {
        $path = (string) $this->argument('path');
        $userId = $this->resolveUserId();

        if ($userId === null) {
            $this->error('No owner identity. Pass --user or set OPENMEMORY_LOCAL_USER_ID in .env.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        if (! $this->option('json')) {
            $this->line($dryRun ? 'Reading archive (dry run, nothing will be written)...' : 'Reading archive...');
        }

        $progress = null;

        if (! $this->option('json') && ! $this->output->isQuiet()) {
            $progress = function (int $seen, NormalizedConversation $conversation): void {
                if ($seen % 100 === 0) {
                    $this->line("  {$seen} conversations read...");
                }
            };
        }

        try {
            $result = $importer->importPath($userId, $path, [
                'provider' => $this->option('provider') ?: null,
                'dry_run' => $dryRun,
                'limit' => $limit,
                'store_raw' => ! $this->option('no-raw'),
                'on_progress' => $progress,
            ]);
        } catch (ArchiveException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'failed', 'error' => $exception->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        $report = $result['report'];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => 'completed',
                'provider' => $result['provider'],
                'dry_run' => $dryRun,
                'import_id' => $result['import']?->id,
                'stats' => $report->toArray(),
                'warnings' => $report->warnings(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Provider: {$result['adapter']}");

        foreach ($report->summaryLines() as $line) {
            $this->line("  {$line}");
        }

        $warnings = $report->warnings();

        if ($warnings !== []) {
            $this->newLine();
            $this->warn('Warnings:');

            foreach (array_slice($warnings, 0, 25) as $warning) {
                $this->line("  - {$warning}");
            }

            if (count($warnings) > 25) {
                $this->line('  - ' . (count($warnings) - 25) . ' more recorded on the import row.');
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Dry run. Nothing was written.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Import {$result['import']?->id} recorded. Review the history at /history.");

        return self::SUCCESS;
    }

    /**
     * Resolve the owner identity for imported history.
     *
     * The chat UI derives a user identity from Internet Identity or a session
     * fallback, neither of which a terminal command has. A stable local
     * identity in configuration is what lets CLI imports and the browser see the
     * same corpus.
     */
    private function resolveUserId(): ?string
    {
        $option = $this->option('user');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $configured = config('conversations.local_user_id');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
