<?php
require_once '../config/config.php';
requerirPermiso('gestionar_documentos_interes');

$page_title = 'Detalle del Documento de interés';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

$pdo = getDBConnection();
$documento_id = (int)($_GET['id'] ?? 0);
if (!$documento_id) {
    header('Location: documentos_interes.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT d.*, dep.nombre AS dependencia_nombre
    FROM documentos_interes d
    LEFT JOIN dependencias dep ON dep.id = d.dependencia_id
    WHERE d.id = ?
");
$stmt->execute([$documento_id]);
$documento = $stmt->fetch();
if (!$documento) {
    header('Location: documentos_interes.php');
    exit;
}

$upload_dir = rtrim(UPLOAD_PATH_DOCUMENTOS_INTERES, '/\\') . DIRECTORY_SEPARATOR;
$ext_permitidas = ALLOWED_DOCUMENTOS_INTERES_EXT;
$mimes_permitidos = ALLOWED_DOCUMENTOS_INTERES_MIMES;
$max_documento_mb = (int) ceil(MAX_DOCUMENT_SIZE / (1024 * 1024));
$max_video_mb = (int) ceil(MAX_VIDEO_SIZE / (1024 * 1024));
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (subidaRechazadaPorLimiteServidor()) {
        $mensaje = mensajeLimiteSubidaServidor('video');
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
                $stmt = $pdo->prepare("INSERT INTO modulos_documento_interes (documento_interes_id, titulo, descripcion, orden, solo_visualizacion) VALUES (?, ?, ?, ?, ?)");
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
                $stmt = $pdo->prepare("UPDATE modulos_documento_interes SET titulo = ?, descripcion = ?, orden = ?, solo_visualizacion = ? WHERE id = ? AND documento_interes_id = ?");
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
        $stmt = $pdo->prepare("DELETE FROM modulos_documento_interes WHERE id = ? AND documento_interes_id = ?");
        $stmt->execute([$modulo_id, $documento_id]);
        $mensaje = 'Módulo eliminado. Los archivos del módulo también se eliminaron.';
        $tipo_mensaje = 'success';
    } elseif ($accion === 'agregar_archivo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $nombre = trim(sanitizar($_POST['nombre_archivo'] ?? ''));
        $descripcion = trim($_POST['descripcion_archivo'] ?? '');
        $orden = (int)($_POST['orden_archivo'] ?? 0);
        
        if ($nombre === '') {
            $mensaje = 'Nombre del archivo es obligatorio.';
            $tipo_mensaje = 'danger';
        } elseif (!isset($_FILES['archivo'])) {
            $mensaje = mensajeLimiteSubidaServidor('video');
            $tipo_mensaje = 'danger';
        } elseif ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $mensaje = mensajeErrorSubidaPhp($_FILES['archivo']['error']);
            $tipo_mensaje = 'danger';
        } else {
            $validacion = validarArchivoDocumentosInteres($_FILES['archivo']);
            if (!$validacion['valido']) {
                $mensaje = $validacion['mensaje'];
                $tipo_mensaje = 'danger';
            } else {
                $ext = $validacion['ext'];
                $archivo_nombre = uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $upload_dir . $archivo_nombre)) {
                    $stmt = $pdo->prepare("INSERT INTO archivos_documento_interes (modulo_id, nombre, descripcion, archivo, orden, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$modulo_id, $nombre, $descripcion ?: null, $archivo_nombre, $orden, $_SESSION['usuario_id']]);
                    $mensaje = 'Archivo agregado.';
                    $tipo_mensaje = 'success';
                } else {
                    $mensaje = 'Error al guardar el archivo en el servidor. Revise permisos de escritura en uploads/documentos_interes/.';
                    $tipo_mensaje = 'danger';
                }
            }
        }
    } elseif ($accion === 'eliminar_archivo') {
        $archivo_id = (int)$_POST['archivo_id'];
        $stmt = $pdo->prepare("SELECT a.archivo FROM archivos_documento_interes a INNER JOIN modulos_documento_interes m ON m.id = a.modulo_id WHERE a.id = ? AND m.documento_interes_id = ?");
        $stmt->execute([$archivo_id, $documento_id]);
        $row = $stmt->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM archivos_documento_interes WHERE id = ?");
            $stmt->execute([$archivo_id]);
            if ($row['archivo'] && file_exists($upload_dir . $row['archivo'])) @unlink($upload_dir . $row['archivo']);
            $mensaje = 'Archivo eliminado.';
            $tipo_mensaje = 'success';
        }
    }
}

$modulos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos_documento_interes WHERE documento_interes_id = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$documento_id]);
    $modulos = $stmt->fetchAll();
} catch (Exception $e) {}

$archivos = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, m.titulo AS modulo_titulo
        FROM archivos_documento_interes a
        INNER JOIN modulos_documento_interes m ON m.id = a.modulo_id
        WHERE m.documento_interes_id = ?
        ORDER BY m.orden ASC, m.id ASC, a.orden ASC, a.id ASC
    ");
    $stmt->execute([$documento_id]);
    $archivos = $stmt->fetchAll();
} catch (Exception $e) {}

$archivos_por_modulo = [];
foreach ($archivos as $a) {
    $mid = $a['modulo_id'];
    if (!isset($archivos_por_modulo[$mid])) $archivos_por_modulo[$mid] = [];
    $archivos_por_modulo[$mid][] = $a;
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-folder2-open me-2"></i><?php echo htmlspecialchars($documento['nombre']); ?></h2>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($documento['dependencia_nombre'] ?? 'Sin dependencia'); ?></p>
        </div>
        <a href="documentos_interes.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Volver</a>
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalModulo">
                        <i class="bi bi-plus-circle me-1"></i>Nuevo módulo
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($modulos)): ?>
                        <p class="text-muted mb-0">No hay módulos. Cree uno para organizar los archivos (ej: Formatos, Plantillas, Procedimientos).</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($modulos as $mod): 
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
                        <p class="text-muted">Cree primero un módulo y luego agregue archivos (PDF, Office o video).</p>
                    <?php elseif (empty($archivos)): ?>
                        <p class="text-muted">No hay archivos. Use «Agregar archivo» y seleccione el módulo.</p>
                    <?php else: ?>
                        <?php foreach ($modulos as $mod): 
                            $lista = isset($archivos_por_modulo[$mod['id']]) ? $archivos_por_modulo[$mod['id']] : [];
                        ?>
                            <h6 class="mt-3 mb-2 text-primary"><i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?></h6>
                            <?php if (empty($lista)): ?>
                                <p class="small text-muted">Sin archivos.</p>
                            <?php else: ?>
                                <div class="list-group mb-3">
                                    <?php foreach ($lista as $ar):
                                        $url_download = UPLOAD_URL . 'documentos_interes/' . $ar['archivo'];
                                        $ext_archivo = strtolower(pathinfo($ar['archivo'] ?? '', PATHINFO_EXTENSION));
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
                                            <div class="flex-grow-1">
                                                <i class="bi bi-<?php echo $icono; ?> me-2"></i>
                                                <strong><?php echo htmlspecialchars($ar['nombre']); ?></strong>
                                                <?php if (!empty($ar['descripcion'])): ?>
                                                    <p class="mb-1 small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></p>
                                                <?php endif; ?>
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
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Documento</h5></div>
                <div class="card-body">
                    <?php if (!empty($documento['imagen'])): ?>
                        <img src="<?php echo UPLOAD_URL . htmlspecialchars($documento['imagen']); ?>" class="img-fluid rounded mb-2" alt="">
                    <?php endif; ?>
                    <?php if (!empty($documento['descripcion'])): ?>
                        <p class="small text-muted"><?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?></p>
                    <?php endif; ?>
                    <p class="small mb-0">Aquí se gestionan los módulos y los archivos (PDF, Office o video) de este documento de interés.</p>
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
                        <label class="form-label">Archivo * (PDF, Office o video)</label>
                        <input type="file" class="form-control" name="archivo" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.webm,.ogg,video/mp4,video/webm,video/ogg" required>
                        <div class="form-text">Documentos hasta <?php echo $max_documento_mb; ?> MB. Videos hasta <?php echo $max_video_mb; ?> MB.</div>
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
