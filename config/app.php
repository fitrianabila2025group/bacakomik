<?php
/**
 * Application configuration.
 */
return [
    'name'      => 'BacaKomik',
    'url'       => getenv('APP_URL') ?: 'http://localhost:8000',
    'env'       => getenv('APP_ENV') ?: 'production',
    'debug'     => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
    'storage'   => __DIR__ . '/../storage',
    'public'    => __DIR__ . '/../public',
    'upload_max' => 2 * 1024 * 1024, // 2 MB
    'allowed_image_ext' => ['jpg', 'jpeg', 'png', 'webp'],
];
