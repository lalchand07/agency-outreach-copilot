<?php

/*
 * Front controller. Works with Apache (see .htaccess), nginx, or the built-in
 * server:  php -S 127.0.0.1:8080 -t public public/index.php
 */

declare(strict_types=1);

// Let the built-in server serve real files (CSS, JS) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    echo 'Run "composer install" first (or upload the vendor/ folder).';
    exit;
}
require $root . '/vendor/autoload.php';

$app = App\App::boot($root);
ini_set('display_errors', $app->isProduction() ? '0' : '1');

(new App\Http\Kernel($app, new App\Http\Session()))
    ->handle(App\Http\Request::fromGlobals())
    ->send();
