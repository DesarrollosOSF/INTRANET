<?php
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Intranet OSF</title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>img/LogoRecortado1.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/main.css">
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link rel="stylesheet" href="<?php echo (strpos($css, 'http') === 0) ? $css : BASE_URL . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>window.APP_BASE_URL = <?php echo json_encode(BASE_URL); ?>;</script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand spa-nav-link" href="<?php echo BASE_URL; ?>index.php">
            <img src="<?php echo BASE_URL; ?>img/LOGOOSF_H.png" alt="Organización San Francisco" class="logo-img">
                <!--<i class="bi bi-building me-2"></i>Intranet OSF-->
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>index.php">
                            <i class="bi bi-house me-1"></i>Dashboard
                        </a>
                    </li>
                    <?php if (tienePermiso('ver_cursos')): ?>
                    <li class="nav-item">
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>cursos/index.php">
                            <i class="bi bi-book me-1"></i>Cursos
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (tienePermiso('ver_datos_interes') || tienePermiso('ver_documentos_interes')): ?>
                    <li class="nav-item">
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>datos_interes.php">
                            <i class="bi bi-folder2-open me-1"></i>Documentos de interés
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php
                    $tiene_admin = tienePermiso('gestionar_usuarios') || tienePermiso('gestionar_perfiles_permisos') || tienePermiso('gestionar_cursos') || tienePermiso('gestionar_comunicados') || tienePermiso('gestionar_dependencias') || tienePermiso('gestionar_documentos_interes') || tienePermiso('ver_reportes');
                    if ($tiene_admin):
                    ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear me-1"></i>Administración
                        </a>
                        <ul class="dropdown-menu">
                            <?php if (tienePermiso('gestionar_usuarios')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/usuarios.php">
                                <i class="bi bi-people me-2"></i>Usuarios
                            </a></li>
                            <?php endif; ?>
                            <?php if (tienePermiso('gestionar_perfiles_permisos')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/perfiles_permisos.php">
                                <i class="bi bi-shield-lock me-2"></i>Perfiles y permisos
                            </a></li>
                            <?php endif; ?>
                            <?php if (tienePermiso('gestionar_dependencias')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/dependencias.php">
                                <i class="bi bi-diagram-3 me-2"></i>Dependencias
                            </a></li>
                            <?php endif; ?>
                            <?php if (tienePermiso('gestionar_documentos_interes')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/documentos_interes.php">
                                <i class="bi bi-folder2-open me-2"></i>Documentos de interés
                            </a></li>
                            <?php endif; ?>
                            <?php if (tienePermiso('gestionar_cursos')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/cursos.php">
                                <i class="bi bi-book-half me-2"></i>Gestión de Cursos
                            </a></li>
                            <?php endif; ?>
                            <?php if (tienePermiso('gestionar_comunicados')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/comunicados.php">
                                <i class="bi bi-megaphone me-2"></i>Gestión de Comunicados
                            </a></li>
                            <?php endif; ?>
                            <?php if (tienePermiso('ver_reportes')): ?>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/reporte_cursos.php">
                                <i class="bi bi-bar-chart me-2"></i>Reportes de Cursos
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>admin/reportes.php">
                                <i class="bi bi-graph-up me-2"></i>Reportes
                            </a></li>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'super_admin'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-secondary spa-nav-link" href="<?php echo BASE_URL; ?>admin/vaciar_archivos_prueba.php">
                                <i class="bi bi-trash me-2"></i>Vaciar archivos de prueba
                            </a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['nombre_completo']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item spa-nav-link" href="<?php echo BASE_URL; ?>perfil.php">
                                <i class="bi bi-person me-2"></i>Mi Perfil
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div id="app-main-content">
