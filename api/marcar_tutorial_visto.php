<?php
require_once '../config/config.php';
requerirAutenticacion();
header('Content-Type: application/json; charset=UTF-8');

$pdo = getDBConnection();
$pdo->prepare("UPDATE usuarios SET tutorial_visto = 1 WHERE id = ?")->execute([$_SESSION['usuario_id']]);
echo json_encode(['ok' => true]);
