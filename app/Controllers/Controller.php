<?php
namespace App\Controllers;

use App\View;
use App\Models\Setting;

abstract class Controller
{
    protected function view(string $name, array $data = [], ?string $layout = 'main'): string
    {
        $data['settings'] = $data['settings'] ?? [
            'site_name'         => Setting::get('site_name', 'BacaKomik'),
            'meta_title'        => Setting::get('meta_title'),
            'meta_description'  => Setting::get('meta_description'),
            'site_logo'         => Setting::get('site_logo'),
            'site_favicon'      => Setting::get('site_favicon'),
            'hero_layout'       => Setting::get('hero_layout', 'classic'),
            'card_style'        => Setting::get('card_style', 'modern'),
        ];
        return View::render($name, $data, $layout);
    }

    protected function redirect(string $url): string
    {
        header('Location: ' . $url);
        exit;
    }

    protected function json($data, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }
}
