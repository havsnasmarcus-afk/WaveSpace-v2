// Staff back office: operational KPIs, interactive lockers map (add beaches/lockers) and team management.
// Only rendered for staff/admin accounts; every API call is also checked server-side (api/staff.php).
import { api, ApiError, isStaff } from './api.js';
import { initNav } from './nav.js';
import { openAuth } from './auth.js';
import { SIZE_INFO } from './catalog.js';
import { $, esc, money, fmtDate, toast, renderErrors, withBusy } from './ui.js';
import { createBeachMap } from './beachmap.js';

const VIEWS = ['kpis', 'map', 'team'];
const state = {
  user: null,
  view: VIEWS.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'kpis',
  locations: [],
  selectedId: null,
  mode: 'view',          // 'view' | 'new' (placing a new beach) | 'edit' (editing the selected beach)
  draft: null,           // { lat, lng } while placing / moving a beach
  staff: [],
  staffQuery: '',
  editingStaff: null,
};
const msgs = (err) => (err instanceof ApiError ? err.messages : ['Unexpected error.']);
const pct = (a, b) => (b > 0 ? Math.round((a / b) * 100) : 0);

// ------------------------------------------------------------------ gate & tabs

function gate(user) {
  state.user = user;
  const ok = isStaff(user);
  $('#staff-app').hidden = !ok;
  if (ok) { $('#gate').innerHTML = ''; show(state.view); return; }
  $('#gate').innerHTML = `
    <h1 class="display text-[2.2rem] sm:text-5xl">Staff area</h1>
    <div class="card mt-8 p-8 text-center">
      <p class="display text-xl">${user ? 'This area is for WaveSpace staff only' : 'Log in with your staff account'}</p>
      <p class="mx-auto mt-2 max-w-md text-muted">${user
        ? 'Your account does not have staff access. Ask an admin to add you to the team.'
        : 'Operational KPIs, the lockers map and team management live here.'}</p>
      ${user ? '<a href="index.html" class="btn btn-ocean mt-5">Back to the map</a>' : '<button id="gate-login" class="btn btn-ocean mt-5">Log in</button>'}
    </div>`;
  $('#gate-login')?.addEventListener('click', () => openAuth({ message: 'Staff log in.' }));
}

function show(view) {
  state.view = view;
  history.replaceState(null, '', `#${view}`);
  document.querySelectorAll('[data-view]').forEach((b) => {
    const on = b.dataset.view === view;
    b.setAttribute('aria-selected', String(on));
    if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
  });
  VIEWS.forEach((v) => { $(`#view-${v}`).hidden = v !== view; });
  if (view === 'kpis') loadKpis();
  if (view === 'map') loadInventory();
  if (view === 'team') loadTeam();
}

document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => show(b.dataset.view)));

function failBox(el, err, retry) {
  el.innerHTML = `<div class="card p-6"><p class="display text-lg">Could not load this section</p>
    <p class="mt-2 text-sm text-muted">${msgs(err).map(esc).join('<br>')}</p>
    <button class="btn btn-ocean mt-4" data-retry>Try again</button></div>`;
  el.querySelector('[data-retry]').addEventListener('click', retry);
}

// ------------------------------------------------------------------ KPIs

async function loadKpis() {
  const el = $('#view-kpis');
  if (!el.innerHTML.trim()) el.innerHTML = '<div class="skeleton h-72"></div>';
  try {
    renderKpis(await api.staffGet('kpis'));
  } catch (err) {
    if (err instanceof ApiError && (err.status === 401 || err.status === 403)) return gate(null);
    failBox(el, err, loadKpis);
  }
}

function tile(label, value, sub = '') {
  return `<div class="card p-5"><p class="text-[0.9rem] text-muted">${label}</p>
    <p class="kpi-value mt-1">${value}</p>${sub ? `<p class="mt-1 text-[0.85rem] text-muted">${sub}</p>` : ''}</div>`;
}

function renderKpis({ totals: t, beaches, daily_revenue: daily, generated_at }) {
  const occ = pct(t.occupied, t.lockers);
  const delta = t.revenue_prev_30d > 0 ? Math.round(((t.revenue_30d - t.revenue_prev_30d) / t.revenue_prev_30d) * 100) : null;
  const failRate = pct(t.failed_payments_30d, t.payments_30d);

  $('#view-kpis').innerHTML = `
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      ${tile('Occupancy', `${occ}%`, `${t.occupied} of ${t.lockers} lockers rented · ${t.beaches} beaches`)}
      ${tile('Monthly recurring revenue', money(Math.round(t.mrr_cents)), `${t.active_subs} active subscriptions`)}
      ${tile('Revenue · last 30 days', money(t.revenue_30d), delta === null ? 'No data for the 30 days before' : `${delta >= 0 ? '▲' : '▼'} ${Math.abs(delta)}% vs previous 30 days`)}
      ${tile('New subscriptions · 30 days', t.new_subs_30d, `${t.customers} registered customers`)}
      ${tile('Failed payments · 30 days', t.failed_payments_30d, `${failRate}% of ${t.payments_30d} payment attempts`)}
      ${tile('Cancelling at renewal', t.pending_cancellations, 'Auto-renew switched off')}
      ${tile('Support requests · 7 days', t.support_7d, 'From the My locker help form')}
      ${tile('Active staff', t.staff, '<button class="link" data-goto="team">Manage team</button>')}
    </div>

    <section class="card mt-6 p-6" aria-labelledby="rev-h">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="rev-h" class="display text-xl">Daily revenue · last 30 days</h2>
        <p class="text-sm text-muted">Paid payments, all beaches</p>
      </div>
      <div class="chart-wrap mt-4">${revenueChart(daily)}<div class="chart-tip" role="status" hidden></div></div>
    </section>

    <section class="card mt-6 overflow-hidden" aria-labelledby="beach-h">
      <h2 id="beach-h" class="display p-6 pb-3 text-xl">Occupancy by beach</h2>
      <div class="overflow-x-auto"><table class="w-full min-w-[560px] text-[0.95rem]">
        <thead class="text-left text-muted"><tr>
          <th class="px-6 py-2 font-normal">Beach</th><th class="py-2 font-normal">Occupancy</th>
          <th class="py-2 text-right font-normal">Rented</th><th class="px-6 py-2 text-right font-normal">Revenue 30d</th></tr></thead>
        <tbody>${[...beaches].sort((a, b) => pct(b.occupied, b.capacity) - pct(a.occupied, a.capacity)).map((b) => {
          const p = pct(b.occupied, b.capacity);
          return `<tr class="border-t" style="border-color:var(--line)">
            <td class="px-6 py-3"><button class="link font-semibold" data-beach="${b.id}">${esc(b.name)}</button>
              <span class="block text-[0.85rem] text-muted">${esc(b.region)}</span></td>
            <td class="py-3 pr-4"><div class="flex items-center gap-3">
              <div class="meter" title="${p}% occupied"><span style="width:${p}%"></span></div>
              <span class="w-11 text-right tabular-nums">${p}%</span></div></td>
            <td class="py-3 text-right tabular-nums">${b.occupied} / ${b.capacity}</td>
            <td class="px-6 py-3 text-right tabular-nums">${money(b.revenue_30d)}</td></tr>`;
        }).join('')}</tbody></table></div>
    </section>
    <p class="mt-3 text-xs text-muted">Updated ${new Date(generated_at).toLocaleTimeString('en-GB')} ·
      <button class="link" id="kpi-refresh">Refresh</button></p>`;

  $('#kpi-refresh').addEventListener('click', loadKpis);
  $('#view-kpis').querySelectorAll('[data-goto]').forEach((b) => b.addEventListener('click', () => show(b.dataset.goto)));
  $('#view-kpis').querySelectorAll('[data-beach]').forEach((b) => b.addEventListener('click', () => {
    state.selectedId = Number(b.dataset.beach);
    state.mode = 'view';
    show('map');
  }));
  bindChartHover();
}

/** Single-series bar chart (inline SVG), one bar per day, with a hover tooltip. */
function revenueChart(daily) {
  const W = 900, H = 220, L = 52, R = 8, T = 10, B = 26;
  const max = Math.max(1, ...daily.map((d) => d.cents));
  const step = niceStep(max / 3);
  const top = Math.ceil(max / step) * step;
  const y = (v) => T + (H - T - B) * (1 - v / top);
  const slot = (W - L - R) / daily.length;
  const bw = Math.max(4, slot - 4); // 2px gap each side
  const grid = [0, 1, 2, 3].map((i) => i * step).filter((v) => v <= top).map((v) => `
    <line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" class="chart-grid"/>
    <text x="${L - 8}" y="${y(v) + 4}" text-anchor="end" class="chart-axis">${money(v)}</text>`).join('');
  const bars = daily.map((d, i) => {
    const x = L + i * slot + (slot - bw) / 2;
    const h = (H - B) - y(d.cents);
    const r = Math.min(4, bw / 2, h);
    const yb = H - B;
    const path = h > 0
      ? `M${x},${yb} V${yb - h + r} Q${x},${yb - h} ${x + r},${yb - h} H${x + bw - r} Q${x + bw},${yb - h} ${x + bw},${yb - h + r} V${yb} Z`
      : '';
    return `<g class="bar" data-i="${i}" data-label="${esc(fmtDate(d.day))}" data-value="${esc(money(d.cents))}">
      <rect x="${L + i * slot}" y="${T}" width="${slot}" height="${H - T - B}" fill="transparent"/>
      ${path ? `<path d="${path}" class="chart-bar"/>` : ''}</g>`;
  }).join('');
  const ticks = daily.map((d, i) => (i % 7 === 0 || i === daily.length - 1)
    ? `<text x="${L + i * slot + slot / 2}" y="${H - 6}" text-anchor="middle" class="chart-axis">${esc(fmtDate(d.day).replace(/ \d{4}$/, ''))}</text>` : '').join('');
  return `<svg viewBox="0 0 ${W} ${H}" class="w-full" role="img" aria-label="Daily paid revenue for the last 30 days, peak ${esc(money(max))}">
    ${grid}<line x1="${L}" x2="${W - R}" y1="${H - B}" y2="${H - B}" class="chart-base"/>${bars}${ticks}</svg>`;
}

function niceStep(raw) {
  const p = 10 ** Math.floor(Math.log10(Math.max(raw, 1)));
  const n = raw / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
}

function bindChartHover() {
  const wrap = $('.chart-wrap');
  const tip = $('.chart-tip', wrap);
  wrap.querySelectorAll('.bar').forEach((g) => {
    g.addEventListener('mouseenter', () => {
      wrap.querySelectorAll('.bar.on').forEach((x) => x.classList.remove('on'));
      g.classList.add('on');
      tip.innerHTML = `<span class="text-muted">${g.dataset.label}</span><strong>${g.dataset.value}</strong>`;
      tip.hidden = false;
      const r = g.getBoundingClientRect(), w = wrap.getBoundingClientRect();
      tip.style.left = `${Math.min(Math.max(r.left - w.left + r.width / 2, 60), w.width - 60)}px`;
    });
  });
  wrap.addEventListener('mouseleave', () => { tip.hidden = true; wrap.querySelectorAll('.bar.on').forEach((x) => x.classList.remove('on')); });
}

// ------------------------------------------------------------------ Interactive map

let bm = null;          // shared beach map (js/beachmap.js)
let mapFailed = null;
let fitted = false;     // zoom to all beaches the first time the map gets data

async function ensureMap() {
  if (bm) { bm.refresh(); return true; }
  if (mapFailed) return false;
  try {
    bm = await createBeachMap($('#staff-map'), {
      onSelect: (id) => selectBeach(id),
      onPick: (lat, lng) => setDraft(lat, lng, false),
    });
    return true;
  } catch (err) {
    mapFailed = err;
    $('#staff-map').innerHTML = `<p class="p-6 text-muted">${esc(err.message)}</p>`;
    return false;
  }
}

/** Remember the position for the beach form; `moveMarker` = also move the + pin (false when the map itself reported it). */
function setDraft(lat, lng, moveMarker = true) {
  state.draft = { lat: Number(Number(lat).toFixed(6)), lng: Number(Number(lng).toFixed(6)) };
  if (bm && moveMarker) bm.setDraft(state.draft.lat, state.draft.lng);
  const f = $('#beach-form');
  if (f) { f.latitude.value = state.draft.lat; f.longitude.value = state.draft.lng; }
}

function clearDraft() {
  state.draft = null;
  bm?.clearDraft();
}

async function loadInventory() {
  if (!state.locations.length) $('#map-side').innerHTML = '<div class="skeleton h-80"></div>';
  const mapOk = await ensureMap();
  try {
    const { locations } = await api.staffGet('inventory');
    state.locations = locations.map((l) => ({
      ...l, id: Number(l.id), lat: Number(l.latitude), lng: Number(l.longitude),
      capacity: Number(l.capacity), available: Number(l.available),
    }));
    if (!state.locations.some((l) => l.id === state.selectedId) && state.mode !== 'new') state.selectedId = state.locations[0]?.id ?? null;
    if (mapOk) {
      renderMarkers();
      if (!fitted) { bm.fitAll(); fitted = true; }
    }
    renderSide();
  } catch (err) {
    if (err instanceof ApiError && (err.status === 401 || err.status === 403)) return gate(null);
    failBox($('#map-side'), err, () => loadInventory());
  }
}

function renderMarkers() {
  bm?.setBeaches(state.locations, state.mode === 'new' ? null : state.selectedId);
  bm?.setPicking(state.mode !== 'view');
}

function selectBeach(id) {
  state.selectedId = id;
  state.mode = 'view';
  clearDraft();
  renderMarkers();
  bm?.focus(id);
  renderSide();
}

const selected = () => state.locations.find((l) => l.id === state.selectedId);

function renderSide() {
  const side = $('#map-side');
  $('#map-hint').textContent = state.mode === 'view'
    ? 'Click a pin to manage that beach\'s lockers.'
    : 'Click the map (or drag the + pin) to set the beach position.';
  bm?.setPicking(state.mode !== 'view');
  $('#add-beach').hidden = state.mode === 'new';

  if (state.mode === 'new') return renderBeachForm(null);
  const l = selected();
  if (!l) { side.innerHTML = '<div class="card p-6 text-muted">No beaches yet. Use “New beach” to add the first one.</div>'; return; }
  if (state.mode === 'edit') return renderBeachForm(l);

  const groups = Object.keys(SIZE_INFO).map((size) => {
    const list = l.lockers.filter((k) => k.size === size);
    if (!list.length) return '';
    const free = list.filter((k) => k.status === 'available').length;
    return `<div class="mt-4">
      <p class="flex justify-between text-sm"><span class="font-semibold">${SIZE_INFO[size].label}</span><span class="text-muted">${free} of ${list.length} free</span></p>
      <div class="lk-grid mt-2">${list.map((k) => {
        const tip = k.status === 'occupied'
          ? `#${k.label} · rented${k.member ? ` by ${k.member}` : ''}${k.renewal_date ? ` · renews ${fmtDate(k.renewal_date)}` : ''}`
          : `#${k.label} · free${k.deletable ? ' · click to remove' : ''}`;
        return `<button type="button" class="lk" data-s="${k.status}" data-locker="${k.id}" ${k.deletable ? '' : 'aria-disabled="true"'}
          title="${esc(tip)}" aria-label="${esc(tip)}">${esc(k.label)}</button>`;
      }).join('')}</div></div>`;
  }).join('');

  side.innerHTML = `
    <div class="card p-6">
      <div class="flex items-start justify-between gap-3">
        <div><p class="text-[0.95rem] text-muted">${esc(l.region)}</p>
          <h2 class="display mt-0.5 text-[1.5rem] leading-tight">${esc(l.name)}</h2></div>
        <button id="edit-beach" class="btn btn-outline px-3 py-1.5 text-sm">Edit</button>
      </div>
      <p class="mt-2">${l.available} of ${l.capacity} lockers free</p>
      <p class="text-sm text-muted">Shortboard ${money(Number(l.shortboard_price_cents))}/mo · Longboard ${money(Number(l.longboard_price_cents))}/mo</p>
      ${groups || '<p class="mt-4 text-sm text-muted">No lockers at this beach yet.</p>'}
    </div>

    <form id="add-lockers" class="card space-y-3 p-6" novalidate>
      <h3 class="display text-lg">Add lockers</h3>
      <div id="al-errors"></div>
      <div class="grid grid-cols-[minmax(0,1fr)_96px] gap-3">
        <label class="field"><span>Size</span><select class="input" name="size">
          ${Object.entries(SIZE_INFO).map(([k, v]) => `<option value="${k}">${v.label}</option>`).join('')}</select></label>
        <label class="field"><span>How many</span><input class="input" name="count" type="number" min="1" max="200" value="4"></label>
      </div>
      <button id="al-save" class="btn btn-coral w-full">Add to ${esc(l.name)}</button>
      <p class="text-xs text-muted">New lockers are numbered after the highest existing number and are free right away.</p>
    </form>`;

  $('#edit-beach').addEventListener('click', () => {
    state.mode = 'edit';
    setDraft(l.lat, l.lng);
    renderSide();
  });
  side.querySelectorAll('.lk[data-s="available"]:not([aria-disabled])').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm(`Remove locker #${b.textContent.trim()} from ${l.name}?`)) return;
    try {
      toast((await api.staffDo('delete_locker', { id: Number(b.dataset.locker) })).message);
      loadInventory();
    } catch (err) { toast(msgs(err)[0], 'error'); }
  }));
  $('#add-lockers').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = Object.fromEntries(new FormData(e.currentTarget));
    const count = Number(f.count);
    if (!Number.isInteger(count) || count < 1 || count > 200) return renderErrors($('#al-errors'), ['Enter a number between 1 and 200.']);
    await withBusy($('#al-save'), async () => {
      try {
        toast((await api.staffDo('add_lockers', { id: l.id, size: f.size, count })).message);
        await loadInventory();
      } catch (err) { renderErrors($('#al-errors'), msgs(err)); }
    });
  });
}

function renderBeachForm(l) {
  const isNew = !l;
  const d = state.draft;
  $('#map-side').innerHTML = `
    <form id="beach-form" class="card space-y-3 p-6" novalidate>
      <div class="flex items-start justify-between gap-3">
        <h2 class="display text-xl">${isNew ? 'New beach' : `Edit ${esc(l.name)}`}</h2>
        <button type="button" id="bf-cancel" class="text-2xl leading-none text-muted hover:text-ink" aria-label="Cancel">×</button>
      </div>
      <div id="bf-errors"></div>
      <label class="field"><span>Beach name</span><input class="input" name="name" maxlength="120" value="${esc(l?.name ?? '')}"></label>
      <label class="field"><span>Region / town</span><input class="input" name="region" maxlength="80" value="${esc(l?.region ?? '')}"></label>
      <div class="grid grid-cols-2 gap-3">
        <label class="field"><span>Latitude</span><input class="input" name="latitude" inputmode="decimal" value="${d?.lat ?? ''}" placeholder="Click the map"></label>
        <label class="field"><span>Longitude</span><input class="input" name="longitude" inputmode="decimal" value="${d?.lng ?? ''}" placeholder="Click the map"></label>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <label class="field"><span>Shortboard €/month</span><input class="input" name="shortboard_price" type="number" min="0.01" step="0.01"
          value="${l ? Number(l.shortboard_price_cents) / 100 : 19}"></label>
        <label class="field"><span>Longboard €/month</span><input class="input" name="longboard_price" type="number" min="0.01" step="0.01"
          value="${l ? Number(l.longboard_price_cents) / 100 : 29}"></label>
      </div>
      ${isNew ? `<div class="grid grid-cols-2 gap-3">
        <label class="field"><span>Shortboard lockers</span><input class="input" name="shortboard_count" type="number" min="0" max="200" value="10"></label>
        <label class="field"><span>Longboard lockers</span><input class="input" name="longboard_count" type="number" min="0" max="200" value="4"></label>
      </div>` : ''}
      <button id="bf-save" class="btn btn-coral w-full">${isNew ? 'Add beach' : 'Save changes'}</button>
    </form>`;

  const form = $('#beach-form');
  // Typing coordinates by hand moves the pin too
  ['latitude', 'longitude'].forEach((n) => form[n].addEventListener('change', () => {
    const lat = Number(form.latitude.value), lng = Number(form.longitude.value);
    if (form.latitude.value && form.longitude.value && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) { setDraft(lat, lng); bm?.map.panTo([lat, lng]); }
  }));
  $('#bf-cancel').addEventListener('click', () => { state.mode = 'view'; clearDraft(); renderMarkers(); renderSide(); });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = Object.fromEntries(new FormData(form));
    const problems = [];
    if (!f.name.trim()) problems.push('Please enter the beach name.');
    if (!f.region.trim()) problems.push('Please enter the region.');
    if (!f.latitude || !f.longitude) problems.push('Click the map to place the beach.');
    if (problems.length) return renderErrors($('#bf-errors'), problems);
    await withBusy($('#bf-save'), async () => {
      try {
        const res = isNew ? await api.staffDo('create_location', f) : await api.staffDo('update_location', { ...f, id: l.id });
        toast(res.message);
        state.mode = 'view';
        if (isNew) state.selectedId = res.id;
        clearDraft();
        await loadInventory();
      } catch (err) { renderErrors($('#bf-errors'), msgs(err)); }
    });
  });
}

$('#add-beach').addEventListener('click', () => {
  state.mode = 'new';
  clearDraft();
  renderMarkers();
  renderSide();
  $('#beach-form [name=name]').focus();
});

// ------------------------------------------------------------------ Team

async function loadTeam() {
  const el = $('#view-team');
  if (!state.staff.length) el.innerHTML = '<div class="skeleton h-72"></div>';
  try {
    const { staff } = await api.staffGet('staff');
    state.staff = staff;
    renderTeam();
  } catch (err) {
    if (err instanceof ApiError && (err.status === 401 || err.status === 403)) return gate(null);
    failBox(el, err, loadTeam);
  }
}

function renderTeam() {
  const q = state.staffQuery.trim().toLowerCase();
  const rows = state.staff.filter((s) => !q || [s.full_name, s.email, s.nif, s.phone].some((v) => String(v ?? '').toLowerCase().includes(q)));
  const el = $('#view-team');
  el.innerHTML = `
    <div class="flex flex-wrap items-center justify-between gap-3">
      <input id="team-q" class="input max-w-xs" type="search" placeholder="Search name, email, NIF…" aria-label="Search staff" value="${esc(state.staffQuery)}">
      <button id="team-add" class="btn btn-coral">+ Add staff member</button>
    </div>
    <div class="card mt-4 overflow-x-auto">
      <table class="w-full min-w-[760px] text-[0.95rem]">
        <thead class="text-left text-muted"><tr>
          <th class="px-6 py-3 font-normal">Name</th><th class="py-3 font-normal">Email</th><th class="py-3 font-normal">NIF</th>
          <th class="py-3 font-normal">Phone</th><th class="py-3 font-normal">Role</th><th class="py-3 font-normal">Status</th>
          <th class="px-6 py-3"><span class="sr-only-label">Actions</span></th></tr></thead>
        <tbody>${rows.map((s) => `
          <tr class="border-t" style="border-color:var(--line)">
            <td class="px-6 py-3 font-semibold">${esc(s.full_name)}${Number(s.id) === Number(state.user.id) ? ' <span class="text-sm font-normal text-muted">(you)</span>' : ''}</td>
            <td class="py-3">${esc(s.email)}</td>
            <td class="py-3 tabular-nums">${esc(s.nif ?? '—')}</td>
            <td class="py-3">${esc(s.phone ?? '—')}</td>
            <td class="py-3"><span class="badge" data-role="${esc(s.role)}">${s.role === 'admin' ? 'Admin' : 'Staff'}</span></td>
            <td class="py-3"><span class="flex items-center gap-2"><span class="dot" data-s="${s.active ? 'active' : 'ended'}"></span>${s.active ? 'Active' : 'Inactive'}</span></td>
            <td class="px-6 py-3 text-right">${s.role === 'admin' && state.user.role !== 'admin' ? ''
              : `<button class="btn btn-outline px-3 py-1.5 text-sm" data-edit="${s.id}">Edit</button>`}</td>
          </tr>`).join('') || `<tr><td colspan="7" class="px-6 py-6 text-center text-muted">No staff match “${esc(state.staffQuery)}”.</td></tr>`}
        </tbody></table>
    </div>`;
  const qIn = $('#team-q');
  qIn.addEventListener('input', () => {
    state.staffQuery = qIn.value;
    const pos = qIn.selectionStart;
    renderTeam();
    const n = $('#team-q'); n.focus(); n.setSelectionRange(pos, pos);
  });
  $('#team-add').addEventListener('click', () => openStaffDialog(null));
  el.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () =>
    openStaffDialog(state.staff.find((s) => s.id === Number(b.dataset.edit)))));
}

const dlg = $('#staff-dialog');
const sform = $('#staff-form');

function openStaffDialog(s) {
  state.editingStaff = s;
  sform.reset();
  renderErrors($('#staff-errors'), []);
  $('#sd-title').textContent = s ? `Edit ${s.full_name}` : 'Add staff member';
  $('#pw-label').textContent = s ? 'New password (leave empty to keep)' : 'Temporary password';
  $('#active-wrap').hidden = !s;
  const self = s && Number(s.id) === Number(state.user.id);
  sform.role.querySelector('[value=admin]').disabled = state.user.role !== 'admin';
  sform.role.disabled = !!self;
  sform.active.disabled = !!self;
  if (s) {
    sform.full_name.value = s.full_name;
    sform.email.value = s.email;
    sform.nif.value = s.nif ?? '';
    sform.phone.value = s.phone ?? '';
    sform.role.value = s.role;
    sform.active.checked = s.active;
  }
  dlg.showModal();
  sform.full_name.focus();
}

function nifOk(n) {
  if (!/^\d{9}$/.test(n)) return false;
  let sum = 0;
  for (let i = 0; i < 8; i++) sum += Number(n[i]) * (9 - i);
  const c = 11 - (sum % 11);
  return (c >= 10 ? 0 : c) === Number(n[8]);
}

dlg.querySelector('[data-close]').addEventListener('click', () => dlg.close());
sform.nif.addEventListener('input', () => { sform.nif.value = sform.nif.value.replace(/\D/g, '').slice(0, 9); });
sform.addEventListener('submit', async (e) => {
  e.preventDefault();
  const s = state.editingStaff;
  const f = {
    full_name: sform.full_name.value.trim(), email: sform.email.value.trim(), nif: sform.nif.value.trim(),
    phone: sform.phone.value.trim(), role: sform.role.value, password: sform.password.value, active: sform.active.checked,
  };
  const problems = [];
  if (!f.full_name) problems.push('Please enter a name.');
  if (!/^\S+@\S+\.\S+$/.test(f.email)) problems.push('Please enter a valid email address.');
  if (!nifOk(f.nif)) problems.push('NIF must be a valid 9-digit Portuguese tax number.');
  if ((!s || f.password) && f.password.length < 8) problems.push('Password must be at least 8 characters.');
  if (problems.length) return renderErrors($('#staff-errors'), problems);
  await withBusy($('#staff-save'), async () => {
    try {
      const res = s ? await api.staffDo('update_staff', { ...f, id: s.id }) : await api.staffDo('create_staff', f);
      dlg.close();
      toast(res.message);
      await loadTeam();
    } catch (err) { renderErrors($('#staff-errors'), msgs(err)); }
  });
});

// ------------------------------------------------------------------ boot

async function init() {
  const user = await initNav();
  window.addEventListener('auth:change', (e) => { state.staff = []; state.locations = []; gate(e.detail); });
  gate(user);
}

init();
