<?php
/**
 * Global helpers loaded by index.php before dispatching.
 */

if (!function_exists('ad')) {
    /**
     * Render an ad slot by key. Echoes nothing if slot is inactive/empty.
     */
    function ad(string $slot): void
    {
        $row = \App\Database::fetch(
            'SELECT ad_code, is_active FROM ad_slots WHERE slot_key = ?',
            [$slot]
        );
        if ($row && $row['is_active'] && trim((string)$row['ad_code']) !== '') {
            echo '<div class="ad-slot ad-' . htmlspecialchars($slot) . '">' . $row['ad_code'] . '</div>';
        }
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
