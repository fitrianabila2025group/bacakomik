<?php
namespace App\Services;

/**
 * Streamed image downloader with MIME validation.
 */
class ImageDownloader
{
    private array $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** @var string[] Extra HTTP headers sent on every request (e.g. X-API-Key for proxy). */
    private array $extraHeaders = [];

    public function __construct(
        private string $userAgent = 'BacaKomikBot/1.0',
        private int $timeout = 30,
        private int $maxRetries = 3
    ) {}

    /** Set additional headers (replaces previous list). */
    public function setExtraHeaders(array $headers): void
    {
        $this->extraHeaders = array_values($headers);
    }

    /**
     * Download an image to $destPathNoExt. Returns full saved path or false on failure.
     * The extension is detected from MIME type and appended to $destPathNoExt.
     */
    public function download(string $url, string $destPathNoExt, ?string $referer = null): string|false
    {
        $dir = dirname($destPathNoExt);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $attempt = 0; $lastErr = '';
        while ($attempt < $this->maxRetries) {
            $attempt++;
            $ch = curl_init();
            $tmpFile = $destPathNoExt . '.tmp';
            $fp = fopen($tmpFile, 'wb');
            if (!$fp) return false;
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => array_merge(array_filter([
                    $referer ? 'Referer: ' . $referer : null,
                    'Accept: image/*,*/*;q=0.8',
                ]), $this->extraHeaders),
                CURLOPT_FAILONERROR    => true,
            ]);
            $ok = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($ok && $code >= 200 && $code < 400 && filesize($tmpFile) > 0) {
                $mime = function_exists('mime_content_type') ? mime_content_type($tmpFile) : null;
                if (!$mime || !isset($this->allowedMime[$mime])) {
                    @unlink($tmpFile);
                    $lastErr = "Invalid MIME: $mime";
                    return false;
                }
                $ext = $this->allowedMime[$mime];
                $finalPath = $destPathNoExt . '.' . $ext;
                rename($tmpFile, $finalPath);
                return $finalPath;
            }

            @unlink($tmpFile);
            $lastErr = "HTTP $code: $err";
            // exponential backoff
            usleep((int)(pow(2, $attempt) * 200000));
        }
        return false;
    }

    /**
     * Parallel download many images using curl_multi.
     *
     * @param array<int,array{url:string,dest:string,referer?:?string}> $items
     *        - url: source image URL
     *        - dest: destination path WITHOUT extension
     *        - referer: optional referer header
     * @param int $concurrency Max simultaneous transfers
     * @return array<int,string|false> Map keyed identically to $items: saved file path or false
     */
    public function downloadBatch(array $items, int $concurrency = 8): array
    {
        $results = [];
        if (!$items) return $results;

        $keys = array_keys($items);
        $cursor = 0;
        $total = count($keys);
        $mh = curl_multi_init();
        // Cap concurrency to existing item count
        $concurrency = max(1, min($concurrency, $total));

        $active = []; // handleId => ['key'=>k, 'ch'=>ch, 'fp'=>fp, 'tmp'=>path, 'attempt'=>n]

        $startOne = function (int $key) use (&$active, $items, $mh) {
            $item = $items[$key];
            $url = $item['url'];
            $dest = $item['dest'];
            $referer = $item['referer'] ?? null;

            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $tmp = $dest . '.tmp';
            $fp = fopen($tmp, 'wb');
            if (!$fp) {
                $active['__failed_' . $key] = ['key' => $key, 'ch' => null, 'fp' => null, 'tmp' => $tmp, 'failed' => true];
                return;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => array_merge(array_filter([
                    $referer ? 'Referer: ' . $referer : null,
                    'Accept: image/*,*/*;q=0.8',
                ]), $this->extraHeaders),
                CURLOPT_FAILONERROR    => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = ['key' => $key, 'ch' => $ch, 'fp' => $fp, 'tmp' => $tmp, 'failed' => false];
        };

        // Prime initial batch
        while ($cursor < $total && count($active) < $concurrency) {
            $startOne($keys[$cursor++]);
        }

        do {
            // Process synchronous failed-to-open entries
            foreach ($active as $hid => $info) {
                if (!empty($info['failed']) && $info['ch'] === null) {
                    $results[$info['key']] = false;
                    unset($active[$hid]);
                }
            }

            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);

            while ($done = curl_multi_info_read($mh)) {
                $ch = $done['handle'];
                $hid = (int)$ch;
                if (!isset($active[$hid])) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }
                $info = $active[$hid];
                $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $errMsg = curl_error($ch);
                $okCurl = ($done['result'] === CURLE_OK);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                fclose($info['fp']);

                $saved = false;
                if ($okCurl && $code >= 200 && $code < 400 && filesize($info['tmp']) > 0) {
                    $mime = function_exists('mime_content_type') ? mime_content_type($info['tmp']) : null;
                    if ($mime && isset($this->allowedMime[$mime])) {
                        $finalPath = $items[$info['key']]['dest'] . '.' . $this->allowedMime[$mime];
                        rename($info['tmp'], $finalPath);
                        $saved = $finalPath;
                    }
                }
                if ($saved === false) @unlink($info['tmp']);
                $results[$info['key']] = $saved;
                unset($active[$hid]);

                // Refill
                if ($cursor < $total) {
                    $startOne($keys[$cursor++]);
                }
            }
        } while ($active || $cursor < $total);

        curl_multi_close($mh);

        // Preserve original key order
        $ordered = [];
        foreach ($keys as $k) $ordered[$k] = $results[$k] ?? false;
        return $ordered;
    }
}
