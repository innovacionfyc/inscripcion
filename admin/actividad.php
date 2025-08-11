<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Actividad (test)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/css/output.css">
</head>
<body class="min-h-screen bg-gray-50">
  <div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold" style="color:#942934;">Actividad (test)</h1>
    <p class="mt-2">Si ves esta página, el enlace del dashboard funciona 🔥</p>
    <a href="dashboard.php" class="mt-4 inline-block px-4 py-2 rounded-2xl border">Volver</a>
  </div>
</body>
</html>
