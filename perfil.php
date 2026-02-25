<?php
require_once 'config/config.php';
requerirAutenticacion();

$page_title = 'Mi Perfil';

require_once 'includes/header.php';

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

// Obtener datos del usuario
$stmt = $pdo->prepare("
    SELECT u.*, d.nombre as dependencia_nombre
    FROM usuarios u
    LEFT JOIN dependencias d ON u.dependencia_id = d.id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['usuario_id']]);
$usuario = $stmt->fetch();

// Obtener estadísticas del usuario
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_cursos,
        SUM(CASE WHEN completado = 1 THEN 1 ELSE 0 END) as cursos_completados,
        AVG(progreso) as promedio_progreso
    FROM inscripciones
    WHERE usuario_id = ?
");
$stmt->execute([$_SESSION['usuario_id']]);
$estadisticas = $stmt->fetch();

// Obtener cursos recientes
$stmt = $pdo->prepare("
    SELECT i.*, c.nombre as curso_nombre, c.imagen as curso_imagen
    FROM inscripciones i
    INNER JOIN cursos c ON i.curso_id = c.id
    WHERE i.usuario_id = ?
    ORDER BY i.fecha_inscripcion DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['usuario_id']]);
$cursos_recientes = $stmt->fetchAll();

// Cambiar contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_password'])) {
    $password_actual = $_POST['password_actual'] ?? '';
    $password_nuevo = $_POST['password_nuevo'] ?? '';
    $password_confirmar = $_POST['password_confirmar'] ?? '';
    
    if (empty($password_actual) || empty($password_nuevo) || empty($password_confirmar)) {
        $mensaje = 'Por favor complete todos los campos';
        $tipo_mensaje = 'danger';
    } elseif ($password_nuevo !== $password_confirmar) {
        $mensaje = 'Las contraseñas nuevas no coinciden';
        $tipo_mensaje = 'danger';
    } elseif (!password_verify($password_actual, $usuario['password'])) {
        $mensaje = 'La contraseña actual es incorrecta';
        $tipo_mensaje = 'danger';
    } else {
        try {
            $password_hash = password_hash($password_nuevo, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt->execute([$password_hash, $_SESSION['usuario_id']]);
            registrarLog($_SESSION['usuario_id'], 'Cambiar contraseña', 'Perfil');
            $mensaje = 'Contraseña actualizada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al actualizar contraseña';
            $tipo_mensaje = 'danger';
        }
    }
}
?>

<div class="container mt-4">
    <h2 class="mb-4"><i class="bi bi-person-circle me-2"></i>Mi Perfil</h2>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <i class="bi bi-person-circle" style="font-size: 5rem; color: #667eea;"></i>
                    <h4 class="mt-3"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></h4>
                    <p class="text-muted"><?php echo htmlspecialchars($usuario['email']); ?></p>
                    <p class="mb-0">
                        <span class="badge bg-<?php echo $usuario['rol'] === 'super_admin' ? 'danger' : 'primary'; ?>">
                            <?php echo $usuario['rol'] === 'super_admin' ? 'Super Administrador' : 'Usuario'; ?>
                        </span>
                    </p>
                    <?php if ($usuario['dependencia_nombre']): ?>
                        <p class="mt-2 mb-0">
                            <i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($usuario['dependencia_nombre']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card shadow-sm mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Estadísticas</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Cursos Inscritos:</strong>
                        <span class="float-end"><?php echo $estadisticas['total_cursos']; ?></span>
                    </div>
                    <div class="mb-3">
                        <strong>Cursos Completados:</strong>
                        <span class="float-end"><?php echo $estadisticas['cursos_completados']; ?></span>
                    </div>
                    <div>
                        <strong>Progreso Promedio:</strong>
                        <span class="float-end"><?php echo number_format($estadisticas['promedio_progreso'] ?? 0, 1); ?>%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card shadow-sm mb-3">
                <div class="card-header">
                    <h6 class="mb-0">Cambiar Contraseña</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Contraseña Actual *</label>
                            <input type="password" class="form-control" name="password_actual" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nueva Contraseña *</label>
                            <input type="password" class="form-control" name="password_nuevo" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirmar Nueva Contraseña *</label>
                            <input type="password" class="form-control" name="password_confirmar" required minlength="6">
                        </div>
                        <button type="submit" name="cambiar_password" class="btn btn-primary">
                            <i class="bi bi-key me-2"></i>Cambiar Contraseña
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="card shadow-sm">
                <div class="card-header">
                    <h6 class="mb-0">Mis Cursos Recientes</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($cursos_recientes)): ?>
                        <p class="text-muted">No tienes cursos inscritos</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($cursos_recientes as $curso): ?>
                                <a href="cursos/ver_curso.php?id=<?php echo $curso['curso_id']; ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($curso['curso_nombre']); ?></h6>
                                            <small class="text-muted">
                                                Inscrito: <?php echo date('d/m/Y', strtotime($curso['fecha_inscripcion'])); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="progress mb-1" style="width: 100px; height: 20px;">
                                                <div class="progress-bar" style="width: <?php echo $curso['progreso']; ?>%">
                                                    <?php echo number_format($curso['progreso'], 0); ?>%
                                                </div>
                                            </div>
                                            <?php if ($curso['completado']): ?>
                                                <span class="badge bg-success">Completado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 text-center">
                            <a href="cursos/index.php" class="btn btn-outline-primary">
                                Ver Todos los Cursos
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
