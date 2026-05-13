<?php /** @var array $comics */ ?>
<div class="page-head">
  <h1 class="page-title">Library</h1>
  <a href="/admin/library/create" class="btn-primary">+ Tambah Komik</a>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Cover</th><th>Judul</th><th>Tipe</th><th>Status</th><th>Views</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($comics as $c): ?>
      <tr>
        <td><div class="thumb" style="background-image:url('<?= htmlspecialchars($c['cover_image'] ?? '') ?>')"></div></td>
        <td><a href="/admin/library/edit/<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></a></td>
        <td><?= htmlspecialchars($c['type']) ?></td>
        <td><?= htmlspecialchars($c['status']) ?></td>
        <td><?= number_format((int)$c['views']) ?></td>
        <td><?= date('d M Y', strtotime($c['updated_at'])) ?></td>
        <td>
          <form method="post" action="/admin/library/delete/<?= $c['id'] ?>" onsubmit="return confirm('Hapus komik ini?')">
            <?= \App\Csrf::field() ?>
            <button class="btn-danger">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
