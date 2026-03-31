@php
    $m = $emailMetadata;
@endphp

<aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Email metadata</p>

    <div class="space-y-3 text-[13px] text-slate-brand">
        @if ($m && $m->subject())
            <p><span class="font-medium text-deep-indigo">Subject: {{ $m->subject() }}</span></p>
        @endif
        @if ($m && $m->direction())
            <p><span class="font-medium text-deep-indigo">Direction: {{ $m->direction() }}</span></p>
        @endif
        @if ($m && $m->fromLine())
            <p><span class="font-medium text-deep-indigo">From: {{ $m->fromLine() }}</span></p>
        @endif
        @if ($m && $m->toLine())
            <p><span class="font-medium text-deep-indigo">To: {{ $m->toLine() }}</span></p>
        @endif
        @if ($m && $m->ccLine())
            <p><span class="font-medium text-deep-indigo">Cc: {{ $m->ccLine() }}</span></p>
        @endif
        @if ($m && $m->sentDisplay())
            <p><span class="font-medium text-deep-indigo">Sent: {{ $m->sentDisplay() }}</span></p>
        @endif
        @if ($m && $m->receivedDisplay())
            <p><span class="font-medium text-deep-indigo">Received: {{ $m->receivedDisplay() }}</span></p>
        @endif
        @if ($m && $m->provider())
            <p><span class="font-medium text-deep-indigo">Provider: {{ $m->provider() }}</span></p>
        @endif
        @if ($m && $m->mailboxName())
            <p><span class="font-medium text-deep-indigo">Mailbox: {{ $m->mailboxName() }}</span></p>
        @elseif ($m && $m->mailboxId())
            <p><span class="font-medium text-deep-indigo">Mailbox ID: {{ $m->mailboxId() }}</span></p>
        @endif
        @if ($m && $m->threadId())
            <p><span class="font-medium text-deep-indigo">Thread ID: {{ $m->threadId() }}</span></p>
        @endif
        @if ($m && $m->accountEmail())
            <p><span class="font-medium text-deep-indigo">Account: {{ $m->accountEmail() }}</span></p>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2">
        @include('idea.partials.email_newsletter_research_status', ['newsletterResearchStatus' => $newsletterResearchStatus ?? null])
    </div>
    <div class="mt-5 pt-4 border-t border-memory-violet/10 space-y-2">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Actions</p>
        <form method="POST" action="{{ route('emails.idea-research', $thought) }}" x-data="{ submitting: false }" @submit="submitting = true">
            @csrf
            <button type="submit" :disabled="submitting" class="w-full text-left px-3 py-2 text-[12px] font-medium text-memory-violet border border-memory-violet/30 rounded-lg hover:bg-memory-violet/5 transition-colors disabled:opacity-50">
                Run idea research
            </button>
        </form>
        <form method="POST" action="{{ route('emails.newsletter-research', $thought) }}" x-data="{ submitting: false }" @submit="submitting = true">
            @csrf
            <button type="submit" :disabled="submitting" class="w-full text-left px-3 py-2 text-[12px] font-medium text-memory-violet border border-memory-violet/30 rounded-lg hover:bg-memory-violet/5 transition-colors disabled:opacity-50">
                Run newsletter research
            </button>
        </form>
    </div>

    @include('idea.partials.thought_detail_sender_rule_card', ['senderRuleContext' => $senderRuleContext])
</aside>
