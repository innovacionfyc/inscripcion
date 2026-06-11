<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/_auth.php';
require_login();
require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';
require_once __DIR__ . '/helpers/audit.php';

/* ============================================================
   Edición de eventos — Fase 1
   - El slug NUNCA se modifica: la URL pública no cambia.
   - La modalidad y las banderas congreso_* son SOLO LECTURA.
   - Módulos virtuales: UPDATE por id / INSERT nuevos / activo=0
     para los quitados. Nunca DELETE.
   ============================================================ */

if (!function_exists('hx')) {
  function hx($s)
  {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('norm_modalidad')) {
  function norm_modalidad($m)
  {
    $m = strtolower(trim((string) $m));
    $m = str_replace(array(' ', '-'), '_', $m);
    return $m;
  }
}

// Helper: guardarAdjuntos (idéntico a crear_evento.php)
if (!function_exists('guardarAdjuntos')) {
  function guardarAdjuntos($inputName, $destDir)
  {
    $permitidas = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp');
    if (!isset($_FILES[$inputName]))
      return 0;

    if (!is_dir($destDir)) {
      @mkdir($destDir, 0775, true);
    }

    $moved = 0;

    $names = $_FILES[$inputName]['name'];
    $tmps = $_FILES[$inputName]['tmp_name'];
    $errs = $_FILES[$inputName]['error'];
    $sizes = $_FILES[$inputName]['size'];

    if (!is_array($names)) {
      $names = array($names);
      $tmps = array($tmps);
      $errs = array($errs);
      $sizes = array($sizes);
    }

    for ($i = 0; $i < count($names); $i++) {
      if ($errs[$i] !== UPLOAD_ERR_OK)
        continue;

      $tmp = $tmps[$i];
      $name = $names[$i];
      $size = (int) $sizes[$i];

      if (!is_uploaded_file($tmp))
        continue;

      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($ext, $permitidas))
        continue;

      if ($size > 25 * 1024 * 1024)
        continue;

      $seguro = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name);
      $seguro = date('Ymd_His') . '_' . mt_rand(1000, 9999) . '_' . $seguro;

      @move_uploaded_file($tmp, rtrim($destDir, '/') . '/' . $seguro) && $moved++;
    }

    return $moved;
  }
}

// -----------------------------------------------------------
// Cargas de datos (reutilizadas en GET y POST)
// -----------------------------------------------------------
function ev_cargar_evento($conn, $id)
{
  $ev = null;
  $stmt = $conn->prepare("SELECT id, nombre, slug, imagen, modalidad, tipo_evento_presencial,
                                 congreso_presencial, congreso_virtual, lugar_personalizado,
                                 url_conexion, fecha_limite, comercial_user_id, autoestudio
                          FROM eventos WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->bind_result($e_id, $e_nombre, $e_slug, $e_imagen, $e_mod, $e_tipo, $e_cp, $e_cv, $e_lugar, $e_url, $e_fl, $e_com, $e_auto);
  if ($stmt->fetch()) {
    $ev = array(
      'id' => (int) $e_id,
      'nombre' => $e_nombre,
      'slug' => $e_slug,
      'imagen' => $e_imagen,
      'modalidad' => $e_mod,
      'tipo_evento_presencial' => $e_tipo,
      'congreso_presencial' => (int) $e_cp,
      'congreso_virtual' => (int) $e_cv,
      'lugar_personalizado' => $e_lugar,
      'url_conexion' => $e_url,
      'fecha_limite' => $e_fl,
      'comercial_user_id' => (int) $e_com,
      'autoestudio' => (int) $e_auto
    );
  }
  $stmt->close();
  return $ev;
}

function ev_cargar_fechas($conn, $id)
{
  $rows = array();
  $stmt = $conn->prepare("SELECT tipo, fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC, id ASC");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->bind_result($f_tipo, $f_fecha, $f_hi, $f_hf);
  while ($stmt->fetch()) {
    $rows[] = array(
      'tipo' => strtolower(trim((string) $f_tipo)),
      'fecha' => (string) $f_fecha,
      'hora_inicio' => substr((string) $f_hi, 0, 5),
      'hora_fin' => substr((string) $f_hf, 0, 5)
    );
  }
  $stmt->close();
  return $rows;
}

function ev_cargar_modulos($conn, $id)
{
  $rows = array();
  $stmt = $conn->prepare("SELECT id, orden, fecha, hora_inicio, hora_fin, nombre
                          FROM eventos_modulos_virtuales
                          WHERE evento_id = ? AND activo = 1
                          ORDER BY orden ASC, id ASC");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->bind_result($m_id, $m_ord, $m_fecha, $m_hi, $m_hf, $m_nom);
  while ($stmt->fetch()) {
    $rows[] = array(
      'id' => (int) $m_id,
      'orden' => (int) $m_ord,
      'fecha' => (string) $m_fecha,
      'hora_inicio' => substr((string) $m_hi, 0, 5),
      'hora_fin' => substr((string) $m_hf, 0, 5),
      'nombre' => (string) $m_nom
    );
  }
  $stmt->close();
  return $rows;
}

function ev_contar_inscritos($conn, $id)
{
  $n = 0;
  $stmt = $conn->prepare("SELECT COUNT(*) FROM inscritos WHERE evento_id = ?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->bind_result($n);
  $stmt->fetch();
  $stmt->close();
  return (int) $n;
}

// Firma normalizada de una lista de fechas para detectar cambios
function ev_firma_fechas($rows)
{
  $sig = array();
  foreach ($rows as $r) {
    $sig[] = $r['tipo'] . '|' . $r['fecha'] . '|' . $r['hora_inicio'] . '|' . $r['hora_fin'];
  }
  sort($sig);
  return implode(';', $sig);
}

// -----------------------------------------------------------
// Resolver evento
// -----------------------------------------------------------
$evento_id = 0;
if (isset($_POST['id'])) {
  $evento_id = (int) $_POST['id'];
} elseif (isset($_GET['id'])) {
  $evento_id = (int) $_GET['id'];
}

$evento = ($evento_id > 0) ? ev_cargar_evento($conn, $evento_id) : null;

if (!$evento) {
  http_response_code(404);
  echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Evento no encontrado</title>';
  echo '<link rel="stylesheet" href="../assets/css/output.css"></head>';
  echo '<body class="bg-gray-100 min-h-screen flex items-center justify-center">';
  echo '<div class="bg-white rounded-2xl shadow-2xl p-8 text-center">';
  echo '<p class="text-red-600 font-bold text-lg">⚠️ Evento no encontrado.</p>';
  echo '<a href="eventos.php" class="inline-block mt-4 bg-[#d32f57] hover:bg-[#942934] text-white font-bold px-6 py-3 rounded-xl">Volver a eventos</a>';
  echo '</div></body></html>';
  exit;
}

$modN = norm_modalidad($evento['modalidad']);
$es_ce = ($modN === 'curso_especial');
$es_phv = ($modN === 'públicamente_hablando_(virtual)');

// Componentes del evento (definen qué secciones se muestran/editan)
$tiene_presencial = ($modN === 'presencial' || $modN === 'hibrida' || ($es_ce && $evento['congreso_presencial'] === 1));
$tiene_virtual    = ($modN === 'virtual' || $modN === 'hibrida' || $es_phv || ($es_ce && $evento['congreso_virtual'] === 1));
$aplica_tipo_pres = $tiene_presencial; // selector Congreso/Seminario
$aplica_url       = ($modN === 'virtual' || $modN === 'hibrida' || $es_ce || $es_phv);

$fechas_actuales = ev_cargar_fechas($conn, $evento_id);
$modulos_actuales = $es_ce ? ev_cargar_modulos($conn, $evento_id) : array();
$total_inscritos = ev_contar_inscritos($conn, $evento_id);

// Clasificar fechas actuales por sección (las 'general' heredadas van a la única sección disponible)
$fechas_pres = array();
$fechas_virt = array();
foreach ($fechas_actuales as $f) {
  if ($f['tipo'] === 'presencial') {
    $fechas_pres[] = $f;
  } elseif ($f['tipo'] === 'virtual') {
    $fechas_virt[] = $f;
  } else { // 'general' u otro legado
    if ($tiene_presencial && !$tiene_virtual) {
      $fechas_pres[] = $f;
    } else {
      $fechas_virt[] = $f;
    }
  }
}

$error = '';
$DEFAULT_LUGAR_PRESENCIAL = "Centro de Convenciones Cafam Floresta\nAv. Cra. 68 No. 90-88, Bogotá - Salón Sauces";

// -----------------------------------------------------------
// POST: guardar cambios
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // ---- Campos simples ----
  $nombre = trim($_POST['nombre'] ?? '');
  $fecha_limite = trim($_POST['fecha_limite'] ?? '');
  $comercial_id = (int) ($_POST['comercial_user_id'] ?? 0);
  $autoestudio = isset($_POST['autoestudio']) ? 1 : 0;

  if ($nombre === '') {
    $error = 'El nombre del evento es obligatorio.';
  } elseif ($fecha_limite === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_limite)) {
    $error = 'La fecha límite es obligatoria y debe ser válida.';
  } elseif ($comercial_id <= 0) {
    $error = 'Debe seleccionar un comercial.';
  }

  // tipo_evento_presencial: solo si aplica; si llega inválido, conservar el actual
  $tipo_evento_presencial = $evento['tipo_evento_presencial'];
  if (empty($error) && $aplica_tipo_pres) {
    $tmpTipo = trim($_POST['tipo_evento_presencial'] ?? '');
    if ($tmpTipo === 'Congreso' || $tmpTipo === 'Seminario') {
      $tipo_evento_presencial = $tmpTipo;
    }
  }

  // lugar personalizado: solo si hay componente presencial; vacío = lugar por defecto
  $lugar_personalizado = $evento['lugar_personalizado'];
  if (empty($error) && $tiene_presencial) {
    $lugar_personalizado = trim($_POST['lugar_personalizado'] ?? '');
    if ($lugar_personalizado === '')
      $lugar_personalizado = null;
  }

  // URL de conexión: solo si aplica; vacía = null
  $url_conexion = $evento['url_conexion'];
  if (empty($error) && $aplica_url) {
    $raw_url = trim($_POST['url_conexion'] ?? '');
    if ($raw_url === '') {
      $url_conexion = null;
    } elseif (!filter_var($raw_url, FILTER_VALIDATE_URL)) {
      $error = 'La URL de conexión al evento no es válida.';
    } else {
      $url_conexion = $raw_url;
    }
  }

  // ---- Fechas enviadas (solo de las secciones que aplican) ----
  $fechas_nuevas = array();
  if (empty($error)) {
    if ($tiene_presencial) {
      $fp = $_POST['fechas_presencial'] ?? array();
      $hip = $_POST['hora_inicio_presencial'] ?? array();
      $hfp = $_POST['hora_fin_presencial'] ?? array();
      for ($i = 0; $i < count($fp); $i++) {
        $f = trim($fp[$i] ?? '');
        $hi = trim($hip[$i] ?? '');
        $hf = trim($hfp[$i] ?? '');
        if ($f === '' || $hi === '' || $hf === '')
          continue;
        $fechas_nuevas[] = array('tipo' => 'presencial', 'fecha' => $f, 'hora_inicio' => substr($hi, 0, 5), 'hora_fin' => substr($hf, 0, 5));
      }
    }
    if ($tiene_virtual) {
      $fv = $_POST['fechas_virtual'] ?? array();
      $hiv = $_POST['hora_inicio_virtual'] ?? array();
      $hfv = $_POST['hora_fin_virtual'] ?? array();
      for ($i = 0; $i < count($fv); $i++) {
        $f = trim($fv[$i] ?? '');
        $hi = trim($hiv[$i] ?? '');
        $hf = trim($hfv[$i] ?? '');
        if ($f === '' || $hi === '' || $hf === '')
          continue;
        $fechas_nuevas[] = array('tipo' => 'virtual', 'fecha' => $f, 'hora_inicio' => substr($hi, 0, 5), 'hora_fin' => substr($hf, 0, 5));
      }
    }
  }

  // Cambia fechas solo si se envió al menos una fila válida y difiere de lo actual.
  // Si el admin deja todo vacío, NO se borran las fechas existentes (protección).
  $cambia_fechas = (!empty($fechas_nuevas) && ev_firma_fechas($fechas_nuevas) !== ev_firma_fechas($fechas_actuales));

  // ---- Módulos enviados (solo Curso Especial) ----
  $mods_updates = array();   // [id => row]
  $mods_inserts = array();   // rows nuevos
  $mods_desactivar = array(); // ids a activo=0
  $cambia_modulos = false;

  if (empty($error) && $es_ce) {
    $mid = $_POST['modulo_id'] ?? array();
    $mnom = $_POST['modulo_nombre'] ?? array();
    $mfec = $_POST['modulo_fecha'] ?? array();
    $mhi = $_POST['modulo_hora_inicio'] ?? array();
    $mhf = $_POST['modulo_hora_fin'] ?? array();

    $map_actuales = array();
    foreach ($modulos_actuales as $m) {
      $map_actuales[$m['id']] = $m;
    }

    $ids_enviados = array();
    $orden = 0;
    $validos = 0;
    for ($i = 0; $i < count($mid); $i++) {
      if ($validos >= 10)
        break; // mismo límite que creación

      $id_m = (int) ($mid[$i] ?? 0);
      $nom = trim($mnom[$i] ?? '');
      $fec = trim($mfec[$i] ?? '');
      $hi = substr(trim($mhi[$i] ?? ''), 0, 5);
      $hf = substr(trim($mhf[$i] ?? ''), 0, 5);

      // fila vacía: ignorar
      if ($nom === '' && $fec === '' && $hi === '' && $hf === '')
        continue;
      // fila incompleta (igual que creación: fecha y nombre obligatorios): ignorar
      if ($nom === '' || $fec === '')
        continue;

      $validos++;
      $orden = $validos;
      $row = array('orden' => $orden, 'fecha' => $fec, 'hora_inicio' => $hi, 'hora_fin' => $hf, 'nombre' => $nom);

      if ($id_m > 0 && isset($map_actuales[$id_m])) {
        $ids_enviados[$id_m] = true;
        $act = $map_actuales[$id_m];
        if (
          $act['nombre'] !== $nom || $act['fecha'] !== $fec ||
          $act['hora_inicio'] !== $hi || $act['hora_fin'] !== $hf ||
          $act['orden'] !== $orden
        ) {
          $mods_updates[$id_m] = $row;
        }
      } elseif ($id_m === 0) {
        $mods_inserts[] = $row;
      }
      // id_m > 0 pero no pertenece a este evento (o ya inactivo): se ignora
    }

    foreach ($map_actuales as $id_m => $m) {
      if (!isset($ids_enviados[$id_m])) {
        $mods_desactivar[] = $id_m;
      }
    }

    $cambia_modulos = (!empty($mods_updates) || !empty($mods_inserts) || !empty($mods_desactivar));
  }

  // ---- Confirmación obligatoria si hay inscritos y cambios sensibles ----
  if (empty($error) && $total_inscritos > 0 && ($cambia_fechas || $cambia_modulos)) {
    if (empty($_POST['confirmar_cambios_sensibles'])) {
      $error = 'Este evento ya tiene ' . $total_inscritos . ' inscrito(s) y estás modificando fechas o módulos. '
        . 'Debes marcar la casilla de confirmación de cambios sensibles para guardar.';
    }
  }

  // ---- Imagen (opcional: conservar si no se sube nueva) ----
  $imagenFinal = $evento['imagen'];
  if (empty($error) && !empty($_FILES['imagen_evento']['name']) && $_FILES['imagen_evento']['error'] === 0) {
    $uploadsDir = dirname(__DIR__) . '/uploads/eventos/';
    if (!is_dir($uploadsDir))
      @mkdir($uploadsDir, 0775, true);
    $ext = pathinfo($_FILES['imagen_evento']['name'], PATHINFO_EXTENSION);
    $nuevaImagen = uniqid('evento_') . '.' . $ext;
    if (move_uploaded_file($_FILES['imagen_evento']['tmp_name'], $uploadsDir . $nuevaImagen)) {
      $imagenFinal = $nuevaImagen;
    }
  }

  // ---- Aplicar cambios ----
  if (empty($error)) {

    // UPDATE explícito de columnas permitidas.
    // ⚠️ slug, modalidad, congreso_presencial y congreso_virtual NO se incluyen: son inmutables aquí.
    $stmt = $conn->prepare("UPDATE eventos SET
                              nombre = ?,
                              tipo_evento_presencial = ?,
                              lugar_personalizado = ?,
                              url_conexion = ?,
                              fecha_limite = ?,
                              comercial_user_id = ?,
                              autoestudio = ?,
                              imagen = ?
                            WHERE id = ? LIMIT 1");
    $stmt->bind_param(
      'sssssiisi',
      $nombre,
      $tipo_evento_presencial,
      $lugar_personalizado,
      $url_conexion,
      $fecha_limite,
      $comercial_id,
      $autoestudio,
      $imagenFinal,
      $evento_id
    );

    if (!$stmt->execute()) {
      $error = 'Error al actualizar el evento: ' . $stmt->error;
    }
    $stmt->close();

    // Fechas: reemplazo completo SOLO si hubo cambios reales
    if (empty($error) && $cambia_fechas) {
      $del = $conn->prepare("DELETE FROM eventos_fechas WHERE evento_id = ?");
      $del->bind_param('i', $evento_id);
      $del->execute();
      $del->close();

      $insF = $conn->prepare("INSERT INTO eventos_fechas (evento_id, tipo, fecha, hora_inicio, hora_fin) VALUES (?, ?, ?, ?, ?)");
      $tipoF = $fechaF = $hiF = $hfF = null;
      $insF->bind_param('issss', $evento_id, $tipoF, $fechaF, $hiF, $hfF);
      foreach ($fechas_nuevas as $f) {
        $tipoF = $f['tipo'];
        $fechaF = $f['fecha'];
        $hiF = $f['hora_inicio'];
        $hfF = $f['hora_fin'];
        $insF->execute();
      }
      $insF->close();
    }

    // Módulos: UPDATE en sitio / INSERT nuevos / soft-delete (activo = 0). Nunca DELETE.
    if (empty($error) && $es_ce && $cambia_modulos) {

      if (!empty($mods_updates)) {
        $up = $conn->prepare("UPDATE eventos_modulos_virtuales
                              SET orden = ?, fecha = ?, hora_inicio = ?, hora_fin = ?, nombre = ?
                              WHERE id = ? AND evento_id = ?");
        $uOrd = $uFec = $uHi = $uHf = $uNom = $uId = null;
        $up->bind_param('issssii', $uOrd, $uFec, $uHi, $uHf, $uNom, $uId, $evento_id);
        foreach ($mods_updates as $id_m => $row) {
          $uOrd = $row['orden'];
          $uFec = $row['fecha'];
          $uHi = ($row['hora_inicio'] !== '') ? $row['hora_inicio'] : null;
          $uHf = ($row['hora_fin'] !== '') ? $row['hora_fin'] : null;
          $uNom = $row['nombre'];
          $uId = $id_m;
          $up->execute();
        }
        $up->close();
      }

      if (!empty($mods_inserts)) {
        $ins = $conn->prepare("INSERT INTO eventos_modulos_virtuales (evento_id, orden, fecha, hora_inicio, hora_fin, nombre, activo)
                               VALUES (?, ?, ?, ?, ?, ?, 1)");
        $iOrd = $iFec = $iHi = $iHf = $iNom = null;
        $ins->bind_param('iissss', $evento_id, $iOrd, $iFec, $iHi, $iHf, $iNom);
        foreach ($mods_inserts as $row) {
          $iOrd = $row['orden'];
          $iFec = $row['fecha'];
          $iHi = ($row['hora_inicio'] !== '') ? $row['hora_inicio'] : null;
          $iHf = ($row['hora_fin'] !== '') ? $row['hora_fin'] : null;
          $iNom = $row['nombre'];
          $ins->execute();
        }
        $ins->close();
      }

      if (!empty($mods_desactivar)) {
        $off = $conn->prepare("UPDATE eventos_modulos_virtuales SET activo = 0 WHERE id = ? AND evento_id = ?");
        $offId = null;
        $off->bind_param('ii', $offId, $evento_id);
        foreach ($mods_desactivar as $id_m) {
          $offId = $id_m;
          $off->execute();
        }
        $off->close();
      }
    }

    // Adjuntos nuevos (los existentes se conservan; eliminación = fase 2)
    if (empty($error)) {
      $baseDocs = dirname(__DIR__) . '/docs';
      if ($tiene_virtual) {
        guardarAdjuntos('adjunto_virtual', $baseDocs . '/evento_virtual/' . $evento_id);
      }
      if ($tiene_presencial) {
        guardarAdjuntos('adjunto_presencial', $baseDocs . '/evento_presencial/' . $evento_id);
      }
    }

    if (empty($error)) {
      log_activity($conn, 'editar_evento', 'evento', (string) $evento_id, array(
        'cambia_fechas' => $cambia_fechas ? 1 : 0,
        'cambia_modulos' => $cambia_modulos ? 1 : 0,
        'inscritos' => $total_inscritos
      ));

      header('Location: editar_evento.php?id=' . $evento_id . '&ok=1');
      exit;
    }
  }

  // Si hubo error: recargar datos frescos para re-render (no se aplicó nada en BD salvo validación previa)
  $evento = ev_cargar_evento($conn, $evento_id);
  $fechas_actuales = ev_cargar_fechas($conn, $evento_id);
  $modulos_actuales = $es_ce ? ev_cargar_modulos($conn, $evento_id) : array();
  $fechas_pres = array();
  $fechas_virt = array();
  foreach ($fechas_actuales as $f) {
    if ($f['tipo'] === 'presencial') {
      $fechas_pres[] = $f;
    } elseif ($f['tipo'] === 'virtual') {
      $fechas_virt[] = $f;
    } else {
      if ($tiene_presencial && !$tiene_virtual) {
        $fechas_pres[] = $f;
      } else {
        $fechas_virt[] = $f;
      }
    }
  }
}

// -----------------------------------------------------------
// Datos para el formulario
// -----------------------------------------------------------
$comerciales = array();
$resU = $conn->query("SELECT id, nombre, email FROM usuarios WHERE activo = 1 ORDER BY nombre ASC");
if ($resU) {
  while ($r = $resU->fetch_assoc()) {
    $comerciales[] = $r;
  }
}
// Si el comercial actual no está en la lista (inactivo), agregarlo para no perder la asignación
$comercialEnLista = false;
foreach ($comerciales as $c) {
  if ((int) $c['id'] === $evento['comercial_user_id']) {
    $comercialEnLista = true;
    break;
  }
}
if (!$comercialEnLista && $evento['comercial_user_id'] > 0) {
  $stmtC = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE id = ? LIMIT 1");
  $stmtC->bind_param('i', $evento['comercial_user_id']);
  $stmtC->execute();
  $stmtC->bind_result($cu_id, $cu_nom, $cu_mail);
  if ($stmtC->fetch()) {
    $comerciales[] = array('id' => $cu_id, 'nombre' => $cu_nom . ' (inactivo)', 'email' => $cu_mail);
  }
  $stmtC->close();
}

$urlPublica = base_url('registro.php?e=' . urlencode($evento['slug']));
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Editar evento</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div class="w-full max-w-4xl bg-white rounded-2xl shadow-2xl p-8 mt-10 mb-10 space-y-6">
    <?php
    $back_to = 'eventos.php';
    $show_back = true;
    include __DIR__ . '/_topbar.php';
    ?>
    <h1 class="text-2xl font-bold text-[#942934] text-center mb-4">✏️ Editar evento</h1>

    <?php if (isset($_GET['ok']) && $_GET['ok'] == 1): ?>
      <div class="bg-green-100 text-green-800 font-medium px-6 py-4 rounded-xl">
        ✅ Cambios guardados correctamente. La URL pública del evento no cambió.
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="bg-red-100 text-red-800 font-medium px-6 py-4 rounded-xl">
        <?php echo hx($error); ?>
      </div>
    <?php endif; ?>

    <!-- Información fija del evento -->
    <div class="p-4 border border-gray-200 rounded-xl bg-gray-50 space-y-2">
      <div>
        <span class="font-semibold text-gray-700">URL pública (no cambia):</span>
        <input type="text" readonly value="<?php echo hx($urlPublica); ?>"
          class="w-full mt-1 p-2 bg-white border border-gray-300 rounded-xl text-sm text-gray-600">
      </div>
      <div class="text-sm text-gray-700">
        <span class="font-semibold">Modalidad (solo lectura):</span>
        <?php echo hx($evento['modalidad']); ?>
        <?php if ($es_ce): ?>
          — Congreso presencial: <?php echo $evento['congreso_presencial'] ? 'Sí' : 'No'; ?>,
          Congreso virtual: <?php echo $evento['congreso_virtual'] ? 'Sí' : 'No'; ?>
        <?php endif; ?>
      </div>
      <div class="text-xs text-gray-500">
        ℹ️ La URL pública no cambiará aunque modifiques el nombre del evento.
      </div>
    </div>

    <?php if ($total_inscritos > 0): ?>
      <div class="bg-amber-100 border border-amber-300 text-amber-900 px-6 py-4 rounded-xl text-sm">
        ⚠️ <strong>Este evento ya tiene <?php echo $total_inscritos; ?> inscrito(s).</strong>
        Cambiar fechas o módulos puede afectar la información ya enviada o seleccionada por los inscritos.
        Si modificas esas secciones, deberás confirmar antes de guardar.
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-6">
      <input type="hidden" name="id" value="<?php echo (int) $evento['id']; ?>">

      <div>
        <label class="font-semibold text-gray-700">Nombre del evento:</label>
        <input type="text" name="nombre" required value="<?php echo hx($evento['nombre']); ?>"
          class="w-full p-3 border border-gray-300 rounded-xl mt-2">
      </div>

      <div class="p-4 border border-gray-200 rounded-xl">
        <label class="font-semibold text-gray-700 block mb-2">Imagen del evento:</label>
        <?php if (!empty($evento['imagen'])): ?>
          <img src="<?php echo hx('../uploads/eventos/' . $evento['imagen']); ?>" alt="Imagen actual"
            class="w-40 h-24 object-cover rounded-xl border mb-3">
        <?php endif; ?>
        <input type="file" name="imagen_evento" accept="image/*" class="w-full border border-gray-300 rounded-xl p-2">
        <small class="text-gray-500">Opcional. Si no subes una nueva, se conserva la actual.</small>
      </div>

      <div>
        <label class="font-semibold text-gray-700">Comercial asignado:</label>
        <select name="comercial_user_id" required class="w-full p-3 border border-gray-300 rounded-xl mt-2">
          <option value="">Selecciona...</option>
          <?php foreach ($comerciales as $c): ?>
            <option value="<?php echo (int) $c['id']; ?>" <?php echo ((int) $c['id'] === $evento['comercial_user_id']) ? 'selected' : ''; ?>>
              <?php echo hx($c['nombre']); ?> (<?php echo hx($c['email']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($aplica_tipo_pres): ?>
        <div class="p-4 border border-gray-200 rounded-xl">
          <label class="font-semibold text-gray-700 block mb-2">Tipo de evento presencial:</label>
          <select name="tipo_evento_presencial" class="w-full p-3 border border-gray-300 rounded-xl">
            <option value="">Selecciona...</option>
            <option value="Congreso" <?php echo ($evento['tipo_evento_presencial'] === 'Congreso') ? 'selected' : ''; ?>>Congreso</option>
            <option value="Seminario" <?php echo ($evento['tipo_evento_presencial'] === 'Seminario') ? 'selected' : ''; ?>>Seminario</option>
          </select>
          <small class="text-gray-500">Si lo dejas sin seleccionar, se conserva el valor actual.</small>
        </div>
      <?php endif; ?>

      <?php if ($tiene_presencial): ?>
        <div class="p-4 border border-gray-200 rounded-xl">
          <label class="font-semibold text-gray-700 block mb-2">Lugar del evento presencial:</label>
          <textarea name="lugar_personalizado" rows="3" class="w-full p-3 border border-gray-300 rounded-xl"
            placeholder="<?php echo hx($DEFAULT_LUGAR_PRESENCIAL); ?>"><?php echo hx((string) $evento['lugar_personalizado']); ?></textarea>
          <small class="text-gray-500">Déjalo vacío para usar el lugar por defecto (Cafam Floresta).</small>
        </div>
      <?php endif; ?>

      <?php if ($aplica_url): ?>
        <div class="p-4 border border-gray-200 rounded-xl">
          <label class="font-semibold text-gray-700 block mb-2">URL de conexión al evento:</label>
          <input type="url" name="url_conexion" value="<?php echo hx((string) $evento['url_conexion']); ?>"
            placeholder="https://..." class="w-full p-3 border border-gray-300 rounded-xl">
          <small class="text-gray-500">Opcional. Vacía = sin enlace en los correos.</small>
        </div>
      <?php endif; ?>

      <div class="p-4 border border-gray-200 rounded-xl">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="autoestudio" value="1" class="accent-[#942934]"
            <?php echo $evento['autoestudio'] ? 'checked' : ''; ?>>
          Incluye Autoestudio y trabajo integrador
        </label>
      </div>

      <div>
        <label class="font-semibold text-gray-700">Fecha límite para confirmar asistencia:</label>
        <input type="date" name="fecha_limite" required value="<?php echo hx($evento['fecha_limite']); ?>"
          class="w-full p-3 border border-gray-300 rounded-xl mt-2">
      </div>

      <!-- ====== ZONA SENSIBLE: FECHAS ====== -->
      <div id="zona_fechas" class="p-4 border border-gray-200 rounded-xl space-y-4">
        <div class="font-bold text-gray-800">📅 Fechas del evento</div>
        <div class="text-xs text-gray-500">
          Si no modificas nada en esta sección, las fechas actuales se conservan tal cual.
        </div>

        <?php if ($tiene_presencial): ?>
          <div>
            <div class="font-semibold text-gray-700 mb-2">📍 Fechas presenciales</div>
            <div id="cont_fechas_presencial" class="space-y-3">
              <?php foreach ($fechas_pres as $f): ?>
                <div class="ev-fecha-row grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                  <div>
                    <label class="block text-sm font-medium mb-1">Fecha</label>
                    <input type="date" name="fechas_presencial[]" value="<?php echo hx($f['fecha']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Hora inicio</label>
                    <input type="time" name="hora_inicio_presencial[]" value="<?php echo hx($f['hora_inicio']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Hora fin</label>
                    <input type="time" name="hora_fin_presencial[]" value="<?php echo hx($f['hora_fin']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <button type="button" class="ev-del-row text-sm font-bold text-red-600 p-3">Quitar</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" data-target="cont_fechas_presencial" data-prefix="presencial"
              class="ev-add-fecha mt-2 bg-[#0ea5e9] hover:bg-[#0284c7] text-white font-bold px-4 py-2 rounded-xl text-sm">
              + Agregar día presencial
            </button>
          </div>
        <?php endif; ?>

        <?php if ($tiene_virtual): ?>
          <div>
            <div class="font-semibold text-gray-700 mb-2">💻 Fechas virtuales</div>
            <div id="cont_fechas_virtual" class="space-y-3">
              <?php foreach ($fechas_virt as $f): ?>
                <div class="ev-fecha-row grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                  <div>
                    <label class="block text-sm font-medium mb-1">Fecha</label>
                    <input type="date" name="fechas_virtual[]" value="<?php echo hx($f['fecha']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Hora inicio</label>
                    <input type="time" name="hora_inicio_virtual[]" value="<?php echo hx($f['hora_inicio']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Hora fin</label>
                    <input type="time" name="hora_fin_virtual[]" value="<?php echo hx($f['hora_fin']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <button type="button" class="ev-del-row text-sm font-bold text-red-600 p-3">Quitar</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" data-target="cont_fechas_virtual" data-prefix="virtual"
              class="ev-add-fecha mt-2 bg-[#0ea5e9] hover:bg-[#0284c7] text-white font-bold px-4 py-2 rounded-xl text-sm">
              + Agregar día virtual
            </button>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($es_ce): ?>
        <!-- ====== ZONA SENSIBLE: MÓDULOS VIRTUALES ====== -->
        <div id="zona_modulos" class="p-4 border border-gray-200 rounded-xl space-y-3">
          <div class="font-bold text-gray-800">💻 Módulos virtuales (máx 10)</div>
          <div class="text-xs text-gray-500">
            Los módulos quitados se desactivan (no se borran): las inscripciones existentes conservan su información.
          </div>

          <div id="cont_modulos" class="space-y-3">
            <?php foreach ($modulos_actuales as $m): ?>
              <div class="ev-mod-row p-3 border border-gray-200 rounded-xl">
                <input type="hidden" name="modulo_id[]" value="<?php echo (int) $m['id']; ?>">
                <input type="hidden" name="modulo_orden[]" value="<?php echo (int) $m['orden']; ?>">
                <div class="flex items-center justify-between gap-2 mb-2">
                  <div class="font-semibold text-gray-800">Módulo (id <?php echo (int) $m['id']; ?>)</div>
                  <button type="button" class="ev-del-mod text-sm font-bold text-red-600">Quitar</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                  <div>
                    <label class="block text-sm font-medium mb-1">Fecha</label>
                    <input type="date" name="modulo_fecha[]" value="<?php echo hx($m['fecha']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Hora inicio</label>
                    <input type="time" name="modulo_hora_inicio[]" value="<?php echo hx($m['hora_inicio']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Hora fin</label>
                    <input type="time" name="modulo_hora_fin[]" value="<?php echo hx($m['hora_fin']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1">Nombre / Tema</label>
                    <input type="text" name="modulo_nombre[]" value="<?php echo hx($m['nombre']); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <button type="button" id="btnAddModuloEdit"
            class="bg-[#0ea5e9] hover:bg-[#0284c7] text-white font-bold px-4 py-2 rounded-xl text-sm">
            + Agregar módulo
          </button>
        </div>
      <?php endif; ?>

      <div class="p-4 border border-gray-200 rounded-xl">
        <div class="font-semibold text-gray-700 mb-2">Adjuntos nuevos (los existentes se conservan)</div>
        <?php if ($tiene_presencial): ?>
          <div class="mb-3">
            <label class="block text-sm font-medium mb-1">Adjuntos presenciales nuevos</label>
            <input type="file" name="adjunto_presencial[]" multiple class="w-full p-3 border border-gray-300 rounded-xl">
          </div>
        <?php endif; ?>
        <?php if ($tiene_virtual): ?>
          <div>
            <label class="block text-sm font-medium mb-1">Adjuntos virtuales nuevos</label>
            <input type="file" name="adjunto_virtual[]" multiple class="w-full p-3 border border-gray-300 rounded-xl">
          </div>
        <?php endif; ?>
        <small class="text-gray-500">Formatos: pdf, doc(x), xls(x), ppt(x), jpg, png, webp. Máx 25MB c/u. La eliminación de adjuntos existentes no está disponible en esta fase.</small>
      </div>

      <?php if ($total_inscritos > 0): ?>
        <div id="bloque_confirmacion" class="p-4 border border-amber-300 bg-amber-50 rounded-xl">
          <label class="inline-flex items-start gap-2">
            <input type="checkbox" id="chk_sensible" name="confirmar_cambios_sensibles" value="1" class="accent-[#942934] mt-1">
            <span class="text-sm text-amber-900">
              Confirmo que entiendo que este evento tiene <strong><?php echo $total_inscritos; ?> inscrito(s)</strong>
              y que los cambios en fechas o módulos pueden afectar la información que ya recibieron o seleccionaron.
            </span>
          </label>
          <div class="text-xs text-amber-700 mt-1">Solo es obligatoria si modificas fechas o módulos.</div>
        </div>
      <?php endif; ?>

      <div class="text-center">
        <button type="submit"
          class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-8 rounded-xl transition-all">
          Guardar cambios
        </button>
      </div>
    </form>
  </div>

  <script>
    (function () {
      function buildFechaRow(prefix) {
        var div = document.createElement('div');
        div.className = 'ev-fecha-row grid grid-cols-1 md:grid-cols-4 gap-3 items-end';
        div.innerHTML = ''
          + '<div><label class="block text-sm font-medium mb-1">Fecha</label>'
          + '<input type="date" name="fechas_' + prefix + '[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '<div><label class="block text-sm font-medium mb-1">Hora inicio</label>'
          + '<input type="time" name="hora_inicio_' + prefix + '[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '<div><label class="block text-sm font-medium mb-1">Hora fin</label>'
          + '<input type="time" name="hora_fin_' + prefix + '[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '<div><button type="button" class="ev-del-row text-sm font-bold text-red-600 p-3">Quitar</button></div>';
        return div;
      }

      function buildModRow() {
        var div = document.createElement('div');
        div.className = 'ev-mod-row p-3 border border-gray-200 rounded-xl';
        div.innerHTML = ''
          + '<input type="hidden" name="modulo_id[]" value="0">'
          + '<input type="hidden" name="modulo_orden[]" value="0">'
          + '<div class="flex items-center justify-between gap-2 mb-2">'
          + '  <div class="font-semibold text-gray-800">Módulo nuevo</div>'
          + '  <button type="button" class="ev-del-mod text-sm font-bold text-red-600">Quitar</button>'
          + '</div>'
          + '<div class="grid grid-cols-1 md:grid-cols-4 gap-3">'
          + '  <div><label class="block text-sm font-medium mb-1">Fecha</label>'
          + '  <input type="date" name="modulo_fecha[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '  <div><label class="block text-sm font-medium mb-1">Hora inicio</label>'
          + '  <input type="time" name="modulo_hora_inicio[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '  <div><label class="block text-sm font-medium mb-1">Hora fin</label>'
          + '  <input type="time" name="modulo_hora_fin[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '  <div><label class="block text-sm font-medium mb-1">Nombre / Tema</label>'
          + '  <input type="text" name="modulo_nombre[]" class="w-full p-3 border border-gray-300 rounded-xl"></div>'
          + '</div>';
        return div;
      }

      function marcarSensible() {
        var chk = document.getElementById('chk_sensible');
        var bloque = document.getElementById('bloque_confirmacion');
        if (chk && !chk.checked) {
          chk.required = true;
          if (bloque) bloque.classList.add('ring-2', 'ring-amber-400');
        }
      }

      // Botones "+ Agregar día"
      var addBtns = document.getElementsByClassName('ev-add-fecha');
      for (var i = 0; i < addBtns.length; i++) {
        addBtns[i].addEventListener('click', function () {
          var cont = document.getElementById(this.getAttribute('data-target'));
          if (cont) {
            cont.appendChild(buildFechaRow(this.getAttribute('data-prefix')));
            marcarSensible();
          }
        });
      }

      // Botón "+ Agregar módulo"
      var btnMod = document.getElementById('btnAddModuloEdit');
      if (btnMod) {
        btnMod.addEventListener('click', function () {
          var cont = document.getElementById('cont_modulos');
          if (!cont) return;
          var rows = cont.getElementsByClassName('ev-mod-row');
          if (rows.length >= 10) {
            alert('Máximo 10 módulos virtuales.');
            return;
          }
          cont.appendChild(buildModRow());
          marcarSensible();
        });
      }

      // Quitar filas (delegación)
      document.addEventListener('click', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('ev-del-row')) {
          var row = t.closest('.ev-fecha-row');
          if (row) { row.parentNode.removeChild(row); marcarSensible(); }
        }
        if (t && t.classList && t.classList.contains('ev-del-mod')) {
          var mrow = t.closest('.ev-mod-row');
          if (mrow) { mrow.parentNode.removeChild(mrow); marcarSensible(); }
        }
      });

      // Cualquier edición dentro de las zonas sensibles exige la confirmación (si hay inscritos)
      var zonas = ['zona_fechas', 'zona_modulos'];
      for (var z = 0; z < zonas.length; z++) {
        var zona = document.getElementById(zonas[z]);
        if (zona) {
          zona.addEventListener('input', marcarSensible);
          zona.addEventListener('change', marcarSensible);
        }
      }
    })();
  </script>
</body>

</html>
