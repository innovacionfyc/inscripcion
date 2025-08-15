<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/_auth.php';
require_login();

require_once dirname(__DIR__) . '/db/conexion.php';
require_once dirname(__DIR__) . '/config/url.php';

$evento_id = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($evento_id <= 0) {
  http_response_code(400);
  echo "Falta evento_id.";
  exit;
}

// Eliminar inscrito (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
  $inscrito_id = isset($_POST['inscrito_id']) ? (int)$_POST['inscrito_id'] : 0;
  if ($inscrito_id > 0) {
    // (Opcional) borrar archivo soporte si quieres
    $stmtS = $conn->prepare("SELECT soporte_pago FROM inscritos WHERE id = ? AND evento_id = ? LIMIT 1");
    $stmtS->bind_param("ii", $inscrito_id, $evento_id);
    if ($stmtS->execute()) {
      $stmtS->bind_result($sopRel);
      if ($stmtS->fetch() && !empty($sopRel)) {
        $abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($sopRel, '/');
        if (is_file($abs)) @unlink($abs);
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

// Info del evento
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

// Inscritos
$inscritos = array();
$stmt = $conn->prepare("SELECT id, tipo_inscripcion, nombre, cedula, cargo, entidad, celular, ciudad, email_personal, email_corporativo, medio, soporte_pago
                        FROM inscritos
                        WHERE evento_id = ?
                        ORDER BY id DESC");
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$stmt->bind_result($id, $tipo, $nombre, $cedula, $cargo, $entidad, $celular, $ciudad, $email_p, $email_c, $medio, $soporte);
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
    'soporte_pago' => $soporte
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
  <div class="max-w-6xl mx-auto px-4 py-6">
    <?php
      $back_to = 'eventos.php';
      $show_back = true;
      include __DIR__ . '/_topbar.php';
    ?>

    <h1 class="text-2xl font-bold text-[#942934] mb-2">👥 Inscritos – <?php echo htmlspecialchars($evento['nombre'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-sm text-gray-600 mb-6">
      Modalidad: <strong><?php echo htmlspecialchars($evento['modalidad'], ENT_QUOTES, 'UTF-8'); ?></strong> •
      Límite: <strong><?php echo htmlspecialchars($evento['limite'], ENT_QUOTES, 'UTF-8'); ?></strong> •
      Comercial: <strong><?php echo htmlspecialchars($evento['comercial'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong>
    </p>

    <div class="bg-white rounded-2xl shadow-2xl p-5 overflow-x-auto">
      <table class="min-w-full text-sm">
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
            <th class="py-2 pr-3">Soporte de pago</th>
            <th class="py-2 pr-3">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($inscritos)): ?>
            <tr><td colspan="11" class="py-4 text-center text-gray-500">Sin inscritos aún</td></tr>
          <?php else: ?>
            <?php $n=1; foreach ($inscritos as $r): ?>
              <tr class="border-b hover:bg-gray-50">
                <td class="py-2 pr-3"><?php echo $n++; ?></td>
                <td class="py-2 pr-3 font-medium"><?php echo htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['cedula'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['entidad'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['cargo'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['ciudad'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['celular'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['email_corporativo'] ?: $r['email_personal'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3"><?php echo htmlspecialchars($r['tipo_inscripcion'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-2 pr-3">
                  <?php if (!empty($r['soporte_pago'])):
                        $url = base_url($r['soporte_pago']);
                        $abs = rtrim($_SERVER['DOCUMENT_ROOT'],'/') . '/' . ltrim($r['soporte_pago'],'/');
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
                <td class="py-2 pr-3">
                  <a href="editar_inscrito.php?id=<?php echo (int)$r['id']; ?>&evento_id=<?php echo $evento_id; ?>"
                     class="inline-block bg-sky-600 hover:bg-sky-700 text-white px-3 py-1 rounded-lg">Editar</a>
                  <form method="POST" class="inline-block ml-2" onsubmit="return confirm('¿Eliminar este inscrito?');">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="inscrito_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-lg">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
