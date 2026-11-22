// Thin client for the PHP JSON endpoints. Every page talks to the backend through this module only.
// The API folder is resolved relative to THIS file (js/api.js -> ../api/), so it works wherever the site is hosted.
const BASE = new URL('../api/', import.meta.url).href;
let csrf = null;

export class ApiError extends Error {
  constructor(message, status = 0, errors = [], detail = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.detail = detail; // real server error text (only sent when 'debug' => true in config/db.local.php)
  }
  /** Human-readable list of problems (field errors if present, otherwise the main message). */
  get messages() {
    const list = this.errors.length ? [...this.errors] : [this.message];
    if (this.detail) list.push(`Details: ${this.detail}`);
    return list;
  }
}

async function request(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' };
  let payload;
  if (method !== 'GET') {
    const token = await csrfToken();
    headers['X-CSRF-Token'] = token;
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify({ ...(body || {}), csrf: token }); // token also in the body: works even if a proxy strips headers
  }

  let res;
  try {
    res = await fetch(BASE + path, { method, headers, credentials: 'same-origin', cache: 'no-store', body: payload });
  } catch {
    throw new ApiError('Network error. Check your connection and try again.');
  }

  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch { /* not JSON */ }

  if (!res.ok || data === null) {
    if (data === null) {
      // The server answered with something that is not JSON (PHP error page, 404 page, ...)
      const hint = res.status === 404 ? `The API file was not found (${path}). Check that the api/ folder was uploaded.` : 'The server sent an unexpected response.';
      throw new ApiError(hint, res.status || 500, [], `HTTP ${res.status} from ${path}`);
    }
    throw new ApiError(data?.error || 'Request failed.', res.status, data?.errors || [], data?.detail || null);
  }
  return data;
}

async function csrfToken() {
  if (!csrf) csrf = (await session()).csrf;
  return csrf;
}

async function session() {
  const data = await request('session.php');
  csrf = data.csrf;
  return data;
}

function normalizeLocation(l) {
  return {
    id: Number(l.id),
    name: l.name,
    region: l.region,
    lat: Number(l.latitude),
    lng: Number(l.longitude),
    capacity: Number(l.capacity),
    available: Number(l.available),
    prices: { shortboard: Number(l.shortboard_price_cents), longboard: Number(l.longboard_price_cents) },
    sizes: {
      shortboard: { cap: Number(l.short_capacity), free: Number(l.short_available) },
      longboard: { cap: Number(l.long_capacity), free: Number(l.long_available) },
    },
  };
}

export const api = {
  session,
  login: (email, password) => request('auth.php', { method: 'POST', body: { action: 'login', email, password } }),
  register: (full_name, email, password) =>
    request('auth.php', { method: 'POST', body: { action: 'register', full_name, email, password } }),
  async logout() {
    await request('auth.php', { method: 'POST', body: { action: 'logout' } });
    csrf = null; // a fresh session needs a fresh token
  },
  async locations() {
    const data = await request('get_locations.php');
    return data.locations.map(normalizeLocation);
  },
  mySubscriptions: () => request('my_subscriptions.php'),
  book: (payload) => request('process_booking.php', { method: 'POST', body: payload }),
  manage: (payload) => request('manage_subscription.php', { method: 'POST', body: payload }),
  // Staff back office (403 for anyone who is not staff/admin)
  staffGet: (view) => request(`staff.php?view=${encodeURIComponent(view)}`),
  staffDo: (action, payload = {}) => request('staff.php', { method: 'POST', body: { ...payload, action } }),
};

export const isStaff = (user) => !!user && (user.role === 'staff' || user.role === 'admin');
