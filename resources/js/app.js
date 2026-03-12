import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('captureBox', () => ({
  content: '',
  saving: false,
  message: '',
  messageType: 'success',
  errorField: '',

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

  async submitCapture() {
    const form = this.$el.querySelector('form');
    if (!form || this.saving) return;
    const content = (this.content || '').trim();
    if (!content) return;

    this.saving = true;
    this.message = '';
    this.messageType = 'success';
    this.errorField = '';

    const body = new FormData(form);
    const url = form.action;
    const csrf = body.get('_token');

    try {
      const res = await fetch(url, {
        method: 'POST',
        body,
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        if (res.status === 422 && data.errors && data.errors.content) {
          this.errorField = data.errors.content[0] || 'Invalid content.';
        } else {
          this.message = data.message || 'Something went wrong. Please try again.';
          this.messageType = 'error';
        }
        return;
      }

      this.content = '';
      this.message = data.message || 'Thought saved.';
      this.messageType = 'success';

      if (data.thought) {
        if (data.thought.parent_id) {
          this.appendCommentToParent(data.thought);
        } else {
          window.location = this.$el.dataset.ideaIndexUrl || window.location.pathname;
        }
      }

      setTimeout(() => { this.message = ''; }, 4000);
    } catch {
      this.message = 'Unable to save. Please try again.';
      this.messageType = 'error';
    } finally {
      this.saving = false;
    }
  },

  appendCommentToParent(thought) {
    const card = document.querySelector(`[data-thought-id="${thought.parent_id}"]`);
    if (!card) return;
    let list = card.querySelector('[data-comments-list]');
    if (!list) {
      list = document.createElement('ul');
      list.className = 'comments-list mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2';
      list.setAttribute('data-comments-list', '');
      card.appendChild(list);
    }
    list.classList.remove('hidden');
    list.removeAttribute('aria-hidden');
    const li = document.createElement('li');
    const contentP = document.createElement('p');
    contentP.className = 'text-[12.5px] text-slate-brand leading-relaxed';
    contentP.textContent = thought.content.length > 200 ? thought.content.slice(0, 200) + '…' : thought.content;
    const timeP = document.createElement('p');
    timeP.className = 'text-[10px] text-slate-brand/40 mt-0.5';
    timeP.textContent = thought.created_at_human || 'just now';
    li.appendChild(contentP);
    li.appendChild(timeP);
    list.appendChild(li);
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
