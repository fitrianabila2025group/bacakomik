<?php
/**
 * Standalone image proxy served by the *shared host*.
 *
 * Why this exists:
 *   Railway/AWS/GCP datacenter IPs are blacklisted by komiku/Cloudflare ->
 *   /proxy on the scraper service returns 403. The shared hosting (cPanel,
 *   regular ISP/IDC ranges) is *not* blocked, so we fetch the image directly
 *   from PHP via cURL.
 *
 * URL pattern accepted:
 *   /img.php?u=<url|base64>&r=<referer|base64>
 *
 * Also accepts a Railway /proxy?url=...&referer=... URL passed as `u=` --
 * it transparently unwraps the inner `url`/`referer` so existing rows in
 * chapter_images / comics.cover_image keep working without DB migration.
 *
 * Hard-cached on disk (storage/cache/img/<md5>.bin) for 30 days, served
 * with browser Cache-Control: public, immutable so each image is hit at
 * most once per server.
 */

declare(strict_types=1);

// ====== config ======
$WHITELIST = [
    'komiku.org', 'komiku.id', 'mangaku.top',
];
$CACHE_DIR = __DIR__ . '/../storage/cache/img';
$CACHE_AGE = 60 * 60 * 24 * 30;            // 30 days browser cache
$TIMEOUT_S = 25;
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

// ====== helpers ======
function img_fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

function img_b64decode(string $s): ?string {
    // accept either raw url-encoded URL or base64url-encoded URL
    if (preg_match('#^https?://#i', $s)) return $s;
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $d = base64_decode(strtr($s, '-_', '+/'), true);
    return $d !== false ? $d : null;
}

function img_host_allowed(string $url, array $whitelist): bool {
    $h = parse_url($url, PHP_URL_HOST) ?: '';
    foreach ($whitelist as $w) {
        if ($h === $w || str_ends_with($h, '.' . $w)) return true;
    }
    return false;
}

// ====== unwrap input ======
$rawU = $_GET['u'] ?? $_GET['url'] ?? '';
$rawR = $_GET['r'] ?? $_GET['referer'] ?? '';
if ($rawU === '') img_fail(400, 'missing u');

$u = img_b64decode($rawU);
$r = $rawR !== '' ? (img_b64decode($rawR) ?? '') : '';
if (!$u) img_fail(400, 'bad u');

// Unwrap a nested Railway /proxy URL (so existing DB rows still work).
if (preg_match('#/proxy\?#', $u)) {
    $qs = parse_url($u, PHP_URL_QUERY);
    if ($qs) {
        parse_str($qs, $inner);
        if (!empty($inner['url'])) {
            $u = $inner['url'];
            if (empty($r) && !empty($inner['referer'])) $r = $inner['referer'];
        }
    }
}

if (!preg_match('#^https?://#i', $u)) img_fail(400, 'not http(s)');
if (!img_host_allowed($u, $WHITELIST)) img_fail(403, 'host not allowed');

// Default referer = root of the image host's parent domain.
if ($r === '' || !preg_match('#^https?://#i', $r)) {
    $host = parse_url($u, PHP_URL_HOST) ?: '';
    $parts = explode('.', $host);
    $root = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
    $r = "https://{$root}/";
}

// ====== disk cache ======
@is_dir($CACHE_DIR) || @mkdir($CACHE_DIR, 0775, true);
$key = md5($u);
$bin = "{$CACHE_DIR}/{$key}.bin";
$ct  = "{$CACHE_DIR}/{$key}.ct";
if (is_file($bin) && is_file($ct) && filesize($bin) > 0) {
    $ctype = trim((string)@file_get_contents($ct)) ?: 'application/octet-stream';
    header("Content-Type: {$ctype}");
    header("Cache-Control: public, max-age={$CACHE_AGE}, immutable");
    header('Access-Control-Allow-Origin: *');
    header('X-Img-Cache: HIT');
    header('Content-Length: ' . filesize($bin));
    readfile($bin);
    exit;
}

// ====== upstream fetch ======
function img_fetch(string $url, string $referer, int $timeout, string $ua): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,id;q=0.8',
            'Referer: ' . $referer,
            'Sec-Fetch-Dest: image',
            'Sec-Fetch-Mode: no-cors',
            'Sec-Fetch-Site: cross-site',
        ],
    ]);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
    $err   = curl_error($ch);
    curl_close($ch);
    return [$body, $code, $ctype, $err];
}

[$body, $code, $ctype, $err] = img_fetch($u, $r, $TIMEOUT_S, $UA);

// On 403/429: warm up image-host root to set hotlink cookie, then retry once.
if (in_array($code, [401, 403, 429], true)) {
    $imgHost = parse_url($u, PHP_URL_HOST) ?: '';
    if ($imgHost !== '') {
        @img_fetch("https://{$imgHost}/", $r, $TIMEOUT_S, $UA);
    }
    @img_fetch($r, $r, $TIMEOUT_S, $UA);
    [$body, $code, $ctype, $err] = img_fetch($u, $r, $TIMEOUT_S, $UA);
}

if ($code < 200 || $code >= 300 || $body === false || $body === '') {
    error_log("img.php failed url={$u} code={$code} err={$err}");
    img_fail(502, "upstream {$code}");
}

// ====== persist + emit ======
@file_put_contents($bin, $body);
@file_put_contents($ct, $ctype);

header("Content-Type: {$ctype}");
header("Cache-Control: public, max-age={$CACHE_AGE}, immutable");
header('Access-Control-Allow-Origin: *');
header('X-Img-Cache: MISS');
header('Content-Length: ' . strlen($body));
echo $body;
