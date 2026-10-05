<?php

/**
 * FILE DIAGNOSTIK SEMENTARA — HAPUS SETELAH SELESAI.
 *
 * Akses: https://apitix.diamcreative.com/cek.php?t=nontix-diag-9f2c7a
 */

$token = 'nontix-diag-9f2c7a';

if (($_GET['t'] ?? '') !== $token) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = realpath(__DIR__.'/..');

echo "=== LINGKUNGAN ===\n";
echo 'PHP          : '.PHP_VERSION.' ('.PHP_SAPI.")\n";
echo 'Base path    : '.$base."\n";
echo 'User         : '.(function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (getenv('USERNAME') ?: getenv('USER') ?: '?'))."\n";

echo "\n=== FILE PENTING ===\n";
$checks = [
    '.env',
    'vendor/autoload.php',
    'vendor/composer/platform_check.php',
    'bootstrap/app.php',
    'bootstrap/cache/config.php',
    'bootstrap/cache/routes-v7.php',
    'bootstrap/cache/packages.php',
    'bootstrap/cache/services.php',
    'storage/app/public',
    'storage/framework/views',
    'storage/logs',
];
foreach ($checks as $path) {
    $full = $base.'/'.$path;
    $exists = file_exists($full);
    $writable = $exists ? (is_writable($full) ? 'writable' : 'NOT-writable') : '';
    echo str_pad($path, 40).($exists ? 'ADA '.$writable : 'TIDAK ADA')."\n";
}

echo "\n=== TABEL MIGRASI (butuh DB) ===\n";
try {
    require $base.'/vendor/autoload.php';
    echo "autoload      : OK\n";
} catch (\Throwable $e) {
    echo "!!! AUTOLOAD GAGAL: ".get_class($e).': '.$e->getMessage()."\n";
    echo $e->getFile().':'.$e->getLine()."\n";
    exit;
}

try {
    $app = require $base.'/bootstrap/app.php';
    echo "bootstrap app : OK\n";

    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    echo "kernel        : OK\n";

    $request = Illuminate\Http\Request::create('/api/events', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    echo 'handle /api/events : HTTP '.$response->getStatusCode()."\n";
    echo 'body          : '.substr((string) $response->getContent(), 0, 400)."\n";

    try {
        Illuminate\Support\Facades\DB::connection()->getPdo();
        echo "database      : OK\n";
        $tables = Illuminate\Support\Facades\DB::select('SHOW TABLES LIKE "payouts"');
        echo 'tabel payouts : '.(count($tables) ? 'ADA' : 'BELUM ADA (migrasi belum jalan)')."\n";
        $col = Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM events LIKE 'sembunyikan_sisa_kuota'");
        echo 'kolom events.sembunyikan_sisa_kuota : '.(count($col) ? 'ADA' : 'BELUM ADA')."\n";
    } catch (\Throwable $e) {
        echo '!!! DATABASE GAGAL: '.get_class($e).': '.$e->getMessage()."\n";
    }
} catch (\Throwable $e) {
    echo "\n!!! EXCEPTION !!!\n";
    echo get_class($e).': '.$e->getMessage()."\n";
    echo $e->getFile().':'.$e->getLine()."\n";
    echo substr($e->getTraceAsString(), 0, 2000)."\n";
}