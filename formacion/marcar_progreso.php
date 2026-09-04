<?php
require_once '../config/config.php';
require_once '../includes/formacion_helpers.php';
requerirPermiso('ver_formacion');
header('Content-Type: application/json; charset=UTF-8');

$pdo = getDBConnection();
$usuario_id = (int)$_SESSION['usuario_id'];
$material_id = (int)($_POST['material_id'] ?? 0);

if (!$material_id) {
    echo json_encode(['ok' => false, 'error' => 'Material inválido.']);
    exit;
}

// Verifica que el material pertenezca a un curso al que el usuario está inscrito
$stmt = $pdo->prepare("
    SELECT fm.curso_id, fi.id AS inscripcion_id, fi.completado
    FROM formacion_materiales fm
    JOIN formacion_inscripciones fi ON fi.curso_id = fm.curso_id AND fi.usuario_id = ?
    WHERE fm.id = ?
");
$stmt->execute([$usuario_id, $material_id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

$inscripcion_id = (int)$row['inscripcion_id'];
$curso_id = (int)$row['curso_id'];

try {
    $pdo->prepare("
        INSERT INTO formacion_progreso_material (inscripcion_id, material_id, completado, fecha_inicio, fecha_completado)
        VALUES (?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE completado = 1, fecha_completado = NOW()
    ")->execute([$inscripcion_id, $material_id]);

    // Recalcular progreso general del curso según materiales vistos
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM formacion_materiales WHERE curso_id = ?");
    $stmtTotal->execute([$curso_id]);
    $total = (int)$stmtTotal->fetchColumn();

    $stmtVistos = $pdo->prepare("
        SELECT COUNT(*) FROM formacion_progreso_material pm
        JOIN formacion_materiales fm ON fm.id = pm.material_id
        WHERE pm.inscripcion_id = ? AND pm.completado = 1 AND fm.curso_id = ?
    ");
    $stmtVistos->execute([$inscripcion_id, $curso_id]);
    $completados = (int)$stmtVistos->fetchColumn();

    // El progreso de contenido no supera 90% aquí: el 100% se reserva para cuando aprueba la evaluación
    $progreso = $total > 0 ? round(($completados / $total) * 90) : 0;
    if (!$row['completado']) {
        $pdo->prepare("UPDATE formacion_inscripciones SET progreso = ? WHERE id = ?")->execute([$progreso, $inscripcion_id]);
    }
    verificarCompletadoCurso($pdo, $curso_id, $inscripcion_id);

    echo json_encode(['ok' => true, 'completados' => $completados, 'total' => $total, 'progreso' => $progreso]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
