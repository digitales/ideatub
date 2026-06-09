@extends('layouts.idea')

@section('title', 'Help — IdeaTub')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-20">

    @include('idea.partials.page_shell_header', [
        'eyebrow' => 'Documentation',
        'title' => 'Help',
        'subtitle' => 'Keyboard shortcuts, YouTube capture, MCP integration, example prompts, and guides for syncing plans and workflows into your thinking space.',
        'centered' => false,
    ])

    @include('help.partials.topic_nav')

    <div class="flex flex-col gap-12">

        {{-- Keyboard shortcuts --}}
        <section id="shortcuts" class="scroll-mt-24">
            <h2 class="text-xl font-semibold tracking-tight text-deep-indigo text-balance mb-4">Keyboard shortcuts</h2>
            <p class="text-sm/6 text-slate-brand mb-4">Press <x-help.kbd>?</x-help.kbd> anywhere in the app to open a quick-reference overlay with these shortcuts.</p>
            <div class="ideatub-surface px-5 py-5 sm:px-6">
                <div class="-mx-4 -my-2 overflow-x-auto whitespace-nowrap sm:-mx-6">
                    <div class="inline-block min-w-full px-4 py-2 align-middle sm:px-6">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-deep-indigo/[0.06]">
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Quick capture</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>⌘/</x-help.kbd> <span class="text-slate-brand/60">or</span> <x-help.kbd>Ctrl+/</x-help.kbd></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 pl-4 text-slate-brand/80" colspan="2">Home: focus capture box · Elsewhere: open capture modal</td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Open search</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>⌘K</x-help.kbd> <span class="text-slate-brand/60">or</span> <x-help.kbd>Ctrl+K</x-help.kbd></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Move down / up thought</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>j</x-help.kbd> <span class="text-slate-brand/60">/</span> <x-help.kbd>k</x-help.kbd></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Open reply</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>Enter</x-help.kbd></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Cancel reply / close search</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>Escape</x-help.kbd></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Submit thought</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>⌘+Enter</x-help.kbd> <span class="text-slate-brand/60">or</span> <x-help.kbd>Ctrl+Enter</x-help.kbd></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 text-deep-indigo">Show shortcut list</td>
                                    <td class="py-2.5 text-right"><x-help.kbd>?</x-help.kbd></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        {{-- YouTube --}}
        <section id="youtube" class="scroll-mt-24">
            <h2 class="text-xl font-semibold tracking-tight text-deep-indigo text-balance mb-4">YouTube video thoughts</h2>
            <div class="ideatub-surface px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-4 text-sm/6 text-slate-brand">
                    <p>On the <a href="{{ route('idea.index') }}" class="font-medium text-memory-violet hover:underline">home</a> capture box and on <a href="{{ route('idea.ideas') }}" class="font-medium text-memory-violet hover:underline">Ideas</a>, paste <strong class="text-deep-indigo">only</strong> a YouTube link (watch, youtu.be, Shorts, or live URLs). After a short moment the composer switches to <strong class="text-deep-indigo">video</strong> mode: you can add an optional transcript, choose <strong class="text-deep-indigo">Research now</strong>, and save. If you leave the transcript empty, IdeaTub will try to fetch captions in the background.</p>
                    <ul role="list" class="flex flex-col gap-2 list-disc pl-5 marker:text-memory-violet/50">
                        <li>The field must contain <strong class="text-deep-indigo">just the URL</strong> — no extra text, spaces, or line breaks, or it stays a normal thought instead of a video.</li>
                        <li>When <strong class="text-deep-indigo">replying</strong> to a thought from the home page, capture stays text-only (no video mode).</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- Example prompts --}}
        <section id="example-prompts" class="scroll-mt-24">
            <h2 class="text-xl font-semibold tracking-tight text-deep-indigo text-balance mb-2">Example prompts</h2>
            <p class="text-sm/6 text-slate-brand mb-5">
                Companion prompts for your Open Brain: migrate memories, bring over your second brain, discover use cases, use quick-capture templates, and run a weekly review.
                <a href="{{ $examplePromptsSourceUrl ?? '#' }}" target="_blank" rel="noopener noreferrer" class="font-medium text-memory-violet hover:underline">Prompt Kit source</a>
            </p>
            <div class="flex flex-col gap-5">
                @foreach($prompts ?? [] as $prompt)
                    <article class="ideatub-surface px-5 py-5 sm:px-6">
                        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-p:text-slate-brand prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100 prose-pre:text-deep-indigo prose-code:text-deep-indigo">
                            {!! $prompt['body_html'] !!}
                        </div>
                    </article>
                @endforeach
            </div>
            @if(!empty($prompts))
                <p class="text-xs/5 text-slate-brand/70 mt-4">
                    From <a href="{{ $examplePromptsSourceUrl }}" target="_blank" rel="noopener noreferrer" class="text-memory-violet hover:underline">Open Brain: Companion Prompts</a> (Prompt Kit by Nate B. Jones).
                </p>
            @endif
        </section>

        {{-- MCP integration --}}
        <section id="mcp" class="scroll-mt-24 flex flex-col gap-8">
            <div>
                <h2 class="text-xl font-semibold tracking-tight text-deep-indigo text-balance mb-2">MCP integration</h2>
                <p class="text-sm/6 text-slate-brand">Connect Claude, Cursor, ChatGPT, or other MCP-capable tools to IdeaTub so they can search your thoughts and capture new ones. Authentication uses a <strong class="text-deep-indigo">per-user MCP key</strong>; the same key works in every client and identifies you.</p>
            </div>

            <div class="ideatub-surface px-5 py-5 sm:px-6">
                <h3 class="text-base font-semibold text-deep-indigo mb-3">Available tools</h3>
                <p class="text-sm/6 text-slate-brand mb-4">Your client’s <strong class="text-deep-indigo">tools/list</strong> is the source of truth for names and parameters. Common tools include:</p>
                <div class="-mx-4 -my-2 overflow-x-auto whitespace-nowrap sm:-mx-6">
                    <div class="inline-block min-w-full px-4 py-2 align-middle sm:px-6">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-deep-indigo/[0.06]">
                                    <th class="whitespace-nowrap py-2 pr-4 text-left font-medium text-deep-indigo">Tool</th>
                                    <th class="py-2 text-left font-medium text-deep-indigo">Description</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-deep-indigo/[0.06]">
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">search_thoughts</td><td class="py-2.5 text-slate-brand">Semantic search over your thoughts</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">browse_recent</td><td class="py-2.5 text-slate-brand">List recent thoughts</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">thought_stats</td><td class="py-2.5 text-slate-brand">Count of your thoughts</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">capture_thought</td><td class="py-2.5 text-slate-brand">Save a new thought (or comment)</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">capture_plan</td><td class="py-2.5 text-slate-brand">Save structured docs (plans, decisions, dev/support/spec notes, research, meeting notes); use <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">doc_type</code> and <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">plan_slug</code> for tags and Stream</td></tr>
                                <tr><td class="py-2.5 pr-4 align-top font-mono text-xs text-deep-indigo">capture_meeting<br>add_meeting<br>add_meeting_notes</td><td class="py-2.5 text-slate-brand">Three names for the <strong class="text-deep-indigo">same</strong> action as <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_plan</code> with <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">doc_type</code> fixed to <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">meeting</code> (no <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">doc_type</code> parameter). Meeting notes appear in <a href="{{ route('idea.stream.meetings') }}" class="font-medium text-memory-violet hover:underline">Stream → Meetings</a>.</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">capture_idea</td><td class="py-2.5 text-slate-brand">Save an idea (typed thought with optional logged date)</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">get_ideas</td><td class="py-2.5 text-slate-brand">List ideas to revisit</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">research_idea</td><td class="py-2.5 text-slate-brand">Queue background research for an idea</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">capture_video</td><td class="py-2.5 text-slate-brand">Save a YouTube video as a thought</td></tr>
                                <tr><td class="py-2.5 pr-4 font-mono text-xs text-deep-indigo">sync_jira</td><td class="py-2.5 text-slate-brand">Refresh Jira activity into thoughts (shown only when Jira is enabled for your workspace)</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="text-sm/6 text-slate-brand mt-5"><strong class="text-deep-indigo">Agent prompt bundles</strong> — View or download Markdown prompts in the app: <a href="{{ route('help.panning-for-gold.index') }}" class="font-medium text-memory-violet hover:underline">Panning for Gold</a> · <a href="{{ route('help.research-to-decision.skills.index') }}" class="font-medium text-memory-violet hover:underline">Research-to-decision skills</a>. <strong class="text-deep-indigo">Repo Learning Coach</strong> (markdown sync + <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-[0.6875rem] text-deep-indigo ring-1 ring-deep-indigo/[0.06]">/learn</code>): <a href="{{ route('help.repo-learning-coach') }}" class="font-medium text-memory-violet hover:underline">help page</a>.</p>
            </div>

            <div id="plans" class="ideatub-surface px-5 py-5 sm:px-6 scroll-mt-24">
                <h3 class="text-base font-semibold text-deep-indigo mb-3">Plans and docs as thoughts (<code class="rounded bg-deep-indigo/[0.06] px-1.5 py-0.5 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_plan</code>)</h3>
                <div class="flex flex-col gap-4 text-sm/6 text-slate-brand">
                    <p>Use <strong class="text-deep-indigo">capture_plan</strong> to sync plans, decisions, dev notes, support docs, specs, research, or <strong class="text-deep-indigo">meeting notes</strong> into IdeaTub. Set <strong class="text-deep-indigo">doc_type</strong> to one of: <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">plan</code> (default), <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">decision</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">dev</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">support</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">spec</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">research</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">meeting</code>. The thought’s source and tag prefix match the doc type (e.g. <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">decision:project-spec</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">meeting:2026-04-01-standup</code>). For meetings you can instead call <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_meeting</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">add_meeting</code>, or <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">add_meeting_notes</code> (same parameters except <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">doc_type</code> is implied). Supported paths: <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">docs/superpowers/plans/*.md</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">decisions/*.md</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">dev/*.md</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">support/*.md</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">specs/*.md</code>; for research or meetings any logical path or omit.</p>
                    <ul role="list" class="flex flex-col gap-2 list-disc pl-5 marker:text-memory-violet/50">
                        <li><strong class="text-deep-indigo">Meetings in the app</strong> — Meeting notes show on <a href="{{ route('idea.stream.meetings') }}" class="font-medium text-memory-violet hover:underline">Stream → Meetings</a> (and in the main stream and home recent feed like other non-research thoughts).</li>
                        <li><strong class="text-deep-indigo">One thought per section</strong> — Call <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_plan</code> once per section. Use the same <strong class="text-deep-indigo">plan_slug</strong> for all sections. IdeaTub adds a tag <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">&lt;doc_type&gt;:&lt;slug&gt;</code> (e.g. <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">plan:2026-03-12-tag-and-stream</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">decision:project-spec</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">spec:tag-and-stream-design</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">meeting:weekly-sync</code>).</li>
                        <li><strong class="text-deep-indigo">Long-form view in Stream</strong> — Open <a href="{{ route('idea.stream') }}" class="font-medium text-memory-violet hover:underline">Stream</a> and add <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">?tag=&lt;doc_type&gt;-&lt;slug&gt;</code> (e.g. <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">/stream?tag=decision-project-spec</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">/stream?tag=spec-tag-and-stream-design</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">/stream?tag=meeting-weekly-sync</code>). All thoughts with that tag appear together.</li>
                        <li><strong class="text-deep-indigo">Share documents</strong> — On an eligible long-form document, use <strong class="text-deep-indigo">Share</strong> on the Stream card or thought detail page to create and copy a readonly link in place (optional password and expiry). Manage all links from <a href="{{ route('shared-research.index') }}" class="font-medium text-memory-violet hover:underline">Shared documents</a>.</li>
                        <li><strong class="text-deep-indigo">Projects</strong> — Use <a href="{{ route('projects.index') }}" class="font-medium text-memory-violet hover:underline">Projects</a> to group thoughts; on a thought’s detail page you can add it to projects and create <strong class="text-deep-indigo">typed links</strong> to other thoughts. Open a project’s <strong class="text-deep-indigo">Graph</strong> to see members and links; <strong class="text-deep-indigo">Share</strong> on a project creates a read-only hub (plus “read all” and per-item pages), with optional password and expiry, similar to shared documents.</li>
                        <li><strong class="text-deep-indigo">Optional: file_path</strong> — Pass the path (e.g. <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">decisions/project-spec.md</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">support/example-investigation.md</code>) so source_metadata records the source file.</li>
                        <li><strong class="text-deep-indigo">Optional: hierarchy</strong> — Create a root thought first, then pass its ID as <strong class="text-deep-indigo">parent_id</strong> for section thoughts so they appear as replies and under the shared tag.</li>
                    </ul>
                    <p>Parameters: <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">content</code> (required); <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">doc_type</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">file_path</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">plan_slug</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">parent_id</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">section_title</code>, <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">project</code> (code project name, e.g. repo or workspace), <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">tags</code> (optional).</p>
                </div>
            </div>

            @if(isset($cursorRuleContent) && $cursorRuleContent !== null)
            <div id="cursor-rule" class="ideatub-surface px-5 py-5 sm:px-6 scroll-mt-24">
                <h3 class="text-base font-semibold text-deep-indigo mb-3">Cursor rule: sync docs to IdeaTub</h3>
                <div class="flex flex-col gap-3 text-sm/6 text-slate-brand">
                    <p>Add this rule to a project so Cursor knows how to sync plans, decisions, dev, support, and spec markdown to IdeaTub via <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_plan</code>. Copy the file into that project’s <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">.cursor/rules/</code> (create the folder if needed). IdeaTub MCP must be configured in Cursor for that project.</p>
                    <div class="flex flex-wrap gap-2">
                        <a href="#" data-download-rule class="inline-flex items-center gap-1.5 rounded-xl bg-memory-violet/12 px-3.5 py-2 text-sm font-medium text-memory-violet transition hover:bg-memory-violet/20">Download ideatub-sync-docs.mdc</a>
                    </div>
                    <pre class="max-h-80 overflow-x-auto rounded-xl bg-deep-indigo/[0.04] p-4 text-xs/5 text-deep-indigo ring-1 ring-deep-indigo/[0.06] whitespace-pre-wrap"><code>{{ e($cursorRuleContent) }}</code></pre>
                </div>
            </div>
            @endif

            @if(isset($researchRuleContent) && $researchRuleContent !== null)
            <div id="cursor-rule-research" class="ideatub-surface px-5 py-5 sm:px-6 scroll-mt-24">
                <h3 class="text-base font-semibold text-deep-indigo mb-3">Cursor rule: save research to IdeaTub</h3>
                <div class="flex flex-col gap-3 text-sm/6 text-slate-brand">
                    <p>Add this rule so Cursor (or Claude Desktop) knows how to save research agent output to IdeaTub via <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_plan</code> with <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">doc_type</code> <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">research</code> and <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">project</code> for the research topic. Copy the file into the project’s <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">.cursor/rules/</code> (create the folder if needed). IdeaTub MCP must be configured for that project.</p>
                    <div class="flex flex-wrap gap-2">
                        <a href="#" data-download-research-rule class="inline-flex items-center gap-1.5 rounded-xl bg-memory-violet/12 px-3.5 py-2 text-sm font-medium text-memory-violet transition hover:bg-memory-violet/20">Download ideatub-sync-research.mdc</a>
                    </div>
                    <pre class="max-h-80 overflow-x-auto rounded-xl bg-deep-indigo/[0.04] p-4 text-xs/5 text-deep-indigo ring-1 ring-deep-indigo/[0.06] whitespace-pre-wrap"><code>{{ e($researchRuleContent) }}</code></pre>
                </div>
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

            <div class="grid gap-4 md:grid-cols-2">
                <div class="ideatub-surface-muted px-5 py-5 sm:px-6">
                    <p class="text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet/90 mb-2">Step 1</p>
                    <h3 class="text-base font-semibold text-deep-indigo mb-2">Get your MCP key</h3>
                    <p class="text-sm/6 text-slate-brand mb-3">Open your profile menu (avatar, top right) → <strong class="text-deep-indigo">MCP key</strong>. Click <strong class="text-deep-indigo">Create MCP key</strong>. Your key is shown <strong class="text-deep-indigo">once</strong> — copy it and store it securely (e.g. password manager).</p>
                    <a href="{{ route('settings.mcp-keys.index') }}" class="text-sm font-medium text-memory-violet hover:underline">Go to MCP key →</a>
                </div>

                <div class="ideatub-surface-muted px-5 py-5 sm:px-6">
                    <p class="text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet/90 mb-2">Step 2</p>
                    <h3 class="text-base font-semibold text-deep-indigo mb-2">Connection URL and auth</h3>
                    <div class="flex flex-col gap-2 text-sm/6 text-slate-brand">
                        <p><strong class="text-deep-indigo">Endpoint:</strong> <code class="break-all rounded bg-deep-indigo/[0.06] px-1.5 py-0.5 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">{{ url('/api/mcp') }}</code></p>
                        <p>Send your key either as <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">?key=YOUR_MCP_KEY</code> in the URL or in the <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">x-ideatub-key</code> header (header is preferred so the key is less likely to appear in logs).</p>
                        <p>Use the <strong class="text-deep-indigo">same key</strong> in every AI client.</p>
                    </div>
                </div>
            </div>

            <div class="ideatub-surface px-5 py-5 sm:px-6">
                <p class="text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet/90 mb-2">Step 3</p>
                <h3 class="text-base font-semibold text-deep-indigo mb-4">Connect your AI client</h3>
                <ul role="list" class="flex flex-col gap-4 divide-y divide-deep-indigo/[0.06] text-sm/6 text-slate-brand">
                    <li class="pb-4">
                        <strong class="text-deep-indigo">Claude Desktop</strong> — Settings → Connectors → Add custom connector. Name: IdeaTub. Remote MCP server URL: <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">{{ url('/api/mcp') }}?key=YOUR_KEY</code>. Enable the connector in each conversation (+ → Connectors).
                    </li>
                    <li class="py-4">
                        <strong class="text-deep-indigo">ChatGPT</strong> (web, paid) — Settings → Apps & Connectors → Advanced → Developer mode ON. Create → Name: IdeaTub, MCP endpoint URL: same URL with <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">?key=YOUR_KEY</code>, Authentication: None. If it doesn’t use tools automatically, say: “Use the IdeaTub search_thoughts tool to find my notes about …”.
                    </li>
                    <li class="py-4">
                        <strong class="text-deep-indigo">Cursor</strong> — Settings (⌘,) → Tools & MCP → Add new MCP server. Enter the full URL with <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">?key=YOUR_KEY</code>. Restart if needed.
                    </li>
                    <li class="py-4">
                        <strong class="text-deep-indigo">Claude Code (CLI)</strong> — <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">claude mcp add --transport http ideatub {{ url('/api/mcp') }} --header "x-ideatub-key: YOUR_KEY"</code>
                    </li>
                    <li class="pt-4">
                        <strong class="text-deep-indigo">Other clients</strong> — If they support a remote MCP or custom connector URL, use <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">{{ url('/api/mcp') }}?key=YOUR_KEY</code>.
                    </li>
                </ul>
            </div>

            <div class="ideatub-surface px-5 py-5 sm:px-6">
                <h3 class="text-base font-semibold text-deep-indigo mb-4">Troubleshooting</h3>
                <div class="-mx-4 -my-2 overflow-x-auto whitespace-nowrap sm:-mx-6">
                    <div class="inline-block min-w-full px-4 py-2 align-middle sm:px-6">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-deep-indigo/[0.06]">
                                <tr>
                                    <td class="w-36 py-2.5 align-top font-medium text-deep-indigo">401 Unauthorized</td>
                                    <td class="py-2.5 text-slate-brand">Key wrong or missing. Use <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">?key=...</code> or <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">x-ideatub-key</code> with no extra spaces. Create a new key on the <a href="{{ route('settings.mcp-keys.index') }}" class="font-medium text-memory-violet hover:underline">MCP key</a> page if you lost it.</td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 align-top font-medium text-deep-indigo">Tools don’t appear</td>
                                    <td class="py-2.5 text-slate-brand">Some clients expect a different MCP transport. Try the full URL with key; if it still fails, your client may need a bridge (see project docs).</td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 align-top font-medium text-deep-indigo">Search returns nothing</td>
                                    <td class="py-2.5 text-slate-brand">Capture some thoughts first (web or <code class="rounded bg-deep-indigo/[0.06] px-1 font-mono text-xs text-deep-indigo ring-1 ring-deep-indigo/[0.06]">capture_thought</code>). Ensure you’re using the key for the account that owns those thoughts.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <p class="text-xs/5 text-slate-brand/80">For more prompt ideas (memory migration, second brain, weekly review), see the <a href="{{ route('help') }}#example-prompts" class="text-memory-violet hover:underline">Example prompts</a> section above and the <a href="https://promptkit.natebjones.com/20260224_uq1_promptkit_1" class="text-memory-violet hover:underline" target="_blank" rel="noopener">Companion Prompt Kit</a>.</p>
        </section>

    </div>
</div>
@endsection
