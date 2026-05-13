<?php $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
<section class="container auth-page">
  <div class="auth-card">
    <h1>Daftar</h1>
    <?php if ($flash): ?><div class="flash error"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <form method="post" action="/register">
      <?= \App\Csrf::field() ?>
      <label>Nama <input type="text" name="name" required></label>
      <label>Email <input type="email" name="email" required></label>
      <label>Password <input type="password" name="password" minlength="6" required></label>
      <button class="btn-primary" type="submit">Daftar</button>
    </form>
    <p>Sudah punya akun? <a href="/login">Masuk</a></p>
  </div>
</section>
