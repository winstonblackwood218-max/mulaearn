// public/assets/api.js — shared fetch helpers connecting the static
// frontend to the real PHP backend. Loaded on every page BEFORE app.js.
//
// Same-origin only: this relies on the frontend being served from the
// same host as /api/*.php (see README "Serving the connected site").
// Session cookies are sent automatically for same-origin requests, so
// no CORS configuration is needed — and none is built here, since
// adding permissive CORS is exactly the kind of thing that quietly
// turns into a security hole if done carelessly.

const Api = (() => {
  let cachedCsrf = null;
  let cachedUser = null;

  async function me(force = false) {
    if (cachedUser !== null && cachedCsrf && !force) {
      return { user: cachedUser, csrf_token: cachedCsrf };
    }
    const res = await fetch('/api/me.php', { credentials: 'same-origin' });
    const data = await res.json();
    cachedCsrf = data.csrf_token;
    cachedUser = data.user;
    return { user: cachedUser, csrf_token: cachedCsrf };
  }

  async function post(url, body) {
    const { csrf_token } = await me();
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...body, csrf_token }),
    });
    let data;
    try { data = await res.json(); }
    catch { data = { success: false, error: 'Unexpected server response.' }; }
    return { ok: res.ok, status: res.status, data };
  }

  async function get(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    let data;
    try { data = await res.json(); }
    catch { data = { success: false, error: 'Unexpected server response.' }; }
    return { ok: res.ok, status: res.status, data };
  }

  /** Redirects to login.html if not authenticated. Returns the user if they are. */
  async function requireAuth() {
    const { user } = await me(true);
    if (!user) {
      const next = encodeURIComponent(location.pathname + location.search);
      location.href = `/login.html?next=${next}`;
      return null;
    }
    return user;
  }

  async function logout() {
    await post('/api/logout.php', {});
    cachedUser = null;
    cachedCsrf = null;
    location.href = '/index.html';
  }

  /** Applies logged-in/logged-out nav state on any page. Non-blocking, never redirects. */
  async function paintNavState() {
    const { user } = await me();
    document.querySelectorAll('[data-auth="in"]').forEach(el => el.style.display = user ? '' : 'none');
    document.querySelectorAll('[data-auth="out"]').forEach(el => el.style.display = user ? 'none' : '');
    document.querySelectorAll('[data-username]').forEach(el => { if (user) el.textContent = user.username; });
  }

  return { me, get, post, requireAuth, logout, paintNavState };
})();
