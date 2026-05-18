@php
    $structuredSections = is_array($structured_sections ?? null) ? $structured_sections : [];
    $authoringStatus = $authoring_status ?? null;
    $renderStructuredSections = $structuredSections !== []
        && ($authoringStatus === null || $authoringStatus === 'validated' || $authoringStatus === 'external');
    $isSafeReferenceUrl = $isSafeReferenceUrl ?? static function (string $url): bool {
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

@if ($renderStructuredSections)
    @foreach ($structuredSections as $sectionTitle => $sectionItems)
        @php
            $title = trim((string) $sectionTitle);
            $items = is_array($sectionItems) ? $sectionItems : [$sectionItems];
        @endphp
        @continue($title === '')

        <h2>{{ $title }}</h2>
        <ul>
            @include('memory.partials.structured_section_items', [
                'items' => $items,
                'isSafeCitationUrl' => $isSafeReferenceUrl,
            ])
        </ul>
    @endforeach
@else
    <x-safe-markdown :markdown="$summary_markdown ?? ''" />
@endif
