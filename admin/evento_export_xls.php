<?php
// admin/evento_export_xls.php  (compatible con PHP antiguo)
require_once __DIR__ . '/_auth.php';
require_login();

// Conexión a la BD (usa la ruta que corresponda en tu proyecto)
require_once __DIR__ . '/bd/conexion.php';           // ← si tu carpeta es /bd
// require_once dirname(__DIR__) . '/db/conexion.php'; // ← si es /db

// Helper de auditoría
require_once __DIR__ . '/helpers/audit.php';

// ID del evento (o slug si usas slug)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "Evento inválido"; exit; }

// Registrar exportación (ahora sí tenemos $conn e ID)
log_activity($conn, 'export_inscritos', 'evento', $id, array('formato' => 'xlsx'));

// ... aquí sigues con la generación del XLSX/CSV y el output

// Nombre del evento
$evNombre = 'evento';
$stmt = $conn->prepare("SELECT nombre FROM eventos WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($evNombreDb);
if ($stmt->fetch()) { $evNombre = $evNombreDb; }
$stmt->close();

// Inscritos
$sql = "SELECT tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, email_personal, email_corporativo, medio
        FROM inscritos
        WHERE evento_id = ?
        ORDER BY id ASC";
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param('i', $id);
$stmt2->execute();
$stmt2->store_result();
$stmt2->bind_result($tipo, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad, $emailp, $emailc, $medio);

// Nombre de archivo “bonito”
$filename = 'inscritos_' . preg_replace('/[^A-Za-z0-9_-]+/','_', $evNombre) . '.xls';

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
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 6px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
    th { background: #942934; color: #fff; text-align: left; }
    .head { font-weight: bold; font-size: 14px; }
    .text { mso-number-format:"\@"; } /* fuerza texto en Excel (no números) */
    .wrap { mso-wrap: normal; white-space: normal; }
    .center { text-align:center; }
  </style>
</head>
<body>
  <table>
    <tr>
      <td colspan="10" class="head">Inscritos – <?php echo htmlspecialchars($evNombre, ENT_QUOTES, 'UTF-8'); ?></td>
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
    </tr>
    <?php while ($stmt2->fetch()): ?>
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
      </tr>
    <?php endwhile; ?>
  </table>
</body>
</html>
<?php
$stmt2->close();
$conn->close();
exit;
