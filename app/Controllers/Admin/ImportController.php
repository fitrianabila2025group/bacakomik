<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;
use App\Services\Scraper\KomikuScraper;
use App\Services\Scraper\ScraperFactory;

class ImportController extends AdminController
{
    public function index(): string
    {
        $jobs = Database::fetchAll('SELECT * FROM import_jobs ORDER BY id DESC LIMIT 20');
        $logs = Database::fetchAll('SELECT * FROM import_logs ORDER BY id DESC LIMIT 50');
        return $this->view('admin/import', [
            'title'    => 'Import',
            'jobs'     => $jobs,
            'logs'     => $logs,
            'apiMode'  => \App\Services\Scraper\ApiClient::isEnabled(),
            'apiUrl'   => \App\Models\Setting::get('scraper_api_url', ''),
        ]);
    }

    public function preview(): string
    {
        Csrf::check();
        $url = trim((string)($_POST['url'] ?? ''));
        try {
            $scraper = ScraperFactory::make();
            $meta = $scraper->fetchComicMetadata($url);
            $chapters = $scraper->fetchChapterList($url);
            return $this->json([
                'ok' => true,
                'meta' => $meta,
                'chapter_count' => count($chapters),
                'first_chapters' => array_slice($chapters, 0, 5),
                'last_chapters'  => array_slice($chapters, -5),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function run(): string
    {
        Csrf::check();
        $type = $_POST['type'] ?? 'comic';
        $urls = trim((string)($_POST['urls'] ?? $_POST['url'] ?? ''));
        if ($urls === '') return $this->json(['ok' => false, 'error' => 'URL wajib diisi'], 400);

        $jobId = Database::insert('import_jobs', [
            'type'       => in_array($type, ['comic','chapter','bulk','site'], true) ? $type : 'comic',
            'target_url' => $urls,
            'status'     => 'pending',
        ]);

        // Site & bulk = potentially long -> driven by web-tick worker (frontend polls /tick/{id}).
        // Best-effort: also try to spawn a detached CLI worker in case the host allows it.
        if ($type === 'site' || $type === 'bulk') {
            if ($type === 'site') {
                $this->spawnSiteCrawler($jobId); // best-effort, harmless if exec disabled
            }
            return $this->json(['ok' => true, 'job_id' => $jobId, 'background' => true]);
        }

        // For comic/chapter we still process synchronously (usually < 2 min).
        ignore_user_abort(true);
        @set_time_limit(0);

        $this->processJob($jobId);
        return $this->json(['ok' => true, 'job_id' => $jobId]);
    }

    public function status(int $id): string
    {
        $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        return $this->json(['job' => $job]);
    }

    /**
     * Web-tick worker. Dipanggil berulang dari frontend (atau cron URL) untuk
     * memajukan job tipe `site` / `bulk` selangkah demi selangkah. Tidak
     * butuh exec()/CLI — aman di shared hosting (cPanel + LiteSpeed).
     *
     * Tick pertama untuk job pending = discovery URL list.
     * Tick berikutnya = import 1 komik dari list, naikkan progress.
     * Selesai saat progress >= total.
     */
    public function tick(int $id): string
    {
        // Note: sengaja TIDAK require CSRF supaya bisa dipicu via:
        //  - frontend AJAX auto-resume
        //  - URL manual (debug) di browser admin: GET /admin/import/tick/{id}
        //  - cron URL eksternal (cPanel cron / UptimeRobot)
        // Auth admin masih dipaksa lewat AdminController constructor.
        $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        if (!$job) return $this->json(['ok' => false, 'error' => 'Job tidak ada'], 404);
        if (in_array($job['status'], ['done','failed','cancelled'], true)) {
            return $this->json(['ok' => true, 'status' => $job['status'], 'job' => $job]);
        }
        if (!in_array($job['type'], ['site','bulk'], true)) {
            return $this->json(['ok' => true, 'status' => $job['status'], 'job' => $job, 'note' => 'tick tidak diperlukan untuk tipe ini']);
        }

        // Lock supaya tick paralel (atau CLI worker) tidak overlap.
        $lockFile = STORAGE_PATH . '/cache/job-' . $id . '.lock';
        @mkdir(dirname($lockFile), 0775, true);
        $fp = @fopen($lockFile, 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$id]);
            return $this->json(['ok' => true, 'busy' => true, 'job' => $job]);
        }

        @set_time_limit(180);
        ignore_user_abort(true);

        try {
            if ($job['type'] === 'site') {
                $this->tickSite($job);
            } else { // bulk
                $this->tickBulk($job);
            }
        } catch (\Throwable $e) {
            Database::update('import_jobs', [
                'status' => 'failed', 'message' => 'tick: ' . $e->getMessage(),
            ], 'id = :id', ['id' => $id]);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        return $this->json(['ok' => true, 'job' => $job]);
    }

    private function tickSite(array $job): void
    {
        $id = (int)$job['id'];
        $urlsFile = STORAGE_PATH . '/cache/job-' . $id . '.urls.json';

        // Phase 1: discovery (sekali saja per job).
        if ($job['status'] === 'pending' || !is_file($urlsFile)) {
            Database::update('import_jobs', ['status' => 'running', 'message' => 'Discovery...'], 'id = :id', ['id' => $id]);

            $raw = trim((string)$job['target_url']);
            $seeds = null; $maxPages = 500; $maxComics = 0;
            if ($raw !== '' && $raw[0] === '{') {
                $cfg = json_decode($raw, true) ?: [];
                $seeds    = !empty($cfg['seeds']) ? (array)$cfg['seeds'] : null;
                $maxPages = (int)($cfg['max_pages']  ?? 500);
                $maxComics= (int)($cfg['max_comics'] ?? 0);
            } elseif ($raw !== '') {
                $seeds = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
            }

            $scraper = ScraperFactory::make();
            $urls = [];
            if (!$seeds) {
                try { $urls = $scraper->crawlSitemap(); } catch (\Throwable $_) {}
            }
            if (count($urls) < 5) {
                try {
                    $extra = $scraper->crawlListing($seeds, $maxPages, $maxComics);
                    $urls = array_values(array_unique(array_merge($urls, $extra)));
                } catch (\Throwable $e) {
                    if (!$urls) throw $e;
                }
            }
            if ($maxComics > 0 && count($urls) > $maxComics) {
                $urls = array_slice($urls, 0, $maxComics);
            }
            file_put_contents($urlsFile, json_encode($urls));
            Database::update('import_jobs', [
                'status'   => 'running',
                'progress' => 0,
                'total'    => count($urls),
                'message'  => 'Discovery selesai. Mulai import ' . count($urls) . ' komik.',
            ], 'id = :id', ['id' => $id]);
            return;
        }

        // Phase 2: import komik. Pre-increment progress dulu sebelum work, supaya
        // kalau tick di-kill mid-import (LiteSpeed timeout) tick berikutnya pindah
        // ke komik selanjutnya — bukan terjebak ulang di komik yang sama.
        $urls   = json_decode((string)file_get_contents($urlsFile), true) ?: [];
        $offset = (int)$job['progress'];
        $total  = count($urls);
        if ($offset >= $total || $total === 0) {
            Database::update('import_jobs', ['status' => 'done', 'message' => "Selesai $total komik"], 'id = :id', ['id' => $id]);
            @unlink($urlsFile);
            return;
        }

        $u = $urls[$offset];
        // Pre-update: progress maju + message live sebelum import jalan.
        Database::update('import_jobs', [
            'progress' => $offset + 1,
            'total'    => $total,
            'message'  => 'Importing ' . ($offset + 1) . "/$total : $u",
        ], 'id = :id', ['id' => $id]);

        $scraper = ScraperFactory::make();
        // Per-chapter progress di field message supaya user lihat aktivitas.
        $perChapterCb = function ($done, $total2, $msg) use ($id, $offset, $total, $u) {
            Database::update('import_jobs', [
                'message' => 'Importing ' . ($offset + 1) . "/$total : $u — chapter $done/$total2",
            ], 'id = :id', ['id' => $id]);
        };
        try {
            $scraper->importFullComic($u, $perChapterCb);
            $finalMsg = ($offset + 1) . "/$total : $u — OK";
        } catch (\Throwable $e) {
            $finalMsg = ($offset + 1) . "/$total : $u — err: " . substr($e->getMessage(), 0, 120);
        }
        $offset++;

        $cur = Database::fetch('SELECT status FROM import_jobs WHERE id = ?', [$id]);
        if ($cur && $cur['status'] === 'cancelled') return;

        $newStatus = $offset >= $total ? 'done' : 'running';
        if ($newStatus === 'done') $finalMsg = "Selesai $total komik";
        Database::update('import_jobs', [
            'status'   => $newStatus,
            'progress' => $offset,
            'total'    => $total,
            'message'  => $finalMsg,
        ], 'id = :id', ['id' => $id]);
        if ($newStatus === 'done') @unlink($urlsFile);
    }

    private function tickBulk(array $job): void
    {
        $id = (int)$job['id'];
        $urlsFile = STORAGE_PATH . '/cache/job-' . $id . '.urls.json';

        if ($job['status'] === 'pending' || !is_file($urlsFile)) {
            $urls = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$job['target_url']))));
            file_put_contents($urlsFile, json_encode($urls));
            Database::update('import_jobs', [
                'status' => 'running', 'progress' => 0, 'total' => count($urls),
                'message' => 'Mulai bulk ' . count($urls) . ' komik.',
            ], 'id = :id', ['id' => $id]);
            return;
        }

        $urls   = json_decode((string)file_get_contents($urlsFile), true) ?: [];
        $offset = (int)$job['progress'];
        $total  = count($urls);
        if ($offset >= $total || $total === 0) {
            Database::update('import_jobs', ['status' => 'done', 'message' => "Selesai $total komik"], 'id = :id', ['id' => $id]);
            @unlink($urlsFile);
            return;
        }

        $u = $urls[$offset];
        // Pre-increment supaya tahan timeout (sama dengan tickSite).
        Database::update('import_jobs', [
            'progress' => $offset + 1, 'total' => $total,
            'message'  => 'Bulk ' . ($offset + 1) . "/$total : $u",
        ], 'id = :id', ['id' => $id]);

        $scraper = ScraperFactory::make();
        try {
            $scraper->importFullComic($u);
            $finalMsg = ($offset + 1) . "/$total : $u — OK";
        } catch (\Throwable $e) {
            $finalMsg = ($offset + 1) . "/$total : $u — err: " . substr($e->getMessage(), 0, 120);
        }
        $offset++;

        $cur = Database::fetch('SELECT status FROM import_jobs WHERE id = ?', [$id]);
        if ($cur && $cur['status'] === 'cancelled') return;

        $newStatus = $offset >= $total ? 'done' : 'running';
        if ($newStatus === 'done') $finalMsg = "Selesai $total komik";
        Database::update('import_jobs', [
            'status'   => $newStatus,
            'progress' => $offset,
            'total'    => $total,
            'message'  => $finalMsg,
        ], 'id = :id', ['id' => $id]);
        if ($newStatus === 'done') @unlink($urlsFile);
    }

    public function cancel(int $id): string
    {
        Csrf::check();
        Database::update('import_jobs', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
        return $this->json(['ok' => true]);
    }

    public function retryFailed(int $id): string
    {
        Csrf::check();
        // Simply re-run the same job (the scraper handles dedup/overwrite of images).
        $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        if (!$job) return $this->json(['ok' => false], 404);
        Database::update('import_jobs', ['status' => 'pending', 'progress' => 0, 'message' => 'Retry'], 'id = :id', ['id' => $id]);
        $this->processJob((int)$job['id']);
        return $this->json(['ok' => true]);
    }

    private function processJob(int $jobId): void
    {
        $job = Database::fetch('SELECT * FROM import_jobs WHERE id = ?', [$jobId]);
        if (!$job || $job['status'] === 'cancelled') return;

        Database::update('import_jobs', ['status' => 'running', 'message' => 'Mulai...'], 'id = :id', ['id' => $jobId]);

        $scraper = ScraperFactory::make();
        $progressCb = function ($done, $total, $msg) use ($jobId) {
            Database::update('import_jobs', [
                'progress' => $done, 'total' => $total, 'message' => $msg,
            ], 'id = :id', ['id' => $jobId]);
        };

        try {
            if ($job['type'] === 'comic') {
                $scraper->importFullComic($job['target_url'], $progressCb);
            } elseif ($job['type'] === 'chapter') {
                $scraper->importSingleChapter($job['target_url']);
            } elseif ($job['type'] === 'site') {
                // target_url stores optional JSON config or seed URLs (one per line).
                $raw = trim((string)$job['target_url']);
                $seeds = null; $maxPages = 500; $maxComics = 0;
                if ($raw !== '' && $raw[0] === '{') {
                    $cfg = json_decode($raw, true) ?: [];
                    $seeds = !empty($cfg['seeds']) ? (array)$cfg['seeds'] : null;
                    $maxPages = (int)($cfg['max_pages'] ?? 500);
                    $maxComics = (int)($cfg['max_comics'] ?? 0);
                } elseif ($raw !== '') {
                    $seeds = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
                }
                if ($seeds) {
                    // crawlListing accepts seeds via first arg
                    $progressCb(0, 0, 'Discovery dari custom seeds...');
                    $urls = $scraper->crawlListing($seeds, $maxPages, $maxComics);
                    $total = count($urls);
                    $progressCb(0, $total, "Mulai import $total komik");
                    foreach ($urls as $i => $u) {
                        try { $scraper->importFullComic($u); } catch (\Throwable $e) {}
                        $progressCb($i + 1, $total, ($i + 1) . "/$total : $u");
                    }
                } else {
                    $scraper->crawlSite($progressCb, $maxPages, $maxComics);
                }
            } else { // bulk
                $urls = array_filter(array_map('trim', preg_split('/\r?\n/', $job['target_url'])));
                $i = 0; $total = count($urls);
                foreach ($urls as $u) {
                    $progressCb($i, $total, "Import $u");
                    try { $scraper->importFullComic($u); }
                    catch (\Throwable $e) { /* logged inside scraper */ }
                    $i++;
                }
                $progressCb($total, $total, 'Selesai');
            }
            Database::update('import_jobs', ['status' => 'done', 'message' => 'Selesai'], 'id = :id', ['id' => $jobId]);
        } catch (\Throwable $e) {
            Database::update('import_jobs', ['status' => 'failed', 'message' => $e->getMessage()], 'id = :id', ['id' => $jobId]);
        }
    }

    /**
     * Launch a detached CLI worker for long-running site crawls.
     * Properly detaches from the web request (won't block PHP-FPM / built-in server worker).
     */
    private function spawnSiteCrawler(int $jobId): void
    {
        $php = PHP_BINARY ?: 'php';
        $cli = BASE_PATH . '/bin/crawl.php';
        $log = BASE_PATH . '/storage/cache/crawl-' . $jobId . '.log';

        // Build env passthrough for DB credentials (will be set in the sh subshell)
        $envParts = [];
        foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS'] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $envParts[] = sprintf('%s=%s', $k, escapeshellarg($v));
            }
        }
        $envStr = $envParts ? implode(' ', $envParts) . ' ' : '';

        // Inner command runs inside `sh -c` so env-var assignments work portably.
        $inner = sprintf(
            '%s%s %s %d </dev/null >%s 2>&1 &',
            $envStr,
            escapeshellarg($php),
            escapeshellarg($cli),
            $jobId,
            escapeshellarg($log)
        );

        // Prefer setsid (full detach from controlling terminal); fallback to nohup.
        $setsid = trim((string)@shell_exec('command -v setsid')) ?: '';
        if ($setsid !== '') {
            $cmd = $setsid . ' sh -c ' . escapeshellarg($inner);
        } else {
            $cmd = 'nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &';
        }
        @exec($cmd);
    }
}
