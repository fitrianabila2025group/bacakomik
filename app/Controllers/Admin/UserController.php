<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;
use App\Models\User;

class UserController extends AdminController
{
    public function index(): string
    {
        return $this->view('admin/users', [
            'title' => 'Users',
            'users' => User::all(),
        ]);
    }

    public function toggle(int $id): string
    {
        Csrf::check();
        $u = User::find($id); if (!$u) return $this->redirect('/admin/users');
        $new = $u['status'] === 'active' ? 'disabled' : 'active';
        Database::update('users', ['status' => $new], 'id = :id', ['id' => $id]);
        return $this->redirect('/admin/users');
    }

    public function setRole(int $id): string
    {
        Csrf::check();
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        Database::update('users', ['role' => $role], 'id = :id', ['id' => $id]);
        return $this->redirect('/admin/users');
    }

    public function delete(int $id): string
    {
        Csrf::check();
        if ($id !== (int)$_SESSION['user_id']) {
            Database::delete('users', 'id = ?', [$id]);
        }
        return $this->redirect('/admin/users');
    }
}
