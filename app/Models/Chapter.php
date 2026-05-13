<?php
namespace App\Models;

use App\Database;
use App\Services\SlugGenerator;

class Chapter
{
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM chapters WHERE id = ?', [$id]);
    }

    public static function findByComicAndSlug(int $comicId, string $slug): ?array
    {
        return Database::fetch('SELECT * FROM chapters WHERE comic_id = ? AND slug = ?', [$comicId, $slug]);
    }

    public static function listByComic(int $comicId, string $order = 'DESC'): array
    {
        $order = $order === 'ASC' ? 'ASC' : 'DESC';
        return Database::fetchAll("SELECT * FROM chapters WHERE comic_id = ? ORDER BY CAST(chapter_number AS DECIMAL(10,2)) $order", [$comicId]);
    }

    public static function images(int $chapterId): array
    {
        return Database::fetchAll('SELECT * FROM chapter_images WHERE chapter_id = ? ORDER BY sort_order ASC, id ASC', [$chapterId]);
    }

    public static function neighbors(int $comicId, string $chapterNumber): array
    {
        $prev = Database::fetch(
            'SELECT * FROM chapters WHERE comic_id = ? AND CAST(chapter_number AS DECIMAL(10,2)) < CAST(? AS DECIMAL(10,2))
             ORDER BY CAST(chapter_number AS DECIMAL(10,2)) DESC LIMIT 1',
            [$comicId, $chapterNumber]
        );
        $next = Database::fetch(
            'SELECT * FROM chapters WHERE comic_id = ? AND CAST(chapter_number AS DECIMAL(10,2)) > CAST(? AS DECIMAL(10,2))
             ORDER BY CAST(chapter_number AS DECIMAL(10,2)) ASC LIMIT 1',
            [$comicId, $chapterNumber]
        );
        return ['prev' => $prev, 'next' => $next];
    }

    public static function uniqueSlug(int $comicId, string $base, ?int $ignoreId = null): string
    {
        $base = SlugGenerator::make($base);
        $slug = $base; $i = 1;
        while (true) {
            $row = Database::fetch('SELECT id FROM chapters WHERE comic_id = ? AND slug = ?', [$comicId, $slug]);
            if (!$row || (int)$row['id'] === $ignoreId) return $slug;
            $i++;
            $slug = $base . '-' . $i;
        }
    }

    public static function totalImages(int $chapterId): int
    {
        $row = Database::fetch('SELECT COUNT(*) AS c FROM chapter_images WHERE chapter_id = ?', [$chapterId]);
        return (int)($row['c'] ?? 0);
    }

    public static function incrementViews(int $id): void
    {
        Database::query('UPDATE chapters SET views = views + 1 WHERE id = ?', [$id]);
    }

    public static function deleteImages(int $chapterId): void
    {
        Database::delete('chapter_images', 'chapter_id = ?', [$chapterId]);
    }
}
