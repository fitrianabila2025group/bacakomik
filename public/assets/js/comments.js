/* BacaKomik comments widget — talks to PHP same-origin /api/comments/* which
   forwards to Railway with X-API-Key. No cross-domain tokens, no CORS. */
(function () {
  const root = document.getElementById('bk-comments');
  if (!root) return;
  let cfg;
  try { cfg = JSON.parse(root.dataset.cfg); } catch (e) { return; }
  if (!cfg.target) return;

  const REACTIONS = [
    { k: 'like',    e: '👍' },
    { k: 'love',    e: '❤️' },
    { k: 'happy',   e: '😄' },
    { k: 'sad',     e: '😢' },
    { k: 'dislike', e: '👎' },
  ];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
    { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]
  ));
  const fmtDate = (ts) => {
    const now = Date.now() / 1000, diff = now - ts;
    if (diff < 60) return 'baru saja';
    if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
    if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
    if (diff < 604800) return Math.floor(diff / 86400) + ' hari lalu';
    return new Date(ts * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
  };

  const api = (method, path, body) => {
    const opts = { method, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-TOKEN'] = cfg.csrf || '';
      opts.body = JSON.stringify(body);
    }
    return fetch('/api/comments' + path, opts).then(async (r) => {
      const text = await r.text();
      let j = {};
      try {
        j = text ? JSON.parse(text) : {};
      } catch (e) {
        throw new Error('Invalid API response: ' + text.slice(0, 160));
      }
      if (!r.ok) throw new Error(j.detail || j.error || ('HTTP ' + r.status));
      return j;
    });
  };

  let state = { sort: 'top', page: 1, total: 0, comments: [] };

  function render() {
    root.innerHTML = `
      <h2 class="comments-title">Komentar <span class="comments-count">${state.total || 0}</span></h2>
      <div class="comments-bar">
        <div class="comments-sort">
          ${['top','new','old'].map(s => `<button data-sort="${s}" class="${state.sort===s?'active':''}">${s==='top'?'Top':s==='new'?'Terbaru':'Terlama'}</button>`).join('')}
        </div>
      </div>
      <div class="comments-form-wrap"></div>
      <ul class="comments-list">${state.comments.map(renderItem).join('')}</ul>
      <div class="comments-pager"></div>
    `;
    renderForm(root.querySelector('.comments-form-wrap'), null, 'Tulis komentar...');
    bindActions();
  }

  function renderItem(c) {
    const replies = (c.replies || []).map(renderItem).join('');
    const reactBtns = REACTIONS.map(r => {
      const n = c.reactions?.[r.k] || 0;
      const mine = (c.my_reactions || []).includes(r.k);
      return `<button class="react-btn ${mine?'on':''}" data-id="${c.id}" data-type="${r.k}">${r.e} <span>${n||''}</span></button>`;
    }).join('');
    const canDel = cfg.me && (cfg.me.id === c.user_id || cfg.me.role === 'admin');
    return `
      <li class="comment ${c.parent_id ? 'is-reply' : ''} ${c.is_pinned ? 'pinned' : ''}" data-id="${c.id}">
        <div class="comment-head">
          <span class="comment-avatar">${esc((c.user_name || '?')[0].toUpperCase())}</span>
          <strong>${esc(c.user_name)}</strong>
          <span class="comment-date">${fmtDate(c.created_at)}</span>
          ${c.is_pinned ? '<span class="pin-badge">📌 Pinned</span>' : ''}
        </div>
        <div class="comment-body">${esc(c.text).replace(/\n/g, '<br>')}</div>
        <div class="comment-actions">
          ${reactBtns}
          ${cfg.me && !c.parent_id ? `<button class="reply-btn" data-id="${c.id}">Balas</button>` : ''}
          ${canDel ? `<button class="del-btn" data-id="${c.id}">Hapus</button>` : ''}
        </div>
        <div class="reply-form-wrap"></div>
        ${replies ? `<ul class="comments-list replies">${replies}</ul>` : ''}
      </li>
    `;
  }

  function renderForm(host, parentId, placeholder) {
    if (!host) return;
    if (!cfg.me) {
      host.innerHTML = `<p class="comments-login"><a href="${cfg.login_url}">Masuk</a> untuk berkomentar.</p>`;
      return;
    }
    host.innerHTML = `
      <form class="comment-form">
        <textarea name="text" rows="3" maxlength="4000" placeholder="${esc(placeholder)}" required></textarea>
        <div class="row"><button type="submit" class="btn-primary">Kirim</button>
          ${parentId ? '<button type="button" class="btn-ghost cancel-reply">Batal</button>' : ''}
        </div>
      </form>
    `;
    const f = host.querySelector('form');
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = f.text.value.trim();
      if (!text) return;
      const btn = f.querySelector('button[type=submit]');
      btn.disabled = true;
      try {
        await api('POST', '', { target: cfg.target, parent_id: parentId, text });
        await load(state.page);
      } catch (err) {
        alert('Gagal: ' + err.message);
        btn.disabled = false;
      }
    });
    const cancel = f.querySelector('.cancel-reply');
    if (cancel) cancel.addEventListener('click', () => { host.innerHTML = ''; });
  }

  function bindActions() {
    root.querySelectorAll('.comments-sort button').forEach(b => b.addEventListener('click', () => {
      state.sort = b.dataset.sort; load(1);
    }));
    root.querySelectorAll('.react-btn').forEach(b => b.addEventListener('click', async () => {
      if (!cfg.me) { location.href = cfg.login_url; return; }
      try {
        await api('POST', '/' + b.dataset.id + '/react', { type: b.dataset.type });
        await load(state.page);
      } catch (err) { alert(err.message); }
    }));
    root.querySelectorAll('.reply-btn').forEach(b => b.addEventListener('click', () => {
      const li = b.closest('.comment');
      const host = li.querySelector(':scope > .reply-form-wrap');
      if (host.innerHTML) { host.innerHTML = ''; return; }
      renderForm(host, parseInt(b.dataset.id, 10), 'Balas...');
      host.querySelector('textarea')?.focus();
    }));
    root.querySelectorAll('.del-btn').forEach(b => b.addEventListener('click', async () => {
      if (!confirm('Hapus komentar ini?')) return;
      try {
        await api('POST', '/' + b.dataset.id + '/delete', {});
        await load(state.page);
      } catch (err) { alert(err.message); }
    }));
  }

  function load(page = 1) {
    state.page = page;
    return api('GET', '?target=' + encodeURIComponent(cfg.target) + '&sort=' + state.sort + '&page=' + page)
      .then(j => { state.total = j.total; state.comments = j.comments || []; render(); renderPager(); })
      .catch(err => { root.innerHTML = '<p class="comments-error">Gagal memuat komentar: ' + esc(err.message) + '</p>'; });
  }

  function renderPager() {
    const pager = root.querySelector('.comments-pager');
    if (!pager) return;
    const hasPrev = state.page > 1;
    const hasNext = state.comments.length >= 15;
    pager.innerHTML = `
      ${hasPrev ? '<button class="btn-ghost comments-prev">← Sebelumnya</button>' : ''}
      ${hasNext ? '<button class="btn-ghost comments-next">Berikutnya →</button>' : ''}
    `;
    pager.querySelector('.comments-prev')?.addEventListener('click', () => load(state.page - 1));
    pager.querySelector('.comments-next')?.addEventListener('click', () => load(state.page + 1));
  }

  load(1);
})();
