<?php
namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Models\Setting;
use App\View;

abstract class AdminController extends Controller
{
    public function __construct()
    {
        Auth::requireAdmin();
    }

    protected function view(string $name, array $data = [], ?string $layout = 'admin'): string
    {
        $data['settings'] = $data['settings'] ?? [
            'site_name' => Setting::get('site_name', 'BacaKomik'),
        ];
        $data['currentUser'] = Auth::user();
        return View::render($name, $data, $layout);
    }
}
