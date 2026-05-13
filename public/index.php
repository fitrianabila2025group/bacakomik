<?php
/**
 * BacaKomik front controller.
 */
declare(strict_types=1);

session_start();

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', BASE_PATH . '/views');
define('STORAGE_PATH', BASE_PATH . '/storage');

// Composer autoload (optional - fallback to manual autoload below)
if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

// Global helpers (ad(), e(), ...)
require BASE_PATH . '/app/helpers.php';

// PSR-4 fallback autoloader for App\
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $relative = substr($class, 4);
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require BASE_PATH . '/config/app.php';
if ($config['env'] === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

use App\Router;
use App\Database;

// Boot DB
Database::init(require BASE_PATH . '/config/database.php');

$router = new Router();

// ========== Frontend ==========
$router->get('/',                    'HomeController@index');
$router->get('/series',              'HomeController@series');
$router->get('/popular',             'HomeController@popular');
$router->get('/library',             'HomeController@library');
$router->get('/search',              'HomeController@search');
$router->get('/api/search',          'HomeController@apiSearch');
$router->get('/comic/{slug}',        'ComicController@show');
$router->get('/comic/{slug}/chapter/{chapterSlug}', 'ReaderController@show');
$router->post('/bookmark/{id}',      'ComicController@bookmark');

// Auth
$router->get('/login',               'AuthController@loginForm');
$router->post('/login',              'AuthController@login');
$router->get('/register',            'AuthController@registerForm');
$router->post('/register',           'AuthController@register');
$router->get('/logout',              'AuthController@logout');

// Pages
$router->get('/page/{slug}',         'HomeController@page');

// ========== Admin ==========
$router->get('/admin',                          'Admin\DashboardController@index');
$router->get('/admin/library',                  'Admin\ComicController@index');
$router->get('/admin/library/create',           'Admin\ComicController@create');
$router->post('/admin/library/store',           'Admin\ComicController@store');
$router->get('/admin/library/edit/{id}',        'Admin\ComicController@edit');
$router->post('/admin/library/update/{id}',     'Admin\ComicController@update');
$router->post('/admin/library/delete/{id}',     'Admin\ComicController@delete');

$router->get('/admin/chapters',                 'Admin\ChapterController@index');
$router->get('/admin/chapters/create',          'Admin\ChapterController@create');
$router->post('/admin/chapters/store',          'Admin\ChapterController@store');
$router->get('/admin/chapters/edit/{id}',       'Admin\ChapterController@edit');
$router->post('/admin/chapters/update/{id}',    'Admin\ChapterController@update');
$router->post('/admin/chapters/delete/{id}',    'Admin\ChapterController@delete');

$router->get('/admin/pages',                    'Admin\SettingController@pages');
$router->post('/admin/pages/save',              'Admin\SettingController@savePage');

$router->get('/admin/reports',                  'Admin\SettingController@reports');
$router->post('/admin/reports/{id}/status',     'Admin\SettingController@reportStatus');

$router->get('/admin/import',                   'Admin\ImportController@index');
$router->post('/admin/import/preview',          'Admin\ImportController@preview');
$router->post('/admin/import/run',              'Admin\ImportController@run');
$router->get('/admin/import/status/{id}',       'Admin\ImportController@status');
$router->post('/admin/import/cancel/{id}',      'Admin\ImportController@cancel');
$router->post('/admin/import/retry-failed/{id}', 'Admin\ImportController@retryFailed');

$router->get('/admin/users',                    'Admin\UserController@index');
$router->post('/admin/users/{id}/toggle',       'Admin\UserController@toggle');
$router->post('/admin/users/{id}/role',         'Admin\UserController@setRole');
$router->post('/admin/users/{id}/delete',       'Admin\UserController@delete');

$router->get('/admin/settings',                 'Admin\SettingController@index');
$router->post('/admin/settings/save',           'Admin\SettingController@save');

$router->get('/admin/appearance',               'Admin\AppearanceController@index');
$router->post('/admin/appearance/save',         'Admin\AppearanceController@save');

$router->get('/admin/ads',                      'Admin\AdsController@index');
$router->post('/admin/ads/save',                'Admin\AdsController@save');

$router->get('/admin/license',                  'Admin\SettingController@license');

// Storage proxy (serve files outside public)
$router->get('/storage/{path:.*}',              'HomeController@serveStorage');

// SEO
$router->get('/sitemap.xml',                    'HomeController@sitemap');
$router->get('/sitemap-pages.xml',              'HomeController@sitemapPages');
$router->get('/sitemap-genres.xml',             'HomeController@sitemapGenres');
$router->get('/sitemap-comics-{chunk:\d+}.xml', 'HomeController@sitemapComics');
$router->get('/sitemap-chapters-{chunk:\d+}.xml','HomeController@sitemapChapters');
$router->get('/robots.txt',                     'HomeController@robots');

$router->dispatch();
