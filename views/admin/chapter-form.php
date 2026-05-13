<?php /** @var ?array $chapter @var array $comics @var array $images */
$ch = $chapter ?? [];
$action = $chapter ? '/admin/chapters/update/' . $chapter['id'] : '/admin/chapters/store';
?>
<h1 class="page-title"><?= $chapter ? 'Edit Chapter' : 'Tambah Chapter' ?></h1>
<form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="card form">
  <?= \App\Csrf::field() ?>
  <div class="grid-2">
    <label>Komik
      <select name="comic_id" <?= $chapter ? 'disabled' : 'required' ?>>
        <option value="">— pilih —</option>
        <?php foreach ($comics as $co): ?>
          <option value="<?= $co['id'] ?>" <?= ($ch['comic_id'] ?? null) == $co['id'] ? 'selected' : '' ?>><?= htmlspecialchars($co['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Nomor Chapter <input type="text" name="chapter_number" required value="<?= htmlspecialchars($ch['chapter_number'] ?? '') ?>"></label>
    <label>Judul <input type="text" name="title" value="<?= htmlspecialchars($ch['title'] ?? '') ?>"></label>
  </div>
  <label>Upload Gambar (multi, drag & drop)
    <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp">
  </label>
  <label>Atau Upload ZIP (auto extract)
    <input type="file" name="zip" accept=".zip">
  </label>
  <button class="btn-primary" type="submit">Simpan</button>
</form>

<?php if ($chapter && !empty($images)): ?>
  <div class="card">
    <h3>Halaman tersimpan (<?= count($images) ?>)</h3>
    <div class="thumb-grid">
      <?php foreach ($images as $im): ?>
        <img src="<?= htmlspecialchars($im['image_path']) ?>" alt="">
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
