<?php
require_once '../config/config.php';
require_once dirname(__DIR__) . '/includes/sst_helpers.php';
requerirPermiso('gestionar_sst');

if (!defined('UPLOAD_PATH_SST')) {
    define('UPLOAD_PATH_SST', UPLOAD_PATH . 'sst/');
}
if (!defined('UPLOAD_URL_SST')) {
    define('UPLOAD_URL_SST', UPLOAD_URL . 'sst/');
}

$pdo = getDBConnection();
$documento_id = (int)($_GET['id'] ?? 0);
if (!$documento_id) {
    header('Location: sst.php');
    exit;
}

$documento = null;
$error_tabla = '';
try {
    $stmt = $pdo->prepare("
        SELECT d.*, dep.nombre AS dependencia_nombre
        FROM sst_documentos d
        LEFT JOIN dependencias dep ON dep.id = d.dependencia_id
        WHERE d.id = ?
    ");
    $stmt->execute([$documento_id]);
    $documento = $stmt->fetch();
} catch (Exception $e) {
    error_log('SST detalle: ' . $e->getMessage());
    $error_tabla = 'No se pudo cargar el documento SST. Verifique que se ejecutó la migración docs/sst_contenido.sql.';
}

if (!$error_tabla && !$documento) {
    header('Location: sst.php');
    exit;
}

$page_title = 'Detalle del documento SST';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

if ($error_tabla) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">' . htmlspecialchars($error_tabla) . '</div>';
    echo '<a href="sst.php" class="btn btn-secondary">Volver</a></div>';
    require_once '../includes/footer.php';
    exit;
}

$ext_base = defined('ALLOWED_DOCUMENTOS_INTERES_EXT') ? ALLOWED_DOCUMENTOS_INTERES_EXT : ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
$mimes_base = defined('ALLOWED_DOCUMENTOS_INTERES_MIMES') ? ALLOWED_DOCUMENTOS_INTERES_MIMES : [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$upload_dir = rtrim(UPLOAD_PATH_SST, '/\\') . DIRECTORY_SEPARATOR;
$ext_permitidas = array_unique(array_merge($ext_base, ['jpg', 'jpeg', 'png', 'gif', 'webp']));
$mimes_permitidos = array_unique(array_merge($mimes_base, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']));
$max_size = defined('MAX_DOCUMENT_SIZE') ? MAX_DOCUMENT_SIZE : (600 * 1024 * 1024);
$max_documento_mb = (int) ceil($max_size / (1024 * 1024));
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('subidaRechazadaPorLimiteServidor') && subidaRechazadaPorLimiteServidor()) {
        $mensaje = function_exists('mensajeLimiteSubidaServidor')
            ? mensajeLimiteSubidaServidor('documento')
            : 'La subida fue rechazada por el servidor. El límite de la aplicación es ' . $max_documento_mb . ' MB.';
        $tipo_mensaje = 'danger';
    }
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_modulo') {
        $titulo = trim(sanitizar($_POST['titulo_modulo'] ?? ''));
        $descripcion = trim(sanitizar($_POST['descripcion_modulo'] ?? ''));
        $orden = (int)($_POST['orden_modulo'] ?? 0);
        $solo_visualizacion = isset($_POST['solo_visualizacion']) ? 1 : 0;
        if ($titulo !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO modulos_sst (sst_documento_id, titulo, descripcion, orden, solo_visualizacion) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$documento_id, $titulo, $descripcion, $orden, $solo_visualizacion]);
                $mensaje = 'Módulo creado.';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error al crear módulo. Ejecute la migración docs/modulo_documento_solo_visualizacion.sql en la base de datos.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'editar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $titulo = trim(sanitizar($_POST['titulo_modulo'] ?? ''));
        $descripcion = trim(sanitizar($_POST['descripcion_modulo'] ?? ''));
        $orden = (int)($_POST['orden_modulo'] ?? 0);
        $solo_visualizacion = isset($_POST['solo_visualizacion']) ? 1 : 0;
        if ($titulo !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE modulos_sst SET titulo = ?, descripcion = ?, orden = ?, solo_visualizacion = ? WHERE id = ? AND sst_documento_id = ?");
                $stmt->execute([$titulo, $descripcion, $orden, $solo_visualizacion, $modulo_id, $documento_id]);
                $mensaje = 'Módulo actualizado.';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error al actualizar módulo. Ejecute la migración docs/modulo_documento_solo_visualizacion.sql en la base de datos.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'eliminar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM modulos_sst WHERE id = ? AND sst_documento_id = ?");
            $stmt->execute([$modulo_id, $documento_id]);
            $mensaje = 'Módulo eliminado. Los archivos del módulo también se eliminaron.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            error_log('SST eliminar módulo: ' . $e->getMessage());
            $mensaje = 'Error al eliminar el módulo.';
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'agregar_archivo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $nombre = trim(sanitizar($_POST['nombre_archivo'] ?? ''));
        $descripcion = trim($_POST['descripcion_archivo'] ?? '');
        $orden = (int)($_POST['orden_archivo'] ?? 0);
        $archivos_subidos = normalizarListaArchivosSubidosSst();

        if ($nombre === '') {
            $mensaje = 'Nombre del archivo es obligatorio.';
            $tipo_mensaje = 'danger';
        } elseif (empty($archivos_subidos)) {
            $mensaje = 'Debe seleccionar al menos un archivo (PDF, Word, Excel, PowerPoint o imagen).';
            $tipo_mensaje = 'danger';
        } else {
            $validados = [];
            $error_validacion = '';
            foreach ($archivos_subidos as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error_validacion = function_exists('mensajeErrorSubidaPhp')
                        ? mensajeErrorSubidaPhp($file['error'])
                        : 'Error al subir uno de los archivos.';
                    break;
                }
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $mime = mimeArchivoSst($file['tmp_name'], $file['name']);
                if (!in_array($ext, $ext_permitidas, true) || !in_array($mime, $mimes_permitidos, true)) {
                    $error_validacion = 'Tipo no permitido (' . htmlspecialchars($file['name']) . '). Use PDF, Word, Excel, PowerPoint o imagen (JPG, PNG, GIF, WEBP).';
                    break;
                }
                if ((int) $file['size'] > $max_size) {
                    $error_validacion = 'El archivo ' . htmlspecialchars($file['name']) . ' supera ' . $max_documento_mb . ' MB.';
                    break;
                }
                $file['ext'] = $ext;
                $validados[] = $file;
            }

            if ($error_validacion !== '') {
                $mensaje = $error_validacion;
                $tipo_mensaje = 'danger';
            } else {
                $todas_imagenes = true;
                foreach ($validados as $file) {
                    if (!esExtensionImagenSst($file['ext'])) {
                        $todas_imagenes = false;
                        break;
                    }
                }

                $guardados = 0;
                $nombres_disco = [];
                foreach ($validados as $file) {
                    $disco = uniqid('', true) . '.' . $file['ext'];
                    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $disco)) {
                        $error_validacion = 'Error al guardar el archivo ' . htmlspecialchars($file['name']) . '.';
                        break;
                    }
                    $nombres_disco[] = $disco;
                }

                if ($error_validacion !== '') {
                    foreach ($nombres_disco as $disco) {
                        if (file_exists($upload_dir . $disco)) {
                            @unlink($upload_dir . $disco);
                        }
                    }
                    $mensaje = $error_validacion;
                    $tipo_mensaje = 'danger';
                } elseif ($todas_imagenes && count($nombres_disco) > 1) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO archivos_sst (modulo_id, nombre, descripcion, archivo, orden, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$modulo_id, $nombre, $descripcion ?: null, $nombres_disco[0], $orden, $_SESSION['usuario_id']]);
                        $archivo_id = (int) $pdo->lastInsertId();
                        $extras_ok = true;
                        try {
                            $stmt_img = $pdo->prepare("INSERT INTO archivos_sst_imagenes (archivo_id, archivo, orden) VALUES (?, ?, ?)");
                            for ($i = 1; $i < count($nombres_disco); $i++) {
                                $stmt_img->execute([$archivo_id, $nombres_disco[$i], $orden + $i]);
                            }
                        } catch (Exception $e_galeria) {
                            $extras_ok = false;
                            error_log('SST galería: ' . $e_galeria->getMessage());
                            for ($i = 1; $i < count($nombres_disco); $i++) {
                                $nombre_extra = $nombre . ' (' . ($i + 1) . ')';
                                $stmt->execute([$modulo_id, $nombre_extra, $descripcion ?: null, $nombres_disco[$i], $orden + $i, $_SESSION['usuario_id']]);
                            }
                        }
                        $guardados = count($nombres_disco);
                        $mensaje = $extras_ok
                            ? 'Galería agregada (' . $guardados . ' imágenes).'
                            : 'Se agregaron ' . $guardados . ' imágenes. Ejecute docs/sst_contenido.sql para guardarlas como galería.';
                        $tipo_mensaje = 'success';
                    } catch (Exception $e) {
                        foreach ($nombres_disco as $disco) {
                            if (file_exists($upload_dir . $disco)) {
                                @unlink($upload_dir . $disco);
                            }
                        }
                        error_log('SST agregar galería: ' . $e->getMessage());
                        $mensaje = 'Error al registrar las imágenes. Ejecute la migración docs/sst_contenido.sql.';
                        $tipo_mensaje = 'danger';
                    }
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO archivos_sst (modulo_id, nombre, descripcion, archivo, orden, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($nombres_disco as $i => $disco) {
                            $nombre_item = count($nombres_disco) > 1 ? ($nombre . ' (' . ($i + 1) . ')') : $nombre;
                            $stmt->execute([$modulo_id, $nombre_item, $descripcion ?: null, $disco, $orden + $i, $_SESSION['usuario_id']]);
                            $guardados++;
                        }
                        $mensaje = $guardados === 1 ? 'Archivo agregado.' : $guardados . ' archivos agregados.';
                        $tipo_mensaje = 'success';
                    } catch (Exception $e) {
                        foreach ($nombres_disco as $disco) {
                            if (file_exists($upload_dir . $disco)) {
                                @unlink($upload_dir . $disco);
                            }
                        }
                        error_log('SST agregar archivo: ' . $e->getMessage());
                        $mensaje = 'Error al registrar el archivo. Ejecute la migración docs/sst_contenido.sql.';
                        $tipo_mensaje = 'danger';
                    }
                }
            }
        }
    } elseif ($accion === 'eliminar_archivo') {
        $archivo_id = (int)$_POST['archivo_id'];
        try {
            $stmt = $pdo->prepare("SELECT a.* FROM archivos_sst a INNER JOIN modulos_sst m ON m.id = a.modulo_id WHERE a.id = ? AND m.sst_documento_id = ?");
            $stmt->execute([$archivo_id, $documento_id]);
            $row = $stmt->fetch();
            if ($row) {
                $imagenes = obtenerImagenesArchivoSst($pdo, $row);
                $stmt = $pdo->prepare("DELETE FROM archivos_sst WHERE id = ?");
                $stmt->execute([$archivo_id]);
                $a_borrar = $imagenes;
                if (!empty($row['archivo']) && !in_array($row['archivo'], $a_borrar, true)) {
                    $a_borrar[] = $row['archivo'];
                }
                foreach ($a_borrar as $img) {
                    if ($img && file_exists($upload_dir . $img)) {
                        @unlink($upload_dir . $img);
                    }
                }
                $mensaje = 'Archivo eliminado.';
                $tipo_mensaje = 'success';
            }
        } catch (Exception $e) {
            error_log('SST eliminar archivo: ' . $e->getMessage());
            $mensaje = 'Error al eliminar el archivo.';
            $tipo_mensaje = 'danger';
        }
    }
}

$modulos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos_sst WHERE sst_documento_id = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$documento_id]);
    $modulos = $stmt->fetchAll();
} catch (Exception $e) {
}

$archivos = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, m.titulo AS modulo_titulo
        FROM archivos_sst a
        INNER JOIN modulos_sst m ON m.id = a.modulo_id
        WHERE m.sst_documento_id = ?
        ORDER BY m.orden ASC, m.id ASC, a.orden ASC, a.id ASC
    ");
    $stmt->execute([$documento_id]);
    $archivos = $stmt->fetchAll();
} catch (Exception $e) {
}

$archivos_por_modulo = [];
foreach ($archivos as $a) {
    $mid = $a['modulo_id'];
    if (!isset($archivos_por_modulo[$mid])) $archivos_por_modulo[$mid] = [];
    $archivos_por_modulo[$mid][] = $a;
}
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($busqueda !== '') {
    $termino = function_exists('mb_strtolower') ? mb_strtolower($busqueda, 'UTF-8') : strtolower($busqueda);

    $modulos_visibles = [];
    $archivos_por_modulo_visibles = [];

    foreach ($modulos as $mod) {
        $titulo_mod = (string)($mod['titulo'] ?? '');
        $desc_mod = (string)($mod['descripcion'] ?? '');
        $coincide_modulo = (function_exists('mb_stripos') ? mb_stripos($titulo_mod, $termino) : stripos($titulo_mod, $termino)) !== false
            || (function_exists('mb_stripos') ? mb_stripos($desc_mod, $termino) : stripos($desc_mod, $termino)) !== false;

        $archivos_mod = $archivos_por_modulo[$mod['id']] ?? [];
        $archivos_coincidentes = array_values(array_filter($archivos_mod, function ($ar) use ($termino) {
            $nombre_ar = (string)($ar['nombre'] ?? '');
            $desc_ar = (string)($ar['descripcion'] ?? '');
            return (function_exists('mb_stripos') ? mb_stripos($nombre_ar, $termino) : stripos($nombre_ar, $termino)) !== false
                || (function_exists('mb_stripos') ? mb_stripos($desc_ar, $termino) : stripos($desc_ar, $termino)) !== false;
        }));

        // Se muestra el módulo si coincide su título/descripción,
        // o si alguno de sus archivos coincide con la búsqueda.
        if ($coincide_modulo || !empty($archivos_coincidentes)) {
            $modulos_visibles[] = $mod;
            $archivos_por_modulo_visibles[$mod['id']] = $coincide_modulo ? $archivos_mod : $archivos_coincidentes;
        }
    }
} else {
    $modulos_visibles = $modulos;
    $archivos_por_modulo_visibles = $archivos_por_modulo;
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-folder2-open me-2"></i><?php echo htmlspecialchars($documento['nombre']); ?></h2>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($documento['dependencia_nombre'] ?? 'Sin dependencia'); ?></p>
        </div>
        <a href="sst.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Volver</a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos</h5>
                    <form method="get" action="" class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0" style="max-width: 400px;">
                        <input type="hidden" name="id" value="<?php echo (int)$documento_id; ?>">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar módulo o archivo..." value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
                        <?php if ($busqueda !== ''): ?>
                            <a href="?id=<?php echo (int)$documento_id; ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                        <?php endif; ?>
                    </form>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalModulo">
                        <i class="bi bi-plus-circle me-1"></i>Nuevo módulo
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($modulos_visibles)): ?>
                        <p class="text-muted mb-0">
                            <?php echo $busqueda !== '' ? 'No se encontraron módulos ni archivos para "' . htmlspecialchars($busqueda) . '".' : 'No hay módulos. Cree uno para organizar los archivos (ej: Formatos, Plantillas, Procedimientos).'; ?>
                        </p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($modulos_visibles as $mod):

                                $cant = isset($archivos_por_modulo[$mod['id']]) ? count($archivos_por_modulo[$mod['id']]) : 0;
                            ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($mod['titulo']); ?></strong>
                                        <span class="badge bg-secondary ms-2"><?php echo $cant; ?> archivo(s)</span>
                                        <?php if (!empty($mod['solo_visualizacion'])): ?>
                                            <span class="badge bg-info text-dark ms-1">Solo visualización</span>
                                        <?php else: ?>
                                            <span class="badge bg-success ms-1">Descarga</span>
                                        <?php endif; ?>
                                        <?php if (!empty($mod['descripcion'])): ?>
                                            <p class="mb-0 small text-muted"><?php echo htmlspecialchars($mod['descripcion']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editarModulo(<?php echo htmlspecialchars(json_encode($mod)); ?>)"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este módulo y sus archivos?');">
                                            <input type="hidden" name="accion" value="eliminar_modulo">
                                            <input type="hidden" name="modulo_id" value="<?php echo $mod['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-file-earmark me-2"></i>Archivos por módulo</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalArchivo" <?php echo empty($modulos) ? 'disabled title="Cree al menos un módulo"' : ''; ?>>
                        <i class="bi bi-plus-circle me-1"></i>Agregar archivo
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($modulos)): ?>
                        <p class="text-muted">Cree primero un módulo y luego agregue archivos (PDF, Word, Excel).</p>
                    <?php elseif (empty($modulos_visibles)): ?>
                        <p class="text-muted">
                            <?php echo $busqueda !== '' ? 'Sin resultados para la búsqueda.' : 'No hay archivos. Use «Agregar archivo» y seleccione el módulo.'; ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($modulos_visibles as $mod):
                            $lista = $archivos_por_modulo_visibles[$mod['id']] ?? [];
                            if (empty($lista)) continue; // el módulo coincidió pero no tiene archivos que mostrar aquí
                        ?>
                            <h6 class="mt-3 mb-2 text-primary"><i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?></h6>
                            <?php if (empty($lista)): ?>
                                <p class="small text-muted">Sin archivos.</p>
                            <?php else: ?>
                                <div class="list-group mb-3">
                                    <?php foreach ($lista as $ar):
    $url_download = UPLOAD_URL_SST . $ar['archivo'];
    $ext_archivo = strtolower(pathinfo($ar['archivo'] ?? '', PATHINFO_EXTENSION));
    $imagenes_galeria = obtenerImagenesArchivoSst($pdo, $ar);
    $es_galeria = count($imagenes_galeria) > 1;
    $es_imagen = $es_galeria || esExtensionImagenSst($ext_archivo);
    if ($ext_archivo === 'pdf') {
        $icono = 'file-earmark-pdf';
    } elseif (in_array($ext_archivo, ['doc', 'docx'], true)) {
        $icono = 'file-earmark-word';
    } elseif (in_array($ext_archivo, ['xls', 'xlsx'], true)) {
        $icono = 'file-earmark-excel';
    } elseif (in_array($ext_archivo, ['ppt', 'pptx'], true)) {
        $icono = 'file-earmark-slides';
    } else {
        $icono = 'file-earmark';
    }
?>
    <div class="list-group-item d-flex justify-content-between align-items-start">
        <div class="d-flex align-items-start flex-grow-1">
            <?php if ($es_imagen): ?>
                <img src="<?php echo htmlspecialchars($url_download); ?>" alt="<?php echo htmlspecialchars($ar['nombre']); ?>"
                     class="rounded me-2 flex-shrink-0" style="width:48px;height:48px;object-fit:cover;">
            <?php else: ?>
                <i class="bi bi-<?php echo $icono; ?> me-2"></i>
            <?php endif; ?>
            <div>
                <strong><?php echo htmlspecialchars($ar['nombre']); ?></strong>
                <?php if ($es_galeria): ?>
                    <span class="badge bg-info text-dark ms-1"><?php echo count($imagenes_galeria); ?> imágenes</span>
                <?php endif; ?>
                <?php if (!empty($ar['descripcion'])): ?>
                    <p class="mb-1 small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="<?php echo htmlspecialchars($url_download); ?>" class="btn btn-sm btn-outline-primary" download><i class="bi bi-download"></i></a>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este archivo?');">
                <input type="hidden" name="accion" value="eliminar_archivo">
                <input type="hidden" name="archivo_id" value="<?php echo $ar['id']; ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Documento</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($documento['imagen'])): ?>
                        <img src="<?php echo UPLOAD_URL . htmlspecialchars($documento['imagen']); ?>" class="img-fluid rounded mb-2" alt="">
                    <?php endif; ?>
                    <?php if (!empty($documento['descripcion'])): ?>
                        <p class="small text-muted"><?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?></p>
                    <?php endif; ?>
                    <p class="small mb-0">Aquí se gestionan los módulos y los archivos (PDF, Word, Excel) de este documento SST.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Módulo -->
<div class="modal fade" id="modalModulo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formModulo">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalModuloTitle">Nuevo módulo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionModulo" value="agregar_modulo">
                    <input type="hidden" name="modulo_id" id="moduloId">
                    <div class="mb-3">
                        <label class="form-label">Título del módulo *</label>
                        <input type="text" class="form-control" name="titulo_modulo" id="tituloModulo" required placeholder="Ej: Formatos, Plantillas">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion_modulo" id="descripcionModulo" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden</label>
                        <input type="number" class="form-control" name="orden_modulo" id="ordenModulo" value="0" min="0">
                    </div>
                    <div class="mb-0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="solo_visualizacion" id="soloVisualizacionModulo" value="1">
                            <label class="form-check-label" for="soloVisualizacionModulo">Solo visualización</label>
                        </div>
                        <div class="form-text">
                            Desactivado: los archivos se descargan al hacer clic (ej. formatos y plantillas).
                            Activado: se abren en pantalla completa para consulta, sin descarga directa (ej. cumpleaños, colaborador del mes).
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Archivo -->
<div class="modal fade" id="modalArchivo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar archivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="agregar_archivo">
                    <div class="mb-3">
                        <label class="form-label">Módulo *</label>
                        <select class="form-select" name="modulo_id" required>
                            <option value="">Seleccione un módulo</option>
                            <?php foreach ($modulos as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['titulo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre del archivo *</label>
                        <input type="text" class="form-control" name="nombre_archivo" required placeholder="Ej: Formato permiso vacaciones">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion_archivo" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo(s) * (PDF, Word, Excel, PowerPoint o Imagen. Máx. <?php echo $max_documento_mb; ?> MB c/u)</label>
                        <input type="file" class="form-control" name="archivos[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp" multiple required>
                        <div class="form-text">Puede seleccionar varias imágenes a la vez: se guardan como una galería.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden</label>
                        <input type="number" class="form-control" name="orden_archivo" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Agregar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function editarModulo(mod) {
        document.getElementById('modalModuloTitle').textContent = 'Editar módulo';
        document.getElementById('accionModulo').value = 'editar_modulo';
        document.getElementById('moduloId').value = mod.id;
        document.getElementById('tituloModulo').value = mod.titulo;
        document.getElementById('descripcionModulo').value = mod.descripcion || '';
        document.getElementById('ordenModulo').value = mod.orden || 0;
        document.getElementById('soloVisualizacionModulo').checked = !!parseInt(mod.solo_visualizacion || 0, 10);
        new bootstrap.Modal(document.getElementById('modalModulo')).show();
    }
    document.getElementById('modalModulo').addEventListener('hidden.bs.modal', function() {
        document.getElementById('formModulo').reset();
        document.getElementById('modalModuloTitle').textContent = 'Nuevo módulo';
        document.getElementById('accionModulo').value = 'agregar_modulo';
        document.getElementById('moduloId').value = '';
        document.getElementById('soloVisualizacionModulo').checked = false;
    });
</script>

<?php require_once '../includes/footer.php'; ?>