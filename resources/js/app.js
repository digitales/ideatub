import './bootstrap';
import Alpine from 'alpinejs';
import Pusher from 'pusher-js';
import Echo from 'laravel-echo';

window.Alpine = Alpine;
window.Pusher = Pusher;

document.addEventListener('DOMContentLoaded', function () {
  const rt = window.ideatub?.realtime;
  if (rt?.driver === 'reverb' && rt?.reverb_key) {
    try {
      window.Echo = new Echo({
        broadcaster: 'reverb',
        key: rt.reverb_key,
        wsHost: rt.reverb_host || window.location.hostname,
        wsPort: rt.reverb_port || 80,
        wssPort: rt.reverb_port || 443,
        forceTLS: (rt.reverb_scheme || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
          headers: {
            'X-CSRF-TOKEN':
              document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            Accept: 'application/json',
          },
        },
      });
    } catch (e) {
      console.warn('Reverb Echo init failed:', e);
    }
  }
});

Alpine.data('captureBox', () => ({
  content: '',
  saving: false,
  message: '',
  messageType: 'success',
  errorField: '',

  init() {
    this._rootEl = this.$el;
    const raw = this._rootEl.dataset.initialContent;
    this.content = raw !== undefined ? raw : '';
    if (this._rootEl.dataset.focusReply === '1') {
      this.$nextTick(() => this.focusCapture());
    }
  },

  focusCapture() {
    const el = this.$refs.captureTextarea;
    if (el && el.focus) el.focus();
  },

  async submitCapture() {
    const root = this._rootEl || this.$el;
    const form = root.tagName === 'FORM' ? root : root.querySelector('form');
    if (!form) return;
    if (this.saving) return;
    const content = (this.content || '').trim();
    if (!content) {
      this.message = 'Add some text to save.';
      this.messageType = 'error';
      this.errorField = '';
      setTimeout(() => { this.message = ''; }, 3000);
      return;
    }

    this.saving = true;
    this.message = '';
    this.messageType = 'success';
    this.errorField = '';

    // Ensure textarea value is in sync with Alpine model before building FormData
    const textarea = form.querySelector('[name="content"]');
    if (textarea) textarea.value = content;

    const body = new FormData(form);
    const url = form.action;
    const csrf = body.get('_token') || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

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
        if (res.status === 419) {
          this.message = 'Session expired. Please refresh the page and try again.';
          this.messageType = 'error';
        } else if (res.status === 422 && data.errors && data.errors.content) {
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
          window.location = (this._rootEl && this._rootEl.dataset.ideaIndexUrl) || window.location.pathname;
        }
      } else {
        // Server may have returned HTML (e.g. redirect); refresh so the list updates
        window.location = (this._rootEl && this._rootEl.dataset.ideaIndexUrl) || window.location.pathname;
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

Alpine.data('thoughtTagRow', (initialTags, updateUrl, editable = false) => ({
  tags: [],
  updateUrl: '',
  streamBaseUrl: '',
  editable: !!editable,
  editing: false,
  error: '',
  tagPillClasses: [
    'bg-memory-violet/10 text-memory-violet',
    'bg-neural-teal/10 text-neural-teal',
    'bg-deep-indigo/8 text-slate-brand',
  ],

  init() {
    this.tags = Array.isArray(initialTags) ? [...initialTags] : [];
    this.updateUrl = updateUrl || '';
    this.streamBaseUrl = this.$el.dataset.streamBaseUrl || '';
    this.editable = editable !== undefined ? !!editable : false;
  },

  slugify(tag) {
    return String(tag)
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/gi, '_')
      .replace(/^_+|_+$/g, '');
  },

  async remove(index) {
    const previous = [...this.tags];
    this.tags.splice(index, 1);
    this.error = '';
    try {
      const res = await fetch(this.updateUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ tags: this.tags }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        this.error = data.message || (data.errors && JSON.stringify(data.errors)) || 'Failed to update tags.';
        this.tags = previous;
      } else if (data.tags) {
        this.tags = data.tags;
      }
    } catch {
      this.error = 'Unable to update tags. Please try again.';
      this.tags = previous;
    }
  },

  async addFromInput() {
    const input = this.$refs.addInput;
    const value = (input?.value || '').trim().toLowerCase();
    if (!value || this.tags.includes(value)) {
      if (input) input.value = '';
      return;
    }
    this.tags.push(value);
    if (input) input.value = '';
    this.error = '';
    const previous = [...this.tags];
    try {
      const res = await fetch(this.updateUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ tags: this.tags }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        this.error = data.message || (data.errors && JSON.stringify(data.errors)) || 'Failed to add tag.';
        this.tags = previous.slice(0, -1);
      } else if (data.tags) {
        this.tags = data.tags;
      }
    } catch {
      this.error = 'Unable to add tag. Please try again.';
      this.tags = previous.slice(0, -1);
    }
  },
}));

Alpine.data('thoughtCardActions', (deleteUrl, thoughtId) => ({
  menuOpen: false,
  confirmOpen: false,
  deleting: false,
  error: '',
  deleteUrl,
  thoughtId,

  get cardEl() {
    return this.$el?.closest('[data-thought-id]') ?? null;
  },

  openMenu() { this.menuOpen = true; this.confirmOpen = false; this.error = ''; },
  closeMenu() { this.menuOpen = false; },
  showConfirm() { this.menuOpen = false; this.confirmOpen = true; this.error = ''; },
  cancelConfirm() { this.confirmOpen = false; this.error = ''; },

  async submitDelete() {
    this.deleting = true;
    this.error = '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    try {
      const res = await fetch(this.deleteUrl, {
        method: 'DELETE',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
      });
      if (res.status === 204) {
        const el = this.cardEl;
        if (el) el.remove();
        return;
      }
      if (res.status === 404) {
        const el = this.cardEl;
        if (el) el.remove();
        return;
      }
      if (res.status === 401 || res.status === 403) {
        this.error = 'Please sign in again.';
        this.confirmOpen = false;
        return;
      }
      const data = await res.json().catch(() => ({}));
      if (res.status === 422) {
        this.error = data.message || 'This thought has comments. Remove them first.';
        return;
      }
      this.error = "Couldn't delete. Try again.";
    } catch {
      this.error = "Couldn't delete. Try again.";
    } finally {
      this.deleting = false;
    }
  },

  init() {
    window.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      this.closeMenu();
      this.cancelConfirm();
    });
  },
}));

Alpine.start();
