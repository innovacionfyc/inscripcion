<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';
require_once __DIR__ . '/../correo/enviar_correo.php';

// 1) Tomar el slug (?e=...)
$slug = isset($_GET['e']) ? $_GET['e'] : '';
if ($slug === '') {
    http_response_code(400);
    echo 'Falta el parámetro "e" (slug del evento).';
    exit;
}

// 2) Consultar el evento
$stmt = $conn->prepare('SELECT id, nombre, imagen, modalidad, fecha_limite FROM eventos WHERE slug = ? LIMIT 1');
$stmt->bind_param('s', $slug);
$stmt->execute();
$res = $stmt->get_result();
$evento = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$evento) {
    http_response_code(404);
    echo 'Evento no encontrado para el slug: ' . htmlspecialchars($slug);
    exit;
}

// 3) Rutas útiles
$imgEventoRel = 'uploads/eventos/' . $evento['imagen']; // para <img src="<?= base_url($imgEventoRel) >
$imgEventoFs   = dirname(__DIR__) . '/uploads/eventos/' . $evento['imagen']; // para file_exists si quieres verificar



$evento = null;
$slug = isset($_GET['e']) ? $_GET['e'] : '';

if ($slug) {
    $stmt = $conn->prepare("SELECT * FROM eventos WHERE slug = ?");
    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $evento = $resultado->fetch_assoc();
    }
}

// Manejo de envío POST
$mensaje_exito = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $evento_id         = $_POST["evento_id"];
    $tipo_inscripcion  = $_POST["tipo_inscripcion"];
    $nombre            = $_POST["nombre"];
    $cedula            = $_POST["cedula"];
    $cargo             = $_POST["cargo"];
    $entidad           = $_POST["entidad"];
    $celular           = $_POST["celular"];
    $ciudad            = $_POST["ciudad"];
    $email_personal    = $_POST["email_personal"];
    $email_corporativo = $_POST["email_corporativo"];
    $medio             = $_POST["medio"];

    // Guardar en la base de datos
    $stmt = $conn->prepare("INSERT INTO inscritos (evento_id, tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, email_personal, email_corporativo, medio)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssss", $evento_id, $tipo_inscripcion, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad, $email_personal, $email_corporativo, $medio);
    
      // Traer fechas del evento para armar el resumen y el horario
      $fechas_stmt = $conn->prepare("SELECT fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC");
      $fechas_stmt->bind_param("i", $evento['id']);
      $fechas_stmt->execute();
      $res = $fechas_stmt->get_result();

      $fechas = [];
      while ($row = $res->fetch_assoc()) { $fechas[] = $row; }
      $fechas_stmt->close();

      // Resumen tipo: 4, 5 y 6 de septiembre de 2025
      function resumirFechas($fechasArr) {
          if (empty($fechasArr)) return '';
          $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
          $dias = array_map(function($f){ return (int)date('j', strtotime($f['fecha'])); }, $fechasArr);
          $mes  = $meses[(int)date('n', strtotime($fechasArr[0]['fecha'])) - 1];
          $anio = date('Y', strtotime($fechasArr[0]['fecha']));
          if (count($dias) == 1) return "{$dias[0]} de $mes de $anio";
          $ultimo = array_pop($dias);
          return implode(', ', $dias) . " y $ultimo de $mes de $anio";
      }

      // Detalle horario HTML
      function detalleHorarioHtml($fechasArr) {
          if (empty($fechasArr)) return '';
          $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
          $html = "<ul style='margin:0;padding-left:18px'>";
          foreach ($fechasArr as $idx => $f) {
              $d = (int)date('j', strtotime($f['fecha']));
              $m = $meses[(int)date('n', strtotime($f['fecha'])) - 1];
              $y = date('Y', strtotime($f['fecha']));
              $hi = substr($f['hora_inicio'], 0, 5);
              $hf = substr($f['hora_fin'], 0, 5);
              $html .= "<li>Día " . ($idx+1) . ": $d de $m de $y — $hi a $hf</li>";
          }
          $html .= "</ul>";
          return $html;
      }

      $resumen_fechas  = resumirFechas($fechas);
      $detalle_horario = detalleHorarioHtml($fechas);

      // Construir datos para el correo
      $es_presencial = strtolower($evento['modalidad']) === 'presencial';
      $path_pdf = $es_presencial ? (__DIR__ . '/../docs/GUIA HOTELERA 2025 - Cafam.pdf') : null;

      $datosCorreo = [
          'nombre_evento'  => $evento['nombre'],
          'modalidad'      => $evento['modalidad'],
          'fecha_limite'   => $evento['fecha_limite'],
          'resumen_fechas' => $resumen_fechas,
          'detalle_horario'=> $detalle_horario,
          'url_imagen'     => __DIR__ . '/../uploads/eventos/' . $evento['imagen'],
          'adjunto_pdf'    => $path_pdf,
          'lugar'          => $es_presencial ? "Centro de Convenciones Cafam Floresta<br>Av. Cra. 68 No. 90-88, Bogotá - Salón Sauces" : ""
      ];

      // Enviar
      $correo = new CorreoDenuncia();
      $correo->sendConfirmacionInscripcion($nombre, $email_corporativo, $datosCorreo);

      $mensaje_exito = true;
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Formulario de Inscripción</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl p-8 mt-6">

    <?php if ($evento): ?>
      <img src="../uploads/eventos/<?= htmlspecialchars($evento['imagen']) ?>" alt="Imagen del evento" class="w-full h-60 object-cover rounded-xl mb-6">
      <h1 class="text-2xl font-bold text-[#942934] mb-4 text-center">Inscripción al evento: <?= htmlspecialchars($evento['nombre']) ?></h1>

      <form method="POST" onsubmit="return validarFormulario()" class="space-y-4">
        <input type="hidden" name="evento_id" value="<?= $evento['id'] ?>">

        <div class="flex gap-4">
          <label class="flex items-center gap-2">
            <input type="radio" name="tipo_inscripcion" value="EMPRESA" required class="accent-[#942934]"> Empresa
          </label>
          <label class="flex items-center gap-2">
            <input type="radio" name="tipo_inscripcion" value="PERSONA NATURAL" required class="accent-[#942934]"> Persona Natural
          </label>
        </div>

        <input type="text" name="nombre" placeholder="Nombre completo" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="text" name="cedula" placeholder="Cédula" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="text" name="cargo" placeholder="Cargo" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="text" name="entidad" placeholder="Entidad o Empresa" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="text" name="celular" placeholder="Celular" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="text" name="ciudad" placeholder="Ciudad" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="email" name="email_personal" placeholder="Email Personal (opcional)" class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="email" name="email_corporativo" placeholder="Email Corporativo" required class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />
        <input type="text" name="medio" placeholder="¿Por qué medio se enteró?" class="w-full p-3 border border-gray-300 rounded-xl placeholder:text-gray-500" />

        <button type="submit" class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-6 rounded-xl w-full transition-all duration-300 hover:scale-[1.01] active:scale-[0.98]">
          Enviar inscripción
        </button>
      </form>
    <?php else: ?>
      <p class="text-red-600 text-center text-lg font-bold">⚠️ Evento no encontrado</p>
    <?php endif; ?>
<!-- Modal de gracias -->
    <?php if ($mensaje_exito): ?>
      <div id="modalGracias" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-2xl shadow-2xl text-center max-w-md">
          <h2 class="text-xl font-bold text-[#942934] mb-4">🎉 ¡Inscripción exitosa!</h2>
          <p class="text-gray-700 mb-4">Gracias por registrarte. Hemos enviado un correo de confirmación a tu email corporativo.</p>
          <button onclick="document.getElementById('modalGracias').remove()" class="bg-[#d32f57] text-white px-6 py-2 rounded-xl hover:bg-[#942934] transition-all">
            Cerrar
          </button>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="../assets/js/jquery.min.js"></script>
  <script>
    function validarFormulario() {
      const correo = document.querySelector('input[name="email_corporativo"]').value;
      const cedula = document.querySelector('input[name="cedula"]').value;
      const celular = document.querySelector('input[name="celular"]').value;

      if (!/^\d+$/.test(cedula)) {
        alert("La cédula debe contener solo números.");
        return false;
      }

      if (!/^\d{7,15}$/.test(celular)) {
        alert("El celular debe contener entre 7 y 15 dígitos.");
        return false;
      }

      if (!/\S+@\S+\.\S+/.test(correo)) {
        alert("Correo corporativo inválido.");
        return false;
      }

      return true;
    }
  </script>
</body>
</html>
