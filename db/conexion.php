<?php
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
