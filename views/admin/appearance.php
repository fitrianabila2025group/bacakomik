<?php /** @var string $hero_layout @var string $card_style @var string $grid_style */ ?>
<h1 class="page-title">Appearance</h1>
<form method="post" action="/admin/appearance/save" class="card form">
  <?= \App\Csrf::field() ?>

  <h3>Hero Layout</h3>
  <div class="appearance-grid">
    <?php
    $heroes = [
      'classic'    => ['Classic Hestia', 'Layout slider klasik dengan teks di kiri.'],
      'centered'   => ['Centered Cinematic', 'Hero besar di tengah, teks dramatis.'],
      'slanted'    => ['Slanted Split', 'Pembagi miring dengan dua panel.'],
      'magazine'   => ['Magazine Spotlight', 'Tata letak ala majalah.'],
    ];
    foreach ($heroes as $key => [$name,$desc]): ?>
      <label class="option-card <?= $hero_layout === $key ? 'active' : '' ?>">
        <input type="radio" name="hero_layout" value="<?= $key ?>" <?= $hero_layout === $key ? 'checked' : '' ?>>
        <div class="preview hero-preview hero-<?= $key ?>"></div>
        <strong><?= $name ?></strong>
        <small><?= $desc ?></small>
      </label>
    <?php endforeach; ?>
  </div>

  <h3>Manga Card Style</h3>
  <div class="appearance-grid">
    <?php
    $cards = [
      'modern'   => 'Modern Rounded',
      'classic'  => 'Classic List',
      'spotlight'=> 'Art Spotlight',
    ];
    foreach ($cards as $key => $name): ?>
      <label class="option-card <?= $card_style === $key ? 'active' : '' ?>">
        <input type="radio" name="card_style" value="<?= $key ?>" <?= $card_style === $key ? 'checked' : '' ?>>
        <div class="preview card-preview card-<?= $key ?>"></div>
        <strong><?= $name ?></strong>
      </label>
    <?php endforeach; ?>
  </div>

  <h3>Grid Container</h3>
  <div class="appearance-grid">
    <?php foreach (['default'=>'Default','wide'=>'Wide','boxed'=>'Boxed'] as $k => $n): ?>
      <label class="option-card <?= $grid_style === $k ? 'active' : '' ?>">
        <input type="radio" name="grid_style" value="<?= $k ?>" <?= $grid_style === $k ? 'checked' : '' ?>>
        <div class="preview grid-preview grid-<?= $k ?>"></div>
        <strong><?= $n ?></strong>
      </label>
    <?php endforeach; ?>
  </div>

  <button class="btn-primary" type="submit">Simpan Tampilan</button>
</form>
