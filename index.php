<?php
/**
 * Root index — delegates to public/index.php.
 *
 * Diperlukan agar request ke "/" tidak menyebabkan 403 di server seperti
 * LiteSpeed yang mengevaluasi DirectoryIndex sebelum mod_rewrite.
 *
 * Direkomendasikan: ubah Document Root domain ke public_html/public lalu
 * file ini boleh dihapus.
 */
require __DIR__ . '/public/index.php';
