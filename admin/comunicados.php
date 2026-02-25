<?php
require_once '../config/config.php';
requerirPermiso('gestionar_comunicados');

$page_title = 'Gestión de Comunicados';
$additional_css = ['assets/css/admin.css'];

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear_comunicado' || $accion === 'editar_comunicado') {
        $titulo = sanitizar($_POST['titulo'] ?? '');
        $contenido = trim($_POST['contenido'] ?? '');
        $tipo = $_POST['tipo'] ?? 'noticia';
        $destacado = isset($_POST['destacado']) ? 1 : 0;
        $activo = isset($_POST['activo']) ? 1 : 0;
        $fecha_expiracion = !empty($_POST['fecha_expiracion']) ? $_POST['fecha_expiracion'] : null;
        $id = $accion === 'editar_comunicado' ? (int)$_POST['id'] : null;
        
        $imagen = null;
        $error_subida_archivo = false;
        $extensiones_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            $es_documento = ($ext === 'pdf');
            $validacion = validarTamanoSubida((int)$_FILES['imagen']['size'], $es_documento ? 'documento' : 'imagen');
            if (!$validacion['valido']) {
                $mensaje = $validacion['mensaje'];
                $tipo_mensaje = 'danger';
                $error_subida_archivo = true;
            } elseif (in_array($ext, $extensiones_permitidas)) {
                $upload_dir = BASE_PATH . 'uploads/comunicados/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $imagen_nombre = uniqid() . '.' . $ext;
                $imagen_path = $upload_dir . $imagen_nombre;
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $imagen_path)) {
                    $imagen = 'comunicados/' . $imagen_nombre;
                }
            }
        }
        
        if (!$error_subida_archivo) {
        try {
            if ($accion === 'crear_comunicado') {
                $stmt = $pdo->prepare("
                    INSERT INTO comunicados (titulo, contenido, tipo, imagen, destacado, activo, fecha_expiracion, usuario_creador)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$titulo, $contenido, $tipo, $imagen, $destacado, $activo, $fecha_expiracion, $_SESSION['usuario_id']]);
                registrarLog($_SESSION['usuario_id'], 'Crear comunicado', 'Comunicados', "Título: $titulo");
                $mensaje = 'Comunicado creado exitosamente';
            } else {
                if ($imagen) {
                    $stmt = $pdo->prepare("
                        UPDATE comunicados 
                        SET titulo = ?, contenido = ?, tipo = ?, imagen = ?, destacado = ?, activo = ?, fecha_expiracion = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$titulo, $contenido, $tipo, $imagen, $destacado, $activo, $fecha_expiracion, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE comunicados 
                        SET titulo = ?, contenido = ?, tipo = ?, destacado = ?, activo = ?, fecha_expiracion = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$titulo, $contenido, $tipo, $destacado, $activo, $fecha_expiracion, $id]);
                }
                registrarLog($_SESSION['usuario_id'], 'Editar comunicado', 'Comunicados', "ID: $id");
                $mensaje = 'Comunicado actualizado exitosamente';
            }
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al guardar comunicado: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
        }
    } elseif ($accion === 'eliminar_comunicado') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("UPDATE comunicados SET activo = 0 WHERE id = ?");
            $stmt->execute([$id]);
            registrarLog($_SESSION['usuario_id'], 'Eliminar comunicado', 'Comunicados', "ID: $id");
            $mensaje = 'Comunicado eliminado exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al eliminar comunicado';
            $tipo_mensaje = 'danger';
        }
    }
}

// Paginación: 10 filas por página
$filas_por_pagina = 10;
$pagina_actual = isset($_GET['pagina_comunicados']) ? max(1, (int)$_GET['pagina_comunicados']) : 1;
$stmt = $pdo->query("SELECT COUNT(*) as total FROM comunicados");
$total_comunicados = (int)$stmt->fetch()['total'];
$total_paginas = $total_comunicados > 0 ? (int)ceil($total_comunicados / $filas_por_pagina) : 1;
$pagina_actual = min($pagina_actual, $total_paginas);
$offset = ($pagina_actual - 1) * $filas_por_pagina;

$stmt = $pdo->prepare("SELECT * FROM comunicados ORDER BY fecha_publicacion DESC LIMIT ? OFFSET ?");
$stmt->execute([$filas_por_pagina, $offset]);
$todos_comunicados = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-megaphone me-2"></i>Gestión de Comunicados</h2>
        <a href="<?php echo BASE_URL; ?>index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Volver al Dashboard
        </a>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Todos los Comunicados</h5>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalComunicado">
                <i class="bi bi-plus-circle me-1"></i>Nuevo Comunicado
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Destacado</th>
                            <th>Estado</th>
                            <th>Fecha Publicación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($todos_comunicados)): ?>
                            <tr><td colspan="6" class="text-muted text-center">No hay comunicados. Crea uno para que aparezca en el Dashboard.</td></tr>
                        <?php else: ?>
                            <?php foreach ($todos_comunicados as $com): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($com['titulo']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $com['tipo'] === 'importante' ? 'danger' : 
                                                ($com['tipo'] === 'evento' ? 'info' : 'success'); 
                                        ?>"><?php echo ucfirst($com['tipo']); ?></span>
                                    </td>
                                    <td><?php echo $com['destacado'] ? '<i class="bi bi-star-fill text-warning"></i>' : '<i class="bi bi-star text-muted"></i>'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $com['activo'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $com['activo'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($com['fecha_publicacion'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editarComunicado(<?php echo htmlspecialchars(json_encode($com)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('¿Estás seguro de eliminar este comunicado?');">
                                            <input type="hidden" name="accion" value="eliminar_comunicado">
                                            <input type="hidden" name="id" value="<?php echo $com['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_paginas > 1): ?>
                <nav class="d-flex justify-content-center mt-3" aria-label="Paginación de comunicados">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina_comunicados=<?php echo $pagina_actual - 1; ?>">Anterior</a>
                        </li>
                        <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                            <li class="page-item <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina_comunicados=<?php echo $p; ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina_comunicados=<?php echo $pagina_actual + 1; ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Comunicado -->
<div class="modal fade" id="modalComunicado" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="formComunicado">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalComunicadoTitle">Nuevo Comunicado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionComunicado" value="crear_comunicado">
                    <input type="hidden" name="id" id="comunicadoId">
                    <div class="mb-3">
                        <label class="form-label">Título *</label>
                        <input type="text" class="form-control" name="titulo" id="tituloComunicado" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contenido *</label>
                        <textarea class="form-control" name="contenido" id="contenidoComunicado" rows="5" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select class="form-select" name="tipo" id="tipoComunicado" required>
                            <option value="noticia">Noticia</option>
                            <option value="importante">Comunicado Importante</option>
                            <option value="evento">Evento</option>
                            <option value="anuncio">Anuncio</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Imagen o documento PDF (opcional)</label>
                        <input type="file" class="form-control" name="imagen" id="imagenComunicado" accept="image/*,application/pdf">
                        <small class="text-muted">Formatos: JPG, PNG, GIF, WEBP, PDF</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="destacado" id="destacadoComunicado">
                                <label class="form-check-label" for="destacadoComunicado">Destacado</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="activo" id="activoComunicado" checked>
                                <label class="form-check-label" for="activoComunicado">Activo</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fecha de Expiración (opcional)</label>
                        <input type="datetime-local" class="form-control" name="fecha_expiracion" id="fechaExpiracionComunicado">
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
function editarComunicado(comunicado) {
    document.getElementById('modalComunicadoTitle').textContent = 'Editar Comunicado';
    document.getElementById('accionComunicado').value = 'editar_comunicado';
    document.getElementById('comunicadoId').value = comunicado.id;
    document.getElementById('tituloComunicado').value = comunicado.titulo;
    document.getElementById('contenidoComunicado').value = comunicado.contenido;
    document.getElementById('tipoComunicado').value = comunicado.tipo;
    document.getElementById('destacadoComunicado').checked = comunicado.destacado == 1;
    document.getElementById('activoComunicado').checked = comunicado.activo == 1;
    if (comunicado.fecha_expiracion) {
        var fecha = new Date(comunicado.fecha_expiracion);
        document.getElementById('fechaExpiracionComunicado').value = fecha.toISOString().slice(0, 16);
    }
    document.getElementById('imagenComunicado').required = false;
    new bootstrap.Modal(document.getElementById('modalComunicado')).show();
}
document.getElementById('modalComunicado').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formComunicado').reset();
    document.getElementById('modalComunicadoTitle').textContent = 'Nuevo Comunicado';
    document.getElementById('accionComunicado').value = 'crear_comunicado';
    document.getElementById('imagenComunicado').required = false;
});
</script>

<?php require_once '../includes/footer.php'; ?>
