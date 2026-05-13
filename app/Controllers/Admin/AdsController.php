<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;

class AdsController extends AdminController
{
    public function index(): string
    {
        $slots = Database::fetchAll('SELECT * FROM ad_slots ORDER BY id ASC');
        return $this->view('admin/ads', [
            'title' => 'Ads',
            'slots' => $slots,
        ]);
    }

    public function save(): string
    {
        Csrf::check();
        $codes  = (array)($_POST['ad_code'] ?? []);
        $active = (array)($_POST['is_active'] ?? []);
        foreach ($codes as $id => $code) {
            $id = (int)$id;
            Database::update('ad_slots', [
                'ad_code'   => (string)$code,
                'is_active' => isset($active[$id]) ? 1 : 0,
            ], 'id = :id', ['id' => $id]);
        }
        $_SESSION['flash'] = 'Iklan tersimpan.';
        return $this->redirect('/admin/ads');
    }
}
