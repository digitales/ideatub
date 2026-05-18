<?php

namespace App\Console\Commands;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\ThoughtCaptureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;

class ImportWorkingMemoryCapturesCommand extends Command
{
    protected $signature = 'working-memory:import-captures
        {--user= : Numeric user id (required)}
        {--project= : Client slug stored in source_metadata.project (required)}
        {--path= : Directory containing markdown files (required)}
        {--kind=slack : Import kind: slack, automation, or meeting}
        {--project-id= : Optional IdeaTub project UUID to link thoughts}
        {--rate=50 : Maximum files imported per minute}
        {--dry-run : List files without importing}
        {--consolidate-after : Queue one consolidated rebuild for --project-id when finished}';

    protected $description = 'Bulk-import markdown captures (Slack summaries, automations, meetings) for working memory corpus growth.';

    public function __construct(
        private readonly ThoughtCaptureService $captureService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = $this->resolveUserId();
        $projectSlug = $this->resolveProjectSlug();
        $path = $this->resolvePath();
        $kind = $this->resolveKind();
        $rate = max(1, (int) $this->option('rate'));
        $dryRun = (bool) $this->option('dry-run');
        $projectId = $this->resolveProjectId();

        $files = $this->discoverMarkdownFiles($path);
        if ($files === []) {
            $this->warn("No markdown files found under {$path}.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d file(s) (%s, project=%s, user=%d).',
            count($files),
            $kind,
            $projectSlug,
            $userId,
        ));

        if ($dryRun) {
            foreach ($files as $file) {
                $this->line('  '.$file);
            }
            $this->comment('Dry run: no thoughts created.');

            return self::SUCCESS;
        }

        $intervalSeconds = 60.0 / $rate;
        $imported = 0;
        $failed = 0;

        foreach ($files as $index => $filePath) {
            if ($index > 0) {
                usleep((int) round($intervalSeconds * 1_000_000));
            }

            try {
                $import = function () use ($userId, $projectSlug, $kind, $filePath, $projectId): void {
                    $this->importFile($userId, $projectSlug, $kind, $filePath, $projectId);
                };
                if ($kind === 'meeting') {
                    $import();
                } else {
                    Thought::withoutEvents($import);
                }
                $imported++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn("Failed {$filePath}: {$exception->getMessage()}");
            }
        }

        $this->info("Imported: {$imported}, failed: {$failed}.");

        if ($this->option('consolidate-after') && $projectId !== null) {
            ConsolidateWorkingMemory::dispatch($userId, 'project', $projectId);
            $this->info("Queued consolidated rebuild for project {$projectId}.");
        }

        return self::SUCCESS;
    }

    private function resolveUserId(): int
    {
        $raw = $this->option('user');
        if (! is_string($raw) || trim($raw) === '' || ! ctype_digit(trim($raw))) {
            throw new InvalidArgumentException('The --user option is required and must be a numeric user id.');
        }

        $userId = (int) trim($raw);
        if (! User::query()->whereKey($userId)->exists()) {
            throw new InvalidArgumentException("User {$userId} does not exist.");
        }

        return $userId;
    }

    private function resolveProjectSlug(): string
    {
        $raw = $this->option('project');
        if (! is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('The --project option is required (client slug for metadata).');
        }

        return Str::of($raw)->trim()->lower()->toString();
    }

    private function resolvePath(): string
    {
        $raw = $this->option('path');
        if (! is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('The --path option is required.');
        }

        $path = realpath(trim($raw));
        if ($path === false || ! is_dir($path)) {
            throw new InvalidArgumentException("Path does not exist or is not a directory: {$raw}");
        }

        return $path;
    }

    private function resolveKind(): string
    {
        $kind = Str::of((string) $this->option('kind'))->trim()->lower()->toString();
        if (! in_array($kind, ['slack', 'automation', 'meeting'], true)) {
            throw new InvalidArgumentException('The --kind option must be one of: slack, automation, meeting.');
        }

        return $kind;
    }

    private function resolveProjectId(): ?string
    {
        $raw = $this->option('project-id');
        if ($raw === null || ! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $normalized = Str::of($raw)->trim()->lower()->toString();
        if (! Str::isUuid($normalized)) {
            throw new InvalidArgumentException('The --project-id option must be a valid UUID.');
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function discoverMarkdownFiles(string $directory): array
    {
        $finder = Finder::create()
            ->files()
            ->in($directory)
            ->name('*.md')
            ->sortByName();

        return array_values(array_map(
            static fn (\SplFileInfo $file): string => $file->getPathname(),
            iterator_to_array($finder),
        ));
    }

    private function importFile(int $userId, string $projectSlug, string $kind, string $filePath, ?string $projectId): void
    {
        $content = trim(File::get($filePath));
        if ($content === '') {
            throw new InvalidArgumentException('File is empty.');
        }

        $basename = pathinfo($filePath, PATHINFO_FILENAME);
        $planSlug = Str::of($basename)->lower()->replace([' ', '_'], '-')->squish()->toString();
        $sectionTitle = Str::of($basename)->replace(['-', '_'], ' ')->squish()->title()->toString();
        $relativePath = $filePath;

        $docType = $kind === 'meeting' ? 'meeting' : 'plan';
        $extraTags = $this->tagsForKind($kind, $projectSlug, $basename, $filePath);
        $source = $docType;

        $sourceMetadata = array_filter([
            'project' => $projectSlug,
            'file_path' => $relativePath,
            'plan_slug' => $planSlug,
            'section_title' => $sectionTitle,
            'import_kind' => $kind,
        ]);

        $result = $this->captureService->create([
            'content' => $content,
            'user_id' => $userId,
            'source' => $source,
            'source_metadata' => $sourceMetadata,
            'no_chunking' => true,
            'plan_slug' => $planSlug,
            'doc_type' => $docType,
            'file_path' => $relativePath,
            'project' => $projectSlug,
            'extra_tags' => $extraTags,
            'skip_ai_metadata' => true,
        ]);

        $thought = $result['thought'] ?? $result['root'] ?? null;
        if ($thought instanceof Thought && $projectId !== null) {
            $project = Project::query()
                ->whereKey($projectId)
                ->where('user_id', $userId)
                ->first();
            if ($project !== null) {
                $thought->projects()->syncWithoutDetaching([$project->id]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tagsForKind(string $kind, string $projectSlug, string $basename, string $filePath): array
    {
        $clientTag = 'client:'.$projectSlug;

        if ($kind === 'meeting') {
            return [$clientTag, 'meeting-import'];
        }

        if ($kind === 'automation') {
            return ['automation', $clientTag];
        }

        $channel = $this->inferSlackChannel($basename, $filePath);

        return array_values(array_filter([
            'slack',
            $channel !== null ? 'channel:'.$channel : null,
            $clientTag,
        ]));
    }

    private function inferSlackChannel(string $basename, string $filePath): ?string
    {
        $haystack = Str::lower($basename.' '.$filePath);
        if (preg_match('/client-([a-z0-9-]+)/', $haystack, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
