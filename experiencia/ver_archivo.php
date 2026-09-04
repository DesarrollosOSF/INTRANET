<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/helpers.php';

$archivo_id = (int)($_GET['id'] ?? 0);
$modulo_id = (int)($_GET['modulo_id'] ?? 0);
$token = trim($_GET['token'] ?? '');

if ($archivo_id <= 0 || $modulo_id <= 0) {
    http_response_code(400);
    exit('Solicitud no válida.');
}

$pdo = getDBConnection();
$row = getArchivoExperienciaConModulo($pdo, $archivo_id, $modulo_id);
require_once '../includes/auditoria_helpers.php'; // ajusta la ruta según la profundidad del archivo
registrarVista($pdo, $_SESSION['usuario_id'], 'experiencia', $archivo_id);


if (!$row) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

if (empty($row['solo_visualizacion'])) {
    http_response_code(403);
    exit('Este archivo no está disponible para visualización protegida.');
}

$acceso_por_token = validarTokenVisorExperienciaArchivo($token, $archivo_id, $modulo_id);
$acceso_por_sesion = isset($_SESSION['usuario_id']) && usuarioPuedeAccederArchivoExperiencia($pdo, $archivo_id, $modulo_id);

if (!$acceso_por_token && !$acceso_por_sesion) {
    http_response_code(403);
    exit('No tiene permiso para ver este documento.');
}

$ruta_fisica = rtrim(UPLOAD_PATH_EXPERIENCIA, '/\\') . DIRECTORY_SEPARATOR . $row['archivo'];
if (!is_file($ruta_fisica)) {
    http_response_code(404);
    exit('El archivo no existe en el servidor.');
}

$ext = strtolower(pathinfo($row['archivo'], PATHINFO_EXTENSION));
$mime = mimeTipoExperienciaArchivo($ext);
$nombre = $row['nombre'] ?: basename($row['archivo']);
$nombre_seguro = preg_replace('/[^\w\s\.\-áéíóúñÁÉÍÓÚÑ]/u', '_', $nombre);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $nombre_seguro . '"');
header('Content-Length: ' . filesize($ruta_fisica));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Accept-Ranges: bytes');


readfile($ruta_fisica);
exit;
