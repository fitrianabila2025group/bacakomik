<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Models\Setting;

class AppearanceController extends AdminController
{
    public function index(): string
    {
        return $this->view('admin/appearance', [
            'title'        => 'Appearance',
            'hero_layout'  => Setting::get('hero_layout', 'classic'),
            'card_style'   => Setting::get('card_style', 'modern'),
            'grid_style'   => Setting::get('grid_style', 'default'),
        ]);
    }

    public function save(): string
    {
        Csrf::check();
        foreach (['hero_layout','card_style','grid_style'] as $k) {
            if (isset($_POST[$k])) Setting::set($k, (string)$_POST[$k]);
        }
        $_SESSION['flash'] = 'Tampilan tersimpan.';
        return $this->redirect('/admin/appearance');
    }
}
