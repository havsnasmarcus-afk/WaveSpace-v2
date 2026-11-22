// Map page: interactive map with one pin per beach (real coordinates) + details of the selected beach (SPEC 3.1).
import { api, ApiError } from './api.js';
import { initNav } from './nav.js';
import { SIZE_INFO } from './catalog.js';
import { $, esc } from './ui.js';
import { createBeachMap } from './beachmap.js';

const POLL_MS = 15000;
const state = { locations: [], selectedId: null, updated: null, error: null };

let bm = null;        // shared interactive map (js/beachmap.js), same component the staff use
let fitted = false;

const selected = () => state.locations.find((l) => l.id === state.selectedId) || state.locations[0];

async function initMap() {
  try {
    bm = await createBeachMap($('#map'), { onSelect: (id) => select(id), scrollZoom: false });
    renderMap();
  } catch (err) {
    $('#map').innerHTML = `<p class="p-6 text-muted">${esc(err.message)} The beach list on the right still works.</p>`;
  }
}

function renderMap() {
  if (!bm || !state.locations.length) return;
  bm.setBeaches(state.locations, selected()?.id ?? null);
  if (!fitted) { bm.fitAll(); fitted = true; }
}

function renderSide() {
  const side = $('#side');
  if (state.error) {
    side.innerHTML = `<div class="card p-6">
      <p class="display text-lg">Could not load locations</p>
      <p class="mt-2 text-sm text-muted">${state.error.map(esc).join('<br>')}</p>
      <button id="retry" class="btn btn-ocean mt-4">Try again</button></div>`;
    $('#retry').addEventListener('click', refresh);
    return;
  }
  const l = selected();
  if (!l) { side.innerHTML = '<div class="card p-6 text-muted">No beaches yet.</div>'; return; }
  const full = l.available === 0;
  const sizes = Object.entries(l.sizes).filter(([, s]) => s.cap > 0).map(([k, s]) =>
    `<li class="flex justify-between gap-3"><span>${SIZE_INFO[k].label} <span class="text-muted">· ${esc(SIZE_INFO[k].note)}</span></span>
     <span class="${s.free === 0 ? 'text-muted' : 'font-semibold'}">${s.free === 0 ? 'Fully booked' : `${s.free} free`}</span></li>`).join('');
  const rows = state.locations.map((x) => `
    <button type="button" class="row" data-id="${x.id}" aria-pressed="${x.id === l.id}">
      <span>${esc(x.name)}</span><span class="text-muted text-[0.95rem]">${x.available} free</span></button>`).join('');
  side.innerHTML = `
    <div class="card p-6">
      <p class="text-[0.95rem] text-muted">${esc(l.region)}</p>
      <h2 class="display mt-0.5 text-[1.65rem] leading-tight">${esc(l.name)}</h2>
      <p class="mt-3">${full ? '<strong>Fully booked</strong>' : `${l.available} of ${l.capacity} lockers free`}</p>
      <ul class="mt-3 space-y-1 text-sm">${sizes}</ul>
      ${full
        ? '<button class="btn btn-coral mt-5 w-full" disabled>Fully booked</button>'
        : `<a class="btn btn-coral mt-5 w-full" href="booking.html?location_id=${l.id}">Book a locker here</a>`}
    </div>
    <div class="space-y-2">${rows}</div>
    <p class="flex items-center gap-2 pt-1 text-xs text-muted"><span class="live-dot"></span>
      Live availability${state.updated ? ` · updated ${state.updated.toLocaleTimeString('en-GB')}` : ''}</p>`;
}

function select(id) {
  state.selectedId = id;
  renderMap();
  bm?.focus(id);
  renderSide();
}

async function refresh() {
  try {
    state.locations = await api.locations();
    state.error = null;
    state.updated = new Date();
    if (!state.locations.some((l) => l.id === state.selectedId)) {
      state.selectedId = (state.locations.find((l) => l.available > 0) || state.locations[0])?.id ?? null;
    }
  } catch (err) {
    state.error = err instanceof ApiError ? err.messages : ['Unexpected error.'];
    if (state.locations.length) return; // keep showing the last good data when a background refresh fails
  }
  renderMap();
  renderSide();
}

document.addEventListener('click', (e) => {
  const el = e.target.closest('.row[data-id]');
  if (el) select(Number(el.dataset.id));
});

initNav();
initMap();
refresh();
setInterval(() => { if (!document.hidden) refresh(); }, POLL_MS);
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
