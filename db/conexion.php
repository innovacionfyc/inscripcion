<?php
/**
 * Conexion principal a la base de datos
 * - En local (Laragon): usa conexion.local.php si existe
 * - En servidor: usa credenciales de producción
 */

// 👉 SI EXISTE CONFIG LOCAL, USARLA
if (file_exists(__DIR__ . '/conexion.local.php')) {
    require __DIR__ . '/conexion.local.php';
    return;
}

// ===============================
// CONFIGURACIÓN PRODUCCIÓN
// ===============================
$dbname = 'inscritos_db';
$host = 'localhost';
$user = 'ErickCer1818';
$pass = 'ElManCertifica18#';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_errno) {
    die('Error de conexión: ' . $conn->connect_error);
}

$conn->set_charset('utf8');
