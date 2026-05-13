<?php
namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Models\Setting;
use App\Models\User;

class AuthController extends Controller
{
    public function loginForm(): string
    {
        return $this->view('auth/login', ['title' => 'Masuk - BacaKomik']);
    }

    public function login(): string
    {
        Csrf::check();
        $email    = trim((string)$this->input('email'));
        $password = (string)$this->input('password');
        if (!Auth::attempt($email, $password)) {
            $_SESSION['flash'] = 'Email atau kata sandi salah.';
            return $this->redirect('/login');
        }
        return $this->redirect(Auth::isAdmin() ? '/admin' : '/');
    }

    public function registerForm(): string
    {
        if (Setting::get('allow_registration', '1') !== '1') {
            return '<h1>Registrasi dinonaktifkan</h1>';
        }
        return $this->view('auth/register', ['title' => 'Daftar - BacaKomik']);
    }

    public function register(): string
    {
        if (Setting::get('allow_registration', '1') !== '1') return $this->redirect('/login');
        Csrf::check();
        $name  = trim((string)$this->input('name'));
        $email = trim((string)$this->input('email'));
        $pass  = (string)$this->input('password');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            $_SESSION['flash'] = 'Data tidak valid (password minimal 6 karakter).';
            return $this->redirect('/register');
        }
        if (User::findByEmail($email)) {
            $_SESSION['flash'] = 'Email sudah terdaftar.';
            return $this->redirect('/register');
        }
        User::create(['name' => $name, 'email' => $email, 'password' => $pass, 'role' => 'user']);
        Auth::attempt($email, $pass);
        return $this->redirect('/');
    }

    public function logout(): string
    {
        Auth::logout();
        return $this->redirect('/');
    }
}
