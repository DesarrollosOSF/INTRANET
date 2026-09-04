<?php
require_once '../config/config.php';
requerirAutenticacion();
require_once '../includes/auditoria_helpers.php';
header('Content-Type: application/json; charset=UTF-8');

$pdo = getDBConnection();
$tipo = $_POST['tipo'] ?? '';
$id = (int)($_POST['id'] ?? 0);

registrarVista($pdo, (int)$_SESSION['usuario_id'], $tipo, $id);
echo json_encode(['ok' => true]);
