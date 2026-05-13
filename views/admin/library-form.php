<?php /** @var ?array $comic @var array $genres @var array $selectedGenres */
$c = $comic ?? [];
$action = $comic ? '/admin/library/update/' . $comic['id'] : '/admin/library/store';
?>
<h1 class="page-title"><?= $comic ? 'Edit Komik' : 'Tambah Komik' ?></h1>
<form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="card form">
  <?= \App\Csrf::field() ?>
  <div class="grid-2">
    <label>Judul <input type="text" name="title" required value="<?= htmlspecialchars($c['title'] ?? '') ?>"></label>
    <label>Slug <input type="text" name="slug" value="<?= htmlspecialchars($c['slug'] ?? '') ?>" placeholder="otomatis"></label>
    <label>Alt Title <input type="text" name="alt_title" value="<?= htmlspecialchars($c['alt_title'] ?? '') ?>"></label>
    <label>Author <input type="text" name="author" value="<?= htmlspecialchars($c['author'] ?? '') ?>"></label>
    <label>Artist <input type="text" name="artist" value="<?= htmlspecialchars($c['artist'] ?? '') ?>"></label>
    <label>Tipe
      <select name="type">
        <?php foreach (['Manga','Manhwa','Manhua','Other'] as $t): ?>
          <option <?= ($c['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <?php foreach (['Ongoing','Completed','Hiatus'] as $s): ?>
          <option <?= ($c['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Rating <input type="number" step="0.1" name="rating" value="<?= htmlspecialchars((string)($c['rating'] ?? 0)) ?>"></label>
  </div>
  <label>Sinopsis <textarea name="synopsis" rows="6"><?= htmlspecialchars($c['synopsis'] ?? '') ?></textarea></label>
  <label>Cover (jpg/png/webp, max 2MB) <input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp"></label>
  <?php if (!empty($c['cover_image'])): ?><img src="<?= htmlspecialchars($c['cover_image']) ?>" style="max-width:120px;border-radius:8px"><?php endif; ?>

  <fieldset class="genre-select">
    <legend>Genre</legend>
    <?php foreach ($genres as $g): ?>
      <label class="check">
        <input type="checkbox" name="genres[]" value="<?= $g['id'] ?>" <?= in_array((int)$g['id'], $selectedGenres ?? [], true) ? 'checked' : '' ?>>
        <?= htmlspecialchars($g['name']) ?>
      </label>
    <?php endforeach; ?>
  </fieldset>

  <div class="row">
    <label class="check"><input type="checkbox" name="is_featured" <?= !empty($c['is_featured']) ? 'checked' : '' ?>> Featured</label>
    <label class="check"><input type="checkbox" name="is_popular" <?= !empty($c['is_popular']) ? 'checked' : '' ?>> Popular</label>
  </div>
  <button class="btn-primary" type="submit">Simpan</button>
</form>
