<?php
namespace App;

/**
 * CSRF token utility.
 */
class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function check(): void
    {
        $token = $_POST['_csrf']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_CSRF_Token'] // fallback header casing
            ?? '';
        $sessionToken = $_SESSION['_csrf'] ?? '';

        if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, (string)$token)) {
            http_response_code(419);

            $accept      = $_SERVER['HTTP_ACCEPT']  ?? '';
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isJson = str_contains($accept, 'application/json')
                   || str_contains($contentType, 'application/json');

            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'     => false,
                    'detail' => 'Invalid CSRF token. Refresh halaman lalu coba lagi.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $_SESSION['flash'] = 'Sesi form kadaluarsa. Silakan refresh halaman lalu coba lagi.';
            $back = $_SERVER['HTTP_REFERER'] ?? '/';
            header('Location: ' . $back);
            exit;
        }
    }
}
