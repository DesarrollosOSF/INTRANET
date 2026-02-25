<?php
require_once '../config/config.php';
requerirPermiso('gestionar_dependencias');

$page_title = 'Gestión de Dependencias';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear') {
        $nombre = sanitizar($_POST['nombre']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        
        try {
            $stmt = $pdo->prepare("INSERT INTO dependencias (nombre, descripcion) VALUES (?, ?)");
            $stmt->execute([$nombre, $descripcion]);
            registrarLog($_SESSION['usuario_id'], 'Crear dependencia', 'Dependencias', "Dependencia: $nombre");
            $mensaje = 'Dependencia creada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al crear dependencia';
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $nombre = sanitizar($_POST['nombre']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("UPDATE dependencias SET nombre = ?, descripcion = ?, activo = ? WHERE id = ?");
            $stmt->execute([$nombre, $descripcion, $activo, $id]);
            registrarLog($_SESSION['usuario_id'], 'Editar dependencia', 'Dependencias', "Dependencia ID: $id");
            $mensaje = 'Dependencia actualizada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al actualizar dependencia';
            $tipo_mensaje = 'danger';
        }
    }
}

$stmt = $pdo->query("SELECT * FROM dependencias ORDER BY nombre");
$dependencias = $stmt->fetchAll();
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-diagram-3 me-2"></i>Gestión de Dependencias</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDependencia">
            <i class="bi bi-plus-circle me-2"></i>Nueva Dependencia
        </button>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dependencias as $dep): ?>
                            <tr>
                                <td><?php echo $dep['id']; ?></td>
                                <td><?php echo htmlspecialchars($dep['nombre']); ?></td>
                                <td><?php echo htmlspecialchars($dep['descripcion'] ?? ''); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $dep['activo'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $dep['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editarDependencia(<?php echo htmlspecialchars(json_encode($dep)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Dependencia -->
<div class="modal fade" id="modalDependencia" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formDependencia">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDependenciaTitle">Nueva Dependencia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionDependencia" value="crear">
                    <input type="hidden" name="id" id="dependenciaId">
                    
                    <div class="mb-3">
                        <label class="form-label">Nombre *</label>
                        <input type="text" class="form-control" name="nombre" id="nombreDependencia" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" id="descripcionDependencia" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3" id="activoDependenciaField" style="display: none;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="activo" id="activoDependencia" checked>
                            <label class="form-check-label" for="activoDependencia">Activo</label>
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
function editarDependencia(dep) {
    document.getElementById('modalDependenciaTitle').textContent = 'Editar Dependencia';
    document.getElementById('accionDependencia').value = 'editar';
    document.getElementById('dependenciaId').value = dep.id;
    document.getElementById('nombreDependencia').value = dep.nombre;
    document.getElementById('descripcionDependencia').value = dep.descripcion || '';
    document.getElementById('activoDependencia').checked = dep.activo == 1;
    document.getElementById('activoDependenciaField').style.display = 'block';
    
    new bootstrap.Modal(document.getElementById('modalDependencia')).show();
}

document.getElementById('modalDependencia').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formDependencia').reset();
    document.getElementById('modalDependenciaTitle').textContent = 'Nueva Dependencia';
    document.getElementById('accionDependencia').value = 'crear';
    document.getElementById('activoDependenciaField').style.display = 'none';
});
</script>

<?php require_once '../includes/footer.php'; ?>
