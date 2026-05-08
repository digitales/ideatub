@foreach ($items as $item)
    @php
        if (is_array($item)) {
            $itemText = trim((string) ($item['text'] ?? ''));
            $rawCitations = $item['citations'] ?? [];
            $itemCitations = is_array($rawCitations) ? $rawCitations : [];
            $showSourceBundleBadge = (($item['fallback_mode'] ?? '') === 'section_bundle');
        } else {
            $itemText = trim((string) $item);
            $itemCitations = [];
            $showSourceBundleBadge = false;
        }
    @endphp
    @continue($itemText === '')
    <li class="leading-relaxed">
        <x-safe-markdown
            :markdown="$itemText"
            class="structured-section-item-markdown [&_p]:my-1 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0"
        />
        @if ($showSourceBundleBadge)
            <span class="inline-flex items-center rounded-full border border-amber-200/80 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] text-amber-900 ml-1.5 align-middle">Source bundle</span>
        @endif
        @if ($itemCitations !== [])
            <span class="inline-flex flex-wrap items-center gap-1.5 mt-1 sm:mt-0 sm:ml-2 sm:inline-flex">
                @foreach ($itemCitations as $citation)
                    @php
                        $url = trim((string) data_get($citation, 'url', ''));
                        $label = trim((string) data_get($citation, 'label', ''));
                    @endphp
                    @continue($url === '' || $label === '')

                    @php
                        $parts = parse_url($url);
                        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
                        $isObviouslyUnsafeScheme = in_array($scheme, ['javascript', 'data', 'vbscript'], true);
                    @endphp
                    @continue($isObviouslyUnsafeScheme)

                    @if ($isSafeCitationUrl($url))
                        <a
                            href="{{ $url }}"
                            class="inline-flex items-center rounded-full border border-memory-violet/20 px-2.5 py-1 text-xs text-memory-violet hover:bg-memory-violet/5 transition-colors"
                        >
                            {{ $label }}
                        </a>
                    @else
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-700">
                            {{ $label }}
                        </span>
                    @endif
                @endforeach
            </span>
        @endif
    </li>
@endforeach
