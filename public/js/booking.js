// Book page: 1 Beach · 2 Locker size · 3 Subscription term · 4 Payment, with a live summary.
import { api, ApiError } from './api.js';
import { initNav } from './nav.js';
import { openAuth } from './auth.js';
import { SIZE_INFO, TERMS, termPrice, termLabel } from './catalog.js';
import { $, esc, money, renderErrors, withBusy, formatCardInput, formatExpiryInput } from './ui.js';

const params = new URLSearchParams(location.search);
const root = $('#booking');
const state = {
  user: null,
  locations: [],
  locationId: Number(params.get('location_id')) || null,
  size: params.get('size') === 'longboard' ? 'longboard' : 'shortboard',
  term: 1,
};

const loc = () => state.locations.find((l) => l.id === state.locationId);

function ensureValidSelection() {
  if (!loc()) state.locationId = (state.locations.find((l) => l.available > 0) || state.locations[0])?.id ?? null;
  const l = loc();
  if (!l) return;
  const free = (s) => l.sizes[s].cap > 0 && l.sizes[s].free > 0;
  if (!free(state.size)) {
    const other = state.size === 'shortboard' ? 'longboard' : 'shortboard';
    if (free(other)) state.size = other;
  }
}

function renderShell() {
  root.innerHTML = `
  <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_380px]">
    <form id="form" class="space-y-9" novalidate>
      <h1 class="display text-[2.2rem] leading-tight sm:text-5xl">Book your locker</h1>
      <div id="errors" aria-live="polite"></div>

      <section>
        <h2 class="display mb-3 text-[1.05rem]"><label for="beach">1. Beach</label></h2>
        <select id="beach" class="input" aria-label="Beach"></select>
      </section>

      <section>
        <h2 class="display mb-3 text-[1.05rem]" id="size-h">2. Locker size</h2>
        <div id="sizes" class="grid gap-4 sm:grid-cols-2" role="radiogroup" aria-labelledby="size-h"></div>
      </section>

      <section>
        <h2 class="display mb-3 text-[1.05rem]" id="term-h">3. Subscription term</h2>
        <div id="terms" class="grid gap-4 sm:grid-cols-3" role="radiogroup" aria-labelledby="term-h"></div>
      </section>

      <section class="space-y-3">
        <h2 class="display text-[1.05rem]">4. Payment <span class="text-[.85rem] font-bold text-muted" style="font-stretch:100%">(simulated — nothing is charged)</span></h2>
        <input class="input" name="card_name" autocomplete="cc-name" placeholder="Name on card" aria-label="Name on card">
        <input class="input" name="card_number" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456" aria-label="Card number">
        <div class="grid grid-cols-2 gap-3">
          <input class="input" name="expiry" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" aria-label="Expiry date (MM/YY)">
          <input class="input" name="cvc" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="CVC" aria-label="CVC">
        </div>
        <p class="text-sm text-muted">Testing? Use 4242 4242 4242 4242 with any future date. 4000 0000 0000 0002 simulates a declined card.</p>
      </section>
    </form>

    <aside class="card p-6 lg:sticky lg:top-6">
      <h2 class="display text-xl">Summary</h2>
      <dl id="summary" class="mt-4 space-y-2.5 text-[0.97rem]"></dl>
      <button id="pay" form="form" type="submit" class="btn btn-coral mt-5 w-full"></button>
      <p id="pay-note" class="mt-3 text-sm text-muted"></p>
    </aside>
  </div>`;
  formatCardInput($('[name=card_number]'));
  formatExpiryInput($('[name=expiry]'));
  $('#form').addEventListener('submit', onSubmit);
  $('#beach').addEventListener('change', (e) => { state.locationId = Number(e.target.value); ensureValidSelection(); renderDynamic(); });
  $('#sizes').addEventListener('click', (e) => pick(e, 'size'));
  $('#terms').addEventListener('click', (e) => pick(e, 'term'));
  renderDynamic();
}

function pick(e, key) {
  const tile = e.target.closest('[role=radio]');
  if (!tile || tile.getAttribute('aria-disabled') === 'true') return;
  state[key] = key === 'term' ? Number(tile.dataset.value) : tile.dataset.value;
  renderDynamic();
}

/** Re-renders everything that depends on the selection, without touching what the user typed in the payment fields. */
function renderDynamic() {
  const l = loc();
  $('#beach').innerHTML = state.locations.map((x) => {
    const full = x.available === 0;
    return `<option value="${x.id}" ${x.id === state.locationId ? 'selected' : ''} ${full ? 'disabled' : ''}>${esc(x.name)} — ${esc(x.region)}${full ? ' (fully booked)' : ''}</option>`;
  }).join('');

  $('#sizes').innerHTML = Object.entries(SIZE_INFO).map(([key, info]) => {
    const s = l.sizes[key];
    const off = s.cap === 0 || s.free === 0;
    return `<button type="button" role="radio" class="tile" data-value="${key}" aria-checked="${state.size === key}" aria-disabled="${off}">
      <span class="block font-bold">${info.label}</span>
      <span class="mt-0.5 block text-[0.9rem] text-muted">${esc(info.note)}</span>
      <span class="mt-3 block font-bold">${off ? 'Fully booked' : `${money(l.prices[key])}/month`}</span></button>`;
  }).join('');

  $('#terms').innerHTML = TERMS.map((t) => `
    <button type="button" role="radio" class="tile" data-value="${t.months}" aria-checked="${state.term === t.months}">
      <span class="block font-bold">${t.label}</span><span class="mt-0.5 block text-[0.9rem] text-muted">${t.note}</span></button>`).join('');

  const sizeOff = l.sizes[state.size].cap === 0 || l.sizes[state.size].free === 0;
  const total = termPrice(l.prices[state.size], state.term);
  $('#summary').innerHTML = `
    <div class="flex justify-between"><dt class="text-muted">Beach</dt><dd class="font-bold">${esc(l.name)}</dd></div>
    <div class="flex justify-between"><dt class="text-muted">Size</dt><dd class="font-bold">${SIZE_INFO[state.size].label}</dd></div>
    <div class="flex justify-between"><dt class="text-muted">Term</dt><dd class="font-bold">${termLabel(state.term)}</dd></div>
    <div class="mt-3 flex justify-between border-t pt-3 text-lg" style="border-color:var(--line)"><dt class="font-bold">Total</dt><dd class="font-bold">${money(total)}</dd></div>`;
  $('#pay').textContent = 'Pay & get access code';
  $('#pay').disabled = sizeOff;
  const allFull = Object.values(l.sizes).every((s) => s.cap === 0 || s.free === 0);
  $('#pay-note').textContent = allFull
    ? 'This beach is fully booked right now. Pick another beach in step 1.'
    : state.term === 1
      ? 'Renews monthly. Cancel any time in My locker.'
      : `Renews every ${state.term} months. Cancel any time in My locker.`;
}

function validate(f) {
  const errs = [];
  if (!String(f.card_name || '').trim()) errs.push('Enter the name on the card.');
  if (!/^\d{13,19}$/.test(String(f.card_number).replace(/\s/g, ''))) errs.push('Enter a valid card number.');
  if (!/^(0[1-9]|1[0-2])\s*\/\s*\d{2}$/.test(String(f.expiry).trim())) errs.push('Enter the expiry as MM/YY.');
  if (!/^\d{3,4}$/.test(String(f.cvc).trim())) errs.push('CVC must be 3 or 4 digits.');
  return errs;
}

async function onSubmit(e) {
  e.preventDefault();
  const errBox = $('#errors');
  const f = Object.fromEntries(new FormData($('#form')));
  const errs = validate(f);
  if (errs.length) { renderErrors(errBox, errs); errBox.scrollIntoView({ block: 'center', behavior: 'smooth' }); return; }
  renderErrors(errBox, []);

  await withBusy($('#pay'), async () => {
    try {
      if (!state.user) {
        state.user = await openAuth({ message: 'Log in or create an account to finish your booking.' });
        if (!state.user) return;
      }
      await api.book({
        location_id: state.locationId, size: state.size, term: state.term,
        card_name: f.card_name, card_number: f.card_number, expiry: String(f.expiry).replace(/\s/g, ''), cvc: f.cvc,
      });
      location.href = 'account.html?booked=1';
    } catch (err) {
      if (!(err instanceof ApiError)) return renderErrors(errBox, ['Unexpected error. Please try again.']);
      if (err.status === 401) state.user = null;
      if (err.status === 409) await refreshAvailability();
      renderErrors(errBox, err.messages);
      errBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  });
}

async function refreshAvailability() {
  try {
    state.locations = await api.locations();
    ensureValidSelection();
    renderDynamic();
  } catch { /* keep what we have */ }
}

async function init() {
  state.user = await initNav();
  window.addEventListener('auth:change', (e) => { state.user = e.detail; });
  try {
    state.locations = await api.locations();
  } catch (err) {
    root.innerHTML = `<div class="card p-6"><p class="display text-lg">Could not load the beaches</p>
      <p class="mt-2 text-sm text-muted">${(err instanceof ApiError ? err.messages : ['Unexpected error.']).map(esc).join('<br>')}</p>
      <button class="btn btn-ocean mt-4" onclick="location.reload()">Try again</button></div>`;
    return;
  }
  if (!state.locations.length) { root.innerHTML = '<p class="text-muted">No beaches available yet.</p>'; return; }
  ensureValidSelection();
  renderShell();
}

init();
