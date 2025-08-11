<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// si ya está logueado -> dashboard
if (!empty($_SESSION['uid'])) {
    header('Location: dashboard.php'); exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $clave   = isset($_POST['clave']) ? $_POST['clave'] : '';

    $stmt = $conn->prepare("SELECT id, nombre, usuario, email, password_hash, rol, activo 
                            FROM usuarios WHERE usuario = ? LIMIT 1");
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $stmt->bind_result($id, $nombre, $usr, $email, $hash, $rol, $activo);
    if ($stmt->fetch() && $activo == 1 && password_verify($clave, $hash)) {
        $_SESSION['uid']   = $id;
        $_SESSION['nombre']= $nombre;
        $_SESSION['rol']   = $rol;
        $stmt->close(); $conn->close();
        header('Location: dashboard.php'); exit;
    } else {
        $err = 'Usuario o contraseña inválidos.';
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ingresar</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
  <div class="w-full max-w-md bg-white p-8 rounded-2xl shadow-2xl">
    <h1 class="text-2xl font-bold text-[#942934] text-center mb-6">Acceso al panel</h1>

    <?php if ($err): ?>
      <div class="bg-red-100 text-red-800 px-4 py-2 rounded-xl mb-4"><?php echo htmlspecialchars($err,ENT_QUOTES,'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="text" name="usuario" placeholder="Usuario" required class="w-full p-3 border border-gray-300 rounded-xl" />
      <input type="password" name="clave" placeholder="Contraseña" required class="w-full p-3 border border-gray-300 rounded-xl" />
      <button type="submit" class="w-full bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-3 rounded-xl transition-all">Ingresar</button>
    </form>
  </div>
</body>
</html>
