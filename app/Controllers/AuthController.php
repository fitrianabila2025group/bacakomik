<?php
namespace App\Controllers;

use App\Auth;
use App\Captcha;
use App\Csrf;
use App\Database;
use App\Models\Setting;
use App\Models\User;
use App\RateLimiter;

class AuthController extends Controller
{
    public function loginForm(): string
    {
        return $this->view('auth/login', ['title' => 'Masuk - BacaKomik']);
    }

    public function login(): string
    {
        Csrf::check();
        $ip  = $this->clientIp();
        $key = 'login:' . $ip;
        if (RateLimiter::tooMany($key, 8, 300)) {
            $_SESSION['flash'] = 'Terlalu banyak percobaan login. Coba lagi 5 menit.';
            return $this->redirect('/login');
        }
        if (!Captcha::verify('login')) {
            RateLimiter::hit($key);
            $_SESSION['flash'] = 'Verifikasi CAPTCHA gagal.';
            return $this->redirect('/login');
        }
        $email    = trim((string)$this->input('email'));
        $password = (string)$this->input('password');
        if (!Auth::attempt($email, $password)) {
            RateLimiter::hit($key);
            $_SESSION['flash'] = 'Email atau kata sandi salah.';
            return $this->redirect('/login');
        }
        RateLimiter::clear($key);
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
        $ip  = $this->clientIp();
        $key = 'register:' . $ip;
        if (RateLimiter::tooMany($key, 5, 600)) {
            $_SESSION['flash'] = 'Terlalu banyak percobaan registrasi dari IP ini.';
            return $this->redirect('/register');
        }
        RateLimiter::hit($key);
        if (!Captcha::verify('register')) {
            $_SESSION['flash'] = 'Verifikasi CAPTCHA gagal. Silakan coba lagi.';
            return $this->redirect('/register');
        }
        $name  = trim((string)$this->input('name'));
        $email = trim((string)$this->input('email'));
        $pass  = (string)$this->input('password');
        if ($name === '' || mb_strlen($name) > 60 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6 || strlen($pass) > 128) {
            $_SESSION['flash'] = 'Data tidak valid (nama 1-60 karakter, password 6-128 karakter).';
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

    private function clientIp(): string
    {
        // Trust X-Forwarded-For only if explicitly enabled via env (Cloudflare etc).
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && (getenv('TRUST_PROXY') ?: '0') === '1') {
            return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function logout(): string
    {
        Auth::logout();
        return $this->redirect('/');
    }
}
