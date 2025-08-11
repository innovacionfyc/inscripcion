<?php
// admin/_topbar.php  (compatible PHP < 7)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Config por defecto
$back_to   = isset($back_to) ? $back_to : 'dashboard.php';
$show_back = isset($show_back) ? (bool)$show_back : true;

// Evita notice si no hay nombre
$nombreUsuario = isset($_SESSION['nombre']) ? $_SESSION['nombre'] : '';
?>
<div class="bg-white border-b shadow-sm">
  <div class="max-w-5xl mx-auto px-6 py-3 flex items-center justify-between">

    <div class="flex items-center gap-3">
      <?php if ($show_back): ?>
        <a href="<?php echo htmlspecialchars($back_to, ENT_QUOTES, 'UTF-8'); ?>"
           class="inline-flex items-center gap-2 bg-[#d32f57] hover:bg-[#942934] text-white font-semibold px-4 py-2 rounded-xl shadow-md transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
          ← Volver
        </a>
      <?php else: ?>
        <span class="text-[#942934] font-bold text-lg">Panel</span>
      <?php endif; ?>
    </div>

    <div class="flex items-center gap-3">
      <span class="text-sm text-gray-600">
        👤 <?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <a href="logout.php"
         class="inline-flex items-center gap-2 bg-gray-600 hover:bg-gray-700 text-white font-medium px-4 py-2 rounded-xl shadow-md transition-all duration-300 hover:scale-[1.02] active:scale-[0.98]">
        🚪 Cerrar sesión
      </a>
    </div>

  </div>
</div>