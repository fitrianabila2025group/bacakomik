<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;
use App\Models\Comic;
use App\Models\Genre;

class ComicController extends AdminController
{
    public function index(): string
    {
        $comics = Database::fetchAll('SELECT * FROM comics ORDER BY updated_at DESC LIMIT 200');
        return $this->view('admin/library', [
            'title'  => 'Library',
            'comics' => $comics,
        ]);
    }

    public function create(): string
    {
        return $this->view('admin/library-form', [
            'title'  => 'Tambah Komik',
            'comic'  => null,
            'genres' => Genre::all(),
            'selectedGenres' => [],
        ]);
    }

    public function store(): string
    {
        Csrf::check();
        $data = $this->collect();
        $data['slug'] = Comic::uniqueSlug($data['slug'] ?: $data['title']);
        $data['cover_image'] = $this->handleCover($data['slug']) ?? null;
        if (!$data['cover_image']) unset($data['cover_image']);

        $genres = (array)($_POST['genres'] ?? []);
        $id = Database::insert('comics', $data);
        Comic::syncGenres($id, $genres);
        return $this->redirect('/admin/library');
    }

    public function edit(int $id): string
    {
        $comic = Comic::find($id);
        if (!$comic) return $this->redirect('/admin/library');
        $genreIds = array_column(Comic::genres($id), 'id');
        return $this->view('admin/library-form', [
            'title'  => 'Edit Komik',
            'comic'  => $comic,
            'genres' => Genre::all(),
            'selectedGenres' => array_map('intval', $genreIds),
        ]);
    }

    public function update(int $id): string
    {
        Csrf::check();
        $comic = Comic::find($id);
        if (!$comic) return $this->redirect('/admin/library');
        $data = $this->collect();
        if ($data['slug'] !== $comic['slug']) {
            $data['slug'] = Comic::uniqueSlug($data['slug'], $id);
        }
        $cover = $this->handleCover($data['slug']);
        if ($cover) $data['cover_image'] = $cover;
        Database::update('comics', $data, 'id = :id', ['id' => $id]);
        Comic::syncGenres($id, (array)($_POST['genres'] ?? []));
        return $this->redirect('/admin/library');
    }

    public function delete(int $id): string
    {
        Csrf::check();
        Database::delete('comics', 'id = ?', [$id]);
        return $this->redirect('/admin/library');
    }

    private function collect(): array
    {
        return [
            'title'       => trim((string)($_POST['title'] ?? '')),
            'slug'        => trim((string)($_POST['slug'] ?? '')),
            'alt_title'   => trim((string)($_POST['alt_title'] ?? '')) ?: null,
            'author'      => trim((string)($_POST['author'] ?? '')) ?: null,
            'artist'      => trim((string)($_POST['artist'] ?? '')) ?: null,
            'type'        => $_POST['type'] ?? 'Manga',
            'status'      => $_POST['status'] ?? 'Ongoing',
            'synopsis'    => trim((string)($_POST['synopsis'] ?? '')) ?: null,
            'rating'      => (float)($_POST['rating'] ?? 0),
            'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
            'is_popular'  => isset($_POST['is_popular']) ? 1 : 0,
        ];
    }

    private function handleCover(string $slug): ?string
    {
        if (empty($_FILES['cover']['tmp_name'])) return null;
        $file = $_FILES['cover'];
        if ($file['error'] !== UPLOAD_ERR_OK) return null;
        if ($file['size'] > 2 * 1024 * 1024) return null;

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return null;

        $dir = STORAGE_PATH . '/covers';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $dest = $dir . '/' . $slug . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
        return '/storage/covers/' . basename($dest);
    }
}
