<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';
require_once dirname(__DIR__) . '/correo/enviar_correo.php'; // ajusté la ruta a dirname(__DIR__)

// -----------------------------
// 1) Tomar slug
// -----------------------------
$slug = isset($_GET['e']) ? $_GET['e'] : '';
if ($slug === '') {
    http_response_code(400);
    echo 'Falta el parámetro "e" (slug del evento).';
    exit;
}

// -----------------------------
// 2) Consultar evento (SIN get_result)
// -----------------------------
$evento = null;

$stmt = $conn->prepare('SELECT id, nombre, imagen, modalidad, fecha_limite FROM eventos WHERE slug = ? LIMIT 1');
$stmt->bind_param('s', $slug);
$stmt->execute();
$stmt->bind_result($ev_id, $ev_nombre, $ev_imagen, $ev_modalidad, $ev_fecha_limite);
if ($stmt->fetch()) {
    $evento = array(
        'id'            => $ev_id,
        'nombre'        => $ev_nombre,
        'imagen'        => $ev_imagen,
        'modalidad'     => $ev_modalidad,
        'fecha_limite'  => $ev_fecha_limite
    );
}
$stmt->close();

if (!$evento) {
    http_response_code(404);
    echo 'Evento no encontrado para el slug: ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    exit;
}

// -----------------------------
// 3) Preparar utilidades correo (fechas del evento)
// -----------------------------
$fechas = array();
$stmtf = $conn->prepare("SELECT fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC");
$stmtf->bind_param("i", $evento['id']);
$stmtf->execute();
$stmtf->bind_result($f_fecha, $f_hi, $f_hf);
while ($stmtf->fetch()) {
    $fechas[] = array('fecha' => $f_fecha, 'hora_inicio' => $f_hi, 'hora_fin' => $f_hf);
}
$stmtf->close();

function resumirFechas($fechasArr) {
    if (empty($fechasArr)) return '';
    $meses = array('enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre');
    $dias = array();
    for ($i=0; $i<count($fechasArr); $i++) {
        $dias[] = (int)date('j', strtotime($fechasArr[$i]['fecha']));
    }
    $mes  = $meses[(int)date('n', strtotime($fechasArr[0]['fecha'])) - 1];
    $anio = date('Y', strtotime($fechasArr[0]['fecha']));
    if (count($dias) == 1) return $dias[0] . " de $mes de $anio";
    $ultimo = array_pop($dias);
    return implode(', ', $dias) . " y $ultimo de $mes de $anio";
}

function detalleHorarioHtml($fechasArr) {
    if (empty($fechasArr)) return '';
    $meses = array('enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre');
    $html = "<ul style='margin:0;padding-left:18px'>";
    for ($i=0; $i<count($fechasArr); $i++) {
        $f = $fechasArr[$i];
        $d = (int)date('j', strtotime($f['fecha']));
        $m = $meses[(int)date('n', strtotime($f['fecha'])) - 1];
        $y = date('Y', strtotime($f['fecha']));

        // Convierte siempre a timestamp y luego a 12h
        $hi = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_inicio']));
        $hf = date('g:i a', strtotime($f['fecha'] . ' ' . $f['hora_fin']));

        // Reemplaza am/pm por a. m./p. m.
        $hi = str_replace(array('am','pm'), array('a. m.','p. m.'), strtolower($hi));
        $hf = str_replace(array('am','pm'), array('a. m.','p. m.'), strtolower($hf));

        $html .= "<li>Día " . ($i+1) . ": $d de $m de $y — <strong>$hi</strong> a <strong>$hf</strong></li>";
    }
    $html .= "</ul>";
    return $html;
}

$mensaje_exito = false;

// -----------------------------
// 4) Manejo POST (INSERT + correo)
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $evento_id         = isset($_POST["evento_id"]) ? $_POST["evento_id"] : 0;
    $tipo_inscripcion  = isset($_POST["tipo_inscripcion"]) ? $_POST["tipo_inscripcion"] : '';
    $nombre            = isset($_POST["nombre"]) ? $_POST["nombre"] : '';
    $cedula            = isset($_POST["cedula"]) ? $_POST["cedula"] : '';
    $cargo             = isset($_POST["cargo"]) ? $_POST["cargo"] : '';
    $entidad           = isset($_POST["entidad"]) ? $_POST["entidad"] : '';
    $celular           = isset($_POST["celular"]) ? $_POST["celular"] : '';
    $ciudad            = isset($_POST["ciudad"]) ? $_POST["ciudad"] : '';
    $email_personal    = isset($_POST["email_personal"]) ? $_POST["email_personal"] : '';
    $email_corporativo = isset($_POST["email_corporativo"]) ? $_POST["email_corporativo"] : '';
    $medio             = isset($_POST["medio"]) ? $_POST["medio"] : '';

    $stmt = $conn->prepare("INSERT INTO inscritos (evento_id, tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, email_personal, email_corporativo, medio)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssss", $evento_id, $tipo_inscripcion, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad, $email_personal, $email_corporativo, $medio);

    if ($stmt->execute()) {
        // Armar datos de correo
        $resumen_fechas  = resumirFechas($fechas);
        $detalle_horario = detalleHorarioHtml($fechas);

        $es_presencial = (strtolower($evento['modalidad']) === 'presencial');
        $path_pdf = $es_presencial ? (__DIR__ . '/docs/GUIA_HOTELERA_2025_Cafam.pdf') : null;

        $datosCorreo = array(
            'nombre_evento'  => $evento['nombre'],
            'modalidad'      => $evento['modalidad'],
            'fecha_limite'   => $evento['fecha_limite'],
            'resumen_fechas' => $resumen_fechas,
            'detalle_horario'=> $detalle_horario,
            'url_imagen'     => dirname(__DIR__) . '/uploads/eventos/' . $evento['imagen'],
            'adjunto_pdf'    => $path_pdf,
            'lugar'          => $es_presencial ? "Centro de Convenciones Cafam Floresta<br>Av. Cra. 68 No. 90-88, Bogotá - Salón Sauces" : ""
        );

        // Enviar correo (maneja excepciones dentro si aplica)
        $correo = new CorreoDenuncia();
        $correo->sendConfirmacionInscripcion($nombre, $email_corporativo, $datosCorreo);

        $mensaje_exito = true;
    } else {
        // Si falla el insert, muestra error
        echo '<pre>Error al guardar inscripción: ' . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    $stmt->close();
    // Nota: NO cierro $conn aquí para poder seguir renderizando la vista sin problemas.
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
      <img src="<?php echo htmlspecialchars('../uploads/eventos/' . $evento['imagen'], ENT_QUOTES, 'UTF-8'); ?>" alt="Imagen del evento" class="w-full h-60 object-cover rounded-xl mb-6">
      <h1 class="text-2xl font-bold text-[#942934] mb-4 text-center">
        Inscripción al evento: <?php echo htmlspecialchars($evento['nombre'], ENT_QUOTES, 'UTF-8'); ?>
      </h1>

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

      <form method="POST" onsubmit="return validarFormulario()" class="space-y-4">
        <input type="hidden" name="evento_id" value="<?php echo (int)$evento['id']; ?>">

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
  </div>

  <script src="../assets/js/jquery.min.js"></script>
  <script>
    function validarFormulario() {
      var correo = document.querySelector('input[name="email_corporativo"]').value;
      var cedula = document.querySelector('input[name="cedula"]').value;
      var celular = document.querySelector('input[name="celular"]').value;

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
