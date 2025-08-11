<?php /* DEBUG: quitar luego */
echo '<!-- '; var_dump($_SESSION); echo ' -->';
?>


<?php
require_once __DIR__ . '/_auth.php';
require_login();
$show_back = false; // en el dashboard no mostramos "Volver"
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
      <a href="usuarios.php" class="block bg-white rounded-2xl shadow p-6 border hover:shadow-lg transition">
        <div class="text-[#d32f57] text-xl font-bold mb-2">👥 Usuarios y roles</div>
        <p class="text-gray-600 text-sm">Crear/eliminar usuarios y asignar permisos.</p>
      </a>
      <?php endif; ?>

      <?php if (isset($_SESSION['usuario']) && isset($_SESSION['usuario']['rol']) && $_SESSION['usuario']['rol'] === 'admin'): ?>
        <a href="actividad.php" class="block bg-white border border-gray-300 rounded-2xl shadow-2xl p-6 hover:scale-[1.01] transition-all duration-300">
          <div class="flex items-center gap-3">
            <span class="text-3xl">📈</span>
            <h3 class="text-xl font-bold" style="color:#942934;">Actividad</h3>
          </div>
          <p class="mt-2 text-gray-600">Últimos accesos y movimientos del panel.</p>
        </a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
