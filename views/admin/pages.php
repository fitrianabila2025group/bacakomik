<?php /** @var array $pages */ ?>
<h1 class="page-title">Pages</h1>
<div class="grid-2">
  <div class="card">
    <h3>Daftar Halaman</h3>
    <ul class="simple-list">
      <?php foreach ($pages as $p): ?>
        <li>
          <a href="#" onclick="loadPage(<?= htmlspecialchars(json_encode($p)) ?>);return false;"><?= htmlspecialchars($p['title']) ?></a>
          <small><?= htmlspecialchars($p['status']) ?></small>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <form method="post" action="/admin/pages/save" class="card form" id="page-form">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="id" id="page-id">
    <label>Title <input type="text" name="title" id="page-title" required></label>
    <label>Slug <input type="text" name="slug" id="page-slug"></label>
    <label>Status
      <select name="status" id="page-status"><option value="published">Published</option><option value="draft">Draft</option></select>
    </label>
    <label>Content (HTML) <textarea name="content" id="page-content" rows="12"></textarea></label>
    <button class="btn-primary">Simpan</button>
  </form>
</div>
<script>
function loadPage(p) {
  document.getElementById('page-id').value = p.id;
  document.getElementById('page-title').value = p.title;
  document.getElementById('page-slug').value = p.slug;
  document.getElementById('page-status').value = p.status;
  document.getElementById('page-content').value = p.content || '';
}
</script>
