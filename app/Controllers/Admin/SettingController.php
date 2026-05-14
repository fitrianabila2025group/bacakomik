<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;
use App\Models\Setting;
use App\Services\SlugGenerator;

class SettingController extends AdminController
{
    public function index(): string
    {
        return $this->view('admin/settings', [
            'title'    => 'Settings',
            'settings' => Setting::all(),
        ]);
    }

    public function save(): string
    {
        Csrf::check();
        $keys = [
            'site_name','meta_title','meta_description','default_theme',
            'maintenance_mode','allow_registration',
            'scraper_delay','scraper_timeout','scraper_user_agent',
            'scraper_concurrent','scraper_whitelist',
            'scraper_use_api','scraper_api_url','scraper_api_key','scraper_api_timeout',
            'scraper_remote_storage','scraper_proxy_public',
            // CAPTCHA
            'captcha_provider','captcha_site_key','captcha_secret_key','captcha_score_min',
            'captcha_on_register','captcha_on_login','captcha_on_comment',
            // Comments (Railway service)
            'comments_enabled','comments_on_comic','comments_on_chapter','comments_guest_allowed',
            'comments_api_url','comments_hmac_secret',
        ];
        foreach ($keys as $k) {
            $val = $_POST[$k] ?? '';
            // checkboxes default to '0' when unchecked
            if (in_array($k, [
                'scraper_use_api','scraper_remote_storage','scraper_proxy_public',
                'maintenance_mode','allow_registration',
                'captcha_on_register','captcha_on_login','captcha_on_comment',
                'comments_enabled','comments_on_comic','comments_on_chapter','comments_guest_allowed',
            ], true)) {
                $val = !empty($_POST[$k]) ? '1' : '0';
            }
            // Validate captcha provider whitelist
            if ($k === 'captcha_provider' && !in_array($val, \App\Captcha::PROVIDERS, true)) {
                $val = 'none';
            }
            Setting::set($k, (string)$val);
        }
        // logo/favicon upload
        foreach (['site_logo','site_favicon'] as $k) {
            if (!empty($_FILES[$k]['tmp_name']) && $_FILES[$k]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$k]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp','ico','svg'], true)) {
                    $dir = STORAGE_PATH . '/settings';
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $name = $k . '.' . $ext;
                    move_uploaded_file($_FILES[$k]['tmp_name'], $dir . '/' . $name);
                    Setting::set($k, '/storage/settings/' . $name);
                }
            }
        }
        $_SESSION['flash'] = 'Settings tersimpan.';
        return $this->redirect('/admin/settings');
    }

    public function pages(): string
    {
        $pages = Database::fetchAll('SELECT * FROM pages ORDER BY id ASC');
        return $this->view('admin/pages', ['title' => 'Pages', 'pages' => $pages]);
    }

    public function savePage(): string
    {
        Csrf::check();
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim((string)($_POST['title'] ?? ''));
        $slug    = SlugGenerator::make($_POST['slug'] ?? $title);
        $content = (string)($_POST['content'] ?? '');
        $status  = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        if ($id) {
            Database::update('pages', compact('title','slug','content','status'), 'id = :id', ['id' => $id]);
        } else {
            Database::insert('pages', compact('title','slug','content','status'));
        }
        return $this->redirect('/admin/pages');
    }

    public function reports(): string
    {
        $reports = Database::fetchAll('SELECT * FROM reports ORDER BY created_at DESC LIMIT 200');
        return $this->view('admin/reports', ['title' => 'Reports', 'reports' => $reports]);
    }

    public function reportStatus(int $id): string
    {
        Csrf::check();
        $status = $_POST['status'] ?? 'solved';
        Database::update('reports', ['status' => $status], 'id = :id', ['id' => $id]);
        return $this->redirect('/admin/reports');
    }

    public function license(): string
    {
        return $this->view('admin/license', ['title' => 'License']);
    }
}
