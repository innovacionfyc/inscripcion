<?php
// Tu app vive en https://inscripcion.fycconsultores.com/public/
if (!defined('APP_BASE')) define('APP_BASE', 'public'); // <-- sin slash

if (!function_exists('base_url')) {
  function base_url($path = '') {
    // Compatible con versiones viejas (sin tipado ni features de PHP 8)
    $scheme = 'http';
    if (
      (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
      (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
      (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
      $scheme = 'https';
    }

    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'inscripcion.fycconsultores.com';
    $base = trim(APP_BASE, '/');           // <- quita posibles slashes
    $uri  = ltrim((string)$path, '/');     // <- normaliza el path

    $url = $scheme . '://' . $host;
    if ($base !== '') $url .= '/' . $base;
    if ($uri  !== '') $url .= '/' . $uri;
    else $url .= '/';

    return $url;
  }
}
