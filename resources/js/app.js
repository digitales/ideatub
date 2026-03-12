import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('captureBox', () => ({
  content: '',
  init() {
    const raw = this.$el.dataset.initialContent;
    this.content = raw !== undefined ? raw : '';
    if (this.$el.dataset.focusReply === '1') {
      this.$nextTick(() => this.focusCapture());
    }
  },
  focusCapture() {
    const el = this.$refs.captureTextarea;
    if (el && el.focus) el.focus();
  },
}));

Alpine.data('ideaShortcuts', () => ({
  searching: false,
  query: '',
  shortcutsOpen: false,
  ideaIndexUrl: '',

  init() {
    const el = this.$el;
    if (el?.dataset?.query !== undefined) this.query = el.dataset.query;
    if (el?.dataset?.ideaIndexUrl !== undefined) this.ideaIndexUrl = el.dataset.ideaIndexUrl;
  },

  handleKey(e) {
    const el = document.activeElement;
    const inInput = el && el.matches('input, textarea, select');
    if (inInput && e.key !== 'Escape') return;
    if (e.key === 'Escape') {
      if (this.shortcutsOpen) this.shortcutsOpen = false;
      else if (this.searching) this.searching = false;
      else if (window.location.search.includes('parent_id') && this.ideaIndexUrl)
        window.location = this.ideaIndexUrl;
      e.preventDefault();
      return;
    }
    if (inInput) return;
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      this.searching = true;
      this.$nextTick(() => this.$refs.searchInput?.focus());
      e.preventDefault();
      return;
    }
    if ((e.metaKey || e.ctrlKey) && e.key === '/') {
      this.$dispatch('focus-capture');
      e.preventDefault();
      return;
    }
    if (e.key === '?') {
      this.shortcutsOpen = true;
      e.preventDefault();
      return;
    }
    if (e.key === 'j') {
      this.$dispatch('thought-nav', { direction: 'next' });
      e.preventDefault();
      return;
    }
    if (e.key === 'k') {
      this.$dispatch('thought-nav', { direction: 'prev' });
      e.preventDefault();
      return;
    }
    if (e.key === 'Enter') {
      this.$dispatch('thought-reply');
      e.preventDefault();
    }
  },
}));

Alpine.start();
