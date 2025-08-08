<?php
$host = "localhost";
/* $user = "root";
$pass = ""; */
$usuario = "ErickCer1818";          
$contrasena = "ElManCertifica18#";
$dbname = "inscritos_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
?>