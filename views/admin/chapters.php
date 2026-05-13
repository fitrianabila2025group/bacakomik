<?php /** @var array $chapters */ ?>
<div class="page-head">
  <h1 class="page-title">Chapters</h1>
  <a href="/admin/chapters/create" class="btn-primary">+ Tambah Chapter</a>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Komik</th><th>Chapter</th><th>Pages</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($chapters as $ch): ?>
      <tr>
        <td><?= htmlspecialchars($ch['comic_title']) ?></td>
        <td><a href="/admin/chapters/edit/<?= $ch['id'] ?>"><?= htmlspecialchars($ch['title'] ?? 'Chapter ' . $ch['chapter_number']) ?></a></td>
        <td><?= (int)$ch['page_count'] ?></td>
        <td><?= date('d M Y H:i', strtotime($ch['updated_at'])) ?></td>
        <td>
          <form method="post" action="/admin/chapters/delete/<?= $ch['id'] ?>" onsubmit="return confirm('Hapus chapter?')">
            <?= \App\Csrf::field() ?><button class="btn-danger">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
