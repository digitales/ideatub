@extends('layouts.idea')

@section('title', 'Help — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Help</h1>
    <p class="text-sm text-slate-brand mb-8">Keyboard shortcuts, YouTube video capture, MCP integration, example prompts, and syncing plans into your thinking space.</p>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h2 class="text-lg font-semibold text-deep-indigo mb-4">Keyboard shortcuts</h2>
        <table class="w-full text-sm text-deep-indigo">
            <tbody class="divide-y divide-memory-violet/10">
                <tr><td class="py-2">Focus capture</td><td class="py-2 text-right text-slate-brand font-medium">⌘/ or Ctrl+/</td></tr>
                <tr><td class="py-2">Open search</td><td class="py-2 text-right text-slate-brand font-medium">⌘K or Ctrl+K</td></tr>
                <tr><td class="py-2">Move down / up thought</td><td class="py-2 text-right text-slate-brand font-medium">j / k</td></tr>
                <tr><td class="py-2">Open reply</td><td class="py-2 text-right text-slate-brand font-medium">Enter</td></tr>
                <tr><td class="py-2">Cancel reply / close search</td><td class="py-2 text-right text-slate-brand font-medium">Escape</td></tr>
                <tr><td class="py-2">Submit thought</td><td class="py-2 text-right text-slate-brand font-medium">⌘+Enter or Ctrl+Enter</td></tr>
                <tr><td class="py-2">Show shortcut list</td><td class="py-2 text-right text-slate-brand font-medium">?</td></tr>
            </tbody>
        </table>
    </div>

    <div class="mt-8 rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <h2 class="text-lg font-semibold text-deep-indigo mb-3">YouTube video thoughts</h2>
        <p class="text-sm text-slate-brand mb-3">On the <a href="{{ route('idea.index') }}" class="text-memory-violet hover:underline">home</a> capture box and on <a href="{{ route('idea.ideas') }}" class="text-memory-violet hover:underline">Ideas</a>, paste <strong>only</strong> a YouTube link (watch, youtu.be, Shorts, or live URLs). After a short moment the composer switches to <strong>video</strong> mode: you can add an optional transcript, choose <strong>Research now</strong>, and save. If you leave the transcript empty, IdeaTub will try to fetch captions in the background.</p>
        <ul class="text-sm text-slate-brand space-y-2 list-disc list-inside">
            <li>The field must contain <strong>just the URL</strong> — no extra text, spaces, or line breaks, or it stays a normal thought instead of a video.</li>
            <li>When <strong>replying</strong> to a thought from the home page, capture stays text-only (no video mode).</li>
        </ul>
    </div>

    {{-- Example prompts (Companion Prompt Kit) --}}
    <div id="example-prompts" class="mt-8">
        <h2 class="text-lg font-semibold text-deep-indigo mb-2">Example prompts</h2>
        <p class="text-sm text-slate-brand mb-4">
            Companion prompts for your Open Brain: migrate memories, bring over your second brain, discover use cases, use quick-capture templates, and run a weekly review.
            <a href="{{ $examplePromptsSourceUrl ?? '#' }}" target="_blank" rel="noopener noreferrer" class="text-memory-violet hover:underline">Prompt Kit source</a>
        </p>
        @foreach($prompts ?? [] as $prompt)
        <section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
            <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo">
                {!! $prompt['body_html'] !!}
            </div>
        </section>
        @endforeach
        @if(!empty($prompts))
        <p class="text-xs text-slate-brand/70 mt-2">
            From <a href="{{ $examplePromptsSourceUrl }}" target="_blank" rel="noopener noreferrer" class="text-memory-violet hover:underline">Open Brain: Companion Prompts</a> (Prompt Kit by Nate B. Jones).
        </p>
        @endif
    </div>

    {{-- MCP integration guide --}}
    <div id="mcp" class="mt-8 space-y-6">
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h2 class="text-lg font-semibold text-deep-indigo mb-3">MCP integration</h2>
            <p class="text-sm text-slate-brand mb-4">Connect Claude, Cursor, ChatGPT, or other MCP-capable tools to IdeaTub so they can search your thoughts and capture new ones. Authentication uses a <strong>per-user MCP key</strong>; the same key works in every client and identifies you.</p>
            <p class="text-sm text-slate-brand mb-4">Your client’s <strong>tools/list</strong> is the source of truth for names and parameters. Common tools include:</p>
            <table class="w-full text-sm text-deep-indigo border-collapse">
                <thead>
                    <tr class="border-b border-memory-violet/15">
                        <th class="text-left py-2 font-medium">Tool</th>
                        <th class="text-left py-2 font-medium">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-memory-violet/10">
                    <tr><td class="py-2 font-mono text-xs">search_thoughts</td><td class="py-2 text-slate-brand">Semantic search over your thoughts</td></tr>
                    <tr><td class="py-2 font-mono text-xs">browse_recent</td><td class="py-2 text-slate-brand">List recent thoughts</td></tr>
                    <tr><td class="py-2 font-mono text-xs">thought_stats</td><td class="py-2 text-slate-brand">Count of your thoughts</td></tr>
                    <tr><td class="py-2 font-mono text-xs">capture_thought</td><td class="py-2 text-slate-brand">Save a new thought (or comment)</td></tr>
                    <tr><td class="py-2 font-mono text-xs">capture_plan</td><td class="py-2 text-slate-brand">Save structured docs (plans, decisions, dev/support/spec notes, research, meeting notes); use <code class="bg-memory-violet/10 px-1 rounded text-[11px]">doc_type</code> and <code class="bg-memory-violet/10 px-1 rounded text-[11px]">plan_slug</code> for tags and Stream</td></tr>
                    <tr><td class="py-2 font-mono text-xs align-top">capture_meeting<br>add_meeting<br>add_meeting_notes</td><td class="py-2 text-slate-brand">Three names for the <strong>same</strong> action as <code class="bg-memory-violet/10 px-1 rounded text-[11px]">capture_plan</code> with <code class="bg-memory-violet/10 px-1 rounded text-[11px]">doc_type</code> fixed to <code class="bg-memory-violet/10 px-1 rounded text-[11px]">meeting</code> (no <code class="bg-memory-violet/10 px-1 rounded text-[11px]">doc_type</code> parameter). Meeting notes appear in <a href="{{ route('idea.stream.meetings') }}" class="text-memory-violet hover:underline">Stream → Meetings</a>.</td></tr>
                    <tr><td class="py-2 font-mono text-xs">capture_idea</td><td class="py-2 text-slate-brand">Save an idea (typed thought with optional logged date)</td></tr>
                    <tr><td class="py-2 font-mono text-xs">get_ideas</td><td class="py-2 text-slate-brand">List ideas to revisit</td></tr>
                    <tr><td class="py-2 font-mono text-xs">research_idea</td><td class="py-2 text-slate-brand">Queue background research for an idea</td></tr>
                    <tr><td class="py-2 font-mono text-xs">capture_video</td><td class="py-2 text-slate-brand">Save a YouTube video as a thought</td></tr>
                    <tr><td class="py-2 font-mono text-xs">sync_jira</td><td class="py-2 text-slate-brand">Refresh Jira activity into thoughts (shown only when Jira is enabled for your workspace)</td></tr>
                </tbody>
            </table>
        </div>

        <div id="plans" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">Plans and docs as thoughts (<code class="bg-memory-violet/10 px-1.5 py-0.5 rounded text-xs font-mono">capture_plan</code>)</h3>
            <p class="text-sm text-slate-brand mb-3">Use <strong>capture_plan</strong> to sync plans, decisions, dev notes, support docs, specs, research, or <strong>meeting notes</strong> into IdeaTub. Set <strong>doc_type</strong> to one of: <code class="bg-memory-violet/10 px-1 rounded text-xs">plan</code> (default), <code class="bg-memory-violet/10 px-1 rounded text-xs">decision</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">dev</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">support</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">spec</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">research</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">meeting</code>. The thought’s source and tag prefix match the doc type (e.g. <code class="bg-memory-violet/10 px-1 rounded text-xs">decision:project-spec</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">meeting:2026-04-01-standup</code>). For meetings you can instead call <code class="bg-memory-violet/10 px-1 rounded text-xs">capture_meeting</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">add_meeting</code>, or <code class="bg-memory-violet/10 px-1 rounded text-xs">add_meeting_notes</code> (same parameters except <code class="bg-memory-violet/10 px-1 rounded text-xs">doc_type</code> is implied). Supported paths: <code class="bg-memory-violet/10 px-1 rounded text-xs">docs/superpowers/plans/*.md</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">decisions/*.md</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">dev/*.md</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">support/*.md</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">specs/*.md</code>; for research or meetings any logical path or omit.</p>
            <ul class="text-sm text-slate-brand mb-4 space-y-2 list-disc list-inside">
                <li><strong class="text-deep-indigo">Meetings in the app</strong> — Meeting notes show on <a href="{{ route('idea.stream.meetings') }}" class="text-memory-violet hover:underline">Stream → Meetings</a> (and in the main stream and home recent feed like other non-research thoughts).</li>
                <li><strong class="text-deep-indigo">One thought per section</strong> — Call <code class="bg-memory-violet/10 px-1 rounded text-xs">capture_plan</code> once per section. Use the same <strong>plan_slug</strong> for all sections. IdeaTub adds a tag <code class="bg-memory-violet/10 px-1 rounded text-xs">&lt;doc_type&gt;:&lt;slug&gt;</code> (e.g. <code class="bg-memory-violet/10 px-1 rounded text-xs">plan:2026-03-12-tag-and-stream</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">decision:project-spec</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">spec:tag-and-stream-design</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">meeting:weekly-sync</code>).</li>
                <li><strong class="text-deep-indigo">Long-form view in Stream</strong> — Open <a href="{{ route('idea.stream') }}" class="text-memory-violet hover:underline">Stream</a> and add <code class="bg-memory-violet/10 px-1 rounded text-xs">?tag=&lt;doc_type&gt;-&lt;slug&gt;</code> (e.g. <code class="bg-memory-violet/10 px-1 rounded text-xs">/stream?tag=decision-project-spec</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">/stream?tag=spec-tag-and-stream-design</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">/stream?tag=meeting-weekly-sync</code>). All thoughts with that tag appear together.</li>
                <li><strong class="text-deep-indigo">Share research</strong> — From a research thought card in Stream (or from <a href="{{ route('shared-research.index') }}" class="text-memory-violet hover:underline">Shared research</a>), use <strong>Share</strong> to get a readonly link. You can optionally set a password and expiry.</li>
                <li><strong class="text-deep-indigo">Optional: file_path</strong> — Pass the path (e.g. <code class="bg-memory-violet/10 px-1 rounded text-xs">decisions/project-spec.md</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">support/example-investigation.md</code>) so source_metadata records the source file.</li>
                <li><strong class="text-deep-indigo">Optional: hierarchy</strong> — Create a root thought first, then pass its ID as <strong>parent_id</strong> for section thoughts so they appear as replies and under the shared tag.</li>
            </ul>
            <p class="text-sm text-slate-brand">Parameters: <code class="bg-memory-violet/10 px-1 rounded text-xs">content</code> (required); <code class="bg-memory-violet/10 px-1 rounded text-xs">doc_type</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">file_path</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">plan_slug</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">parent_id</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">section_title</code>, <code class="bg-memory-violet/10 px-1 rounded text-xs">project</code> (code project name, e.g. repo or workspace), <code class="bg-memory-violet/10 px-1 rounded text-xs">tags</code> (optional).</p>
        </div>

        @if(isset($cursorRuleContent) && $cursorRuleContent !== null)
        <div id="cursor-rule" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">Cursor rule: sync docs to IdeaTub</h3>
            <p class="text-sm text-slate-brand mb-3">Add this rule to a project so Cursor knows how to sync plans, decisions, dev, support, and spec markdown to IdeaTub via <code class="bg-memory-violet/10 px-1 rounded text-xs">capture_plan</code>. Copy the file into that project’s <code class="bg-memory-violet/10 px-1 rounded text-xs">.cursor/rules/</code> (create the folder if needed). IdeaTub MCP must be configured in Cursor for that project.</p>
            <div class="mb-3 flex flex-wrap gap-2">
                <a href="#" data-download-rule class="inline-flex items-center gap-1.5 rounded-lg bg-memory-violet/15 px-3 py-1.5 text-sm font-medium text-memory-violet hover:bg-memory-violet/25 transition-colors">Download ideatub-sync-docs.mdc</a>
            </div>
            <pre class="text-xs text-deep-indigo bg-slate-100/80 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap border border-memory-violet/10" style="max-height: 20rem;"><code>{{ e($cursorRuleContent) }}</code></pre>
        </div>
        @endif

        @if(isset($researchRuleContent) && $researchRuleContent !== null)
        <div id="cursor-rule-research" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">Cursor rule: save research to IdeaTub</h3>
            <p class="text-sm text-slate-brand mb-3">Add this rule so Cursor (or Claude Desktop) knows how to save research agent output to IdeaTub via <code class="bg-memory-violet/10 px-1 rounded text-xs">capture_plan</code> with <code class="bg-memory-violet/10 px-1 rounded text-xs">doc_type</code> <code class="bg-memory-violet/10 px-1 rounded text-xs">research</code> and <code class="bg-memory-violet/10 px-1 rounded text-xs">project</code> for the research topic. Copy the file into the project’s <code class="bg-memory-violet/10 px-1 rounded text-xs">.cursor/rules/</code> (create the folder if needed). IdeaTub MCP must be configured for that project.</p>
            <div class="mb-3 flex flex-wrap gap-2">
                <a href="#" data-download-research-rule class="inline-flex items-center gap-1.5 rounded-lg bg-memory-violet/15 px-3 py-1.5 text-sm font-medium text-memory-violet hover:bg-memory-violet/25 transition-colors">Download ideatub-sync-research.mdc</a>
            </div>
            <pre class="text-xs text-deep-indigo bg-slate-100/80 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap border border-memory-violet/10" style="max-height: 20rem;"><code>{{ e($researchRuleContent) }}</code></pre>
        </div>
        @endif

        <script>
            (function () {
                var cursorContent = @json($cursorRuleContent ?? '');
                var researchContent = @json($researchRuleContent ?? '');
                if (cursorContent) {
                    var btn = document.querySelector('[data-download-rule]');
                    if (btn) btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var blob = new Blob([cursorContent], { type: 'text/markdown' });
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = 'ideatub-sync-docs.mdc';
                        a.click();
                        URL.revokeObjectURL(a.href);
                    });
                }
                if (researchContent) {
                    var researchBtn = document.querySelector('[data-download-research-rule]');
                    if (researchBtn) researchBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var blob = new Blob([researchContent], { type: 'text/markdown' });
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = 'ideatub-sync-research.mdc';
                        a.click();
                        URL.revokeObjectURL(a.href);
                    });
                }
            })();
        </script>

        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">1. Get your MCP key</h3>
            <p class="text-sm text-slate-brand mb-3">Open your profile menu (avatar, top right) → <strong>MCP key</strong>. Click <strong>Create MCP key</strong>. Your key is shown <strong>once</strong> — copy it and store it securely (e.g. password manager).</p>
            <p class="text-sm text-slate-brand"><a href="{{ route('settings.mcp-keys.index') }}" class="text-memory-violet hover:underline font-medium">Go to MCP key →</a></p>
        </div>

        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">2. Connection URL and auth</h3>
            <p class="text-sm text-slate-brand mb-2"><strong>Endpoint:</strong> <code class="bg-memory-violet/10 px-1.5 py-0.5 rounded text-xs break-all">{{ url('/api/mcp') }}</code></p>
            <p class="text-sm text-slate-brand mb-2">Send your key either as <code class="bg-memory-violet/10 px-1.5 py-0.5 rounded text-xs">?key=YOUR_MCP_KEY</code> in the URL or in the <code class="bg-memory-violet/10 px-1.5 py-0.5 rounded text-xs">x-ideatub-key</code> header (header is preferred so the key is less likely to appear in logs).</p>
            <p class="text-sm text-slate-brand">Use the <strong>same key</strong> in every AI client.</p>
        </div>

        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">3. Connect your AI client</h3>
            <ul class="space-y-4 text-sm text-slate-brand">
                <li>
                    <strong class="text-deep-indigo">Claude Desktop</strong> — Settings → Connectors → Add custom connector. Name: IdeaTub. Remote MCP server URL: <code class="bg-memory-violet/10 px-1 rounded text-xs">{{ url('/api/mcp') }}?key=YOUR_KEY</code>. Enable the connector in each conversation (+ → Connectors).
                </li>
                <li>
                    <strong class="text-deep-indigo">ChatGPT</strong> (web, paid) — Settings → Apps & Connectors → Advanced → Developer mode ON. Create → Name: IdeaTub, MCP endpoint URL: same URL with <code class="bg-memory-violet/10 px-1 rounded text-xs">?key=YOUR_KEY</code>, Authentication: None. If it doesn’t use tools automatically, say: “Use the IdeaTub search_thoughts tool to find my notes about …”.
                </li>
                <li>
                    <strong class="text-deep-indigo">Cursor</strong> — Settings (⌘,) → Tools & MCP → Add new MCP server. Enter the full URL with <code class="bg-memory-violet/10 px-1 rounded text-xs">?key=YOUR_KEY</code>. Restart if needed.
                </li>
                <li>
                    <strong class="text-deep-indigo">Claude Code (CLI)</strong> — <code class="bg-memory-violet/10 px-1 rounded text-xs">claude mcp add --transport http ideatub {{ url('/api/mcp') }} --header "x-ideatub-key: YOUR_KEY"</code>
                </li>
                <li>
                    <strong class="text-deep-indigo">Other clients</strong> — If they support a remote MCP or custom connector URL, use <code class="bg-memory-violet/10 px-1 rounded text-xs">{{ url('/api/mcp') }}?key=YOUR_KEY</code>.
                </li>
            </ul>
        </div>

        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            <h3 class="text-base font-semibold text-deep-indigo mb-3">Troubleshooting</h3>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-memory-violet/10">
                    <tr>
                        <td class="py-2 font-medium text-deep-indigo align-top w-36">401 Unauthorized</td>
                        <td class="py-2 text-slate-brand">Key wrong or missing. Use <code class="bg-memory-violet/10 px-1 rounded text-xs">?key=...</code> or <code class="bg-memory-violet/10 px-1 rounded text-xs">x-ideatub-key</code> with no extra spaces. Create a new key on the <a href="{{ route('settings.mcp-keys.index') }}" class="text-memory-violet hover:underline">MCP key</a> page if you lost it.</td>
                    </tr>
                    <tr>
                        <td class="py-2 font-medium text-deep-indigo align-top">Tools don’t appear</td>
                        <td class="py-2 text-slate-brand">Some clients expect a different MCP transport. Try the full URL with key; if it still fails, your client may need a bridge (see project docs).</td>
                    </tr>
                    <tr>
                        <td class="py-2 font-medium text-deep-indigo align-top">Search returns nothing</td>
                        <td class="py-2 text-slate-brand">Capture some thoughts first (web or <code class="bg-memory-violet/10 px-1 rounded text-xs">capture_thought</code>). Ensure you’re using the key for the account that owns those thoughts.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-slate-brand/80">For more prompt ideas (memory migration, second brain, weekly review), see the <a href="{{ route('help') }}#example-prompts" class="text-memory-violet hover:underline">Example prompts</a> section above and the <a href="https://promptkit.natebjones.com/20260224_uq1_promptkit_1" class="text-memory-violet hover:underline" target="_blank" rel="noopener">Companion Prompt Kit</a>.</p>
    </div>
</div>
@endsection
