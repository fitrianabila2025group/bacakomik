<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;
use App\Services\Scraper\KomikuScraper;

class ImportController extends AdminController
{
    public function index(): string
    {
        $jobs = Database::fetchAll('SELECT * FROM import_jobs ORDER BY id DESC LIMIT 20');
        $logs = Database::fetchAll('SELECT * FROM import_logs ORDER BY id DESC LIMIT 50');
        return $this->view('admin/import', [
            'title' => 'Import',
            'jobs'  => $jobs,
            'logs'  => $logs,
        ]);
    }

    public function preview(): string
    {
        Csrf::check();
        $url = trim((string)($_POST['url'] ?? ''));
        try {
            $scraper = new KomikuScraper();
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

        // Site-wide crawl is too long for a web request -> spawn detached CLI worker.
        if ($type === 'site') {
            $this->spawnSiteCrawler($jobId);
            return $this->json(['ok' => true, 'job_id' => $jobId, 'background' => true]);
        }

        // For simplicity we run synchronously but with output buffer flushed.
        // In production you would push this to a worker process.
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

        $scraper = new KomikuScraper();
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
