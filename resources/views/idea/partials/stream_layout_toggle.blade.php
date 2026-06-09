<div class="ideatub-segment-track gap-0.5 p-0.5" role="group" aria-label="Layout">
    <button
        type="button"
        data-testid="layout-toggle-list"
        @click="setLayout('list')"
        :class="layout === 'list' ? 'ideatub-segment-tab-active' : 'ideatub-segment-tab'"
        class="p-1.5"
        aria-label="List layout"
    >
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>
    <button
        type="button"
        data-testid="layout-toggle-grid"
        @click="setLayout('grid')"
        :class="layout === 'grid' ? 'ideatub-segment-tab-active' : 'ideatub-segment-tab'"
        class="p-1.5"
        aria-label="Grid layout"
    >
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
    </button>
</div>
