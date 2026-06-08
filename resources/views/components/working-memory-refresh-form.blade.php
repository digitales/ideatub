@php
    $formClass = $formClass ?? '';
    $hiddenFields = is_array($hiddenFields ?? null) ? $hiddenFields : [];
    $buttonLabel = $buttonLabel ?? 'Refresh working memory';
    $showForceButton = (bool) ($showForceButton ?? false);
    $forceButtonLabel = $forceButtonLabel ?? 'Rebuild in IdeaTub';
    $forceButtonClass = $forceButtonClass ?? $buttonClass;
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
    <div class="inline-flex flex-wrap items-center gap-2">
        <button
            type="submit"
            class="inline-flex items-center justify-center gap-2 {{ $buttonClass }}"
        >
            {{ $buttonLabel }}
        </button>
        @if ($showForceButton)
            <div class="inline-flex flex-wrap items-center gap-2 rounded-lg border border-slate-200/80 bg-slate-50/80 px-2 py-1">
                <label class="inline-flex items-center gap-1.5 text-xs text-slate-brand">
                    <input type="checkbox" name="fresh_start" value="1" class="rounded border-slate-300 text-memory-violet focus:ring-memory-violet/30">
                    Start fresh
                </label>
                <button
                    type="submit"
                    name="force"
                    value="1"
                    class="inline-flex items-center justify-center gap-2 {{ $forceButtonClass }}"
                >
                    {{ $forceButtonLabel }}
                </button>
            </div>
        @endif
    </div>
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
                            var submitter = e.submitter;
                            var button = submitter && submitter.type === 'submit'
                                ? submitter
                                : form.querySelector('button[type="submit"]');
                            if (! button || button.disabled) {
                                e.preventDefault();

                                return;
                            }
                            form.dataset.wmSubmitting = '1';
                            form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
                                btn.disabled = true;
                            });
                            button.setAttribute('aria-busy', 'true');
                            var spinner =
                                '<span class="inline-block size-3.5 rounded-full border-2 border-neural-teal/50 border-t-neural-teal animate-spin" aria-hidden="true"></span>';
                            var pendingLabel = button.name === 'force'
                                ? 'Rebuilding…'
                                : 'Refreshing…';
                            button.innerHTML =
                                spinner + '<span>' + pendingLabel + '</span>';
                        });
                    });
                });
            })();
        </script>
    @endpush
@endonce
