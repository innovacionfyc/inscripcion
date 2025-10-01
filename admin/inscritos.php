<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/_auth.php';
require_login();

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';
require_once dirname(__DIR__) . '/correo/enviar_correo.php';
date_default_timezone_set('America/Bogota');

/* ====== Helper de rol robusto ====== */
function fyc_is_admin_from_session()
{
  $cands = array(
    isset($_SESSION['user']['role']) ? $_SESSION['user']['role'] : null,
    isset($_SESSION['user']['rol']) ? $_SESSION['user']['rol'] : null,
    isset($_SESSION['role']) ? $_SESSION['role'] : null,
    isset($_SESSION['rol']) ? $_SESSION['rol'] : null,
    isset($_SESSION['user']['perfil']) ? $_SESSION['user']['perfil'] : null,
    isset($_SESSION['perfil']) ? $_SESSION['perfil'] : null,
  );

  if (isset($_SESSION['user']['is_admin']) && ($_SESSION['user']['is_admin'] === true || $_SESSION['user']['is_admin'] === 1 || $_SESSION['user']['is_admin'] === '1')) {
    return true;
  }

  foreach ($cands as $r) {
    if (!isset($r))
      continue;
    $v = strtolower(trim((string) $r));
    if ($v === 'admin' || $v === 'administrator' || $v === 'administrador' || $v === 'superadmin' || $v === 'super') {
      return true;
    }
  }
  return false;
}

$es_admin = fyc_is_admin_from_session();

$evento_id = isset($_GET['evento_id']) ? (int) $_GET['evento_id'] : 0;
if ($evento_id <= 0) {
  http_response_code(400);
  echo "Falta evento_id.";
  exit;
}

/* ====== Eliminar inscrito (solo ADMIN) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
  if (!$es_admin) {
    http_response_code(403);
    echo "No autorizado";
    exit;
  }

  $inscrito_id = isset($_POST['inscrito_id']) ? (int) $_POST['inscrito_id'] : 0;
  if ($inscrito_id > 0) {
    $stmtS = $conn->prepare("SELECT soporte_pago FROM inscritos WHERE id = ? AND evento_id = ? LIMIT 1");
    $stmtS->bind_param("ii", $inscrito_id, $evento_id);
    if ($stmtS->execute()) {
      $stmtS->bind_result($sopRel);
      if ($stmtS->fetch() && !empty($sopRel)) {
        $abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($sopRel, '/');
        if (is_file($abs))
          @unlink($abs);
      }
    }
    $stmtS->close();

    $stmtD = $conn->prepare("DELETE FROM inscritos WHERE id = ? AND evento_id = ? LIMIT 1");
    $stmtD->bind_param("ii", $inscrito_id, $evento_id);
    $stmtD->execute();
    $stmtD->close();
  }
  header("Location: inscritos.php?evento_id=" . $evento_id);
  exit;
}

/* ====== REENVIAR correo (visible para todos los roles) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'reenviar') {
  $inscrito_id = isset($_POST['inscrito_id']) ? (int) $_POST['inscrito_id'] : 0;
  $destino_tipo = isset($_POST['destino_tipo']) ? strtolower(trim($_POST['destino_tipo'])) : 'corp';
  $email_otro = isset($_POST['email_otro']) ? trim($_POST['email_otro']) : '';

  $okReenviar = false;
  if ($inscrito_id > 0) {
    // Inscrito (trae campos nuevos)
    $stmtI = $conn->prepare("SELECT tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, 
                                     email_personal, email_corporativo, medio, soporte_pago,
                                     asistencia_tipo, modulos_seleccionados
                              FROM inscritos
                              WHERE id = ? AND evento_id = ? LIMIT 1");
    $stmtI->bind_param("ii", $inscrito_id, $evento_id);
    $stmtI->execute();
    $stmtI->bind_result(
      $tipo,
      $ins_nombre,
      $cedula,
      $cargo,
      $entidad,
      $celular,
      $ciudad,
      $email_p,
      $email_c,
      $medio,
      $soporte,
      $asistencia_tipo,
      $modulos_csv
    );
    if ($stmtI->fetch()) {
      $stmtI->close();

      // Evento
      $evento = null;
      $stmtE = $conn->prepare("SELECT nombre, imagen, modalidad, fecha_limite, whatsapp_numero, firma_imagen, encargado_nombre, lugar_personalizado
                               FROM eventos WHERE id = ? LIMIT 1");
      $stmtE->bind_param("i", $evento_id);
      $stmtE->execute();
      $stmtE->bind_result($ev_nombre, $ev_imagen, $ev_modalidad, $ev_limite, $ev_wa, $ev_firma, $ev_encargado, $ev_lugar);
      if ($stmtE->fetch()) {
        $evento = array(
          'id' => $evento_id,
          'nombre' => $ev_nombre,
          'imagen' => $ev_imagen,
          'modalidad' => $ev_modalidad,
          'fecha_limite' => $ev_limite,
          'whatsapp_numero' => $ev_wa,
          'firma_imagen' => $ev_firma,
          'encargado_nombre' => $ev_encargado,
          'lugar_personalizado' => $ev_lugar
        );
      }
      $stmtE->close();

      if ($evento) {
        // Fechas del evento (con horas)
        $fechas = array();
        $stmtF = $conn->prepare("SELECT fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC");
        $stmtF->bind_param("i", $evento_id);
        $stmtF->execute();
        $stmtF->bind_result($f_fecha, $f_hi, $f_hf);
        while ($stmtF->fetch()) {
          $fechas[] = array('fecha' => $f_fecha, 'hora_inicio' => $f_hi, 'hora_fin' => $f_hf);
        }
        $stmtF->close();

        /* === AJUSTE CLAVE: filtrar fechas si es virtual + asistencia por módulos === */
        $esVirtual = (isset($evento['modalidad']) && strtolower($evento['modalidad']) === 'virtual');
        $usarFiltrado = $esVirtual && strtoupper((string) $asistencia_tipo) === 'MODULOS' && !empty($modulos_csv);

        $fechas_base = $fechas;
        if ($usarFiltrado) {
          $modsSet = array();
          foreach (explode(',', $modulos_csv) as $v) {
            $v = trim($v);
            if ($v !== '') {
              $modsSet[$v] = true;
            }
          }
          $fechas_filtradas = array();
          foreach ($fechas as $f) {
            if (isset($modsSet[$f['fecha']])) {
              $fechas_filtradas[] = $f;
            }
          }
          if (!empty($fechas_filtradas)) {
            $fechas_base = $fechas_filtradas; // solo módulos seleccionados
          }
        }

        // Resumen/Detalle horario (usando $fechas_base)
        $resumen_fechas = '';
        if (!empty($fechas_base)) {
          $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
          $dias = array();
          for ($i = 0; $i < count($fechas_base); $i++)
            $dias[] = (int) date('j', strtotime($fechas_base[$i]['fecha']));
          $mes = $meses[(int) date('n', strtotime($fechas_base[0]['fecha'])) - 1];
          $anio = date('Y', strtotime($fechas_base[0]['fecha']));
          if (count($dias) == 1) {
            $resumen_fechas = $dias[0] . " de $mes de $anio";
          } else {
            $ult = array_pop($dias);
            $resumen_fechas = implode(', ', $dias) . " y $ult de $mes de $anio";
          }
        }
        $detalle_horario = '';
        if (!empty($fechas_base)) {
          $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
          $det = "<ul style='margin:0;padding-left:18px'>";
          for ($i = 0; $i < count($fechas_base); $i++) {
            $f = $fechas_base[$i];
            $d = (int) date('j', strtotime($f['fecha']));
            $m = $meses[(int) date('n', strtotime($f['fecha'])) - 1];
            $y = date('Y', strtotime($f['fecha']));
            $hi = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_inicio']));
            $hf = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_fin']));
            $hi = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hi));
            $hf = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hf));
            $det .= "<li>Día " . ($i + 1) . ": $d de $m de $y — <strong>$hi</strong> a <strong>$hf</strong></li>";
          }
          $det .= "</ul>";
          $detalle_horario = $det;
        }

        // Lugar final para el correo (solo presencial)
        $lugar_final = '';
        $modLow = strtolower($evento['modalidad'] ?? '');
        if ($modLow === 'presencial') {
          if (!empty($evento['lugar_personalizado'])) {
            // Permitimos <br> si el admin lo escribió con saltos de línea
            $lugar_final = nl2br($evento['lugar_personalizado']);
          } else {
            // Por defecto
            $lugar_final = "Centro de Convenciones Cafam Floresta<br>Av. Cra. 68 No. 90-88, Bogotá - Salón Sauces";
          }
        }

        // Correo destino
        $correoDestino = '';
        if ($destino_tipo === 'otro' && preg_match('/^\S+@\S+\.\S+$/', $email_otro)) {
          $correoDestino = $email_otro;
        } elseif ($destino_tipo === 'pers' && !empty($email_p)) {
          $correoDestino = $email_p;
        } else {
          $correoDestino = !empty($email_c) ? $email_c : $email_p;
        }

        if (!empty($correoDestino)) {
          // Archivos/URLs
          $img_file = dirname(__DIR__) . '/uploads/eventos/' . $evento['imagen'];
          $img_pub = base_url('uploads/eventos/' . $evento['imagen']);
          $wa_num = !empty($evento['whatsapp_numero']) ? preg_replace('/\D/', '', $evento['whatsapp_numero']) : '';
          $firma_file = '';
          if (!empty($evento['firma_imagen'])) {
            $tmp = dirname(__DIR__) . '/uploads/firmas/' . $evento['firma_imagen'];
            if (is_file($tmp))
              $firma_file = $tmp;
          }
          $firma_url_public = !empty($evento['firma_imagen']) ? base_url('uploads/firmas/' . $evento['firma_imagen']) : '';

          // Texto humano de módulos (renumerado sobre $fechas_base)
          $modulos_human = '';
          if (strtoupper((string) $asistencia_tipo) === 'MODULOS' && !empty($modulos_csv)) {
            $mods = array();
            foreach (explode(',', $modulos_csv) as $v) {
              $v = trim($v);
              if ($v !== '') {
                $mods[$v] = true;
              }
            }
            $chunks = array();
            for ($i = 0; $i < count($fechas_base); $i++) {
              $f = $fechas_base[$i]['fecha'];
              if (isset($mods[$f])) {
                $chunks[] = 'Día ' . ($i + 1) . ' (' . date('d/m/Y', strtotime($f)) . ')';
              }
            }
            $modulos_human = implode(', ', $chunks);
          }

          // Datos para el correo
          $datosCorreo = array(
            'evento_id' => (int) $evento['id'],
            'nombre_evento' => $evento['nombre'],
            'modalidad' => $evento['modalidad'],
            'fecha_limite' => $evento['fecha_limite'],
            'resumen_fechas' => $resumen_fechas,
            'detalle_horario' => $detalle_horario,
            'url_imagen' => $img_file,
            'url_imagen_public' => $img_pub,
            'adjunto_pdf' => (strtolower($evento['modalidad']) === 'presencial' ? (is_file(dirname(__DIR__) . '/docs/GUIA HOTELERA 2025 - Cafam.pdf') ? dirname(__DIR__) . '/docs/GUIA HOTELERA 2025 - Cafam.pdf' : null) : null),
            'firma_file' => $firma_file,
            'firma_url_public' => $firma_url_public,
            'encargado_nombre' => $evento['encargado_nombre'],
            'lugar' => $lugar_final,
            'entidad_empresa' => $entidad,
            'nombre_inscrito' => $ins_nombre,
            'whatsapp_numero' => $wa_num,
            'asistencia_tipo' => $asistencia_tipo,
            'modulos_texto' => $modulos_human,
          );

          $mailer = new CorreoDenuncia();
          $okReenviar = $mailer->sendConfirmacionInscripcion($ins_nombre, $correoDestino, $datosCorreo);
        }
      }
    } else {
      $stmtI->close();
    }
  }

  header("Location: inscritos.php?evento_id=" . $evento_id . "&reenviado=" . ($okReenviar ? 1 : 0));
  exit;
}

/* ====== Info del evento ====== */
$evento = null;
$stmtE = $conn->prepare("SELECT e.nombre, e.slug, e.modalidad, e.fecha_limite, u.nombre AS comercial
                         FROM eventos e
                         LEFT JOIN usuarios u ON u.id = e.comercial_user_id
                         WHERE e.id = ? LIMIT 1");
$stmtE->bind_param("i", $evento_id);
$stmtE->execute();
$stmtE->bind_result($ev_nombre, $ev_slug, $ev_modalidad, $ev_limite, $ev_comercial);
if ($stmtE->fetch()) {
  $evento = array(
    'id' => $evento_id,
    'nombre' => $ev_nombre,
    'slug' => $ev_slug,
    'modalidad' => $ev_modalidad,
    'limite' => $ev_limite,
    'comercial' => $ev_comercial
  );
}
$stmtE->close();

if (!$evento) {
  http_response_code(404);
  echo "Evento no encontrado.";
  exit;
}

/* ====== Mapa fechas -> "Día N (dd/mm)" para asistencia por módulos ====== */
$fechas_map = array();
$stmtF = $conn->prepare("SELECT fecha FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC");
$stmtF->bind_param("i", $evento_id);
$stmtF->execute();
$stmtF->bind_result($f_fecha);
$idx = 0;
while ($stmtF->fetch()) {
  $idx++;
  $fechas_map[$f_fecha] = 'Día ' . $idx . ' (' . date('d/m', strtotime($f_fecha)) . ')';
}
$stmtF->close();

function format_asistencia($tipo, $mods_csv, $fechas_map)
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
      if (isset($fechas_map[$v]))
        $out[] = $fechas_map[$v];
      else
        $out[] = '(' . date('d/m', strtotime($v)) . ')';
    }
    return !empty($out) ? 'Módulos: ' . implode(', ', $out) : 'Módulos: —';
  }
  return '—';
}

/* ====== Inscritos (con campos nuevos) ====== */
$inscritos = array();
$stmt = $conn->prepare("SELECT 
                          id, tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, 
                          email_personal, email_corporativo, medio, soporte_pago,
                          asistencia_tipo, modulos_seleccionados, whatsapp_consent, fecha_registro
                        FROM inscritos
                        WHERE evento_id = ?
                        ORDER BY id DESC");
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$stmt->bind_result(
  $id,
  $tipo,
  $nombre,
  $cedula,
  $cargo,
  $entidad,
  $celular,
  $ciudad,
  $email_p,
  $email_c,
  $medio,
  $soporte,
  $asis_tipo,
  $mods_csv,
  $wa_consent,
  $f_reg
);
while ($stmt->fetch()) {
  $inscritos[] = array(
    'id' => $id,
    'tipo_inscripcion' => $tipo,
    'nombre' => $nombre,
    'cedula' => $cedula,
    'cargo' => $cargo,
    'entidad' => $entidad,
    'celular' => $celular,
    'ciudad' => $ciudad,
    'email_personal' => $email_p,
    'email_corporativo' => $email_c,
    'medio' => $medio,
    'soporte_pago' => $soporte,
    'asistencia_tipo' => $asis_tipo,
    'modulos_seleccionados' => $mods_csv,
    'whatsapp_consent' => $wa_consent,
    'fecha_registro' => $f_reg
  );
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Inscritos – <?php echo htmlspecialchars($evento['nombre'], ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>

<body class="bg-gray-100 min-h-screen">
  <div class="max-w-screen-2xl mx-auto px-4 py-6">
    <?php
    $back_to = 'eventos.php';
    $show_back = true;
    include __DIR__ . '/_topbar.php';
    ?>

    <?php if (isset($_GET['reenviado'])): ?>
      <?php if ($_GET['reenviado'] == '1'): ?>
        <div class="mb-4 bg-green-100 text-green-800 px-4 py-3 rounded-xl">✅ Correo reenviado correctamente.</div>
      <?php else: ?>
        <div class="mb-4 bg-yellow-100 text-yellow-800 px-4 py-3 rounded-xl">⚠️ No se pudo reenviar el correo.</div>
      <?php endif; ?>
    <?php endif; ?>

    <h1 class="text-2xl font-bold text-[#942934] mb-2">👥 Inscritos –
      <?php echo htmlspecialchars($evento['nombre'], ENT_QUOTES, 'UTF-8'); ?>
    </h1>
    <p class="text-sm text-gray-600 mb-6">
      Modalidad: <strong><?php echo htmlspecialchars($evento['modalidad'], ENT_QUOTES, 'UTF-8'); ?></strong> •
      Límite: <strong><?php echo htmlspecialchars($evento['limite'], ENT_QUOTES, 'UTF-8'); ?></strong> •
      Comercial: <strong><?php echo htmlspecialchars($evento['comercial'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong>
    </p>

    <div class="bg-white rounded-2xl shadow-2xl p-5 overflow-x-auto">
      <table class="min-w-full text-xs md:text-sm">
        <thead>
          <tr class="text-left border-b">
            <th class="py-2 pr-3">#</th>
            <th class="py-2 pr-3">Nombre</th>
            <th class="py-2 pr-3">Cédula</th>
            <th class="py-2 pr-3">Entidad</th>
            <th class="py-2 pr-3">Cargo</th>
            <th class="py-2 pr-3">Ciudad</th>
            <th class="py-2 pr-3">Celular</th>
            <th class="py-2 pr-3">Email</th>
            <th class="py-2 pr-3">Tipo</th>
            <th class="py-2 pr-3">Asistencia</th>
            <th class="py-2 pr-3">Fecha de inscripción</th>
            <th class="py-2 pr-3">WhatsApp</th>
            <th class="py-2 pr-3">Soporte de Asistencia</th>
            <th class="py-2 pr-3">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($inscritos)): ?>
            <tr>
              <td colspan="14" class="py-4 text-center text-gray-500">Sin inscritos aún</td>
            </tr>
          <?php else: ?>
            <?php $n = 1;
            foreach ($inscritos as $r): ?>
              <tr class="border-b hover:bg-gray-50">
                <td class="py-2 pr-3"><?php echo $n++; ?></td>
                <td class="py-2 pr-3 font-medium"><?php echo htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['cedula'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3 whitespace-normal break-words">
                  <?php echo htmlspecialchars($r['entidad'], ENT_QUOTES, 'UTF-8'); ?>
                </td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['cargo'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['ciudad'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['celular'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3 break-all">
                  <?php echo htmlspecialchars($r['email_corporativo'] ?: $r['email_personal'], ENT_QUOTES, 'UTF-8'); ?>
                </td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['tipo_inscripcion'], ENT_QUOTES, 'UTF-8'); ?></td>

                <!-- Asistencia -->
                <td class="py-2 pr-3">
                  <?php
                  echo htmlspecialchars(
                    format_asistencia($r['asistencia_tipo'], $r['modulos_seleccionados'], $fechas_map),
                    ENT_QUOTES,
                    'UTF-8'
                  );
                  ?>
                </td>

                <!-- Fecha de inscripción -->
                <td class="py-2 pr-3">
                  <?php
                  if (!empty($r['fecha_registro'])) {
                    try {
                      $dt = new DateTime($r['fecha_registro'], new DateTimeZone('UTC')); // si tu BD guarda UTC
                      $dt->setTimezone(new DateTimeZone('America/Bogota'));
                      echo $dt->format('d/m/Y g:i a'); // ej: 14/09/2025 3:27 pm
                    } catch (Exception $e) {
                      $ts = strtotime($r['fecha_registro']);
                      echo $ts ? date('d/m/Y g:i a', $ts) : '—';
                    }
                  } else {
                    echo '—';
                  }
                  ?>
                </td>

                <!-- Consentimiento WhatsApp -->
                <td class="py-2 pr-3">
                  <?php
                  $w = strtoupper((string) $r['whatsapp_consent']);
                  echo ($w === 'SI' || $w === 'NO') ? $w : '—';
                  ?>
                </td>

                <!-- Soporte -->
                <td class="py-2 pr-3">
                  <?php if (!empty($r['soporte_pago'])):
                    $url = '/' . ltrim($r['soporte_pago'], '/');
                    $abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($r['soporte_pago'], '/');
                    $existe = is_file($abs);
                    ?>
                    <?php if ($existe): ?>
                      <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank"
                        class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-lg">Ver</a>
                      <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" download
                        class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded-lg ml-2">Descargar</a>
                    <?php else: ?>
                      <span class="inline-block bg-yellow-100 text-yellow-800 px-2 py-1 rounded-lg">Ruta inválida</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="inline-block bg-red-100 text-red-800 px-2 py-1 rounded-lg">Sin soporte</span>
                  <?php endif; ?>
                </td>

                <!-- Acciones -->
                <td class="py-2 pr-3 whitespace-nowrap">
                  <!-- Reenviar (todos los roles) -->
                  <button type="button" class="inline-block bg-teal-600 hover:bg-teal-700 text-white px-3 py-1 rounded-lg"
                    data-id="<?php echo (int) $r['id']; ?>"
                    data-nombre="<?php echo htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-corp="<?php echo htmlspecialchars($r['email_corporativo'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-pers="<?php echo htmlspecialchars($r['email_personal'], ENT_QUOTES, 'UTF-8'); ?>"
                    onclick="openReenviarFromBtn(this)">
                    Reenviar
                  </button>

                  <?php if ($es_admin): ?>
                    <a href="editar_inscrito.php?id=<?php echo (int) $r['id']; ?>&evento_id=<?php echo $evento_id; ?>"
                      class="inline-block bg-sky-600 hover:bg-sky-700 text-white px-3 py-1 rounded-lg ml-2">Editar</a>
                    <form method="POST" class="inline-block ml-2" onsubmit="return confirm('¿Eliminar este inscrito?');">
                      <input type="hidden" name="accion" value="eliminar">
                      <input type="hidden" name="inscrito_id" value="<?php echo (int) $r['id']; ?>">
                      <button type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-lg">Eliminar</button>
                    </form>
                  <?php else: ?>
                    <!-- no admin: solo Reenviar -->
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- MODAL REENVIAR -->
  <div id="modalReenviar" class="fixed inset-0 hidden bg-black bg-opacity-50 items-center justify-center z-50">
    <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-md">
      <h3 class="text-lg font-bold text-[#942934] mb-2">Reenviar confirmación</h3>
      <p id="reenviarNombre" class="text-sm text-gray-600 mb-3"></p>

      <form method="POST" onsubmit="return validarReenvio();">
        <input type="hidden" name="accion" value="reenviar">
        <input type="hidden" name="inscrito_id" id="reenviar_inscrito_id">

        <div class="space-y-2 text-sm">
          <div id="optCorp" class="hidden">
            <label class="inline-flex items-center gap-2">
              <input type="radio" name="destino_tipo" value="corp" class="accent-[#942934]">
              <span>Correo corporativo: <span id="labCorp" class="font-medium"></span></span>
            </label>
          </div>
          <div id="optPers" class="hidden">
            <label class="inline-flex items-center gap-2">
              <input type="radio" name="destino_tipo" value="pers" class="accent-[#942934]">
              <span>Correo personal: <span id="labPers" class="font-medium"></span></span>
            </label>
          </div>
          <div>
            <label class="inline-flex items-center gap-2">
              <input type="radio" name="destino_tipo" value="otro" class="accent-[#942934]">
              <span>Otro correo:</span>
            </label>
            <input type="email" name="email_otro" id="email_otro"
              class="mt-2 w-full p-2 border border-gray-300 rounded-lg" placeholder="escribe@correo.com" disabled>
          </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button type="button" onclick="cerrarModalReenviar()"
            class="px-4 py-2 rounded-lg border border-gray-300">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-white bg-teal-600 hover:bg-teal-700">Enviar</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openReenviarFromBtn(btn) {
      var id = btn.getAttribute('data-id');
      var nom = btn.getAttribute('data-nombre') || '';
      var corp = btn.getAttribute('data-corp') || '';
      var pers = btn.getAttribute('data-pers') || '';

      document.getElementById('reenviar_inscrito_id').value = id;
      document.getElementById('reenviarNombre').textContent = 'Inscrito: ' + nom;

      var optC = document.getElementById('optCorp'), labC = document.getElementById('labCorp');
      var optP = document.getElementById('optPers'), labP = document.getElementById('labPers');

      if (corp) { optC.classList.remove('hidden'); labC.textContent = corp; }
      else { optC.classList.add('hidden'); labC.textContent = ''; }

      if (pers) { optP.classList.remove('hidden'); labP.textContent = pers; }
      else { optP.classList.add('hidden'); labP.textContent = ''; }

      var radios = document.getElementsByName('destino_tipo');
      for (var i = 0; i < radios.length; i++) { radios[i].checked = false; }
      var emailOtro = document.getElementById('email_otro');
      emailOtro.value = ''; emailOtro.disabled = true;

      if (corp) {
        document.querySelector('input[name="destino_tipo"][value="corp"]').checked = true;
      } else if (pers) {
        document.querySelector('input[name="destino_tipo"][value="pers"]').checked = true;
      } else {
        document.querySelector('input[name="destino_tipo"][value="otro"]').checked = true;
        emailOtro.disabled = false;
        emailOtro.focus();
      }

      document.getElementById('modalReenviar').classList.remove('hidden');
      document.getElementById('modalReenviar').classList.add('flex');
    }
    function cerrarModalReenviar() {
      var m = document.getElementById('modalReenviar');
      m.classList.add('hidden'); m.classList.remove('flex');
    }
    (function () {
      var radios = document.getElementsByName('destino_tipo');
      var emailOtro = document.getElementById('email_otro');
      function toggle() {
        var sel = '';
        for (var i = 0; i < radios.length; i++) { if (radios[i].checked) sel = radios[i].value; }
        emailOtro.disabled = (sel !== 'otro');
        if (sel === 'otro') emailOtro.focus();
      }
      for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', toggle); }
    })();
    function validarReenvio() {
      var sel = ''; var radios = document.getElementsByName('destino_tipo');
      for (var i = 0; i < radios.length; i++) { if (radios[i].checked) sel = radios[i].value; }
      if (sel === 'otro') {
        var v = (document.getElementById('email_otro').value || '').trim();
        if (!/^\S+@\S+\.\S+$/.test(v)) { alert('Por favor escribe un correo válido.'); return false; }
      }
      return true;
    }
  </script>
</body>

</html>