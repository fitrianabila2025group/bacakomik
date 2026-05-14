<?php $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
<section class="container auth-page">
  <div class="auth-card">
    <h1>Masuk</h1>
    <?php if ($flash): ?><div class="flash error"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <form method="post" action="/login">
      <?= \App\Csrf::field() ?>
      <label>Email <input type="email" name="email" required></label>
      <label>Password <input type="password" name="password" required></label>
      <?php if (\App\Captcha::enabledFor('login')): ?>
        <div class="captcha-wrap" style="margin:.75rem 0"><?= \App\Captcha::widget('login') ?></div>
      <?php endif; ?>
      <button class="btn-primary" type="submit">Masuk</button>
    </form>
    <p>Belum punya akun? <a href="/register">Daftar</a></p>
  </div>
</section>
