{{-- Expects $rows: list<array{label: string, value: string, href: ?string}> --}}
@if (! empty($rows))
    <dl class="space-y-2 text-[12px] text-slate-brand">
        @foreach ($rows as $row)
            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-3 sm:items-baseline">
                <dt class="shrink-0 font-medium text-deep-indigo sm:w-36">{{ $row['label'] }}</dt>
                <dd class="min-w-0 break-all [overflow-wrap:anywhere]">
                    {{ $row['value'] }}
                    @if (! empty($row['href']))
                        <span class="whitespace-nowrap">
                            <a
                                href="{{ $row['href'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-2 font-medium text-memory-violet hover:underline"
                            >Open video</a>
                        </span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
