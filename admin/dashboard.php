<?php
require_once __DIR__ . '/_auth.php';
require_login();

$show_back = false; // en el dashboard no mostramos "Volver"

// 🔧 CONEXIÓN: sube un nivel y entra a /db/
require_once dirname(__DIR__) . '/db/conexion.php';

// Helper de auditoría (está dentro de /admin/helpers/)
require_once __DIR__ . '/helpers/audit.php';

// Registrar acceso al dashboard
log_activity($conn, 'enter_module', 'modulo', 'dashboard');

include __DIR__ . '/_topbar.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="max-w-5xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-[#942934]">¡Hola, <?php echo htmlspecialchars($_SESSION['nombre']); ?>!</h1>
      <a href="logout.php" class="text-sm text-white bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-xl">Salir</a>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <!-- Crear evento -->
      <a href="crear_evento.php" class="block bg-white rounded-2xl shadow p-6 border hover:shadow-lg transition">
        <div class="text-[#d32f57] text-xl font-bold mb-2">➕ Crear evento</div>
        <p class="text-gray-600 text-sm">Configura un nuevo evento y obtén su enlace de inscripción.</p>
      </a>

      <!-- Eventos activos -->
      <a href="eventos.php" class="block bg-white rounded-2xl shadow p-6 border hover:shadow-lg transition">
        <div class="text-[#d32f57] text-xl font-bold mb-2">📅 Eventos activos</div>
        <p class="text-gray-600 text-sm">Ver lista, copiar enlace, exportar inscritos o eliminar.</p>
      </a>

      <!-- Usuarios (solo admin) -->
      <?php if (!empty($_SESSION['rol']) && $_SESSION['rol']==='admin'): ?>
      <a href="usuarios.php" class="block bg-white rounded-2xl shadow p-6 border hover:scale-[1.01] transition-all duration-300">
        <div class="text-[#d32f57] text-xl font-bold mb-2">👥 Usuarios y roles</div>
        <p class="text-gray-600 text-sm">Crear/eliminar usuarios y asignar permisos.</p>
      </a>
      <?php endif; ?>

      <?php
      // Detectar rol desde varias posibles llaves
      $role = null;
      if (isset($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
          if (isset($_SESSION['usuario']['rol']))        $role = $_SESSION['usuario']['rol'];
          elseif (isset($_SESSION['usuario']['tipo']))   $role = $_SESSION['usuario']['tipo'];
          elseif (isset($_SESSION['usuario']['perfil'])) $role = $_SESSION['usuario']['perfil'];
      }
      if (!$role && isset($_SESSION['rol']))    $role = $_SESSION['rol'];
      if (!$role && isset($_SESSION['perfil'])) $role = $_SESSION['perfil'];
      if (!$role && isset($_SESSION['tipo']))   $role = $_SESSION['tipo'];
      $roleNorm = is_string($role) ? strtolower(trim($role)) : $role;
      ?>

      <?php if ($roleNorm === 'admin' || $roleNorm === 'administrador' || $roleNorm === 1): ?>
        <a href="actividad.php" class="block bg-white rounded-2xl shadow p-6 border hover:scale-[1.01] transition-all duration-300">
          <div class="text-[#d32f57] text-xl font-bold mb-2"> 📈 Actividad</div>
          <p class="text-gray-600 text-sm">Últimos accesos y movimientos del panel.</p>
        </a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
