<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';
require_once dirname(__DIR__) . '/correo/enviar_correo.php';

$slug = isset($_GET['e']) ? $_GET['e'] : '';
if ($slug === '') {
  http_response_code(400);
  echo 'Falta el parámetro "e" (slug del evento).';
  exit;
}

function norm_modalidad($m)
{
  $m = strtolower(trim((string) $m));
  $m = str_replace(array(' ', '-'), '_', $m);
  return $m;
}

$evento = null;

$stmt = $conn->prepare('SELECT id, nombre, imagen, modalidad, fecha_limite, whatsapp_numero, firma_imagen, encargado_nombre, lugar_personalizado, autoestudio FROM eventos WHERE slug = ? LIMIT 1');
$stmt->bind_param('s', $slug);
$stmt->execute();
$stmt->bind_result($ev_id, $ev_nombre, $ev_imagen, $ev_modalidad, $ev_fecha_limite, $ev_wa, $ev_firma, $ev_encargado, $ev_lugar, $ev_autoestudio);
if ($stmt->fetch()) {
  $evento = array(
    'id' => $ev_id,
    'nombre' => $ev_nombre,
    'imagen' => $ev_imagen,
    'modalidad' => $ev_modalidad,
    'fecha_limite' => $ev_fecha_limite,
    'whatsapp_numero' => $ev_wa,
    'firma_imagen' => $ev_firma,
    'encargado_nombre' => $ev_encargado,
    'lugar_personalizado' => $ev_lugar,
    'autoestudio' => (int) $ev_autoestudio
  );
}
$stmt->close();

if (!$evento) {
  http_response_code(404);
  echo 'Evento no encontrado para el slug: ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
  exit;
}

$mod = norm_modalidad($evento['modalidad'] ?? '');

// Fechas/horario del evento (con tipo)
$fechas_presenciales = array();
$fechas_virtuales = array();
$fechas_generales = array();

$stmtf = $conn->prepare("
    SELECT tipo, fecha, hora_inicio, hora_fin
    FROM eventos_fechas
    WHERE evento_id = ?
    ORDER BY fecha ASC
  ");
$stmtf->bind_param("i", $evento['id']);
$stmtf->execute();
$stmtf->bind_result($f_tipo, $f_fecha, $f_hi, $f_hf);

while ($stmtf->fetch()) {
  $row = array(
    'tipo' => $f_tipo,
    'fecha' => $f_fecha,
    'hora_inicio' => $f_hi,
    'hora_fin' => $f_hf
  );

  $tipo = strtolower(trim($f_tipo));
  if ($tipo === 'presencial') {
    $fechas_presenciales[] = $row;
  } elseif ($tipo === 'virtual') {
    $fechas_virtuales[] = $row;
  } else {
    $fechas_generales[] = $row;
  }
}
$stmtf->close();

// Curso Especial: módulos virtuales con nombre
$modulos_virtuales = array();
if ($mod === 'curso_especial') {
  $stmtm = $conn->prepare("
      SELECT id, orden, fecha, hora_inicio, hora_fin, nombre
      FROM eventos_modulos_virtuales
      WHERE evento_id = ? AND activo = 1
      ORDER BY orden ASC, fecha ASC
    ");
  $stmtm->bind_param("i", $evento['id']);
  $stmtm->execute();
  $stmtm->bind_result($mid, $mord, $mfecha, $mhi, $mhf, $mnom);
  while ($stmtm->fetch()) {
    $modulos_virtuales[] = array(
      'id' => (int) $mid,
      'orden' => (int) $mord,
      'fecha' => $mfecha,
      'hora_inicio' => $mhi,
      'hora_fin' => $mhf,
      'nombre' => $mnom
    );
  }
  $stmtm->close();
}

// Compatibilidad: $fechas para módulos virtuales clásicos (solo modalidad virtual)
if ($mod === 'hibrida') {
  $fechas = array_merge($fechas_presenciales, $fechas_virtuales);
  usort($fechas, function ($a, $b) {
    return strcmp($a['fecha'], $b['fecha']);
  });
} elseif ($mod === 'virtual') {
  $fechas = $fechas_virtuales;
} elseif ($mod === 'presencial') {
  $fechas = $fechas_presenciales;
} elseif ($mod === 'curso_especial') {
  $fechas = $fechas_presenciales;
} else {
  $fechas = $fechas_generales;
}

function pintarFechasHtml($fechasArr)
{
  if (empty($fechasArr))
    return '<div class="text-gray-500 text-sm">—</div>';

  $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
  $out = "<div class='space-y-1'>";

  for ($i = 0; $i < count($fechasArr); $i++) {
    $f = $fechasArr[$i];
    $d = (int) date('j', strtotime($f['fecha']));
    $m = $meses[(int) date('n', strtotime($f['fecha'])) - 1];
    $y = date('Y', strtotime($f['fecha']));

    $hi = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_inicio']));
    $hf = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_fin']));
    $hi = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hi));
    $hf = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hf));

    $out .= "<div class='text-sm text-gray-700'>" . $d . " de " . $m . " de " . $y . " — <span class='font-semibold'>" . $hi . "</span> a <span class='font-semibold'>" . $hf . "</span></div>";
  }

  $out .= "</div>";
  return $out;
}

function resumirFechas($fechasArr)
{
  if (empty($fechasArr))
    return '';
  $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
  $dias = array();
  for ($i = 0; $i < count($fechasArr); $i++) {
    $dias[] = (int) date('j', strtotime($fechasArr[$i]['fecha']));
  }
  $mes = $meses[(int) date('n', strtotime($fechasArr[0]['fecha'])) - 1];
  $anio = date('Y', strtotime($fechasArr[0]['fecha']));
  if (count($dias) == 1)
    return $dias[0] . " de $mes de $anio";
  $ultimo = array_pop($dias);
  return implode(', ', $dias) . " y $ultimo de $mes de $anio";
}

function detalleHorarioHtml($fechasArr)
{
  if (empty($fechasArr))
    return '';
  $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
  $html = "<ul style='margin:0;padding-left:18px'>";
  for ($i = 0; $i < count($fechasArr); $i++) {
    $f = $fechasArr[$i];
    $d = (int) date('j', strtotime($f['fecha']));
    $m = $meses[(int) date('n', strtotime($f['fecha'])) - 1];
    $y = date('Y', strtotime($f['fecha']));

    $hi = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_inicio']));
    $hf = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_fin']));
    $hi = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hi));
    $hf = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hf));

    $html .= "<li>Día " . ($i + 1) . ": $d de $m de $y — <strong>$hi</strong> a <strong>$hf</strong></li>";
  }
  $html .= "</ul>";
  return $html;
}

$resumen_fechas = !empty($fechas) ? resumirFechas($fechas) : 'Por definir';
$detalle_horario = !empty($fechas) ? detalleHorarioHtml($fechas) : '<em>Pronto te enviaremos el horario detallado.</em>';

$mensaje_exito = false;

// Lugar final para correo (solo si aplica presencial)
$lugar_final = '';
if (!empty($evento['lugar_personalizado'])) {
  $lugar_final = nl2br($evento['lugar_personalizado']);
} else {
  $lugar_final = "Centro de Convenciones Cafam Floresta<br>Av. Cra. 68 No. 90-88, Bogotá - Salón Sauces";
}

// Helpers de normalización
if (!function_exists('strtoupper_utf8')) {
  function strtoupper_utf8($texto)
  {
    $texto = trim($texto);
    $upper = strtoupper($texto);
    $map = array(
      'á' => 'Á',
      'é' => 'É',
      'í' => 'Í',
      'ó' => 'Ó',
      'ú' => 'Ú',
      'à' => 'À',
      'è' => 'È',
      'ì' => 'Ì',
      'ò' => 'Ò',
      'ù' => 'Ù',
      'ä' => 'Ä',
      'ë' => 'Ë',
      'ï' => 'Ï',
      'ö' => 'Ö',
      'ü' => 'Ü',
      'ñ' => 'Ñ',
      'ç' => 'Ç'
    );
    $upper = strtr($upper, $map);
    $upper = preg_replace('/\s+/', ' ', $upper);
    return $upper;
  }
}
if (!function_exists('strtolower_utf8')) {
  function strtolower_utf8($t)
  {
    $map = array(
      'Á' => 'á',
      'É' => 'é',
      'Í' => 'í',
      'Ó' => 'ó',
      'Ú' => 'ú',
      'À' => 'à',
      'È' => 'è',
      'Ì' => 'ì',
      'Ò' => 'ò',
      'Ù' => 'ù',
      'Ä' => 'ä',
      'Ë' => 'ë',
      'Ï' => 'ï',
      'Ö' => 'ö',
      'Ü' => 'ü',
      'Ñ' => 'ñ',
      'Ç' => 'ç'
    );
    return strtolower(strtr($t, $map));
  }
}
if (!function_exists('titlecase_es')) {
  function titlecase_es($texto)
  {
    $texto = trim($texto);
    $texto = strtolower_utf8($texto);
    $texto = preg_replace('/\s+/', ' ', $texto);

    $seps = array(' ', '-', '\'');
    $chars_lower = array('á', 'é', 'í', 'ó', 'ú', 'à', 'è', 'ì', 'ò', 'ù', 'ä', 'ë', 'ï', 'ö', 'ü', 'ñ', 'ç');
    $chars_upper = array('Á', 'É', 'Í', 'Ó', 'Ú', 'À', 'È', 'Ì', 'Ò', 'Ù', 'Ä', 'Ë', 'Ï', 'Ö', 'Ü', 'Ñ', 'Ç');

    $out = '';
    $len = strlen($texto);
    $capitalize_next = true;

    for ($i = 0; $i < $len; $i++) {
      $ch = $texto[$i];
      if (in_array($ch, $seps, true)) {
        $out .= $ch;
        $capitalize_next = true;
        continue;
      }
      if ($capitalize_next) {
        $pos = array_search($ch, $chars_lower, true);
        $out .= ($pos !== false) ? $chars_upper[$pos] : strtoupper($ch);
        $capitalize_next = false;
      } else {
        $out .= $ch;
      }
    }
    return $out;
  }
}

function pintarModulosVirtualesHtml($mods)
{
  if (empty($mods))
    return '<div class="text-gray-500 text-sm">—</div>';
  $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
  $out = "<div class='space-y-2'>";
  for ($i = 0; $i < count($mods); $i++) {
    $m = $mods[$i];
    $d = (int) date('j', strtotime($m['fecha']));
    $mm = $meses[(int) date('n', strtotime($m['fecha'])) - 1];
    $y = date('Y', strtotime($m['fecha']));

    $hi = $m['hora_inicio'] ? date('g:i a', strtotime($m['fecha'] . ' ' . $m['hora_inicio'])) : '';
    $hf = $m['hora_fin'] ? date('g:i a', strtotime($m['fecha'] . ' ' . $m['hora_fin'])) : '';
    $hi = $hi ? str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hi)) : '';
    $hf = $hf ? str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hf)) : '';

    $hor = ($hi && $hf) ? (" — <span class='font-semibold'>$hi</span> a <span class='font-semibold'>$hf</span>") : '';
    $out .= "<div class='p-3 border border-gray-200 rounded-xl bg-white'>"
      . "<div class='font-semibold text-gray-800'>" . htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') . "</div>"
      . "<div class='text-sm text-gray-700 mt-1'>Fecha: $d de $mm de $y$hor</div>"
      . "</div>";
  }
  $out .= "</div>";
  return $out;
}

// POST: guardar inscripción + correo
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $evento_id = isset($_POST["evento_id"]) ? (int) $_POST["evento_id"] : 0;
  $tipo_inscripcion = isset($_POST["tipo_inscripcion"]) ? $_POST["tipo_inscripcion"] : '';

  $nombres = isset($_POST['nombres']) ? $_POST['nombres'] : '';
  $apellidos = isset($_POST['apellidos']) ? $_POST['apellidos'] : '';
  $nombres = titlecase_es($nombres);
  $apellidos = titlecase_es($apellidos);

  $nombre_completo = trim($nombres . ' ' . $apellidos);
  $nombre = $nombre_completo;

  $cedula = isset($_POST["cedula"]) ? $_POST["cedula"] : '';
  $cargo = isset($_POST["cargo"]) ? $_POST["cargo"] : '';
  $entidad = isset($_POST['entidad']) ? $_POST['entidad'] : '';
  $entidad = strtoupper_utf8($entidad);
  $celular = isset($_POST["celular"]) ? $_POST["celular"] : '';
  $ciudad = isset($_POST["ciudad"]) ? $_POST["ciudad"] : '';
  $email_personal = isset($_POST["email_personal"]) ? $_POST["email_personal"] : '';
  $email_corporativo = isset($_POST["email_corporativo"]) ? $_POST["email_corporativo"] : '';

  $medio_opcion = isset($_POST["medio_opcion"]) ? trim($_POST["medio_opcion"]) : '';
  $medio_otro = isset($_POST["medio_otro"]) ? trim($_POST["medio_otro"]) : '';

  $mapMedio = array(
    'CORREO' => 'Correo electrónico',
    'WHATSAPP' => 'WhatsApp',
    'LLAMADA' => 'Llamada de nuestros ejecutivos de cuenta',
    'REDES' => 'Redes sociales',
    'OTRO' => 'Otro'
  );
  if (isset($mapMedio[$medio_opcion])) {
    if ($medio_opcion === 'OTRO') {
      $medio = 'Otro' . (!empty($medio_otro) ? (': ' . $medio_otro) : '');
    } else {
      $medio = $mapMedio[$medio_opcion];
    }
  } else {
    $medio = '';
  }

  $mod_form = norm_modalidad($evento['modalidad'] ?? '');

  $asistencia_tipo = 'COMPLETO';
  $modulos_csv = '';

  // Curso Especial: 4 casos
  if ($mod_form === 'curso_especial') {
    $sel = isset($_POST['curso_especial_opcion']) ? strtoupper(trim($_POST['curso_especial_opcion'])) : 'CONGRESO';
    $permitidas = array('CONGRESO', 'CURSO_COMPLETO', 'MODULOS_VIRTUALES', 'CONGRESO_MAS_MODULOS');
    if (!in_array($sel, $permitidas, true))
      $sel = 'CONGRESO';

    $asistencia_tipo = $sel;

    // Selección de módulos (por ID)
    $mods_sel = isset($_POST['modulos_virtuales']) && is_array($_POST['modulos_virtuales']) ? $_POST['modulos_virtuales'] : array();
    $ids_validos = array();
    $mapMods = array();
    for ($i = 0; $i < count($modulos_virtuales); $i++) {
      $mapMods[(int) $modulos_virtuales[$i]['id']] = true;
    }
    for ($i = 0; $i < count($mods_sel); $i++) {
      $id = (int) $mods_sel[$i];
      if ($id > 0 && isset($mapMods[$id]))
        $ids_validos[] = $id;
    }
    $ids_validos = array_values(array_unique($ids_validos));

    if ($sel === 'CURSO_COMPLETO') {
      $modulos_csv = 'ALL';
    } elseif ($sel === 'CONGRESO') {
      $modulos_csv = '';
    } else {
      if (empty($ids_validos)) {
        // Si no seleccionó módulos cuando era obligatorio, lo degradamos a CONGRESO (seguro)
        if ($sel === 'MODULOS_VIRTUALES') {
          $asistencia_tipo = 'CONGRESO';
          $modulos_csv = '';
        } else {
          $asistencia_tipo = 'CONGRESO';
          $modulos_csv = '';
        }
      } else {
        $modulos_csv = implode(',', $ids_validos);
      }
    }
  }
  // Virtual clásico: COMEPLETO vs MODULOS por fechas (lo que ya tenías)
  else if ($mod_form === 'virtual') {
    $asistencia_tipo = isset($_POST['asistencia_tipo']) ? strtoupper(trim($_POST['asistencia_tipo'])) : 'COMPLETO';
    if ($asistencia_tipo !== 'COMPLETO') {
      $asistencia_tipo = 'MODULOS';
    }

    $modulos_arr = isset($_POST['modulos']) && is_array($_POST['modulos']) ? $_POST['modulos'] : array();

    $validas = array();
    $fechas_map = array();
    for ($i = 0; $i < count($fechas); $i++) {
      $fechas_map[$fechas[$i]['fecha']] = true;
    }

    for ($i = 0; $i < count($modulos_arr); $i++) {
      $val = trim($modulos_arr[$i]);
      if (isset($fechas_map[$val]))
        $validas[] = $val;
    }

    if ($asistencia_tipo === 'MODULOS' && empty($validas)) {
      $asistencia_tipo = 'COMPLETO';
      $modulos_csv = '';
    } else {
      $modulos_csv = implode(',', $validas);
    }
  } else {
    $asistencia_tipo = 'COMPLETO';
    $modulos_csv = '';
  }

  $whatsapp_consent = isset($_POST['whatsapp_consent']) ? (($_POST['whatsapp_consent'] === 'SI') ? 'SI' : 'NO') : null;
  $fecha_registro = date('Y-m-d H:i:s');

  $soporte_rel = '';
  if (isset($_FILES['soporte_pago']) && is_array($_FILES['soporte_pago']) && $_FILES['soporte_pago']['error'] === 0) {
    $maxBytes = 10 * 1024 * 1024;
    $tmpName = $_FILES['soporte_pago']['tmp_name'];
    $origName = $_FILES['soporte_pago']['name'];
    $size = (int) $_FILES['soporte_pago']['size'];

    if ($size <= $maxBytes && is_uploaded_file($tmpName)) {
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      $permitidas = array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp');
      if (in_array($ext, $permitidas)) {
        $destDir = dirname(__DIR__) . '/uploads/soportes/';
        if (!is_dir($destDir)) {
          @mkdir($destDir, 0775, true);
        }
        $nombreSeguro = 'soporte_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (@move_uploaded_file($tmpName, $destDir . $nombreSeguro)) {
          $soporte_rel = 'uploads/soportes/' . $nombreSeguro;
        } else {
          error_log('[SOPORTE] No se pudo mover el archivo subido.');
        }
      } else {
        error_log('[SOPORTE] Extensión no permitida: ' . $ext);
      }
    } else {
      error_log('[SOPORTE] Archivo supera el límite o no es válido.');
    }
  }

  $stmt = $conn->prepare("INSERT INTO inscritos (
      evento_id, tipo_inscripcion, nombre, nombres, apellidos, cedula, cargo, entidad, celular, ciudad,
      email_personal, email_corporativo, medio, soporte_pago,
      asistencia_tipo, modulos_seleccionados, whatsapp_consent, fecha_registro
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

  $stmt->bind_param(
    "isssssssssssssssss",
    $evento_id,
    $tipo_inscripcion,
    $nombre,
    $nombres,
    $apellidos,
    $cedula,
    $cargo,
    $entidad,
    $celular,
    $ciudad,
    $email_personal,
    $email_corporativo,
    $medio,
    $soporte_rel,
    $asistencia_tipo,
    $modulos_csv,
    $whatsapp_consent,
    $fecha_registro
  );

  if ($stmt->execute()) {

    // Para correos: determinar si incluye presencial y/o virtual
    $incluye_presencial = false;
    $incluye_virtual = false;

    if ($mod_form === 'curso_especial') {
      if ($asistencia_tipo === 'CONGRESO' || $asistencia_tipo === 'CURSO_COMPLETO' || $asistencia_tipo === 'CONGRESO_MAS_MODULOS')
        $incluye_presencial = true;
      if ($asistencia_tipo === 'CURSO_COMPLETO')
        $incluye_virtual = true;
      if ($asistencia_tipo === 'MODULOS_VIRTUALES' || $asistencia_tipo === 'CONGRESO_MAS_MODULOS')
        $incluye_virtual = true;
    } else {
      $incluye_presencial = ($mod_form === 'presencial' || $mod_form === 'hibrida');
      $incluye_virtual = ($mod_form === 'virtual' || $mod_form === 'hibrida');
    }

    // PDF guía hotelera (solo cuando realmente aplica presencial por defecto)
    $path_pdf = null;
    if ($incluye_presencial) {
      $usa_lugar_por_defecto = empty($evento['lugar_personalizado']);
      if ($usa_lugar_por_defecto) {
        $pdf_nombre = 'GUIA HOTELERA 2025 - Cafam.pdf';
        $path_pdf_candidato = dirname(__DIR__) . '/docs/' . $pdf_nombre;
        if (is_file($path_pdf_candidato)) {
          $path_pdf = $path_pdf_candidato;
        }
      }
    }

    $firma_file = '';
    if (!empty($evento['firma_imagen'])) {
      $tmp = dirname(__DIR__) . '/uploads/firmas/' . $evento['firma_imagen'];
      if (file_exists($tmp)) {
        $firma_file = $tmp;
      }
    }

    $img_file = dirname(__DIR__) . '/uploads/eventos/' . $evento['imagen'];
    $img_pub = base_url('uploads/eventos/' . $evento['imagen']);

    $wa_num = '';
    if (!empty($evento['whatsapp_numero'])) {
      $wa_num = preg_replace('/\D/', '', $evento['whatsapp_numero']);
    }

    $firma_url_public = '';
    if (!empty($evento['firma_imagen'])) {
      $firma_url_public = base_url('uploads/firmas/' . $evento['firma_imagen']);
    }

    // Texto humano de módulos para Curso Especial
    $modulos_human = '';
    if ($mod_form === 'curso_especial') {
      if ($asistencia_tipo === 'CURSO_COMPLETO') {
        $parts = array();
        for ($i = 0; $i < count($modulos_virtuales); $i++) {
          $m = $modulos_virtuales[$i];
          $parts[] = $m['nombre'] . ' (' . date('d/m/Y', strtotime($m['fecha'])) . ')';
        }
        $modulos_human = !empty($parts) ? implode(', ', $parts) : '';
      } elseif (!empty($modulos_csv) && $modulos_csv !== 'ALL') {
        $ids = explode(',', $modulos_csv);
        $map = array();
        for ($i = 0; $i < count($modulos_virtuales); $i++) {
          $map[(int) $modulos_virtuales[$i]['id']] = $modulos_virtuales[$i];
        }
        $parts = array();
        for ($i = 0; $i < count($ids); $i++) {
          $id = (int) $ids[$i];
          if (isset($map[$id])) {
            $m = $map[$id];
            $parts[] = $m['nombre'] . ' (' . date('d/m/Y', strtotime($m['fecha'])) . ')';
          }
        }
        $modulos_human = !empty($parts) ? implode(', ', $parts) : '';
      }
    } else {
      // Virtual clásico
      if ($asistencia_tipo === 'MODULOS' && !empty($modulos_csv)) {
        $mods = explode(',', $modulos_csv);
        $bloques = array();
        for ($i = 0; $i < count($fechas); $i++) {
          $f = $fechas[$i]['fecha'];
          if (in_array($f, $mods, true)) {
            $bloques[] = 'Día ' . ($i + 1) . ' (' . date('d/m/Y', strtotime($f)) . ')';
          }
        }
        $modulos_human = implode(', ', $bloques);
      }
    }

    $datosCorreo = array(
      'evento_id' => (int) $evento['id'],
      'nombre_evento' => $evento['nombre'],
      'modalidad' => $evento['modalidad'],
      'autoestudio' => (int) ($evento['autoestudio'] ?? 0),
      'fecha_limite' => $evento['fecha_limite'],
      'resumen_fechas' => $resumen_fechas,
      'detalle_horario' => $detalle_horario,
      'url_imagen' => $img_file,
      'url_imagen_public' => $img_pub,
      'adjunto_pdf' => $path_pdf,
      'firma_file' => $firma_file,
      'encargado_nombre' => $evento['encargado_nombre'],
      'lugar' => ($incluye_presencial ? $lugar_final : ''),
      'entidad_empresa' => $entidad,
      'nombre_inscrito' => $nombre,
      'whatsapp_numero' => $wa_num,
      'firma_url_public' => $firma_url_public,

      'asistencia_tipo' => $asistencia_tipo,
      'modulos_texto' => $modulos_human,
      'modulos_fechas' => $modulos_csv,
      'whatsapp_consent' => $whatsapp_consent,

      // flags para que en el siguiente paso el correo sea 100% preciso
      'incluye_presencial' => $incluye_presencial ? 'SI' : 'NO',
      'incluye_virtual' => $incluye_virtual ? 'SI' : 'NO'
    );

    $MAIL_DEBUG = false;

    $correo = new CorreoDenuncia();
    if ($MAIL_DEBUG) {
      $debugDir = dirname(__DIR__) . '/storage/mail_debug/';
      if (!is_dir($debugDir)) {
        @mkdir($debugDir, 0775, true);
      }

      $html = '';
      if (method_exists($correo, 'buildConfirmacionInscripcionHtml')) {
        $html = $correo->buildConfirmacionInscripcionHtml($nombre, $email_corporativo, $datosCorreo);
      } elseif (method_exists($correo, 'renderConfirmacionInscripcion')) {
        $html = $correo->renderConfirmacionInscripcion($nombre, $email_corporativo, $datosCorreo);
      } else {
        $html = '<h2>DEBUG CORREO - Confirmación inscripción</h2>'
          . '<pre style="white-space:pre-wrap;background:#111;color:#0f0;padding:16px;border-radius:12px">'
          . htmlspecialchars(print_r($datosCorreo, true), ENT_QUOTES, 'UTF-8')
          . '</pre>';
      }

      $fname = 'confirmacion_evento_' . (int) $evento['id'] . '_' . date('Ymd_His') . '.html';
      file_put_contents($debugDir . $fname, $html);

      echo '<div style="padding:16px;font-family:Arial">';
      echo '<h3>✅ Correo NO enviado (modo debug local)</h3>';
      echo '<p><code>' . htmlspecialchars($debugDir . $fname, ENT_QUOTES, 'UTF-8') . '</code></p>';
      echo '</div>';
      exit;
    } else {
      $correo->sendConfirmacionInscripcion($nombre, $email_corporativo, $datosCorreo);
    }

    // Aviso al comercial
    $com_email = '';
    $com_nombre = '';
    $eid = (int) $evento['id'];

    $stmtc = $conn->prepare("SELECT u.email, u.nombre
                                 FROM eventos e
                                 INNER JOIN usuarios u ON u.id = e.comercial_user_id
                                 WHERE e.id = ? LIMIT 1");
    if ($stmtc) {
      $stmtc->bind_param("i", $eid);
      if ($stmtc->execute()) {
        $stmtc->bind_result($c_email, $c_nombre);
        if ($stmtc->fetch()) {
          $com_email = $c_email;
          $com_nombre = $c_nombre;
        }
      }
      $stmtc->close();
    }

    if (!empty($com_email)) {
      $aviso = array(
        'evento_id' => $eid,
        'nombre_evento' => $evento['nombre'],
        'modalidad' => $evento['modalidad'],
        'resumen_fechas' => $resumen_fechas,
        'tipo_inscripcion' => $tipo_inscripcion,
        'inscrito_nombre' => $nombre,
        'cedula' => $cedula,
        'cargo' => $cargo,
        'entidad' => $entidad,
        'ciudad' => $ciudad,
        'celular' => $celular,
        'email_personal' => $email_personal,
        'email_corporativo' => $email_corporativo,
        'medio' => $medio,
        'soporte_pago' => $soporte_rel,

        // nuevo: selección exacta (para correo comercial, siguiente paso lo mostramos bonito)
        'asistencia_tipo' => $asistencia_tipo,
        'modulos_seleccionados' => $modulos_csv,
        'modulos_texto' => $modulos_human,
        'incluye_presencial' => $incluye_presencial ? 'SI' : 'NO',
        'incluye_virtual' => $incluye_virtual ? 'SI' : 'NO'
      );

      $okAviso = $correo->sendAvisoNuevaInscripcion($com_email, $aviso);
      if (!$okAviso) {
        error_log('[AVISO_COMERCIAL] Falló el envío al comercial: ' . $com_email);
      }
    }

    $mensaje_exito = true;
  } else {
    echo '<pre>Error al guardar inscripción: ' . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8') . '</pre>';
  }

  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Formulario de Inscripción</title>
  <link rel="stylesheet" href="../assets/css/output.css">
  <link rel="preload" href="../assets/img/loader-buho.gif" as="image">
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div style="max-width:1500px; margin:0 auto; padding:24px 16px;">
    <div class="bg-white rounded-2xl shadow-2xl" style="width:100%; max-width:1080px; margin:0 auto; padding:28px;">
      <?php if ($evento): ?>
        <img src="<?php echo htmlspecialchars('../uploads/eventos/' . $evento['imagen'], ENT_QUOTES, 'UTF-8'); ?>"
          alt="Imagen del evento" class="w-full h-48 md:h-64 lg:h-72 xl:h-80 object-cover rounded-xl mb-4 md:mb-6">
        <h1 class="text-2xl font-bold text-[#942934] mb-4 text-center">
          Inscripción al evento: <?php echo htmlspecialchars($evento['nombre'], ENT_QUOTES, 'UTF-8'); ?>
        </h1>

        <?php if ($mensaje_exito): ?>
          <div id="modalGracias" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-2xl shadow-2xl text-center" style="max-width:520px;width:92%;">
              <h2 class="text-xl font-bold text-[#942934] mb-4">🎉 ¡Inscripción exitosa!</h2>
              <p class="text-gray-700 mb-4">
                Gracias por registrarte. Hemos enviado un correo de confirmación a tu email corporativo.
              </p>
              <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px;">
                <button type="button" onclick="otraInscripcion()"
                  style="background:#0ea5e9;color:#fff;padding:10px 18px;border-radius:12px;font-weight:600;">
                  Realizar otra inscripción
                </button>
                <button type="button" onclick="cerrarModalGracias()"
                  style="background:#d32f57;color:#fff;padding:10px 18px;border-radius:12px;font-weight:600;">
                  Ir al inicio
                </button>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($mod === 'hibrida'): ?>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="p-4 border border-gray-300 rounded-xl bg-white">
              <div class="font-bold text-[#942934] mb-2">📍 Fechas presenciales</div>
              <?php echo pintarFechasHtml($fechas_presenciales); ?>
            </div>

            <div class="p-4 border border-gray-300 rounded-xl bg-white">
              <div class="font-bold text-[#942934] mb-2">💻 Fechas virtuales</div>
              <?php echo pintarFechasHtml($fechas_virtuales); ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($mod === 'curso_especial'): ?>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="p-4 border border-gray-300 rounded-xl bg-white">
              <div class="font-bold text-[#942934] mb-2">📍 Congreso / Presencial</div>
              <?php echo pintarFechasHtml($fechas_presenciales); ?>
            </div>
            <div class="p-4 border border-gray-300 rounded-xl bg-white">
              <div class="font-bold text-[#942934] mb-2">💻 Módulos virtuales</div>
              <?php echo pintarModulosVirtualesHtml($modulos_virtuales); ?>
            </div>
          </div>

          <div class="mb-4 p-4 border border-gray-300 rounded-xl bg-[#f8fafc]">
            <div class="font-bold text-gray-900 mb-1">🧭 Elige tu tipo de inscripción</div>
            <div class="text-sm text-gray-700">
              Selecciona una opción para que tu registro quede exactamente con lo que necesitas.
              El correo de confirmación mostrará con precisión tu elección.
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($evento['autoestudio']) && (int) $evento['autoestudio'] === 1): ?>
          <div class="mb-4 p-4 border border-gray-300 rounded-xl bg-green-50">
            <div class="font-bold text-green-800">✅ Este evento incluye Autoestudio</div>
            <div class="text-sm text-green-700 mt-1">
              Recibirás la información correspondiente en los canales oficiales del evento.
            </div>
          </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return preEnviar(event)" enctype="multipart/form-data" class="space-y-4">
          <input type="hidden" name="evento_id" value="<?php echo (int) $evento['id']; ?>">

          <div class="flex gap-4">
            <label class="flex items-center gap-2">
              <input type="radio" name="tipo_inscripcion" value="EMPRESA" required class="accent-[#942934]"> Empresa
            </label>
            <label class="flex items-center gap-2">
              <input type="radio" name="tipo_inscripcion" value="PERSONA NATURAL" required class="accent-[#942934]">
              Persona Natural
            </label>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input id="nombres" name="nombres" type="text" placeholder="Nombres" required
              class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
            <input id="apellidos" name="apellidos" type="text" placeholder="Apellidos" required
              class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          </div>

          <input type="text" name="cedula" placeholder="Cédula" required
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <input type="text" name="cargo" placeholder="Cargo" required
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <input type="text" name="entidad" id="entidad" placeholder="Entidad o Empresa" required
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <input type="text" name="celular" placeholder="Celular" required
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <input type="text" name="ciudad" placeholder="Ciudad" required
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <input type="email" name="email_personal" placeholder="Email Personal (opcional)"
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <input type="email" name="email_corporativo" placeholder="Email Corporativo" required
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />

          <?php if ($mod === 'virtual' && !empty($fechas)): ?>
            <div class="p-4 border border-gray-300 rounded-xl">
              <div class="font-semibold text-gray-800 mb-2">Asistencia</div>

              <label class="inline-flex items-center gap-2 mr-4">
                <input type="radio" name="asistencia_tipo" value="COMPLETO" class="accent-[#942934]" checked>
                Curso completo (<?php echo count($fechas); ?> día<?php echo count($fechas) > 1 ? 's' : ''; ?>)
              </label>

              <label class="inline-flex items-center gap-2">
                <input type="radio" name="asistencia_tipo" value="MODULOS" class="accent-[#942934]">
                Por módulos
              </label>

              <div id="wrap_modulos" class="mt-3 hidden">
                <div class="text-sm text-gray-600 mb-2">Elige uno o varios días:</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <?php for ($i = 0; $i < count($fechas); $i++):
                    $dia = $i + 1;
                    $f = $fechas[$i]['fecha'];
                    $rotulo = 'Día ' . $dia . ' — ' . date('d/m/Y', strtotime($f));
                    ?>
                    <label class="inline-flex items-center gap-2 p-2 border border-gray-200 rounded-lg">
                      <input type="checkbox" name="modulos[]" value="<?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?>"
                        class="accent-[#942934]">
                      <span><?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?></span>
                    </label>
                  <?php endfor; ?>
                </div>
                <p class="text-xs text-gray-500 mt-2">Selecciona al menos un día.</p>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($mod === 'curso_especial'): ?>
            <div class="p-4 border border-gray-300 rounded-xl">
              <div class="font-semibold text-gray-900 mb-3">Selecciona tu opción</div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <label
                  class="p-3 border border-gray-200 rounded-xl flex gap-2 items-start cursor-pointer hover:bg-slate-50">
                  <input type="radio" name="curso_especial_opcion" value="CONGRESO" class="accent-[#942934]" checked>
                  <div>
                    <div class="font-semibold">Opción 1: Congreso (Presencial)</div>
                    <div class="text-sm text-gray-600">Asistes únicamente al congreso presencial.</div>
                  </div>
                </label>

                <label
                  class="p-3 border border-gray-200 rounded-xl flex gap-2 items-start cursor-pointer hover:bg-slate-50">
                  <input type="radio" name="curso_especial_opcion" value="CURSO_COMPLETO" class="accent-[#942934]">
                  <div>
                    <div class="font-semibold">Opción 2: Curso completo</div>
                    <div class="text-sm text-gray-600">Presencial + todos los módulos virtuales.</div>
                  </div>
                </label>

                <label
                  class="p-3 border border-gray-200 rounded-xl flex gap-2 items-start cursor-pointer hover:bg-slate-50">
                  <input type="radio" name="curso_especial_opcion" value="MODULOS_VIRTUALES" class="accent-[#942934]">
                  <div>
                    <div class="font-semibold">Opción 3: Módulos virtuales</div>
                    <div class="text-sm text-gray-600">Seleccionas uno o más módulos virtuales (sin presencial).</div>
                  </div>
                </label>

                <label
                  class="p-3 border border-gray-200 rounded-xl flex gap-2 items-start cursor-pointer hover:bg-slate-50">
                  <input type="radio" name="curso_especial_opcion" value="CONGRESO_MAS_MODULOS" class="accent-[#942934]">
                  <div>
                    <div class="font-semibold">Opción 4: Congreso + módulos</div>
                    <div class="text-sm text-gray-600">Presencial + seleccionas uno o más módulos virtuales.</div>
                  </div>
                </label>
              </div>

              <div id="wrap_modulos_ce" class="mt-4 hidden">
                <div class="font-semibold text-gray-800 mb-2">Elige tus módulos virtuales</div>

                <?php if (!empty($modulos_virtuales)): ?>
                  <div class="space-y-2">
                    <?php for ($i = 0; $i < count($modulos_virtuales); $i++):
                      $m = $modulos_virtuales[$i];
                      $fechaTxt = date('d/m/Y', strtotime($m['fecha']));
                      ?>
                      <label class="flex gap-2 items-start p-3 border border-gray-200 rounded-xl">
                        <input type="checkbox" name="modulos_virtuales[]" value="<?php echo (int) $m['id']; ?>"
                          class="accent-[#942934] mt-1">
                        <div>
                          <div class="font-semibold text-gray-900">
                            <?php echo htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                          </div>
                          <div class="text-sm text-gray-700">Fecha: <?php echo $fechaTxt; ?></div>
                        </div>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <div class="text-xs text-gray-500 mt-2">Selecciona al menos un módulo cuando esta opción lo requiera.</div>
                <?php else: ?>
                  <div class="text-sm text-gray-600">Este evento aún no tiene módulos virtuales configurados.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <input type="file" name="soporte_pago" accept=".pdf,image/*"
            class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
          <p class="text-sm text-black-500 -mt-2">Soporte de Asistencia Opcional (PDF o imagen - máx. 10 MB).</p>

          <div class="p-4 border border-gray-300 rounded-xl">
            <div class="font-semibold text-gray-800 mb-2">
              Acepto la vinculación del número celular aquí registrado al grupo de WhatsApp que tendrá como única
              finalidad
              socializar toda la información relacionada con el evento, lo que incluye programación, recordatorios,
              capacitaciones y demás comunicaciones pertinentes.
            </div>
            <label class="inline-flex items-center gap-2 mr-6">
              <input type="radio" name="whatsapp_consent" value="SI" class="accent-[#942934]" required> SI
            </label>
            <label class="inline-flex items-center gap-2">
              <input type="radio" name="whatsapp_consent" value="NO" class="accent-[#942934]" required> NO
            </label>
          </div>

          <div class="p-4 border border-gray-200 rounded-xl">
            <label for="medio_opcion" class="font-semibold text-gray-800 mb-2 block">¿Por qué medio se enteró?</label>

            <select id="medio_opcion" name="medio_opcion" required class="w-full p-3 border border-gray-300 rounded-xl">
              <option value="">— Seleccione una opción —</option>
              <option value="CORREO">Correo electrónico</option>
              <option value="WHATSAPP">WhatsApp</option>
              <option value="LLAMADA">Llamada de nuestros ejecutivos de cuenta</option>
              <option value="REDES">Redes sociales</option>
              <option value="OTRO">Otro</option>
            </select>

            <input id="medio_otro" name="medio_otro" type="text" placeholder="¿Cuál medio?"
              class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500 mt-3 hidden" />
          </div>

          <button type="submit"
            class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-4 sm:px-6 rounded-xl w-full transition-all duration-300 hover:scale-[1.01] active:scale-[0.98]">
            Enviar inscripción
          </button>
        </form>

        <div id="loaderOverlay"
          style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:9999;">
          <div
            style="background:#fff;padding:22px 26px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;max-width:340px;">
            <img src="../assets/img/loader-buho.gif" alt="Cargando…"
              style="width:140px;height:auto;display:block;margin:0 auto 10px;">
            <div style="font-weight:700;color:#111827;margin-bottom:4px;">Enviando tu inscripción…</div>
            <div style="font-size:13px;color:#6b7280;">Por favor espera, esto puede tardar algunos segundos.</div>
          </div>
        </div>

      <?php else: ?>
        <p class="text-red-600 text-center text-lg font-bold">⚠️ Evento no encontrado</p>
      <?php endif; ?>
    </div>
  </div>

  <script src="../assets/js/jquery.min.js"></script>
  <script>
    function validarFormulario() {
      var correoEl = document.querySelector('input[name="email_corporativo"]');
      var correo = (correoEl ? correoEl.value : '').trim();
      var cedula = document.querySelector('input[name="cedula"]').value.trim();
      var celular = document.querySelector('input[name="celular"]').value.trim();

      if (!/^\d+$/.test(cedula)) {
        alert("La cédula debe contener solo números.");
        return false;
      }
      if (!/^\d{7,15}$/.test(celular)) {
        alert("El celular debe contener entre 7 y 15 dígitos.");
        return false;
      }
      if (!/^\S+@\S+\.\S+$/.test(correo)) {
        alert("Correo corporativo inválido.");
        return false;
      }
      return true;
    }

    function preEnviar(evt) {
      if (typeof validarFormulario === 'function') {
        if (!validarFormulario()) {
          if (evt && evt.preventDefault) evt.preventDefault();
          return false;
        }
      }

      // Validación Curso Especial: módulos obligatorios en casos 3 y 4
      var MODALIDAD = "<?php echo addslashes($mod); ?>";
      if (MODALIDAD === 'curso_especial') {
        var radios = document.getElementsByName('curso_especial_opcion');
        var sel = 'CONGRESO';
        for (var i = 0; i < radios.length; i++) {
          if (radios[i].checked) sel = radios[i].value;
        }
        if (sel === 'MODULOS_VIRTUALES' || sel === 'CONGRESO_MAS_MODULOS') {
          var wrap = document.getElementById('wrap_modulos_ce');
          if (wrap) {
            var checks = wrap.querySelectorAll('input[type="checkbox"]:checked');
            if (!checks || checks.length === 0) {
              alert('Por favor selecciona al menos un módulo virtual.');
              if (evt && evt.preventDefault) evt.preventDefault();
              return false;
            }
          }
        }
      }

      var btn = document.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.className += ' opacity-70 cursor-not-allowed';
        btn.innerHTML = '<img src="../assets/img/loader-buho.gif" alt="" style="height:20px;width:auto;vertical-align:middle;margin-right:8px;"> Enviando…';
      }

      var overlay = document.getElementById('loaderOverlay');
      if (overlay) overlay.style.display = 'flex';

      return true;
    }

    (function () {
      var entidad = document.getElementById('entidad');
      if (!entidad) return;
      entidad.addEventListener('input', function () {
        var start = this.selectionStart, end = this.selectionEnd;
        var v = this.value || '';
        this.value = v.toUpperCase();
        if (typeof start === 'number' && typeof end === 'number') {
          this.setSelectionRange(start, end);
        }
      });
    })();

    (function () {
      function toTitleCase(str) {
        str = (str || '').toLowerCase();
        var parts = str.split(/(\s+|-)/);
        for (var i = 0; i < parts.length; i++) {
          var s = parts[i];
          if (s && !/^\s+$/.test(s) && s !== '-') {
            parts[i] = s.charAt(0).toUpperCase() + s.slice(1);
          }
        }
        return parts.join('');
      }
      function bindTitleCase(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
          var pos = this.selectionStart;
          var val = this.value || '';
          var nuevo = toTitleCase(val);
          if (nuevo !== val) {
            this.value = nuevo;
            if (typeof pos === 'number') this.setSelectionRange(pos, pos);
          }
        });
      }
      bindTitleCase('nombres');
      bindTitleCase('apellidos');
    })();

    (function () {
      var radios = document.getElementsByName('asistencia_tipo');
      var wrap = document.getElementById('wrap_modulos');
      if (!wrap || !radios || radios.length === 0) return;

      function toggleModulos() {
        var tipo = 'COMPLETO';
        for (var i = 0; i < radios.length; i++) { if (radios[i].checked) tipo = radios[i].value; }
        wrap.className = (tipo === 'MODULOS')
          ? wrap.className.replace(' hidden', '')
          : (wrap.className.indexOf('hidden') >= 0 ? wrap.className : wrap.className + ' hidden');
      }

      for (var i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', toggleModulos);
      }
      toggleModulos();
    })();

    // Curso Especial: mostrar/ocultar selección de módulos
    (function () {
      var MODALIDAD = "<?php echo addslashes($mod); ?>";
      if (MODALIDAD !== 'curso_especial') return;

      var wrap = document.getElementById('wrap_modulos_ce');
      var radios = document.getElementsByName('curso_especial_opcion');
      if (!wrap || !radios || radios.length === 0) return;

      function toggleCE() {
        var sel = 'CONGRESO';
        for (var i = 0; i < radios.length; i++) {
          if (radios[i].checked) sel = radios[i].value;
        }
        if (sel === 'MODULOS_VIRTUALES' || sel === 'CONGRESO_MAS_MODULOS') {
          wrap.classList.remove('hidden');
        } else {
          wrap.classList.add('hidden');
          var checks = wrap.querySelectorAll('input[type="checkbox"]');
          for (var j = 0; j < checks.length; j++) checks[j].checked = false;
        }
      }

      for (var i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', toggleCE);
      }
      toggleCE();
    })();

    function cerrarModalGracias() {
      var modal = document.getElementById('modalGracias');
      if (modal) modal.classList.add('hidden');
      window.location.href = "https://fycconsultores.com/inicio";
    }

    var SLUG_ACTUAL = "<?php echo isset($slug) ? addslashes($slug) : ''; ?>";
    if (!SLUG_ACTUAL) {
      var m = location.search.match(/[?&]e=([^&]+)/);
      if (m) { try { SLUG_ACTUAL = decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { } }
    }

    function otraInscripcion() {
      window.location.href = "registro.php?e=" + encodeURIComponent(SLUG_ACTUAL);
    }

    (function () {
      var sel = document.getElementById('medio_opcion');
      var otro = document.getElementById('medio_otro');
      if (!sel || !otro) return;

      function toggleOtro() {
        if (sel.value === 'OTRO') {
          otro.classList.remove('hidden');
          otro.required = true;
        } else {
          otro.classList.add('hidden');
          otro.required = false;
          otro.value = '';
        }
      }
      sel.addEventListener('change', toggleOtro);
      toggleOtro();
    })();
  </script>
</body>

</html>