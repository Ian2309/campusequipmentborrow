'use strict';
let me, equipment = [], requests = [], waiver = '', current = null, snap = '';

(async function init() {
  try { me = await startSession('student'); } catch (e) { toast(e.message, 'err'); return; }
  if (!me) return;
  const full = `${me.first_name} ${me.last_name}`;
  $('#avatar').textContent = $('#accAvatar').textContent = initials(full);
  $('#chipId').textContent = $('#accSid').textContent = me.student_id;
  $('#accName').textContent = full; $('#accEmail').textContent = me.email;
  $('#accCourse').textContent = me.course; $('#accYear').textContent = me.year_level;
  $('#sigLabel').textContent = `Type your full name to sign (${full})`;
  $('#logout2').addEventListener('click', logout);

  wireShell({ catalog: 'Equipment Catalog', requests: 'My Borrow Requests', notifications: 'Notifications', account: 'Account Details' });
  $('#changePw').addEventListener('click', () => openPw(false));
  if (me.must_change_password) openPw(true); else await startData();
})();

async function startData() {
  api('borrow.php').then(d => { waiver = d.waiver; }).catch(() => {});
  await refresh();
  every(5000, refresh); // live updates: new equipment, stock changes, decisions, notifications
}

// Change password. Forced (cannot be dismissed) when PEMU issued a temporary password.
let pwForced = false;
function openPw(forced) {
  pwForced = forced;
  $('#pwForm').reset(); $('#pwMsg').textContent = '';
  $('#pwTitle').textContent = forced ? 'Set a new password' : 'Change password';
  $('#pwInfo').textContent = forced ? 'You logged in with a temporary password from PEMU. Choose your own password to continue.' : '';
  $('#pwCurLabel').textContent = forced ? 'Temporary password' : 'Current password';
  $('#pwCancel').hidden = forced;
  $('#pwDialog').showModal();
}
$('#pwDialog').addEventListener('cancel', e => { if (pwForced) e.preventDefault(); });
$('#pwForm').addEventListener('submit', async e => {
  e.preventDefault();
  const m = $('#pwMsg'), btn = $('#pwSubmit'); m.textContent = '';
  if ($('#pwNew').value !== $('#pwNew2').value) { m.textContent = 'New passwords do not match.'; return; }
  btn.disabled = true;
  try {
    await api('change_password.php', { current: $('#pwCur').value, new: $('#pwNew').value });
    $('#pwDialog').close();
    toast('Password updated.');
    if (pwForced) { pwForced = false; await startData(); }
  } catch (err) { m.textContent = err.message; }
  btn.disabled = false;
});

async function refresh() {
  const [e, r] = await Promise.all([api('equipment.php'), api('my_requests.php')]);
  const key = JSON.stringify([e.equipment, r.requests]);
  if (key !== snap) { snap = key; equipment = e.equipment; requests = r.requests; renderCatalog(); renderRequests(); }
  await loadNotifications();
}

function renderCatalog() {
  const sel = $('#catFilter'), keep = sel.value;
  const cats = [...new Set(equipment.map(x => x.category))].sort();
  sel.innerHTML = '<option value="">All categories</option>' + cats.map(c => `<option>${esc(c)}</option>`).join('');
  sel.value = cats.includes(keep) ? keep : '';

  const q = $('#search').value.trim().toLowerCase();
  const list = equipment.filter(x => (!sel.value || x.category === sel.value) && (x.name + ' ' + x.description).toLowerCase().includes(q));
  $('#grid').innerHTML = list.map(x => eqCard(x, true)).join('') || '<p class="empty">No equipment found.</p>';
}

function renderRequests() {
  $('#reqBody').innerHTML = requests.map(r => `<tr>
    <td><strong>${esc(r.equipment_name)}</strong><br><small class="muted">${esc(r.purpose)}</small></td>
    <td>${fmtDate(r.borrow_date)}</td><td>${fmtDate(r.return_date)}</td><td>${esc(r.course_code)}</td>
    <td>${badge(r.status)}</td><td>${esc(r.admin_note) || '—'}</td></tr>`).join('')
    || '<tr><td colspan="6" class="empty">No requests yet. Pick an item from the catalog.</td></tr>';
  $('#accReqs').textContent = requests.length;
}

$('#search').addEventListener('input', renderCatalog);
$('#catFilter').addEventListener('change', renderCatalog);

// Borrow dialog with online waiver
function openRequest(id) {
  current = equipment.find(x => x.id === id); if (!current) return;
  $('#reqForm').reset();
  $('#reqTitle').textContent = 'Borrow: ' + current.name;
  $('#waiverText').textContent = waiver || 'Waiver unavailable. Please reload the page.';
  $('#bDate').min = $('#rDate').min = todayStr();
  $('#reqMsg').textContent = '';
  $('#reqDialog').showModal();
}
$('#grid').addEventListener('click', e => { const b = e.target.closest('[data-req]'); if (b) openRequest(+b.dataset.req); });

// Clicking a card (or pressing Enter on it) shows the item's full details
onEquipPick($('#grid'), id => {
  const x = equipment.find(e => e.id === id); if (!x) return;
  const mine = requests.find(r => +r.equipment_id === x.id && ['Pending Approval', 'Approved'].includes(r.status));
  openEquipment(x, {
    rows: mine ? [['Your request', badge(mine.status)]] : [],
    blocked: !!mine,
    note: mine ? `You already have a ${mine.status.toLowerCase()} request for this item.` : '',
    onRequest: () => { $('#eqDialog').close(); openRequest(x.id); },
  });
});

$('#reqForm').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = $('#reqSubmit'), msg = $('#reqMsg'); msg.textContent = ''; btn.disabled = true;
  try {
    await api('borrow.php', {
      equipment_id: current.id, purpose: $('#purpose').value, borrow_date: $('#bDate').value, return_date: $('#rDate').value,
      course_code: $('#course').value, waiver_agreed: $('#agree').checked, signature: $('#signature').value,
    });
    $('#reqDialog').close();
    snap = ''; await refresh(); // the confirmation arrives as a notification toast
  } catch (err) { msg.textContent = err.message; }
  btn.disabled = false;
});