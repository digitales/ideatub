@php
    $payload = is_array($workingMemoryPayload ?? null) ? $workingMemoryPayload : null;
    $refreshAction = route('working-memory.refresh.project', $project);
    $hasPayload = $payload !== null;

    if ($hasPayload) {
        $freshness = $payload['freshness_state'] ?? 'stale';
        $freshnessClasses = match ($freshness) {
            'fresh' => 'bg-neural-teal/15 text-neural-teal',
            'degraded' => 'bg-amber-50 text-amber-900',
            default => 'bg-slate-100 text-slate-600',
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
        $externalProtected = false;
        if (($payload['baseline_build_type'] ?? '') === 'external'
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

<section>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-deep-indigo">Working memory</h2>
            @if ($hasPayload)
                <p class="mt-1 text-sm text-slate-brand/70">
                    {{ $sectionCountLabel }}
                    @if ($lastRefreshedDisplay)
                        · last refreshed {{ $lastRefreshedDisplay }}
                    @endif
                    @if (($payload['baseline_build_type'] ?? '') === 'external')
                        · synced from agent
                    @endif
                </p>
            @else
                <p class="mt-1 text-sm text-slate-brand/70">Not built yet for this project.</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs shrink-0">
            @if ($hasPayload)
                <span class="rounded-full px-2.5 py-1 font-semibold uppercase tracking-wide {{ $freshnessClasses }}">
                    {{ $freshness }}
                </span>
                @if (($payload['baseline_build_type'] ?? '') === 'external')
                    <span class="rounded-full border border-memory-violet/30 bg-memory-violet/10 px-2.5 py-1 font-semibold uppercase tracking-wide text-memory-violet">
                        Synced from agent
                    </span>
                @endif
            @endif
            @if ($hasPayload && $externalProtected)
                <div class="flex flex-col items-end gap-2 max-w-sm">
                    <p class="text-xs text-slate-brand text-right">
                        Synced from your agent. Re-run your agent sync to update it.
                    </p>
                    @include('components.working-memory-refresh-form', [
                        'action' => $refreshAction,
                        'formClass' => 'inline',
                        'buttonClass' => 'font-medium text-memory-violet hover:underline bg-transparent border-0 p-0 cursor-pointer',
                        'buttonLabel' => 'Refresh',
                        'showForceButton' => $aiAuthoringEnabled,
                        'forceButtonClass' => 'font-medium text-slate-brand hover:text-deep-indigo',
                    ])
                </div>
            @else
                @include('components.working-memory-refresh-form', [
                    'action' => $refreshAction,
                    'formClass' => 'inline',
                    'buttonClass' => 'font-medium text-memory-violet hover:underline bg-transparent border-0 p-0 cursor-pointer',
                    'buttonLabel' => 'Refresh',
                ])
            @endif
            @if ($hasPayload)
                <span class="text-slate-brand/30" aria-hidden="true">·</span>
                <a href="{{ route('projects.memory.versions', $project) }}" class="font-medium text-memory-violet hover:underline">History</a>
            @endif
        </div>
    </div>

    @if ($hasPayload)
        @if ($renderStructuredSections)
            <div class="space-y-3">
                @foreach ($structuredSections as $sectionTitle => $sectionItems)
                    @php
                        $title = trim((string) $sectionTitle);
                        $items = is_array($sectionItems) ? $sectionItems : [$sectionItems];
                    @endphp
                    @continue($title === '')

                    <div class="rounded-xl bg-deep-indigo/[0.03] px-4 py-4 sm:px-5 ring-1 ring-deep-indigo/[0.05]">
                        <h3 class="text-sm font-semibold text-deep-indigo">{{ $title }}</h3>
                        <ul class="mt-2 space-y-1.5 text-sm text-slate-brand list-none pl-0">
                            @include('memory.partials.structured_section_items', [
                                'items' => $items,
                                'isSafeCitationUrl' => $isSafeReferenceUrl,
                            ])
                        </ul>
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-xl bg-deep-indigo/[0.03] px-4 py-4 sm:px-5 ring-1 ring-deep-indigo/[0.05] prose prose-sm max-w-none text-slate-brand">
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
        <div class="rounded-xl border border-dashed border-deep-indigo/10 px-6 py-8 text-center">
            <p class="text-sm text-slate-brand/70 max-w-md mx-auto">
                Refresh to build working memory from thoughts linked to this project, or open the dedicated memory page.
            </p>
            <p class="mt-4">
                <a href="{{ route('projects.memory.show', $project) }}" class="text-sm font-medium text-memory-violet hover:underline">
                    Open working memory page
                </a>
            </p>
        </div>
    @endif
</section>
