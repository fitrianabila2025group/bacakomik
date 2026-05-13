<?php
namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Models\Comic;
use App\Models\Chapter;

class ComicController extends Controller
{
    public function show(string $slug): string
    {
        $comic = Comic::findBySlug($slug);
        if (!$comic) { http_response_code(404); return '<h1>Komik tidak ditemukan</h1>'; }
        Comic::incrementViews((int)$comic['id']);
        $chapters = Chapter::listByComic((int)$comic['id'], 'DESC');
        $genres   = Comic::genres((int)$comic['id']);

        $isBookmarked = false;
        if (Auth::check()) {
            $row = Database::fetch('SELECT id FROM bookmarks WHERE user_id = ? AND comic_id = ?', [$_SESSION['user_id'], $comic['id']]);
            $isBookmarked = (bool)$row;
        }

        return $this->view('comic-detail', [
            'title'        => $comic['title'] . ' - BacaKomik',
            'comic'        => $comic,
            'chapters'     => $chapters,
            'genres'       => $genres,
            'isBookmarked' => $isBookmarked,
        ]);
    }

    public function bookmark(int $id): string
    {
        Auth::requireLogin();
        Csrf::check();
        $exists = Database::fetch('SELECT id FROM bookmarks WHERE user_id = ? AND comic_id = ?', [$_SESSION['user_id'], $id]);
        if ($exists) {
            Database::delete('bookmarks', 'id = ?', [$exists['id']]);
            return $this->json(['bookmarked' => false]);
        }
        Database::insert('bookmarks', ['user_id' => $_SESSION['user_id'], 'comic_id' => $id]);
        return $this->json(['bookmarked' => true]);
    }
}
