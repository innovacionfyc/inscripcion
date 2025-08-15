<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/_auth.php';
require_login();

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$evento_id = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($id <= 0 || $evento_id <= 0) {
  http_response_code(400);
  echo "Parámetros inválidos.";
  exit;
}

// Cargar inscrito
$stmt = $conn->prepare("SELECT id, evento_id, tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, email_personal, email_corporativo, medio, soporte_pago
                        FROM inscritos WHERE id = ? AND evento_id = ? LIMIT 1");
$stmt->bind_param("ii", $id, $evento_id);
$stmt->execute();
$stmt->bind_result($rid, $revento, $rtipo, $rnombre, $rcedula, $rcargo, $rentidad, $rcelular, $rciudad, $remailp, $remailc, $rmedio, $rsoporte);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo "Inscrito no encontrado.";
  exit;
}
$stmt->close();

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tipo_inscripcion  = $_POST['tipo_inscripcion'] ?? $rtipo;
  $nombre            = $_POST['nombre'] ?? $rnombre;
  $cedula            = $_POST['cedula'] ?? $rcedula;
  $cargo             = $_POST['cargo'] ?? $rcargo;
  $entidad           = $_POST['entidad'] ?? $rentidad;
  $celular           = $_POST['celular'] ?? $rcelular;
  $ciudad            = $_POST['ciudad'] ?? $rciudad;
  $email_personal    = $_POST['email_personal'] ?? $remailp;
  $email_corporativo = $_POST['email_corporativo'] ?? $remailc;
  $medio             = $_POST['medio'] ?? $rmedio;

  // Reemplazar soporte (opcional)
  $nuevo_soporte = $rsoporte;
  if (isset($_FILES['soporte_pago']) && is_array($_FILES['soporte_pago']) && $_FILES['soporte_pago']['error'] === 0) {
    $maxBytes = 10 * 1024 * 1024;
    $tmpName  = $_FILES['soporte_pago']['tmp_name'];
    $origName = $_FILES['soporte_pago']['name'];
    $size     = (int)$_FILES['soporte_pago']['size'];

    if ($size <= $maxBytes && is_uploaded_file($tmpName)) {
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      $permitidas = array('pdf','jpg','jpeg','png','gif','webp');
      if (in_array($ext, $permitidas)) {
        $destDir = dirname(__DIR__) . '/uploads/soportes/';
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
        $nombreSeguro = 'soporte_' . date('Ymd_His') . '_' . mt_rand(1000,9999) . '.' . $ext;
        if (@move_uploaded_file($tmpName, $destDir . $nombreSeguro)) {
          // eliminar anterior si existía
          if (!empty($rsoporte)) {
            $oldAbs = rtrim($_SERVER['DOCUMENT_ROOT'],'/') . '/' . ltrim($rsoporte,'/');
            if (is_file($oldAbs)) @unlink($oldAbs);
          }
          $nuevo_soporte = 'uploads/soportes/' . $nombreSeguro;
        }
      }
    }
  }

  $stmtU = $conn->prepare("UPDATE inscritos
                           SET tipo_inscripcion=?, nombre=?, cedula=?, cargo=?, entidad=?, celular=?, ciudad=?,
                               email_personal=?, email_corporativo=?, medio=?, soporte_pago=?
                           WHERE id=? AND evento_id=? LIMIT 1");
  $stmtU->bind_param("ssssssssssssi",
    $tipo_inscripcion, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad,
    $email_personal, $email_corporativo, $medio, $nuevo_soporte, $id, $evento_id
  );
  if ($stmtU->execute()) {
    $mensaje = 'Actualizado correctamente.';
    // refrescar datos base para el formulario
    $rsoporte = $nuevo_soporte;
    $rtipo = $tipo_inscripcion; $rnombre = $nombre; $rcedula = $cedula; $rcargo = $cargo;
    $rentidad = $entidad; $rcelular = $celular; $rciudad = $ciudad; $remailp = $email_personal; $remailc = $email_corporativo; $rmedio = $medio;
  } else {
    $mensaje = 'Error al actualizar: ' . $stmtU->error;
  }
  $stmtU->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar inscrito</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="max-w-3xl mx-auto px-4 py-6">
    <?php
      $back_to = 'inscritos.php?evento_id=' . $evento_id;
      $show_back = true;
      include __DIR__ . '/_topbar.php';
    ?>

    <h1 class="text-2xl font-bold text-[#942934] mb-4">✏️ Editar inscrito</h1>

    <?php if (!empty($mensaje)): ?>
      <div class="mb-4 px-4 py-3 rounded-xl <?php echo (strpos($mensaje,'Error')===false?'bg-green-100 text-green-800':'bg-red-100 text-red-800'); ?>">
        <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl shadow-2xl p-6 space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="font-semibold">Tipo inscripción</label>
          <select name="tipo_inscripcion" class="w-full p-3 border border-gray-300 rounded-xl">
            <option value="EMPRESA" <?php echo ($rtipo==='EMPRESA'?'selected':''); ?>>Empresa</option>
            <option value="PERSONA NATURAL" <?php echo ($rtipo==='PERSONA NATURAL'?'selected':''); ?>>Persona Natural</option>
          </select>
        </div>
        <div>
          <label class="font-semibold">Nombre</label>
          <input type="text" name="nombre" value="<?php echo htmlspecialchars($rnombre, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Cédula</label>
          <input type="text" name="cedula" value="<?php echo htmlspecialchars($rcedula, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Cargo</label>
          <input type="text" name="cargo" value="<?php echo htmlspecialchars($rcargo, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Entidad</label>
          <input type="text" name="entidad" value="<?php echo htmlspecialchars($rentidad, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Celular</label>
          <input type="text" name="celular" value="<?php echo htmlspecialchars($rcelular, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Ciudad</label>
          <input type="text" name="ciudad" value="<?php echo htmlspecialchars($rciudad, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Email personal</label>
          <input type="email" name="email_personal" value="<?php echo htmlspecialchars($remailp, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
        </div>
        <div>
          <label class="font-semibold">Email corporativo</label>
          <input type="email" name="email_corporativo" value="<?php echo htmlspecialchars($remailc, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl" required>
        </div>
        <div>
          <label class="font-semibold">Medio</label>
          <input type="text" name="medio" value="<?php echo htmlspecialchars($rmedio, ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-3 border border-gray-300 rounded-xl">
        </div>
      </div>

      <div>
        <label class="font-semibold">Soporte de pago (opcional – reemplaza el existente)</label>
        <input type="file" name="soporte_pago" accept=".pdf,image/*" class="w-full p-3 border border-gray-300 rounded-xl">
        <?php if (!empty($rsoporte)): ?>
          <p class="text-sm text-gray-600 mt-2">
            Actual: <a class="text-sky-700 underline" href="<?php echo htmlspecialchars(base_url($rsoporte), ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Ver soporte</a>
          </p>
        <?php else: ?>
          <p class="text-sm text-gray-500 mt-2">Sin soporte actual.</p>
        <?php endif; ?>
      </div>

      <div class="text-right">
        <button type="submit" class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-6 rounded-xl">Guardar cambios</button>
      </div>
    </form>
  </div>
</body>
</html>
