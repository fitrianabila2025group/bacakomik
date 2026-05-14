<?php
namespace App;

use App\Auth;
use App\Csrf;
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
        if (Setting::get('comments_enabled', '0') !== '1') return false;
        // Need either dedicated comments_api_url OR scraper_api_url to fall back to.
        $api = trim((string)Setting::get('comments_api_url', '')) ?: trim((string)Setting::get('scraper_api_url', ''));
        $key = trim((string)Setting::get('scraper_api_key', ''));
        return $api !== '' && $key !== '';
    }

    public static function enabledFor(string $context): bool
    {
        // $context = 'comic'|'chapter'
        return self::enabled() && Setting::get('comments_on_' . $context, '1') === '1';
    }

    /**
     * Render the comments widget. Browser talks to /api/comments/* on this
     * shared host; PHP forwards to Railway with the scraper API key.
     *
     * @param string $context  'comic' | 'chapter'
     * @param string $target   "comic:slug" or "chapter:123"
     */
    public static function render(string $context, string $target): string
    {
        if (!self::enabledFor($context)) return '';
        $user = Auth::user();
        $cfg = [
            'target'    => $target,
            'csrf'      => Csrf::token(),
            'me'        => $user ? ['id'=>(int)$user['id'],'name'=>$user['name'],'role'=>$user['role']] : null,
            'login_url' => '/login',
        ];
        $json = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        return '<section id="bk-comments" class="comments-section" data-cfg="' . $json . '"></section>'
            . '<script src="/assets/js/comments.js?v=' . (@filemtime(BASE_PATH . '/public/assets/js/comments.js') ?: time()) . '" defer></script>';
    }
}
