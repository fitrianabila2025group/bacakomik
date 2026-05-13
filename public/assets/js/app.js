// BacaKomik frontend JS
function toggleTheme() {
  const root = document.documentElement;
  const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  localStorage.setItem('theme', next);
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
  fd.append('_csrf', document.cookie); // best-effort; server will validate token if posted via real form
  try {
    const res = await fetch(`/bookmark/${id}`, { method: 'POST', body: fd });
    if (res.status === 401 || res.redirected) { location.href = '/login'; return; }
    const data = await res.json();
    btn.dataset.active = data.bookmarked ? '1' : '0';
    btn.textContent = data.bookmarked ? 'Tersimpan' : 'Bookmark';
  } catch (err) { console.error(err); }
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
    dropdown.innerHTML = data.results.map(r =>
      `<a href="/comic/${r.slug}" style="display:flex;gap:.5rem;padding:.4rem;border-radius:6px;align-items:center;">
         <img src="${r.cover_image||''}" style="width:32px;height:44px;object-fit:cover;border-radius:4px;background:#eee">
         <span><strong>${r.title}</strong><br><small style="color:var(--muted)">${r.type} · ${r.status}</small></span>
       </a>`
    ).join('') || '<small style="color:var(--muted)">Tidak ada hasil</small>';
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
