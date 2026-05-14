<?php
/**
 * BacaKomik full-site crawler CLI.
 *
 * Usage:
 *   php bin/crawl.php                   # default: crawl all seeds, import everything
 *   php bin/crawl.php <jobId>           # process an import_jobs row of type=site
 *   php bin/crawl.php --max=50          # cap to first 50 comics
 *   php bin/crawl.php --seed=URL        # use a custom seed listing URL
 *   php bin/crawl.php --pages=200       # cap listing pages per seed
 *
 * Env vars are inherited (DB_HOST, DB_USER, DB_PASS, DB_NAME).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', BASE_PATH . '/views');
define('STORAGE_PATH', BASE_PATH . '/storage');

require BASE_PATH . '/app/helpers.php';
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') !== 0) return;
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

@set_time_limit(0);
ini_set('memory_limit', '512M');

use App\Database;
use App\Services\Scraper\KomikuScraper;
use App\Services\Scraper\ScraperFactory;

Database::init(require BASE_PATH . '/config/database.php');

// ---- Parse CLI args ----
$jobId = 0; $maxComics = 0; $maxPages = 500; $seeds = null;
foreach (array_slice($argv, 1) as $arg) {
    if (ctype_digit($arg)) { $jobId = (int)$arg; continue; }
    if (preg_match('/^--max=(\d+)$/', $arg, $m))   { $maxComics = (int)$m[1]; continue; }
    if (preg_match('/^--pages=(\d+)$/', $arg, $m)) { $maxPages = (int)$m[1]; continue; }
    if (preg_match('/^--seed=(.+)$/', $arg, $m))   { $seeds[] = $m[1]; continue; }
}

// ---- Resolve job (create one if --seed/--max passed without jobId) ----
if ($jobId > 0) {
    $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$jobId]);
    if (!$job) { fwrite(STDERR, "Job #$jobId tidak ditemukan\n"); exit(1); }
    $raw = trim((string)$job['target_url']);
    if ($raw !== '' && $raw[0] === '{') {
        $cfg = json_decode($raw, true) ?: [];
        $seeds    = !empty($cfg['seeds']) ? (array)$cfg['seeds'] : $seeds;
        $maxPages = (int)($cfg['max_pages']  ?? $maxPages);
        $maxComics= (int)($cfg['max_comics'] ?? $maxComics);
    } elseif ($raw !== '') {
        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
        if ($lines) $seeds = $lines;
    }
} else {
    $jobId = Database::insert('import_jobs', [
        'type'       => 'site',
        'target_url' => json_encode([
            'seeds' => $seeds, 'max_pages' => $maxPages, 'max_comics' => $maxComics,
        ]),
        'status'     => 'pending',
        'message'    => 'CLI launched',
    ]);
    echo "Created job #$jobId\n";
}

Database::update('import_jobs', ['status' => 'running', 'message' => 'Discovery...'], 'id = :id', ['id' => $jobId]);

$scraper = ScraperFactory::make();

$progressCb = function ($done, $total, $msg) use ($jobId) {
    Database::update('import_jobs', [
        'progress' => $done, 'total' => $total, 'message' => $msg,
    ], 'id = :id', ['id' => $jobId]);
    fwrite(STDOUT, "[$done/$total] $msg\n");
};

try {
    echo "Starting crawl (max_pages=$maxPages, max_comics=" . ($maxComics ?: 'all') . ")\n";
    if ($seeds) echo "Seeds:\n  - " . implode("\n  - ", $seeds) . "\n";

    $progressCb(0, 0, 'Discovery: sitemap...');
    $urls = [];
    if (!$seeds) {
        // Tanpa custom seed → coba sitemap dulu (jauh lebih cepat & lengkap).
        try {
            $urls = $scraper->crawlSitemap();
            echo "Sitemap discovery: " . count($urls) . " comics\n";
        } catch (\Throwable $e) {
            echo "Sitemap gagal: " . $e->getMessage() . "\n";
        }
    }
    if (count($urls) < 5) {
        $progressCb(0, 0, 'Discovery: listing pages...');
        $extra = $scraper->crawlListing($seeds, $maxPages, $maxComics);
        $urls = array_values(array_unique(array_merge($urls, $extra)));
    }
    if ($maxComics > 0 && count($urls) > $maxComics) {
        $urls = array_slice($urls, 0, $maxComics);
    }
    $total = count($urls);
    echo "Discovered $total comics\n";
    $progressCb(0, $total, "Mulai import $total komik");

    $imported = 0; $errors = [];
    foreach ($urls as $i => $u) {
        try {
            $scraper->importFullComic($u);
            $imported++;
        } catch (\Throwable $e) {
            $errors[] = $u . ' :: ' . $e->getMessage();
        }
        $progressCb($i + 1, $total, ($i + 1) . "/$total : $u");
    }

    Database::update('import_jobs', [
        'status'  => 'done',
        'message' => "Selesai: $imported/$total komik (errors: " . count($errors) . ")",
    ], 'id = :id', ['id' => $jobId]);
    echo "Done. Imported $imported / $total. Errors: " . count($errors) . "\n";
} catch (\Throwable $e) {
    Database::update('import_jobs', [
        'status' => 'failed', 'message' => $e->getMessage(),
    ], 'id = :id', ['id' => $jobId]);
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(2);
}
