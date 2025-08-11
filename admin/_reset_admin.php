<?php
// admin/_reset_admin.php  (BORRAR AL TERMINAR)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/db/conexion.php';

// Cambia aquí si quieres otro usuario/clave
$usuario = 'admin';
$nombre  = 'Administrador';
$email   = 'admin@fycconsultores.com';
$clave_plana = 'Admin@123'; // <- contraseña nueva
$rol = 'admin';

$hash = password_hash($clave_plana, PASSWORD_BCRYPT);

// ¿existe?
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
$stmt->bind_param('s', $usuario);
$stmt->execute();
$stmt->bind_result($id);
$existe = $stmt->fetch();
$stmt->close();

if ($existe) {
    $stmt = $conn->prepare("UPDATE usuarios 
        SET nombre=?, email=?, password_hash=?, rol='admin', activo=1
        WHERE id=?");
    $stmt->bind_param('sssi', $nombre, $email, $hash, $id);
    $ok = $stmt->execute();
    $stmt->close();
    echo $ok ? "Admin actualizado. Usuario: {$usuario}  Clave: {$clave_plana}" 
             : "Error actualizando: " . $conn->error;
} else {
    $stmt = $conn->prepare("INSERT INTO usuarios (nombre, usuario, email, password_hash, rol, activo)
                            VALUES (?, ?, ?, ?, 'admin', 1)");
    $stmt->bind_param('ssss', $nombre, $usuario, $email, $hash);
    $ok = $stmt->execute();
    $stmt->close();
    echo $ok ? "Admin creado. Usuario: {$usuario}  Clave: {$clave_plana}" 
             : "Error insertando: " . $conn->error;
}

$conn->close();
