<?php
require_once '../config/config.php';
requerirPermiso('ver_formacion');

$pdo = getDBConnection();
$usuario_id = (int)$_SESSION['usuario_id'];
$material_id = (int)($_GET['id'] ?? 0);

// Solo se sirve el archivo si el usuario está inscrito al curso dueño del material
$stmt = $pdo->prepare("
    SELECT fm.archivo
    FROM formacion_materiales fm
    JOIN formacion_inscripciones fi ON fi.curso_id = fm.curso_id AND fi.usuario_id = ?
    WHERE fm.id = ?
");
$stmt->execute([$usuario_id, $material_id]);
$material = $stmt->fetch();

if (!$material) {
    http_response_code(403);
    exit('No autorizado.');
}

$ruta = realpath(__DIR__ . '/../uploads/' . $material['archivo']);
$carpeta_permitida = realpath(__DIR__ . '/../uploads/formacion/');

// Verifica que la ruta resuelta siga dentro de uploads/formacion/ (evita path traversal)
if (!$ruta || !$carpeta_permitida || strpos($ruta, $carpeta_permitida) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$mime = mime_content_type($ruta);
$size = filesize($ruta);

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="material"'); // nombre genérico, no revela el original
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$start = 0;
$end = $size - 1;

// Soporte de peticiones parciales (necesario para poder adelantar/atrasar el video)
if (
    isset($_SERVER['HTTP_RANGE']) &&
    preg_match(
        '/bytes=(\d*)-(\d*)/',
        $_SERVER['HTTP_RANGE'],
        $m
    )
) {

    $start = $m[1] !== ''
        ? (int)$m[1]
        : 0;

    $end = $m[2] !== ''
        ? (int)$m[2]
        : $end;

    $end = min(
        $end,
        $size - 1
    );

    http_response_code(206);

    header(
        "Content-Range: bytes {$start}-{$end}/{$size}"
    );

}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fp = fopen($ruta, 'rb');
fseek($fp, $start);

$buffer = 8192;
$restante = $length;
while (!feof($fp) && $restante > 0) {
    $leer = $restante > $buffer ? $buffer : $restante;
    echo fread($fp, $leer);
    flush();
    $restante -= $leer;
}
fclose($fp);
require_once '../includes/auditoria_helpers.php'; // ajusta la ruta según la profundidad del archivo
registrarVista($pdo, $_SESSION['usuario_id'], 'formacion', $material_id);
exit;
