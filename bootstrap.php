<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $path = ROOT . '/app/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$GLOBALS['cfg'] = require ROOT . '/config.php';
require ROOT . '/app/helpers.php';
require ROOT . '/app/ui.php';

date_default_timezone_set('Africa/Kigali');

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', cfg('env') === 'dev' ? '1' : '0');
    error_reporting(E_ALL);

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

    session_name('mthix_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
