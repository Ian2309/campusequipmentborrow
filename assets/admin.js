'use strict';
let me, equipment = [], requests = [], damages = [], resets = [], snap = '', act = null, eqCat = '', lowSeen = null, photoData = '';

(async function init() {
  try { me = await startSession('admin'); } catch (e) { toast(e.message, 'err'); return; }
  if (!me) return;
  $('#avatar').textContent = $('#accAvatar').textContent = initials(me.full_name || me.username);
  $('#chipId').textContent = $('#accUser').textContent = me.username;
  $('#accName').textContent = me.full_name;
  $('#logout2').addEventListener('click', logout);
  wireShell({ overview: 'Dashboard Overview', requests: 'Borrow Requests', inventory: 'Equipment Inventory', damage: 'Damage Records', resets: 'Password Reset Requests', notifications: 'Notifications', account: 'Account & Backup' });
  await refresh();
  every(5000, refresh); // new student requests and notifications appear within seconds
})();

async function refresh() {
  const [e, r, d, p] = await Promise.all([api('equipment.php'), api('admin_requests.php'), api('damage_reports.php'), api('admin_resets.php')]);
  const key = JSON.stringify([e.equipment, r.requests, d.damages, p.resets]);
  if (key !== snap) { snap = key; equipment = e.equipment; requests = r.requests; damages = d.damages; resets = p.resets; renderAll(); }
  await loadNotifications();
}

const fullName = r => `${r.first_name} ${r.last_name}`;
const isOverdue = r => r.status === 'Approved' && r.return_date < todayStr();
const studentCell = r => `<strong>${esc(fullName(r))}</strong><br><small class="muted">${esc(r.student_id)} · ${esc(r.course)}</small>`;
const dates = r => `${fmtDate(r.borrow_date)} to ${fmtDate(r.return_date)}`;
const statusCell = r => badge(r.status) + (isOverdue(r) ? ' ' + badge('Overdue') : '');
const buttons = r => r.status === 'Pending Approval'
  ? `<div class="actions"><button class="btn sm primary" data-act="approve" data-id="${r.id}">Approve</button><button class="btn sm" data-act="reject" data-id="${r.id}">Reject</button></div>`
  : r.status === 'Approved' ? `<div class="actions"><button class="btn sm primary" data-act="return" data-id="${r.id}">Mark returned</button></div>` : '';

function renderAll() {
  const pending = requests.filter(r => r.status === 'Pending Approval');
  const out = requests.filter(r => r.status === 'Approved');
  const openDmg = damages.filter(d => !d.resolved);
  const units = equipment.reduce((a, x) => ({ av: a.av + x.available, tot: a.tot + x.total }), { av: 0, tot: 0 });
  const low = equipment.filter(x => stockState(x) !== 'ok');

  $('#stats').innerHTML = [
    [pending.length, 'Pending requests'], [out.length, 'Items currently borrowed'],
    [`${units.av} / ${units.tot}`, 'Units on the shelf'], [openDmg.length, 'Unsettled damage records'], [low.length, 'Low or out-of-stock items'],
  ].map(([n, l]) => `<div class="stat"><b>${esc(n)}</b><span>${l}</span></div>`).join('');

  const set = (sel, n) => $$(sel).forEach(b => { b.textContent = n; b.hidden = !n; });
  set('[data-pending-badge]', pending.length); set('[data-damage-badge]', openDmg.length); set('[data-low-badge]', low.length);

  $('#pendBody').innerHTML = pending.slice(0, 8).map(r => `<tr><td>${studentCell(r)}</td><td>${esc(r.equipment_name)}<br><small class="muted">${esc(r.receipt_no || '')}</small></td><td>${dates(r)}</td><td>${statusCell(r)}</td><td>${buttons(r)}</td></tr>`).join('')
    || '<tr><td colspan="5" class="empty">Nothing waiting. New student requests will show up here.</td></tr>';

    renderRequests(); renderResets();
  renderLowStock(low); renderEquipOverview(); lowStockToasts(low);

  $('#invBody').innerHTML = equipment.map(x => `<tr><td>${miniThumb(x)}</td><td><strong>${esc(x.name)}</strong><br><small class="muted">${esc(x.description)}</small></td><td>${esc(x.category)}</td><td>${x.available}</td><td>${x.total}</td><td><div class="actions"><button class="btn sm primary" data-restock="${x.id}">+ Add quantity</button><button class="btn sm danger" data-del="${x.id}">Delete</button></div></td></tr>`).join('')
    || '<tr><td colspan="6" class="empty">No equipment yet. Add your first item above.</td></tr>';

  $('#dmgBody').innerHTML = damages.map(d => `<tr>
    <td>${fmtDateTime(d.created_at)}</td>
    <td><strong>${esc(d.first_name + ' ' + d.last_name)}</strong><br><small class="muted">${esc(d.student_id)}</small></td>
    <td>${esc(d.equipment_name)}<br><small class="muted">${esc(fmtDate(d.borrow_date))} to ${esc(fmtDate(d.return_date))}</small></td>
    <td>${esc(d.severity)}${d.unusable ? ' (removed from stock)' : ''}<br><small>${esc(d.description)}</small></td>
    <td>${peso(d.estimated_cost)}</td>
    <td><small>Signed by ${esc(d.waiver_signature)}<br>${fmtDateTime(d.waiver_signed_at)} · v${esc(d.waiver_version)}</small></td>
    <td>${d.resolved ? badge('Settled') : `${badge('Open')}<br><br><button class="btn sm" data-settle="${d.id}">Mark settled</button>`}</td></tr>`).join('')
    || '<tr><td colspan="7" class="empty">No damage records. Damage reported at return will be kept here with the signed waiver.</td></tr>';
}

function renderRequests() {
  const q = $('#rSearch').value.trim().toLowerCase(), f = $('#rFilter').value;
  const list = requests.filter(r => (!f || r.status === f) && `${r.receipt_no || ''} ${fullName(r)} ${r.student_id} ${r.equipment_name}`.toLowerCase().includes(q));
  $('#reqBody').innerHTML = list.map(r => `<tr>
    <td><strong class="receipt-btn">${esc(r.receipt_no || '—')}</strong></td>
    <td>${studentCell(r)}</td><td>${esc(r.equipment_name)}</td><td>${dates(r)}<br><small class="muted">${esc(r.course_code)}</small></td>
    <td>${esc(r.purpose)}</td><td><small>${esc(r.waiver_signature)}<br>${fmtDateTime(r.waiver_signed_at)}</small></td>
    <td>${statusCell(r)}${r.admin_note ? `<br><small class="muted">${esc(r.admin_note)}</small>` : ''}</td><td>${buttons(r)}</td></tr>`).join('')
    || '<tr><td colspan="8" class="empty">No requests match.</td></tr>';
}
$('#rSearch').addEventListener('input', renderRequests);
$('#rFilter').addEventListener('change', renderRequests);

// Approve / reject / return dialog
document.addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (b) {
    const r = requests.find(x => x.id === +b.dataset.id); if (!r) return;
    act = { id: r.id, type: b.dataset.act };
    $('#actForm').reset(); $('#actMsg').textContent = ''; $('#dmgBox').hidden = true;
    $('#condBox').hidden = act.type !== 'return';
    $('#actTitle').textContent = { approve: 'Approve request', reject: 'Reject request', return: 'Mark as returned' }[act.type];
    $('#actInfo').textContent = `${fullName(r)} (${r.student_id}) · ${r.equipment_name} · ${dates(r)}`;
    $('#actDialog').showModal();
  }
  const s = e.target.closest('[data-settle]'); if (s) {
    s.disabled = true;
    api('damage_reports.php', { id: +s.dataset.settle }).then(() => { toast('Damage record marked as settled.'); snap = ''; return refresh(); }).catch(err => { toast(err.message, 'err'); s.disabled = false; });
  }
});
$$('input[name=cond]').forEach(r => r.addEventListener('change', () => { $('#dmgBox').hidden = $('input[name=cond]:checked').value !== 'damaged'; }));

$('#actForm').addEventListener('submit', async e => {
  e.preventDefault();
  const body = { request_id: act.id, action: act.type, note: $('#actNote').value };
  if (act.type === 'return' && $('input[name=cond]:checked').value === 'damaged') {
    body.damage = { description: $('#dDesc').value, severity: $('#dSev').value, cost: parseFloat($('#dCost').value) || 0, unusable: $('#dUnusable').checked };
  }
  const btn = $('#actSubmit'); btn.disabled = true; $('#actMsg').textContent = '';
  try {
    await api('admin_action.php', body);
    $('#actDialog').close();
    toast({ approve: 'Request approved. The student was notified.', reject: 'Request rejected. The student was notified.', return: 'Return recorded. The student was notified.' }[act.type]);
    snap = ''; await refresh();
  } catch (err) { $('#actMsg').textContent = err.message; }
  btn.disabled = false;
});

// ---- Overview: low stock alerts + equipment grouped by category ----
function renderLowStock(low) {
  $('#lowPanel').hidden = !low.length;
  $('#lowList').innerHTML = [...low].sort((a, b) => a.available - b.available || a.name.localeCompare(b.name)).map(x =>
    `<li class="${stockState(x)}"><span><strong>${esc(x.name)}</strong> <small class="muted">${esc(x.category)}</small></span>`
    + (x.available < 1 ? '<span class="badge rejected">Out of stock</span>' : `<span class="badge pending-approval">Only ${x.available} of ${x.total} left</span>`) + `<button class="btn sm primary" data-restock="${x.id}">+ Add quantity</button></li>`).join('');
}
// One pop-up summary on first load, then one per item that newly drops to low or out of stock.
function lowStockToasts(low) {
  const now = new Set(low.map(x => x.id));
  if (lowSeen === null) { if (now.size) toast(`Low stock alert: ${now.size} item(s) are low or out of stock. See the Overview.`, 'info'); }
  else low.filter(x => !lowSeen.has(x.id)).forEach(x => toast(`Low stock alert: ${x.name} (${x.available} of ${x.total} left).`, 'info'));
  lowSeen = now;
}
function renderEquipOverview() {
  const q = $('#eqSearch').value.trim().toLowerCase();
  const cats = [...new Set(equipment.map(x => x.category))].sort();
  if (eqCat && !cats.includes(eqCat)) eqCat = '';
  $('#catTabs').innerHTML = [['', 'All', equipment.length], ...cats.map(c => [c, c, equipment.filter(x => x.category === c).length])]
    .map(([v, l, n]) => `<button type="button" data-cat="${esc(v)}" class="${v === eqCat ? 'active' : ''}">${esc(l)}<small>${n}</small></button>`).join('');
  const list = equipment.filter(x => (!eqCat || x.category === eqCat) && `${x.name} ${x.description}`.toLowerCase().includes(q));
  $('#eqGrid').innerHTML = cats.filter(c => list.some(x => x.category === c)).map(c => {
    const items = list.filter(x => x.category === c);
    return `<h4 class="cat-head">${esc(c)} <small class="muted">${items.length} item(s)</small></h4><div class="grid">${items.map(x => eqCard(x, false, true)).join('')}</div>`;
  }).join('') || '<p class="empty">No equipment found.</p>';
}
$('#eqSearch').addEventListener('input', renderEquipOverview);
$('#catTabs').addEventListener('click', e => { const b = e.target.closest('[data-cat]'); if (b) { eqCat = b.dataset.cat; renderEquipOverview(); } });
onEquipPick($('#eqGrid'), id => {
  const x = equipment.find(e => e.id === id); if (!x) return;
  const mine = requests.filter(r => +r.equipment_id === x.id);
  openEquipment(x, { rows: [
    ['Pending requests', String(mine.filter(r => r.status === 'Pending Approval').length)],
    ['Overdue', String(mine.filter(isOverdue).length)],
    ['Requests so far', String(mine.length)],
  ] });
});

// ---- Add equipment: every field is required, including a photo (resized in the browser, then uploaded) ----
const MAX_SIDE = 900;
async function resizePhoto(file) {
  const bmp = await createImageBitmap(file);
  const s = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(bmp.width * s)); c.height = Math.max(1, Math.round(bmp.height * s));
  const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(bmp, 0, 0, c.width, c.height);
  if (bmp.close) bmp.close();
  return { data: c.toDataURL('image/jpeg', 0.85), canvas: c };
}
$('#ePhoto').addEventListener('change', async e => {
  const f = e.target.files[0], m = $('#addMsg'); photoData = ''; $('#photoPrev').replaceChildren(); m.textContent = '';
  if (!f) return;
  if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { m.textContent = 'The photo must be a JPG, PNG or WebP image.'; e.target.value = ''; return; }
  if (f.size > 15 * 1024 * 1024) { m.textContent = 'That photo is too large (15 MB max).'; e.target.value = ''; return; }
  try { const r = await resizePhoto(f); photoData = r.data; $('#photoPrev').replaceChildren(r.canvas); }
  catch { m.textContent = 'Could not read that image. Try another photo.'; e.target.value = ''; }
});
['#eName', '#eCat', '#eTotal', '#eDesc', '#ePhoto'].forEach(s => ['input', 'change'].forEach(ev => $(s).addEventListener(ev, () => $(s).classList.remove('invalid'))));

$('#addForm').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = $('#addBtn'), m = $('#addMsg'); m.textContent = '';
  const v = { name: $('#eName').value.trim(), category: $('#eCat').value, total: parseInt($('#eTotal').value, 10) || 0, desc: $('#eDesc').value.trim() };
  const checks = [['#eName', !v.name, 'name'], ['#eCat', !v.category, 'category'], ['#eTotal', v.total < 1, 'total units (at least 1)'], ['#ePhoto', !photoData, 'photo'], ['#eDesc', !v.desc, 'description']];
  checks.forEach(([s, bad]) => $(s).classList.toggle('invalid', bad));
  const missing = checks.filter(c => c[1]);
  if (missing.length) { m.textContent = 'Please complete: ' + missing.map(c => c[2]).join(', ') + '.'; $(missing[0][0]).focus(); return; }
  btn.disabled = true;
  try {
    const d = await api('add_equipment.php', { ...v, photo: photoData });
    $('#addForm').reset(); $('#eTotal').value = 1; photoData = ''; $('#photoPrev').replaceChildren();
    toast(d.message); snap = ''; await refresh();
  } catch (err) { m.textContent = err.message; }
  btn.disabled = false;
});

// Backup download
$('#backupBtn').addEventListener('click', async () => {
  const btn = $('#backupBtn'); btn.disabled = true;
  try {
    const res = await fetch(API + 'backup.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: '{}' });
    if (!(res.headers.get('content-type') || '').includes('sql')) throw new Error((await res.json().catch(() => ({}))).message || 'Backup failed.');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(await res.blob());
    a.download = (res.headers.get('content-disposition') || '').match(/filename="(.+?)"/)?.[1] || 'campusmate_backup.sql';
    a.click(); URL.revokeObjectURL(a.href);
    toast('Backup downloaded and saved on the server.');
  } catch (err) { toast(err.message, 'err'); }
  btn.disabled = false;
});

// Password resets
function renderResets() {
  const open = resets.filter(r => r.status === 'Pending');
  $$('[data-reset-badge]').forEach(b => { b.textContent = open.length; b.hidden = !open.length; });
  $('#resetBody').innerHTML = resets.map(r => `<tr>
    <td>${fmtDateTime(r.created_at)}</td>
    <td><strong>${esc(r.first_name + ' ' + r.last_name)}</strong><br><small class="muted">${esc(r.student_id)} · ${esc(r.course)}</small></td>
    <td>${esc(r.email)}</td>
    <td>${r.status === 'Pending' ? badge('Pending Approval', 'Waiting') : badge('Settled', 'Done ' + fmtDateTime(r.handled_at))}</td>
    <td>${r.status === 'Pending' ? `<button class="btn sm primary" data-reset="${r.id}">Generate temporary password</button>` : ''}</td></tr>`).join('')
    || '<tr><td colspan="5" class="empty">No password reset requests.</td></tr>';
}
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-reset]'); if (!b) return;
  const r = resets.find(x => x.id === +b.dataset.reset); if (!r) return;
  b.disabled = true;
  try {
    const d = await api('admin_resets.php', { id: r.id });
    $('#tempFor').textContent = `For ${r.first_name} ${r.last_name} (${r.student_id})`;
    $('#tempPw').textContent = d.temp_password;
    $('#tempDialog').showModal();
    snap = ''; refresh();
  } catch (err) { toast(err.message, 'err'); b.disabled = false; }
});
$('#tempDialog').addEventListener('close', () => { $('#tempPw').textContent = ''; });

// Change my own password
$('#changePw').addEventListener('click', () => { $('#pwForm').reset(); $('#pwMsg').textContent = ''; $('#pwDialog').showModal(); });
$('#pwForm').addEventListener('submit', async e => {
  e.preventDefault();
  const m = $('#pwMsg'), btn = $('#pwSubmit'); m.textContent = '';
  if ($('#pwNew').value !== $('#pwNew2').value) { m.textContent = 'New passwords do not match.'; return; }
  btn.disabled = true;
  try {
    await api('change_password.php', { current: $('#pwCur').value, new: $('#pwNew').value });
    $('#pwDialog').close(); toast('Password updated.');
  } catch (err) { m.textContent = err.message; }
  btn.disabled = false;
});

// ---- Add quantity (restock) and delete: work from the Overview cards, the low-stock list and the Inventory table ----
let stockItem = null, delItem = null;
document.addEventListener('click', e => {
  const r = e.target.closest('[data-restock]'), d = e.target.closest('[data-del]');
  if (r) openStock(+r.dataset.restock); else if (d) openDelete(+d.dataset.del);
});
function openStock(id) {
  const x = equipment.find(e => e.id === id); if (!x) return;
  stockItem = x; $('#stockForm').reset(); $('#stockMsg').textContent = '';
  $('#stockTitle').textContent = 'Add quantity: ' + x.name;
  $('#stockInfo').textContent = `Right now ${x.available} available of ${x.total} total.`;
  $('#stockQty').value = 1; $('#stockDialog').showModal(); $('#stockQty').select();
}
$('#stockForm').addEventListener('submit', async e => {
  e.preventDefault();
  const qty = parseInt($('#stockQty').value, 10) || 0, m = $('#stockMsg'), btn = $('#stockSubmit'); m.textContent = '';
  if (qty < 1 || qty > 1000) { m.textContent = 'Enter a quantity between 1 and 1000.'; return; }
  btn.disabled = true;
  try {
    const d = await api('equipment_manage.php', { action: 'restock', id: stockItem.id, qty });
    $('#stockDialog').close(); toast(d.message); snap = ''; await refresh();
  } catch (err) { m.textContent = err.message; }
  btn.disabled = false;
});
function openDelete(id) {
  const x = equipment.find(e => e.id === id); if (!x) return;
  delItem = x; $('#delMsg').textContent = '';
  $('#delInfo').textContent = `Delete "${x.name}" (${x.total} units)? It disappears from the catalog for students. Items that were borrowed before cannot be deleted, because their requests and signed waivers must be kept.`;
  $('#delDialog').showModal();
}
$('#delConfirm').addEventListener('click', async () => {
  const btn = $('#delConfirm'); btn.disabled = true; $('#delMsg').textContent = '';
  try {
    const d = await api('equipment_manage.php', { action: 'delete', id: delItem.id });
    $('#delDialog').close(); toast(d.message); snap = ''; await refresh();
  } catch (err) { $('#delMsg').textContent = err.message; }
  btn.disabled = false;
});