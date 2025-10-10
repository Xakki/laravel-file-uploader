<?php

// See type https://mimetype.io/all-types

$defaultExtensions = [
    'docx',
    'application/vnd.oasis.opendocument.text' => 'odt',
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'image/jpeg' => '*',
    'image/pjpeg' => '*',
    'image/gif' => 'gif',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/octet-stream' => '*',
    'application/x-rar-compressed' => 'rar',
    'application/vnd.rar' => 'rar',
    'application/zip' => 'zip',
    'application/x-zip-compressed' => 'zip',
    'application/gzip' => 'gz',
    'application/x-gzip' => 'gz',
    'application/json' => 'json',
];

return [
    'disk' => env('FILE_UPLOADER_DISK', 'public'),
    'directory' => env('FILE_UPLOADER_DIRECTORY', '/'),
    'temporary_directory' => env('FILE_UPLOADER_TEMPORARY_DIRECTORY', '.chunks'),
    'metadata_directory' => env('FILE_UPLOADER_METADATA_DIRECTORY', '.meta'),
    'trash_directory' => env('FILE_UPLOADER_TRASH_DIRECTORY', '.trash'),
    // Load file by chunk, by 1Mb default
    'chunk_size' => (int) env('FILE_UPLOADER_CHUNK_SIZE', 1024 * 1024),
    'max_size' => (int) env('FILE_UPLOADER_MAX_SIZE', 1024 * 1024 * 50),
    'allowed_extensions' => env('FILE_UPLOADER_ALLOWED_EXTENSIONS', $defaultExtensions),
    'middleware' => ['web', 'auth'],
    'route_prefix' => 'file-upload',
    'route_name' => 'file-uploader.',
    'trash_ttl_days' => (int) env('FILE_UPLOADER_TRASH_TTL_DAYS', 30),
    'public_url_resolver' => null,
    'soft_delete' => true,
    'pagination' => [
        'per_page' => 50,
    ],
    'locales' => ['en', 'ru'],
    'locale' => env('FILE_UPLOADER_DEFAULT_LOCALE', 'en'),
    'allow_list' => true,
    'allow_delete' => true,
    'allow_delete_all_files' => (bool) env('FILE_UPLOADER_ALLOW_DELETE_ALL_FILES', false),
    'allow_cleanup' => true,
    'full_access' => [
        'users' => [],
        'roles' => [],
    ],
];
