<?php
require_once '../config/config.php';
requerirAutenticacion();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['inscripcion_id']) || !isset($data['material_id']) || !isset($data['tiempo_visualizado'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

$inscripcion_id = (int)$data['inscripcion_id'];
$material_id = (int)$data['material_id'];
$tiempo_visualizado = (int)$data['tiempo_visualizado'];

// Verificar que la inscripción pertenece al usuario
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT i.id FROM inscripciones i
        WHERE i.id = ? AND i.usuario_id = ?
    ");
    $stmt->execute([$inscripcion_id, $_SESSION['usuario_id']]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    
    // Actualizar progreso
    $stmt = $pdo->prepare("
        UPDATE progreso_material
        SET tiempo_visualizado = ?
        WHERE inscripcion_id = ? AND material_id = ?
    ");
    $stmt->execute([$tiempo_visualizado, $inscripcion_id, $material_id]);
    
    // Actualizar progreso general del curso
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_materiales,
            SUM(CASE WHEN pm.completado = 1 THEN 1 ELSE 0 END) as materiales_completados
        FROM materiales m
        LEFT JOIN progreso_material pm ON m.id = pm.material_id AND pm.inscripcion_id = ?
        WHERE m.curso_id = (SELECT curso_id FROM inscripciones WHERE id = ?)
    ");
    $stmt->execute([$inscripcion_id, $inscripcion_id]);
    $progreso = $stmt->fetch();
    
    $porcentaje = $progreso['total_materiales'] > 0 
        ? ($progreso['materiales_completados'] / $progreso['total_materiales']) * 100 
        : 0;
    
    $stmt = $pdo->prepare("
        UPDATE inscripciones
        SET progreso = ?
        WHERE id = ?
    ");
    $stmt->execute([$porcentaje, $inscripcion_id]);
    
    // Marcar como completado si todos los materiales están completados
    if ($progreso['materiales_completados'] == $progreso['total_materiales'] && $progreso['total_materiales'] > 0) {
        $stmt = $pdo->prepare("
            UPDATE inscripciones
            SET completado = 1, fecha_completado = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$inscripcion_id]);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al guardar progreso']);
}
