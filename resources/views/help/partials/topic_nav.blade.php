@php
    $topics = [
        [
            'href' => '#shortcuts',
            'title' => 'Keyboard shortcuts',
            'description' => 'Quick capture, search, and navigation keys.',
        ],
        [
            'href' => '#youtube',
            'title' => 'YouTube capture',
            'description' => 'Paste a link to save video thoughts with optional research.',
        ],
        [
            'href' => '#example-prompts',
            'title' => 'Example prompts',
            'description' => 'Companion Prompt Kit templates for your Open Brain.',
        ],
        [
            'href' => '#mcp',
            'title' => 'MCP integration',
            'description' => 'Connect Claude, Cursor, ChatGPT, and other MCP clients.',
        ],
    ];

    $guides = [
        [
            'href' => route('help.research-to-decision'),
            'title' => 'Research-to-decision',
            'description' => 'OB1 skills workflow with IdeaTub capture.',
        ],
        [
            'href' => route('help.repo-learning-coach'),
            'title' => 'Repo Learning Coach',
            'description' => 'Markdown curriculum synced under /learn.',
        ],
        [
            'href' => route('help.panning-for-gold.index'),
            'title' => 'Panning for Gold',
            'description' => 'Prompts for meetings and brain dumps.',
        ],
        [
            'href' => route('help.working-memory-authoring.index'),
            'title' => 'Working memory authoring',
            'description' => 'Refresh scoped working memory via MCP.',
        ],
        [
            'href' => route('help.working-memory-corpus-sync'),
            'title' => 'Corpus sync',
            'description' => 'Bulk-import captures into working memory.',
        ],
        [
            'href' => route('help.attention-pulse'),
            'title' => 'Attention Pulse',
            'description' => 'Memory health, commitments, and Jira follow-ups.',
        ],
        [
            'href' => route('help.memory-graph'),
            'title' => 'Memory graph',
            'description' => 'Local, project, tag, semantic, and vault graph levels.',
        ],
    ];
@endphp

<nav aria-label="Help topics" class="mb-10">
    <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-3">On this page</p>
    <ul role="list" class="grid gap-3 sm:grid-cols-2">
        @foreach ($topics as $topic)
            <li>
                <a
                    href="{{ $topic['href'] }}"
                    class="ideatub-surface group flex h-full flex-col px-4 py-3.5 transition hover:ring-memory-violet/25 dark:hover:ring-violet-400/30"
                >
                    <span class="text-sm font-medium text-deep-indigo group-hover:text-memory-violet">{{ $topic['title'] }}</span>
                    <span class="mt-0.5 text-sm/6 text-slate-brand">{{ $topic['description'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>

<div class="mb-10">
    <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-3">Guides</p>
    <ul role="list" class="grid gap-3 sm:grid-cols-2">
        @foreach ($guides as $guide)
            <li>
                <a
                    href="{{ $guide['href'] }}"
                    class="ideatub-surface-muted group flex h-full flex-col px-4 py-3.5 transition hover:ring-memory-violet/20 dark:hover:ring-violet-400/25"
                >
                    <span class="text-sm font-medium text-deep-indigo group-hover:text-memory-violet">{{ $guide['title'] }}</span>
                    <span class="mt-0.5 text-sm/6 text-slate-brand">{{ $guide['description'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
