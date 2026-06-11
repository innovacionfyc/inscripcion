<?php
require_once __DIR__ . '/_auth.php';
require_login();
require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';
$back_to = 'dashboard.php';
$show_back = true;
include __DIR__ . '/_topbar.php';

// Traer eventos (contando inscritos + datos del comercial)
$sql = "SELECT
          e.id,
          e.nombre,
          e.slug,
          e.modalidad,
          e.fecha_limite,
          e.imagen,
          (SELECT COUNT(1) FROM inscritos i WHERE i.evento_id = e.id) AS total_inscritos,
          u.nombre AS comercial_nombre,
          u.email  AS comercial_email
        FROM eventos e
        LEFT JOIN usuarios u ON u.id = e.comercial_user_id
        ORDER BY e.id DESC";
$rs = $conn->query($sql);

// (opcional, útil en Plesk si algo falla)
if (!$rs) { error_log('[eventos.php] SQL error: ' . $conn->error); }

$eventos = array();
if ($rs) {
  while ($row = $rs->fetch_assoc()) { $eventos[] = $row; }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Eventos activos</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="max-w-5xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-[#942934]">📅 Eventos</h1>
      <a href="dashboard.php" class="text-sm text-white bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-xl">Volver</a>
    </div>

    <?php if (empty($eventos)): ?>
      <div class="bg-white rounded-2xl shadow p-6 border text-center text-gray-600">
        No hay eventos creados.
      </div>
    <?php else: ?>
      <div class="grid gap-4">
        <?php foreach ($eventos as $ev):
          $formUrl = base_url('registro.php?e=' . urlencode($ev['slug'])); ?>
          <div class="bg-white rounded-2xl shadow p-4 border flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
              <img src="<?php echo htmlspecialchars('../uploads/eventos/' . $ev['imagen']); ?>" class="w-20 h-16 object-cover rounded-xl border" alt="">
              <div>
                <div class="font-bold text-[#d32f57]"><?php echo htmlspecialchars($ev['nombre']); ?></div>
                <div class="text-sm text-gray-600">
                  Modalidad: <?php echo htmlspecialchars($ev['modalidad']); ?> •
                  Límite: <?php echo htmlspecialchars($ev['fecha_limite']); ?> •
                  Inscritos: <span class="font-semibold"><?php echo (int)$ev['total_inscritos']; ?></span>
                </div>

                <!-- 🔧 Cambiado $row -> $ev -->
                <p class="text-sm text-gray-600">
                  Asignado a:
                  <span class="font-semibold">
                    <?php echo htmlspecialchars($ev['comercial_nombre'] ?: '—', ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                  <?php if (!empty($ev['comercial_email'])): ?>
                    <span class="text-gray-500">
                      (<?php echo htmlspecialchars($ev['comercial_email'], ENT_QUOTES, 'UTF-8'); ?>)
                    </span>
                  <?php endif; ?>
                </p>

                <div class="text-xs text-gray-500 break-all mt-1">
                  <?php echo htmlspecialchars($formUrl); ?>
                </div>
              </div>
            </div>

            <div class="flex flex-wrap gap-2 md:justify-end">
              <!-- Copiar URL -->
              <button
                onclick="copiar('<?php echo htmlspecialchars($formUrl, ENT_QUOTES, 'UTF-8'); ?>')"
                class="bg-[#d32f57] hover:bg-[#942934] text-white px-3 py-2 rounded-xl text-sm">
                📋 Copiar enlace
              </button>

              <!-- Editar evento (la URL pública no cambia) -->
              <a href="editar_evento.php?id=<?php echo (int)$ev['id']; ?>"
                 class="bg-sky-600 hover:bg-sky-700 text-white px-3 py-2 rounded-xl text-sm">
                ✏️ Editar
              </a>

              <!-- Exportar CSV -->
              <a href="evento_export_xls.php?id=<?php echo (int)$ev['id']; ?>"
                 class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-xl text-sm">
                ⬇️ Exportar inscritos (Excel)
              </a>

              <!-- 🔧 Cambiado $row -> $ev -->
              <a href="inscritos.php?evento_id=<?php echo (int)$ev['id']; ?>"
                 class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-xl transition-all">
                👥 Ver inscritos
              </a>

              <!-- Eliminar (solo admin) -->
              <?php if (!empty($_SESSION['rol']) && $_SESSION['rol']==='admin'): ?>
                <form method="POST" action="evento_delete.php" onsubmit="return confirmarEliminar();" class="inline">
                  <input type="hidden" name="id" value="<?php echo (int)$ev['id']; ?>">
                  <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-xl text-sm">
                    🗑️ Eliminar
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
    function copiar(texto) {
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(function(){ alert('Enlace copiado'); });
      } else {
        const t = document.createElement('textarea');
        t.value = texto; document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); alert('Enlace copiado'); } finally { document.body.removeChild(t); }
      }
    }
    function confirmarEliminar() {
      return confirm('¿Eliminar este evento? También se eliminarán sus fechas y, opcionalmente, sus inscritos.');
    }
  </script>
</body>
</html>
