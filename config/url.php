<?php
// App en: https://inscripcion.fycconsultores.com/public/
// Por eso APP_BASE = '/public'
define('APP_BASE', '/public');

function base_url(string $path = ''): string {
    // Detecta HTTPS incluso detrás de proxy/CDN (Plesk)
    $scheme = 'http';
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'inscripcion.fycconsultores.com';
    $base = rtrim(APP_BASE, '/');
    $uri  = ltrim($path, '/');

    return $scheme . '://' . $host . ($base ? '/' . $base : '') . ($uri ? '/' . $uri : '/');
}
