<?php
namespace App\Models;

use App\Database;
use App\Services\SlugGenerator;

class Comic
{
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM comics WHERE id = ?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::fetch('SELECT * FROM comics WHERE slug = ?', [$slug]);
    }

    public static function paginate(int $page = 1, int $perPage = 24, array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['search'])) {
            $where[] = '(title LIKE :s OR author LIKE :s OR alt_title LIKE :s)';
            $params['s'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['type']))   { $where[] = 'type = :t';   $params['t'] = $filters['type']; }
        if (!empty($filters['status'])) { $where[] = 'status = :st'; $params['st'] = $filters['status']; }
        if (!empty($filters['genre_id'])) {
            $where[] = 'id IN (SELECT comic_id FROM comic_genres WHERE genre_id = :gid)';
            $params['gid'] = (int)$filters['genre_id'];
        }
        $sql = 'SELECT * FROM comics';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $order = $filters['order'] ?? 'updated_at DESC';
        $sql .= ' ORDER BY ' . $order;
        $offset = max(0, ($page - 1) * $perPage);
        $sql .= " LIMIT $perPage OFFSET $offset";
        return Database::fetchAll($sql, $params);
    }

    public static function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM comics';
        $row = Database::fetch($sql);
        return (int)($row['c'] ?? 0);
    }

    public static function featured(int $limit = 6): array
    {
        return Database::fetchAll("SELECT * FROM comics WHERE is_featured = 1 ORDER BY updated_at DESC LIMIT $limit");
    }

    public static function popular(int $limit = 12): array
    {
        return Database::fetchAll("SELECT * FROM comics ORDER BY views DESC LIMIT $limit");
    }

    public static function latest(int $limit = 12): array
    {
        return Database::fetchAll("SELECT * FROM comics ORDER BY updated_at DESC LIMIT $limit");
    }

    public static function genres(int $comicId): array
    {
        return Database::fetchAll(
            'SELECT g.* FROM genres g JOIN comic_genres cg ON cg.genre_id = g.id WHERE cg.comic_id = ?',
            [$comicId]
        );
    }

    public static function syncGenres(int $comicId, array $genreIds): void
    {
        Database::delete('comic_genres', 'comic_id = ?', [$comicId]);
        foreach (array_unique($genreIds) as $gid) {
            Database::insert('comic_genres', ['comic_id' => $comicId, 'genre_id' => (int)$gid]);
        }
    }

    public static function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = SlugGenerator::make($base);
        $slug = $base;
        $i = 1;
        while (true) {
            $row = Database::fetch('SELECT id FROM comics WHERE slug = ?', [$slug]);
            if (!$row || (int)$row['id'] === $ignoreId) return $slug;
            $i++;
            $slug = $base . '-' . $i;
        }
    }

    public static function incrementViews(int $id): void
    {
        Database::query('UPDATE comics SET views = views + 1 WHERE id = ?', [$id]);
    }
}
