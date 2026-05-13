<?php
namespace App\Controllers\Admin;

use App\Database;

class DashboardController extends AdminController
{
    public function index(): string
    {
        $stats = [
            'comics'   => (int)(Database::fetch('SELECT COUNT(*) c FROM comics')['c'] ?? 0),
            'chapters' => (int)(Database::fetch('SELECT COUNT(*) c FROM chapters')['c'] ?? 0),
            'users'    => (int)(Database::fetch('SELECT COUNT(*) c FROM users')['c'] ?? 0),
            'views'    => (int)(Database::fetch('SELECT COALESCE(SUM(views),0) c FROM comics')['c'] ?? 0),
        ];
        $latestComics   = Database::fetchAll('SELECT * FROM comics ORDER BY created_at DESC LIMIT 6');
        $latestChapters = Database::fetchAll(
            'SELECT ch.*, c.title AS comic_title, c.slug AS comic_slug
             FROM chapters ch JOIN comics c ON c.id = ch.comic_id
             ORDER BY ch.created_at DESC LIMIT 8'
        );
        // last 7 days chart
        $chart = Database::fetchAll(
            "SELECT DATE(created_at) d, COUNT(*) c FROM chapters
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(created_at) ORDER BY d ASC"
        );
        return $this->view('admin/dashboard', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'latestComics' => $latestComics,
            'latestChapters' => $latestChapters,
            'chart' => $chart,
        ]);
    }
}
