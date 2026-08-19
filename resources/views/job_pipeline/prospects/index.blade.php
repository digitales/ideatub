@extends('layouts.idea')

@section('content')
<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-gray-500">
            <th>Company</th><th>Role</th><th>Source</th><th>Notes</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($prospects as $prospect)
            <tr
                class="border-t"
                x-data="{
                    notes: @js($prospect->notes ?? ''),
                    saving: false,
                    error: '',
                    async save() {
                        if (this.saving) return;
                        this.saving = true;
                        this.error = '';
                        try {
                            const res = await fetch('{{ route('job_pipeline.prospects.update', $prospect) }}', {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '',
                                },
                                body: JSON.stringify({ notes: this.notes }),
                            });
                            if (!res.ok) {
                                this.error = 'Failed to save notes.';
                            }
                        } catch {
                            this.error = 'Network error. Try again.';
                        } finally {
                            this.saving = false;
                        }
                    },
                }"
            >
                <td>{{ $prospect->company }}</td>
                <td>{{ $prospect->role_title }}</td>
                <td>{{ $prospect->source }}</td>
                <td>
                    <textarea x-model="notes" rows="1" class="border rounded w-full text-sm" @blur="save()"></textarea>
                    <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
                </td>
                <td class="flex gap-1">
                    <form method="POST" action="{{ route('job_pipeline.prospects.shortlist', $prospect) }}">@csrf<button class="px-2 py-1 border rounded text-xs">Shortlist</button></form>
                    <form method="POST" action="{{ route('job_pipeline.prospects.mark-applied', $prospect) }}">@csrf<button class="px-2 py-1 border rounded text-xs">Mark Applied</button></form>
                    <form method="POST" action="{{ route('job_pipeline.prospects.dismiss', $prospect) }}">@csrf<button class="px-2 py-1 border rounded text-xs">Dismiss</button></form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
