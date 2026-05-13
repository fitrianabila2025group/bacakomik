<?php
namespace App;

/**
 * Minimal regex-based router supporting GET/POST and {param} or {name:regex} placeholders.
 */
class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, string $handler): void  { $this->add('GET', $path, $handler); }
    public function post(string $path, string $handler): void { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, string $handler): void
    {
        $this->routes[$method][] = ['path' => $path, 'handler' => $handler];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        foreach ($this->routes[$method] ?? [] as $route) {
            $regex = $this->compile($route['path']);
            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches);
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->call($route['handler'], $params);
                return;
            }
        }
        http_response_code(404);
        $this->renderNotFound();
    }

    private function compile(string $path): string
    {
        $regex = preg_replace_callback('#\{([a-zA-Z_]+)(?::([^}]+))?\}#', function ($m) {
            $name = $m[1];
            $pattern = $m[2] ?? '[^/]+';
            return '(?P<' . $name . '>' . $pattern . ')';
        }, $path);
        return '#^' . $regex . '/?$#';
    }

    private function call(string $handler, array $params): void
    {
        [$controller, $method] = explode('@', $handler);
        $class = 'App\\Controllers\\' . $controller;
        if (!class_exists($class)) {
            http_response_code(500);
            echo "Controller not found: $class";
            return;
        }
        $instance = new $class();
        if (!method_exists($instance, $method)) {
            http_response_code(500);
            echo "Method not found: $class@$method";
            return;
        }
        echo $instance->$method(...array_values($params));
    }

    private function renderNotFound(): void
    {
        // Render the styled 404 view inside the main layout (with header/footer).
        echo View::render('404', [
            'title' => '404 — Halaman Tidak Ditemukan',
            'meta'  => ['description' => 'Halaman yang Anda cari tidak ditemukan.'],
        ], 'main');
    }
}
