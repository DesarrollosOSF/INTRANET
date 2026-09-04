<?php
require_once '../config/config.php';
requerirPermiso('ver_formacion');

$pdo = getDBConnection();
$usuario_id = (int)$_SESSION['usuario_id'];
$inscripcion_id = (int)($_GET['inscripcion_id'] ?? 0);

// Solo se permite si el curso pertenece al usuario y ya está completado
$stmt = $pdo->prepare("
    SELECT fi.id, fc.encuesta_url
    FROM formacion_inscripciones fi
    JOIN formacion_cursos fc ON fc.id = fi.curso_id
    WHERE fi.id = ? AND fi.usuario_id = ? AND fi.completado = 1
");
$stmt->execute([$inscripcion_id, $usuario_id]);
$datos = $stmt->fetch();

if (!$datos || empty($datos['encuesta_url'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo->prepare("INSERT INTO formacion_encuesta_track (inscripcion_id) VALUES (?)")->execute([$inscripcion_id]);
    registrarLog($usuario_id, 'Clic en encuesta de satisfacción', 'Formación', "Inscripción ID: $inscripcion_id");
} catch (Exception $e) {
    // No bloquear el flujo si falla el registro del clic
    error_log('No se pudo registrar clic de encuesta: ' . $e->getMessage());
}

header('Location: ' . $datos['encuesta_url']);
exit;
