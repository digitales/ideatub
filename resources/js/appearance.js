const storeUrl = () =>
  document.querySelector('meta[name="appearance-store-url"]')?.getAttribute('content') ?? '';

const csrfToken = () =>
  document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

function systemPrefersDark() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function applyAppearance(appearance) {
  const root = document.documentElement;
  root.dataset.appearance = appearance;

  const dark =
    appearance === 'dark' || (appearance === 'system' && systemPrefersDark());

  root.classList.toggle('dark', dark);
  updateControlUI(appearance);
}

function updateControlUI(appearance) {
  document.querySelectorAll('[data-appearance-option]').forEach((button) => {
    const active = button.getAttribute('data-appearance-option') === appearance;
    button.setAttribute('aria-pressed', active ? 'true' : 'false');
    button.classList.toggle('ideatub-segment-tab-active', active);
    button.classList.toggle('ideatub-segment-tab', !active);
  });
}

export async function setAppearance(appearance) {
  const url = storeUrl();
  if (!url) {
    applyAppearance(appearance);

    return;
  }

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({ appearance }),
  });

  if (!response.ok) {
    return;
  }

  applyAppearance(appearance);
}

const systemMedia = window.matchMedia('(prefers-color-scheme: dark)');
systemMedia.addEventListener('change', () => {
  if (document.documentElement.dataset.appearance === 'system') {
    applyAppearance('system');
  }
});

window.ideatubAppearance = {
  applyAppearance,
  setAppearance,
};

document.addEventListener('DOMContentLoaded', () => {
  const appearance = document.documentElement.dataset.appearance ?? 'system';
  updateControlUI(appearance);
});
