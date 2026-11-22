// Small, dependency-free UI helpers shared by all pages.
export const $ = (sel, root = document) => root.querySelector(sel);

export const esc = (s) =>
  String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** 1900 -> "€19", 5130 -> "€51.30" */
export const money = (cents) => `€${(cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)}`;

export const fmtDate = (iso) => {
  const s = String(iso);
  const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
  // "2027-01-06" is a calendar day, not a UTC instant: build it in local time so it never shows as the day before
  const d = day ? new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3])) : new Date(s.replace(' ', 'T').slice(0, 19));
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};

export function toast(message, type = 'success') {
  let host = $('#toasts');
  if (!host) {
    host = document.createElement('div');
    host.id = 'toasts';
    host.className = 'fixed bottom-4 inset-x-4 sm:left-auto sm:right-6 sm:w-96 space-y-2 z-50';
    host.setAttribute('aria-live', 'polite');
    document.body.append(host);
  }
  const el = document.createElement('div');
  el.className = 'rounded-xl px-4 py-3 text-sm font-medium shadow-lg text-white';
  el.style.background = type === 'error' ? '#b3402a' : '#0C1C2A';
  el.textContent = message;
  host.append(el);
  setTimeout(() => el.remove(), 5500);
}

/** Render a list of problems into a container (empty list clears it). */
export function renderErrors(container, errors) {
  if (!container) return;
  if (!errors || !errors.length) {
    container.innerHTML = '';
    return;
  }
  container.innerHTML = `<div role="alert" class="rounded-xl px-4 py-3 text-sm space-y-1" style="background:#FBE9E1;color:#8a2f17;border:1px solid #f0c7b6">
    ${errors.map((e) => `<p>${esc(e)}</p>`).join('')}</div>`;
}

/** Disable a button and show a busy label while an async action runs. */
export async function withBusy(button, fn) {
  const label = button.textContent;
  button.disabled = true;
  button.textContent = 'Please wait…';
  try {
    return await fn();
  } finally {
    button.disabled = false;
    button.textContent = label;
  }
}

export function formatCardInput(input) {
  input.addEventListener('input', () => {
    const digits = input.value.replace(/\D/g, '').slice(0, 19);
    input.value = digits.replace(/(.{4})/g, '$1 ').trim();
  });
}

export function formatExpiryInput(input) {
  input.addEventListener('input', () => {
    let d = input.value.replace(/\D/g, '').slice(0, 4);
    if (d.length >= 3) d = `${d.slice(0, 2)}/${d.slice(2)}`;
    input.value = d;
  });
}
