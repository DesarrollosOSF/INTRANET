<?php
require_once 'config/config.php';
requerirAutenticacion();
if (!tienePermiso('ver_datos_interes') && !tienePermiso('ver_documentos_interes')) {
    header('Location: ' . BASE_URL . 'login.php?error=sin_permiso');
    exit;
}

$documento_id = (int)($_GET['id'] ?? 0);
if (!$documento_id) {
    header('Location: ' . BASE_URL . 'datos_interes.php');
    exit;
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT d.*, dep.nombre AS dependencia_nombre
    FROM documentos_interes d
    LEFT JOIN dependencias dep ON dep.id = d.dependencia_id
    WHERE d.id = ?
");
$stmt->execute([$documento_id]);
$documento = $stmt->fetch();
if (!$documento) {
    header('Location: ' . BASE_URL . 'datos_interes.php');
    exit;
}

$modulos = [];
$archivos_por_modulo = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos_documento_interes WHERE documento_interes_id = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$documento_id]);
    $modulos = $stmt->fetchAll();
    foreach ($modulos as $mod) {
        $stmt2 = $pdo->prepare("SELECT * FROM archivos_documento_interes WHERE modulo_id = ? ORDER BY orden ASC, id ASC");
        $stmt2->execute([$mod['id']]);
        $archivos_por_modulo[$mod['id']] = $stmt2->fetchAll();
    }
} catch (Exception $e) {}

$page_title = 'Documento: ' . $documento['nombre'];
$additional_css = ['assets/css/main.css'];
require_once 'includes/header.php';
?>

<div class="container my-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>datos_interes.php">Documentos de interés</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($documento['nombre']); ?></li>
        </ol>
    </nav>
    
    <div class="d-flex flex-wrap gap-3 align-items-start mb-4">
        <?php if (!empty($documento['imagen'])): ?>
            <img src="<?php echo UPLOAD_URL . htmlspecialchars($documento['imagen']); ?>" class="rounded" alt="" style="max-height: 120px; object-fit: cover;">
        <?php endif; ?>
        <div class="flex-grow-1">
            <h2 class="mb-1"><?php echo htmlspecialchars($documento['nombre']); ?></h2>
            <p class="text-muted mb-0"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($documento['dependencia_nombre'] ?? 'Sin dependencia'); ?></p>
            <?php if (!empty($documento['descripcion'])): ?>
                <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos y archivos</h5>
        </div>
        <div class="card-body">
            <?php if (empty($modulos)): ?>
                <p class="text-muted mb-0">Aún no hay módulos ni archivos publicados para este documento.</p>
            <?php else: ?>
                <?php foreach ($modulos as $mod): 
                    $archivos = isset($archivos_por_modulo[$mod['id']]) ? $archivos_por_modulo[$mod['id']] : [];
                ?>
                    <h6 class="mt-4 mb-2 text-primary"><i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?></h6>
                    <?php if (!empty($mod['descripcion'])): ?>
                        <p class="small text-muted"><?php echo htmlspecialchars($mod['descripcion']); ?></p>
                    <?php endif; ?>
                    <?php if (empty($archivos)): ?>
                        <p class="small text-muted">Sin archivos en este módulo.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush mb-3">
                            <?php foreach ($archivos as $ar): 
                                $url = UPLOAD_URL . 'documentos_interes/' . $ar['archivo'];
                                $ext = strtolower(pathinfo($ar['archivo'], PATHINFO_EXTENSION));
                            ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-file-earmark-<?php echo $ext === 'pdf' ? 'pdf' : (in_array($ext, ['xls','xlsx']) ? 'excel' : 'word'); ?> text-primary me-2"></i>
                                        <strong><?php echo htmlspecialchars($ar['nombre']); ?></strong>
                                        <?php if (!empty($ar['descripcion'])): ?>
                                            <br><span class="small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?php echo htmlspecialchars($url); ?>" class="btn btn-sm btn-outline-primary" download>
                                        <i class="bi bi-download me-1"></i>Descargar
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="mt-3">
        <a href="<?php echo BASE_URL; ?>datos_interes.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Volver a Documentos de interés</a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
