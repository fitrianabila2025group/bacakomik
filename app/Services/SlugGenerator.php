<?php
namespace App\Services;

class SlugGenerator
{
    public static function make(string $text): string
    {
        $text = strtolower(trim($text));
        // Transliterate accented chars
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($t !== false) $text = $t;
        }
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        $text = trim((string)$text, '-');
        return $text !== '' ? $text : 'item';
    }
}
