<?php
// Muestra errores solo mientras pruebas en el server.
// Quita estas 2 líneas cuando todo funcione.
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

/**
 * PRODUCCIÓN (Plesk)
 */
$dbname = 'inscritos_db';
$host = 'localhost';
$user = 'ErickCer1818';
$pass = 'ElManCertifica18#';
/* $user = 'root';
$pass = ''; */

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_errno) {
    die('Error de conexión: ' . $conn->connect_error);
}
$conn->set_charset('utf8');
