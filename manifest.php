<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode([
    'id' => app_scope(),
    'name' => APP_NAME,
    'short_name' => 'SPCC Grades',
    'description' => 'College grading sheet. Scores entered offline stay on this device and sync when you reconnect.',
    'start_url' => url('index.php'),
    'scope' => app_scope(),
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#F3F1EB',
    'theme_color' => '#142B49',
    'icons' => [
        [
            'src' => url('img/icon-192.png'),
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => url('img/icon-512.png'),
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => url('img/icon-512.png'),
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
