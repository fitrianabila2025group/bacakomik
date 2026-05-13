<?php /** @var array $users */ ?>
<h1 class="page-title">Users</h1>
<div class="card">
  <table class="data-table">
    <thead><tr><th>ID</th><th>Nama</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td><?= htmlspecialchars($u['name']) ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <form method="post" action="/admin/users/<?= $u['id'] ?>/role" class="inline">
            <?= \App\Csrf::field() ?>
            <select name="role" onchange="this.form.submit()">
              <option value="user"  <?= $u['role'] === 'user'  ? 'selected' : '' ?>>user</option>
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
            </select>
          </form>
        </td>
        <td><?= htmlspecialchars($u['status']) ?></td>
        <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
        <td class="row">
          <form method="post" action="/admin/users/<?= $u['id'] ?>/toggle" class="inline">
            <?= \App\Csrf::field() ?><button class="btn-ghost"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/users/<?= $u['id'] ?>/delete" onsubmit="return confirm('Hapus user?')" class="inline">
            <?= \App\Csrf::field() ?><button class="btn-danger">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
