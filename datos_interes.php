<?php
require_once 'config/config.php';
requerirAutenticacion();
if (!tienePermiso('ver_datos_interes') && !tienePermiso('ver_documentos_interes')) {
    header('Location: ' . BASE_URL . 'login.php?error=sin_permiso');
    exit;
}

$page_title = 'Documentos de interés';
$additional_css = ['assets/css/main.css'];
require_once 'includes/header.php';

$pdo = getDBConnection();
$documentos = [];
$dependencias = [];
$puede_gestionar = tienePermiso('gestionar_documentos_interes');
$error_tabla = false;

try {
    $dependencias = $pdo->query("SELECT id, nombre FROM dependencias WHERE activo = 1 ORDER BY nombre")->fetchAll();
} catch (PDOException $e) {
    $error_tabla = true;
}

if (!$error_tabla) {
    $dependencia_id = isset($_GET['dependencia_id']) ? (int)$_GET['dependencia_id'] : 0;
    $busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

    $where = ["dep.activo = 1"];
    $params = [];

    if ($dependencia_id > 0) {
        $where[] = "doc.dependencia_id = ?";
        $params[] = $dependencia_id;
    }
    if ($busqueda !== '') {
        $term = '%' . $busqueda . '%';
        $where[] = "(doc.nombre LIKE ? OR doc.descripcion LIKE ?)";
        $params[] = $term;
        $params[] = $term;
    }

    try {
        $sql = "SELECT doc.id, doc.nombre, doc.descripcion, doc.imagen, doc.dependencia_id, doc.fecha_carga,
                       dep.nombre AS dependencia_nombre
                FROM documentos_interes doc
                INNER JOIN dependencias dep ON dep.id = doc.dependencia_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY dep.nombre, doc.nombre";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documentos = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error_tabla = true;
    }
}
?>

<div class="container my-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Documentos de interés</h2>
        <?php if ($puede_gestionar): ?>
            <a href="<?php echo BASE_URL; ?>admin/documentos_interes.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-2"></i>Gestionar documentos
            </a>
        <?php endif; ?>
    </div>
    <p class="text-muted">Documentos institucionales organizados por dependencia. Entrando a cada uno podrá ver módulos y descargar archivos (formatos, plantillas, procedimientos, manuales).</p>

    <?php if ($error_tabla): ?>
        <div class="alert alert-info">El módulo de documentos aún no está instalado. Ejecute las migraciones correspondientes en la base de datos (documentos de interés y módulos).</div>
    <?php endif; ?>

    <?php if (!$error_tabla): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="" class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Dependencia</label>
                    <select name="dependencia_id" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($dependencias as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $dependencia_id === (int)$d['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Buscar por nombre o descripción</label>
                    <input type="text" name="q" class="form-control" placeholder="Nombre, descripción..." value="<?php echo htmlspecialchars($busqueda ?? ''); ?>">
                </div>
                <div class="col-12 col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filtrar</button>
                    <a href="datos_interes.php" class="btn btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error_tabla): ?>
                <p class="text-muted mb-0">No hay documentos disponibles.</p>
            <?php elseif (empty($documentos)): ?>
                <p class="text-muted mb-0">No hay documentos que coincidan con el filtro.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($documentos as $doc): 
                        $imagen_url = !empty($doc['imagen']) ? UPLOAD_URL . $doc['imagen'] : '';
                    ?>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="card h-100">
                                <?php if ($imagen_url): ?>
                                    <img src="<?php echo htmlspecialchars($imagen_url); ?>" class="card-img-top" alt="" style="height: 140px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 140px;">
                                        <i class="bi bi-folder2-open display-4 text-secondary"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($doc['nombre']); ?></h6>
                                    <p class="small text-muted mb-2"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($doc['dependencia_nombre']); ?></p>
                                    <?php if (!empty($doc['descripcion'])): ?>
                                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(mb_substr($doc['descripcion'], 0, 80)) . (mb_strlen($doc['descripcion']) > 80 ? '…' : ''); ?></p>
                                    <?php endif; ?>
                                    <a href="ver_documento.php?id=<?php echo (int)$doc['id']; ?>" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-folder2-open me-1"></i>Ver módulos y archivos
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
