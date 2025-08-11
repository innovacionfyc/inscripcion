<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// si ya está logueado -> dashboard
if (!empty($_SESSION['uid'])) {
    header('Location: dashboard.php'); exit;
}

// ===== DEBUG OPCIONAL =====
// navega a /admin/login.php?debug=1 para ver chequeos
if (isset($_GET['debug'])) {
    echo "<pre>";
    echo "PHP OK\n";
    echo "MySQL host: " . $conn->host_info . "\n";

    // ¿existe la tabla usuarios?
    $rs = $conn->query("SHOW TABLES LIKE 'usuarios'");
    echo $rs && $rs->num_rows ? "Tabla usuarios: OK\n" : "Tabla usuarios: NO ENCONTRADA\n";

    // ¿hay al menos 1 usuario activo?
    $rs2 = $conn->query("SELECT id, usuario, rol, activo FROM usuarios LIMIT 3");
    if ($rs2) {
        echo "Usuarios (sample):\n";
        while ($row = $rs2->fetch_assoc()) {
            echo " - id={$row['id']} usuario={$row['usuario']} rol={$row['rol']} activo={$row['activo']}\n";
        }
    } else {
        echo "Error consultando usuarios: " . $conn->error . "\n";
    }
    echo "</pre>";
    exit;
}
// ==========================

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $clave   = isset($_POST['clave']) ? $_POST['clave'] : '';

    if ($usuario === '' || $clave === '') {
        $err = 'Completa usuario y contraseña.';
    } else {
        $sql = "SELECT id, nombre, usuario, email, password_hash, rol, activo 
                FROM usuarios WHERE usuario = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $err = "Error preparando consulta: " . $conn->error;
        } else {
            $stmt->bind_param('s', $usuario);
            if (!$stmt->execute()) {
                $err = "Error ejecutando consulta: " . $stmt->error;
            } else {
                $stmt->bind_result($id, $nombre, $usr, $email, $hash, $rol, $activo);
                if ($stmt->fetch()) {
                    if ((int)$activo !== 1) {
                        $err = "Usuario inactivo.";
                    } elseif (!password_verify($clave, $hash)) {
                        $err = "Usuario o contraseña inválidos.";
                    } else {
                        $_SESSION['uid']    = $id;
                        $_SESSION['nombre'] = $nombre;
                        $_SESSION['rol']    = $rol;
                        $stmt->close();
                        $conn->close();
                        header('Location: dashboard.php'); exit;
                    }
                } else {
                    $err = "Usuario o contraseña inválidos.";
                }
            }
            if ($stmt) { $stmt->close(); }
        }
    }
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

    <p class="text-center mt-4 text-sm text-gray-500">
      ¿Problemas? <a href="login.php?debug=1" class="underline">Modo debug</a>
    </p>
  </div>
</body>
</html>
