<?php
require_once __DIR__ . '/_auth.php';
require_role('admin'); // solo admin elimina
require_once dirname(__DIR__) . '/db/conexion.php';

// --- helpers seguros para borrar ---
function fyc_safe_unlink($abs) {
    if ($abs && is_file($abs)) { @unlink($abs); }
}
function fyc_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        if (is_dir($p)) {
            fyc_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "Evento inválido"; exit; }

// --- obtener datos del evento ANTES de borrar (para eliminar archivos) ---
$img = $firma = '';
if ($st = $conn->prepare("SELECT imagen, firma_imagen FROM eventos WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $id);
    if ($st->execute()) {
        $st->bind_result($img, $firma);
        $st->fetch();
    }
    $st->close();
}

// --- primero borra dependencias (por si no hay FK ON DELETE CASCADE) ---
$stmt1 = $conn->prepare("DELETE FROM eventos_fechas WHERE evento_id = ?");
$stmt1->bind_param('i', $id);
$stmt1->execute();
$stmt1->close();

/*
// OPCIONAL: borra inscritos del evento y sus soportes en disco
// Descomenta si quieres que al eliminar el evento *también* se eliminen sus inscritos y archivos.
if ($stI = $conn->prepare("SELECT soporte_pago FROM inscritos WHERE evento_id = ?")) {
    $stI->bind_param('i', $id);
    if ($stI->execute()) {
        $stI->bind_result($soporteRel);
        while ($stI->fetch()) {
            if (!empty($soporteRel)) {
                $abs = rtrim(dirname(__DIR__), '/') . '/' . ltrim($soporteRel, '/');
                fyc_safe_unlink($abs);
            }
        }
    }
    $stI->close();

    $delIns = $conn->prepare("DELETE FROM inscritos WHERE evento_id = ?");
    $delIns->bind_param('i', $id);
    $delIns->execute();
    $delIns->close();
}
*/

// --- ahora borra el evento ---
$stmt3 = $conn->prepare("DELETE FROM eventos WHERE id = ?");
$stmt3->bind_param('i', $id);
$stmt3->execute();
$stmt3->close();

// --- borrar archivos/carpetas en disco ---
$base = rtrim(dirname(__DIR__), '/');

// imagen principal del evento
if (!empty($img)) {
    $imgAbs = $base . '/uploads/eventos/' . basename($img);
    fyc_safe_unlink($imgAbs);
}

// firma del evento (si la guardas por evento)
if (!empty($firma)) {
    $firmaAbs = $base . '/uploads/firmas/' . basename($firma);
    fyc_safe_unlink($firmaAbs);
}

// carpetas de adjuntos por evento
$dirVirtual     = $base . '/docs/evento_virtual/' . $id;
$dirPresencial  = $base . '/docs/evento_presencial/' . $id;
fyc_rrmdir($dirVirtual);
fyc_rrmdir($dirPresencial);

$conn->close();
header('Location: eventos.php');
exit;
