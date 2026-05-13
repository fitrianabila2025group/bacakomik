<?php
namespace App\Services\Scraper;

interface ScraperInterface
{
    public function fetchComicMetadata(string $url): array;
    public function fetchChapterList(string $url): array;
    public function fetchChapterImages(string $chapterUrl): array;
    public function downloadImage(string $url, string $destPath): bool;
    public function importFullComic(string $url, ?callable $progressCallback = null): array;
}
