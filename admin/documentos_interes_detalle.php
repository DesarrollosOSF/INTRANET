<?php
require_once '../config/config.php';
requerirPermiso('gestionar_documentos_interes');

$page_title = 'Módulos y documentos';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

$pdo = getDBConnection();
$dependencia_id = (int)($_GET['dependencia_id'] ?? 0);

if (!$dependencia_id) {
    header('Location: documentos_interes.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM dependencias WHERE id = ?");
$stmt->execute([$dependencia_id]);
$dependencia = $stmt->fetch();

if (!$dependencia) {
    header('Location: documentos_interes.php');
    exit;
}

$upload_dir = rtrim(UPLOAD_PATH_DOCUMENTOS_INTERES, '/\\') . DIRECTORY_SEPARATOR;
$max_size = MAX_DOCUMENT_SIZE;
$ext_permitidas = ALLOWED_DOCUMENTOS_INTERES_EXT;
$mimes_permitidos = ALLOWED_DOCUMENTOS_INTERES_MIMES;
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$mensaje = '';
$tipo_mensaje = '';

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_modulo') {
        $titulo = trim(sanitizar($_POST['titulo_modulo'] ?? ''));
        $descripcion = trim(sanitizar($_POST['descripcion_modulo'] ?? ''));
        $orden = (int)($_POST['orden_modulo'] ?? 0);
        if ($titulo !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO modulos_documentos_interes (dependencia_id, titulo, descripcion, orden) VALUES (?, ?, ?, ?)");
                $stmt->execute([$dependencia_id, $titulo, $descripcion, $orden]);
                registrarLog($_SESSION['usuario_id'], 'Agregar módulo documentos', 'Documentos', "Dependencia ID: $dependencia_id");
                $mensaje = 'Módulo creado.';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error. Asegúrese de haber ejecutado la migración de módulos de documentos de interés en la base de datos.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'editar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $titulo = trim(sanitizar($_POST['titulo_modulo'] ?? ''));
        $descripcion = trim(sanitizar($_POST['descripcion_modulo'] ?? ''));
        $orden = (int)($_POST['orden_modulo'] ?? 0);
        if ($titulo !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE modulos_documentos_interes SET titulo = ?, descripcion = ?, orden = ? WHERE id = ? AND dependencia_id = ?");
                $stmt->execute([$titulo, $descripcion, $orden, $modulo_id, $dependencia_id]);
                $mensaje = 'Módulo actualizado.';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error al actualizar módulo.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'eliminar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        try {
            $stmt = $pdo->prepare("UPDATE documentos_interes SET modulo_id = NULL WHERE modulo_id = ?");
            $stmt->execute([$modulo_id]);
            $stmt = $pdo->prepare("DELETE FROM modulos_documentos_interes WHERE id = ? AND dependencia_id = ?");
            $stmt->execute([$modulo_id, $dependencia_id]);
            $mensaje = 'Módulo eliminado. Los documentos quedan sin módulo.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al eliminar módulo.';
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'agregar_documento') {
        $modulo_id = !empty($_POST['modulo_id']) ? (int)$_POST['modulo_id'] : null;
        $nombre = trim(sanitizar($_POST['nombre'] ?? ''));
        $descripcion = trim($_POST['descripcion'] ?? '');
        $orden = (int)($_POST['orden'] ?? 0);
        if ($nombre === '') {
            $mensaje = 'Nombre del documento es obligatorio.';
            $tipo_mensaje = 'danger';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM documentos_interes WHERE dependencia_id = ? AND nombre = ?");
            $stmt->execute([$dependencia_id, $nombre]);
            if ($stmt->fetch()) {
                $mensaje = 'Ya existe un documento con ese nombre en esta dependencia.';
                $tipo_mensaje = 'danger';
            } elseif (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                $mensaje = 'Debe adjuntar un archivo.';
                $tipo_mensaje = 'danger';
            } else {
                $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['archivo']['tmp_name']);
                finfo_close($finfo);
                if (!in_array($ext, $ext_permitidas) || !in_array($mime, $mimes_permitidos)) {
                    $mensaje = 'Tipo no permitido. Use PDF, Word o Excel.';
                    $tipo_mensaje = 'danger';
                } elseif ($_FILES['archivo']['size'] > $max_size) {
                    $mensaje = 'Archivo supera 10 MB.';
                    $tipo_mensaje = 'danger';
                } else {
                    $archivo_nombre = uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['archivo']['tmp_name'], $upload_dir . $archivo_nombre)) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO documentos_interes (nombre, dependencia_id, modulo_id, descripcion, archivo, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$nombre, $dependencia_id, $modulo_id, $descripcion ?: null, $archivo_nombre, $_SESSION['usuario_id']]);
                            $mensaje = 'Documento agregado.';
                            $tipo_mensaje = 'success';
                        } catch (Exception $e) {
                            @unlink($upload_dir . $archivo_nombre);
                            $mensaje = 'Error al guardar.';
                            $tipo_mensaje = 'danger';
                        }
                    } else {
                        $mensaje = 'Error al subir el archivo.';
                        $tipo_mensaje = 'danger';
                    }
                }
            }
        }
    } elseif ($accion === 'editar_documento') {
        $doc_id = (int)$_POST['doc_id'];
        $nombre = trim(sanitizar($_POST['nombre'] ?? ''));
        $modulo_id = !empty($_POST['modulo_id']) ? (int)$_POST['modulo_id'] : null;
        $descripcion = trim($_POST['descripcion'] ?? '');
        if ($nombre === '') {
            $mensaje = 'Nombre obligatorio.';
            $tipo_mensaje = 'danger';
        } else {
            $stmt = $pdo->prepare("SELECT id, archivo FROM documentos_interes WHERE id = ? AND dependencia_id = ?");
            $stmt->execute([$doc_id, $dependencia_id]);
            $doc_actual = $stmt->fetch();
            if (!$doc_actual) {
                $mensaje = 'Documento no encontrado.';
                $tipo_mensaje = 'danger';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM documentos_interes WHERE dependencia_id = ? AND nombre = ? AND id != ?");
                $stmt->execute([$dependencia_id, $nombre, $doc_id]);
                if ($stmt->fetch()) {
                    $mensaje = 'Ya existe otro documento con ese nombre en esta dependencia.';
                    $tipo_mensaje = 'danger';
                } else {
                    $archivo_final = $doc_actual['archivo'];
                    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $_FILES['archivo']['tmp_name']);
                        finfo_close($finfo);
                        if (in_array($ext, $ext_permitidas) && in_array($mime, $mimes_permitidos) && $_FILES['archivo']['size'] <= $max_size) {
                            $archivo_nuevo = uniqid() . '.' . $ext;
                            if (move_uploaded_file($_FILES['archivo']['tmp_name'], $upload_dir . $archivo_nuevo)) {
                                if (file_exists($upload_dir . $doc_actual['archivo'])) @unlink($upload_dir . $doc_actual['archivo']);
                                $archivo_final = $archivo_nuevo;
                            }
                        }
                    }
                    $stmt = $pdo->prepare("UPDATE documentos_interes SET nombre = ?, modulo_id = ?, descripcion = ?, archivo = ?, fecha_actualizacion = NOW() WHERE id = ? AND dependencia_id = ?");
                    $stmt->execute([$nombre, $modulo_id, $descripcion ?: null, $archivo_final, $doc_id, $dependencia_id]);
                    $mensaje = 'Documento actualizado.';
                    $tipo_mensaje = 'success';
                }
            }
        }
    } elseif ($accion === 'eliminar_documento') {
        $doc_id = (int)$_POST['doc_id'];
        $stmt = $pdo->prepare("SELECT archivo FROM documentos_interes WHERE id = ? AND dependencia_id = ?");
        $stmt->execute([$doc_id, $dependencia_id]);
        $row = $stmt->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM documentos_interes WHERE id = ? AND dependencia_id = ?");
            $stmt->execute([$doc_id, $dependencia_id]);
            if ($row['archivo'] && file_exists($upload_dir . $row['archivo'])) @unlink($upload_dir . $row['archivo']);
            $mensaje = 'Documento eliminado.';
            $tipo_mensaje = 'success';
        }
    }
}

// Módulos de la dependencia
$modulos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos_documentos_interes WHERE dependencia_id = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$dependencia_id]);
    $modulos = $stmt->fetchAll();
} catch (Exception $e) {}

// Documentos de la dependencia
$stmt = $pdo->prepare("
    SELECT doc.*, u.nombre_completo AS usuario_nombre
    FROM documentos_interes doc
    LEFT JOIN usuarios u ON u.id = doc.usuario_id
    WHERE doc.dependencia_id = ?
    ORDER BY COALESCE(doc.modulo_id, 999999) ASC, doc.fecha_carga ASC
");
$stmt->execute([$dependencia_id]);
$documentos = $stmt->fetchAll();

$docs_por_modulo = [];
$docs_sin_modulo = [];
foreach ($documentos as $doc) {
    $mid = isset($doc['modulo_id']) ? $doc['modulo_id'] : null;
    if ($mid) {
        if (!isset($docs_por_modulo[$mid])) $docs_por_modulo[$mid] = [];
        $docs_por_modulo[$mid][] = $doc;
    } else {
        $docs_sin_modulo[] = $doc;
    }
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-folder2-open me-2"></i><?php echo htmlspecialchars($dependencia['nombre']); ?></h2>
            <p class="text-muted mb-0">Módulos y documentos de esta dependencia</p>
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
            <!-- Módulos -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos de la dependencia</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalModulo">
                        <i class="bi bi-plus-circle me-1"></i>Nuevo módulo
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Cree módulos para organizar los documentos (ej: Formatos, Plantillas, Procedimientos, Manuales, Circulares).</p>
                    <?php if (empty($modulos)): ?>
                        <p class="text-muted mb-0">No hay módulos. Cree uno para organizar los documentos.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($modulos as $mod):
                                $cant = isset($docs_por_modulo[$mod['id']]) ? count($docs_por_modulo[$mod['id']]) : 0;
                            ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($mod['titulo']); ?></strong>
                                        <span class="badge bg-secondary ms-2"><?php echo $cant; ?> doc.</span>
                                        <?php if (!empty($mod['descripcion'])): ?>
                                            <p class="mb-0 small text-muted"><?php echo htmlspecialchars($mod['descripcion']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editarModulo(<?php echo htmlspecialchars(json_encode($mod)); ?>)"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este módulo? Los documentos quedarán sin módulo.');">
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

            <!-- Documentos por módulo -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-file-earmark me-2"></i>Documentos por módulo</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalDocumento">
                        <i class="bi bi-plus-circle me-1"></i>Agregar documento
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($documentos)): ?>
                        <p class="text-muted">No hay documentos. Cree módulos y use «Agregar documento» (puede asignar «Sin módulo» si aún no hay módulos).</p>
                    <?php else: ?>
                        <?php foreach ($modulos as $mod):
                            $lista = isset($docs_por_modulo[$mod['id']]) ? $docs_por_modulo[$mod['id']] : [];
                        ?>
                            <h6 class="mt-3 mb-2 text-primary"><i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?></h6>
                            <?php if (empty($lista)): ?>
                                <p class="small text-muted">Sin documentos en este módulo.</p>
                            <?php else: ?>
                                <div class="list-group mb-3">
                                    <?php foreach ($lista as $doc): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <i class="bi bi-file-earmark-arrow-down me-2"></i>
                                                    <strong><?php echo htmlspecialchars($doc['nombre']); ?></strong>
                                                    <?php if (!empty($doc['descripcion'])): ?>
                                                        <p class="mb-1 small text-muted"><?php echo htmlspecialchars(mb_substr($doc['descripcion'], 0, 80)); ?><?php echo mb_strlen($doc['descripcion']) > 80 ? '…' : ''; ?></p>
                                                    <?php endif; ?>
                                                    <a href="<?php echo BASE_URL; ?>uploads/documentos_interes/<?php echo htmlspecialchars($doc['archivo']); ?>" class="btn btn-sm btn-outline-primary" download>Descargar</a>
                                                </div>
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-warning" onclick='editarDoc(<?php echo json_encode($doc); ?>)'><i class="bi bi-pencil"></i></button>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este documento?');">
                                                        <input type="hidden" name="accion" value="eliminar_documento">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!empty($docs_sin_modulo)): ?>
                            <h6 class="mt-3 mb-2 text-muted"><i class="bi bi-file-earmark me-1"></i>Sin módulo</h6>
                            <div class="list-group mb-3">
                                <?php foreach ($docs_sin_modulo as $doc): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <i class="bi bi-file-earmark me-2"></i>
                                                <strong><?php echo htmlspecialchars($doc['nombre']); ?></strong>
                                                <a href="<?php echo BASE_URL; ?>uploads/documentos_interes/<?php echo htmlspecialchars($doc['archivo']); ?>" class="btn btn-sm btn-outline-primary ms-2" download>Descargar</a>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-warning" onclick='editarDoc(<?php echo json_encode($doc); ?>)'><i class="bi bi-pencil"></i></button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?');">
                                                <input type="hidden" name="accion" value="eliminar_documento">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="mb-0">Información</h5></div>
                <div class="card-body small">
                    <p>Tipos permitidos: PDF, Word (.doc, .docx), Excel (.xls, .xlsx). Máx. 10 MB.</p>
                    <p>El nombre del documento debe ser único dentro de esta dependencia.</p>
                    <p class="mb-0">Al editar puede reemplazar el archivo (se actualiza la fecha de modificación).</p>
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
                        <input type="text" class="form-control" name="titulo_modulo" id="tituloModulo" required placeholder="Ej: Formatos de permisos, Plantillas oficiales">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion_modulo" id="descripcionModulo" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden</label>
                        <input type="number" class="form-control" name="orden_modulo" id="ordenModulo" value="0" min="0">
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

<!-- Modal Agregar Documento -->
<div class="modal fade" id="modalDocumento" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="agregar_documento">
                    <div class="mb-3">
                        <label class="form-label">Módulo</label>
                        <select class="form-select" name="modulo_id">
                            <option value="">Sin módulo</option>
                            <?php foreach ($modulos as $mod): ?>
                                <option value="<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['titulo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre del documento *</label>
                        <input type="text" class="form-control" name="nombre" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo * (PDF, Word, Excel. Máx. 10 MB)</label>
                        <input type="file" class="form-control" name="archivo" accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden</label>
                        <input type="number" class="form-control" name="orden" value="0" min="0">
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

<!-- Modal Editar Documento -->
<div class="modal fade" id="modalEditarDoc" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="formEditarDoc">
                <div class="modal-header">
                    <h5 class="modal-title">Editar documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="editar_documento">
                    <input type="hidden" name="doc_id" id="editarDocId">
                    <div class="mb-3">
                        <label class="form-label">Módulo</label>
                        <select class="form-select" name="modulo_id" id="editarDocModulo">
                            <option value="">Sin módulo</option>
                            <?php foreach ($modulos as $mod): ?>
                                <option value="<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['titulo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre del documento *</label>
                        <input type="text" class="form-control" name="nombre" id="editarDocNombre" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" id="editarDocDescripcion" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reemplazar archivo (opcional)</label>
                        <input type="file" class="form-control" name="archivo" accept=".pdf,.doc,.docx,.xls,.xlsx">
                        <small class="text-muted">Dejar vacío para mantener el archivo actual.</small>
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

<script>
function editarModulo(mod) {
    document.getElementById('modalModuloTitle').textContent = 'Editar módulo';
    document.getElementById('accionModulo').value = 'editar_modulo';
    document.getElementById('moduloId').value = mod.id;
    document.getElementById('tituloModulo').value = mod.titulo;
    document.getElementById('descripcionModulo').value = mod.descripcion || '';
    document.getElementById('ordenModulo').value = mod.orden || 0;
    new bootstrap.Modal(document.getElementById('modalModulo')).show();
}
document.getElementById('modalModulo').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formModulo').reset();
    document.getElementById('modalModuloTitle').textContent = 'Nuevo módulo';
    document.getElementById('accionModulo').value = 'agregar_modulo';
    document.getElementById('moduloId').value = '';
});

function editarDoc(doc) {
    document.getElementById('editarDocId').value = doc.id;
    document.getElementById('editarDocNombre').value = doc.nombre;
    document.getElementById('editarDocDescripcion').value = doc.descripcion || '';
    document.getElementById('editarDocModulo').value = doc.modulo_id || '';
    new bootstrap.Modal(document.getElementById('modalEditarDoc')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
