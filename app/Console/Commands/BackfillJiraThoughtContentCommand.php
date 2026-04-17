<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;

class BackfillJiraThoughtContentCommand extends Command
{
    protected $signature = 'jira:backfill-thought-content {--dry-run : Show updates without writing}';

    protected $description = 'Normalize existing Jira thought content to include issue key and summary.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $unchanged = 0;

        Thought::query()
            ->where('source', 'jira')
            ->orderBy('id')
            ->chunkById(200, function ($thoughts) use ($dryRun, &$scanned, &$updated, &$skipped, &$unchanged): void {
                foreach ($thoughts as $thought) {
                    $scanned++;

                    $sourceMetadata = is_array($thought->source_metadata) ? $thought->source_metadata : [];
                    $issueKey = trim((string) ($sourceMetadata['jira_issue_key'] ?? ''));
                    $issueSummary = trim((string) ($sourceMetadata['jira_issue_summary'] ?? ''));
                    $eventType = mb_strtolower(trim((string) ($sourceMetadata['jira_event_type'] ?? '')));

                    if ($issueKey === '' || $issueSummary === '') {
                        $skipped++;

                        continue;
                    }

                    $detail = $this->extractDetail($thought->content, $issueKey, $issueSummary, $eventType);
                    $normalizedContent = $this->formatContent($issueKey, $issueSummary, $detail);

                    if ($normalizedContent === $thought->content) {
                        $unchanged++;
                        continue;
                    }

                    $updated++;
                    if ($dryRun) {
                        continue;
                    }

                    $thought->update([
                        'content' => $normalizedContent,
                    ]);
                }
            });

        if ($dryRun) {
            $this->info('Dry run complete. No writes performed.');
        }

        $this->line("Scanned: {$scanned}");
        $this->line("Updated: {$updated}");
        $this->line("Skipped: {$skipped}");
        $this->line("Unchanged: {$unchanged}");

        return self::SUCCESS;
    }

    private function formatContent(string $issueKey, string $issueSummary, ?string $detail): string
    {
        $prefix = "{$issueKey}: {$issueSummary}";
        $normalizedDetail = trim((string) $detail);

        if ($normalizedDetail === '') {
            return $prefix;
        }

        return "{$prefix} - {$normalizedDetail}";
    }

    private function extractDetail(string $content, string $issueKey, string $issueSummary, string $eventType): ?string
    {
        $raw = trim($content);
        if ($raw === '') {
            return null;
        }

        $issueKeyPattern = preg_quote($issueKey, '/');
        $summaryPattern = preg_quote($issueSummary, '/');

        if (preg_match("/^{$issueKeyPattern}:\\s*{$summaryPattern}\\s*-\\s*(.+)$/us", $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match("/^{$issueKeyPattern}:\\s*{$summaryPattern}$/u", $raw) === 1) {
            return null;
        }

        if ($eventType === 'comment') {
            if (preg_match("/^Commented\\s+on\\s+{$issueKeyPattern}\\s*[:\\-]\\s*(.+)$/ius", $raw, $matches) === 1) {
                return 'Commented: '.trim($matches[1]);
            }

            if (preg_match('/^Commented:\\s*(.+)$/ius', $raw, $matches) === 1) {
                return 'Commented: '.trim($matches[1]);
            }
        }

        if ($eventType === 'created') {
            if (preg_match("/^Created\\s+{$issueKeyPattern}(?:\\s*[:\\-]\\s*(.+))?$/ius", $raw, $matches) === 1) {
                $suffix = trim((string) ($matches[1] ?? ''));

                return $suffix === '' ? 'Created' : 'Created '.$suffix;
            }

            if (preg_match('/^Created(?:\\s*[:\\-]\\s*(.+))?$/ius', $raw, $matches) === 1) {
                $suffix = trim((string) ($matches[1] ?? ''));

                return $suffix === '' ? 'Created' : 'Created '.$suffix;
            }
        }

        if ($eventType === 'updated') {
            if (preg_match("/^Updated\\s+{$issueKeyPattern}(?:\\s*[:\\-]\\s*(.+))?$/ius", $raw, $matches) === 1) {
                $suffix = trim((string) ($matches[1] ?? ''));

                return $suffix === '' ? 'Updated' : 'Updated '.$suffix;
            }

            if (preg_match('/^Updated(?:\\s*[:\\-]\\s*(.+))?$/ius', $raw, $matches) === 1) {
                $suffix = trim((string) ($matches[1] ?? ''));

                return $suffix === '' ? 'Updated' : 'Updated '.$suffix;
            }
        }

        if ($raw === $issueKey) {
            return null;
        }

        // Ambiguous legacy content: keep original detail payload intact.
        return $raw;
    }
}
