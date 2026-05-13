<?php
namespace App\Controllers;

use App\Database;
use App\Models\Comic;
use App\Models\Genre;
use App\Models\Setting;

class HomeController extends Controller
{
    public function index(): string
    {
        return $this->view('home', [
            'title'    => Setting::get('meta_title') ?? 'BacaKomik',
            'featured' => Comic::featured(6),
            'latest'   => Comic::latest(18),
            'popular'  => Comic::popular(12),
            'genres'   => Genre::all(),
        ]);
    }

    public function series(): string
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $filters = [
            'search'   => $_GET['q'] ?? null,
            'type'     => $_GET['type'] ?? null,
            'status'   => $_GET['status'] ?? null,
            'genre_id' => null,
        ];
        if (!empty($_GET['genre'])) {
            $g = Genre::findBySlug($_GET['genre']);
            if ($g) $filters['genre_id'] = $g['id'];
        }
        return $this->view('search', [
            'title'   => 'Series - BacaKomik',
            'comics'  => Comic::paginate($page, 24, $filters),
            'page'    => $page,
            'genres'  => Genre::all(),
            'filters' => $filters,
        ]);
    }

    public function popular(): string
    {
        return $this->view('search', [
            'title'  => 'Populer - BacaKomik',
            'comics' => Comic::popular(48),
            'page'   => 1,
            'genres' => Genre::all(),
            'filters'=> ['search' => null, 'type' => null, 'status' => null, 'genre_id' => null],
        ]);
    }

    public function library(): string
    {
        $userId = $_SESSION['user_id'] ?? 0;
        $bookmarks = $userId ? Database::fetchAll(
            'SELECT c.* FROM bookmarks b JOIN comics c ON c.id = b.comic_id WHERE b.user_id = ? ORDER BY b.created_at DESC',
            [$userId]
        ) : [];
        $history = $userId ? Database::fetchAll(
            'SELECT c.*, ch.chapter_number, ch.slug AS ch_slug, h.last_read_at
             FROM reading_history h JOIN comics c ON c.id = h.comic_id JOIN chapters ch ON ch.id = h.chapter_id
             WHERE h.user_id = ? ORDER BY h.last_read_at DESC LIMIT 20',
            [$userId]
        ) : [];
        return $this->view('library', [
            'title'     => 'Library - BacaKomik',
            'bookmarks' => $bookmarks,
            'history'   => $history,
        ]);
    }

    public function search(): string { return $this->series(); }

    public function apiSearch(): string
    {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') return $this->json(['results' => []]);
        $rows = Database::fetchAll(
            'SELECT id, title, slug, cover_image, type, status FROM comics
             WHERE title LIKE :q OR alt_title LIKE :q OR author LIKE :q LIMIT 12',
            ['q' => '%' . $q . '%']
        );
        return $this->json(['results' => $rows]);
    }

    public function page(string $slug): string
    {
        $page = Database::fetch('SELECT * FROM pages WHERE slug = ? AND status = "published"', [$slug]);
        if (!$page) { http_response_code(404); return '<h1>Page not found</h1>'; }
        return $this->view('page', ['title' => $page['title'], 'page' => $page]);
    }

    public function serveStorage(string $path): string
    {
        // Path traversal guard
        $clean = str_replace(['..', "\0"], '', $path);
        $full  = realpath(STORAGE_PATH . '/' . $clean);
        if (!$full || strpos($full, realpath(STORAGE_PATH)) !== 0 || !is_file($full)) {
            http_response_code(404);
            return 'Not found';
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($full) : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: public, max-age=86400');
        readfile($full);
        exit;
    }

    /**
     * Sitemap index — points to per-section sitemaps. Submit this URL ke Google Search Console.
     * Per-section sitemap di-split agar tetap < 50.000 URL per file (Google limit).
     */
    public function sitemap(): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex'); // sitemap index sendiri tidak perlu di-index
        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');

        $now = date('c');
        $sections = [
            ['loc' => "$base/sitemap-pages.xml",   'lastmod' => $now],
            ['loc' => "$base/sitemap-genres.xml",  'lastmod' => $now],
        ];

        // Comics dipecah per 5000 URL
        $comicCount = (int)(Database::fetch('SELECT COUNT(*) AS c FROM comics')['c'] ?? 0);
        $comicChunks = max(1, (int)ceil($comicCount / 5000));
        for ($i = 1; $i <= $comicChunks; $i++) {
            $latest = Database::fetch(
                'SELECT MAX(updated_at) AS m FROM comics ORDER BY id LIMIT 5000 OFFSET ' . (($i - 1) * 5000)
            );
            $sections[] = ['loc' => "$base/sitemap-comics-$i.xml", 'lastmod' => date('c', strtotime($latest['m'] ?? 'now'))];
        }

        // Chapters dipecah per 10000
        $chapterCount = (int)(Database::fetch('SELECT COUNT(*) AS c FROM chapters')['c'] ?? 0);
        $chapterChunks = max(1, (int)ceil($chapterCount / 10000));
        for ($i = 1; $i <= $chapterChunks; $i++) {
            $latest = Database::fetch(
                'SELECT MAX(updated_at) AS m FROM chapters ORDER BY id LIMIT 10000 OFFSET ' . (($i - 1) * 10000)
            );
            $sections[] = ['loc' => "$base/sitemap-chapters-$i.xml", 'lastmod' => date('c', strtotime($latest['m'] ?? 'now'))];
        }

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($sections as $s) {
            $out .= '  <sitemap><loc>' . htmlspecialchars($s['loc']) . '</loc><lastmod>' . $s['lastmod'] . "</lastmod></sitemap>\n";
        }
        $out .= '</sitemapindex>';
        echo $out; exit;
    }

    /** Sitemap untuk halaman statis (home, pages, popular, dll). */
    public function sitemapPages(): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $now = date('c');

        $urls = [
            ['loc' => "$base/",         'priority' => '1.0', 'changefreq' => 'hourly'],
            ['loc' => "$base/series",   'priority' => '0.9', 'changefreq' => 'hourly'],
            ['loc' => "$base/popular",  'priority' => '0.8', 'changefreq' => 'daily'],
            ['loc' => "$base/library",  'priority' => '0.5', 'changefreq' => 'weekly'],
        ];
        foreach (Database::fetchAll("SELECT slug, updated_at FROM pages WHERE status = 'published'") as $p) {
            $urls[] = [
                'loc'      => "$base/page/" . htmlspecialchars($p['slug']),
                'lastmod'  => date('c', strtotime($p['updated_at'])),
                'priority' => '0.6', 'changefreq' => 'monthly',
            ];
        }
        echo $this->urlSetXml($urls, $now); exit;
    }

    /** Sitemap untuk halaman genre. */
    public function sitemapGenres(): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $now = date('c');
        $urls = [];
        foreach (Database::fetchAll('SELECT slug FROM genres ORDER BY name') as $g) {
            $urls[] = [
                'loc'      => "$base/series?genre=" . htmlspecialchars($g['slug']),
                'priority' => '0.7', 'changefreq' => 'daily',
            ];
        }
        echo $this->urlSetXml($urls, $now); exit;
    }

    /** Sitemap untuk komik (paginated, max 5000 per chunk). */
    public function sitemapComics(int $chunk = 1): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $offset = max(0, ($chunk - 1) * 5000);
        $rows = Database::fetchAll(
            'SELECT slug, updated_at, cover_image FROM comics ORDER BY id LIMIT 5000 OFFSET ' . $offset
        );
        if (!$rows) { http_response_code(404); echo 'Not found'; exit; }

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/0.9">' . "\n";
        foreach ($rows as $c) {
            $loc = "$base/comic/" . htmlspecialchars($c['slug']);
            $lastmod = date('c', strtotime($c['updated_at']));
            $out .= '  <url><loc>' . $loc . '</loc><lastmod>' . $lastmod . '</lastmod><changefreq>daily</changefreq><priority>0.8</priority>';
            if (!empty($c['cover_image'])) {
                $img = $base . htmlspecialchars($c['cover_image']);
                $out .= '<image:image><image:loc>' . $img . '</image:loc></image:image>';
            }
            $out .= "</url>\n";
        }
        $out .= '</urlset>';
        echo $out; exit;
    }

    /** Sitemap untuk chapter (paginated, max 10000 per chunk). */
    public function sitemapChapters(int $chunk = 1): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $offset = max(0, ($chunk - 1) * 10000);
        $rows = Database::fetchAll(
            'SELECT c.slug AS comic_slug, ch.slug AS chapter_slug, ch.updated_at
             FROM chapters ch JOIN comics c ON c.id = ch.comic_id
             ORDER BY ch.id LIMIT 10000 OFFSET ' . $offset
        );
        if (!$rows) { http_response_code(404); echo 'Not found'; exit; }

        $urls = [];
        foreach ($rows as $r) {
            $urls[] = [
                'loc'        => "$base/comic/" . htmlspecialchars($r['comic_slug']) . '/' . htmlspecialchars($r['chapter_slug']),
                'lastmod'    => date('c', strtotime($r['updated_at'])),
                'priority'   => '0.6',
                'changefreq' => 'weekly',
            ];
        }
        echo $this->urlSetXml($urls); exit;
    }

    private function urlSetXml(array $urls, ?string $defaultLastmod = null): string
    {
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $out .= '  <url><loc>' . $u['loc'] . '</loc>';
            $lm = $u['lastmod'] ?? $defaultLastmod;
            if ($lm) $out .= '<lastmod>' . $lm . '</lastmod>';
            if (!empty($u['changefreq'])) $out .= '<changefreq>' . $u['changefreq'] . '</changefreq>';
            if (!empty($u['priority']))   $out .= '<priority>' . $u['priority'] . '</priority>';
            $out .= "</url>\n";
        }
        $out .= '</urlset>';
        return $out;
    }

    public function robots(): string
    {
        header('Content-Type: text/plain');
        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin\n";
        echo "Disallow: /login\n";
        echo "Disallow: /register\n";
        echo "Sitemap: $base/sitemap.xml\n";
        exit;
    }
}
