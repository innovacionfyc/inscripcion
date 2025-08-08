<?php
if (!defined('APP_BASE')) define('APP_BASE', '/public');

if (!function_exists('base_url')) {
  function base_url(string $path = ''): string {
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
}
