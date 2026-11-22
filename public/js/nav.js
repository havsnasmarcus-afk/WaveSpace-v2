// Top navigation (Map · Book · My locker · Log in / Register). Stays in sync through the auth:change event.
import { api, isStaff } from './api.js';
import { openAuth } from './auth.js';
import { esc, toast } from './ui.js';

const TABS = [
  ['index.html', 'Map'],
  ['booking.html', 'Book'],
  ['account.html', 'My locker'],
];

export async function initNav() {
  let user = null;
  try { ({ user } = await api.session()); } catch { /* backend unreachable: show the logged-out nav */ }
  paint(user);
  window.addEventListener('auth:change', (e) => paint(e.detail));
  return user;
}

function paint(user) {
  const host = document.getElementById('nav');
  if (!host) return;
  const here = location.pathname.split('/').pop() || 'index.html';
  // The Staff tab only exists for staff/admin accounts (the API enforces this too)
  const list = isStaff(user) ? [...TABS, ['staff.html', 'Staff']] : TABS;
  const tabs = list.map(([href, label]) =>
    `<a class="tab${href === 'staff.html' ? ' tab-staff' : ''}" href="${href}" ${here === href ? 'aria-current="page"' : ''}>${label}</a>`).join('');
  host.innerHTML = `
    <div class="site-nav">
      <div class="mx-auto flex max-w-[1240px] flex-wrap items-center justify-between gap-x-6 gap-y-2 px-6 py-3">
        <a href="index.html" class="wordmark text-[1.15rem]">WaveSpace</a>
        <nav class="flex flex-wrap items-center gap-1" aria-label="Main">
          ${tabs}
          ${user
            ? `<span class="ml-3 hidden text-sm text-muted sm:inline">${esc(user.full_name)}</span>
               <button class="tab" id="nav-logout" type="button">Log out</button>`
            : '<button class="tab" id="nav-login" type="button">Log in / Register</button>'}
        </nav>
      </div>
    </div>`;
  host.querySelector('#nav-login')?.addEventListener('click', () => openAuth());
  host.querySelector('#nav-logout')?.addEventListener('click', async () => {
    try {
      await api.logout();
      window.dispatchEvent(new CustomEvent('auth:change', { detail: null }));
      toast('You have been logged out.');
    } catch {
      toast('Could not log out. Please try again.', 'error');
    }
  });
}
