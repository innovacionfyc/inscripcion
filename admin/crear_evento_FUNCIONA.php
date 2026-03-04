<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/_auth.php';
require_login();
require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';

function generarSlug($texto)
{
  $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $texto)));
  return $slug;
}

/* Lugar por defecto para eventos presenciales (el que usas en los correos) */
$DEFAULT_LUGAR_PRESENCIAL = "Centro de Convenciones Cafam Floresta\nAv. Cra. 68 No. 90-88, Bogotá - Salón Sauces";

// Cargar usuarios comerciales desde la BD
$comerciales = [];
$sqlUsuarios = "SELECT id, nombre, email, whatsapp FROM usuarios WHERE activo = 1 ORDER BY nombre ASC";
$resUsuarios = $conn->query($sqlUsuarios);
if ($resUsuarios && $resUsuarios->num_rows > 0) {
  while ($row = $resUsuarios->fetch_assoc()) {
    $comerciales[] = $row;
  }
}

// Helper: guardarAdjuntos (múltiples archivos)
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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $nombre = $_POST["nombre"] ?? '';
  $slug = generarSlug($nombre);
  $modalidad = $_POST["modalidad"] ?? '';
  $fecha_limite = $_POST["fecha_limite"] ?? '';
  $comercial_id = $_POST["comercial_user_id"] ?? null;

  // Flags (mantengo compatibilidad con tu código)
  $autoestudio = isset($_POST['autoestudio']) ? 1 : 0;
  $trabajo_integrador = isset($_POST['trabajo_integrador']) ? 1 : 0;

  $modLow = strtolower(trim($modalidad));

  // Lugar personalizado: aplica a modalidades que tienen bloque presencial
  $cambiar_lugar = isset($_POST['cambiar_lugar']) ? strtoupper(trim($_POST['cambiar_lugar'])) : 'NO';
  $lugar_personalizado = null;
  if (($modLow === 'presencial' || $modLow === 'hibrida' || $modLow === 'curso_especial') && $cambiar_lugar === 'SI') {
    $lugar_personalizado = trim($_POST['lugar_personalizado'] ?? '');
    if ($lugar_personalizado === '')
      $lugar_personalizado = null;
  }

  // Upload imagen principal del evento
  $nombreImagen = '';
  if (!empty($_FILES['imagen']['name']) && $_FILES['imagen']['error'] === 0) {
    $uploadsDir = dirname(__DIR__) . '/uploads/eventos/';
    if (!is_dir($uploadsDir))
      @mkdir($uploadsDir, 0775, true);
    $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
    $nombreImagen = uniqid('evento_') . '.' . $ext;
    move_uploaded_file($_FILES['imagen']['tmp_name'], $uploadsDir . $nombreImagen);
  }

  // INSERT evento
  $stmt = $conn->prepare("INSERT INTO eventos (nombre, slug, imagen, modalidad, lugar_personalizado, fecha_limite, comercial_user_id, autoestudio) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("ssssssii", $nombre, $slug, $nombreImagen, $modalidad, $lugar_personalizado, $fecha_limite, $comercial_id, $autoestudio);

  if ($stmt->execute()) {
    $evento_id = $stmt->insert_id;
    $stmt->close();

    // =========================================================
    // 1) Guardar fechas/horarios (eventos_fechas)
    //    - Presencial / Virtual / Hibrida: como ya estaba
    //    - Curso Especial: SOLO fechas presenciales (tipo = presencial)
    // =========================================================
    $stmt_fecha = $conn->prepare("INSERT INTO eventos_fechas (evento_id, tipo, fecha, hora_inicio, hora_fin) VALUES (?, ?, ?, ?, ?)");
    $tipo = $fecha = $hora_inicio = $hora_fin = null;
    $stmt_fecha->bind_param("issss", $evento_id, $tipo, $fecha, $hora_inicio, $hora_fin);

    if ($modLow === 'hibrida') {

      $fechasP = $_POST["fechas_presencial"] ?? [];
      $hinP = $_POST["hora_inicio_presencial"] ?? [];
      $hfinP = $_POST["hora_fin_presencial"] ?? [];

      for ($i = 0; $i < count($fechasP); $i++) {
        $tipo = 'presencial';
        $fecha = $fechasP[$i] ?? null;
        $hora_inicio = $hinP[$i] ?? null;
        $hora_fin = $hfinP[$i] ?? null;
        if (!empty($fecha))
          $stmt_fecha->execute();
      }

      $fechasV = $_POST["fechas_virtual"] ?? [];
      $hinV = $_POST["hora_inicio_virtual"] ?? [];
      $hfinV = $_POST["hora_fin_virtual"] ?? [];

      for ($i = 0; $i < count($fechasV); $i++) {
        $tipo = 'virtual';
        $fecha = $fechasV[$i] ?? null;
        $hora_inicio = $hinV[$i] ?? null;
        $hora_fin = $hfinV[$i] ?? null;
        if (!empty($fecha))
          $stmt_fecha->execute();
      }

    } else {

      $fechas = $_POST["fechas"] ?? [];
      $hin = $_POST["hora_inicio"] ?? [];
      $hfin = $_POST["hora_fin"] ?? [];

      // Curso Especial se comporta como "bloque presencial"
      if ($modLow === 'curso_especial') {
        $tipoNormal = 'presencial';
      } else {
        $tipoNormal = ($modLow === 'presencial') ? 'presencial' : (($modLow === 'virtual') ? 'virtual' : 'general');
      }

      for ($i = 0; $i < count($fechas); $i++) {
        $tipo = $tipoNormal;
        $fecha = $fechas[$i] ?? null;
        $hora_inicio = $hin[$i] ?? null;
        $hora_fin = $hfin[$i] ?? null;
        if (!empty($fecha))
          $stmt_fecha->execute();
      }
    }

    $stmt_fecha->close();

    // =========================================================
    // 2) Curso Especial: Guardar Módulos Virtuales (máx 10)
    //    en eventos_modulos_virtuales
    // =========================================================
    if ($modLow === 'curso_especial') {
      $mods_fecha = $_POST['modulos_fecha'] ?? [];
      $mods_hi = $_POST['modulos_hora_inicio'] ?? [];
      $mods_hf = $_POST['modulos_hora_fin'] ?? [];
      $mods_nom = $_POST['modulos_nombre'] ?? [];

      // Normalizar arrays
      if (!is_array($mods_fecha))
        $mods_fecha = [];
      if (!is_array($mods_hi))
        $mods_hi = [];
      if (!is_array($mods_hf))
        $mods_hf = [];
      if (!is_array($mods_nom))
        $mods_nom = [];

      // Insert preparado
      $stmt_mod = $conn->prepare("INSERT INTO eventos_modulos_virtuales (evento_id, orden, fecha, hora_inicio, hora_fin, nombre, activo) VALUES (?, ?, ?, ?, ?, ?, 1)");
      $orden = 1;
      $mfecha = $mhi = $mhf = $mnom = null;
      $stmt_mod->bind_param("iissss", $evento_id, $orden, $mfecha, $mhi, $mhf, $mnom);

      $insertados = 0;
      for ($i = 0; $i < count($mods_fecha); $i++) {
        if ($insertados >= 10)
          break;

        $mfecha = trim($mods_fecha[$i] ?? '');
        $mhi = trim($mods_hi[$i] ?? '');
        $mhf = trim($mods_hf[$i] ?? '');
        $mnom = trim($mods_nom[$i] ?? '');

        // Si la fila está vacía, la saltamos
        if ($mfecha === '' && $mnom === '' && $mhi === '' && $mhf === '') {
          continue;
        }

        // Validación mínima: fecha y nombre obligatorios para guardar
        if ($mfecha === '' || $mnom === '') {
          // Si llega incompleto, lo ignoramos para no romper el flujo.
          continue;
        }

        $orden = $insertados + 1;
        $stmt_mod->execute();
        $insertados++;
      }

      $stmt_mod->close();
    }

    // =========================================================
    // 3) Adjuntos por modalidad (docs/...)
    //    - Curso Especial: permite adjuntar presencial y virtual
    // =========================================================
    $baseDocs = dirname(__DIR__) . '/docs';

    // Adjuntos Autoestudio
    if ((int) $autoestudio === 1) {
      $destA = $baseDocs . '/autoestudio/' . (int) $evento_id;
      guardarAdjuntos('docs_autoestudio', $destA);
    }

    if ($modLow === 'virtual') {
      $dest = $baseDocs . '/evento_virtual/' . (int) $evento_id;
      guardarAdjuntos('docs_virtual', $dest);
    } elseif ($modLow === 'presencial') {
      $dest = $baseDocs . '/evento_presencial/' . (int) $evento_id;
      guardarAdjuntos('docs_presencial', $dest);
    } elseif ($modLow === 'hibrida') {
      $destV = $baseDocs . '/evento_virtual/' . (int) $evento_id;
      guardarAdjuntos('docs_virtual', $destV);

      $destP = $baseDocs . '/evento_presencial/' . (int) $evento_id;
      guardarAdjuntos('docs_presencial', $destP);
    } elseif ($modLow === 'curso_especial') {
      // En Curso Especial dejamos adjuntar ambos (porque hay congreso + módulos virtuales)
      $destV = $baseDocs . '/evento_virtual/' . (int) $evento_id;
      guardarAdjuntos('docs_virtual', $destV);

      $destP = $baseDocs . '/evento_presencial/' . (int) $evento_id;
      guardarAdjuntos('docs_presencial', $destP);
    }

    // Compatibilidad (tus flags)
    if ($trabajo_integrador) {
      $destT = $baseDocs . '/trabajo_integrador/' . (int) $evento_id;
      guardarAdjuntos('docs_trabajo_integrador', $destT);
    }

    $conn->close();
    header("Location: " . basename(__FILE__) . "?ok=1&slug=" . urlencode($slug));
    exit;

  } else {
    $error = "Error al guardar el evento: " . $stmt->error;
    $stmt->close();
    $conn->close();
  }
}

$slugValue = $_GET['slug'] ?? '';
$formURL = $slugValue ? base_url('registro.php?e=' . urlencode($slugValue)) : '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Crear evento</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div class="w-full max-w-4xl bg-white rounded-2xl shadow-2xl p-8 mt-10 space-y-6">
    <?php
    $back_to = 'dashboard.php';
    $show_back = true;
    include __DIR__ . '/_topbar.php';
    ?>
    <h1 class="text-2xl font-bold text-[#942934] text-center mb-4">📆 Crear nuevo evento</h1>

    <?php if (isset($_GET['ok']) && $_GET['ok'] == 1 && $slugValue): ?>
      <div class="bg-green-100 text-green-800 font-medium px-6 py-4 rounded-xl mb-4">
        <div class="flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24"
            stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <span>Evento creado exitosamente</span>
        </div>

        <div class="mt-3">
          <p class="text-sm">Enlace del formulario:</p>
          <div class="flex items-center gap-2 mt-2">
            <input type="text" id="urlFormulario"
              class="bg-white text-sm border border-gray-300 rounded-xl px-4 py-2 w-full"
              value="<?php echo htmlspecialchars($formURL, ENT_QUOTES, 'UTF-8'); ?>" readonly>
            <button onclick="copiarURL()"
              class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold px-4 py-2 rounded-xl transition-all">
              📋 Copiar URL
            </button>
          </div>
          <p id="mensajeCopiado" class="text-green-600 text-sm font-medium mt-1 hidden transition-opacity duration-300">
            ✔️ Enlace copiado
          </p>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="bg-red-100 text-red-800 font-medium px-6 py-4 rounded-xl mb-4">
        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <form id="form-evento" method="POST" enctype="multipart/form-data" class="space-y-6">
      <input type="text" name="nombre" placeholder="Nombre del evento" required
        class="w-full p-3 border border-gray-300 rounded-xl" />
      <input type="file" name="imagen" accept="image/*" required class="w-full border border-gray-300 rounded-xl p-2" />

      <div>
        <label class="font-semibold text-gray-700">Seleccionar comercial:</label>
        <select name="comercial_user_id" required class="w-full p-3 border border-gray-300 rounded-xl mt-2">
          <option value="">Selecciona...</option>
          <?php foreach ($comerciales as $c): ?>
            <option value="<?php echo $c['id']; ?>">
              <?php echo htmlspecialchars($c['nombre']); ?> (
              <?php echo htmlspecialchars($c['email']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div id="wrap_num_dias_normal">
          <label class="font-semibold text-gray-700">¿Cuántos días dura el evento?</label>
          <select id="num_dias" class="w-full p-3 border border-gray-300 rounded-xl mt-2">
            <option value="">Selecciona...</option>
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <option value="<?php echo $i; ?>">
                <?php echo $i; ?> día
                <?php echo ($i > 1 ? 's' : ''); ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div id="dias_container" class="space-y-4"></div>

        <div id="wrap_num_dias_hibrida" class="hidden space-y-4">
          <div>
            <label class="font-semibold text-gray-700">¿Cuántos días PRESENCIALES?</label>
            <select id="num_dias_presencial" class="w-full p-3 border border-gray-300 rounded-xl mt-2">
              <option value="">Selecciona...</option>
              <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?php echo $i; ?>">
                  <?php echo $i; ?> día
                  <?php echo ($i > 1 ? 's' : ''); ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label class="font-semibold text-gray-700">¿Cuántos días VIRTUALES?</label>
            <select id="num_dias_virtual" class="w-full p-3 border border-gray-300 rounded-xl mt-2">
              <option value="">Selecciona...</option>
              <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?php echo $i; ?>">
                  <?php echo $i; ?> día
                  <?php echo ($i > 1 ? 's' : ''); ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div id="dias_presencial_wrap" class="space-y-4 hidden">
          <h3 class="text-lg font-bold text-gray-800">Fechas presenciales</h3>
          <div id="dias_presencial_container" class="space-y-4"></div>
        </div>

        <div id="dias_virtual_wrap" class="space-y-4 hidden">
          <h3 class="text-lg font-bold text-gray-800">Fechas virtuales</h3>
          <div id="dias_virtual_container" class="space-y-4"></div>
        </div>

        <div class="mt-4">
          <label class="font-semibold text-gray-700">Modalidad:</label>
          <select id="modalidad" name="modalidad" required class="w-full p-3 border border-gray-300 rounded-xl mt-2">
            <option value="">Selecciona...</option>
            <option value="Presencial">Presencial</option>
            <option value="Virtual">Virtual</option>
            <option value="Hibrida">Híbrida</option>
            <option value="Curso_Especial">Curso Especial</option>
          </select>
        </div>

        <!-- Curso Especial: módulos virtuales (máx 10) -->
        <div id="curso_especial_wrap" class="mt-4 p-4 border border-gray-200 rounded-xl hidden">
          <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
              <div class="font-semibold text-gray-800">Módulos virtuales (máx 10)</div>
              <div class="text-sm text-gray-600 mt-1">Configura fecha, hora y nombre/tema de cada módulo.</div>
            </div>
            <button type="button" id="btnAddModulo"
              class="bg-[#0ea5e9] hover:bg-[#0284c7] text-white font-bold px-4 py-2 rounded-xl transition-all">
              + Agregar módulo
            </button>
          </div>

          <div id="modulos_virtuales_container" class="mt-4 space-y-3"></div>

          <div class="text-xs text-gray-500 mt-3">
            Nota: Si no agregas módulos, el evento igual se puede crear (solo congreso/presencial).
          </div>
        </div>

        <div id="wrapCambiarLugar" class="mt-3 p-4 border border-gray-200 rounded-xl hidden">
          <div class="font-semibold mb-2">¿Deseas cambiar el lugar por defecto?</div>
          <label class="inline-flex items-center gap-2 mr-4">
            <input type="radio" id="lugar_si" name="cambiar_lugar" value="SI" class="accent-[#942934]">
            Sí
          </label>
          <label class="inline-flex items-center gap-2">
            <input type="radio" id="lugar_no" name="cambiar_lugar" value="NO" class="accent-[#942934]" checked>
            No
          </label>

          <textarea id="lugar_personalizado" name="lugar_personalizado"
            class="mt-3 w-full p-3 border border-gray-300 rounded-xl" rows="3"
            placeholder="Escribe aquí el lugar del evento" disabled></textarea>
        </div>

        <div id="docs_virtual_wrap" style="display:none; margin-top:12px;">
          <label class="block font-semibold mb-1">Adjuntos para evento virtual</label>
          <input type="file" name="docs_virtual[]" multiple class="w-full p-3 border border-gray-300 rounded-xl">
          <small class="text-gray-500">Formatos: pdf, doc(x), xls(x), ppt(x), jpg, png, webp. Máx 25MB c/u.</small>
        </div>

        <div id="docs_presencial_wrap" style="display:none; margin-top:12px;">
          <label class="block font-semibold mb-1">Adjuntos para evento presencial</label>
          <input type="file" name="docs_presencial[]" multiple class="w-full p-3 border border-gray-300 rounded-xl">
          <small class="text-gray-500">Formatos: pdf, doc(x), xls(x), ppt(x), jpg, png, webp. Máx 25MB c/u.</small>
        </div>

        <div class="mt-4 p-4 border border-gray-200 rounded-xl">
          <div class="font-semibold text-gray-700 mb-2">Autoestudio y trabajo integrador</div>

          <label class="inline-flex items-center gap-2">
            <input type="checkbox" id="autoestudio" name="autoestudio" value="1" class="accent-[#942934]">
            Incluye Autoestudio y trabajo integrador
          </label>

          <div id="wrap_autoestudio_files" class="mt-3 hidden">
            <label class="block font-semibold mb-1">Adjuntos de Autoestudio</label>
            <input type="file" name="docs_autoestudio[]" multiple class="w-full p-3 border border-gray-300 rounded-xl">
            <small class="text-gray-500">Puedes adjuntar guías, PDFs, etc.</small>
          </div>
        </div>

        <div class="mt-4">
          <label class="font-semibold text-gray-700">Fecha límite para confirmar asistencia:</label>
          <input type="date" name="fecha_limite" required class="w-full p-3 border border-gray-300 rounded-xl mt-2">
        </div>

        <div class="text-center mt-4">
          <button type="submit"
            class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-8 rounded-xl transition-all">
            Crear evento
          </button>
        </div>
      </div>
    </form>
  </div>

  <script src="../assets/js/jquery.min.js"></script>
  <script>
    (function () {
      function fycGetModalidad() {
        var sel = document.querySelector('select[name="modalidad"], #modalidad');
        if (sel && typeof sel.value !== 'undefined') return String(sel.value || '').toLowerCase();
        return '';
      }

      var DEF_LUGAR = <?php echo json_encode($DEFAULT_LUGAR_PRESENCIAL); ?>;

      function fycToggleAdjuntosYLugar() {
        var v = fycGetModalidad();

        // ✅ NORMALIZACIÓN (para soportar "Curso Especial" y "Curso_Especial")
        var vNorm = (v || '').toLowerCase().replace(/\s+/g, '_');

        var vWrap = document.getElementById('docs_virtual_wrap');
        var pWrap = document.getElementById('docs_presencial_wrap');

        // Curso Especial: permite adjuntar ambos
        if (vWrap) vWrap.style.display = (vNorm === 'virtual' || vNorm === 'hibrida' || vNorm === 'curso_especial') ? 'block' : 'none';
        if (pWrap) pWrap.style.display = (vNorm === 'presencial' || vNorm === 'hibrida' || vNorm === 'curso_especial') ? 'block' : 'none';

        // wrappers días
        var wrapNormal = document.getElementById('wrap_num_dias_normal');
        var wrapH = document.getElementById('wrap_num_dias_hibrida');

        var diasNormal = document.getElementById('dias_container');
        var presWrap = document.getElementById('dias_presencial_wrap');
        var virtWrap = document.getElementById('dias_virtual_wrap');

        var selN = document.getElementById('num_dias');
        var selP = document.getElementById('num_dias_presencial');
        var selV = document.getElementById('num_dias_virtual');

        if (vNorm === 'hibrida') {
          if (wrapNormal) wrapNormal.classList.add('hidden');
          if (wrapH) wrapH.classList.remove('hidden');

          if (selN) selN.value = '';
          if (diasNormal) diasNormal.classList.add('hidden');
          if (presWrap) presWrap.classList.remove('hidden');
          if (virtWrap) virtWrap.classList.remove('hidden');
        } else {
          // Presencial, Virtual o Curso Especial: usa flujo normal (un solo selector de días)
          if (wrapNormal) wrapNormal.classList.remove('hidden');
          if (wrapH) wrapH.classList.add('hidden');

          if (selP) selP.value = '';
          if (selV) selV.value = '';
          if (diasNormal) diasNormal.classList.remove('hidden');
          if (presWrap) presWrap.classList.add('hidden');
          if (virtWrap) virtWrap.classList.add('hidden');
        }

        // Curso Especial: mostrar bloque módulos
        var ceWrap = document.getElementById('curso_especial_wrap');
        if (ceWrap) {
          if (vNorm === 'curso_especial') {
            ceWrap.classList.remove('hidden');
            // ✅ Asegurar que el botón quede “amarrado” siempre
            if (typeof window.__bindCE === 'function') window.__bindCE();
          } else {
            ceWrap.classList.add('hidden');
          }
        }

        // mostrar bloque cambiar lugar si hay presencial
        var lugarWrap = document.getElementById('wrapCambiarLugar');
        var rSi = document.getElementById('lugar_si');
        var rNo = document.getElementById('lugar_no');
        var ta = document.getElementById('lugar_personalizado');

        if (lugarWrap) {
          if (vNorm === 'presencial' || vNorm === 'hibrida' || vNorm === 'curso_especial') {
            lugarWrap.classList.remove('hidden');
            var si = rSi && rSi.checked;
            if (ta) {
              ta.disabled = !si;
              if (si && !ta.value) ta.value = DEF_LUGAR;
              if (!si && ta.value === DEF_LUGAR) ta.value = '';
            }
          } else {
            lugarWrap.classList.add('hidden');
            if (ta) ta.disabled = true;
            if (rNo) rNo.checked = true;
            if (rSi) rSi.checked = false;
          }
        }

        if (typeof window.__renderDias === 'function') window.__renderDias();
      }

      function fycBind() {
        var sel = document.querySelector('select[name="modalidad"], #modalidad');
        if (sel) sel.addEventListener('change', fycToggleAdjuntosYLugar);

        var rSi = document.getElementById('lugar_si');
        var rNo = document.getElementById('lugar_no');
        if (rSi) rSi.addEventListener('change', fycToggleAdjuntosYLugar);
        if (rNo) rNo.addEventListener('change', fycToggleAdjuntosYLugar);

        fycToggleAdjuntosYLugar();
      }

      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fycBind);
      else fycBind();
    })();

    (function () {
      function toggleAutoestudio() {
        var chk = document.getElementById('autoestudio');
        var wrap = document.getElementById('wrap_autoestudio_files');
        if (!chk || !wrap) return;

        if (chk.checked) {
          wrap.classList.remove('hidden');
        } else {
          wrap.classList.add('hidden');
          var input = wrap.querySelector('input[type="file"]');
          if (input) input.value = '';
        }
      }

      function bindAutoestudio() {
        var chk = document.getElementById('autoestudio');
        if (chk) chk.addEventListener('change', toggleAutoestudio);
        toggleAutoestudio();
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAutoestudio);
      } else {
        bindAutoestudio();
      }
    })();

    (function () {
      function byId(id) { return document.getElementById(id); }

      function getModalidadLower() {
        var sel = document.querySelector('select[name="modalidad"], #modalidad');
        if (sel && typeof sel.value !== 'undefined') return String(sel.value || '').toLowerCase();
        return '';
      }

      function buildRowsHTML(n, prefix, old) {
        var html = '';
        for (var j = 0; j < n; j++) {
          var ov = (old && old[j]) ? old[j] : { f: '', hi: '', hf: '' };
          html += ''
            + '<div class="fyc-dia-row grid grid-cols-1 md:grid-cols-3 gap-3">'
            + '  <div>'
            + '    <label class="block text-sm font-medium mb-1">Fecha día ' + (j + 1) + '</label>'
            + '    <input type="date" name="fechas_' + prefix + '[]" value="' + ov.f + '" class="w-full p-3 border border-gray-300 rounded-xl" required>'
            + '  </div>'
            + '  <div>'
            + '    <label class="block text-sm font-medium mb-1">Hora inicio</label>'
            + '    <input type="time" name="hora_inicio_' + prefix + '[]" value="' + ov.hi + '" class="w-full p-3 border border-gray-300 rounded-xl" required>'
            + '  </div>'
            + '  <div>'
            + '    <label class="block text-sm font-medium mb-1">Hora fin</label>'
            + '    <input type="time" name="hora_fin_' + prefix + '[]" value="' + ov.hf + '" class="w-full p-3 border border-gray-300 rounded-xl" required>'
            + '  </div>'
            + '</div>';
        }
        return html;
      }

      function readOldValues(containerId, prefix) {
        var wrap = byId(containerId);
        if (!wrap) return [];
        var out = [];
        var rows = wrap.getElementsByClassName('fyc-dia-row');
        for (var i = 0; i < rows.length; i++) {
          var f = rows[i].querySelector('input[name="fechas_' + prefix + '[]"]');
          var hi = rows[i].querySelector('input[name="hora_inicio_' + prefix + '[]"]');
          var hf = rows[i].querySelector('input[name="hora_fin_' + prefix + '[]"]');
          out.push({ f: f ? f.value : '', hi: hi ? hi.value : '', hf: hf ? hf.value : '' });
        }
        return out;
      }

      function renderDias() {
        var modalidad = getModalidadLower();

        var normalWrap = byId('dias_container');
        var presWrap = byId('dias_presencial_container');
        var virtWrap = byId('dias_virtual_container');

        if (modalidad === 'hibrida') {
          var selP = byId('num_dias_presencial');
          var selV = byId('num_dias_virtual');
          var nP = selP ? parseInt(selP.value, 10) : 0;
          var nV = selV ? parseInt(selV.value, 10) : 0;

          if (!nP || nP < 1) {
            if (presWrap) presWrap.innerHTML = '';
          } else {
            var oldPres = readOldValues('dias_presencial_container', 'presencial');
            if (presWrap) presWrap.innerHTML = buildRowsHTML(nP, 'presencial', oldPres);
          }

          if (!nV || nV < 1) {
            if (virtWrap) virtWrap.innerHTML = '';
          } else {
            var oldVirt = readOldValues('dias_virtual_container', 'virtual');
            if (virtWrap) virtWrap.innerHTML = buildRowsHTML(nV, 'virtual', oldVirt);
          }

          if (normalWrap) normalWrap.innerHTML = '';
          return;
        }

        var sel = byId('num_dias');
        var n = sel ? parseInt(sel.value, 10) : 0;

        if (!n || n < 1) {
          if (normalWrap) normalWrap.innerHTML = '';
          if (presWrap) presWrap.innerHTML = '';
          if (virtWrap) virtWrap.innerHTML = '';
          return;
        }

        var old = [];
        if (normalWrap) {
          var rows = normalWrap.getElementsByClassName('fyc-dia-row');
          for (var i = 0; i < rows.length; i++) {
            var f = rows[i].querySelector('input[name="fechas[]"]');
            var hi = rows[i].querySelector('input[name="hora_inicio[]"]');
            var hf = rows[i].querySelector('input[name="hora_fin[]"]');
            old.push({ f: f ? f.value : '', hi: hi ? hi.value : '', hf: hf ? hf.value : '' });
          }
        }

        var html = '';
        for (var j = 0; j < n; j++) {
          var ov = old[j] || { f: '', hi: '', hf: '' };
          html += ''
            + '<div class="fyc-dia-row grid grid-cols-1 md:grid-cols-3 gap-3">'
            + '  <div>'
            + '    <label class="block text-sm font-medium mb-1">Fecha día ' + (j + 1) + '</label>'
            + '    <input type="date" name="fechas[]" value="' + ov.f + '" class="w-full p-3 border border-gray-300 rounded-xl" required>'
            + '  </div>'
            + '  <div>'
            + '    <label class="block text-sm font-medium mb-1">Hora inicio</label>'
            + '    <input type="time" name="hora_inicio[]" value="' + ov.hi + '" class="w-full p-3 border border-gray-300 rounded-xl" required>'
            + '  </div>'
            + '  <div>'
            + '    <label class="block text-sm font-medium mb-1">Hora fin</label>'
            + '    <input type="time" name="hora_fin[]" value="' + ov.hf + '" class="w-full p-3 border border-gray-300 rounded-xl" required>'
            + '  </div>'
            + '</div>';
        }

        if (normalWrap) normalWrap.innerHTML = html;
        if (presWrap) presWrap.innerHTML = '';
        if (virtWrap) virtWrap.innerHTML = '';
      }

      window.__renderDias = renderDias;

      function bindDias() {
        var sel = byId('num_dias');
        if (sel) sel.addEventListener('change', renderDias);

        var selP = byId('num_dias_presencial');
        var selV = byId('num_dias_virtual');
        if (selP) selP.addEventListener('change', renderDias);
        if (selV) selV.addEventListener('change', renderDias);

        var modSel = document.querySelector('select[name="modalidad"], #modalidad');
        if (modSel) modSel.addEventListener('change', renderDias);

        renderDias();
      }

      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindDias);
      else bindDias();
    })();

    // Curso Especial: builder de módulos virtuales (máx 10)
    (function () {
      function byId(id) { return document.getElementById(id); }

      function addModuloRow(prefill) {
        var cont = byId('modulos_virtuales_container');
        if (!cont) return;

        var rows = cont.getElementsByClassName('ce-mod-row');
        if (rows && rows.length >= 10) {
          alert('Máximo 10 módulos virtuales.');
          return;
        }

        var idx = (rows ? rows.length : 0) + 1;
        var v = prefill || { fecha: '', hi: '', hf: '', nombre: '' };

        var div = document.createElement('div');
        div.className = 'ce-mod-row p-3 border border-gray-200 rounded-xl';

        div.innerHTML = ''
          + '<div class="flex items-center justify-between gap-2 mb-2">'
          + '  <div class="font-semibold text-gray-800">Módulo ' + idx + '</div>'
          + '  <button type="button" class="ce-del text-sm font-bold text-red-600">Quitar</button>'
          + '</div>'
          + '<div class="grid grid-cols-1 md:grid-cols-4 gap-3">'
          + '  <div>'
          + '    <label class="block text-sm font-medium mb-1">Fecha</label>'
          + '    <input type="date" name="modulos_fecha[]" value="' + (v.fecha || '') + '" class="w-full p-3 border border-gray-300 rounded-xl">'
          + '  </div>'
          + '  <div>'
          + '    <label class="block text-sm font-medium mb-1">Hora inicio</label>'
          + '    <input type="time" name="modulos_hora_inicio[]" value="' + (v.hi || '') + '" class="w-full p-3 border border-gray-300 rounded-xl">'
          + '  </div>'
          + '  <div>'
          + '    <label class="block text-sm font-medium mb-1">Hora fin</label>'
          + '    <input type="time" name="modulos_hora_fin[]" value="' + (v.hf || '') + '" class="w-full p-3 border border-gray-300 rounded-xl">'
          + '  </div>'
          + '  <div>'
          + '    <label class="block text-sm font-medium mb-1">Nombre / Tema</label>'
          + '    <input type="text" name="modulos_nombre[]" value="' + (v.nombre || '').replace(/"/g, '&quot;') + '" placeholder="Ej: Módulo 1 - Control interno" class="w-full p-3 border border-gray-300 rounded-xl">'
          + '  </div>'
          + '</div>';

        cont.appendChild(div);

        var btnDel = div.querySelector('.ce-del');
        if (btnDel) {
          btnDel.addEventListener('click', function () {
            div.parentNode.removeChild(div);
            renumerar();
          });
        }
      }

      function renumerar() {
        var cont = byId('modulos_virtuales_container');
        if (!cont) return;
        var rows = cont.getElementsByClassName('ce-mod-row');
        for (var i = 0; i < rows.length; i++) {
          var t = rows[i].querySelector('.font-semibold');
          if (t) t.textContent = 'Módulo ' + (i + 1);
        }
      }

      function bindCE() {
        var btn = byId('btnAddModulo');
        if (!btn) return;

        // Evitar doble bind si se llama más de una vez
        if (btn.getAttribute('data-bound') === '1') return;
        btn.setAttribute('data-bound', '1');

        btn.addEventListener('click', function () {
          addModuloRow();
          // por si el usuario está en iframe / o cambia altura
          if (typeof window.__renderDias === 'function') window.__renderDias();
        });
      }

      // ✅ Exponer bindCE para que se pueda llamar cuando se muestre Curso Especial
      window.__bindCE = bindCE;

      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindCE);
      else bindCE();
    })();

    function copiarURL() {
      var input = document.getElementById('urlFormulario');
      var msg = document.getElementById('mensajeCopiado');
      var texto = input.value;

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(function () {
          msg.classList.remove('hidden');
          setTimeout(function () { msg.classList.add('hidden'); }, 1500);
        }).catch(fallbackCopy);
      } else {
        fallbackCopy();
      }

      function fallbackCopy() {
        input.select();
        try {
          document.execCommand('copy');
          msg.classList.remove('hidden');
          setTimeout(function () { msg.classList.add('hidden'); }, 1500);
        } catch (e) {
          msg.textContent = "❌ No se pudo copiar";
          msg.classList.remove('hidden');
        }
      }
    }
    window.copiarURL = copiarURL;
  </script>
</body>

</html>