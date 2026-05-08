@php
    $formClass = $formClass ?? '';
    $hiddenFields = is_array($hiddenFields ?? null) ? $hiddenFields : [];
@endphp
<form
    method="POST"
    action="{{ $action }}"
    @if ($formClass !== '')
        class="{{ $formClass }}"
    @endif
    data-working-memory-refresh
>
    @csrf
    @foreach ($hiddenFields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <button
        type="submit"
        class="inline-flex items-center justify-center gap-2 {{ $buttonClass }}"
    >
        Refresh working memory
    </button>
</form>
@once
    @push('scripts')
        <script>
            (function () {
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('form[data-working-memory-refresh]').forEach(function (form) {
                        if (form.dataset.wmRefreshBound === '1') {
                            return;
                        }
                        form.dataset.wmRefreshBound = '1';
                        form.addEventListener('submit', function (e) {
                            if (form.dataset.wmSubmitting === '1') {
                                e.preventDefault();

                                return;
                            }
                            var button = form.querySelector('button[type="submit"]');
                            if (! button || button.disabled) {
                                e.preventDefault();

                                return;
                            }
                            form.dataset.wmSubmitting = '1';
                            button.disabled = true;
                            button.setAttribute('aria-busy', 'true');
                            var spinner =
                                '<span class="inline-block size-3.5 rounded-full border-2 border-neural-teal/50 border-t-neural-teal animate-spin" aria-hidden="true"></span>';
                            button.innerHTML =
                                spinner + '<span>Refreshing…</span>';
                        });
                    });
                });
            })();
        </script>
    @endpush
@endonce
