<?php

namespace App\Console\Commands;

use App\Models\Thought;
use App\Services\Import\MicrositeMarkdownLinkRewriter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RewriteMicrositeMarkdownLinksCommand extends Command
{
    protected $signature = 'research:microsite-rewrite-links
                            {--dry-run : Show planned updates without writing}
                            {--root= : Only process the microsite with this root thought UUID}
                            {--user= : Only microsites owned by this user ID}
                            {--limit= : Max microsite roots to process}';

    protected $description = 'Re-run microsite markdown link rewriting on stored pages (relative *.md, bracket links, and bare https://ideatub.com/… URLs → ?page=…).';

    public function __construct(
        private MicrositeMarkdownLinkRewriter $linkRewriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $roots = Thought::query()
            ->whereNull('parent_id')
            ->where('source_metadata->document_layout', 'microsite');

        $rootOpt = $this->option('root');
        if ($rootOpt !== null && $rootOpt !== '') {
            $roots->whereKey((string) $rootOpt);
        }

        $userOpt = $this->option('user');
        if ($userOpt !== null && $userOpt !== '') {
            $roots->where('user_id', (int) $userOpt);
        }

        $roots->orderBy('id');

        $limitOpt = $this->option('limit');
        if ($limitOpt !== null && $limitOpt !== '') {
            $roots->limit((int) $limitOpt);
        }

        $collection = $roots->get();

        if ($collection->isEmpty()) {
            $this->warn('No matching microsite roots found.');

            return self::SUCCESS;
        }

        $rootsProcessed = 0;
        $rootsSkipped = 0;
        $pagesUpdated = 0;
        $pagesUnchanged = 0;

        foreach ($collection as $root) {
            if (! $root instanceof Thought) {
                continue;
            }
            if (! $root->isMicrositeRoot()) {
                $this->warn('Skipping '.$root->id.': not a microsite root.');
                $rootsSkipped++;

                continue;
            }

            $map = $this->pathKeyToSegmentMapForRoot($root);
            if ($map === null) {
                $this->warn('Skipping microsite root '.$root->id.': missing file_path or page_path_segment on one or more pages.');
                $rootsSkipped++;

                continue;
            }

            $rootsProcessed++;
            $pages = $this->micrositePagesForRoot($root);

            foreach ($pages as $page) {
                $filePath = (string) data_get($page->source_metadata, 'file_path', '');
                if ($filePath === '') {
                    $this->warn('Skipping page '.$page->id.': empty file_path.');

                    continue;
                }

                $rewritten = $this->linkRewriter->rewrite(
                    (string) $page->content,
                    $filePath,
                    $map,
                    (string) $root->id,
                );
                $next = (string) $rewritten['markdown'];
                if ($next === (string) $page->content) {
                    $pagesUnchanged++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [dry-run] Would update page %s (file %s)',
                        $page->id,
                        $filePath
                    ));
                    $pagesUpdated++;

                    continue;
                }

                $page->update(['content' => $next]);
                $pagesUpdated++;
            }
        }

        $this->info(sprintf(
            'Microsite roots processed: %d | skipped: %d | pages updated: %d | pages unchanged: %d%s',
            $rootsProcessed,
            $rootsSkipped,
            $pagesUpdated,
            $pagesUnchanged,
            $dryRun ? ' (dry run)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Root plus microsite child pages in import order; non-microsite children of the same parent are excluded.
     *
     * @return Collection<int, Thought>
     */
    private function micrositePagesForRoot(Thought $root): Collection
    {
        $children = $root->childThoughtsForMicrosite()
            ->get()
            ->filter(fn (Thought $t) => $t->isMicrositeDocumentLayout());

        $ordered = Thought::sortByMicrositeImportOrder($children);

        return collect([$root])->merge($ordered);
    }

    /**
     * Same shape as MicrositeImportService path key → page_path_segment map.
     *
     * @return array<string, string>|null
     */
    private function pathKeyToSegmentMapForRoot(Thought $root): ?array
    {
        $map = [];
        foreach ($this->micrositePagesForRoot($root) as $page) {
            $fp = (string) data_get($page->source_metadata, 'file_path', '');
            $segment = (string) data_get($page->source_metadata, 'page_path_segment', '');
            if ($fp === '' || $segment === '') {
                return null;
            }
            $key = $this->linkRewriter->pathKeyForRelativePath($fp);
            if ($key === '') {
                return null;
            }
            $map[$key] = $segment;
        }

        return $map;
    }
}
