<?php
// admin/evento_export_xls.php  (compatible con PHP antiguo)
require_once __DIR__ . '/_auth.php';
require_login();
date_default_timezone_set('America/Bogota');

// ✅ Conexión a la BD: sube un nivel y entra a /db/
require_once dirname(__DIR__) . '/db/conexion.php';

// Helper de auditoría
require_once __DIR__ . '/helpers/audit.php';

// ID del evento (o slug si usas slug)
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo "Evento inválido";
  exit;
}

// Registrar exportación (ahora sí tenemos $conn e ID)
log_activity($conn, 'export_inscritos', 'evento', $id, array('formato' => 'xlsx'));

// Nombre del evento
$evNombre = 'evento';
$stmt = $conn->prepare("SELECT nombre FROM eventos WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($evNombreDb);
if ($stmt->fetch()) {
  $evNombre = $evNombreDb;
}
$stmt->close();

/* ========== MAPA DE FECHAS PARA ASISTENCIA POR MÓDULOS ========== */
$fechas_map = array(); // 'YYYY-mm-dd' => 'Día N (dd/mm)'
$stmtF = $conn->prepare("SELECT fecha FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC");
$stmtF->bind_param('i', $id);
$stmtF->execute();
$stmtF->bind_result($f_fecha);
$idx = 0;
while ($stmtF->fetch()) {
  $idx++;
  $fechas_map[$f_fecha] = 'Día ' . $idx . ' (' . date('d/m', strtotime($f_fecha)) . ')';
}
$stmtF->close();

function asistencia_humana($tipo, $mods_csv, $fechas_map)
{
  $tipo = strtoupper(trim((string) $tipo));
  if ($tipo === 'COMPLETO')
    return 'Completo';
  if ($tipo === 'MODULOS') {
    $out = array();
    foreach (explode(',', (string) $mods_csv) as $v) {
      $v = trim($v);
      if ($v === '')
        continue;
      $out[] = isset($fechas_map[$v]) ? $fechas_map[$v] : date('d/m', strtotime($v));
    }
    return 'Módulos: ' . (empty($out) ? '—' : implode(', ', $out));
  }
  return '—';
}

/* ========== INSCRITOS (AGREGANDO CAMPOS NUEVOS) ========== */
$sql = "SELECT 
  tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad,
  email_personal, email_corporativo, medio,
  asistencia_tipo, modulos_seleccionados, whatsapp_consent, fecha_registro,
  CONVERT_TZ(fecha_registro, '+00:00', '-05:00') AS fecha_co
FROM inscritos
WHERE evento_id = ?
ORDER BY id ASC";
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param('i', $id);
$stmt2->execute();
$stmt2->store_result();
$stmt2->bind_result(
  $tipo,
  $nombre,
  $cedula,
  $cargo,
  $entidad,
  $celular,
  $ciudad,
  $emailp,
  $emailc,
  $medio,
  $asis_tipo,
  $mods_csv,
  $wa_consent,
  $f_reg,
  $fecha_registro,
  $fecha_co
);

// Nombre de archivo “bonito”
$filename = 'inscritos_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $evNombre) . '.xls';

// Cabeceras para Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Opcional: BOM para que respete tildes en algunos Excel
echo "\xEF\xBB\xBF";

// HTML que Excel interpreta como hoja
?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8">
  <style>
    table {
      border-collapse: collapse;
      width: 100%;
    }

    th,
    td {
      border: 1px solid #ddd;
      padding: 6px;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
    }

    th {
      background: #942934;
      color: #fff;
      text-align: left;
    }

    .head {
      font-weight: bold;
      font-size: 14px;
    }

    .text {
      mso-number-format: "\@";
    }

    .wrap {
      mso-wrap: normal;
      white-space: normal;
    }

    .center {
      text-align: center;
    }
  </style>
</head>

<body>
  <table>
    <tr>
      <td colspan="14" class="head">Inscritos – <?php echo htmlspecialchars($evNombre, ENT_QUOTES, 'UTF-8'); ?></td>
    </tr>
  </table>

  <table>
    <tr>
      <th>Tipo inscripción</th>
      <th>Nombre</th>
      <th class="text">Cédula</th>
      <th>Cargo</th>
      <th>Entidad</th>
      <th class="text">Celular</th>
      <th>Ciudad</th>
      <th>Email personal</th>
      <th>Email corporativo</th>
      <th>Medio</th>
      <th>Asistencia</th>
      <th>Módulos (detalle)</th>
      <th>Consentimiento WhatsApp</th>
      <th>Fecha de inscripción</th>
      <td class="wrap">
        <?php echo $fechaCo ? date('d/m/Y g:i a', strtotime($fechaCo)) : ''; ?>
      </td>
    </tr>
    <?php while ($stmt2->fetch()): ?>
      <?php
      // Asistencia humana y módulos detalle
      $asist_txt = asistencia_humana($asis_tipo, $mods_csv, $fechas_map);

      $mods_txt = '';
      if (strtoupper((string) $asis_tipo) === 'MODULOS' && !empty($mods_csv)) {
        $parts = array();
        foreach (explode(',', $mods_csv) as $v) {
          $v = trim($v);
          if ($v === '')
            continue;
          $parts[] = isset($fechas_map[$v]) ? $fechas_map[$v] : date('d/m', strtotime($v));
        }
        $mods_txt = implode(', ', $parts);
      }

      // Consentimiento
      $wa_txt = '';
      if (is_string($wa_consent) && $wa_consent !== '') {
        $W = strtoupper($wa_consent);
        $wa_txt = ($W === 'SI' || $W === 'NO') ? $W : '';
      }

      // Fecha de inscripción
      $fecha_txt = '';
      if (!empty($f_reg)) {
        $ts = strtotime($f_reg);
        $fecha_txt = $ts ? date('d/m/Y g:i a', $ts) : '';
      }
      ?>
      <tr>
        <td><?php echo htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text"><?php echo htmlspecialchars($cedula, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($cargo, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($entidad, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="text"><?php echo htmlspecialchars($celular, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($ciudad, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($emailp, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($emailc, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($medio, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($asist_txt, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="wrap"><?php echo htmlspecialchars($mods_txt, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="center"><?php echo htmlspecialchars($wa_txt, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="center"><?php echo htmlspecialchars($fecha_txt, ENT_QUOTES, 'UTF-8'); ?></td>
      </tr>
    <?php endwhile; ?>
  </table>
</body>

</html>
<?php
$stmt2->close();
$conn->close();
exit;
