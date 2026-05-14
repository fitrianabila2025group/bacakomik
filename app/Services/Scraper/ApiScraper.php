<?php
namespace App\Services\Scraper;

use App\Database;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Setting;
use App\Services\ImageDownloader;
use App\Services\SlugGenerator;

/**
 * Scraper that proxies HTML extraction to the external Python service
 * (see /scraper-service).
 *
 * THIN-MODE (default, controlled by setting `scraper_remote_storage='1'`):
 *   - Tidak men-download gambar ke shared hosting sama sekali.
 *   - URL cover & setiap halaman chapter disimpan langsung ke DB sebagai
 *     URL absolut ke endpoint /proxy Railway (public, di-cache 7 hari di
 *     browser pengunjung). Bandwidth + storage ditanggung VPS Railway.
 *   - 1 import komik = ~ N kali (1 metadata + 1 list chapter + N
 *     /scrape/images) HTTP call dari shared host. Tidak ada disk I/O.
 *
 * LEGACY-MODE (`scraper_remote_storage='0'`):
 *   - Pakai pipeline parent {@see KomikuScraper::importFullComic()} yg
 *     men-download tiap gambar via /proxy ke /storage/comics/...
 *     (berat untuk shared hosting; hanya pakai bila benar-benar perlu
 *     hosting gambar lokal).
 */
class ApiScraper extends KomikuScraper
{
    protected ApiClient $api;
    private static bool $schemaChecked = false;

    public function __construct(array $config = [], ?ApiClient $api = null)
    {
        parent::__construct($config);
        $this->api = $api ?? new ApiClient();
        // Downloader masih dibuat utk fallback legacy-mode + endpoint publik
        // lain (mis. admin upload manual). Di thin-mode tidak akan dipanggil.
        $this->downloader = new ImageDownloader(
            (string)$this->config['user_agent'],
            (int)$this->config['timeout'],
            (int)$this->config['retries']
        );
        $this->downloader->setExtraHeaders([$this->api->authHeader()]);

        // Pastikan kolom DB cukup lebar utk URL proxy absolut.
        $this->ensureSchema();
    }

    /** True jika thin-mode aktif (default ON saat API enabled). */
    protected function isRemoteStorage(): bool
    {
        return (string)Setting::get('scraper_remote_storage', '1') === '1';
    }

    /** True jika /proxy bisa diakses publik tanpa key (default ON). */
    protected function isProxyPublic(): bool
    {
        return (string)Setting::get('scraper_proxy_public', '1') === '1';
    }

    // ====================== Public API overrides ======================

    public function fetchComicMetadata(string $url): array
    {
        $meta = $this->api->post('/scrape/comic', ['url' => $url]);
        if (!empty($meta['cover_url'])) {
            // Selalu publik-safe utk DB-stored URL (hindari kebocoran key).
            $meta['cover_url'] = $this->api->proxyUrl($meta['cover_url'], $url, true);
        }
        $meta += [
            'alt_title' => null, 'author' => null, 'artist' => null,
            'rating' => 0, 'views' => 0, 'genres' => [], 'synopsis' => null,
        ];
        return $meta;
    }

    public function fetchChapterList(string $url): array
    {
        $resp = $this->api->post('/scrape/chapters', ['url' => $url]);
        return $resp['chapters'] ?? [];
    }

    public function fetchChapterImages(string $chapterUrl): array
    {
        $resp = $this->api->post('/scrape/images', ['url' => $chapterUrl]);
        $images = $resp['images'] ?? [];
        $publicSafe = $this->isRemoteStorage() || $this->isProxyPublic();
        $out = [];
        foreach ($images as $img) {
            $out[] = $this->api->proxyUrl($img, $chapterUrl, $publicSafe);
        }
        return $out;
    }

    public function crawlSitemap(?array $sitemapUrls = null): array
    {
        $query = [];
        if ($sitemapUrls) $query['urls'] = implode(',', $sitemapUrls);
        $resp = $this->api->get('/discover/sitemap', $query);
        return $resp['urls'] ?? [];
    }

    public function crawlListing(?array $seeds = null, int $maxPages = 200, int $maxComics = 0): array
    {
        $query = ['max_pages' => $maxPages, 'max_comics' => $maxComics];
        if ($seeds) $query['seeds'] = implode(',', $seeds);
        $resp = $this->api->get('/discover/listing', $query);
        return $resp['urls'] ?? [];
    }

    public function ping(): array
    {
        return $this->api->ping();
    }

    // ====================== Thin-mode import pipeline ======================

    /**
     * Import komik tanpa men-download gambar: cukup INSERT URL proxy ke DB.
     *
     * Pakai endpoint bulk `/scrape/comic-full` (service v1.3+) supaya hanya
     * 1 round-trip HTTP per komik (bukan 1 + 1 + N). Otomatis fallback ke
     * pipeline per-call kalau endpoint tsb belum tersedia.
     */
    public function importFullComic(string $url, ?callable $progressCallback = null): array
    {
        if (!$this->isRemoteStorage()) {
            return parent::importFullComic($url, $progressCallback);
        }

        $this->logEvent('info', $url, 'Mulai import komik (bulk remote)');

        // 1 ROUND-TRIP: ambil metadata + chapters + semua image URL sekaligus.
        try {
            $bulk = $this->api->post('/scrape/comic-full', [
                'url'            => $url,
                'concurrency'    => max(2, min(12, (int)Setting::get('scraper_concurrency', '6'))),
                'include_images' => true,
            ]);
        } catch (\Throwable $e) {
            // Fallback: service lama belum punya /scrape/comic-full.
            $this->logEvent('warning', $url, '/scrape/comic-full gagal, fallback per-call: ' . $e->getMessage());
            return $this->importFullComicFallback($url, $progressCallback);
        }

        $meta = $bulk['meta'] ?? [];
        $chapters = $bulk['chapters'] ?? [];
        if (empty($meta['title'])) {
            throw new \RuntimeException('Gagal mengambil judul komik dari ' . $url);
        }

        // Cover -> URL proxy publik.
        if (!empty($meta['cover_url'])) {
            $meta['cover_url'] = $this->api->proxyUrl($meta['cover_url'], $url, true);
        }

        $existing = Database::fetch('SELECT * FROM comics WHERE source_url = ?', [$url]);
        $slug = $existing ? $existing['slug'] : Comic::uniqueSlug($meta['title']);

        $data = [
            'title'      => $meta['title'],
            'slug'       => $slug,
            'alt_title'  => $meta['alt_title'] ?? null,
            'author'     => $meta['author'] ?? null,
            'artist'     => $meta['artist'] ?? null,
            'type'       => $meta['type'] ?? 'Manga',
            'status'     => $meta['status'] ?? 'Ongoing',
            'synopsis'   => $meta['synopsis'] ?? null,
            'rating'     => $meta['rating'] ?? 0,
            'views'      => 0, // original count for bacakomik.io, di-increment via ComicController.
            'source_url' => $url,
        ];
        if (!empty($meta['cover_url'])) {
            $data['cover_image'] = $meta['cover_url'];
        }

        if ($existing) {
            Database::update('comics', $data, 'id = :id', ['id' => $existing['id']]);
            $comicId = (int)$existing['id'];
        } else {
            $comicId = Database::insert('comics', $data);
        }

        $genreIds = [];
        foreach ((array)($meta['genres'] ?? []) as $gname) {
            $genreIds[] = Genre::findOrCreate($gname);
        }
        if ($genreIds) Comic::syncGenres($comicId, $genreIds);

        $totalChapters = count($chapters);
        $totalImages = 0;
        $errors = [];

        if ($progressCallback) $progressCallback(0, $totalChapters, "0 dari $totalChapters chapter");

        $publicSafe = $this->isProxyPublic();
        foreach ($chapters as $i => $ch) {
            $rawImages = (array)($ch['images'] ?? []);
            // bungkus ke URL proxy (publik) — sekali kerjain di PHP, tidak perlu HTTP.
            $imgs = [];
            foreach ($rawImages as $u) {
                if ($u === '') continue;
                $imgs[] = $this->api->proxyUrl($u, $ch['url'] ?? $url, $publicSafe);
            }
            try {
                $totalImages += $this->insertChapterImages($comicId, $slug, $ch, $imgs);
            } catch (\Throwable $e) {
                $errors[] = ($ch['url'] ?? '?') . ' :: ' . $e->getMessage();
                $this->logEvent('error', $ch['url'] ?? $url, $e->getMessage());
            }
            if (!empty($ch['error'])) {
                $errors[] = ($ch['url'] ?? '?') . ' :: ' . $ch['error'];
            }
            if ($progressCallback) {
                $progressCallback($i + 1, $totalChapters, ($i + 1) . " dari $totalChapters chapter");
            }
        }

        $this->logEvent('success', $url, "Selesai: $totalChapters chapter, $totalImages gambar (bulk)");

        return [
            'comic_id' => $comicId,
            'chapters' => $totalChapters,
            'images'   => $totalImages,
            'errors'   => $errors,
        ];
    }

    /**
     * Fallback per-call (versi lama) bila /scrape/comic-full tidak tersedia.
     */
    private function importFullComicFallback(string $url, ?callable $progressCallback): array
    {
        $meta = $this->fetchComicMetadata($url);
        if (empty($meta['title'])) {
            throw new \RuntimeException('Gagal mengambil judul komik dari ' . $url);
        }
        $existing = Database::fetch('SELECT * FROM comics WHERE source_url = ?', [$url]);
        $slug = $existing ? $existing['slug'] : Comic::uniqueSlug($meta['title']);
        $data = [
            'title' => $meta['title'], 'slug' => $slug,
            'alt_title' => $meta['alt_title'] ?? null,
            'author' => $meta['author'] ?? null, 'artist' => $meta['artist'] ?? null,
            'type' => $meta['type'] ?? 'Manga', 'status' => $meta['status'] ?? 'Ongoing',
            'synopsis' => $meta['synopsis'] ?? null,
            'rating' => $meta['rating'] ?? 0, 'views' => 0,
            'source_url' => $url,
        ];
        if (!empty($meta['cover_url'])) $data['cover_image'] = $meta['cover_url'];
        if ($existing) {
            Database::update('comics', $data, 'id = :id', ['id' => $existing['id']]);
            $comicId = (int)$existing['id'];
        } else {
            $comicId = Database::insert('comics', $data);
        }
        $genreIds = [];
        foreach ((array)$meta['genres'] as $g) $genreIds[] = Genre::findOrCreate($g);
        if ($genreIds) Comic::syncGenres($comicId, $genreIds);

        $chapters = $this->fetchChapterList($url);
        $totalChapters = count($chapters);
        $totalImages = 0; $errors = [];
        if ($progressCallback) $progressCallback(0, $totalChapters, "0 dari $totalChapters chapter");
        foreach ($chapters as $i => $ch) {
            try { $totalImages += $this->importChapterRemote($comicId, $slug, $ch); }
            catch (\Throwable $e) { $errors[] = $ch['url'] . ' :: ' . $e->getMessage(); }
            if ($progressCallback) $progressCallback($i + 1, $totalChapters, ($i + 1) . " dari $totalChapters chapter");
        }
        return ['comic_id' => $comicId, 'chapters' => $totalChapters, 'images' => $totalImages, 'errors' => $errors];
    }

    /**
     * Insert satu chapter + gambar (URL sudah dibungkus /proxy publik).
     */
    protected function insertChapterImages(int $comicId, string $comicSlug, array $ch, array $imgUrls): int
    {
        $chSlug = SlugGenerator::make('chapter-' . $ch['number']);
        $existing = Database::fetch(
            'SELECT * FROM chapters WHERE comic_id = ? AND slug = ?',
            [$comicId, $chSlug]
        );
        if ($existing) {
            $chapterId = (int)$existing['id'];
            $existingCount = (int)Database::fetch(
                'SELECT COUNT(*) AS c FROM chapter_images WHERE chapter_id = ?',
                [$chapterId]
            )['c'];
            $force = (int)Setting::get('scraper_force_refetch', '0') === 1;
            if ($existingCount > 0 && !$force) {
                return 0;
            }
            Chapter::deleteImages($chapterId);
        } else {
            $chSlug = Chapter::uniqueSlug($comicId, 'chapter-' . $ch['number']);
            $chapterId = Database::insert('chapters', [
                'comic_id'       => $comicId,
                'chapter_number' => $ch['number'],
                'title'          => $ch['title'] ?: ('Chapter ' . $ch['number']),
                'slug'           => $chSlug,
                'source_url'     => $ch['url'],
                'views'          => 0,
            ]);
        }

        $count = 0;
        foreach ($imgUrls as $idx => $imgUrl) {
            if ($imgUrl === '') continue;
            Database::insert('chapter_images', [
                'chapter_id' => $chapterId,
                'image_path' => $imgUrl,
                'image_url'  => $imgUrl,
                'sort_order' => $idx + 1,
            ]);
            $count++;
        }
        return $count;
    }

    public function importSingleChapter(string $chapterUrl, ?int $forceComicId = null): array
    {
        if (!$this->isRemoteStorage()) {
            return parent::importSingleChapter($chapterUrl, $forceComicId);
        }

        $parsed = parse_url($chapterUrl);
        $segments = explode('/', trim($parsed['path'] ?? '', '/'));
        array_pop($segments);
        $parentUrl = $parsed['scheme'] . '://' . $parsed['host'] . '/' . implode('/', $segments);

        $comicId = $forceComicId;
        if (!$comicId) {
            $existing = Database::fetch('SELECT id FROM comics WHERE source_url = ?', [$parentUrl]);
            if ($existing) {
                $comicId = (int)$existing['id'];
            } else {
                // Trigger full import — paling konsisten.
                $res = $this->importFullComic($parentUrl);
                $comicId = (int)$res['comic_id'];
            }
        }

        $comic = Comic::find($comicId);
        $title  = basename(rtrim($chapterUrl, '/'));
        $number = preg_match('/(\d+(?:\.\d+)?)/', $title, $m) ? $m[1] : '0';

        $imgCount = $this->importChapterRemote($comicId, $comic['slug'], [
            'number' => $number,
            'title'  => $title,
            'url'    => $chapterUrl,
            'date'   => '',
            'views'  => 0,
        ]);
        return ['comic_id' => $comicId, 'images' => $imgCount];
    }

    /**
     * Insert/refresh 1 chapter + URL halamannya. Tidak ada file lokal.
     */
    protected function importChapterRemote(int $comicId, string $comicSlug, array $ch): int
    {
        $chSlug = SlugGenerator::make('chapter-' . $ch['number']);
        $existing = Database::fetch(
            'SELECT * FROM chapters WHERE comic_id = ? AND slug = ?',
            [$comicId, $chSlug]
        );
        if ($existing) {
            $chapterId = (int)$existing['id'];
            $existingCount = (int)Database::fetch(
                'SELECT COUNT(*) AS c FROM chapter_images WHERE chapter_id = ?',
                [$chapterId]
            )['c'];
            $force = (int)Setting::get('scraper_force_refetch', '0') === 1;
            if ($existingCount > 0 && !$force) {
                $this->logEvent('info', $ch['url'], "Chapter {$ch['number']} dilewati (sudah $existingCount gambar)");
                return 0;
            }
            Chapter::deleteImages($chapterId);
        } else {
            $chSlug = Chapter::uniqueSlug($comicId, 'chapter-' . $ch['number']);
            $chapterId = Database::insert('chapters', [
                'comic_id'       => $comicId,
                'chapter_number' => $ch['number'],
                'title'          => $ch['title'] ?: ('Chapter ' . $ch['number']),
                'slug'           => $chSlug,
                'source_url'     => $ch['url'],
                'views'          => 0,
            ]);
        }

        // Ambil daftar URL gambar (sudah berbentuk URL /proxy publik).
        $images = $this->fetchChapterImages($ch['url']);
        $count = 0;
        foreach ($images as $idx => $imgUrl) {
            if ($imgUrl === '') continue;
            Database::insert('chapter_images', [
                'chapter_id' => $chapterId,
                'image_path' => $imgUrl,         // URL absolut → langsung dipakai <img src>
                'image_url'  => $imgUrl,
                'sort_order' => $idx + 1,
            ]);
            $count++;
        }

        $this->logEvent('success', $ch['url'], "Chapter {$ch['number']} : $count gambar (remote)");

        $delay = (float)$this->config['delay'];
        if ($delay > 0) usleep((int)($delay * 1_000_000));

        return $count;
    }

    // ====================== Helpers ======================

    private function logEvent(string $status, string $url, string $message): void
    {
        try {
            Database::insert('import_logs', [
                'source'     => 'api',
                'source_url' => $url,
                'status'     => $status,
                'message'    => $message,
            ]);
        } catch (\Throwable $e) { /* ignore */ }
    }

    /**
     * Pastikan kolom `comics.cover_image` cukup lebar utk URL absolut /proxy.
     * Jalan max sekali per request, error di-swallow.
     */
    private function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            $col = Database::fetch(
                "SELECT CHARACTER_MAXIMUM_LENGTH AS len
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'comics'
                    AND COLUMN_NAME = 'cover_image'"
            );
            if ($col && (int)$col['len'] < 500) {
                Database::query("ALTER TABLE comics MODIFY cover_image VARCHAR(500) NULL");
            }
        } catch (\Throwable $e) { /* ignore */ }
    }
}
