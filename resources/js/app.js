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
  drafts: [],
  currentDraftId: null,
  draftsExpanded: false,
  draftSaveTimeout: null,
  focusOverlayOpen: false,
  isReplyMode: false,
  noChunking: false,

  init() {
    this._rootEl = this.$el;
    const raw = this._rootEl.dataset.initialContent;
    this.content = raw !== undefined ? raw : '';
    this.isReplyMode = this._rootEl.dataset.focusReply === '1';
    const noChunkCb = this._rootEl.querySelector('input[name="no_chunking"]');
    if (noChunkCb && noChunkCb.checked) this.noChunking = true;
    if (!this.isReplyMode) this.fetchDrafts();
    if (this.isReplyMode) this.$nextTick(() => this.focusCapture());
    this.$watch('content', () => this.scheduleDraftSave());
    this.$watch('noChunking', () => this.scheduleDraftSave());
    this._escapeHandler = (e) => {
      if (e.key === 'Escape' && this.focusOverlayOpen) this.focusOverlayOpen = false;
    };
    document.addEventListener('keydown', this._escapeHandler);
  },

  destroy() {
    if (this._escapeHandler) document.removeEventListener('keydown', this._escapeHandler);
  },

  focusCapture() {
    const el = this.$refs.captureTextarea;
    if (el && el.focus) el.focus();
  },

  get draftsUrl() {
    return this._rootEl?.dataset?.draftsUrl || '';
  },

  get csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  },

  async fetchDrafts() {
    const url = this.draftsUrl;
    if (!url) return;
    try {
      const res = await fetch(url, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': this.csrfToken,
        },
      });
      if (res.ok) this.drafts = await res.json();
    } catch {
      // leave drafts as-is on failure
    }
  },

  scheduleDraftSave() {
    if (this.draftSaveTimeout) clearTimeout(this.draftSaveTimeout);
    if (this.isReplyMode) return;
    this.draftSaveTimeout = setTimeout(() => this.saveDraft(), 1500);
  },

  async saveDraft() {
    if (this.isReplyMode) return;
    const trimmed = (this.content || '').trim();
    if (!trimmed) return;
    const url = this.draftsUrl;
    if (!url) return;
    const body = JSON.stringify({ content: trimmed, no_chunking: this.noChunking });
    const isUpdate = !!this.currentDraftId;
    const reqUrl = isUpdate ? `${url}/${this.currentDraftId}` : url;
    const method = isUpdate ? 'PATCH' : 'POST';
    try {
      const res = await fetch(reqUrl, {
        method,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body,
      });
      if (res.ok) {
        if (!isUpdate) {
          const data = await res.json().catch(() => ({}));
          if (data.id) this.currentDraftId = data.id;
        }
        this.fetchDrafts();
      } else {
        this.message = "Draft couldn't be saved";
        this.messageType = 'error';
        setTimeout(() => { this.message = ''; }, 4000);
      }
    } catch {
      this.message = "Draft couldn't be saved";
      this.messageType = 'error';
      setTimeout(() => { this.message = ''; }, 4000);
    }
  },

  async loadDraft(id) {
    const url = this.draftsUrl;
    if (!url) return;
    try {
      const res = await fetch(`${url}/${id}`, {
        method: 'GET',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
      });
      if (!res.ok) return;
      const data = await res.json();
      this.content = data.content ?? '';
      this.noChunking = !!data.no_chunking;
      this.currentDraftId = id;
      this.draftsExpanded = false;
      this.$nextTick(() => this.focusCapture());
    } catch {
      // no-op
    }
  },

  async discardDraft(id) {
    const url = this.draftsUrl;
    if (!url) return;
    try {
      const res = await fetch(`${url}/${id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
      });
      if (!res.ok) return;
      this.drafts = this.drafts.filter((d) => d.id != id);
      if (this.currentDraftId == id) {
        this.currentDraftId = null;
        this.content = '';
        this.noChunking = false;
      }
    } catch {
      // no-op
    }
  },

  toggleFocus() {
    this.focusOverlayOpen = !this.focusOverlayOpen;
    if (this.focusOverlayOpen) {
      this.$nextTick(() => this.focusCapture());
    } else {
      const btn = this.$refs.focusButton;
      if (btn && btn.focus) btn.focus();
    }
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

      if (this.currentDraftId) {
        try {
          await fetch(`${this.draftsUrl}/${this.currentDraftId}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
          });
        } catch {
          // ignore
        }
        this.currentDraftId = null;
      }

      this.content = '';
      this.message = data.message || 'Thought saved.';
      this.messageType = 'success';
      this.fetchDrafts();

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

Alpine.data('thoughtContentEditor', ({ content, updateUrl, editable = false, previewMaxLength = null }) => ({
  content: content || '',
  originalContent: content || '',
  draftContent: content || '',
  updateUrl: updateUrl || '',
  editable: !!editable,
  previewMaxLength: previewMaxLength == null || previewMaxLength === '' ? null : Number(previewMaxLength),
  editing: false,
  saving: false,
  error: '',

  get viewContent() {
    if (this.previewMaxLength == null || Number.isNaN(this.previewMaxLength) || this.previewMaxLength <= 0) {
      return this.content;
    }
    const max = this.previewMaxLength;
    const s = this.content || '';
    if (s.length <= max) return s;
    return `${s.slice(0, max)}...`;
  },

  get saveDisabled() {
    return (
      this.saving ||
      this.draftContent.trim() === '' ||
      this.draftContent === this.originalContent
    );
  },

  startEdit() {
    if (!this.editable) return;
    this.editing = true;
    this.draftContent = this.content;
    this.error = '';
    this.$nextTick(() => this.$el.querySelector('textarea')?.focus());
  },

  cancelEdit() {
    this.editing = false;
    this.draftContent = this.content;
    this.error = '';
  },

  async saveEdit() {
    const trimmed = this.draftContent.trim();
    if (!trimmed || this.saveDisabled) return;

    this.saving = true;
    this.error = '';

    try {
      const res = await fetch(this.updateUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ content: trimmed }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        if (res.status === 422 && data.errors?.content?.[0]) this.error = data.errors.content[0];
        else if (res.status === 401 || res.status === 403 || res.status === 419)
          this.error = 'Please sign in again.';
        else if (res.status === 404) this.error = 'This thought no longer exists.';
        else this.error = data.message || 'Unable to update thought.';
        return;
      }

      this.content = data.content ?? trimmed;
      this.originalContent = this.content;
      this.draftContent = this.content;
      this.editing = false;
    } catch {
      this.error = 'Unable to update thought.';
    } finally {
      this.saving = false;
    }
  },
}));

Alpine.data('inboxPage', () => ({
  flashSuccess: '',
  flashError: '',
  inboxCount: 0,

  init() {
    const raw = this.$el?.dataset?.inboxInitialCount;
    this.inboxCount =
      raw !== undefined && raw !== '' ? Number.parseInt(String(raw), 10) || 0 : 0;
  },

  get csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  },

  accountMenuAriaLabel(count) {
    const n = typeof count === 'number' ? count : Number.parseInt(String(count), 10) || 0;
    if (n > 99) {
      return 'Account menu, inbox has more than 99 actionable items';
    }
    if (n > 0) {
      return `Account menu, inbox has ${n} actionable ${n === 1 ? 'item' : 'items'}`;
    }
    return 'Account menu';
  },

  applyRemainingCount(remaining) {
    const count =
      typeof remaining === 'number' ? remaining : Number.parseInt(String(remaining), 10) || 0;
    this.inboxCount = count;

    const avatarBtn = document.querySelector('[data-inbox-avatar-button]');
    if (avatarBtn) {
      avatarBtn.setAttribute('aria-label', this.accountMenuAriaLabel(count));
    }

    const badges = document.querySelectorAll('[data-inbox-badge]');
    if (count <= 0) {
      badges.forEach((el) => el.remove());
      return;
    }
    const label = count > 99 ? '99+' : String(count);
    badges.forEach((el) => {
      el.textContent = label;
    });
  },

  async submitAction(event) {
    event.preventDefault();
    const form = event.target;
    if (!form || form.tagName !== 'FORM') {
      return;
    }

    const submitter = event.submitter;
    const usedSubmitter = submitter && submitter.matches('button[type="submit"]');

    if (usedSubmitter) {
      submitter.disabled = true;
    }

    const body = new FormData(form);
    const url = form.getAttribute('action');
    const token = body.get('_token') || this.csrfToken;

    const reenable = () => {
      if (usedSubmitter && submitter) {
        submitter.disabled = false;
      }
    };

    try {
      const res = await fetch(url, {
        method: 'POST',
        body,
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-TOKEN': String(token) } : {}),
        },
      });

      const ct = (res.headers.get('Content-Type') || '').toLowerCase();
      const isJson = ct.includes('application/json');

      if (res.ok && !isJson) {
        window.location.href = window.location.href;
        return;
      }

      let data = {};
      if (isJson) {
        try {
          data = await res.json();
        } catch {
          data = {};
        }
      }

      if (!res.ok) {
        reenable();
        this.flashError =
          data.message ||
          (res.status === 419
            ? 'Session expired. Please refresh the page and try again.'
            : 'Something went wrong. Please try again.');
        this.flashSuccess = '';
        return;
      }

      if (!data || data.ok !== true) {
        window.location.href = window.location.href;
        return;
      }

      const itemId = data.item_id;
      if (itemId != null) {
        const card = document.querySelector(`[data-inbox-item-id="${itemId}"]`);
        if (card) {
          card.remove();
        }
      }

      const previousInboxCount = this.inboxCount;
      if (typeof data.remaining_count === 'number') {
        this.applyRemainingCount(data.remaining_count);
      } else {
        this.applyRemainingCount(Math.max(0, previousInboxCount - 1));
      }

      this.flashSuccess = data.message || 'Done.';
      this.flashError = '';

      const stillOnPage = document.querySelectorAll('[data-inbox-item-id]').length;
      if (stillOnPage === 0) {
        window.location.href = window.location.href;
        return;
      }

      setTimeout(() => {
        this.flashSuccess = '';
      }, 4000);
    } catch {
      reenable();
      this.flashError = 'Unable to complete action. Please try again.';
      this.flashSuccess = '';
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

  requestEdit() {
    this.menuOpen = false;
    this.confirmOpen = false;
    this.error = '';
    window.dispatchEvent(
      new CustomEvent('thought-edit-requested', {
        detail: { thoughtId },
      }),
    );
  },

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
    this._keydownHandler = (e) => {
      if (e.key !== 'Escape') return;
      this.closeMenu();
      this.cancelConfirm();
    };
    window.addEventListener('keydown', this._keydownHandler);
  },

  destroy() {
    if (this._keydownHandler) window.removeEventListener('keydown', this._keydownHandler);
  },
}));

Alpine.start();
