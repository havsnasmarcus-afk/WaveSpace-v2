// Shared interactive map (Leaflet) used by the public Map page AND the staff Lockers map.
// Both read the same `locations` table, so a beach a staff member adds shows up on the public map at its real position.
import { esc } from './ui.js';

// Leaflet is loaded on demand. If one CDN is blocked (school network, ad blocker) the next one is tried.
const SOURCES = [
  ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'],
  ['https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'],
  ['https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js'],
];
// Free tile servers that need no API key. If the first one keeps failing, the next one is used.
const TILES = [
  ['https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'],
  ['https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, tiles by <a href="https://www.hotosm.org/">HOT</a>'],
];
const PORTUGAL = [[36.9, -9.6], [42.2, -6.2]];

function addTag(tag, attrs) {
  return new Promise((resolve, reject) => {
    const el = Object.assign(document.createElement(tag), attrs);
    el.onload = resolve;
    el.onerror = () => { el.remove(); reject(new Error(`failed: ${attrs.src || attrs.href}`)); };
    document.head.append(el);
  });
}

let loading = null;
export function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  loading ||= (async () => {
    for (const [css, js] of SOURCES) {
      try {
        await Promise.all([addTag('link', { rel: 'stylesheet', href: css }), addTag('script', { src: js })]);
        if (window.L) return window.L;
      } catch { /* try the next CDN */ }
    }
    loading = null;
    throw new Error('The map could not load. Check your internet connection (or ad blocker) and reload the page.');
  })();
  return loading;
}

/** Pin colour: none free / under 25% free / plenty free. */
export function pinLevel(l) {
  if (!l.capacity || !l.available) return 'full';
  return l.available / l.capacity < 0.25 ? 'low' : 'ok';
}

/**
 * Build a map inside `el`.
 *   onSelect(id)       a beach pin was clicked
 *   onPick(lat, lng)   while picking, the map was clicked / the + pin dragged
 * Returns { setBeaches, fitAll, focus, setPicking, setDraft, clearDraft, refresh }.
 */
export async function createBeachMap(el, { onSelect = () => {}, onPick = () => {}, scrollZoom = true } = {}) {
  const L = await loadLeaflet();
  el.innerHTML = '';
  const map = L.map(el, { scrollWheelZoom: scrollZoom, zoomSnap: 0.5 });
  map.fitBounds(PORTUGAL);
  if (!scrollZoom) { // don't hijack page scrolling until the user interacts with the map
    map.once('focus click', () => map.scrollWheelZoom.enable());
  }

  // Tiles: CARTO first, switch to plain OpenStreetMap if CARTO keeps failing
  let tileIdx = 0, tileErrors = 0;
  let tiles = null;
  const useTiles = (i) => {
    if (tiles) tiles.remove();
    tiles = L.tileLayer(TILES[i][0], { maxZoom: 19, subdomains: 'abc', attribution: TILES[i][1] }).addTo(map);
    tiles.on('tileerror', () => { if (++tileErrors > 4 && tileIdx < TILES.length - 1) { tileErrors = 0; useTiles(++tileIdx); } });
  };
  useTiles(0);

  let picking = false;
  let draft = null;
  const markers = new Map();
  let beaches = [];

  map.on('click', (e) => { if (picking) { setDraft(e.latlng.lat, e.latlng.lng); onPick(round(e.latlng.lat), round(e.latlng.lng)); } });

  const round = (n) => Number(n.toFixed(6));
  const icon = (l, sel) => L.divIcon({
    className: '', iconSize: [34, 34], iconAnchor: [17, 17],
    html: `<span class="smap-pin" data-level="${pinLevel(l)}" ${sel ? 'data-selected' : ''}>${l.available}</span>`,
  });
  const label = (l) => `${l.name}: ${l.available} of ${l.capacity} lockers free`;

  /** Add/update/remove pins to match `list` (keeps existing markers, so live refreshes do not flicker). */
  function setBeaches(list, selectedId = null) {
    beaches = list;
    const seen = new Set();
    list.forEach((l) => {
      if (!Number.isFinite(l.lat) || !Number.isFinite(l.lng)) return;
      seen.add(l.id);
      const sel = l.id === selectedId;
      let m = markers.get(l.id);
      if (!m) {
        m = L.marker([l.lat, l.lng], { keyboard: true }).addTo(map);
        m.on('click', () => { if (!picking) onSelect(l.id); });
        markers.set(l.id, m);
      }
      m.setLatLng([l.lat, l.lng]);
      m.setIcon(icon(l, sel));
      m.setZIndexOffset(sel ? 1000 : 0);
      m.getElement()?.setAttribute('aria-label', label(l));
      m.getElement()?.setAttribute('title', label(l));
      m.unbindTooltip().bindTooltip(`<strong>${esc(l.name)}</strong><br>${l.available} of ${l.capacity} free`, { direction: 'top', offset: [0, -14] });
    });
    markers.forEach((m, id) => { if (!seen.has(id)) { m.remove(); markers.delete(id); } });
  }

  function fitAll() {
    const pts = beaches.filter((l) => Number.isFinite(l.lat)).map((l) => [l.lat, l.lng]);
    if (pts.length > 1) map.fitBounds(L.latLngBounds(pts).pad(0.2));
    else if (pts.length === 1) map.setView(pts[0], 11);
  }

  function focus(id) {
    const l = beaches.find((x) => x.id === id);
    if (l && !map.getBounds().pad(-0.1).contains([l.lat, l.lng])) map.panTo([l.lat, l.lng]);
  }

  function setPicking(on) {
    picking = on;
    el.classList.toggle('placing', on);
  }

  function setDraft(lat, lng) {
    if (!draft) {
      draft = L.marker([lat, lng], {
        draggable: true, keyboard: true, title: 'New position (drag to adjust)',
        icon: L.divIcon({ className: '', html: '<span class="smap-pin" data-level="draft">+</span>', iconSize: [34, 34], iconAnchor: [17, 17] }),
      }).addTo(map);
      draft.on('dragend', () => { const p = draft.getLatLng(); onPick(round(p.lat), round(p.lng)); });
    } else {
      draft.setLatLng([lat, lng]);
    }
  }

  function clearDraft() {
    if (draft) { draft.remove(); draft = null; }
  }

  // The map is often created/shown inside a container that just became visible: re-measure it.
  const refresh = () => setTimeout(() => map.invalidateSize(), 0);
  new ResizeObserver(() => map.invalidateSize()).observe(el);

  return { map, setBeaches, fitAll, focus, setPicking, setDraft, clearDraft, refresh };
}
