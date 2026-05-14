<?php
namespace App;

use App\Models\Setting;

/**
 * Multi-provider CAPTCHA helper.
 * Supports: Cloudflare Turnstile, Google reCAPTCHA v2, Google reCAPTCHA v3, hCaptcha.
 *
 * Settings keys (admin/settings):
 *   captcha_provider     : none|turnstile|recaptcha_v2|recaptcha_v3|hcaptcha
 *   captcha_site_key     : public key
 *   captcha_secret_key   : private key (server verification)
 *   captcha_on_register  : "1" to enforce on /register
 *   captcha_on_login     : "1" to enforce on /login (recommended after N failed attempts)
 *   captcha_on_comment   : "1" to enforce on POST /comments
 *   captcha_score_min    : (recaptcha_v3 only) minimum score 0..1, default 0.5
 */
class Captcha
{
    public const PROVIDERS = ['none','turnstile','recaptcha_v2','recaptcha_v3','hcaptcha'];

    public static function provider(): string
    {
        $p = (string)Setting::get('captcha_provider', 'none');
        return in_array($p, self::PROVIDERS, true) ? $p : 'none';
    }

    public static function enabledFor(string $context): bool
    {
        if (self::provider() === 'none') return false;
        if (Setting::get('captcha_site_key', '') === '' || Setting::get('captcha_secret_key', '') === '') return false;
        return Setting::get('captcha_on_' . $context, '0') === '1';
    }

    /** Inline <script> tag(s) that must appear in <head>. Idempotent per request. */
    public static function headScript(): string
    {
        static $emitted = false;
        if ($emitted) return '';
        $emitted = true;
        switch (self::provider()) {
            case 'turnstile':
                return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            case 'recaptcha_v2':
                return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
            case 'recaptcha_v3':
                $sk = htmlspecialchars(Setting::get('captcha_site_key', ''), ENT_QUOTES);
                return '<script src="https://www.google.com/recaptcha/api.js?render=' . $sk . '"></script>';
            case 'hcaptcha':
                return '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>';
        }
        return '';
    }

    /**
     * Widget HTML to inject inside a <form>. Adds the response field automatically.
     * For recaptcha_v3 returns a hidden input + script that requests token on submit.
     */
    public static function widget(string $action = 'submit'): string
    {
        if (self::provider() === 'none') return '';
        $sk = htmlspecialchars(Setting::get('captcha_site_key', ''), ENT_QUOTES);
        $action = preg_replace('/[^a-z0-9_]/i', '', $action) ?: 'submit';
        switch (self::provider()) {
            case 'turnstile':
                return '<div class="cf-turnstile" data-sitekey="' . $sk . '"></div>';
            case 'recaptcha_v2':
                return '<div class="g-recaptcha" data-sitekey="' . $sk . '"></div>';
            case 'recaptcha_v3':
                return '<input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-' . $action . '">'
                    . '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f.querySelector("#g-recaptcha-response-' . $action . '"))return;e.preventDefault();grecaptcha.ready(function(){grecaptcha.execute("' . $sk . '",{action:"' . $action . '"}).then(function(t){f.querySelector("#g-recaptcha-response-' . $action . '").value=t;f.submit();});});},{once:true});</script>';
            case 'hcaptcha':
                return '<div class="h-captcha" data-sitekey="' . $sk . '"></div>';
        }
        return '';
    }

    /**
     * Verify the token submitted by the user. Returns true on success.
     * Returns true automatically if provider is "none" or context is not enforced.
     */
    public static function verify(string $context = 'register'): bool
    {
        if (!self::enabledFor($context)) return true;
        $secret = (string)Setting::get('captcha_secret_key', '');
        if ($secret === '') return false;

        $provider = self::provider();
        $field = match ($provider) {
            'turnstile'    => 'cf-turnstile-response',
            'hcaptcha'     => 'h-captcha-response',
            default        => 'g-recaptcha-response',
        };
        $token = trim((string)($_POST[$field] ?? ''));
        if ($token === '') return false;

        $endpoint = match ($provider) {
            'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'hcaptcha'     => 'https://hcaptcha.com/siteverify',
            default        => 'https://www.google.com/recaptcha/api/siteverify',
        };

        $payload = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        if ($err || !$body) return false;

        $j = json_decode((string)$body, true);
        if (!is_array($j) || empty($j['success'])) return false;

        if ($provider === 'recaptcha_v3') {
            $min = (float)(Setting::get('captcha_score_min', '0.5'));
            if (!isset($j['score']) || $j['score'] < $min) return false;
        }
        return true;
    }
}
