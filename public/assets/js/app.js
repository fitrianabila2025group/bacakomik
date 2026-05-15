// BacaKomik frontend JS
function toggleTheme() {
  const root = document.documentElement;
  const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  localStorage.setItem('theme', next);
}

// Shared HTML escaper (XSS-safe interpolation into innerHTML)
function escHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[c]));
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// Tabs
document.addEventListener('click', (e) => {
  const tab = e.target.closest('[data-tabs] .tab');
  if (!tab) return;
  const wrap = tab.closest('[data-tabs]').parentElement;
  wrap.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  wrap.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  tab.classList.add('active');
  const panel = wrap.querySelector(`[data-panel="${tab.dataset.tab}"]`);
  if (panel) panel.classList.add('active');
});

// Bookmark
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.bookmark-btn');
  if (!btn) return;
  const id = btn.dataset.comic;
  const fd = new FormData();
  fd.append('_csrf', csrfToken());
  try {
    const res = await fetch(`/bookmark/${id}`, {
      method: 'POST',
      body: fd,
      headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    if (res.status === 401 || res.redirected) { location.href = '/login'; return; }
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch (_) {}
    if (!res.ok) throw new Error(data.detail || data.error || ('HTTP ' + res.status));
    btn.dataset.active = data.bookmarked ? '1' : '0';
    btn.textContent = data.bookmarked ? 'Tersimpan' : 'Bookmark';
  } catch (err) { console.error(err); alert('Gagal bookmark: ' + err.message); }
});

// Realtime search dropdown (simple)
const searchInput = document.querySelector('.search-form input[name="q"]');
if (searchInput) {
  let dropdown;
  searchInput.addEventListener('input', async () => {
    const q = searchInput.value.trim();
    if (q.length < 2) { dropdown && dropdown.remove(); return; }
    const res = await fetch('/api/search?q=' + encodeURIComponent(q));
    const data = await res.json();
    if (!dropdown) {
      dropdown = document.createElement('div');
      dropdown.className = 'search-dropdown';
      dropdown.style.cssText = 'position:absolute;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:.5rem;box-shadow:var(--shadow);z-index:60;min-width:280px;margin-top:.5rem;';
      searchInput.closest('.search-form').appendChild(dropdown);
    }
    dropdown.innerHTML = (data.results || []).map(r => {
      const slug  = encodeURIComponent(r.slug || '');
      const title = escHtml(r.title);
      const cover = escHtml(r.cover_image || '');
      const type  = escHtml(r.type || '');
      const stat  = escHtml(r.status || '');
      return `<a href="/comic/${slug}" style="display:flex;gap:.5rem;padding:.4rem;border-radius:6px;align-items:center;">
         <img src="${cover}" style="width:32px;height:44px;object-fit:cover;border-radius:4px;background:#eee" alt="">
         <span><strong>${title}</strong><br><small style="color:var(--muted)">${type} · ${stat}</small></span>
       </a>`;
    }).join('') || '<small style="color:var(--muted)">Tidak ada hasil</small>';
  });
  document.addEventListener('click', (e) => {
    if (dropdown && !e.target.closest('.search-form')) { dropdown.remove(); dropdown = null; }
  });
}

// Chapter search filter
const chSearch = document.querySelector('.chapter-search');
if (chSearch) {
  chSearch.addEventListener('input', () => {
    const q = chSearch.value.toLowerCase();
    document.querySelectorAll('.chapter-list li').forEach(li => {
      li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}
