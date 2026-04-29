{{--
  Cursor setup for downloaded OB1-style skills (R2D) or Panning prompts.
  Expects $variant: 'r2d' | 'pfg'
--}}
@php
    $isR2d = ($variant ?? 'r2d') === 'r2d';
@endphp
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mt-6">
    <h2 class="text-base font-semibold text-deep-indigo mb-3">Using with Cursor</h2>
    <p class="text-sm text-slate-brand mb-4">Cursor loads <strong>project instructions</strong> from <code class="bg-memory-violet/10 px-1 rounded text-xs">.cursor/rules/</code> (<code class="bg-memory-violet/10 px-1 rounded text-xs">*.mdc</code>). It does <strong>not</strong> auto-load <code class="bg-memory-violet/10 px-1 rounded text-xs">~/.claude/skills/</code> (that path is for Claude Code). Use the steps below so the agent reads the right files.</p>
    <ol class="text-sm text-slate-brand space-y-3 list-decimal list-inside marker:text-deep-indigo">
        <li><strong class="text-deep-indigo">Connect IdeaTub MCP in Cursor.</strong> Add the MCP server URL and your key per <a href="{{ route('help') }}#mcp" class="text-memory-violet hover:underline">Help → MCP integration</a> (header <code class="bg-memory-violet/10 px-1 rounded text-[11px]">x-ideatub-key</code> preferred).</li>
        <li><strong class="text-deep-indigo">Download the bundle</strong> using <strong>Download ZIP</strong> above (or grab individual <code class="bg-memory-violet/10 px-1 rounded text-[11px]">.md</code> files).</li>
        @if($isR2d)
        <li><strong class="text-deep-indigo">Unzip at your project root.</strong> The ZIP already mirrors IdeaTub paths: <code class="bg-memory-violet/10 px-1 rounded text-[11px]">.cursor/rules/research-to-decision-ideatub.mdc</code>, <code class="bg-memory-violet/10 px-1 rounded text-[11px]">resources/prompts/research-to-decision-ideatub.md</code>, and <code class="bg-memory-violet/10 px-1 rounded text-[11px]">resources/skills/research-to-decision/&lt;skill&gt;/</code>. Merge folders into your repo (read <code class="bg-memory-violet/10 px-1 rounded text-[11px]">CURSOR-BUNDLE.txt</code> inside the archive). Adjust <code class="bg-memory-violet/10 px-1 rounded text-[11px]">globs:</code> in the rule if your paths differ.</li>
        @else
        <li><strong class="text-deep-indigo">Unzip at your project root.</strong> The ZIP includes <code class="bg-memory-violet/10 px-1 rounded text-[11px]">.cursor/rules/panning-for-gold.mdc</code> and <code class="bg-memory-violet/10 px-1 rounded text-[11px]">resources/prompts/panning-for-gold-*.md</code>. Merge into your repo (see <code class="bg-memory-violet/10 px-1 rounded text-[11px]">CURSOR-BUNDLE.txt</code> in the archive).</li>
        @endif
        <li><strong class="text-deep-indigo">Reload Cursor</strong> (or reload the window) so rules and MCP tools are picked up.</li>
    </ol>
    <p class="text-xs text-slate-brand/80 mt-4">Repo reference (paths and rule sources): <code class="bg-memory-violet/10 px-1 rounded text-[11px]">docs/cursor-mcp-integration.md</code> and <code class="bg-memory-violet/10 px-1 rounded text-[11px]">CLAUDE.md</code> in the IdeaTub project.</p>
</div>
