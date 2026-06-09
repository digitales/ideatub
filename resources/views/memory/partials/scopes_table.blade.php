<table class="w-full text-left text-sm text-deep-indigo">
    <thead>
        <tr class="border-b border-deep-indigo/[0.06] bg-deep-indigo/[0.02]">
            <th scope="col" class="py-2 pr-4 text-sm font-medium text-slate-brand whitespace-nowrap">Scope</th>
            <th scope="col" class="hidden py-2 pr-4 text-sm font-medium text-slate-brand whitespace-nowrap sm:table-cell">Freshness</th>
            <th scope="col" class="py-2 pr-4 text-sm font-medium text-slate-brand whitespace-nowrap">Status</th>
            <th scope="col" class="py-2 text-right text-sm font-medium text-slate-brand whitespace-nowrap">Updated</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-deep-indigo/[0.06]">
        @foreach ($rows as $row)
            @include('memory.partials.scopes_row', ['row' => $row])
        @endforeach
    </tbody>
</table>
