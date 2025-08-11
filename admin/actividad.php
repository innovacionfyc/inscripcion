<?php
// admin/actividad.php
session_start();

// Ajusta según tu sesión real:
session_start();
if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario']['rol']) || $_SESSION['usuario']['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Actividad</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Usa la misma hoja de estilos que tu panel -->
  <link rel="stylesheet" href="/assets/css/output.css">
</head>
<body class="min-h-screen bg-gray-50">
  <div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold" style="color:#942934;">Actividad</h1>
      <a href="dashboard.php" class="px-4 py-2 rounded-2xl border transition-all duration-300 hover:scale-[1.01]">Volver</a>
    </div>

    <div class="bg-white border border-gray-300 rounded-2xl shadow-2xl p-8">
      <p class="text-gray-700">Aquí vamos a listar los accesos y acciones del panel. (Página protegida solo para administrador)</p>
    </div>
  </div>
</body>
</html>