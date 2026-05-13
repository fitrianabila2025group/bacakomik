<?php /** @var array $reports */ ?>
<h1 class="page-title">Reports</h1>
<div class="card">
  <table class="data-table">
    <thead><tr><th>ID</th><th>User</th><th>Komik</th><th>Chapter</th><th>Pesan</th><th>Status</th><th>Tanggal</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reports as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= (int)$r['user_id'] ?></td>
        <td><?= (int)$r['comic_id'] ?></td>
        <td><?= (int)$r['chapter_id'] ?></td>
        <td><?= htmlspecialchars((string)$r['message']) ?></td>
        <td><?= htmlspecialchars($r['status']) ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td>
          <form method="post" action="/admin/reports/<?= $r['id'] ?>/status" class="inline">
            <?= \App\Csrf::field() ?>
            <select name="status" onchange="this.form.submit()">
              <option value="pending" <?= $r['status'] === 'pending' ? 'selected' : '' ?>>pending</option>
              <option value="solved"  <?= $r['status'] === 'solved'  ? 'selected' : '' ?>>solved</option>
              <option value="rejected"<?= $r['status'] === 'rejected'? 'selected' : '' ?>>rejected</option>
            </select>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
