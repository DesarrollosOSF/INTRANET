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

if (!isset($data['intento_id']) || !isset($data['pregunta_id']) || !isset($data['opcion_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

$intento_id = (int)$data['intento_id'];
$pregunta_id = (int)$data['pregunta_id'];
$opcion_id = (int)$data['opcion_id'];

try {
    $pdo = getDBConnection();
    
    // Verificar autorización
    $stmt = $pdo->prepare("
        SELECT ie.id FROM intentos_evaluacion ie
        INNER JOIN inscripciones i ON ie.inscripcion_id = i.id
        WHERE ie.id = ? AND i.usuario_id = ? AND ie.estado = 'en_proceso'
    ");
    $stmt->execute([$intento_id, $_SESSION['usuario_id']]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    
    // Guardar o actualizar respuesta
    $stmt = $pdo->prepare("
        INSERT INTO respuestas_usuario (intento_id, pregunta_id, opcion_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE opcion_id = ?
    ");
    $stmt->execute([$intento_id, $pregunta_id, $opcion_id, $opcion_id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al guardar respuesta']);
}
