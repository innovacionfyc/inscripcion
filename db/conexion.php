<?php
// Muestra errores solo mientras pruebas en el server.
// Quita estas 2 líneas cuando todo funcione.
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

/**
 * PRODUCCIÓN (Plesk)
 */
$host = 'localhost';              // en Plesk suele ir bien 127.0.0.1
$user = 'ErickCer1818';           // <-- tu usuario real
$pass = 'ElManCertifica18#';      // <-- tu contraseña real
$dbname = 'inscritos_db';         // <-- tu base

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_errno) {
    die('Error de conexión: ' . $conn->connect_error);
}
$conn->set_charset('utf8');
