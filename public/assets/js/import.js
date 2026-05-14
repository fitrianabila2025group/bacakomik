// BacaKomik importer — preview, run, polling progress
(function () {
  const csrf = document.querySelector('input[name="_csrf"]')?.value;

  function post(url, data) {
    const fd = new FormData();
    fd.append('_csrf', csrf);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
  }

  function showResult(html) {
    const el = document.getElementById('preview-result');
    if (el) el.innerHTML = html;
  }

  // Preview
  document.getElementById('btn-preview')?.addEventListener('click', async () => {
    const url = document.querySelector('#form-preview input[name="url"]').value;
    if (!url) return;
    showResult('<em>Memuat preview...</em>');
    const res = await post('/admin/import/preview', { url });
    if (!res.ok) { showResult('<span style="color:#b91c1c">Error: ' + res.error + '</span>'); return; }
    const m = res.meta;
    showResult(`
      <div style="display:flex;gap:1rem;align-items:start">
        ${m.cover_url ? `<img src="${m.cover_url}" style="width:120px;border-radius:8px">` : ''}
        <div>
          <h4 style="margin:.25rem 0">${m.title || '(tanpa judul)'}</h4>
          <p style="margin:.25rem 0;color:var(--muted)">${m.alt_title || ''}</p>
          <p>${m.type} · ${m.status} · ★${m.rating || 0} · ${res.chapter_count} chapter</p>
          <p>${(m.synopsis||'').slice(0,240)}...</p>
          <small>Genres: ${(m.genres||[]).join(', ')}</small>
        </div>
      </div>`);
  });

  // Trigger imports
  function startImport(type, urls) {
    return post('/admin/import/run', { type, urls });
  }

  document.getElementById('btn-import-comic')?.addEventListener('click', async () => {
    const url = document.querySelector('#form-preview input[name="url"]').value;
    if (!url) return;
    const r = await startImport('comic', url);
    if (r.ok) pollJob(r.job_id); else alert(r.error || 'Gagal');
  });
  document.getElementById('btn-import-chapter')?.addEventListener('click', async () => {
    const url = document.querySelector('#form-chapter input[name="url"]').value;
    if (!url) return;
    const r = await startImport('chapter', url);
    if (r.ok) pollJob(r.job_id); else alert(r.error || 'Gagal');
  });
  document.getElementById('btn-import-bulk')?.addEventListener('click', async () => {
    const urls = document.querySelector('#form-bulk textarea[name="urls"]').value;
    if (!urls.trim()) return;
    const r = await startImport('bulk', urls);
    if (r.ok) pollJob(r.job_id); else alert(r.error || 'Gagal');
  });

  document.getElementById('btn-crawl-site')?.addEventListener('click', async () => {
    const form = document.getElementById('form-site');
    const seeds = form.querySelector('textarea[name="seeds"]').value.trim();
    const max_comics = parseInt(form.querySelector('input[name="max_comics"]').value || '0', 10);
    const max_pages  = parseInt(form.querySelector('input[name="max_pages"]').value  || '50', 10);
    if (!confirm(`Mulai auto-crawl seluruh situs?\nMax komik: ${max_comics || 'unlimited'}\nProses berjalan di background.`)) return;
    const payload = JSON.stringify({
      seeds: seeds ? seeds.split(/\r?\n/).map(s => s.trim()).filter(Boolean) : null,
      max_pages, max_comics,
    });
    const r = await startImport('site', payload);
    if (r.ok) { alert('Crawler dimulai (job #' + r.job_id + '). Pantau di tabel.'); pollJob(r.job_id); }
    else alert(r.error || 'Gagal');
  });

  // Polling + web-tick worker (driver untuk job site/bulk yang berjalan
  // selangkah-demi-selangkah lewat HTTP — bekerja di shared hosting tanpa exec/cron).
  function pollJob(id) {
    let inFlightTick = false;
    const interval = setInterval(async () => {
      // Jaga agar tidak menumpuk request tick (importFullComic bisa lama).
      if (!inFlightTick) {
        inFlightTick = true;
        post('/admin/import/tick/' + id, {})
          .catch(() => {})
          .finally(() => { inFlightTick = false; });
      }

      const res = await fetch('/admin/import/status/' + id).then(r => r.json()).catch(() => null);
      if (!res) return;
      const job = res.job; if (!job) return;
      let row = document.querySelector(`#jobs-table tr[data-id="${id}"]`);
      if (!row) {
        const tbody = document.querySelector('#jobs-table tbody');
        row = document.createElement('tr');
        row.dataset.id = id;
        row.innerHTML = `<td>${id}</td><td>${job.type}</td><td><span class="status status-${job.status}">${job.status}</span></td>
          <td><progress max="${Math.max(1,job.total)}" value="${job.progress}"></progress><small>${job.progress}/${job.total}</small></td>
          <td><small>${job.message||''}</small></td><td>${job.updated_at}</td><td></td>`;
        tbody.prepend(row);
      } else {
        row.children[2].innerHTML = `<span class="status status-${job.status}">${job.status}</span>`;
        row.children[3].innerHTML = `<progress max="${Math.max(1,job.total)}" value="${job.progress}"></progress><small>${job.progress}/${job.total}</small>`;
        row.children[4].innerHTML = `<small>${(job.message||'').replace(/</g,'&lt;')}</small>`;
        row.children[5].textContent = job.updated_at;
      }
      if (['done','failed','cancelled'].includes(job.status)) clearInterval(interval);
    }, 2000);
  }

  // Cancel / retry
  document.addEventListener('click', async (e) => {
    const cancelId = e.target.dataset?.cancel;
    if (cancelId) { await post('/admin/import/cancel/' + cancelId, {}); pollJob(cancelId); }
    const retryId = e.target.dataset?.retry;
    if (retryId)  { await post('/admin/import/retry-failed/' + retryId, {}); pollJob(retryId); }
  });

  // Auto-resume: lanjutkan polling+tick untuk job yang masih pending/running
  // saat halaman dibuka (mis. job lama dari sesi sebelumnya).
  document.querySelectorAll('#jobs-table tbody tr').forEach(tr => {
    const id = tr.dataset?.id;
    const status = tr.querySelector('.status')?.textContent?.trim();
    if (id && (status === 'pending' || status === 'running')) {
      pollJob(id);
    }
  });
})();
