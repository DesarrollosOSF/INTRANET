<?php
/**
 * Helpers del módulo SST (listado público de archivos e imágenes).
 */

function esExtensionImagenSst($ext)
{
    return in_array(strtolower((string) $ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/**
 * Convierte $_FILES['archivos'] o $_FILES['archivo'] (uno o varios) en una lista plana.
 */
function normalizarListaArchivosSubidosSst()
{
    $campo = isset($_FILES['archivos']) ? 'archivos' : (isset($_FILES['archivo']) ? 'archivo' : null);
    if ($campo === null || !isset($_FILES[$campo]['name'])) {
        return [];
    }

    $f = $_FILES[$campo];
    if (!is_array($f['name'])) {
        if (($f['name'] ?? '') === '') {
            return [];
        }
        return [[
            'name' => $f['name'],
            'type' => $f['type'] ?? '',
            'tmp_name' => $f['tmp_name'] ?? '',
            'error' => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($f['size'] ?? 0),
        ]];
    }

    $lista = [];
    foreach ($f['name'] as $i => $name) {
        if ($name === '') {
            continue;
        }
        $lista[] = [
            'name' => $name,
            'type' => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($f['size'][$i] ?? 0),
        ];
    }
    return $lista;
}

function mimeArchivoSst($tmp_path, $nombre_original = '')
{
    if (function_exists('mimeArchivoSubido')) {
        return mimeArchivoSubido($tmp_path, $nombre_original);
    }
    if ($tmp_path && is_readable($tmp_path) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmp_path);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }
    }
    return 'application/octet-stream';
}

/**
 * Devuelve los nombres de archivo de imagen asociados a un registro de archivos_sst.
 * Si existe la tabla opcional archivos_sst_imagenes, incluye esas fotos extra (galería).
 */
function obtenerImagenesArchivoSst($pdo, $ar)
{
    $imagenes = [];
    $archivo = $ar['archivo'] ?? '';
    if ($archivo !== '' && esExtensionImagenSst(pathinfo($archivo, PATHINFO_EXTENSION))) {
        $imagenes[] = $archivo;
    }

    if (!$pdo instanceof PDO) {
        return $imagenes;
    }

    try {
        $stmt = $pdo->prepare("SELECT archivo FROM archivos_sst_imagenes WHERE archivo_id = ? ORDER BY orden ASC, id ASC");
        $stmt->execute([(int) ($ar['id'] ?? 0)]);
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['archivo']) && !in_array($row['archivo'], $imagenes, true)) {
                $imagenes[] = $row['archivo'];
            }
        }
    } catch (Exception $e) {
        // La galería extra es opcional; no debe tumbar la página.
    }

    return $imagenes;
}
