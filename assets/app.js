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

