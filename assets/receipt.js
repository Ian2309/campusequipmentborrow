// Fills student/receipt.html with one of the logged-in student's own receipts.
// URL: receipt.html?no=RTU-123456   (add &print=1 to open the print / Save as PDF window automatically)
(async function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const no = (params.get('no') || '').trim().toUpperCase();

  function show(text, withLogin) {
    const m = $('msg');
    m.hidden = false;
    m.textContent = text;
    if (withLogin) {
      m.append(' ');
      const a = document.createElement('a');
      a.href = '../index.html';
      a.textContent = 'Go to the login page';
      m.append(a);
    }
  }

  const toDate = s => new Date(String(s).replace(' ', 'T'));
  const fmtDate = s => s ? toDate(s).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : '';
  const fmtDateTime = s => s ? toDate(s).toLocaleString('en-PH', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';

  async function get(file) {
    const res = await fetch('../api/' + file, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let data = null;
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok || !data || data.success === false) {
      const err = new Error((data && data.message) || 'Could not load the receipt.');
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function addRow(dl, label, value) {
    if (!value) return;
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    dd.textContent = value;
    dl.append(dt, dd);
  }

  if (!/^RTU-\d{6}$/.test(no)) {
    show('This receipt link is not valid.');
    return;
  }

  try {
    const session = await get('session.php');
    if (!session.authenticated || session.role !== 'student') {
      show('Please log in as a student to view this receipt.', true);
      return;
    }
    const list = await get('my_requests.php');
    const r = (list.requests || []).find(x => x.receipt_no === no);
    if (!r) {
      show('Receipt not found. It may belong to another account.');
      return;
    }
    const u = session.user || {};
    const fullName = [u.first_name, u.last_name].filter(Boolean).join(' ');

    $('rNo').textContent = r.receipt_no;
    $('rStatus').textContent = r.status;

    const b = $('rBorrower');
    addRow(b, 'Name', fullName);
    addRow(b, 'Student number', u.student_id);
    addRow(b, 'Course and year', [u.course, u.year_level].filter(Boolean).join(' · '));
    addRow(b, 'Email', u.email);

    const d = $('rDetails');
    addRow(d, 'Equipment', r.equipment_name);
    addRow(d, 'Borrow date', fmtDate(r.borrow_date));
    addRow(d, 'Return date', fmtDate(r.return_date));
    addRow(d, 'Course code', r.course_code);
    addRow(d, 'Purpose', r.purpose);
    addRow(d, 'Request submitted', fmtDateTime(r.created_at));
    if (r.waiver_signature) {
      const when = r.waiver_signed_at ? ' on ' + fmtDateTime(r.waiver_signed_at) : '';
      const ver = r.waiver_version ? ' (waiver version ' + r.waiver_version + ')' : '';
      addRow(d, 'Liability waiver', 'Accepted and signed by ' + r.waiver_signature + when + ver);
    }
    addRow(d, 'PEMU note', r.admin_note);

    $('rSigned').textContent = r.waiver_signature || fullName;
    $('rPrinted').textContent = 'Printed on ' + fmtDateTime(new Date().toISOString());

    document.title = r.receipt_no;   // becomes the default PDF file name
    $('msg').hidden = true;
    $('toolbar').hidden = false;
    $('sheet').hidden = false;
    $('btnPrint').addEventListener('click', () => window.print());

    if (params.get('print') === '1') setTimeout(() => window.print(), 500);
  } catch (err) {
    if (err.status === 401) show('Please log in as a student to view this receipt.', true);
    else show(err.message || 'Could not load the receipt.');
  }
})();
