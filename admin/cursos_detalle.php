<?php
require_once '../config/config.php';
requerirPermiso('gestionar_cursos');

$page_title = 'Detalle del Curso';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

$pdo = getDBConnection();
$curso_id = (int)($_GET['id'] ?? 0);

if (!$curso_id) {
    header('Location: cursos.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.*, d.nombre as dependencia_nombre
    FROM cursos c
    LEFT JOIN dependencias d ON c.dependencia_id = d.id
    WHERE c.id = ?
");
$stmt->execute([$curso_id]);
$curso = $stmt->fetch();

if (!$curso) {
    header('Location: cursos.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'agregar_modulo') {
        $titulo = sanitizar($_POST['titulo_modulo'] ?? '');
        $descripcion = sanitizar($_POST['descripcion_modulo'] ?? '');
        $orden = (int)($_POST['orden_modulo'] ?? 0);
        if ($titulo !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, descripcion, orden) VALUES (?, ?, ?, ?)");
                $stmt->execute([$curso_id, $titulo, $descripcion, $orden]);
                registrarLog($_SESSION['usuario_id'], 'Agregar módulo', 'Cursos', "Curso ID: $curso_id");
                $mensaje = 'Módulo creado exitosamente';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error al crear módulo. Asegúrese de haber ejecutado la migración de módulos en la base de datos.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'editar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $titulo = sanitizar($_POST['titulo_modulo'] ?? '');
        $descripcion = sanitizar($_POST['descripcion_modulo'] ?? '');
        $orden = (int)($_POST['orden_modulo'] ?? 0);
        if ($titulo !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, descripcion = ?, orden = ? WHERE id = ? AND curso_id = ?");
                $stmt->execute([$titulo, $descripcion, $orden, $modulo_id, $curso_id]);
                registrarLog($_SESSION['usuario_id'], 'Editar módulo', 'Cursos', "Módulo ID: $modulo_id");
                $mensaje = 'Módulo actualizado';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error al actualizar módulo';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'eliminar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        try {
            $stmt = $pdo->prepare("UPDATE materiales SET modulo_id = NULL WHERE modulo_id = ?");
            $stmt->execute([$modulo_id]);
            $stmt = $pdo->prepare("DELETE FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt->execute([$modulo_id, $curso_id]);
            registrarLog($_SESSION['usuario_id'], 'Eliminar módulo', 'Cursos', "Módulo ID: $modulo_id");
            $mensaje = 'Módulo eliminado. Los materiales quedan sin módulo.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al eliminar módulo';
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'agregar_material') {
        $modulo_id = !empty($_POST['modulo_id']) ? (int)$_POST['modulo_id'] : null;
        $tipo = $_POST['tipo'];
        $titulo = sanitizar($_POST['titulo']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $orden = (int)($_POST['orden'] ?? 0);
        
        $archivo = null;
        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $es_imagen = ($tipo === 'imagen');
            $validacion = validarTamanoSubida((int)$_FILES['archivo']['size'], $es_imagen ? 'imagen' : 'documento');
            if (!$validacion['valido']) {
                $mensaje = $validacion['mensaje'];
                $tipo_mensaje = 'danger';
            } else {
                $upload_dir = BASE_PATH . 'uploads/materiales/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
                $archivo_nombre = uniqid() . '.' . $ext;
                $archivo_path = $upload_dir . $archivo_nombre;
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $archivo_path)) {
                    $archivo = 'materiales/' . $archivo_nombre;
                }
            }
        }
        
        if ($archivo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO materiales (curso_id, modulo_id, tipo, titulo, descripcion, archivo, tiempo_minimo, orden)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?)
                ");
                $stmt->execute([$curso_id, $modulo_id, $tipo, $titulo, $descripcion, $archivo, $orden]);
                registrarLog($_SESSION['usuario_id'], 'Agregar material', 'Cursos', "Curso ID: $curso_id");
                $mensaje = 'Material agregado exitosamente';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                $mensaje = 'Error al agregar material. Si falta la columna modulo_id, ejecute la migración de módulos en la base de datos.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'eliminar_material') {
        $material_id = (int)$_POST['material_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM materiales WHERE id = ?");
            $stmt->execute([$material_id]);
            registrarLog($_SESSION['usuario_id'], 'Eliminar material', 'Cursos', "Material ID: $material_id");
            $mensaje = 'Material eliminado exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al eliminar material';
            $tipo_mensaje = 'danger';
        }
    }
}

// Obtener módulos del curso
$modulos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$curso_id]);
    $modulos = $stmt->fetchAll();
} catch (Exception $e) {
    // Tabla modulos no existe: sugerir migración
}

// Obtener materiales (con modulo_id si existe la columna)
$materiales = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM materiales 
        WHERE curso_id = ? 
        ORDER BY COALESCE(modulo_id, 999999) ASC, orden ASC, fecha_creacion ASC
    ");
    $stmt->execute([$curso_id]);
    $materiales = $stmt->fetchAll();
} catch (Exception $e) {
    $stmt = $pdo->prepare("SELECT * FROM materiales WHERE curso_id = ? ORDER BY orden ASC, fecha_creacion ASC");
    $stmt->execute([$curso_id]);
    $materiales = $stmt->fetchAll();
}

// Agrupar materiales por módulo para la vista
$materiales_por_modulo = [];
$materiales_sin_modulo = [];
foreach ($materiales as $m) {
    $mid = isset($m['modulo_id']) ? $m['modulo_id'] : null;
    if ($mid) {
        if (!isset($materiales_por_modulo[$mid])) $materiales_por_modulo[$mid] = [];
        $materiales_por_modulo[$mid][] = $m;
    } else {
        $materiales_sin_modulo[] = $m;
    }
}

// Obtener evaluación
$stmt = $pdo->prepare("SELECT * FROM evaluaciones WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$evaluacion = $stmt->fetch();
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-book-half me-2"></i><?php echo htmlspecialchars($curso['nombre']); ?></h2>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($curso['dependencia_nombre'] ?? 'Sin dependencia'); ?></p>
        </div>
        <a href="cursos.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>Volver
        </a>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-8">
            <!-- Módulos del curso -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos del curso</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalModulo">
                        <i class="bi bi-plus-circle me-1"></i>Nuevo Módulo
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Estructura el curso en módulos o lecciones. Los materiales se agregan dentro de cada módulo. La evaluación final se habilita cuando el usuario complete todos los materiales.</p>
                    <?php if (empty($modulos)): ?>
                        <p class="text-muted mb-0">No hay módulos. Cree al menos uno para organizar el contenido (ej: Módulo 1, Módulo 2...).</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($modulos as $mod): 
                                $cant = isset($materiales_por_modulo[$mod['id']]) ? count($materiales_por_modulo[$mod['id']]) : 0;
                            ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($mod['titulo']); ?></strong>
                                        <span class="badge bg-secondary ms-2"><?php echo $cant; ?> material<?php echo $cant !== 1 ? 'es' : ''; ?></span>
                                        <?php if (!empty($mod['descripcion'])): ?>
                                            <p class="mb-0 small text-muted"><?php echo htmlspecialchars($mod['descripcion']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editarModulo(<?php echo htmlspecialchars(json_encode($mod)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este módulo? Los materiales quedarán sin módulo.');">
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
            
            <!-- Materiales por módulo -->
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-file-earmark me-2"></i>Materiales por módulo</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalMaterial" <?php echo empty($modulos) ? 'disabled title="Cree al menos un módulo"' : ''; ?>>
                        <i class="bi bi-plus-circle me-1"></i>Agregar Material
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($materiales) && empty($modulos)): ?>
                        <p class="text-muted">Cree primero módulos y luego agregue materiales a cada uno.</p>
                    <?php elseif (empty($materiales)): ?>
                        <p class="text-muted">No hay materiales. Use «Agregar Material» y seleccione el módulo.</p>
                    <?php else: ?>
                        <?php foreach ($modulos as $mod): 
                            $mat_list = isset($materiales_por_modulo[$mod['id']]) ? $materiales_por_modulo[$mod['id']] : [];
                        ?>
                            <h6 class="mt-3 mb-2 text-primary"><i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?></h6>
                            <?php if (empty($mat_list)): ?>
                                <p class="small text-muted">Sin materiales en este módulo.</p>
                            <?php else: ?>
                                <div class="list-group mb-3">
                                    <?php foreach ($mat_list as $material): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <i class="bi bi-<?php echo $material['tipo'] === 'video' ? 'play-circle' : ($material['tipo'] === 'pdf' ? 'file-pdf' : 'image'); ?> me-2"></i>
                                                    <strong><?php echo htmlspecialchars($material['titulo']); ?></strong>
                                                    <?php if (!empty($material['descripcion'])): ?>
                                                        <p class="mb-1 small text-muted"><?php echo htmlspecialchars($material['descripcion']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este material?');">
                                                    <input type="hidden" name="accion" value="eliminar_material">
                                                    <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!empty($materiales_sin_modulo)): ?>
                            <h6 class="mt-3 mb-2 text-muted"><i class="bi bi-file-earmark me-1"></i>Sin módulo asignado</h6>
                            <div class="list-group mb-3">
                                <?php foreach ($materiales_sin_modulo as $material): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <i class="bi bi-<?php echo $material['tipo'] === 'video' ? 'play-circle' : ($material['tipo'] === 'pdf' ? 'file-pdf' : 'image'); ?> me-2"></i>
                                                <strong><?php echo htmlspecialchars($material['titulo']); ?></strong>
                                                <form method="POST" class="d-inline ms-2" onsubmit="return confirm('¿Eliminar este material?');">
                                                    <input type="hidden" name="accion" value="eliminar_material">
                                                    <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </div>
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
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Evaluación</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Se habilita cuando el usuario complete el 100% de los materiales de todos los módulos.</p>
                    <?php if ($evaluacion): ?>
                        <p><strong>Nombre:</strong> <?php echo htmlspecialchars($evaluacion['nombre']); ?></p>
                        <p><strong>Puntaje mínimo:</strong> <?php echo $evaluacion['puntaje_minimo']; ?>%</p>
                        <p><strong>Intentos permitidos:</strong> <?php echo $evaluacion['numero_intentos']; ?></p>
                        <a href="evaluaciones.php?curso_id=<?php echo $curso_id; ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i>Gestionar Evaluación
                        </a>
                    <?php else: ?>
                        <p class="text-muted">No hay evaluación configurada</p>
                        <a href="evaluaciones.php?curso_id=<?php echo $curso_id; ?>&accion=crear" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>Crear Evaluación
                        </a>
                    <?php endif; ?>
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
                    <h5 class="modal-title" id="modalModuloTitle">Nuevo Módulo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionModulo" value="agregar_modulo">
                    <input type="hidden" name="modulo_id" id="moduloId">
                    <div class="mb-3">
                        <label class="form-label">Título del módulo *</label>
                        <input type="text" class="form-control" name="titulo_modulo" id="tituloModulo" required placeholder="Ej: Módulo 1 - Introducción">
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

<!-- Modal Material -->
<div class="modal fade" id="modalMaterial" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="agregar_material">
                    <div class="mb-3">
                        <label class="form-label">Módulo *</label>
                        <select class="form-select" name="modulo_id" required>
                            <option value="">Seleccione un módulo</option>
                            <?php foreach ($modulos as $mod): ?>
                                <option value="<?php echo $mod['id']; ?>"><?php echo htmlspecialchars($mod['titulo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo de Material *</label>
                        <select class="form-select" name="tipo" id="tipoMaterial" required>
                            <option value="">Seleccione...</option>
                            <option value="video">Video</option>
                            <option value="pdf">Documento PDF</option>
                            <option value="imagen">Imagen</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo *</label>
                        <input type="file" class="form-control" name="archivo" id="archivoMaterial" required>
                        <small class="text-muted" id="archivoHelp"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden dentro del módulo</label>
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

<script>
function editarModulo(mod) {
    document.getElementById('modalModuloTitle').textContent = 'Editar Módulo';
    document.getElementById('accionModulo').value = 'editar_modulo';
    document.getElementById('moduloId').value = mod.id;
    document.getElementById('tituloModulo').value = mod.titulo;
    document.getElementById('descripcionModulo').value = mod.descripcion || '';
    document.getElementById('ordenModulo').value = mod.orden || 0;
    var modal = document.getElementById('modalModulo');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) new bootstrap.Modal(modal).show();
}
document.getElementById('modalModulo').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formModulo').reset();
    document.getElementById('modalModuloTitle').textContent = 'Nuevo Módulo';
    document.getElementById('accionModulo').value = 'agregar_modulo';
    document.getElementById('moduloId').value = '';
});

document.getElementById('tipoMaterial').addEventListener('change', function() {
    var tipo = this.value;
    var archivoInput = document.getElementById('archivoMaterial');
    var helpText = document.getElementById('archivoHelp');
    if (tipo === 'video') { archivoInput.setAttribute('accept', 'video/*'); helpText.textContent = 'Formatos: MP4, WebM, OGG'; }
    else if (tipo === 'pdf') { archivoInput.setAttribute('accept', 'application/pdf'); helpText.textContent = 'Formato: PDF'; }
    else if (tipo === 'imagen') { archivoInput.setAttribute('accept', 'image/*'); helpText.textContent = 'Formatos: JPG, PNG, GIF, WEBP'; }
    else { archivoInput.removeAttribute('accept'); helpText.textContent = ''; }
});
</script>

<?php require_once '../includes/footer.php'; ?>
