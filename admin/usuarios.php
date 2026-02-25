<?php
require_once '../config/config.php';
requerirPermiso('gestionar_usuarios');

$page_title = 'Gestión de Usuarios';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear') {
        $nombre = sanitizar($_POST['nombre_completo']);
        $email = sanitizar($_POST['email']);
        $password = $_POST['password'];
        $rol_id = (int)$_POST['rol_id'];
        $dependencia_id = !empty($_POST['dependencia_id']) ? (int)$_POST['dependencia_id'] : null;
        
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre_completo, email, password, rol_id, dependencia_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $email, $password_hash, $rol_id, $dependencia_id]);
            
            $usuario_id = $pdo->lastInsertId();
            
            // Asignar perfil según rol: 1=Super Admin, 2=Colaborador/Usuario básico, 3=Administrador
            $perfil_id = $rol_id;
            $stmt = $pdo->prepare("INSERT INTO usuario_perfiles (usuario_id, perfil_id) VALUES (?, ?)");
            $stmt->execute([$usuario_id, $perfil_id]);
            
            registrarLog($_SESSION['usuario_id'], 'Crear usuario', 'Usuarios', "Usuario: $email");
            $mensaje = 'Usuario creado exitosamente';
            $tipo_mensaje = 'success';
        } catch (PDOException $e) {
            $mensaje = 'Error al crear usuario: ' . ($e->getCode() == 23000 ? 'El email ya existe' : $e->getMessage());
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $nombre = sanitizar($_POST['nombre_completo']);
        $email = sanitizar($_POST['email']);
        $rol_id = (int)$_POST['rol_id'];
        $dependencia_id = !empty($_POST['dependencia_id']) ? (int)$_POST['dependencia_id'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                UPDATE usuarios 
                SET nombre_completo = ?, email = ?, rol_id = ?, dependencia_id = ?, activo = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $email, $rol_id, $dependencia_id, $activo, $id]);
            
            // Actualizar perfil según rol: 1=Super Admin, 2=Colaborador, 3=Administrador
            $perfil_id = $rol_id;
            $stmt = $pdo->prepare("DELETE FROM usuario_perfiles WHERE usuario_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("INSERT INTO usuario_perfiles (usuario_id, perfil_id) VALUES (?, ?)");
            $stmt->execute([$id, $perfil_id]);
            
            registrarLog($_SESSION['usuario_id'], 'Editar usuario', 'Usuarios', "Usuario ID: $id");
            $mensaje = 'Usuario actualizado exitosamente';
            $tipo_mensaje = 'success';
        } catch (PDOException $e) {
            $mensaje = 'Error al actualizar usuario: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'cambiar_password') {
        $id = (int)$_POST['id'];
        $password = $_POST['password'];
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt->execute([$password_hash, $id]);
            registrarLog($_SESSION['usuario_id'], 'Cambiar contraseña', 'Usuarios', "Usuario ID: $id");
            $mensaje = 'Contraseña actualizada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al actualizar contraseña';
            $tipo_mensaje = 'danger';
        }
    }
}

// Paginación: 10 filas por página
$filas_por_pagina = 10;
$pagina_actual = isset($_GET['pagina_usuarios']) ? max(1, (int)$_GET['pagina_usuarios']) : 1;
$stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
$total_usuarios = (int)$stmt->fetch()['total'];
$total_paginas = $total_usuarios > 0 ? (int)ceil($total_usuarios / $filas_por_pagina) : 1;
$pagina_actual = min($pagina_actual, $total_paginas);
$offset = ($pagina_actual - 1) * $filas_por_pagina;

// Obtener usuarios (paginado)
$stmt = $pdo->prepare("
    SELECT u.*, d.nombre as dependencia_nombre, r.nombre as rol
    FROM usuarios u
    LEFT JOIN dependencias d ON u.dependencia_id = d.id
    LEFT JOIN roles r ON u.rol_id = r.id
    ORDER BY u.fecha_creacion DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$filas_por_pagina, $offset]);
$usuarios = $stmt->fetchAll();

// Obtener dependencias
$stmt = $pdo->query("SELECT * FROM dependencias WHERE activo = 1 ORDER BY nombre");
$dependencias = $stmt->fetchAll();

// Obtener roles
$stmt = $pdo->query("SELECT * FROM roles WHERE activo = 1 ORDER BY nombre");
$roles = $stmt->fetchAll();
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-people me-2"></i>Gestión de Usuarios</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUsuario">
            <i class="bi bi-person-plus me-2"></i>Nuevo Usuario
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
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Dependencia</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td><?php echo $usuario['id']; ?></td>
                                <td><?php echo htmlspecialchars($usuario['nombre_completo']); ?></td>
                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                <td>
                                    <?php
                                    $rol_badge = ['super_admin' => ['danger', 'Super Administrador'], 'administrador' => ['warning', 'Administrador'], 'usuario' => ['primary', 'Usuario básico']];
                                    $r = $rol_badge[$usuario['rol']] ?? ['secondary', $usuario['rol']];
                                    ?>
                                    <span class="badge bg-<?php echo $r[0]; ?>"><?php echo htmlspecialchars($r[1]); ?></span>
                                </td>
                                <td><?php echo $usuario['dependencia_nombre'] ?? 'Sin asignar'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $usuario['activo'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editarUsuario(<?php echo htmlspecialchars(json_encode($usuario)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="cambiarPassword(<?php echo $usuario['id']; ?>)">
                                        <i class="bi bi-key"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_paginas > 1): ?>
                <nav class="d-flex justify-content-center mt-3" aria-label="Paginación de usuarios">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina_usuarios=<?php echo $pagina_actual - 1; ?>">Anterior</a>
                        </li>
                        <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                            <li class="page-item <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina_usuarios=<?php echo $p; ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina_usuarios=<?php echo $pagina_actual + 1; ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Usuario -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formUsuario">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalUsuarioTitle">Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionUsuario" value="crear">
                    <input type="hidden" name="id" id="usuarioId">
                    
                    <div class="mb-3">
                        <label class="form-label">Nombre Completo *</label>
                        <input type="text" class="form-control" name="nombre_completo" id="nombreCompleto" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" class="form-control" name="email" id="emailUsuario" required>
                    </div>
                    
                    <div class="mb-3" id="passwordField">
                        <label class="form-label">Contraseña *</label>
                        <input type="password" class="form-control" name="password" id="passwordUsuario" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Rol *</label>
                        <select class="form-select" name="rol_id" id="rolUsuario" required>
                            <?php
                            $rol_etiquetas = ['super_admin' => 'Super Administrador', 'administrador' => 'Administrador', 'usuario' => 'Usuario básico'];
                            foreach ($roles as $rol):
                                $etiqueta = $rol_etiquetas[$rol['nombre']] ?? $rol['nombre'];
                            ?>
                                <option value="<?php echo $rol['id']; ?>"><?php echo htmlspecialchars($etiqueta); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Dependencia</label>
                        <select class="form-select" name="dependencia_id" id="dependenciaUsuario">
                            <option value="">Sin asignar</option>
                            <?php foreach ($dependencias as $dep): ?>
                                <option value="<?php echo $dep['id']; ?>"><?php echo htmlspecialchars($dep['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="activoField" style="display: none;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="activo" id="activoUsuario" checked>
                            <label class="form-check-label" for="activoUsuario">Usuario Activo</label>
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

<!-- Modal Cambiar Password -->
<div class="modal fade" id="modalPassword" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar Contraseña</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="cambiar_password">
                    <input type="hidden" name="id" id="passwordUsuarioId">
                    
                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña *</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cambiar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editarUsuario(usuario) {
    document.getElementById('modalUsuarioTitle').textContent = 'Editar Usuario';
    document.getElementById('accionUsuario').value = 'editar';
    document.getElementById('usuarioId').value = usuario.id;
    document.getElementById('nombreCompleto').value = usuario.nombre_completo;
    document.getElementById('emailUsuario').value = usuario.email;
    document.getElementById('rolUsuario').value = usuario.rol_id;
    document.getElementById('dependenciaUsuario').value = usuario.dependencia_id || '';
    document.getElementById('activoUsuario').checked = usuario.activo == 1;
    document.getElementById('passwordField').style.display = 'none';
    document.getElementById('passwordUsuario').removeAttribute('required');
    document.getElementById('activoField').style.display = 'block';
    
    new bootstrap.Modal(document.getElementById('modalUsuario')).show();
}

function cambiarPassword(usuarioId) {
    document.getElementById('passwordUsuarioId').value = usuarioId;
    new bootstrap.Modal(document.getElementById('modalPassword')).show();
}

// Reset modal al cerrar
document.getElementById('modalUsuario').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formUsuario').reset();
    document.getElementById('modalUsuarioTitle').textContent = 'Nuevo Usuario';
    document.getElementById('accionUsuario').value = 'crear';
    document.getElementById('passwordField').style.display = 'block';
    document.getElementById('passwordUsuario').setAttribute('required', 'required');
    document.getElementById('activoField').style.display = 'none';
});
</script>

<?php require_once '../includes/footer.php'; ?>
