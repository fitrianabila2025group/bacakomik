<?php /** @var array $stats @var array $latestComics @var array $latestChapters @var array $chart */ ?>
<h1 class="page-title">Overview</h1>
<div class="stats-grid">
  <div class="stat-card"><span class="label">Total Komik</span><span class="value"><?= number_format($stats['comics']) ?></span></div>
  <div class="stat-card"><span class="label">Total Chapter</span><span class="value"><?= number_format($stats['chapters']) ?></span></div>
  <div class="stat-card"><span class="label">Total User</span><span class="value"><?= number_format($stats['users']) ?></span></div>
  <div class="stat-card"><span class="label">Total Views</span><span class="value"><?= number_format($stats['views']) ?></span></div>
</div>

<div class="card">
  <h3>Chapter 7 hari terakhir</h3>
  <canvas id="chartChapters" height="80"></canvas>
</div>
<script>
window.__chartData = <?= json_encode($chart) ?>;
document.addEventListener('DOMContentLoaded', () => {
  if (!window.Chart) return;
  const ctx = document.getElementById('chartChapters');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: window.__chartData.map(r => r.d),
      datasets: [{ label: 'Chapters', data: window.__chartData.map(r => r.c),
        borderColor: '#6366F1', backgroundColor: 'rgba(99,102,241,0.15)', fill: true, tension: 0.35 }]
    },
    options: { plugins: { legend: { display: false } } }
  });
});
</script>

<div class="grid-2">
  <div class="card">
    <h3>Komik Terbaru</h3>
    <ul class="simple-list">
      <?php foreach ($latestComics as $c): ?>
        <li><a href="/admin/library/edit/<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></a><small><?= date('d M', strtotime($c['created_at'])) ?></small></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <h3>Chapter Terbaru</h3>
    <ul class="simple-list">
      <?php foreach ($latestChapters as $c): ?>
        <li><a href="/comic/<?= htmlspecialchars($c['comic_slug']) ?>"><?= htmlspecialchars($c['comic_title']) ?> ch <?= htmlspecialchars($c['chapter_number']) ?></a><small><?= date('d M H:i', strtotime($c['created_at'])) ?></small></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
