<?php
namespace App\Models;

use App\Database;

class Setting
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::fetchAll('SELECT setting_key, setting_value FROM settings');
            self::$cache = [];
            foreach ($rows as $r) self::$cache[$r['setting_key']] = $r['setting_value'];
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $exists = Database::fetch('SELECT id FROM settings WHERE setting_key = ?', [$key]);
        if ($exists) {
            Database::update('settings', ['setting_value' => $value], 'setting_key = :k', ['k' => $key]);
        } else {
            Database::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
        }
        self::$cache = null;
    }
}
