<?php
require_once __DIR__ . '/_auth.php';
require_login();
require_once dirname(__DIR__) . '/db/conexion.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "Evento inválido"; exit; }

// nombre del evento (para nombre archivo)
$evNombre = 'evento';
$stmt = $conn->prepare("SELECT nombre FROM eventos WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($evNombreDb);
if ($stmt->fetch()) { $evNombre = $evNombreDb; }
$stmt->close();

// consulta inscritos
$sql = "SELECT tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, email_personal, email_corporativo, medio
        FROM inscritos
        WHERE evento_id = ?
        ORDER BY id ASC";
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param('i', $id);
$stmt2->execute();
$stmt2->store_result();
$stmt2->bind_result($tipo, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad, $emailp, $emailc, $medio);

// headers CSV
$filename = 'inscritos_' . preg_replace('/[^A-Za-z0-9_-]+/','_', $evNombre) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output', 'w');
// encabezados
fputcsv($out, array('tipo_inscripcion','nombre','cedula','cargo','entidad','celular','ciudad','email_personal','email_corporativo','medio'));

while ($stmt2->fetch()) {
  fputcsv($out, array($tipo, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad, $emailp, $emailc, $medio));
}
fclose($out);
$stmt2->close();
$conn->close();
exit;
