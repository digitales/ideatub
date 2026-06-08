@php
    $payload = is_array($workingMemoryPayload ?? null) ? $workingMemoryPayload : null;
    $refreshAction = route('working-memory.refresh.project', $project);
    $hasPayload = $payload !== null;
    $navBtnClass = 'inline-flex items-center rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet transition-colors hover:bg-memory-violet/5 hover:text-memory-violet/80';
    $actionBtnClass = $navBtnClass;
    $forceBtnClass = 'inline-flex items-center rounded-lg border border-deep-indigo/[0.08] px-3 py-1.5 text-xs font-medium text-slate-brand transition-colors hover:bg-white hover:text-deep-indigo';

    if ($hasPayload) {
        $freshness = $payload['freshness_state'] ?? 'stale';
        $freshnessClasses = match ($freshness) {
            'fresh' => 'bg-neural-teal/15 text-neural-teal border-neural-teal/30',
            'degraded' => 'bg-amber-50 text-amber-900 border-amber-200/80',
            default => 'bg-slate-100 text-slate-600 border-slate-200',
        };
        $structuredSections = is_array($payload['structured_sections'] ?? null) ? $payload['structured_sections'] : [];
        $authoringStatus = $payload['authoring_status'] ?? null;
        $renderStructuredSections = $structuredSections !== []
            && ($authoringStatus === null || $authoringStatus === 'validated' || $authoringStatus === 'external');
        $references = is_array($payload['references'] ?? null) ? $payload['references'] : [];
        $overlayDeltas = is_array($payload['overlay_deltas'] ?? null) ? $payload['overlay_deltas'] : [];
        $lastRefreshedAt = $payload['last_refreshed_at'] ?? null;
        $lastRefreshedDisplay = is_string($lastRefreshedAt) && $lastRefreshedAt !== ''
            ? \Illuminate\Support\Carbon::parse($lastRefreshedAt)->diffForHumans()
            : null;
        $sectionCount = count($structuredSections);
        $sectionCountLabel = $sectionCount === 1 ? '1 section' : $sectionCount.' sections';
        $isExternal = ($payload['baseline_build_type'] ?? '') === 'external';
        $externalProtected = false;
        if ($isExternal
            && ($authoringStatus ?? '') === 'external'
            && ! empty($payload['canonical_created_at'])) {
            $protectDays = max(0, (int) config('working_memory.external_protect_days', 14));
            if ($protectDays > 0) {
                $externalProtected = \Illuminate\Support\Carbon::parse($payload['canonical_created_at'])
                    ->gte(now()->subDays($protectDays));
            }
        }
        $aiAuthoringEnabled = config('features.working_memory_ai_authored')
            && config('working_memory.authoring_enabled');
    }

    $isSafeReferenceUrl = static function (string $url): bool {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            return in_array($scheme, ['http', 'https'], true)
                && filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//');
    };
@endphp

<section class="ideatub-surface px-5 py-5 sm:px-6">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-deep-indigo">Working memory</h2>
            @if ($hasPayload)
                <p class="mt-1 text-sm text-slate-brand/70 max-w-[48ch]">
                    {{ $sectionCountLabel }}
                    @if ($lastRefreshedDisplay)
                        · refreshed {{ $lastRefreshedDisplay }}
                    @endif
                </p>
            @else
                <p class="mt-1 text-sm text-slate-brand/70 max-w-[48ch]">Not built yet for this project.</p>
            @endif
        </div>

        @if ($hasPayload)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $freshnessClasses }}">
                    {{ $freshness }}
                </span>
                @if ($isExternal)
                    <span class="inline-flex items-center rounded-full border border-memory-violet/30 bg-memory-violet/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-memory-violet">
                        Synced from agent
                    </span>
                @endif
            </div>
        @endif
    </div>

    @if ($hasPayload && $externalProtected)
        <div class="mt-4 rounded-xl border border-memory-violet/20 bg-memory-violet/5 px-4 py-3">
            <p class="text-sm text-slate-brand">
                Synced from your agent. Re-run your agent sync to update it, or rebuild in IdeaTub below.
            </p>
        </div>
    @endif

    <div class="mt-4 flex flex-col gap-3 border-t border-deep-indigo/[0.06] pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <nav class="flex flex-wrap items-center gap-2" aria-label="Working memory actions">
            @if ($hasPayload)
                <a href="{{ route('projects.memory.versions', $project) }}" class="{{ $navBtnClass }}">
                    History
                </a>
                <a href="{{ route('projects.memory.show', $project) }}" class="{{ $navBtnClass }}">
                    Full page
                </a>
            @else
                <a href="{{ route('projects.memory.show', $project) }}" class="{{ $navBtnClass }}">
                    Open memory page
                </a>
            @endif
        </nav>

        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
            @include('components.working-memory-refresh-form', [
                'action' => $refreshAction,
                'buttonClass' => $actionBtnClass,
                'buttonLabel' => 'Refresh',
                'showForceButton' => ($hasPayload && $externalProtected && $aiAuthoringEnabled),
                'forceButtonClass' => $forceBtnClass,
            ])
        </div>
    </div>

    @if ($hasPayload)
        @if ($renderStructuredSections)
            <div class="mt-5 space-y-3">
                @foreach ($structuredSections as $sectionTitle => $sectionItems)
                    @php
                        $title = trim((string) $sectionTitle);
                        $items = is_array($sectionItems) ? $sectionItems : [$sectionItems];
                    @endphp
                    @continue($title === '')

                    <div class="rounded-xl bg-deep-indigo/[0.03] px-4 py-4 sm:px-5 ring-1 ring-deep-indigo/[0.05]">
                        <h3 class="text-sm font-semibold text-deep-indigo">{{ $title }}</h3>
                        <ul class="mt-2 space-y-2 text-sm text-slate-brand list-none pl-0">
                            @include('memory.partials.structured_section_items', [
                                'items' => $items,
                                'isSafeCitationUrl' => $isSafeReferenceUrl,
                            ])
                        </ul>
                    </div>
                @endforeach
            </div>
        @else
            <div class="mt-5 rounded-xl bg-deep-indigo/[0.03] px-4 py-4 sm:px-5 ring-1 ring-deep-indigo/[0.05] prose prose-sm max-w-none text-slate-brand">
                @include('memory.partials.structured_sections_content', [
                    'structured_sections' => $structuredSections,
                    'authoring_status' => $authoringStatus,
                    'summary_markdown' => $payload['summary_markdown'] ?? '',
                    'isSafeReferenceUrl' => $isSafeReferenceUrl,
                ])
            </div>
        @endif

        @if ($references !== [])
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($references as $reference)
                    @php
                        $url = trim((string) data_get($reference, 'url', ''));
                        $label = trim((string) data_get($reference, 'label', ''));
                    @endphp
                    @continue($url === '' || $label === '' || ! $isSafeReferenceUrl($url))

                    <a
                        href="{{ $url }}"
                        class="inline-flex items-center rounded-full border border-memory-violet/20 px-2.5 py-1 text-xs text-memory-violet hover:bg-memory-violet/5 transition-colors"
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @endif

        <details class="mt-4 rounded-xl ring-1 ring-deep-indigo/[0.08] bg-white/70">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-deep-indigo list-none [&::-webkit-details-marker]:hidden">
                Details &amp; metadata
            </summary>
            <div class="px-4 pb-4 border-t border-deep-indigo/[0.06] pt-3">
                @php
                    $confidenceDisplay = isset($payload['confidence_score']) && $payload['confidence_score'] !== ''
                        ? number_format((float) $payload['confidence_score'], 2)
                        : '—';
                    $inputCountDisplay = isset($payload['input_count']) && $payload['input_count'] !== ''
                        ? (string) $payload['input_count']
                        : '—';
                    $canonicalVersionDisplay = ! empty($payload['canonical_created_at'])
                        ? \Illuminate\Support\Carbon::parse($payload['canonical_created_at'])
                            ->timezone(config('app.timezone'))
                            ->format('M j, Y g:i A')
                        : '—';
                @endphp
                <dl class="grid gap-3 sm:grid-cols-2 text-[13px] text-slate-brand">
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Confidence</dt>
                        <dd class="text-deep-indigo font-medium">{{ $confidenceDisplay }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Last refreshed</dt>
                        <dd class="text-deep-indigo font-medium">{{ $lastRefreshedDisplay ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Consolidation window (days)</dt>
                        <dd class="text-deep-indigo font-medium">{{ $payload['effective_consolidation_window_days'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Input count</dt>
                        <dd class="text-deep-indigo font-medium">{{ $inputCountDisplay }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Baseline build</dt>
                        <dd class="text-deep-indigo font-medium">{{ $payload['baseline_build_type'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Source</dt>
                        <dd class="text-deep-indigo font-medium">{{ $payload['source_label'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Canonical version</dt>
                        <dd class="text-deep-indigo font-medium">{{ $canonicalVersionDisplay }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Authoring status</dt>
                        <dd class="text-deep-indigo font-medium">{{ $authoringStatus ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.08em] text-memory-violet/70">Recent updates (count)</dt>
                        <dd class="text-deep-indigo font-medium">{{ count($overlayDeltas) }}</dd>
                    </div>
                </dl>
            </div>
        </details>
    @else
        <div class="mt-5 rounded-xl border border-dashed border-deep-indigo/10 px-6 py-8 text-center">
            <p class="text-sm text-slate-brand/70 max-w-md mx-auto">
                Refresh to build working memory from thoughts linked to this project.
            </p>
        </div>
    @endif
</section>
