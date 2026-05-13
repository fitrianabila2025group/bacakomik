<?php
namespace App\Models;

use App\Database;

class User
{
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public static function create(array $data): int
    {
        $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        unset($data['password']);
        return Database::insert('users', $data);
    }

    public static function all(): array
    {
        return Database::fetchAll('SELECT id, name, email, role, status, created_at FROM users ORDER BY id DESC');
    }
}
