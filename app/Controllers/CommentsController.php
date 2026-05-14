<?php
namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Models\Setting;

/**
 * Browser-facing proxy for the Railway comments service.
 *
 * Browser  --(/api/comments/* + CSRF + session cookie)-->  PHP (this controller)
 *          --(X-API-Key header to scraper_api_url)----->  Railway /comments/*
 *
 * Same-origin from the browser's perspective so no CORS, no cross-domain
 * tokens, no expiry headaches. PHP injects the authenticated user identity
 * into the upstream payload.
 */
class CommentsController extends Controller
{
    private function api(): array
    {
        $base = trim((string)Setting::get('comments_api_url', '')) ?: trim((string)Setting::get('scraper_api_url', ''));
        $key  = trim((string)Setting::get('scraper_api_key', ''));
        if ($base === '' || $key === '') {
            echo $this->json(['ok' => false, 'detail' => 'Comments API not configured'], 503);
            exit;
        }
        return [rtrim($base, '/'), $key];
    }

    private function actor(): array
    {
        $u = Auth::user();
        return [
            'actor_id'   => $u ? (int)$u['id'] : 0,
            'actor_name' => $u ? (string)$u['name'] : '',
            'actor_role' => ($u && ($u['role'] ?? '') === 'admin') ? 'admin' : 'user',
        ];
    }

    /** GET /api/comments?target=...&sort=...&page=... */
    public function index(): string
    {
        [$base, $key] = $this->api();
        $a = $this->actor();
        $qs = http_build_query([
            'target'   => (string)($_GET['target']  ?? ''),
            'sort'     => (string)($_GET['sort']    ?? 'top'),
            'page'     => (int)   ($_GET['page']    ?? 1),
            'per_page' => (int)   ($_GET['per_page'] ?? 15),
            'actor_id' => $a['actor_id'],
        ]);
        return $this->forward('GET', $base . '/comments?' . $qs, $key);
    }

    /** POST /api/comments  body: {target, parent_id?, text} */
    public function store(): string
    {
        Csrf::check();
        if (!Auth::check()) { return $this->json(['ok' => false, 'detail' => 'login required'], 401); }
        [$base, $key] = $this->api();
        $body = $this->jsonBody();
        $payload = array_merge($this->actor(), [
            'target'    => (string)($body['target']    ?? ''),
            'parent_id' => isset($body['parent_id']) && $body['parent_id'] ? (int)$body['parent_id'] : null,
            'text'      => (string)($body['text']      ?? ''),
        ]);
        return $this->forward('POST', $base . '/comments', $key, $payload);
    }

    /** POST /api/comments/{id}/react  body: {type} */
    public function react(int $id): string
    {
        Csrf::check();
        if (!Auth::check()) { return $this->json(['ok' => false, 'detail' => 'login required'], 401); }
        [$base, $key] = $this->api();
        $body = $this->jsonBody();
        $payload = array_merge($this->actor(), [
            'type' => (string)($body['type'] ?? ''),
        ]);
        return $this->forward('POST', $base . '/comments/' . $id . '/react', $key, $payload);
    }

    /** POST /api/comments/{id}/delete  (use POST so CSRF works easily) */
    public function delete(int $id): string
    {
        Csrf::check();
        if (!Auth::check()) { return $this->json(['ok' => false, 'detail' => 'login required'], 401); }
        [$base, $key] = $this->api();
        return $this->forward('DELETE', $base . '/comments/' . $id, $key, $this->actor());
    }

    /** POST /api/comments/{id}/pin  (admin) */
    public function pin(int $id): string
    {
        Csrf::check();
        if (!Auth::isAdmin()) { return $this->json(['ok' => false, 'detail' => 'admin only'], 403); }
        [$base, $key] = $this->api();
        return $this->forward('POST', $base . '/comments/' . $id . '/pin', $key, $this->actor());
    }

    // ---------- helpers ----------

    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    private function forward(string $method, string $url, string $key, ?array $body = null): string
    {
        $ch = curl_init($url);
        $headers = ['X-API-Key: ' . $key, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return $this->json(['ok' => false, 'detail' => 'upstream error: ' . $err], 502);
        }
        // Pass-through status & body verbatim.
        http_response_code($status ?: 502);
        header('Content-Type: application/json; charset=utf-8');
        return (string)$resp;
    }
}
