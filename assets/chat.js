'use strict';
// Floating orb assistant for both dashboards (student + PEMU admin). Loaded last; replaces any older chat box.
(() => {
  const here = document.currentScript.src;
  const admin = document.body.dataset.page === 'admin';
  ['#chatFab', '#chat'].forEach(s => $(s)?.remove());

  const css = document.createElement('link');
  css.rel = 'stylesheet'; css.href = new URL('chat.css', here).href;
  document.head.append(css);

  const wrap = document.createElement('div');
  wrap.className = 'orb-wrap';
  wrap.innerHTML =
    '<button class="orb" type="button" aria-label="Open the assistant" aria-expanded="false"><span class="orb-core"></span></button>'
    + '<span class="orb-tip">Ask me anything</span>'
    + '<section class="orb-panel" role="dialog" aria-label="Assistant" hidden>'
    +   '<header class="orb-head"><span class="mini-orb"></span><div class="grow"><strong></strong><small>Answers from live system data</small></div>'
    +   '<button class="orb-x" type="button" aria-label="Close the assistant">&times;</button></header>'
    +   '<div class="orb-log" aria-live="polite"></div><div class="orb-chips"></div>'
    +   '<form class="orb-form"><input maxlength="200" placeholder="Ask a question…" aria-label="Your question" autocomplete="off">'
    +   '<button class="orb-send" aria-label="Send">&#10148;</button></form></section>';
  document.body.append(wrap);

  const orb = $('.orb', wrap), panel = $('.orb-panel', wrap), log = $('.orb-log', wrap), chips = $('.orb-chips', wrap);
  const input = $('input', wrap), send = $('.orb-send', wrap);
  $('strong', wrap).textContent = admin ? 'PEMU Assistant' : 'Equipment Assistant';

  const add = (text, cls) => { const d = document.createElement('div'); d.className = 'msg ' + cls; d.textContent = text; log.append(d); log.scrollTop = log.scrollHeight; return d; };
  const setChips = list => chips.replaceChildren(...(list || []).slice(0, 5).map(t => { const b = document.createElement('button'); b.type = 'button'; b.textContent = t; return b; }));
  const toggle = open => {
    panel.hidden = !open; wrap.classList.toggle('open', open); orb.setAttribute('aria-expanded', open);
    if (open) input.focus(); else orb.focus();
  };

  let busy = false;
  async function ask(q) {
    if (busy) return;
    busy = true; send.disabled = true; orb.classList.add('thinking');
    add(q, 'me'); input.value = ''; setChips([]);
    const dots = add('', 'bot typing'); dots.innerHTML = '<i></i><i></i><i></i>';
    try {
      const d = await api('chat.php', { query: q });
      await new Promise(r => setTimeout(r, 350));                 // brief pause so the typing dots read naturally
      dots.remove(); add(d.reply, 'bot'); setChips(d.suggestions);
    } catch (err) { dots.remove(); add(err.message, 'bot'); }
    busy = false; send.disabled = false; orb.classList.remove('thinking'); input.focus();
  }

  add(admin ? 'Hi! I can look up requests, overdue items, stock, damage records and students, and explain how the admin pages work.'
            : 'Hi! I can check equipment availability, your requests and due dates, and show you how to use the app.', 'bot');
  setChips(admin
    ? ['Give me an overview', 'Any pending requests?', 'Who has not returned items?', 'How do I add equipment?', 'Which items are low on stock?']
    : ['What can I borrow?', 'What should I return today?', 'How do I borrow equipment?', 'How do I use this app?', 'Show my requests']);

  orb.addEventListener('click', () => toggle(panel.hidden));
  $('.orb-x', wrap).addEventListener('click', () => toggle(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden && !document.querySelector('dialog[open]')) toggle(false); });
  chips.addEventListener('click', e => { const b = e.target.closest('button'); if (b) ask(b.textContent); });
  $('.orb-form', wrap).addEventListener('submit', e => { e.preventDefault(); const q = input.value.trim(); if (q) ask(q); });
})();