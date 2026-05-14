/* BacaKomik comments widget — vanilla JS, talks to Railway FastAPI /comments. */
(function () {
  const root = document.getElementById('bk-comments');
  if (!root) return;
  let cfg;
  try { cfg = JSON.parse(root.dataset.cfg); } catch (e) { return; }
  if (!cfg.api || !cfg.target) return;

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
    const d = new Date(ts * 1000), now = Date.now() / 1000, diff = now - ts;
    if (diff < 60) return 'baru saja';
    if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
    if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
    if (diff < 604800) return Math.floor(diff / 86400) + ' hari lalu';
    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
  };

  const api = (path, opts = {}) => {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
    if (cfg.token) headers['X-User-Token'] = cfg.token;
    return fetch(cfg.api + '/comments' + path, Object.assign({}, opts, { headers }))
      .then(async (r) => {
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.detail || ('HTTP ' + r.status));
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
          ${cfg.token && !c.parent_id ? `<button class="reply-btn" data-id="${c.id}">Balas</button>` : ''}
          ${canDel ? `<button class="del-btn" data-id="${c.id}">Hapus</button>` : ''}
        </div>
        <div class="reply-form-wrap"></div>
        ${replies ? `<ul class="comments-list replies">${replies}</ul>` : ''}
      </li>
    `;
  }

  function renderForm(host, parentId, placeholder) {
    if (!host) return;
    if (!cfg.token) {
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
      f.querySelector('button[type=submit]').disabled = true;
      try {
        await api('', { method: 'POST', body: JSON.stringify({ target: cfg.target, parent_id: parentId, text }) });
        await load(state.page);
      } catch (err) {
        alert('Gagal: ' + err.message);
        f.querySelector('button[type=submit]').disabled = false;
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
      if (!cfg.token) { location.href = cfg.login_url; return; }
      try {
        await api('/' + b.dataset.id + '/react', { method: 'POST', body: JSON.stringify({ type: b.dataset.type }) });
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
        await api('/' + b.dataset.id, { method: 'DELETE' });
        await load(state.page);
      } catch (err) { alert(err.message); }
    }));
  }

  function load(page = 1) {
    state.page = page;
    return api('?target=' + encodeURIComponent(cfg.target) + '&sort=' + state.sort + '&page=' + page)
      .then(j => { state.total = j.total; state.comments = j.comments; render(); })
      .catch(err => { root.innerHTML = '<p class="comments-error">Gagal memuat komentar: ' + esc(err.message) + '</p>'; });
  }

  load(1);
})();
