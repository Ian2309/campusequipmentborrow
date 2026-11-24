'use strict';
let role = 'student';
const msg = (el, text, ok) => { el.textContent = text; el.classList.toggle('ok', !!ok); };

async function ensureToken() {
  if (CSRF) return;
  const s = await api('session.php');
  CSRF = s.csrf;
  if (s.authenticated) location.replace(s.role === 'admin' ? 'admin/index.html' : 'student/index.html');
}
ensureToken().catch(e => msg($('#loginMsg'), e.message));

function setRole(r) {
  role = r;
  document.body.className = 'theme-' + r;
  $$('.role-switch button').forEach(b => { const on = b.dataset.role === r; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on); });
  $('#userLabel').textContent = r === 'admin' ? 'Admin username' : 'Student number or email';
  $('#loginBtn').textContent = r === 'admin' ? 'Log in as PEMU Admin' : 'Log in as Student';
  $('#showForgot').hidden = r === 'admin';
  showForm('login');
  msg($('#loginMsg'), ''); mark(null);
}
function mark(field) {
  ['username', 'password'].forEach(id => { const on = field === id || field === 'both'; $('#' + id).classList.toggle('invalid', on); $('#' + id).setAttribute('aria-invalid', on); });
  const first = field === 'password' ? 'password' : 'username';
  if (field) $('#' + first).focus();
}
['username', 'password'].forEach(id => $('#' + id).addEventListener('input', () => { $('#' + id).classList.remove('invalid'); msg($('#loginMsg'), ''); }));

function showForm(which) {
  $('#forgotForm').hidden = which !== 'forgot';
  $('#loginForm').hidden = which !== 'login';
  $('#regLink').hidden = which !== 'login' || role === 'admin';
  $('#regForm').hidden = which !== 'reg';
}
$$('.role-switch button').forEach(b => b.addEventListener('click', () => setRole(b.dataset.role)));
$('#showReg').addEventListener('click', e => { e.preventDefault(); showForm('reg'); });
$('#showForgot').addEventListener('click', e => { e.preventDefault(); showForm('forgot'); });
$('#backLogin').addEventListener('click', e => { e.preventDefault(); showForm('login'); });
$('#showLogin').addEventListener('click', e => { e.preventDefault(); showForm('login'); });

$('#loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = $('#loginBtn'); btn.disabled = true; msg($('#loginMsg'), ''); mark(null);
  try {
    await ensureToken();
    const d = await api('login.php', { role, username: $('#username').value.trim(), password: $('#password').value });
    location.href = d.redirect;
  } catch (err) { msg($('#loginMsg'), err.message); mark(err.field); btn.disabled = false; }
});

$('#regForm').addEventListener('submit', async e => {
  e.preventDefault();
  const m = $('#regMsg'), btn = $('#regBtn');
  if ($('#r_pass').value !== $('#r_pass2').value) return msg(m, 'Passwords do not match.');
  btn.disabled = true; msg(m, '');
  try {
    await ensureToken();
    const sid = $('#r_sid').value.trim();
    const d = await api('register.php', {
      firstname: $('#r_first').value, lastname: $('#r_last').value, student_id: sid, email: $('#r_email').value,
      course: $('#r_course').value, year_level: $('#r_year').value, password: $('#r_pass').value,
    });
    $('#regForm').reset(); showForm('login');
    $('#username').value = sid; $('#password').value = '';
    msg($('#loginMsg'), d.message, true);
  } catch (err) { msg(m, err.message); }
  btn.disabled = false;
});

$('#forgotForm').addEventListener('submit', async e => {
  e.preventDefault();
  const m = $('#forgotMsg'), btn = $('#forgotBtn'); btn.disabled = true; msg(m, '');
  try {
    await ensureToken();
    const d = await api('forgot_password.php', { student_id: $('#f_sid').value, email: $('#f_email').value });
    $('#forgotForm').reset(); msg(m, d.message, true);
  } catch (err) { msg(m, err.message); }
  btn.disabled = false;
});