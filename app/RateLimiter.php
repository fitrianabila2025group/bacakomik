<?php
namespace App;

/**
 * Simple file-based rate limiter (no Redis / no DB writes).
 * Tracks N hits per key in a sliding window.
 *
 * Usage:
 *   if (RateLimiter::tooMany('login:'.$ip, 5, 300)) { die('Too many attempts'); }
 *   RateLimiter::hit('login:'.$ip);
 */
class RateLimiter
{
    private static function dir(): string
    {
        $d = STORAGE_PATH . '/cache/ratelimit';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.json';
    }

    /** @return array{0:int,1:int} [hits, oldest_ts] */
    private static function load(string $key, int $window): array
    {
        $f = self::file($key);
        if (!is_file($f)) return [0, time()];
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || !isset($j['hits'])) return [0, time()];
        $cutoff = time() - $window;
        $hits = array_values(array_filter((array)$j['hits'], fn($t) => $t >= $cutoff));
        return [count($hits), $hits[0] ?? time()];
    }

    public static function hit(string $key, int $window = 300): void
    {
        $f = self::file($key);
        $j = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        $cutoff = time() - $window;
        $hits = array_values(array_filter((array)($j['hits'] ?? []), fn($t) => $t >= $cutoff));
        $hits[] = time();
        @file_put_contents($f, json_encode(['hits' => $hits]), LOCK_EX);
    }

    public static function tooMany(string $key, int $max, int $window = 300): bool
    {
        [$hits] = self::load($key, $window);
        return $hits >= $max;
    }

    public static function clear(string $key): void
    {
        @unlink(self::file($key));
    }
}
