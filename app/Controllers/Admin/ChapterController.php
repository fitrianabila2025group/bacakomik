<?php
namespace App\Controllers\Admin;

use App\Csrf;
use App\Database;
use App\Models\Chapter;
use App\Models\Comic;
use App\Services\SlugGenerator;

class ChapterController extends AdminController
{
    public function index(): string
    {
        $rows = Database::fetchAll(
            'SELECT ch.*, c.title comic_title, c.slug comic_slug,
              (SELECT COUNT(*) FROM chapter_images WHERE chapter_id = ch.id) page_count
             FROM chapters ch JOIN comics c ON c.id = ch.comic_id
             ORDER BY ch.created_at DESC LIMIT 200'
        );
        return $this->view('admin/chapters', [
            'title'    => 'Chapters',
            'chapters' => $rows,
        ]);
    }

    public function create(): string
    {
        return $this->view('admin/chapter-form', [
            'title'   => 'Tambah Chapter',
            'chapter' => null,
            'comics'  => Database::fetchAll('SELECT id, title FROM comics ORDER BY title ASC'),
        ]);
    }

    public function store(): string
    {
        Csrf::check();
        $comicId = (int)($_POST['comic_id'] ?? 0);
        $comic = Comic::find($comicId);
        if (!$comic) return $this->redirect('/admin/chapters/create');

        $number = trim((string)($_POST['chapter_number'] ?? '1'));
        $title  = trim((string)($_POST['title'] ?? ('Chapter ' . $number)));
        $slug   = Chapter::uniqueSlug($comicId, 'chapter-' . $number);
        $chapterId = Database::insert('chapters', [
            'comic_id'       => $comicId,
            'chapter_number' => $number,
            'title'          => $title,
            'slug'           => $slug,
        ]);

        $this->handleImages($chapterId, $comic['slug'], $slug);
        $this->handleZip($chapterId, $comic['slug'], $slug);
        return $this->redirect('/admin/chapters');
    }

    public function edit(int $id): string
    {
        $chapter = Chapter::find($id);
        if (!$chapter) return $this->redirect('/admin/chapters');
        $images = Chapter::images($id);
        return $this->view('admin/chapter-form', [
            'title'   => 'Edit Chapter',
            'chapter' => $chapter,
            'images'  => $images,
            'comics'  => Database::fetchAll('SELECT id, title FROM comics ORDER BY title ASC'),
        ]);
    }

    public function update(int $id): string
    {
        Csrf::check();
        $chapter = Chapter::find($id);
        if (!$chapter) return $this->redirect('/admin/chapters');
        $comic = Comic::find((int)$chapter['comic_id']);
        Database::update('chapters', [
            'chapter_number' => trim((string)($_POST['chapter_number'] ?? $chapter['chapter_number'])),
            'title'          => trim((string)($_POST['title'] ?? $chapter['title'])),
        ], 'id = :id', ['id' => $id]);

        $this->handleImages($id, $comic['slug'], $chapter['slug']);
        $this->handleZip($id, $comic['slug'], $chapter['slug']);
        return $this->redirect('/admin/chapters/edit/' . $id);
    }

    public function delete(int $id): string
    {
        Csrf::check();
        Database::delete('chapters', 'id = ?', [$id]);
        return $this->redirect('/admin/chapters');
    }

    private function handleImages(int $chapterId, string $comicSlug, string $chapterSlug): void
    {
        if (empty($_FILES['images']['tmp_name'][0] ?? null)) return;
        $dir = STORAGE_PATH . '/comics/' . $comicSlug . '/' . $chapterSlug;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $existing = Chapter::totalImages($chapterId);
        $files = $_FILES['images'];
        $count = count($files['tmp_name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
            $sort = $existing + $i + 1;
            $name = 'page-' . str_pad((string)$sort, 3, '0', STR_PAD_LEFT) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                Database::insert('chapter_images', [
                    'chapter_id' => $chapterId,
                    'image_path' => '/storage/comics/' . $comicSlug . '/' . $chapterSlug . '/' . $name,
                    'sort_order' => $sort,
                ]);
            }
        }
    }

    private function handleZip(int $chapterId, string $comicSlug, string $chapterSlug): void
    {
        if (empty($_FILES['zip']['tmp_name']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) return;
        if (!class_exists(\ZipArchive::class)) return;
        $zip = new \ZipArchive();
        if ($zip->open($_FILES['zip']['tmp_name']) !== true) return;
        $dir = STORAGE_PATH . '/comics/' . $comicSlug . '/' . $chapterSlug;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $existing = Chapter::totalImages($chapterId);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameInfo($i);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'], true)) $entries[] = $name;
        }
        sort($entries, SORT_NATURAL);
        foreach ($entries as $idx => $entry) {
            $sort = $existing + $idx + 1;
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $name = 'page-' . str_pad((string)$sort, 3, '0', STR_PAD_LEFT) . '.' . $ext;
            $stream = $zip->getStream($entry);
            if (!$stream) continue;
            $out = fopen($dir . '/' . $name, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($stream); fclose($out);
            Database::insert('chapter_images', [
                'chapter_id' => $chapterId,
                'image_path' => '/storage/comics/' . $comicSlug . '/' . $chapterSlug . '/' . $name,
                'sort_order' => $sort,
            ]);
        }
        $zip->close();
    }
}
