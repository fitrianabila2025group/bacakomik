<?php
/**
 * Global helpers loaded by index.php before dispatching.
 */

if (!function_exists('ad')) {
    /**
     * Render an ad slot by key. Echoes nothing if slot is inactive/empty.
     */
    function ad(string $slot): void
    {
        $row = \App\Database::fetch(
            'SELECT ad_code, is_active FROM ad_slots WHERE slot_key = ?',
            [$slot]
        );
        if ($row && $row['is_active'] && trim((string)$row['ad_code']) !== '') {
            echo '<div class="ad-slot ad-' . htmlspecialchars($slot) . '">' . $row['ad_code'] . '</div>';
        }
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('imgproxy')) {
    /**
     * Wrap an image URL so it is served by /img.php on the shared host
     * (which is NOT blocked by komiku/Cloudflare like Railway IPs are).
     *
     * Accepts any of:
     *   - empty / placeholder local path  -> returned as-is
     *   - existing /img.php?u=... URL     -> returned as-is
     *   - Railway https://(host)/proxy?url=... -> unwrapped & rewrapped to /img.php
     *   - raw https://...komiku.org/...        -> wrapped in /img.php
     */
    function imgproxy(?string $url, ?string $referer = null): string
    {
        $url = (string)$url;
        if ($url === '') return '';
        // local path or already wrapped
        if ($url[0] === '/' || str_contains($url, '/img.php?')) return $url;
        if (!preg_match('#^https?://#i', $url)) return $url;

        // Unwrap Railway /proxy?url=...&referer=...
        if (str_contains($url, '/proxy?')) {
            $qs = parse_url($url, PHP_URL_QUERY);
            if ($qs) {
                parse_str($qs, $inner);
                if (!empty($inner['url'])) {
                    $url = $inner['url'];
                    if (!$referer && !empty($inner['referer'])) $referer = $inner['referer'];
                }
            }
        }

        $q = ['u' => $url];
        if ($referer) $q['r'] = $referer;
        return '/img.php?' . http_build_query($q);
    }
}
