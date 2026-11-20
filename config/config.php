<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'hasil_prod');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', '/hasil_prod/');
define('APP_NAME', 'PT. MCP - Hasil Produksi');
define('UPLOAD_DO_DIR', __DIR__ . '/../uploads/do/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
