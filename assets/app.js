'use strict';
// Shared helpers used by every page. No inline scripts anywhere (strict CSP).
const API = document.documentElement.dataset.api;
let CSRF = '';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const loginUrl = () => new URL('../index.html', new URL(API, location.href)).href;
const initials = n => String(n).trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
const toDate = d => new Date(d.length === 10 ? d + 'T00:00:00' : d.replace(' ', 'T'));
const fmtDate = d => d ? toDate(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
const fmtDateTime = d => d ? toDate(d).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
const todayStr = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const badge = (s, label) => `<span class="badge ${esc(s.toLowerCase().replace(/\s+/g, '-'))}">${esc(label || s)}</span>`;
const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });

async function api(file, body) {
  const opt = body === undefined
    ? { credentials: 'same-origin' }
    : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) };
  let res;
  try { res = await fetch(API + file, opt); } catch { throw new Error('Cannot reach the server. Make sure Apache is running and you are online.'); }
  let data;
  try { data = await res.json(); } catch { throw new Error('Unexpected server response.'); }
  if (!data.success) {
    if (res.status === 401 && document.body.dataset.page !== 'login') location.replace(loginUrl());
    const err = new Error(data.message || 'Request failed.');
    err.field = data.field;
    throw err;
  }
  return data;
}

function toast(msg, kind = 'ok') {
  const t = document.createElement('div');
  t.className = 'toast ' + kind; t.textContent = msg;
  $('#toasts').append(t);
  setTimeout(() => t.remove(), 6000);
}

// Confirms the visitor holds the right role, stores the CSRF token, returns the user (or redirects to login).
async function startSession(role) {
  const s = await api('session.php');
  CSRF = s.csrf;
  if (!s.authenticated || s.role !== role) { location.replace(loginUrl()); return null; }
  return s.user;
}
// Asks for confirmation first, then logs out. Every logout button calls this.
function logout() {
  let d = $('#logoutDialog');
  if (!d) {
    d = document.createElement('dialog'); d.id = 'logoutDialog';
    d.innerHTML = '<h3>Log out?</h3><p class="muted">Are you sure you want to log out of your account?</p>'
      + '<div class="dialog-foot"><button type="button" class="btn" data-close>Stay logged in</button><button type="button" class="btn primary" id="logoutYes">Yes, log out</button></div>';
    document.body.append(d);
    $('#logoutYes', d).addEventListener('click', async () => {
      try { await api('logout.php', {}); } catch { /* ignore */ }
      location.replace(loginUrl());
    });
  }
  d.showModal();
}

const every = (ms, fn) => setInterval(() => { if (!document.hidden) fn().catch(() => {}); }, ms);

// Notifications (shared by student + admin pages). New unread items pop up as a toast.
let seenNotif = null;
async function loadNotifications() {
  const d = await api('notifications.php');
  if (seenNotif !== null) d.notifications.filter(n => n.id > seenNotif && !n.is_read).reverse().forEach(n => toast(n.message, 'info'));
  seenNotif = Math.max(seenNotif ?? 0, 0, ...d.notifications.map(n => n.id));
  $$('[data-notif-badge]').forEach(b => { b.textContent = d.unread; b.hidden = !d.unread; });
  $('#notifList').innerHTML = d.notifications.map(n =>
    `<li class="${n.is_read ? '' : 'unread'}"><span class="dot"></span><div><p>${esc(n.message)}</p><time>${fmtDateTime(n.created_at)}</time></div></li>`
  ).join('') || '<li class="empty">No notifications yet.</li>';
}

// Shared page chrome: tab switching, logout, mark-all-read, dialog close buttons, broken-image fallback.
function wireShell(titles, onTab) {
  const show = tab => {
    $$('.view').forEach(v => { v.hidden = v.id !== 'view-' + tab; });
    $$('.nav button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    $('#pageTitle').textContent = titles[tab];
    if (onTab) onTab(tab);
  };
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-tab]'); if (t) show(t.dataset.tab);
    const c = e.target.closest('[data-close]'); if (c) c.closest('dialog').close();
  });
  document.addEventListener('error', e => { if (e.target.tagName === 'IMG') e.target.classList.add('broken'); }, true);
  $('#logout').addEventListener('click', logout);
  $('#markRead').addEventListener('click', async () => { await api('notifications.php', { action: 'read_all' }); loadNotifications(); });
  show(Object.keys(titles)[0]);
}

// ---- Equipment helpers shared by the student catalog and the admin overview ----
// Stock state: out = none left, low = 1 left or a quarter (or less) of the units, ok = healthy. Same rule the assistant uses.
const stockState = x => x.available < 1 ? 'out' : (x.available <= 1 || x.available * 4 <= x.total ? 'low' : 'ok');
const stockLabel = x => ({ out: 'Out of stock', low: 'Low stock', ok: 'Available' })[stockState(x)];
// Uploaded photos are stored as "uploads/equipment/xyz.jpg" (relative to the site root); old items may hold a full https:// link.
const imgUrl = p => !p ? '' : /^https?:\/\//i.test(p) ? p : new URL('../' + String(p).replace(/^\/+/, ''), new URL(API, location.href)).href;
const photoImg = x => x.image ? `<img src="${esc(imgUrl(x.image))}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '';
const miniThumb = x => `<div class="mini-thumb">${esc(x.name[0].toUpperCase())}${photoImg(x)}</div>`;

function eqCard(x, canRequest, admin) {
  const st = stockState(x);
  return `<article class="eq" data-eq="${x.id}" tabindex="0" aria-label="View details of ${esc(x.name)}">
    <div class="thumb">${esc(x.name[0].toUpperCase())}${photoImg(x)}</div>
    <div class="eq-body">
      <span class="pill">${esc(x.category)}</span><h3>${esc(x.name)}</h3>
      <p>${esc(x.description) || 'No description.'}</p>
      <progress max="${Math.max(x.total, 1)}" value="${x.available}"></progress>
      <div class="stock ${st === 'ok' ? '' : st}"><span>${x.available ? x.available + ' available' : 'Out of stock'}</span><span>${x.total} total</span></div>
      ${canRequest ? `<button class="btn primary" data-req="${x.id}" ${x.available ? '' : 'disabled'}>Request to borrow</button>` : ''}
      ${admin ? `<div class="actions"><button class="btn sm primary" data-restock="${x.id}">+ Add quantity</button><button class="btn sm danger" data-del="${x.id}">Delete</button></div>` : ''}
    </div></article>`;
}

// Cards open the details dialog on click or Enter/Space (the Request button inside a card keeps its own action).
function onEquipPick(root, handler) {
  root.addEventListener('click', e => { if (e.target.closest('[data-req],[data-restock],[data-del]')) return; const c = e.target.closest('[data-eq]'); if (c) handler(+c.dataset.eq); });
  root.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-eq]')) { e.preventDefault(); handler(+e.target.dataset.eq); } });
}

// Details dialog (#eqDialog). opts.rows: extra [label, html] rows. opts.onRequest: shows the Request button.
// opts.blocked + opts.note: keep the button disabled and say why.
function openEquipment(x, opts = {}) {
  const st = stockState(x);
  $('#eqPhoto').innerHTML = esc(x.name[0].toUpperCase()) + (x.image ? `<img src="${esc(imgUrl(x.image))}" alt="${esc(x.name)}" referrerpolicy="no-referrer">` : '');
  $('#eqCat').textContent = x.category;
  $('#eqName').textContent = x.name;
  $('#eqDesc').textContent = x.description || 'No description provided.';
  const cls = { ok: 'approved', low: 'pending-approval', out: 'rejected' }[st];
  const rows = [
    ['Status', `<span class="badge ${cls}">${esc(stockLabel(x))}</span>`],
    ['Available now', `${x.available} of ${x.total} units`],
    ['Currently borrowed', String(Math.max(x.total - x.available, 0))],
    ['Loan period', 'Up to 14 days'],
    ['Pick-up and return', 'PEMU office'],
    ...(opts.rows || []),
  ];
  $('#eqFacts').innerHTML = rows.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${v}</dd>`).join('');
  const btn = $('#eqRequest');
  btn.hidden = !opts.onRequest;
  btn.disabled = x.available < 1 || !!opts.blocked;
  btn.onclick = opts.onRequest || null;
  $('#eqNote').textContent = opts.note || (x.available < 1 ? 'This item is out of stock right now.' : '');
  $('#eqDialog').showModal();
}

// Connection watcher: tells the user when the internet drops (pages keep showing the last loaded data).
(() => {
  const bar = document.createElement('div');
  bar.className = 'net-banner'; bar.setAttribute('role', 'status'); bar.hidden = true;
  bar.textContent = 'You are offline. What you see may be out of date and will refresh when your connection returns.';
  const sync = () => { bar.hidden = navigator.onLine; };
  const mount = () => { document.body.append(bar); sync(); };
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
  window.addEventListener('offline', sync);
  window.addEventListener('online', () => { sync(); if ($('#toasts')) toast('Back online.', 'info'); });
})();

// ---- dark mode toggle (remembered in this browser; only on pages with the top bar, never on login) ----
(function () {
  const KEY = 'rtu-theme', root = document.documentElement;
  const moon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>';
  const sun = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  let dark = false;
  try { dark = localStorage.getItem(KEY) === 'dark'; } catch (e) {}
  const apply = () => {
    const on = dark && !!document.querySelector('.top-r');
    const el = document.querySelector('.theme-admin,.theme-student');
    [root, el].forEach(n => { if (n) { if (on) n.setAttribute('data-dark', ''); else n.removeAttribute('data-dark'); } });
    const b = document.getElementById('themeToggle');
    if (b) { b.innerHTML = dark ? sun : moon; b.title = dark ? 'Switch to light mode' : 'Switch to dark mode'; b.setAttribute('aria-label', b.title); }
  };
  const mount = () => {
    const bar = document.querySelector('.top-r');
    if (bar && !document.getElementById('themeToggle')) {
      const b = document.createElement('button');
      b.type = 'button'; b.id = 'themeToggle'; b.className = 'icon-btn';
      b.addEventListener('click', () => { dark = !dark; try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (e) {} apply(); });
      bar.prepend(b);
    }
    apply();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();

// ---- professional sidebar labels (text only; same buttons, same behavior) ----
(function () {
  const admin = { overview: 'Dashboard Overview', requests: 'Borrow Requests', inventory: 'Equipment Inventory', damage: 'Damage Records', notifications: 'Notifications', account: 'Account & Backup' };
  const student = { catalog: 'Equipment Catalog', requests: 'My Borrow Requests', notifications: 'Notifications', account: 'Account Details' };
  const run = () => {
    const isAdmin = !!document.querySelector('.nav [data-tab="overview"]');
    const map = isAdmin ? admin : student;
    document.querySelectorAll('.nav button[data-tab]').forEach(b => {
      const label = map[b.dataset.tab]; if (!label) return;
      const t = [...b.childNodes].find(n => n.nodeType === 3 && n.nodeValue.trim());
      if (t) t.nodeValue = label;
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();

// ---- greeting banner with live date and time (admin Dashboard + student Catalog) ----
(function () {
  const ADMIN_NAME = "Ma'am Gunda"; // shown only on the admin banner
  const st = document.createElement('style');
  st.textContent = `
.wb{position:relative;overflow:hidden;margin-bottom:1.6rem;padding:2rem 13rem 2rem 2.2rem;min-height:190px;border-radius:22px;color:#fff;
  background:radial-gradient(circle at 90% -20%,rgba(255,255,255,.3) 0,transparent 52%),radial-gradient(circle at 5% 130%,rgba(255,255,255,.16) 0,transparent 45%),var(--side);
  box-shadow:0 16px 36px rgba(0,0,0,.22)}
.wb small{display:inline-flex;align-items:center;gap:.5rem;padding:.4rem .9rem;border-radius:99px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);font-size:.9rem;font-weight:600;letter-spacing:.02em}
.wb h3{margin:.9rem 0 .2rem;font-size:clamp(1.7rem,4.6vw,2.7rem);line-height:1.15;color:#fff;letter-spacing:-.01em;text-shadow:0 2px 10px rgba(0,0,0,.25)}
.wb .wb-time{font-size:clamp(1.15rem,2.6vw,1.6rem);font-weight:700;font-variant-numeric:tabular-nums;opacity:.95;margin-bottom:.5rem}
.wb p{margin:0;max-width:34rem;opacity:.95;font-size:1.02rem}
.wb::after{content:"";position:absolute;right:1.6rem;bottom:-10px;width:170px;height:170px;background:url("../assets/images/robot.svg") center/contain no-repeat;filter:drop-shadow(0 10px 14px rgba(0,0,0,.3));animation:wb-float 4s ease-in-out infinite}
@keyframes wb-float{50%{transform:translateY(-8px)}}
@media(prefers-reduced-motion:reduce){.wb::after{animation:none}}
@media(max-width:700px){.wb{padding:1.4rem 7.5rem 1.4rem 1.3rem;min-height:150px}.wb::after{width:104px;height:104px;right:.6rem}.wb p{font-size:.9rem}}
@media(max-width:480px){.wb{padding-right:6.2rem}.wb small{font-size:.74rem}.wb::after{width:84px;height:84px}}`;
  document.head.appendChild(st);

  let name = '';
  const greet = d => {
    const h = d.getHours(); // 0-23, so 12:00 am is 0
    if (h < 12) return ['Good morning', h < 5 ? '\u{1F305}' : '\u2600\uFE0F'];
    if (h < 18) return ['Good afternoon', '\u{1F324}\uFE0F'];
    return ['Good evening', '\u{1F319}'];
  };
  const tick = () => {
    const box = document.querySelector('.wb'); if (!box) return;
    const d = new Date(), g = greet(d);
    box.querySelector('small').textContent = '\u{1F4C5} ' + d.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    box.querySelector('h3').textContent = g[0] + (name ? ', ' + name : '') + '! ' + g[1];
    box.querySelector('.wb-time').textContent = '\u{1F551} ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
  };
  const build = () => {
    const isAdmin = !!document.querySelector('.nav [data-tab="overview"]');
    const view = document.getElementById(isAdmin ? 'view-overview' : 'view-catalog');
    if (!view) return false;
    const src = document.getElementById('accName');
    const real = (src && src.textContent.trim()) || '';
    name = isAdmin ? (ADMIN_NAME || real) : real.split(/\s+/)[0];
    if (!view.querySelector('.wb')) {
      const box = document.createElement('section'); box.className = 'wb';
      box.innerHTML = '<small></small><h3></h3><div class="wb-time"></div><p></p>';
      box.querySelector('p').textContent = isAdmin ? 'Welcome back to RTU Equipment Hub. Here is your equipment overview for today.' : 'Welcome back to RTU Equipment Hub. Browse the catalog and request the equipment you need.';
      view.prepend(box);
    }
    tick();
    return !!name;
  };
  const start = () => { let n = 0; const t = setInterval(() => { if (build() || ++n > 20) clearInterval(t); }, 500); build(); setInterval(tick, 1000); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

// ---- show/hide eye on every password field (login, register, dialogs) ----
(function () {
  const A = 'viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="display:block;pointer-events:none"';
  const eye = '<svg ' + A + '><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeOff = '<svg ' + A + '><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><path d="M1 1l22 22"/></svg>';
  const lockSvg = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>';

  const enhance = () => {
    document.querySelectorAll('input[type="password"]:not([data-pw])').forEach(inp => {
      inp.setAttribute('data-pw', '');
      const wrap = document.createElement('span');
      wrap.style.cssText = 'position:relative;display:block';
      inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
      inp.style.paddingRight = '3.2rem';
      if (inp.closest('.auth-card')) {
        inp.style.paddingLeft = '3rem';
        const l = document.createElement('span');
        l.innerHTML = lockSvg;
        l.style.cssText = 'position:absolute;left:1rem;top:50%;transform:translateY(-50%);width:20px;height:20px;display:flex;pointer-events:none;color:#0a2f7a;z-index:2';
        wrap.appendChild(l);
      }
      const b = document.createElement('button');
      b.type = 'button'; b.innerHTML = eye; b.title = 'Show password'; b.setAttribute('aria-label', 'Show password');
      b.style.cssText = 'position:absolute;right:6px;top:50%;transform:translateY(-50%);width:40px;height:40px;padding:0;margin:0;border:0;border-radius:8px;background:transparent;color:inherit;opacity:.7;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2';
      b.addEventListener('mouseenter', () => { b.style.background = 'rgba(100,116,139,.18)'; b.style.opacity = '1'; });
      b.addEventListener('mouseleave', () => { b.style.background = 'transparent'; b.style.opacity = '.7'; });
      b.addEventListener('click', () => {
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        b.innerHTML = show ? eyeOff : eye;
        b.title = show ? 'Hide password' : 'Show password'; b.setAttribute('aria-label', b.title);
        inp.focus();
      });
      wrap.appendChild(b);
    });
  };
  const start = () => { enhance(); new MutationObserver(enhance).observe(document.body, { childList: true, subtree: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

// ---- bigger greeting (overrides the sizes of the banner above) ----
(function () {
  const st = document.createElement('style');
  st.textContent = `
body .wb{min-height:240px;padding:2.4rem 15rem 2.4rem 2.6rem;border-radius:26px}
body .wb h3{font-size:clamp(2.2rem,6.4vw,4rem);font-weight:800;line-height:1.05;letter-spacing:-.025em;margin:1rem 0 .3rem}
body .wb small{font-size:1.02rem;padding:.5rem 1.1rem}
body .wb .wb-time{font-size:clamp(1.4rem,3vw,2rem);font-weight:800}
body .wb p{font-size:1.15rem;max-width:40rem}
body .wb::after{width:210px;height:210px;right:2rem}
@media(max-width:700px){body .wb{padding:1.5rem 7.8rem 1.5rem 1.3rem;min-height:170px}body .wb h3{font-size:clamp(1.7rem,8vw,2.3rem)}body .wb::after{width:118px;height:118px}}
@media(max-width:480px){body .wb{padding-right:6.4rem}body .wb h3{font-size:1.65rem}body .wb .wb-time{font-size:1.1rem}body .wb::after{width:92px;height:92px}}`;
  document.head.appendChild(st);
})();

// ---- RTU favicon (no HTML changes) ----
(function () {
  const css = document.querySelector('link[rel="stylesheet"][href*="app.css"]');
  if (!css) return;
  const href = css.getAttribute('href').replace(/app\.css.*$/, 'images/rtu-logo.png');
  let ic = document.querySelector('link[rel~="icon"]');
  if (!ic) { ic = document.createElement('link'); ic.rel = 'icon'; document.head.appendChild(ic); }
  ic.type = 'image/png'; ic.href = href;
})();

// ---- draggable chatbot orb (touch + mouse; remembers where you put it) ----
(function () {
  const KEY = 'rtu-orb-pos';
  const setPos = (wrap, x, y) => {
    const w = wrap.offsetWidth || 68, h = wrap.offsetHeight || 68;
    x = Math.min(Math.max(8, x), window.innerWidth - w - 8);
    y = Math.min(Math.max(8, y), window.innerHeight - h - 8);
    wrap.style.setProperty('left', x + 'px', 'important');
    wrap.style.setProperty('top', y + 'px', 'important');
    wrap.style.setProperty('bottom', 'auto', 'important');
    wrap.classList.toggle('orb-right', x + w / 2 > window.innerWidth / 2);
    wrap.classList.toggle('orb-down', y < 460);
  };
  const init = wrap => {
    const orb = wrap.querySelector('.orb'); if (!orb) return false;
    orb.style.touchAction = 'none'; orb.style.userSelect = 'none'; orb.style.webkitUserSelect = 'none';
    let sx = 0, sy = 0, ox = 0, oy = 0, down = false, moved = false;
    const start = (x, y) => { const r = wrap.getBoundingClientRect(); down = true; moved = false; sx = x; sy = y; ox = r.left; oy = r.top; };
    const move = (x, y) => {
      if (!down) return false;
      const dx = x - sx, dy = y - sy;
      if (!moved && Math.hypot(dx, dy) < 8) return false;
      moved = true; wrap.classList.add('orb-dragging'); setPos(wrap, ox + dx, oy + dy); return true;
    };
    const end = () => {
      if (!down) return; down = false; wrap.classList.remove('orb-dragging');
      if (moved) { const r = wrap.getBoundingClientRect(); try { localStorage.setItem(KEY, JSON.stringify([r.left, r.top])); } catch (_) {} }
    };
    orb.addEventListener('touchstart', e => { const t = e.touches[0]; start(t.clientX, t.clientY); }, { passive: true });
    orb.addEventListener('touchmove', e => { const t = e.touches[0]; if (move(t.clientX, t.clientY)) e.preventDefault(); }, { passive: false });
    orb.addEventListener('touchend', end); orb.addEventListener('touchcancel', end);
    orb.addEventListener('mousedown', e => { if (!e.button) start(e.clientX, e.clientY); });
    document.addEventListener('mousemove', e => { move(e.clientX, e.clientY); });
    document.addEventListener('mouseup', end);
    wrap.addEventListener('click', e => { if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; } }, true);
    let p = null; try { p = JSON.parse(localStorage.getItem(KEY)); } catch (_) {}
    if (p && p.length === 2) setPos(wrap, p[0], p[1]);
    window.addEventListener('resize', () => { if (wrap.style.getPropertyValue('top')) { const r = wrap.getBoundingClientRect(); setPos(wrap, r.left, r.top); } });
    return true;
  };
  const start = () => { let n = 0; const t = setInterval(() => { const w = document.querySelector('.orb-wrap'); if ((w && init(w)) || ++n > 40) clearInterval(t); }, 500); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();


