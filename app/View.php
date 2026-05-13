<?php
namespace App;

/**
 * Tiny view renderer.
 */
class View
{
    public static function render(string $name, array $data = [], ?string $layout = 'main'): string
    {
        $file = VIEW_PATH . '/' . str_replace('.', '/', $name) . '.php';
        if (!is_file($file)) {
            return "<p>View not found: $name</p>";
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = ob_get_clean();

        if ($layout === null) return $content;

        $layoutFile = VIEW_PATH . '/layouts/' . $layout . '.php';
        if (!is_file($layoutFile)) return $content;

        $title = $data['title'] ?? null;
        $meta = $data['meta'] ?? [];
        ob_start();
        require $layoutFile;
        return ob_get_clean();
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
