<?php
// api/registrar_log.php

require_once '../config/config.php';
requerirAutenticacion();

// Asegúrate de incluir el archivo donde declaraste registrarLog() si no está en config.php
// require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Obtener el cuerpo de la petición JSON
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);

    $accion = $input['accion'] ?? 'ver_comunicado';
    $modulo = $input['modulo'] ?? 'Comunicados';
    $detalles = $input['detalles'] ?? null;

    if (isset($_SESSION['usuario_id'])) {
        // Llamada a tu función existente
        $resultado = registrarLog($_SESSION['usuario_id'], $accion, $modulo, $detalles);

        if ($resultado) {
            echo json_encode(['status' => 'success', 'message' => 'Log registrado']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar en BD']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Sesión no válida']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);