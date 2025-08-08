<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php'; // si lo estás usando

// debug opcional, luego bórralo
 echo dirname(__DIR__) . '/db/conexion.php'; exit;
// --- CONFIGURACIÓN DE URL BASE ---
define('APP_BASE', '/public'); // porque tu URL es https://inscripcion.fycconsultores.com/public/

function base_url(string $path = ''): string {
    // Detecta HTTPS incluso detrás de proxy/reverse proxy
    $https = 'http';
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
        $https = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'inscripcion.fycconsultores.com';
    $base = rtrim(APP_BASE, '/');
    $uri  = ltrim($path, '/');

    return $https . '://' . $host . ($base ? '/' . $base : '') . '/' . $uri;
}
// --- FIN CONFIGURACIÓN URL BASE ---

function generarSlug($texto) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $texto)));
    return $slug;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre       = $_POST["nombre"];
    $slug         = generarSlug($nombre);
    $modalidad    = $_POST["modalidad"];
    $fecha_limite = $_POST["fecha_limite"];

    $nombreImagen = '';
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === 0) {
        $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $nombreImagen = uniqid('evento_') . '.' . $ext;
        @mkdir(__DIR__ . '/../uploads/eventos/', 0775, true);
        move_uploaded_file($_FILES['imagen']['tmp_name'], __DIR__ . '/../uploads/eventos/' . $nombreImagen);
    }

    $stmt = $conn->prepare("INSERT INTO eventos (nombre, slug, imagen, modalidad, fecha_limite) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $nombre, $slug, $nombreImagen, $modalidad, $fecha_limite);

    if ($stmt->execute()) {
        $evento_id = $stmt->insert_id;
        $stmt->close();

        $fechas        = isset($_POST["fechas"]) ? $_POST["fechas"] : [];
        $horas_inicio  = isset($_POST["hora_inicio"]) ? $_POST["hora_inicio"] : [];
        $horas_fin     = isset($_POST["hora_fin"]) ? $_POST["hora_fin"] : [];

        if (!empty($fechas)) {
            $stmt_fecha = $conn->prepare("INSERT INTO eventos_fechas (evento_id, fecha, hora_inicio, hora_fin) VALUES (?, ?, ?, ?)");
            $stmt_fecha->bind_param("isss", $evento_id, $fecha, $hora_inicio, $hora_fin);

            for ($i = 0; $i < count($fechas); $i++) {
                $fecha       = $fechas[$i];
                $hora_inicio = $horas_inicio[$i] ?? null;
                $hora_fin    = $horas_fin[$i] ?? null;
                $stmt_fecha->execute();
            }
            $stmt_fecha->close();
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

$slugValue = isset($_GET['slug']) ? $_GET['slug'] : '';
$formURL   = $slugValue ? base_url('registro.php?e=' . urlencode($slugValue)) : '';
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
    <h1 class="text-2xl font-bold text-[#942934] text-center mb-4">📆 Crear nuevo evento</h1>

    <?php if (isset($_GET['ok']) && $_GET['ok'] == 1 && $slugValue): ?>
      <div class="bg-green-100 text-green-800 font-medium px-6 py-4 rounded-xl mb-4">
        <div class="flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <span>Evento creado exitosamente</span>
        </div>

        <div class="mt-3">
          <p class="text-sm">Enlace del formulario:</p>
          <div class="flex items-center gap-2 mt-2">
            <input
              type="text"
              id="urlFormulario"
              class="bg-white text-sm border border-gray-300 rounded-xl px-4 py-2 w-full"
              value="<?= htmlspecialchars($formURL, ENT_QUOTES, 'UTF-8') ?>"
              readonly
            >
            <button
              onclick="copiarURL()"
              class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold px-4 py-2 rounded-xl transition-all"
            >
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
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form id="form-evento" method="POST" enctype="multipart/form-data" class="space-y-6">
      <input type="text" name="nombre" placeholder="Nombre del evento" required class="w-full p-3 border border-gray-300 rounded-xl" />
      <input type="file" name="imagen" accept="image/*" required class="w-full border border-gray-300 rounded-xl p-2" />

      <div>
        <label class="font-semibold text-gray-700">¿Cuántos días dura el evento?</label>
        <select id="num_dias" class="w-full p-3 border border-gray-300 rounded-xl mt-2">
          <option value="">Selecciona...</option>
          <?php for ($i = 1; $i <= 7; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?> día<?= $i > 1 ? 's' : '' ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div id="dias_container" class="space-y-4"></div>

      <div>
        <label class="font-semibold text-gray-700">Modalidad:</label>
        <select name="modalidad" required class="w-full p-3 border border-gray-300 rounded-xl mt-2">
          <option value="">Selecciona...</option>
          <option value="Presencial">Presencial</option>
          <option value="Virtual">Virtual</option>
        </select>
      </div>

      <div>
        <label class="font-semibold text-gray-700">Fecha límite para confirmar asistencia:</label>
        <input type="date" name="fecha_limite" required class="w-full p-3 border border-gray-300 rounded-xl mt-2">
      </div>

      <div class="text-center">
        <button type="submit" class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-8 rounded-xl transition-all">
          Crear evento
        </button>
      </div>
    </form>
  </div>

  <script src="../assets/js/jquery.min.js"></script>
  <script>
    $('#num_dias').on('change', function () {
      const num = parseInt(this.value || 0, 10);
      const container = $('#dias_container');
      container.empty();

      for (let i = 1; i <= num; i++) {
        container.append(`
          <div class="p-4 border border-gray-200 rounded-xl">
            <h3 class="font-bold mb-2 text-[#685f2f]">Día ${i}</h3>
            <label>Fecha:</label>
            <input type="date" name="fechas[]" class="block w-full p-2 border border-gray-300 rounded-xl mb-2" required>
            <label>Horario (inicio y fin):</label>
            <div class="flex gap-4">
              <input type="time" name="hora_inicio[]" class="w-1/2 p-2 border border-gray-300 rounded-xl" required>
              <input type="time" name="hora_fin[]" class="w-1/2 p-2 border border-gray-300 rounded-xl" required>
            </div>
          </div>
        `);
      }
    });

    function copiarURL() {
      const input = document.getElementById('urlFormulario');
      const msg   = document.getElementById('mensajeCopiado');
      const texto = input.value;

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(() => {
          msg.classList.remove('hidden');
          setTimeout(() => msg.classList.add('hidden'), 1500);
        }).catch(() => fallbackCopy());
      } else {
        fallbackCopy();
      }

      function fallbackCopy() {
        input.select();
        input.setSelectionRange(0, 99999);
        try {
          document.execCommand('copy');
          msg.classList.remove('hidden');
          setTimeout(() => msg.classList.add('hidden'), 1500);
        } catch (_) {
          msg.textContent = "❌ No se pudo copiar";
          msg.classList.remove('hidden');
        }
      }
    }
    window.copiarURL = copiarURL;
  </script>
</body>
</html>
