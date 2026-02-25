<?php
require_once 'config/config.php';

// Si ya está autenticado, redirigir al dashboard
if (isset($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';
$ROL_USUARIO_POR_DEFECTO = 2; // rol "usuario" (el super_admin asigna el definitivo al activar)
$PERFIL_COLABORADOR = 2;

$pdo = getDBConnection();
$stmt = $pdo->query("SELECT * FROM dependencias WHERE activo = 1 ORDER BY nombre");
$dependencias = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = sanitizar($_POST['nombre_completo'] ?? '');
    $email = sanitizar($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $dependencia_id = !empty($_POST['dependencia_id']) ? (int)$_POST['dependencia_id'] : null;
    
    if (empty($nombre) || empty($email) || empty($password)) {
        $mensaje = 'Por favor complete todos los campos';
        $tipo_mensaje = 'danger';
    } else {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre_completo, email, password, rol_id, dependencia_id, activo)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$nombre, $email, $password_hash, $ROL_USUARIO_POR_DEFECTO, $dependencia_id]);
            
            $usuario_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO usuario_perfiles (usuario_id, perfil_id) VALUES (?, ?)");
            $stmt->execute([$usuario_id, $PERFIL_COLABORADOR]);
            
            $mensaje = 'Solicitud de registro enviada. Un administrador activará su cuenta y le asignará el rol correspondiente.';
            $tipo_mensaje = 'success';
            $_POST = [];
        } catch (PDOException $e) {
            $mensaje = $e->getCode() == 23000 ? 'El email ya está registrado.' : 'Error al registrar. Intente nuevamente.';
            $tipo_mensaje = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preload" href="<?php echo BASE_URL; ?>img/LogoRecortado1.png" as="image">
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>img/LogoRecortado1.png">
    <title>Nuevo Usuario - Intranet OSF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <i class="bi bi-person-plus fs-1 text-primary"></i>
                <h2 class="mt-3">Nuevo Usuario</h2>
                <p class="text-muted">Solicite su cuenta. Un administrador la activará y asignará su rol.</p>
            </div>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i><?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="nombre_completo" class="form-label">
                        <i class="bi bi-person me-2"></i>Nombre Completo *
                    </label>
                    <input type="text" class="form-control" id="nombre_completo" name="nombre_completo" required
                           value="<?php echo isset($_POST['nombre_completo']) ? htmlspecialchars($_POST['nombre_completo']) : ''; ?>">
                </div>
                
                <div class="mb-3">
                    <label for="email" class="form-label">
                        <i class="bi bi-envelope me-2"></i>Email *
                    </label>
                    <input type="email" class="form-control" id="email" name="email" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">
                        <i class="bi bi-lock me-2"></i>Contraseña *
                    </label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                
                <div class="mb-3">
                    <label for="dependencia_id" class="form-label">
                        <i class="bi bi-building me-2"></i>Dependencia
                    </label>
                    <select class="form-select" name="dependencia_id" id="dependencia_id">
                        <option value="">Sin asignar</option>
                        <?php foreach ($dependencias as $dep): ?>
                            <option value="<?php echo $dep['id']; ?>" <?php echo (isset($_POST['dependencia_id']) && (int)$_POST['dependencia_id'] === (int)$dep['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dep['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-person-plus me-2"></i>Enviar solicitud
                </button>
                
                <a href="login.php" class="btn btn-primary w-100 mt-2">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Volver a Iniciar Sesión
                </a>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
