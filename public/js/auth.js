// Log in / Register dialog. openAuth() resolves with the signed-in user, or null if the dialog was dismissed.
import { api, ApiError } from './api.js';
import { $, esc, renderErrors, withBusy } from './ui.js';

let dialog = null;
let resolver = null;
let mode = 'login';

function build() {
  if (dialog) return;
  dialog = document.createElement('dialog');
  dialog.setAttribute('aria-labelledby', 'auth-title');
  dialog.innerHTML = `
    <form id="auth-form" class="p-7 space-y-4" novalidate>
      <div class="flex items-start justify-between gap-4">
        <h2 id="auth-title" class="display text-2xl">Log in</h2>
        <button type="button" id="auth-close" class="text-2xl leading-none text-muted hover:text-ink" aria-label="Close">×</button>
      </div>
      <p id="auth-msg" class="text-muted text-[0.95rem]"></p>
      <div class="flex gap-2" role="tablist">
        <button type="button" role="tab" data-mode="login" class="tab">Log in</button>
        <button type="button" role="tab" data-mode="register" class="tab">Create account</button>
      </div>
      <div id="auth-errors"></div>
      <label id="auth-name-wrap" class="block">
        <span class="sr-only-label">Full name</span>
        <input class="input" name="full_name" placeholder="Full name" autocomplete="name">
      </label>
      <label class="block"><span class="sr-only-label">Email</span>
        <input class="input" type="email" name="email" placeholder="Email" autocomplete="email" required></label>
      <label class="block"><span class="sr-only-label">Password</span>
        <input class="input" type="password" name="password" placeholder="Password (min. 8 characters)" autocomplete="current-password" required></label>
      <button id="auth-submit" class="btn btn-coral w-full">Log in</button>
    </form>`;
  document.body.append(dialog);

  dialog.addEventListener('close', () => finish(null));
  $('#auth-close', dialog).addEventListener('click', () => dialog.close());
  dialog.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
  $('#auth-form', dialog).addEventListener('submit', onSubmit);
}

function setMode(m) {
  mode = m;
  const reg = m === 'register';
  $('#auth-title', dialog).textContent = reg ? 'Create your account' : 'Log in';
  $('#auth-submit', dialog).textContent = reg ? 'Create account' : 'Log in';
  $('#auth-name-wrap', dialog).style.display = reg ? '' : 'none';
  $('[name=password]', dialog).autocomplete = reg ? 'new-password' : 'current-password';
  dialog.querySelectorAll('[data-mode]').forEach((b) => {
    const on = b.dataset.mode === m;
    b.setAttribute('aria-selected', String(on));
    if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
  });
  renderErrors($('#auth-errors', dialog), []);
}

async function onSubmit(e) {
  e.preventDefault();
  const form = e.currentTarget;
  const f = Object.fromEntries(new FormData(form));
  const errBox = $('#auth-errors', dialog);
  const problems = [];
  if (!String(f.email).trim() || !f.password) problems.push('Please enter your email and password.');
  if (mode === 'register' && !String(f.full_name).trim()) problems.push('Please enter your name.');
  if (mode === 'register' && String(f.password).length < 8) problems.push('Password must be at least 8 characters.');
  if (problems.length) return renderErrors(errBox, problems);

  await withBusy($('#auth-submit', dialog), async () => {
    try {
      const { user } = mode === 'register'
        ? await api.register(String(f.full_name).trim(), String(f.email).trim(), f.password)
        : await api.login(String(f.email).trim(), f.password);
      form.reset();
      window.dispatchEvent(new CustomEvent('auth:change', { detail: user }));
      finish(user);
    } catch (err) {
      renderErrors(errBox, err instanceof ApiError ? err.messages : ['Unexpected error. Please try again.']);
    }
  });
}

function finish(user) {
  const r = resolver;
  resolver = null;
  if (dialog?.open) dialog.close();
  if (r) r(user);
}

export function openAuth({ mode: m = 'login', message = '' } = {}) {
  build();
  setMode(m);
  $('#auth-msg', dialog).innerHTML = esc(message);
  if (!dialog.open) dialog.showModal();
  return new Promise((resolve) => { resolver = resolve; });
}
