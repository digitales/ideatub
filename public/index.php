<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// nginx / PHP-FPM often omit Authorization from $_SERVER; OAuth Bearer + MCP need it.
if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    foreach (['REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $redirectKey) {
        if (! empty($_SERVER[$redirectKey])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER[$redirectKey];
            break;
        }
    }
}

if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_X_AUTHORIZATION'] ?? null;
    if (! is_string($auth) || $auth === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'authorization' && is_string($value) && $value !== '') {
                    $auth = $value;
                    break;
                }
            }
        }
    }
    if ((! is_string($auth) || $auth === '') && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && is_string($value) && $value !== '') {
                $auth = $value;
                break;
            }
        }
    }
    if (is_string($auth) && $auth !== '') {
        $_SERVER['HTTP_AUTHORIZATION'] = $auth;
    }
}

$app->handleRequest(Request::capture());
