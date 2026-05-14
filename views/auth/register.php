<?php $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
<section class="container auth-page">
  <div class="auth-card">
    <h1>Daftar</h1>
    <?php if ($flash): ?><div class="flash error"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <form method="post" action="/register">
      <?= \App\Csrf::field() ?>
      <label>Nama <input type="text" name="name" maxlength="60" required></label>
      <label>Email <input type="email" name="email" maxlength="120" required></label>
      <label>Password <input type="password" name="password" minlength="6" maxlength="128" required></label>
      <?php if (\App\Captcha::enabledFor('register')): ?>
        <div class="captcha-wrap" style="margin:.75rem 0"><?= \App\Captcha::widget('register') ?></div>
      <?php endif; ?>
      <button class="btn-primary" type="submit">Daftar</button>
    </form>
    <p>Sudah punya akun? <a href="/login">Masuk</a></p>
  </div>
</section>
