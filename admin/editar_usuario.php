<?php
// admin/editar_usuario.php — Edición completa de usuarios (Fase 1)
require_once __DIR__ . '/_auth.php';
require_role('admin'); // solo administradores
require_once dirname(__DIR__) . '/db/conexion.php';
require_once __DIR__ . '/helpers/audit.php';

if (!function_exists('hx')) {
  function hx($s)
  {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
  }
}

/* ============================================================
   Reglas Fase 1:
   - `usuario` (login) es SOLO LECTURA.
   - No se edita password_hash, created_at ni last_login_*.
   - Firma: si no se sube nueva, se conserva firma_path actual;
     si se sube, se guarda archivo nuevo y se actualiza el campo.
     La firma anterior NO se borra (fase 1).
   - correo/enviar_correo.php ya consume usuarios.firma_path:
     no requiere cambios allí.
   ============================================================ */

function eu_cargar_usuario($conn, $id)
{
  $u = null;
  $stmt = $conn->prepare("SELECT id, nombre, usuario, email, rol, activo, whatsapp, firma_path
                          FROM usuarios WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->bind_result($r_id, $r_nombre, $r_usuario, $r_email, $r_rol, $r_activo, $r_wa, $r_firma);
  if ($stmt->fetch()) {
    $u = array(
      'id' => (int) $r_id,
      'nombre' => $r_nombre,
      'usuario' => $r_usuario,
      'email' => $r_email,
      'rol' => $r_rol,
      'activo' => (int) $r_activo,
      'whatsapp' => $r_wa,
      'firma_path' => $r_firma
    );
  }
  $stmt->close();
  return $u;
}

// Cuenta admins activos DISTINTOS de $excluir_id
function eu_otros_admins_activos($conn, $excluir_id)
{
  $n = 0;
  $stmt = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?");
  $stmt->bind_param('i', $excluir_id);
  $stmt->execute();
  $stmt->bind_result($n);
  $stmt->fetch();
  $stmt->close();
  return (int) $n;
}

// -----------------------------------------------------------
// Resolver usuario
// -----------------------------------------------------------
$usuario_id = 0;
if (isset($_POST['id'])) {
  $usuario_id = (int) $_POST['id'];
} elseif (isset($_GET['id'])) {
  $usuario_id = (int) $_GET['id'];
}

$usuario = ($usuario_id > 0) ? eu_cargar_usuario($conn, $usuario_id) : null;

if (!$usuario) {
  http_response_code(404);
  echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Usuario no encontrado</title>';
  echo '<link rel="stylesheet" href="../assets/css/output.css"></head>';
  echo '<body class="bg-gray-100 min-h-screen flex items-center justify-center">';
  echo '<div class="bg-white rounded-2xl shadow-2xl p-8 text-center">';
  echo '<p class="text-red-600 font-bold text-lg">⚠️ Usuario no encontrado.</p>';
  echo '<a href="usuarios.php" class="inline-block mt-4 bg-[#d32f57] hover:bg-[#942934] text-white font-bold px-6 py-3 rounded-xl">Volver a usuarios</a>';
  echo '</div></body></html>';
  exit;
}

$msg = '';
$err = '';

// Valores del formulario (por defecto: BD; en POST con error se conservan los enviados)
$form = array(
  'nombre' => $usuario['nombre'],
  'email' => $usuario['email'],
  'whatsapp' => $usuario['whatsapp'],
  'rol' => $usuario['rol'],
  'activo' => $usuario['activo']
);

// -----------------------------------------------------------
// POST: guardar cambios
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $whatsapp = isset($_POST['whatsapp']) ? preg_replace('/\D+/', '', $_POST['whatsapp']) : '';
  if ($whatsapp === '')
    $whatsapp = null;
  $rol = $_POST['rol'] ?? 'editor';
  $activo = (isset($_POST['activo']) && (int) $_POST['activo'] === 1) ? 1 : 0;

  // Conservar para re-render en caso de error
  $form['nombre'] = $nombre;
  $form['email'] = $email;
  $form['whatsapp'] = $whatsapp;
  $form['rol'] = $rol;
  $form['activo'] = $activo;

  // --- Validaciones básicas ---
  if ($nombre === '') {
    $err = 'El nombre es obligatorio.';
  } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'El email es obligatorio y debe ser válido.';
  } elseif (!in_array($rol, array('admin', 'editor'), true)) {
    $err = 'Rol inválido.';
  }

  // --- Email duplicado (otro usuario con el mismo email) ---
  if ($err === '') {
    $chk = $conn->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1");
    $chk->bind_param('si', $email, $usuario_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
      $err = 'Ya existe otro usuario con ese email.';
    }
    $chk->close();
  }

  // --- Protecciones de auto-gestión y último admin ---
  if ($err === '') {
    // No autodesactivarse
    if ($usuario_id === (int) $_SESSION['uid'] && $activo === 0) {
      $err = 'No puedes desactivar tu propio usuario.';
    }
  }

  if ($err === '') {
    // Si tras el cambio este usuario NO queda como admin activo,
    // debe existir al menos otro admin activo.
    $otros_admins = eu_otros_admins_activos($conn, $usuario_id);
    $quedara_admin_activo = ($rol === 'admin' && $activo === 1);
    if ($otros_admins === 0 && !$quedara_admin_activo) {
      // Mensaje específico según el caso
      $era_admin_activo = ($usuario['rol'] === 'admin' && $usuario['activo'] === 1);
      if ($era_admin_activo && $rol !== 'admin') {
        $err = 'No puedes quitar el rol de administrador: debe quedar al menos un administrador activo.';
      } elseif ($era_admin_activo && $activo === 0) {
        $err = 'No puedes desactivar a este usuario: debe quedar al menos un administrador activo.';
      } else {
        $err = 'Debe existir al menos un administrador activo.';
      }
    }
  }

  // --- Firma (opcional): conservar la actual si no se sube nueva ---
  $firma_nueva = null; // ruta relativa si se subió
  if ($err === '' && !empty($_FILES['firma']['name']) && $_FILES['firma']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['firma']['error'] !== UPLOAD_ERR_OK) {
      $err = 'Error al subir la firma.';
    } elseif (!is_uploaded_file($_FILES['firma']['tmp_name'])) {
      $err = 'Archivo de firma inválido.';
    } elseif ((int) $_FILES['firma']['size'] > 2 * 1024 * 1024) {
      $err = 'La firma no puede superar los 2MB.';
    } else {
      $ext = strtolower(pathinfo($_FILES['firma']['name'], PATHINFO_EXTENSION));
      $ext_ok = array('png', 'jpg', 'jpeg', 'webp');
      $mime_ok = array('image/png', 'image/jpeg', 'image/jpg', 'image/webp');
      $mime_real = function_exists('mime_content_type') ? mime_content_type($_FILES['firma']['tmp_name']) : null;

      if (!in_array($ext, $ext_ok, true)) {
        $err = 'La firma debe ser PNG, JPG, JPEG o WEBP.';
      } elseif ($mime_real !== null && !in_array($mime_real, $mime_ok, true)) {
        $err = 'El contenido de la firma no es una imagen válida (PNG, JPG o WEBP).';
      } else {
        $upload_dir = dirname(__DIR__) . '/uploads/firmas/';
        if (!is_dir($upload_dir)) {
          @mkdir($upload_dir, 0775, true);
        }
        $new_name = 'firma_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        if (@move_uploaded_file($_FILES['firma']['tmp_name'], $upload_dir . $new_name)) {
          $firma_nueva = 'uploads/firmas/' . $new_name;
        } else {
          $err = 'No se pudo guardar la firma.';
        }
      }
    }
  }

  // --- Aplicar UPDATE ---
  if ($err === '') {
    if ($firma_nueva !== null) {
      // Incluye firma_path (NO toca usuario, password_hash, created_at, last_login_*)
      $stmt = $conn->prepare("UPDATE usuarios
                              SET nombre = ?, email = ?, whatsapp = ?, rol = ?, activo = ?, firma_path = ?
                              WHERE id = ? LIMIT 1");
      $stmt->bind_param('ssssisi', $nombre, $email, $whatsapp, $rol, $activo, $firma_nueva, $usuario_id);
    } else {
      // Conserva firma_path actual: no se incluye en el UPDATE
      $stmt = $conn->prepare("UPDATE usuarios
                              SET nombre = ?, email = ?, whatsapp = ?, rol = ?, activo = ?
                              WHERE id = ? LIMIT 1");
      $stmt->bind_param('ssssii', $nombre, $email, $whatsapp, $rol, $activo, $usuario_id);
    }

    if ($stmt->execute()) {
      $stmt->close();

      if (function_exists('log_activity')) {
        log_activity($conn, 'editar_usuario', 'usuario', (string) $usuario_id, array(
          'rol' => $rol,
          'activo' => $activo,
          'firma_actualizada' => ($firma_nueva !== null) ? 1 : 0
        ));
      }

      $msg = 'Usuario actualizado correctamente.';
      // Recargar datos frescos para mostrar el estado guardado
      $usuario = eu_cargar_usuario($conn, $usuario_id);
      $form = array(
        'nombre' => $usuario['nombre'],
        'email' => $usuario['email'],
        'whatsapp' => $usuario['whatsapp'],
        'rol' => $usuario['rol'],
        'activo' => $usuario['activo']
      );
    } else {
      $err = 'Error al actualizar el usuario: ' . $conn->error;
      $stmt->close();
    }
  }
}

$firmaActualUrl = '';
if (!empty($usuario['firma_path'])) {
  // firma_path es relativa a la raíz del proyecto (uploads/firmas/...)
  $firmaAbs = dirname(__DIR__) . '/' . ltrim($usuario['firma_path'], '/');
  if (is_file($firmaAbs)) {
    $firmaActualUrl = '../' . ltrim($usuario['firma_path'], '/');
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Editar usuario</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl p-8 mt-10 mb-10 space-y-6">
    <?php
    $back_to = 'usuarios.php';
    $show_back = true;
    include __DIR__ . '/_topbar.php';
    ?>
    <h1 class="text-2xl font-bold text-[#942934] text-center mb-2">✏️ Editar usuario</h1>

    <?php if ($msg): ?>
      <div class="bg-green-100 text-green-800 font-medium px-6 py-4 rounded-xl"><?php echo hx($msg); ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="bg-red-100 text-red-800 font-medium px-6 py-4 rounded-xl"><?php echo hx($err); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-5">
      <input type="hidden" name="id" value="<?php echo (int) $usuario['id']; ?>">

      <div>
        <label class="font-semibold text-gray-700 block mb-1">Usuario (login, no editable):</label>
        <input type="text" value="<?php echo hx($usuario['usuario']); ?>" readonly
          class="w-full p-3 bg-gray-100 border border-gray-300 rounded-xl text-gray-600 cursor-not-allowed">
      </div>

      <div>
        <label class="font-semibold text-gray-700 block mb-1">Nombre completo:</label>
        <input type="text" name="nombre" required value="<?php echo hx($form['nombre']); ?>"
          class="w-full p-3 border border-gray-300 rounded-xl">
      </div>

      <div>
        <label class="font-semibold text-gray-700 block mb-1">Email:</label>
        <input type="email" name="email" required value="<?php echo hx($form['email']); ?>"
          class="w-full p-3 border border-gray-300 rounded-xl">
      </div>

      <div>
        <label class="font-semibold text-gray-700 block mb-1">WhatsApp:</label>
        <input type="text" name="whatsapp" value="<?php echo hx((string) $form['whatsapp']); ?>"
          placeholder="Ej: 573001234567" class="w-full p-3 border border-gray-300 rounded-xl">
        <small class="text-gray-500">Solo dígitos. Se usa en el botón de WhatsApp de los correos.</small>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="font-semibold text-gray-700 block mb-1">Rol:</label>
          <select name="rol" class="w-full p-3 border border-gray-300 rounded-xl">
            <option value="editor" <?php echo ($form['rol'] === 'editor') ? 'selected' : ''; ?>>Editor</option>
            <option value="admin" <?php echo ($form['rol'] === 'admin') ? 'selected' : ''; ?>>Administrador</option>
          </select>
        </div>
        <div>
          <label class="font-semibold text-gray-700 block mb-1">Estado:</label>
          <select name="activo" class="w-full p-3 border border-gray-300 rounded-xl">
            <option value="1" <?php echo ((int) $form['activo'] === 1) ? 'selected' : ''; ?>>Activo</option>
            <option value="0" <?php echo ((int) $form['activo'] === 0) ? 'selected' : ''; ?>>Inactivo</option>
          </select>
        </div>
      </div>

      <div class="p-4 border border-gray-200 rounded-xl">
        <label class="font-semibold text-gray-700 block mb-2">Firma:</label>
        <?php if ($firmaActualUrl !== ''): ?>
          <div class="mb-3">
            <div class="text-sm text-gray-600 mb-1">Firma actual:</div>
            <img src="<?php echo hx($firmaActualUrl); ?>" alt="Firma actual"
              class="max-w-xs border border-gray-200 rounded-xl bg-white p-2">
          </div>
        <?php else: ?>
          <div class="text-sm text-gray-500 mb-2">Este usuario no tiene firma cargada.</div>
        <?php endif; ?>
        <input type="file" name="firma" accept="image/png, image/jpeg, image/webp"
          class="w-full p-2 border border-gray-300 rounded-xl">
        <small class="text-gray-500">Opcional. PNG, JPG, JPEG o WEBP, máx 2MB. Si no subes una nueva, se conserva la actual.</small>
      </div>

      <div class="flex items-center justify-between gap-3">
        <a href="usuarios.php" class="text-gray-600 hover:text-gray-800 font-medium">← Cancelar</a>
        <button type="submit"
          class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 px-8 rounded-xl transition-all">
          Guardar cambios
        </button>
      </div>
    </form>
  </div>
</body>

</html>
<?php $conn->close(); ?>
