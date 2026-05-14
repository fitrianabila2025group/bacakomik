<?php
namespace App;

use App\Models\Setting;

/**
 * Comments helper.
 *
 * - Issues short-lived HMAC tokens identifying the current PHP-session user
 *   to the FastAPI /comments service on Railway.
 * - Renders the widget mount-point + bootstrap config consumed by
 *   /assets/js/comments.js.
 *
 * The shared host never stores comments; they live on the Railway service.
 */
class Comments
{
    public static function enabled(): bool
    {
        return Setting::get('comments_enabled', '0') === '1'
            && trim((string)Setting::get('comments_api_url', '')) !== ''
            && trim((string)Setting::get('comments_hmac_secret', '')) !== '';
    }

    public static function enabledFor(string $context): bool
    {
        // $context = 'comic'|'chapter'
        return self::enabled() && Setting::get('comments_on_' . $context, '1') === '1';
    }

    public static function apiUrl(): string
    {
        return rtrim((string)Setting::get('comments_api_url', ''), '/');
    }

    /**
     * Sign a JSON payload using HMAC-SHA256 with the shared secret.
     * Returns "<base64url(json)>.<hex_sig>" or '' if no secret/user.
     */
    public static function userToken(?array $user = null, int $ttl = 3600): string
    {
        $u = $user ?? Auth::user();
        if (!$u) return '';
        $secret = (string)Setting::get('comments_hmac_secret', '');
        if ($secret === '') return '';
        $payload = [
            'uid'  => (int)$u['id'],
            'name' => (string)$u['name'],
            'role' => (($u['role'] ?? 'user') === 'admin') ? 'admin' : 'user',
            'exp'  => time() + max(60, $ttl),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig  = hash_hmac('sha256', $b64, $secret);
        return $b64 . '.' . $sig;
    }

    /**
     * Render the comments widget mount + bootstrap. Echoes nothing if disabled.
     *
     * @param string $context  'comic' | 'chapter'
     * @param string $target   "comic:slug" or "chapter:123"
     */
    public static function render(string $context, string $target): string
    {
        if (!self::enabledFor($context)) return '';
        $user  = Auth::user();
        $cfg = [
            'api'         => self::apiUrl(),
            'target'      => $target,
            'token'       => self::userToken($user),
            'me'          => $user ? ['id'=>(int)$user['id'],'name'=>$user['name'],'role'=>$user['role']] : null,
            'login_url'   => '/login',
            'guest_ok'    => Setting::get('comments_guest_allowed', '0') === '1',
        ];
        $json = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        return '<section id="bk-comments" class="comments-section" data-cfg="' . $json . '"></section>'
            . '<script src="/assets/js/comments.js" defer></script>';
    }
}
