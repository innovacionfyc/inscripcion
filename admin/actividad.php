<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['usuario'])) { header("Location: login.php"); exit; }

$rol = null;
if (isset($_SESSION['usuario']['rol'])) $rol = $_SESSION['usuario']['rol'];
elseif (isset($_SESSION['rol'])) $rol = $_SESSION['rol'];
$rol = is_string($rol) ? strtolower($rol) : $rol;

if (!($rol === 'admin' || $rol === 'administrador' || $rol === 1)) {
  header("Location: login.php"); exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Actividad</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/css/output.css">
</head>
<body class="min-h-screen bg-gray-50">
  <div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold" style="color:#942934;">Actividad</h1>
      <a href="dashboard.php" class="px-4 py-2 rounded-2xl border transition-all duration-300 hover:scale-[1.01]">Volver</a>
    </div>
    <div class="bg-white border border-gray-300 rounded-2xl shadow-2xl p-8">
      <p class="text-gray-700">Página solo para administrador. Aquí pondremos el historial.</p>
    </div>
  </div>
</body>
</html>