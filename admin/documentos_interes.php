<?php
require_once '../config/config.php';
requerirPermiso('gestionar_documentos_interes');

$page_title = 'Gestión de Documentos de interés';
$additional_css = ['assets/css/admin.css', 'assets/css/cursos.css'];
require_once '../includes/header.php';

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';
$upload_dir = rtrim(BASE_PATH . 'uploads/documentos_interes/', '/\\') . DIRECTORY_SEPARATOR;
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// Crear o editar documento (solo datos básicos + imagen)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear' || $accion === 'editar') {
        $id = $accion === 'editar' ? (int)$_POST['id'] : 0;
        $nombre = trim(sanitizar($_POST['nombre'] ?? ''));
        $dependencia_id = (int)$_POST['dependencia_id'];
        $descripcion = trim($_POST['descripcion'] ?? '');
        
        if (empty($nombre) || $dependencia_id <= 0) {
            $mensaje = 'Nombre y dependencia son obligatorios.';
            $tipo_mensaje = 'danger';
        } else {
            $imagen = null;
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $validacion = validarTamanoSubida((int)$_FILES['imagen']['size'], 'imagen');
                if (!$validacion['valido']) {
                    $mensaje = $validacion['mensaje'];
                    $tipo_mensaje = 'danger';
                } else {
                    $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                        $mensaje = 'Formato de imagen no permitido.';
                        $tipo_mensaje = 'danger';
                    } else {
                        $imagen_nombre = uniqid() . '.' . $ext;
                        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $upload_dir . $imagen_nombre)) {
                            $imagen = 'documentos_interes/' . $imagen_nombre;
                        }
                    }
                }
            }
            
            if ($mensaje === '') {
                try {
                    if ($accion === 'crear') {
                        $stmt = $pdo->prepare("INSERT INTO documentos_interes (nombre, dependencia_id, descripcion, imagen, usuario_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$nombre, $dependencia_id, $descripcion ?: null, $imagen, $_SESSION['usuario_id']]);
                        $mensaje = 'Documento de interés creado. Ahora puede agregar módulos y archivos en el detalle.';
                        $tipo_mensaje = 'success';
                        registrarLog($_SESSION['usuario_id'], 'Crear documento de interés', 'Documentos', "Documento: $nombre");
                    } else {
                        $doc_actual = $pdo->prepare("SELECT imagen FROM documentos_interes WHERE id = ?");
                        $doc_actual->execute([$id]);
                        $row = $doc_actual->fetch();
                        $imagen_final = $imagen ?: ($row['imagen'] ?? null);
                        $stmt = $pdo->prepare("UPDATE documentos_interes SET nombre = ?, dependencia_id = ?, descripcion = ?, imagen = COALESCE(?, imagen) WHERE id = ?");
                        $stmt->execute([$nombre, $dependencia_id, $descripcion ?: null, $imagen, $id]);
                        $mensaje = 'Documento actualizado.';
                        $tipo_mensaje = 'success';
                        registrarLog($_SESSION['usuario_id'], 'Editar documento de interés', 'Documentos', "ID: $id");
                    }
                } catch (PDOException $e) {
                    $mensaje = $e->getCode() == 23000 ? 'Ya existe un documento con ese nombre en esta dependencia.' : 'Error al guardar.';
                    $tipo_mensaje = 'danger';
                }
            }
        }
    } elseif ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT imagen FROM documentos_interes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $pdo->prepare("DELETE FROM documentos_interes WHERE id = ?")->execute([$id]);
        if ($row && $row['imagen'] && file_exists(BASE_PATH . 'uploads/' . $row['imagen'])) {
            @unlink(BASE_PATH . 'uploads/' . $row['imagen']);
        }
        $mensaje = 'Documento eliminado.';
        $tipo_mensaje = 'success';
        registrarLog($_SESSION['usuario_id'], 'Eliminar documento de interés', 'Documentos', "ID: $id");
    }
}

$documentos_por_pagina = 6;
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;

try {
    $count_sql = "SELECT COUNT(*) FROM documentos_interes d INNER JOIN dependencias dep ON dep.id = d.dependencia_id";
    $total_documentos = (int) $pdo->query($count_sql)->fetchColumn();
} catch (Exception $e) {
    $total_documentos = 0;
}
$total_paginas = $total_documentos > 0 ? (int) ceil($total_documentos / $documentos_por_pagina) : 1;
$pagina_actual = min($pagina_actual, $total_paginas);
$offset = ($pagina_actual - 1) * $documentos_por_pagina;

try {
    $documentos = $pdo->query("
        SELECT d.*, dep.nombre AS dependencia_nombre,
               (SELECT COUNT(*) FROM modulos_documento_interes m WHERE m.documento_interes_id = d.id) AS num_modulos
        FROM documentos_interes d
        INNER JOIN dependencias dep ON dep.id = d.dependencia_id
        ORDER BY dep.nombre, d.nombre
        LIMIT " . (int) $documentos_por_pagina . " OFFSET " . (int) $offset . "
    ")->fetchAll();
} catch (Exception $e) {
    $documentos = $pdo->query("
        SELECT d.*, dep.nombre AS dependencia_nombre, 0 AS num_modulos
        FROM documentos_interes d
        INNER JOIN dependencias dep ON dep.id = d.dependencia_id
        ORDER BY dep.nombre, d.nombre
        LIMIT " . (int) $documentos_por_pagina . " OFFSET " . (int) $offset . "
    ")->fetchAll();
}
$dependencias = $pdo->query("SELECT id, nombre FROM dependencias WHERE activo = 1 ORDER BY nombre")->fetchAll();
?>

<div class="container-fluid mt-4 pagina-documentos-interes">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-folder2-open me-2"></i>Gestión de Documentos de interés</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDocumento">
            <i class="bi bi-plus-circle me-2"></i>Nuevo documento
        </button>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <p class="text-muted">Cree un documento de interés (nombre, dependencia, imagen). Luego entre al detalle para agregar módulos y archivos dentro de cada módulo.</p>
    
    <div class="documentos-interes-contenido">
        <div class="row g-3">
            <?php foreach ($documentos as $doc): 
                $imagen_url = !empty($doc['imagen']) ? UPLOAD_URL . $doc['imagen'] : '';
            ?>
                <div class="col-12 col-sm-6 col-lg-4">
                    <div class="card shadow-sm h-100">
                        <?php if ($imagen_url): ?>
                            <img src="<?php echo htmlspecialchars($imagen_url); ?>" class="card-img-top" alt="" style="height: 140px; object-fit: cover;">
                        <?php else: ?>
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 140px;">
                                <i class="bi bi-folder2-open display-4 text-secondary"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h6 class="card-title"><?php echo htmlspecialchars($doc['nombre']); ?></h6>
                            <p class="small text-muted mb-1"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($doc['dependencia_nombre']); ?></p>
                            <p class="small mb-2"><?php echo (int)($doc['num_modulos'] ?? 0); ?> módulo(s)</p>
                            <div class="d-flex gap-1">
                                <a href="documentos_detalle.php?id=<?php echo (int)$doc['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">
                                    <i class="bi bi-pencil-square me-1"></i>Gestionar
                                </a>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick='editarDoc(<?php echo htmlspecialchars(json_encode($doc)); ?>)'><i class="bi bi-pencil"></i></button>
                                <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este documento y todos sus módulos y archivos?');">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($documentos)): ?>
            <p class="text-muted text-center py-4">No hay documentos. Use «Nuevo documento» para crear uno (nombre, dependencia, imagen). Luego podrá agregar módulos y archivos.</p>
        <?php endif; ?>
    </div>
    <?php if ($total_paginas > 1): ?>
        <nav class="documentos-interes-paginacion mt-auto" aria-label="Paginación de documentos">
            <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">
                <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>"><i class="bi bi-chevron-left"></i> Anterior</a>
                </li>
                <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                    <li class="page-item <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $p; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>">Siguiente <i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
            <p class="text-muted text-center small mt-2 mb-0">Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> (<?php echo $total_documentos; ?> documento(s) en total)</p>
        </nav>
    <?php endif; ?>
</div>

<!-- Modal Nuevo/Editar Documento -->
<div class="modal fade" id="modalDocumento" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="formDocumento">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDocumentoTitle">Nuevo documento de interés</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionDoc" value="crear">
                    <input type="hidden" name="id" id="docId">
                    <div class="mb-3">
                        <label class="form-label">Nombre del documento *</label>
                        <input type="text" class="form-control" name="nombre" id="docNombre" required placeholder="Ej: Formatos de permisos">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dependencia *</label>
                        <select class="form-select" name="dependencia_id" id="docDependencia" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($dependencias as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" id="docDescripcion" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Imagen representativa</label>
                        <input type="file" class="form-control" name="imagen" id="docImagen" accept="image/*">
                        <small class="text-muted">Máx. 10 MB. JPG, PNG, GIF, WEBP</small>
                        <div id="docImagenPreview" class="mt-2"></div>
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
function editarDoc(doc) {
    document.getElementById('modalDocumentoTitle').textContent = 'Editar documento';
    document.getElementById('accionDoc').value = 'editar';
    document.getElementById('docId').value = doc.id;
    document.getElementById('docNombre').value = doc.nombre;
    document.getElementById('docDependencia').value = doc.dependencia_id;
    document.getElementById('docDescripcion').value = doc.descripcion || '';
    if (doc.imagen) {
        document.getElementById('docImagenPreview').innerHTML = '<img src="<?php echo UPLOAD_URL; ?>' + doc.imagen + '" class="img-thumbnail" style="max-height: 120px;">';
    } else {
        document.getElementById('docImagenPreview').innerHTML = '';
    }
    new bootstrap.Modal(document.getElementById('modalDocumento')).show();
}
document.getElementById('modalDocumento').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formDocumento').reset();
    document.getElementById('modalDocumentoTitle').textContent = 'Nuevo documento de interés';
    document.getElementById('accionDoc').value = 'crear';
    document.getElementById('docId').value = '';
    document.getElementById('docImagenPreview').innerHTML = '';
});
document.getElementById('docImagen').addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        var r = new FileReader();
        r.onload = function(ev) { document.getElementById('docImagenPreview').innerHTML = '<img src="' + ev.target.result + '" class="img-thumbnail" style="max-height: 120px;">'; };
        r.readAsDataURL(e.target.files[0]);
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
