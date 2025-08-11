<?php
require_once __DIR__ . '/_auth.php';
require_role('admin'); // solo admin elimina
require_once dirname(__DIR__) . '/db/conexion.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "Evento inválido"; exit; }

// primero borra dependencias (por si no hay FK ON DELETE CASCADE)
$stmt1 = $conn->prepare("DELETE FROM eventos_fechas WHERE evento_id = ?");
$stmt1->bind_param('i', $id);
$stmt1->execute();
$stmt1->close();

// OPCIONAL: borra inscritos del evento (descomenta si lo deseas)
/*
$stmt2 = $conn->prepare("DELETE FROM inscritos WHERE evento_id = ?");
$stmt2->bind_param('i', $id);
$stmt2->execute();
$stmt2->close();
*/

// ahora borra evento
$stmt3 = $conn->prepare("DELETE FROM eventos WHERE id = ?");
$stmt3->bind_param('i', $id);
$stmt3->execute();
$stmt3->close();

$conn->close();
header('Location: eventos.php');
exit;
