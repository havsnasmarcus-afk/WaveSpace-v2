// My locker: status, current access code, subscription controls and help (SPEC 3.3 / 3.4).
import { api, ApiError } from './api.js';
import { initNav } from './nav.js';
import { openAuth } from './auth.js';
import { SIZE_INFO, termLabel, HELP_TOPICS } from './catalog.js';
import { $, esc, money, fmtDate, toast, renderErrors, withBusy, formatCardInput, formatExpiryInput } from './ui.js';

const root = $('#locker');
const state = { user: null, subs: [], payments: [], currentId: Number(new URLSearchParams(location.search).get('sub')) || null };
let billingSubId = null;

const current = () => state.subs.find((s) => s.id === state.currentId) || state.subs[0];
const STATUS_LABEL = { active: 'Active', revoked: 'Revoked', ended: 'Ended' };

function loggedOut() {
  state.subs = [];
  root.innerHTML = `
    <h1 class="display text-[2.2rem] sm:text-5xl">My locker</h1>
    <div class="card mt-8 p-8 text-center">
      <p class="display text-xl">Log in to see your locker</p>
      <p class="mx-auto mt-2 max-w-md text-muted">Your access code and subscription live here once you are signed in.</p>
      <button id="login-btn" class="btn btn-ocean mt-5">Log in / Register</button>
    </div>`;
  $('#login-btn').addEventListener('click', () => openAuth());
}

function empty() {
  root.innerHTML = `
    <h1 class="display text-[2.2rem] sm:text-5xl">My locker</h1>
    <div class="card mt-8 p-8 text-center">
      <p class="display text-xl">No locker yet</p>
      <p class="mx-auto mt-2 max-w-md text-muted">Pick a beach on the map and your access code will show up here.</p>
      <a href="index.html" class="btn btn-coral mt-5">Find a beach</a>
    </div>`;
}

function render() {
  const s = current();
  if (!s) return empty();
  const active = s.status === 'active';
  const picker = state.subs.length > 1 ? `
    <div class="mt-6 flex flex-wrap gap-2" role="tablist" aria-label="Your lockers">
      ${state.subs.map((x) => `<button type="button" class="tab" data-sub="${x.id}" ${x.id === s.id ? 'aria-current="page"' : ''}>
        ${esc(x.location)} #${esc(x.locker)}${x.status === 'active' ? '' : ` · ${STATUS_LABEL[x.status] || x.status}`}</button>`).join('')}
    </div>` : '';
  const digits = active && s.pin ? [...s.pin].map((d) => `<span>${esc(d)}</span>`).join('') : '';
  const renewLine = active
    ? (s.auto_renew ? `Renews on ${fmtDate(s.renewal_date)}` : `Cancelled · access ends on ${fmtDate(s.renewal_date)}`)
    : `Started ${fmtDate(s.start_date)}`;

  root.innerHTML = `
    <h1 class="display text-[2.2rem] sm:text-5xl">My locker</h1>
    ${picker}
    <div class="mt-8 grid gap-6 md:grid-cols-2">
      <section class="card p-6" aria-labelledby="st-h">
        <p id="st-h" class="text-[0.95rem] text-muted">Status</p>
        <p class="mt-1 flex items-center gap-2.5 text-[1.45rem] font-bold"><span class="dot" data-s="${esc(s.status)}"></span>${esc(STATUS_LABEL[s.status] || s.status)}</p>
        <p class="mt-4">${esc(s.location)}, ${esc(s.region)} — Locker #${esc(s.locker)}</p>
        <p class="mt-1">${esc(SIZE_INFO[s.size]?.label || s.size)} · ${termLabel(s.term_months)}</p>
        <p class="mt-1 text-muted">${renewLine}</p>
      </section>
      <section class="rounded-2xl p-6 text-white" style="background:var(--ocean)" aria-labelledby="code-h">
        <p id="code-h" class="text-[0.95rem]" style="color:#d6e8ef">Current access code</p>
        ${active
          ? `<div class="code-digits mt-3" aria-label="Access code ${esc(s.pin.split('').join(' '))}">${digits}</div>
             <button id="new-code" class="link mt-4 text-[0.95rem]" style="color:#e8f3f7">Generate new code</button>`
          : `<p class="mt-4 text-lg font-bold">Code disabled</p>
             <p class="mt-1 text-[0.95rem]" style="color:#d6e8ef">This locker is no longer active.</p>`}
      </section>
    </div>

    <section class="card mt-6 p-6" aria-labelledby="sub-h">
      <h2 id="sub-h" class="display text-xl">Subscription</h2>
      ${active ? `
        <p class="mt-2 text-[0.97rem] text-muted">${money(s.amount_cents)} every ${termLabel(s.term_months).replace(/^1 /, '')} · card •••• ${esc(s.card_last4)}</p>
        <div class="mt-4 flex flex-wrap gap-3">
          <button id="renew" class="btn btn-ocean">Renew</button>
          ${s.auto_renew
            ? '<button id="cancel" class="btn btn-outline">Cancel subscription</button>'
            : '<button class="btn btn-outline" disabled>Cancelled</button>'}
          <button id="card" class="btn btn-outline">Update billing method</button>
          <button id="fail" class="btn btn-outline" title="Demo control: revokes the code and frees the locker, as if a renewal payment failed">Simulate failed payment</button>
        </div>`
      : `<p class="mt-2 text-muted">This subscription is not active. <a class="link" href="index.html">Book a new locker</a></p>`}
    </section>

    <section class="card mt-6 p-6" aria-labelledby="help-h">
      <h2 id="help-h" class="display text-xl">Need help?</h2>
      <form id="help" class="mt-4 space-y-3" novalidate>
        <div id="help-errors"></div>
        <select class="input" name="topic" aria-label="Topic">${HELP_TOPICS.map((t) => `<option>${esc(t)}</option>`).join('')}</select>
        <textarea class="input" name="message" placeholder="Tell us what's going on" aria-label="Message" maxlength="2000"></textarea>
        <button id="help-send" class="btn btn-coral">Send request</button>
      </form>
    </section>

    ${state.payments.length ? `
    <section class="card mt-6 overflow-hidden" aria-labelledby="pay-h">
      <h2 id="pay-h" class="display p-6 pb-3 text-xl">Payment history</h2>
      <div class="overflow-x-auto"><table class="w-full text-[0.95rem]">
        <thead class="text-left text-muted"><tr><th class="px-6 py-2 font-normal">Date</th><th class="py-2 font-normal">Beach</th><th class="py-2 font-normal">Amount</th><th class="px-6 py-2 font-normal">Status</th></tr></thead>
        <tbody>${state.payments.map((p) => `
          <tr class="border-t" style="border-color:var(--line)"><td class="whitespace-nowrap px-6 py-2.5">${fmtDate(p.created_at)}</td><td>${esc(p.location)}</td>
          <td>${money(p.amount_cents)}</td><td class="px-6 font-semibold" style="color:${p.status === 'paid' ? 'var(--ocean)' : '#b3402a'}">${p.status === 'paid' ? 'Paid' : 'Failed'}</td></tr>`).join('')}
        </tbody></table></div>
    </section>` : ''}`;

  bind(s);
}

function bind(s) {
  root.querySelectorAll('[data-sub]').forEach((b) => b.addEventListener('click', () => { state.currentId = Number(b.dataset.sub); render(); }));
  $('#new-code')?.addEventListener('click', (e) => act(e.currentTarget, { action: 'new_code', subscription_id: s.id }));
  $('#renew')?.addEventListener('click', (e) => act(e.currentTarget, { action: 'renew', subscription_id: s.id }));
  // SPEC 3.4: cancelling is one click; access stays until the end of the paid period (and "Renew" undoes it)
  $('#cancel')?.addEventListener('click', (e) => act(e.currentTarget, { action: 'cancel_renewal', subscription_id: s.id }));
  $('#fail')?.addEventListener('click', (e) => {
    if (!confirm('Simulate a failed payment? Your code will be revoked and the locker released immediately.')) return;
    act(e.currentTarget, { action: 'simulate_failure', subscription_id: s.id });
  });
  $('#card')?.addEventListener('click', () => {
    billingSubId = s.id;
    $('#billing-form').reset();
    renderErrors($('#billing-errors'), []);
    $('#billing').showModal();
  });
  $('#help').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = Object.fromEntries(new FormData(e.currentTarget));
    if (!String(f.message).trim()) return renderErrors($('#help-errors'), ['Please tell us what is going on.']);
    await withBusy($('#help-send'), async () => {
      try {
        const res = await api.manage({ action: 'support', subscription_id: s.id, topic: f.topic, message: f.message });
        toast(res.message);
        e.currentTarget.reset();
        renderErrors($('#help-errors'), []);
      } catch (err) {
        renderErrors($('#help-errors'), err instanceof ApiError ? err.messages : ['Unexpected error.']);
      }
    });
  });
}

async function act(btn, payload) {
  await withBusy(btn, async () => {
    try {
      const res = await api.manage(payload);
      toast(res.message);
      await load();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : 'Unexpected error.', 'error');
    }
  });
}

async function load() {
  try {
    const data = await api.mySubscriptions();
    state.user = data.user;
    state.subs = data.subscriptions;
    state.payments = data.payments;
    render();
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) return loggedOut();
    root.innerHTML = `<h1 class="display text-[2.2rem] sm:text-5xl">My locker</h1>
      <div class="card mt-8 p-6"><p class="display text-lg">We could not load your locker</p>
      <p class="mt-2 text-sm text-muted">${(err instanceof ApiError ? err.messages : ['Unexpected error.']).map(esc).join('<br>')}</p>
      <button class="btn btn-ocean mt-4" onclick="location.reload()">Try again</button></div>`;
  }
}

// Billing dialog (SPEC 3.4)
formatCardInput($('#billing-form [name=card_number]'));
formatExpiryInput($('#billing-form [name=expiry]'));
$('#billing-close').addEventListener('click', () => $('#billing').close());
$('#billing-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = Object.fromEntries(new FormData(e.currentTarget));
  await withBusy($('#billing-save'), async () => {
    try {
      const res = await api.manage({ action: 'update_card', subscription_id: billingSubId, ...f, expiry: String(f.expiry).replace(/\s/g, '') });
      $('#billing').close();
      toast(res.message);
      await load();
    } catch (err) {
      renderErrors($('#billing-errors'), err instanceof ApiError ? err.messages : ['Unexpected error.']);
    }
  });
});

async function init() {
  state.user = await initNav();
  window.addEventListener('auth:change', (e) => (e.detail ? load() : loggedOut()));
  if (new URLSearchParams(location.search).has('booked')) {
    toast('Payment successful! Your access code is ready.');
    history.replaceState(null, '', 'account.html');
  }
  if (!state.user) {
    loggedOut();
    openAuth({ message: 'Log in to see your locker and access code.' });
    return;
  }
  load();
}

init();
