<?php
namespace App\Services\Scraper;

use App\Database;
use App\Models\Comic;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Setting;
use App\Services\ImageDownloader;
use App\Services\SlugGenerator;
use DOMDocument;
use DOMXPath;

/**
 * KomikuScraper - scrape komiku.org (owned source).
 *
 * Selectors are kept in $config['selectors'] so they can be tuned without
 * touching the parsing logic. See README.md for instructions.
 */
class KomikuScraper implements ScraperInterface
{
    protected array $config;
    protected ImageDownloader $downloader;
    protected string $cacheDir;

    public function __construct(array $config = [])
    {
        $defaults = [
            'whitelist' => array_filter(array_map('trim', explode(',', (string)Setting::get('scraper_whitelist', 'komiku.org,komiku.id,img.komiku.org,mangaku.top,img.mangaku.top,cover.mangaku.top')))),
            'user_agent'=> Setting::get('scraper_user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'),
            'timeout'   => (int)Setting::get('scraper_timeout', '30'),
            'delay'     => (float)Setting::get('scraper_delay', '0.2'),
            'retries'   => 3,
            'selectors' => [
                'cover'        => '//img[@itemprop="image"]/@src | //div[contains(@class,"thumb")]//img/@src',
                'title'        => '//h1//span[@itemprop="name"] | //h1',
                'alt_title'    => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Judul Alternatif") or contains(.,"Alternative")]]/td[2]',
                'author'       => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Author") or contains(.,"Pengarang")]]/td[2]',
                'artist'       => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Ilustrator") or contains(.,"Artist")]]/td[2]',
                'type'         => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Tipe") or contains(.,"Jenis") or contains(.,"Type")]]/td[2]',
                'status'       => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Status")]]/td[2]',
                'rating'       => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Rating")]]/td[2]',
                'synopsis'     => '//*[@itemprop="description"] | //div[contains(@class,"desc")] | //section[contains(@class,"sinopsis")] | //div[@id="Sinopsis"]',
                'genres'       => '//ul[contains(@class,"genre")]//a | //div[contains(@class,"genre")]//a',
                'views'        => '//table[contains(@class,"inftable")]//tr[td[contains(.,"Pembaca") or contains(.,"Dilihat") or contains(.,"Views")]]/td[2]',
                'chapter_rows' => '//*[@id="Daftar_Chapter"]//tr[@itemprop="itemListElement"] | //tbody[@id="daftarChapter"]//tr[@itemprop="itemListElement"] | //table[contains(@class,"chapter")]//tr',
                'chapter_link' => './/a/@href',
                'chapter_title'=> './/a',
                'chapter_date' => './/td[contains(@class,"tanggalseries") or contains(@class,"date")]',
                'chapter_views'=> './/td[contains(@class,"views")]',
                'image'        => '//div[@id="Baca_Komik"]//img/@src | //section[@id="Baca_Komik"]//img/@src | //*[@id="Baca_Komik"]//img/@src | //img[@itemprop="image" and ancestor::*[@id="Baca_Komik"]]/@src',
                // Listing/directory pages — used by full-site crawler.
                // We collect EVERY <a href> on listing pages and filter by regex below
                // (more robust than a fragile container-class selector).
                'listing_links'    => '//a/@href',
                'listing_next'     => '//a[contains(@class,"next") and not(contains(@class,"disabled"))]/@href | //link[@rel="next"]/@href | //a[normalize-space(.)="Next" or normalize-space(.)="»" or normalize-space(.)="Selanjutnya"]/@href',
            ],
            'listing_seeds' => [
                // komiku.org directory entry points (paginated with ?halaman=N).
                // Cover semua tipe: manga + manhwa + manhua.
                'https://komiku.org/daftar-komik/',
                'https://komiku.org/pustaka/',
                'https://komiku.org/pustaka/?tipe=manga',
                'https://komiku.org/pustaka/?tipe=manhwa',
                'https://komiku.org/pustaka/?tipe=manhua',
                // komiku.id now redirects to mangaku.top (uses /komik/SLUG/)
                'https://mangaku.top/komik/',
            ],
            // XML sitemaps — paling cepat untuk discovery (1 request = ribuan komik).
            'sitemap_urls' => [
                'https://komiku.org/sitemapL5yutt5/series/',
            ],
            // Regex applied to discovered links to identify a comic detail page.
            'detail_pattern' => '#^https?://[^/]+/(manga|komik)/[^/]+/?$#i',
            // Regex applied to discovered links to identify a NEXT listing page (paginated).
            // Covers:
            //   - komiku.org : /daftar-komik/?halaman=N , /daftar-komik/?huruf=X , /pustaka/?tipe=...&halaman=N
            //   - generic    : /daftar-komik/page/N/ , ?page=N , page=N
            //   - mangaku.top: /komik/page/N/
            'pagination_pattern' => '#/(daftar-komik|pustaka|komik)/?(\?(halaman|page|huruf|tipe|genre|status|orderby)=[^&]+|page/\d+/?)#i',
        ];
        $this->config = array_replace_recursive($defaults, $config);
        $this->cacheDir = STORAGE_PATH . '/cache';
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0775, true);
        $this->downloader = new ImageDownloader(
            (string)$this->config['user_agent'],
            (int)$this->config['timeout'],
            (int)$this->config['retries']
        );
    }

    // ====================== Public API ======================

    /**
     * Discover comic detail URLs by crawling listing/directory pages.
     * Will paginate "next" links and dedupe URLs containing /manga/ or /komik/.
     *
     * @param string[]|null $seeds  Optional override start URLs.
     * @param int $maxPages         Max listing pages to crawl per seed (safety).
     * @param int $maxComics        Max unique comic URLs to collect (0 = unlimited).
     * @return string[]             Absolute comic detail URLs.
     */
    public function crawlListing(?array $seeds = null, int $maxPages = 200, int $maxComics = 0): array
    {
        $seeds = $seeds ?: ($this->config['listing_seeds'] ?? []);
        $detailPattern = $this->config['detail_pattern'] ?? '#^https?://[^/]+/(manga|komik)/[^/]+/?$#i';
        $paginationPattern = $this->config['pagination_pattern'] ?? '#/(daftar-komik|pustaka|komik)/(page/\d+/|\?page=\d+|page=\d+)#i';
        $found = [];
        $visited = [];

        foreach ($seeds as $seed) {
            if (!$this->isWhitelistedHost($seed)) continue;
            $queue = [$seed];
            $pages = 0;
            while ($queue && $pages < $maxPages) {
                $url = array_shift($queue);
                $url = strtok($url, '#');
                if (isset($visited[$url])) continue;
                $visited[$url] = true;
                $pages++;

                try {
                    $html = $this->fetchHtml($url);
                } catch (\Throwable $e) {
                    $this->log('warning', $url, 'crawl skip: ' . $e->getMessage());
                    continue;
                }
                $xp = $this->makeXPath($html);

                $linkNodes = $xp->query($this->config['selectors']['listing_links']);
                if ($linkNodes) {
                    foreach ($linkNodes as $n) {
                        $rawHref = trim($n->nodeValue);
                        if ($rawHref === '' || str_starts_with($rawHref, 'javascript:') || str_starts_with($rawHref, 'mailto:')) continue;
                        $href = $this->absoluteUrl($rawHref, $url);
                        $href = strtok($href, '#');
                        if (!$this->isWhitelistedHost($href)) continue;

                        // Comic detail page?
                        if (preg_match($detailPattern, $href)) {
                            // Skip listing/index-style URLs (e.g. /komik/?orderby=...)
                            if (str_contains($href, '?')) continue;
                            $found[$href] = true;
                            if ($maxComics > 0 && count($found) >= $maxComics) break 3;
                            continue;
                        }
                        // Pagination link of the listing itself?
                        if (preg_match($paginationPattern, $href) && !isset($visited[$href])) {
                            $queue[] = $href;
                        }
                    }
                }

                // Also follow explicit "Next" rel/anchor if present
                $nextNodes = $xp->query($this->config['selectors']['listing_next']);
                if ($nextNodes && $nextNodes->length) {
                    $next = $this->absoluteUrl(trim($nextNodes->item(0)->nodeValue), $url);
                    $next = strtok($next, '#');
                    if ($this->isWhitelistedHost($next) && !isset($visited[$next])) $queue[] = $next;
                }
                $this->sleepDelay();
            }
        }

        return array_keys($found);
    }

    /**
     * Discover comic URLs via XML sitemap (fast: usually 1 request).
     * Each <loc> in the sitemap is normalized: paths under
     *   /sitemapL5yutt5/manga/SLUG/  → /manga/SLUG/
     * become canonical detail URLs.
     *
     * @param string[]|null $sitemapUrls
     * @return string[] Absolute comic detail URLs
     */
    public function crawlSitemap(?array $sitemapUrls = null): array
    {
        $sitemapUrls = $sitemapUrls ?: ($this->config['sitemap_urls'] ?? []);
        $found = [];
        foreach ($sitemapUrls as $sm) {
            if (!$this->isWhitelistedHost($sm)) continue;
            try {
                $xml = $this->fetchHtml($sm);
            } catch (\Throwable $e) {
                $this->log('warning', $sm, 'sitemap skip: ' . $e->getMessage());
                continue;
            }
            // Sitemap index? Recurse into each <sitemap><loc>.
            if (preg_match_all('#<sitemap>\s*<loc>([^<]+)</loc>#i', $xml, $idx)) {
                foreach ($idx[1] as $child) {
                    $found = array_merge($found, $this->crawlSitemap([trim($child)]));
                }
            }
            // Extract <url><loc>…</loc></url>
            if (preg_match_all('#<loc>([^<]+)</loc>#i', $xml, $m)) {
                foreach ($m[1] as $loc) {
                    $loc = trim($loc);
                    // Normalize sitemap-style /sitemapL5yutt5/manga/SLUG/ to /manga/SLUG/
                    $loc = preg_replace('#/sitemap[^/]*/(manga|komik)/#i', '/$1/', $loc);
                    if (!$this->isWhitelistedHost($loc)) continue;
                    if (preg_match($this->config['detail_pattern'], $loc)) {
                        $found[$loc] = true;
                    }
                }
            }
        }
        return array_keys($found);
    }


    /**
     * Full-site auto crawl: discover (sitemap first, fallback to listing crawl)
     * then importFullComic() for each URL.
     * Reports progress via callback($done, $total, $message).
     */
    public function crawlSite(?callable $progressCallback = null, int $maxPages = 500, int $maxComics = 0): array
    {
        if ($progressCallback) $progressCallback(0, 0, 'Discovery: sitemap...');
        $urls = [];
        try {
            $urls = $this->crawlSitemap();
        } catch (\Throwable $e) {
            $this->log('warning', '', 'Sitemap discovery gagal: ' . $e->getMessage());
        }
        if (count($urls) < 5) {
            // Fallback ke listing crawl
            if ($progressCallback) $progressCallback(0, 0, 'Discovery: listing pages...');
            $urls = array_values(array_unique(array_merge($urls, $this->crawlListing(null, $maxPages, $maxComics))));
        }
        if ($maxComics > 0 && count($urls) > $maxComics) {
            $urls = array_slice($urls, 0, $maxComics);
        }
        $total = count($urls);
        $this->log('info', '', "Discovery selesai: $total komik ditemukan");
        if ($progressCallback) $progressCallback(0, $total, "Mulai import $total komik");

        $imported = 0; $errors = [];
        foreach ($urls as $i => $u) {
            try {
                $this->importFullComic($u);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = $u . ' :: ' . $e->getMessage();
                $this->log('error', $u, 'crawlSite: ' . $e->getMessage());
            }
            if ($progressCallback) $progressCallback($i + 1, $total, ($i + 1) . "/$total : " . $u);
        }
        return ['discovered' => $total, 'imported' => $imported, 'errors' => $errors];
    }

    private function isWhitelistedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        foreach ($this->config['whitelist'] as $w) {
            if ($host === $w || str_ends_with($host, '.' . $w)) return true;
        }
        return false;
    }

    public function fetchComicMetadata(string $url): array
    {
        $this->validateUrl($url);
        $html = $this->fetchHtml($url);
        $xp = $this->makeXPath($html);
        $sel = $this->config['selectors'];

        $title = $this->xText($xp, '//h1//span[@itemprop="name"]') ?: $this->xText($xp, $sel['title']);
        $title = preg_replace('/^\s*Komik\s+/i', '', (string)$title);
        $cover = $this->xValue($xp, $sel['cover']);
        $cover = $cover ? $this->absoluteUrl($cover, $url) : null;

        $genreNodes = $xp->query($sel['genres']);
        $genres = [];
        if ($genreNodes) {
            foreach ($genreNodes as $g) $genres[] = trim($g->textContent);
        }

        $type   = $this->normalizeType($this->xText($xp, $sel['type']));
        $status = $this->normalizeStatus($this->xText($xp, $sel['status']));
        $rating = (float)preg_replace('/[^0-9.]/', '', $this->xText($xp, $sel['rating']));
        $views  = (int)preg_replace('/[^0-9]/', '', $this->xText($xp, $sel['views']));

        return [
            'title'      => $title,
            'alt_title'  => $this->xText($xp, $sel['alt_title']),
            'author'     => $this->xText($xp, $sel['author']),
            'artist'     => $this->xText($xp, $sel['artist']),
            'type'       => $type,
            'status'     => $status,
            'rating'     => $rating,
            'views'      => $views,
            'synopsis'   => $this->xText($xp, $sel['synopsis']),
            'cover_url'  => $cover,
            'genres'     => array_values(array_filter(array_unique($genres))),
            'source_url' => $url,
        ];
    }

    public function fetchChapterList(string $url): array
    {
        $this->validateUrl($url);
        $html = $this->fetchHtml($url);
        $xp = $this->makeXPath($html);
        $sel = $this->config['selectors'];

        $rows = $xp->query($sel['chapter_rows']);
        $chapters = [];
        if ($rows) {
            foreach ($rows as $row) {
                $linkNode = $xp->query($sel['chapter_link'], $row);
                if (!$linkNode || $linkNode->length === 0) continue;
                $link = $this->absoluteUrl(trim($linkNode->item(0)->nodeValue), $url);
                $titleNode = $xp->query($sel['chapter_title'], $row);
                $title = $titleNode && $titleNode->length ? trim($titleNode->item(0)->textContent) : '';
                $dateNode = $xp->query($sel['chapter_date'], $row);
                $date = $dateNode && $dateNode->length ? trim($dateNode->item(0)->textContent) : '';
                $viewsNode = $xp->query($sel['chapter_views'], $row);
                $views = $viewsNode && $viewsNode->length ? (int)preg_replace('/[^0-9]/', '', $viewsNode->item(0)->textContent) : 0;

                if (preg_match('/(\d+(?:\.\d+)?)/', $title, $m)) {
                    $number = $m[1];
                } else {
                    $number = (string)(count($chapters) + 1);
                }
                $chapters[] = [
                    'number' => $number,
                    'title'  => $title,
                    'url'    => $link,
                    'date'   => $date,
                    'views'  => $views,
                ];
            }
        }
        // ensure ascending order
        usort($chapters, fn($a, $b) => (float)$a['number'] <=> (float)$b['number']);
        return $chapters;
    }

    public function fetchChapterImages(string $chapterUrl): array
    {
        $this->validateUrl($chapterUrl);
        $html = $this->fetchHtml($chapterUrl);
        $xp = $this->makeXPath($html);
        $nodes = $xp->query($this->config['selectors']['image']);
        $images = [];
        if ($nodes) {
            foreach ($nodes as $n) {
                $src = trim($n->nodeValue);
                if ($src !== '') $images[] = $this->absoluteUrl($src, $chapterUrl);
            }
        }
        return $images;
    }

    public function downloadImage(string $url, string $destPath): bool
    {
        // $destPath may already include extension; strip it for ImageDownloader
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $destPath);
        $result = $this->downloader->download($url, $base);
        return $result !== false;
    }

    /**
     * Full import: comic metadata + cover + every chapter + every image.
     * Returns ['comic_id' => int, 'chapters' => int, 'images' => int, 'errors' => []].
     */
    public function importFullComic(string $url, ?callable $progressCallback = null): array
    {
        $this->log('info', $url, 'Mulai import komik');
        $meta = $this->fetchComicMetadata($url);

        if (empty($meta['title'])) {
            throw new \RuntimeException('Gagal mengambil judul komik dari ' . $url);
        }

        // Comic upsert
        $existing = Database::fetch('SELECT * FROM comics WHERE source_url = ?', [$url]);
        $slug = $existing ? $existing['slug'] : Comic::uniqueSlug($meta['title']);

        $coverPath = null;
        if (!empty($meta['cover_url'])) {
            $base = STORAGE_PATH . '/covers/' . $slug;
            $saved = $this->downloader->download($meta['cover_url'], $base, $url);
            if ($saved) {
                $coverPath = '/storage/covers/' . basename($saved);
            } else {
                $this->log('warning', $meta['cover_url'], 'Gagal unduh cover');
            }
        }

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
            'views'      => $meta['views'] ?? 0,
            'source_url' => $url,
        ];
        if ($coverPath) $data['cover_image'] = $coverPath;

        if ($existing) {
            Database::update('comics', $data, 'id = :id', ['id' => $existing['id']]);
            $comicId = (int)$existing['id'];
        } else {
            $comicId = Database::insert('comics', $data);
        }

        // Genres
        $genreIds = [];
        foreach ($meta['genres'] as $gname) {
            $genreIds[] = Genre::findOrCreate($gname);
        }
        if ($genreIds) Comic::syncGenres($comicId, $genreIds);

        // Chapters
        $chapters = $this->fetchChapterList($url);
        $totalChapters = count($chapters);
        $totalImages = 0;
        $errors = [];

        if ($progressCallback) $progressCallback(0, $totalChapters, "0 dari $totalChapters chapter");

        foreach ($chapters as $i => $ch) {
            try {
                $this->sleepDelay();
                $imgCount = $this->importChapter($comicId, $slug, $ch);
                $totalImages += $imgCount;
            } catch (\Throwable $e) {
                $errors[] = $ch['url'] . ' :: ' . $e->getMessage();
                $this->log('error', $ch['url'], $e->getMessage());
            }
            if ($progressCallback) $progressCallback($i + 1, $totalChapters, ($i + 1) . " dari $totalChapters chapter");
        }

        $this->log('success', $url, "Selesai: $totalChapters chapter, $totalImages gambar");

        return [
            'comic_id' => $comicId,
            'chapters' => $totalChapters,
            'images'   => $totalImages,
            'errors'   => $errors,
        ];
    }

    /**
     * Import a single chapter (also used for "import by chapter URL").
     */
    public function importSingleChapter(string $chapterUrl, ?int $forceComicId = null): array
    {
        $this->validateUrl($chapterUrl);

        // Try to detect parent comic URL by stripping last path segment
        $parsed = parse_url($chapterUrl);
        $path = trim($parsed['path'] ?? '', '/');
        $segments = explode('/', $path);
        // crude: parent is one level up
        array_pop($segments);
        $parentPath = '/' . implode('/', $segments);
        $parentUrl  = $parsed['scheme'] . '://' . $parsed['host'] . $parentPath;

        $comicId = $forceComicId;
        if (!$comicId) {
            $existing = Database::fetch('SELECT id FROM comics WHERE source_url = ?', [$parentUrl]);
            if ($existing) {
                $comicId = (int)$existing['id'];
            } else {
                // fallback: import comic metadata only
                $meta = $this->fetchComicMetadata($parentUrl);
                $slug = Comic::uniqueSlug($meta['title']);
                $comicId = Database::insert('comics', [
                    'title' => $meta['title'], 'slug' => $slug,
                    'type' => $meta['type'], 'status' => $meta['status'],
                    'synopsis' => $meta['synopsis'], 'source_url' => $parentUrl,
                ]);
            }
        }

        $comic = Comic::find($comicId);
        $title = basename(rtrim($chapterUrl, '/'));
        $number = preg_match('/(\d+(?:\.\d+)?)/', $title, $m) ? $m[1] : '0';

        $imgCount = $this->importChapter($comicId, $comic['slug'], [
            'number' => $number,
            'title'  => $title,
            'url'    => $chapterUrl,
            'date'   => '',
            'views'  => 0,
        ]);
        return ['comic_id' => $comicId, 'images' => $imgCount];
    }

    // ====================== Internals ======================

    private function importChapter(int $comicId, string $comicSlug, array $ch): int
    {
        $chSlug = SlugGenerator::make('chapter-' . $ch['number']);
        $existing = Database::fetch('SELECT * FROM chapters WHERE comic_id = ? AND slug = ?', [$comicId, $chSlug]);
        if ($existing) {
            $chapterId = (int)$existing['id'];
            // Incremental: jika sudah ada gambar, skip (cepat). Set scraper_force_refetch=1 untuk override.
            $existingCount = (int)Database::fetch(
                'SELECT COUNT(*) AS c FROM chapter_images WHERE chapter_id = ?',
                [$chapterId]
            )['c'];
            $force = (int)Setting::get('scraper_force_refetch', '0') === 1;
            if ($existingCount > 0 && !$force) {
                $this->log('info', $ch['url'], "Chapter {$ch['number']} dilewati (sudah $existingCount gambar)");
                return 0;
            }
            // re-fetch images: clear old refs (file remains)
            Chapter::deleteImages($chapterId);
        } else {
            $chSlug = Chapter::uniqueSlug($comicId, 'chapter-' . $ch['number']);
            $chapterId = Database::insert('chapters', [
                'comic_id'       => $comicId,
                'chapter_number' => $ch['number'],
                'title'          => $ch['title'] ?: ('Chapter ' . $ch['number']),
                'slug'           => $chSlug,
                'source_url'     => $ch['url'],
                'views'          => $ch['views'] ?? 0,
            ]);
        }

        $images = $this->fetchChapterImages($ch['url']);
        $dir = STORAGE_PATH . '/comics/' . $comicSlug . '/' . $chSlug;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        // Build batch download list
        $batch = [];
        foreach ($images as $idx => $imgUrl) {
            $base = $dir . '/page-' . str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT);
            $batch[$idx] = ['url' => $imgUrl, 'dest' => $base, 'referer' => $ch['url']];
        }

        $concurrency = max(1, (int)Setting::get('scraper_concurrency', '8'));
        $saved = $this->downloader->downloadBatch($batch, $concurrency);

        $count = 0;
        foreach ($saved as $idx => $path) {
            if ($path) {
                $relative = '/storage/comics/' . $comicSlug . '/' . $chSlug . '/' . basename($path);
                Database::insert('chapter_images', [
                    'chapter_id' => $chapterId,
                    'image_path' => $relative,
                    'image_url'  => $batch[$idx]['url'],
                    'sort_order' => $idx + 1,
                ]);
                $count++;
            } else {
                $this->log('warning', $batch[$idx]['url'], 'Gagal unduh gambar chapter ' . $ch['number']);
            }
        }
        $this->log('success', $ch['url'], "Chapter {$ch['number']} : $count gambar");
        return $count;
    }

    private function validateUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) throw new \InvalidArgumentException('URL tidak valid: ' . $url);
        $allowed = false;
        foreach ($this->config['whitelist'] as $w) {
            if ($host === $w || str_ends_with($host, '.' . $w)) { $allowed = true; break; }
        }
        if (!$allowed) {
            throw new \RuntimeException('Domain tidak diizinkan: ' . $host);
        }
    }

    private function fetchHtml(string $url): string
    {
        $cacheKey = $this->cacheDir . '/' . md5($url) . '.html';
        if (is_file($cacheKey) && (time() - filemtime($cacheKey)) < 3600) {
            return (string)file_get_contents($cacheKey);
        }

        $attempt = 0; $lastErr = '';
        while ($attempt < $this->config['retries']) {
            $attempt++;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->config['timeout'],
                CURLOPT_USERAGENT      => $this->config['user_agent'],
                CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body !== false && $code >= 200 && $code < 400) {
                file_put_contents($cacheKey, $body);
                return (string)$body;
            }
            $lastErr = "HTTP $code $err";
            usleep((int)(pow(2, $attempt) * 200000));
        }
        throw new \RuntimeException("Gagal fetch $url: $lastErr");
    }

    private function makeXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($dom);
    }

    private function xText(DOMXPath $xp, string $expr): ?string
    {
        $nodes = $xp->query($expr);
        if (!$nodes || $nodes->length === 0) return null;
        return trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
    }

    private function xValue(DOMXPath $xp, string $expr): ?string
    {
        $nodes = $xp->query($expr);
        if (!$nodes || $nodes->length === 0) return null;
        return trim($nodes->item(0)->nodeValue);
    }

    private function absoluteUrl(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) return $href;
        if (str_starts_with($href, '//')) return 'https:' . $href;
        $b = parse_url($base);
        if (str_starts_with($href, '/')) return $b['scheme'] . '://' . $b['host'] . $href;
        $path = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $b['scheme'] . '://' . $b['host'] . $path . $href;
    }

    private function normalizeType(?string $type): string
    {
        $t = strtolower((string)$type);
        if (str_contains($t, 'manhwa')) return 'Manhwa';
        if (str_contains($t, 'manhua')) return 'Manhua';
        if (str_contains($t, 'manga'))  return 'Manga';
        return 'Manga';
    }

    private function normalizeStatus(?string $s): string
    {
        $t = strtolower((string)$s);
        if (str_contains($t, 'tamat') || str_contains($t, 'complete') || str_contains($t, 'end')) return 'Completed';
        if (str_contains($t, 'hiatus')) return 'Hiatus';
        return 'Ongoing';
    }

    private function sleepDelay(): void
    {
        $d = (float)$this->config['delay'];
        if ($d > 0) usleep((int)($d * 1_000_000));
    }

    private function log(string $status, string $url, string $message): void
    {
        try {
            Database::insert('import_logs', [
                'source'     => 'komiku',
                'source_url' => $url,
                'status'     => $status,
                'message'    => $message,
            ]);
        } catch (\Throwable $e) { /* ignore */ }
    }
}
