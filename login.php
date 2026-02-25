<?php
require_once 'config/config.php';

// Si ya está autenticado, redirigir al dashboard (salvo que venga por error para poder ver mensaje o cambiar de cuenta)
if (isset($_SESSION['usuario_id']) && !isset($_GET['error'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'sin_permiso') {
        $error = 'Su cuenta no tiene permisos para acceder. Inicie sesión con otra cuenta.';
        // Solo se vacía la sesión de este navegador (no afecta a otros usuarios del sistema)
        $_SESSION = [];
    } elseif ($_GET['error'] === 'cuenta_desactivada') {
        $error = 'Su cuenta está desactivada. Contacte al administrador.';
        $_SESSION = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizar($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Por favor complete todos los campos';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                SELECT u.id, u.nombre_completo, u.email, u.password, u.activo, r.nombre as rol
                FROM usuarios u
                INNER JOIN roles r ON u.rol_id = r.id
                WHERE u.email = ?
            ");
            
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            
            if ($usuario && password_verify($password, $usuario['password'])) {
                if ($usuario['activo'] == 1) {
                    // Iniciar sesión
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['nombre_completo'] = $usuario['nombre_completo'];
                    $_SESSION['email'] = $usuario['email'];
                    $_SESSION['rol'] = $usuario['rol'];
                    
                    // Registrar log
                    registrarLog($usuario['id'], 'Inicio de sesión', 'Autenticación');
                    
                    header('Location: ' . BASE_URL . 'index.php');
                    exit;
                } else {
                    $error = 'Su cuenta está desactivada. Contacte al administrador.';
                }
            } else {
                $error = 'Email o contraseña incorrectos';
            }
        } catch (Exception $e) {
            $error = 'Error al iniciar sesión. Intente nuevamente.';
            error_log("Error de login: " . $e->getMessage());
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
    <title>Iniciar Sesión - Intranet OSF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <i class="bi bi-building fs-1 text-primary"></i>
                <h2 class="mt-3">Intranet OSF</h2>
                <p class="text-muted">Organización San Francisco</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="email" class="form-label">
                        <i class="bi bi-envelope me-2"></i>Email
                    </label>
                    <input type="email" class="form-control" id="email" name="email" required autofocus>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">
                        <i class="bi bi-lock me-2"></i>Contraseña
                    </label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar Sesión
                </button>
                
                <a href="registro.php" class="btn btn-primary w-100 mt-2">
                    <i class="bi bi-person-plus me-2"></i>Nuevo Usuario
                </a>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
