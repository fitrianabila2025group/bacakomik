<?php
namespace App\Services\Scraper;

use App\Models\Setting;

/**
 * Thin HTTP client for the external Python scraper service
 * (see /scraper-service in this repo).
 *
 * Reads URL + key from settings table:
 *   scraper_api_url    e.g. https://xxxx.up.railway.app
 *   scraper_api_key    matches SCRAPER_API_KEY on the service
 *   scraper_use_api    "1" to enable
 */
class ApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim((string)($baseUrl ?? Setting::get('scraper_api_url', '')), '/');
        $this->apiKey  = (string)($apiKey ?? Setting::get('scraper_api_key', ''));
        $this->timeout = $timeout ?? (int)Setting::get('scraper_api_timeout', '120');
        if ($this->timeout <= 0) $this->timeout = 120;
    }

    public static function isEnabled(): bool
    {
        return (string)Setting::get('scraper_use_api', '0') === '1'
            && trim((string)Setting::get('scraper_api_url', '')) !== ''
            && trim((string)Setting::get('scraper_api_key', '')) !== '';
    }

    public function baseUrl(): string { return $this->baseUrl; }
    public function apiKey(): string  { return $this->apiKey;  }

    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query) $url .= '?' . http_build_query($query);
        return $this->request('GET', $url, null);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Build a /proxy URL that the PHP image downloader can hit directly
     * (with the X-API-Key header) to fetch a cover/chapter image through
     * the same Cloudflare-bypassing tunnel.
     */
    public function proxyUrl(string $imageUrl, ?string $referer = null): string
    {
        $q = ['url' => $imageUrl];
        if ($referer) $q['referer'] = $referer;
        return $this->baseUrl . '/proxy?' . http_build_query($q);
    }

    public function authHeader(): string
    {
        return 'X-API-Key: ' . $this->apiKey;
    }

    public function ping(): array
    {
        return $this->request('GET', $this->baseUrl . '/health', null, /*auth*/ false);
    }

    // ---- internals ----
    private function request(string $method, string $url, ?string $body, bool $auth = true): array
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('scraper_api_url belum diset');
        }
        $headers = ['Accept: application/json'];
        if ($auth) $headers[] = $this->authHeader();
        if ($body !== null) $headers[] = 'Content-Type: application/json';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException("API $method $url gagal: $err");
        }
        $decoded = json_decode((string)$resp, true);
        if ($code >= 400) {
            $msg = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : substr((string)$resp, 0, 300);
            throw new \RuntimeException("API $method $url HTTP $code: $msg");
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException("API $method $url: response bukan JSON");
        }
        return $decoded;
    }
}
