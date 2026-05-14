<?php
namespace App\Services\Scraper;

use App\Services\ImageDownloader;

/**
 * Scraper that proxies HTML extraction to the external Python service
 * (see /scraper-service). Image downloads are routed through the service's
 * /proxy endpoint so cover & chapter images go through the same Cloudflare
 * bypass as the HTML scrape.
 *
 * Inherits {@see KomikuScraper::importFullComic()} / importChapter() so the
 * import-and-store-to-DB pipeline stays identical to the legacy scraper.
 */
class ApiScraper extends KomikuScraper
{
    protected ApiClient $api;

    public function __construct(array $config = [], ?ApiClient $api = null)
    {
        parent::__construct($config);
        $this->api = $api ?? new ApiClient();
        // Make the image downloader send the API key on every request so it
        // can hit /proxy successfully.
        $this->downloader = new ImageDownloader(
            (string)$this->config['user_agent'],
            (int)$this->config['timeout'],
            (int)$this->config['retries']
        );
        $this->downloader->setExtraHeaders([$this->api->authHeader()]);
    }

    // ====================== Public API overrides ======================

    public function fetchComicMetadata(string $url): array
    {
        $meta = $this->api->post('/scrape/comic', ['url' => $url]);
        // Route the cover through the proxy so /storage/covers/* still gets the file.
        if (!empty($meta['cover_url'])) {
            $meta['cover_url'] = $this->api->proxyUrl($meta['cover_url'], $url);
        }
        // Normalise expected shape.
        $meta += ['alt_title' => null, 'author' => null, 'artist' => null,
                  'rating' => 0, 'views' => 0, 'genres' => [], 'synopsis' => null];
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
        // Wrap each image URL through /proxy so PHP's downloader can hit it
        // with the API key header (CF bypass + hotlink protection covered).
        $out = [];
        foreach ($images as $img) {
            $out[] = $this->api->proxyUrl($img, $chapterUrl);
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
}
