<?php
namespace App\Controllers;

use App\Database;
use App\Models\Comic;
use App\Models\Chapter;

class ReaderController extends Controller
{
    public function show(string $slug, string $chapterSlug): string
    {
        $comic = Comic::findBySlug($slug);
        if (!$comic) { http_response_code(404); return '<h1>Komik tidak ditemukan</h1>'; }

        $chapter = Chapter::findByComicAndSlug((int)$comic['id'], $chapterSlug);
        if (!$chapter) { http_response_code(404); return '<h1>Chapter tidak ditemukan</h1>'; }

        Chapter::incrementViews((int)$chapter['id']);
        $images   = Chapter::images((int)$chapter['id']);
        $list     = Chapter::listByComic((int)$comic['id'], 'ASC');
        $neighbors = Chapter::neighbors((int)$comic['id'], $chapter['chapter_number']);

        // Save reading history
        if (!empty($_SESSION['user_id'])) {
            Database::query(
                'INSERT INTO reading_history (user_id, comic_id, chapter_id, last_read_at) VALUES (?,?,?, NOW())
                 ON DUPLICATE KEY UPDATE chapter_id = VALUES(chapter_id), last_read_at = NOW()',
                [$_SESSION['user_id'], $comic['id'], $chapter['id']]
            );
        }

        return $this->view('reader', [
            'title'      => $comic['title'] . ' Chapter ' . $chapter['chapter_number'],
            'comic'      => $comic,
            'chapter'    => $chapter,
            'images'     => $images,
            'chapterList'=> $list,
            'prev'       => $neighbors['prev'],
            'next'       => $neighbors['next'],
        ], 'reader');
    }
}
