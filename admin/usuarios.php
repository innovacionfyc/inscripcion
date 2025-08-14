<?php
// admin/usuarios.php
require_once __DIR__ . '/_auth.php';
require_role('admin'); // solo administradores
require_once dirname(__DIR__) . '/db/conexion.php';

// Helpers
function limpiar($s){ return trim($s); }
function gen_pass($len = 10){
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$';
    $out=''; for($i=0;$i<$len;$i++){ $out .= $c[rand(0, strlen($c)-1)]; }
    return $out;
}

$msg = ''; $err = '';

// ACCIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = isset($_POST['accion']) ? $_POST['accion'] : '';

    // Crear usuario
    if ($accion === 'crear') {
        $nombre  = limpiar($_POST['nombre']);
        $usuario = limpiar($_POST['usuario']);
        $whatsapp = isset($_POST['whatsapp']) ? preg_replace('/\D+/', '', $_POST['whatsapp']) : null;
        $email   = limpiar($_POST['email']);
        $rol     = isset($_POST['rol']) ? $_POST['rol'] : 'editor';
        $clave   = limpiar($_POST['clave']);
        $clave2  = limpiar($_POST['clave2']);
        $firma_path = null;

        // Validaciones básicas
        if ($nombre==='' || $usuario==='' || $email==='' || $clave==='' ) {
            $err = 'Completa todos los campos.';
        } elseif ($clave !== $clave2) {
            $err = 'Las contraseñas no coinciden.';
        } elseif (!in_array($rol, array('admin','editor'))) {
            $err = 'Rol inválido.';
        } else {
            // Validar y subir firma si se envió
            if (!empty($_FILES['firma']['name'])) {
                $allowed = ['image/png','image/jpeg','image/jpg'];
                if (!in_array($_FILES['firma']['type'], $allowed)) {
                    $err = 'La firma debe ser una imagen PNG o JPG.';
                } elseif ($_FILES['firma']['size'] > 2*1024*1024) {
                    $err = 'La firma no puede superar los 2MB.';
                } else {
                    $ext = pathinfo($_FILES['firma']['name'], PATHINFO_EXTENSION);
                    $new_name = 'firma_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                    $upload_dir = dirname(__DIR__) . '/uploads/firmas/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    if (move_uploaded_file($_FILES['firma']['tmp_name'], $upload_dir . $new_name)) {
                        $firma_path = 'uploads/firmas/' . $new_name;
                    } else {
                        $err = 'Error al subir la firma.';
                    }
                }
            }

            // usuario único
            if ($err==='') {
                $chk = $conn->prepare("SELECT id FROM usuarios WHERE usuario=? LIMIT 1");
                $chk->bind_param('s', $usuario);
                $chk->execute(); $chk->store_result();
                if ($chk->num_rows > 0) {
                    $err = 'Ya existe un usuario con ese nombre.';
                } else {
                    $hash = password_hash($clave, PASSWORD_BCRYPT);
                    $ins  = $conn->prepare("INSERT INTO usuarios (nombre, usuario, email, password_hash, rol, activo, firma_path, whatsapp) 
                                            VALUES (?,?,?,?,?,1,?,?)");
                    $ins->bind_param('sssssss', $nombre, $usuario, $email, $hash, $rol, $firma_path, $whatsapp);
                    if ($ins->execute()) { 
                        $msg = 'Usuario creado correctamente.'; 
                    } else { 
                        $err = 'Error al crear: ' . $conn->error; 
                    }
                    $ins->close();
                }
                $chk->close();
            }
        }
    }

    // Cambiar rol
    if ($accion === 'cambiar_rol') {
        $id  = (int)$_POST['id'];
        $rol = isset($_POST['rol']) ? $_POST['rol'] : 'editor';
        if ($id>0 && in_array($rol, array('admin','editor'))) {
            if ($id == $_SESSION['uid'] && $rol !== 'admin') {
                $rs = $conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='admin' AND activo=1");
                $row = $rs ? $rs->fetch_assoc() : array('c'=>0);
                if ((int)$row['c'] <= 1) { $err = 'No puedes quitarte tu rol de administrador (serías el único).'; }
            }
            if ($err==='') {
                $st = $conn->prepare("UPDATE usuarios SET rol=? WHERE id=?");
                $st->bind_param('si', $rol, $id);
                if ($st->execute()) { $msg='Rol actualizado.'; } else { $err='Error al actualizar rol.'; }
                $st->close();
            }
        }
    }

    // Activar / Desactivar
    if ($accion === 'toggle_activo') {
        $id = (int)$_POST['id'];
        $act = (int)$_POST['valor']; 
        if ($id>0) {
            if ($id == $_SESSION['uid'] && $act == 0) {
                $err = 'No puedes desactivarte a ti mismo.';
            } else {
                if ($act==0) {
                    $q = $conn->prepare("SELECT rol FROM usuarios WHERE id=?");
                    $q->bind_param('i', $id); $q->execute(); $q->bind_result($rrol); $q->fetch(); $q->close();
                    if ($rrol === 'admin') {
                        $rs = $conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='admin' AND activo=1 AND id<>".$id);
                        $row = $rs ? $rs->fetch_assoc() : array('c'=>0);
                        if ((int)$row['c'] <= 0) { $err = 'Debe existir al menos un administrador activo.'; }
                    }
                }
                if ($err==='') {
                    $st = $conn->prepare("UPDATE usuarios SET activo=? WHERE id=?");
                    $st->bind_param('ii', $act, $id);
                    if ($st->execute()) { $msg = $act? 'Usuario activado.' : 'Usuario desactivado.'; } else { $err='Error al actualizar.'; }
                    $st->close();
                }
            }
        }
    }

    // Resetear contraseña
    if ($accion === 'reset_pass') {
        $id = (int)$_POST['id'];
        if ($id>0) {
            $temp = gen_pass(10);
            $hash = password_hash($temp, PASSWORD_BCRYPT);
            $st = $conn->prepare("UPDATE usuarios SET password_hash=? WHERE id=?");
            $st->bind_param('si', $hash, $id);
            if ($st->execute()) { $msg = 'Contraseña temporal: ' . htmlspecialchars($temp, ENT_QUOTES, 'UTF-8'); } else { $err='Error al resetear clave.'; }
            $st->close();
        }
    }

    // Eliminar usuario
    if ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        if ($id>0) {
            if ($id == $_SESSION['uid']) {
                $err = 'No puedes eliminar tu propio usuario.';
            } else {
                $q = $conn->prepare("SELECT rol FROM usuarios WHERE id=?");
                $q->bind_param('i', $id); $q->execute(); $q->bind_result($rrol); $q->fetch(); $q->close();
                if ($rrol === 'admin') {
                    $rs = $conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='admin' AND id<>".$id);
                    $row = $rs ? $rs->fetch_assoc() : array('c'=>0);
                    if ((int)$row['c'] <= 0) { $err = 'Debe existir al menos un administrador.'; }
                }
                if ($err==='') {
                    $st = $conn->prepare("DELETE FROM usuarios WHERE id=?");
                    $st->bind_param('i', $id);
                    if ($st->execute()) { $msg='Usuario eliminado.'; } else { $err='Error al eliminar.'; }
                    $st->close();
                }
            }
        }
    }
}

// LISTADO
$lista = array();
$rs = $conn->query("SELECT id, nombre, usuario, email, rol, activo, created_at FROM usuarios ORDER BY id DESC");
if ($rs) { while ($row = $rs->fetch_assoc()) { $lista[] = $row; } }
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Usuarios</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen">
<?php
$back_to = 'dashboard.php';
$show_back = true;
include __DIR__ . '/_topbar.php';
?>

<div class="max-w-5xl mx-auto p-6">

  <h1 class="text-2xl font-bold text-[#942934] mb-4">👥 Gestión de usuarios</h1>

  <?php if ($msg): ?>
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded-xl mb-4"><?php echo $msg; ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="bg-red-100 text-red-800 px-4 py-2 rounded-xl mb-4"><?php echo $err; ?></div>
  <?php endif; ?>

  <!-- Crear usuario -->
  <div class="bg-white rounded-2xl shadow p-6 border mb-6">
    <h2 class="font-bold text-[#d32f57] mb-3">➕ Crear usuario</h2>
    <form method="POST" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-4">
      <input type="hidden" name="accion" value="crear">
      <input type="text"   name="nombre"  placeholder="Nombre completo" required class="p-3 border border-gray-300 rounded-xl">
      <input type="text"   name="usuario" placeholder="Usuario" required class="p-3 border border-gray-300 rounded-xl">
      <input type="email"  name="email"   placeholder="Email" required class="p-3 border border-gray-300 rounded-xl">
      <input type="text"  name="whatsapp" placeholder="WhatsApp (ej: 573001234567)" class="p-3 border border-gray-300 rounded-xl">
      <select name="rol" class="p-3 border border-gray-300 rounded-xl" required>
        <option value="editor">Editor</option>
        <option value="admin">Administrador</option>
      </select>
      <input type="password" name="clave"  placeholder="Contraseña" required class="p-3 border border-gray-300 rounded-xl">
      <input type="password" name="clave2" placeholder="Repite contraseña" required class="p-3 border border-gray-300 rounded-xl">
      <div class="md:col-span-2">
        <label class="block mb-2 text-gray-700 font-medium">Firma (PNG/JPG, máx 2MB):</label>
        <input type="file" name="firma" accept="image/png, image/jpeg" class="p-2 border border-gray-300 rounded-xl w-full">
      </div>
      <div class="md:col-span-2">
        <button type="submit" class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold px-5 py-3 rounded-xl transition-all">
          Crear usuario
        </button>
      </div>
    </form>
  </div>

  <!-- Lista de usuarios -->
  <div class="bg-white rounded-2xl shadow p-6 border">
    <h2 class="font-bold text-[#d32f57] mb-3">Listado</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="bg-gray-100 text-gray-700">
            <th class="text-left p-3">ID</th>
            <th class="text-left p-3">Nombre</th>
            <th class="text-left p-3">Usuario</th>
            <th class="text-left p-3">Email</th>
            <th class="text-left p-3">Rol</th>
            <th class="text-left p-3">Estado</th>
            <th class="text-left p-3">Creado</th>
            <th class="text-left p-3">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lista)): ?>
            <tr><td colspan="8" class="p-4 text-center text-gray-500">No hay usuarios.</td></tr>
          <?php else: foreach ($lista as $u): ?>
            <tr class="border-b">
              <td class="p-3"><?php echo (int)$u['id']; ?></td>
              <td class="p-3"><?php echo htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="p-3"><?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="p-3"><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="p-3">
                <form method="POST" class="flex items-center gap-2">
                  <input type="hidden" name="accion" value="cambiar_rol">
                  <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                  <select name="rol" class="p-2 border border-gray-300 rounded-lg">
                    <option value="editor" <?php echo ($u['rol']=='editor'?'selected':''); ?>>Editor</option>
                    <option value="admin"  <?php echo ($u['rol']=='admin'?'selected':''); ?>>Administrador</option>
                  </select>
                  <button class="text-white bg-emerald-600 hover:bg-emerald-700 px-3 py-1.5 rounded-lg">Guardar</button>
                </form>
              </td>
              <td class="p-3">
                <form method="POST" class="inline">
                  <input type="hidden" name="accion" value="toggle_activo">
                  <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                  <input type="hidden" name="valor" value="<?php echo ($u['activo']?0:1); ?>">
                  <?php if ($u['activo']): ?>
                    <button class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1.5 rounded-lg">Desactivar</button>
                  <?php else: ?>
                    <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg">Activar</button>
                  <?php endif; ?>
                </form>
              </td>
              <td class="p-3"><?php echo htmlspecialchars($u['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="p-3">
                <div class="flex flex-wrap gap-2">
                  <form method="POST" class="inline" onsubmit="return confirm('¿Resetear contraseña?');">
                    <input type="hidden" name="accion" value="reset_pass">
                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                    <button class="bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg">Reset clave</button>
                  </form>
                  <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar usuario?');">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                    <button class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg">Eliminar</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
