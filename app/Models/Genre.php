<?php
namespace App\Models;

use App\Database;
use App\Services\SlugGenerator;

class Genre
{
    public static function all(): array
    {
        return Database::fetchAll('SELECT * FROM genres ORDER BY name ASC');
    }

    public static function findOrCreate(string $name): int
    {
        $slug = SlugGenerator::make($name);
        $row = Database::fetch('SELECT id FROM genres WHERE slug = ?', [$slug]);
        if ($row) return (int)$row['id'];
        return Database::insert('genres', ['name' => trim($name), 'slug' => $slug]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::fetch('SELECT * FROM genres WHERE slug = ?', [$slug]);
    }
}
