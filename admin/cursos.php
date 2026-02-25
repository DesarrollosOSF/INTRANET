<?php
require_once '../config/config.php';
requerirPermiso('gestionar_cursos');

$page_title = 'Gestión de Cursos';
$additional_css = ['assets/css/admin.css', 'assets/css/cursos.css'];
$additional_js = ['assets/js/grid-pagination.js'];

require_once '../includes/header.php';

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = sanitizar($_POST['nombre']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $dependencia_id = (int)$_POST['dependencia_id'];
        $activo = isset($_POST['activo']) ? 1 : 0;
        $id = $accion === 'editar' ? (int)$_POST['id'] : null;
        
        // Manejar imagen (máx 2MB)
        $imagen = null;
        $error_subida_imagen = false;
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $validacion = validarTamanoSubida((int)$_FILES['imagen']['size'], 'imagen');
            if (!$validacion['valido']) {
                $mensaje = $validacion['mensaje'];
                $tipo_mensaje = 'danger';
                $error_subida_imagen = true;
            } else {
                $upload_dir = BASE_PATH . 'uploads/cursos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
                $imagen_nombre = uniqid() . '.' . $ext;
                $imagen_path = $upload_dir . $imagen_nombre;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $imagen_path)) {
                    $imagen = 'cursos/' . $imagen_nombre;
                }
            }
        }
        
        if (!$error_subida_imagen) {
        try {
            if ($accion === 'crear') {
                $stmt = $pdo->prepare("
                    INSERT INTO cursos (nombre, descripcion, imagen, dependencia_id, activo)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $descripcion, $imagen, $dependencia_id, $activo]);
                $curso_id = $pdo->lastInsertId();
                registrarLog($_SESSION['usuario_id'], 'Crear curso', 'Cursos', "Curso: $nombre");
                $mensaje = 'Curso creado exitosamente';
            } else {
                if ($imagen) {
                    $stmt = $pdo->prepare("
                        UPDATE cursos 
                        SET nombre = ?, descripcion = ?, imagen = ?, dependencia_id = ?, activo = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $descripcion, $imagen, $dependencia_id, $activo, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE cursos 
                        SET nombre = ?, descripcion = ?, dependencia_id = ?, activo = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $descripcion, $dependencia_id, $activo, $id]);
                }
                registrarLog($_SESSION['usuario_id'], 'Editar curso', 'Cursos', "Curso ID: $id");
                $mensaje = 'Curso actualizado exitosamente';
            }
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
        }
    }
}

$stmt = $pdo->query("
    SELECT c.*, d.nombre as dependencia_nombre
    FROM cursos c
    LEFT JOIN dependencias d ON c.dependencia_id = d.id
    ORDER BY c.fecha_creacion DESC
");
$cursos = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM dependencias WHERE activo = 1 ORDER BY nombre");
$dependencias = $stmt->fetchAll();
?>

<div class="container-fluid dashboard-wrap mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="bi bi-book-half me-2"></i>Gestión de Cursos</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCurso">
            <i class="bi bi-plus-circle me-2"></i>Nuevo Curso
        </button>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="cursos-panel flex-grow-1 d-flex flex-column">
        <div class="cursos-panel-header d-flex justify-content-between align-items-center">
            <span class="cursos-panel-title"><i class="bi bi-collection-play me-2"></i>Cursos</span>
        </div>
        <div class="cursos-panel-content cursos-grid-wrap">
            <?php if (empty($cursos)): ?>
                <p class="text-muted text-center py-5">No hay cursos. Use «Nuevo Curso» para crear uno.</p>
            <?php else: ?>
                <div class="cursos-grid" id="cursosGestionGrid" data-blocks-per-page="6">
                    <?php foreach ($cursos as $index => $curso):
                        $imagen_url = !empty($curso['imagen']) ? UPLOAD_URL . $curso['imagen'] : '';
                        $nombre = htmlspecialchars($curso['nombre']);
                        $descripcion = htmlspecialchars($curso['descripcion'] ?? '');
                        $dependencia = htmlspecialchars($curso['dependencia_nombre'] ?? 'Sin dependencia');
                        $curso_json = htmlspecialchars(json_encode($curso), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="curso-block"
                             data-index="<?php echo $index; ?>"
                             data-curso-id="<?php echo (int)$curso['id']; ?>"
                             data-nombre="<?php echo $nombre; ?>"
                             data-descripcion="<?php echo $descripcion; ?>"
                             data-imagen="<?php echo htmlspecialchars($imagen_url); ?>"
                             data-dependencia="<?php echo $dependencia; ?>"
                             data-activo="<?php echo (int)$curso['activo']; ?>"
                             data-curso-json="<?php echo $curso_json; ?>"
                             role="button" tabindex="0" title="Clic para ver detalle">
                            <div class="curso-block-inner">
                                <?php if ($imagen_url): ?>
                                    <div class="curso-block-preview" style="background-image: url('<?php echo htmlspecialchars($imagen_url); ?>');"></div>
                                <?php else: ?>
                                    <div class="curso-block-preview curso-block-preview-icon">
                                        <i class="bi bi-book"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="curso-block-info">
                                    <strong><?php echo $nombre; ?></strong>
                                    <span><i class="bi bi-diagram-3 me-1"></i><?php echo $dependencia; ?></span>
                                    <span class="badge bg-<?php echo $curso['activo'] ? 'success' : 'secondary'; ?> mt-1" style="font-size: 0.65rem;">
                                        <?php echo $curso['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <nav class="cursos-pagination-wrap mt-3" id="cursosGestionPagination" aria-label="Paginación de cursos"></nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($cursos)): ?>
<!-- Modal fullscreen para detalle del curso (gestión) -->
<div class="curso-fullscreen-overlay" id="cursoGestionFullscreen" aria-hidden="true" aria-modal="true" role="dialog" aria-labelledby="cursoGestionFullscreenTitulo" inert>
    <div class="curso-fullscreen-backdrop"></div>
    <div class="curso-fullscreen-content">
        <button type="button" class="curso-fullscreen-close" id="cursoGestionFullscreenClose" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="curso-fullscreen-header">
            <h4 id="cursoGestionFullscreenTitulo"></h4>
            <span id="cursoGestionFullscreenDependencia"></span>
        </div>
        <div class="curso-fullscreen-body" id="cursoGestionFullscreenBody"></div>
        <div class="curso-fullscreen-footer" id="cursoGestionFullscreenFooter"></div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Curso (crear/editar) -->
<div class="modal fade" id="modalCurso" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="formCurso">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCursoTitle">Nuevo Curso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionCurso" value="crear">
                    <input type="hidden" name="id" id="cursoId">
                    
                    <div class="mb-3">
                        <label class="form-label">Nombre del Curso *</label>
                        <input type="text" class="form-control" name="nombre" id="nombreCurso" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" id="descripcionCurso" rows="4"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Dependencia *</label>
                        <select class="form-select" name="dependencia_id" id="dependenciaCurso" required>
                            <option value="">Seleccione una dependencia</option>
                            <?php foreach ($dependencias as $dep): ?>
                                <option value="<?php echo $dep['id']; ?>"><?php echo htmlspecialchars($dep['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Imagen Representativa</label>
                        <input type="file" class="form-control" name="imagen" id="imagenCurso" accept="image/*">
                        <small class="text-muted">Máx 2MB. Formatos: JPG, PNG, GIF, WEBP</small>
                        <div id="imagenPreview" class="mt-2"></div>
                    </div>
                    
                    <div class="mb-3" id="activoCursoField" style="display: none;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="activo" id="activoCurso" checked>
                            <label class="form-check-label" for="activoCurso">Curso Activo</label>
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

<script>
function editarCurso(curso) {
    document.getElementById('modalCursoTitle').textContent = 'Editar Curso';
    document.getElementById('accionCurso').value = 'editar';
    document.getElementById('cursoId').value = curso.id;
    document.getElementById('nombreCurso').value = curso.nombre;
    document.getElementById('descripcionCurso').value = curso.descripcion || '';
    document.getElementById('dependenciaCurso').value = curso.dependencia_id;
    document.getElementById('activoCurso').checked = curso.activo == 1;
    document.getElementById('activoCursoField').style.display = 'block';
    
    if (curso.imagen) {
        document.getElementById('imagenPreview').innerHTML = 
            '<img src="<?php echo UPLOAD_URL; ?>' + curso.imagen + '" class="img-thumbnail" style="max-height: 150px;">';
    } else {
        document.getElementById('imagenPreview').innerHTML = '';
    }
    
    var modal = document.getElementById('modalCurso');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        new bootstrap.Modal(modal).show();
    }
}

document.getElementById('imagenCurso').addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        var reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('imagenPreview').innerHTML = 
                '<img src="' + ev.target.result + '" class="img-thumbnail" style="max-height: 150px;">';
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

document.getElementById('modalCurso').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formCurso').reset();
    document.getElementById('modalCursoTitle').textContent = 'Nuevo Curso';
    document.getElementById('accionCurso').value = 'crear';
    document.getElementById('imagenPreview').innerHTML = '';
    document.getElementById('activoCursoField').style.display = 'none';
});

<?php if (!empty($cursos)): ?>
document.addEventListener('DOMContentLoaded', function() {
    var grid = document.getElementById('cursosGestionGrid');
    var overlay = document.getElementById('cursoGestionFullscreen');
    var closeBtn = document.getElementById('cursoGestionFullscreenClose');
    var backdrop = overlay ? overlay.querySelector('.curso-fullscreen-backdrop') : null;
    var lastFocusedBlock = null;

    function openFullscreen(block) {
        if (!overlay) return;
        lastFocusedBlock = block;
        var nombre = block.getAttribute('data-nombre') || '';
        var descripcion = block.getAttribute('data-descripcion') || '';
        var imagen = block.getAttribute('data-imagen') || '';
        var dependencia = block.getAttribute('data-dependencia') || '';
        var cursoId = block.getAttribute('data-curso-id') || '';
        var activo = block.getAttribute('data-activo') === '1';

        document.getElementById('cursoGestionFullscreenTitulo').textContent = nombre;
        document.getElementById('cursoGestionFullscreenDependencia').innerHTML = '<i class="bi bi-diagram-3 me-1"></i>' + dependencia.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var body = document.getElementById('cursoGestionFullscreenBody');
        var footer = document.getElementById('cursoGestionFullscreenFooter');

        if (imagen) {
            body.innerHTML = '<img src="' + imagen.replace(/"/g, '&quot;') + '" alt="' + nombre.replace(/"/g, '&quot;') + '" class="curso-fullscreen-imagen">';
        } else {
            body.innerHTML = '<div class="curso-fullscreen-sin-imagen"><i class="bi bi-book"></i></div>';
        }
        body.innerHTML += '<div class="curso-fullscreen-descripcion"><p>' + (descripcion || 'Sin descripción.').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</p></div>';

        footer.innerHTML = '<span class="badge bg-' + (activo ? 'success' : 'secondary') + ' me-2">' + (activo ? 'Activo' : 'Inactivo') + '</span>' +
            '<a href="cursos_detalle.php?id=' + cursoId + '" class="btn btn-primary btn-sm me-2"><i class="bi bi-gear me-1"></i>Gestionar</a>' +
            '<button type="button" class="btn btn-warning btn-sm" id="btnEditarDesdeFullscreen"><i class="bi bi-pencil me-1"></i>Editar</button>';

        document.getElementById('btnEditarDesdeFullscreen').addEventListener('click', function() {
            var json = lastFocusedBlock.getAttribute('data-curso-json');
            if (json) {
                try {
                    var curso = JSON.parse(json);
                    closeFullscreen();
                    editarCurso(curso);
                } catch (e) {}
            }
        });

        overlay.removeAttribute('inert');
        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { if (closeBtn) closeBtn.focus(); }, 50);
    }

    function closeFullscreen() {
        if (!overlay) return;
        if (lastFocusedBlock && lastFocusedBlock.offsetParent !== null) {
            lastFocusedBlock.focus();
        }
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('inert', '');
        document.body.style.overflow = '';
    }

    if (grid) {
        grid.querySelectorAll('.curso-block').forEach(function(block) {
            block.addEventListener('click', function(e) {
                if (e.target.closest('a') || e.target.closest('button')) return;
                openFullscreen(block);
            });
            block.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFullscreen(block); } });
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', closeFullscreen);
    if (backdrop) backdrop.addEventListener('click', closeFullscreen);
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeFullscreen(); });

    initGridPagination({ grid: '#cursosGestionGrid', paginationWrap: '#cursosGestionPagination' });
});
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
