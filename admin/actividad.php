<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Chequeo admin rápido (misma lógica del dashboard)
$rol = NULL;
if (isset($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
  if (isset($_SESSION['usuario']['rol']))        $rol = $_SESSION['usuario']['rol'];
  elseif (isset($_SESSION['usuario']['tipo']))   $rol = $_SESSION['usuario']['tipo'];
  elseif (isset($_SESSION['usuario']['perfil'])) $rol = $_SESSION['usuario']['perfil'];
}
if (!$rol && isset($_SESSION['rol']))    $rol = $_SESSION['rol'];
if (!$rol && isset($_SESSION['perfil'])) $rol = $_SESSION['perfil'];
if (!$rol && isset($_SESSION['tipo']))   $rol = $_SESSION['tipo'];
$rolNorm = is_string($rol) ? strtolower(trim($rol)) : $rol;
if (!($rolNorm === 'admin' || $rolNorm === 'administrador' || $rolNorm === 1)) {
  header("Location: login.php"); exit;
}

// Conexión + helper
require_once __DIR__ . "/../bd/conexion.php";  // ajusta si tu conexion.php está en otra ruta
require_once __DIR__ . "/helpers/audit.php";

// Opcional: log de acceso al módulo
log_activity($conn, 'enter_module', 'modulo', 'actividad');

// Traer últimos 200
$sql = "SELECT a.*, u.nombre AS usuario_nombre, u.email AS usuario_email
        FROM audit_log a
        LEFT JOIN usuarios u ON u.id = a.user_id
        ORDER BY a.created_at DESC
        LIMIT 200";
$res = $conn->query($sql);
$rows = array();
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
      <a href="/admin/dashboard.php" class="px-4 py-2 rounded-2xl border transition-all duration-300 hover:scale-[1.01]">Volver</a>
    </div>

    <div class="bg-white border border-gray-300 rounded-2xl shadow-2xl">
      <div class="overflow-x-auto">
        <table class="w-full table-auto">
          <thead class="bg-gray-100">
            <tr>
              <th class="text-left p-3 border-b">Fecha</th>
              <th class="text-left p-3 border-b">Usuario</th>
              <th class="text-left p-3 border-b">Acción</th>
              <th class="text-left p-3 border-b">Entidad</th>
              <th class="text-left p-3 border-b">Meta</th>
              <th class="text-left p-3 border-b">IP</th>
              <th class="text-left p-3 border-b">Agente</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="p-6 text-center text-gray-600">Sin actividad registrada</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="p-3 border-b"><?=h($r['created_at'])?></td>
              <td class="p-3 border-b">
                <?=h(isset($r['usuario_nombre']) ? $r['usuario_nombre'] : '—')?>
                <div class="text-xs text-gray-500"><?=h(isset($r['usuario_email']) ? $r['usuario_email'] : '')?></div>
              </td>
              <td class="p-3 border-b"><?=h($r['action'])?></td>
              <td class="p-3 border-b">
                <?php if (!empty($r['entity_type'])): ?>
                  <span class="inline-block px-2 py-1 text-xs rounded-full border">
                    <?=h($r['entity_type'])?>#<?=h($r['entity_id'])?>
                  </span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="p-3 border-b align-top">
                <?php if (!empty($r['meta'])): ?>
                  <details class="cursor-pointer">
                    <summary class="text-sm" style="color:#e96510;">Ver</summary>
                    <pre class="mt-2 text-xs bg-gray-100 p-2 rounded"><?=h($r['meta'])?></pre>
                  </details>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="p-3 border-b"><?=h($r['ip'])?></td>
              <td class="p-3 border-b">
                <div class="max-w-[320px] truncate" title="<?=h($r['user_agent'])?>"><?=h($r['user_agent'])?></div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="p-4 text-sm text-gray-600">Mostrando los últimos 200 eventos.</div>
    </div>
  </div>
</body>
</html>