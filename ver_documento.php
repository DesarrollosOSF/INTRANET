<?php
require_once 'config/config.php';
requerirAutenticacion();

$pdo = getDBConnection();



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
    // Paginación de módulos (máximo 6 por página)
    $modulos_por_pagina = 6;
    $pagina_modulos = isset($_GET['pagina_modulos']) ? max(1, (int)$_GET['pagina_modulos']) : 1;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM modulos_documento_interes WHERE documento_interes_id = ?");
    $stmt->execute([$documento_id]);
    $total_modulos = (int)($stmt->fetch()['total'] ?? 0);
    $total_paginas_modulos = $total_modulos > 0 ? (int)ceil($total_modulos / $modulos_por_pagina) : 1;
    $pagina_modulos = min($pagina_modulos, $total_paginas_modulos);
    $offset_modulos = ($pagina_modulos - 1) * $modulos_por_pagina;

    $stmt = $pdo->prepare("
        SELECT *
        FROM modulos_documento_interes
        WHERE documento_interes_id = ?
        ORDER BY orden ASC, id ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, (int)$documento_id, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$modulos_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(3, (int)$offset_modulos, PDO::PARAM_INT);
    $stmt->execute();
    $modulos = $stmt->fetchAll();

    foreach ($modulos as $mod) {
        $stmt2 = $pdo->prepare("SELECT * FROM archivos_documento_interes WHERE modulo_id = ? ORDER BY orden ASC, id ASC");
        $stmt2->execute([$mod['id']]);
        $archivos_por_modulo[$mod['id']] = $stmt2->fetchAll();
    }
} catch (Exception $e) {}

require_once 'includes/auditoria_helpers.php'; // ajusta la ruta según la profundidad del archivo
registrarVista($pdo, $_SESSION['usuario_id'], 'documento_interes', $documento_id);

$page_title = 'Documento: ' . $documento['nombre'];
$additional_css = ['assets/css/main.css', 'assets/css/dashboard.css'];
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

    <div class="d-flex flex-column" style="min-height: 550px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos y archivos</h5>
        </div>

        <div class="flex-grow-1">
            <?php if (empty($modulos)): ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <p class="text-muted mb-0">Aún no hay módulos ni archivos publicados para este documento.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($modulos as $mod):
                        $archivos = isset($archivos_por_modulo[$mod['id']]) ? $archivos_por_modulo[$mod['id']] : [];
                        $solo_visualizacion = !empty($mod['solo_visualizacion']);
                    ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card shadow-sm modulo-acordeon-card" data-modulo-id="<?php echo (int)$mod['id']; ?>">
                                <button type="button"
                                        class="modulo-acordeon-toggle card-header bg-white border-bottom-0 w-100 text-start"
                                        aria-expanded="false"
                                        aria-controls="doc-interes-modulo-panel-<?php echo (int)$mod['id']; ?>">
                                    <span class="d-flex align-items-start gap-2 w-100">
                                        <i class="bi bi-chevron-right modulo-acordeon-chevron flex-shrink-0" aria-hidden="true"></i>
                                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary flex-shrink-0 modulo-acordeon-icon">
                                            <i class="bi bi-layers-half"></i>
                                        </span>
                                        <span class="flex-grow-1 min-w-0">
                                            <span class="d-block fw-semibold text-primary modulo-acordeon-titulo">
                                                <?php echo htmlspecialchars($mod['titulo']); ?>
                                                <?php if ($solo_visualizacion): ?>
                                                    <span class="badge bg-info text-dark ms-1 align-middle modulo-acordeon-badge">Solo visualización</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if (!empty($mod['descripcion'])): ?>
                                                <span class="d-block small text-muted mt-1"><?php echo htmlspecialchars($mod['descripcion']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($archivos)): ?>
                                                <span class="d-block small text-muted mt-1">
                                                    <i class="bi bi-paperclip me-1"></i><?php echo count($archivos); ?> archivo<?php echo count($archivos) === 1 ? '' : 's'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </button>
                                <div class="modulo-acordeon-panel collapse"
                                     id="doc-interes-modulo-panel-<?php echo (int)$mod['id']; ?>">
                                    <div class="card-body pt-2 border-top">
                                        <?php if (empty($archivos)): ?>
                                            <div class="alert alert-light border mb-0 small text-muted">
                                                <i class="bi bi-info-circle me-1"></i>Sin archivos en este módulo.
                                            </div>
                                        <?php else: ?>
                                            <div class="list-group list-group-flush">
                                                <?php foreach ($archivos as $ar):
                                                    $url = UPLOAD_URL . 'documentos_interes/' . $ar['archivo'];
                                                    $ext = strtolower(pathinfo($ar['archivo'], PATHINFO_EXTENSION));
                                                    if ($ext === 'pdf') {
                                                        $icono = 'file-earmark-pdf';
                                                    } elseif (in_array($ext, ['xls', 'xlsx'], true)) {
                                                        $icono = 'file-earmark-excel';
                                                    } elseif (in_array($ext, ['doc', 'docx'], true)) {
                                                        $icono = 'file-earmark-word';
                                                    } elseif (in_array($ext, ['ppt', 'pptx'], true)) {
                                                        $icono = 'file-earmark-slides';
                                                    } elseif (in_array($ext, ALLOWED_VIDEO_EXT, true)) {
                                                        $icono = 'play-circle';
                                                    } else {
                                                        $icono = 'file-earmark';
                                                    }
                                                ?>
                                                    <div class="list-group-item px-0">
                                                        <?php if ($solo_visualizacion): ?>
                                                            <button type="button"
                                                                class="btn btn-link text-decoration-none d-flex justify-content-between align-items-start gap-2 p-0 border-0 text-start w-100 doc-viewer-trigger"
                                                                data-nombre="<?php echo htmlspecialchars($ar['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-descripcion="<?php echo htmlspecialchars($ar['descripcion'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-url="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-ext="<?php echo htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?>"
                                                                title="Clic para ver en pantalla completa">
                                                                <div class="d-flex align-items-start gap-2 flex-grow-1">
                                                                    <i class="bi bi-<?php echo $icono; ?> text-primary mt-1"></i>
                                                                    <div class="flex-grow-1">
                                                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($ar['nombre']); ?></div>
                                                                        <?php if (!empty($ar['descripcion'])): ?>
                                                                            <div class="small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                                <i class="bi bi-arrows-fullscreen text-muted mt-1 flex-shrink-0"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <a href="<?php echo htmlspecialchars($url); ?>" class="text-decoration-none d-flex justify-content-between align-items-start gap-2" download>
                                                                <div class="d-flex align-items-start gap-2 flex-grow-1">
                                                                    <i class="bi bi-<?php echo $icono; ?> text-primary mt-1"></i>
                                                                    <div class="flex-grow-1">
                                                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($ar['nombre']); ?></div>
                                                                        <?php if (!empty($ar['descripcion'])): ?>
                                                                            <div class="small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                                <i class="bi bi-download text-muted mt-1 flex-shrink-0"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (($total_paginas_modulos ?? 1) > 1): ?>
                    <?php
                    $rango_inicio_mod = $total_modulos > 0 ? ($offset_modulos + 1) : 0;
                    $rango_fin_mod = $total_modulos > 0 ? min($offset_modulos + $modulos_por_pagina, $total_modulos) : 0;
                    $base_query = ['id' => $documento_id];
                    ?>
                    <div class="row align-items-center mt-3 g-2">
                        <div class="col-12 col-md-4">
                            <div class="small text-muted text-center text-md-start">
                                <?php echo $rango_inicio_mod; ?>-<?php echo $rango_fin_mod; ?> de <?php echo $total_modulos; ?> módulos
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <nav aria-label="Paginación de módulos" class="d-flex justify-content-center">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $pagina_modulos <= 1 ? 'disabled' : ''; ?>">
                                        <?php $prev_q = $base_query + ['pagina_modulos' => $pagina_modulos - 1]; ?>
                                        <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($prev_q)); ?>" aria-label="Anterior" title="Anterior">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                    <?php for ($p = 1; $p <= $total_paginas_modulos; $p++): ?>
                                        <li class="page-item <?php echo $p === $pagina_modulos ? 'active' : ''; ?>">
                                            <?php $p_q = $base_query + ['pagina_modulos' => $p]; ?>
                                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($p_q)); ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $pagina_modulos >= $total_paginas_modulos ? 'disabled' : ''; ?>">
                                        <?php $next_q = $base_query + ['pagina_modulos' => $pagina_modulos + 1]; ?>
                                        <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($next_q)); ?>" aria-label="Siguiente" title="Siguiente">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <div class="col-12 col-md-4"></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="mt-3 mt-auto">
            <a href="<?php echo BASE_URL; ?>datos_interes.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Volver a Documentos de interés</a>
        </div>
    </div>
</div>

<div class="comunicado-fullscreen-overlay" id="docFullscreen" aria-hidden="true" aria-modal="true" role="dialog" aria-labelledby="docFullscreenTitulo" inert>
    <div class="comunicado-fullscreen-backdrop"></div>
    <div class="comunicado-fullscreen-content">
        <button type="button" class="comunicado-fullscreen-close" id="docFullscreenClose" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="comunicado-fullscreen-header">
            <h4 id="docFullscreenTitulo" class="mb-1"></h4>
            <p class="comunicado-fullscreen-fecha mb-0" id="docFullscreenDescripcion"></p>
        </div>
        <div class="comunicado-fullscreen-body" id="docFullscreenBody"></div>
        <div class="comunicado-fullscreen-footer" id="docFullscreenFooter"></div>
    </div>
</div>

<script>
function initModuloAcordeon() {
    document.querySelectorAll('.modulo-acordeon-toggle').forEach(function(toggle) {
        if (toggle._moduloAcordeonBound) return;
        toggle._moduloAcordeonBound = true;

        toggle.addEventListener('click', function() {
            var card = toggle.closest('.modulo-acordeon-card');
            var panel = card ? card.querySelector('.modulo-acordeon-panel') : null;
            if (!panel) return;

            var isOpen = panel.classList.contains('show');
            if (isOpen) {
                panel.classList.remove('show');
                panel.classList.add('collapse');
                toggle.setAttribute('aria-expanded', 'false');
                if (card) card.classList.remove('is-open');
            } else {
                panel.classList.add('show');
                panel.classList.remove('collapse');
                toggle.setAttribute('aria-expanded', 'true');
                if (card) card.classList.add('is-open');
            }
        });
    });
}

function initDocumentoViewer() {
    var overlay = document.getElementById('docFullscreen');
    if (!overlay) return;

    var closeBtn = document.getElementById('docFullscreenClose');
    var backdrop = overlay.querySelector('.comunicado-fullscreen-backdrop');
    var lastFocusedTrigger = null;

    function escHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/\n/g, '<br>');
    }

    function openDocViewer(trigger) {
        lastFocusedTrigger = trigger;
        var nombre = trigger.getAttribute('data-nombre') || '';
        var descripcion = trigger.getAttribute('data-descripcion') || '';
        var url = trigger.getAttribute('data-url') || '';
        var ext = (trigger.getAttribute('data-ext') || '').toLowerCase();

        document.getElementById('docFullscreenTitulo').textContent = nombre;
        document.getElementById('docFullscreenDescripcion').innerHTML = descripcion
            ? escHtml(descripcion)
            : '<span class="text-muted">Documento de consulta</span>';

        var body = document.getElementById('docFullscreenBody');
         var footer = document.getElementById('docFullscreenFooter');
        body.innerHTML = '';
        // footer.innerHTML = '';
        body.className = 'comunicado-fullscreen-body';

        var imagenes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        var videos = ['mp4', 'webm', 'ogg'];
        if (ext === 'pdf') {
            body.innerHTML = '<iframe src="' + url.replace(/"/g, '&quot;') + '#toolbar=0" class="comunicado-contenido-full" title="' + escHtml(nombre) + '"></iframe>';
            // footer.innerHTML = '<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir en nueva pestaña</a>';
        } else if (videos.indexOf(ext) !== -1) {
            body.innerHTML = '<video class="comunicado-contenido-full comunicado-video-full" controls playsinline><source src="' + url.replace(/"/g, '&quot;') + '">Su navegador no soporta la reproducción de video.</video>';
        } else if (imagenes.indexOf(ext) !== -1) {
            body.innerHTML = '<img src="' + url.replace(/"/g, '&quot;') + '" alt="' + escHtml(nombre) + '" class="comunicado-contenido-full comunicado-imagen-full">';
        } else {
            // body.innerHTML = '<div class="comunicado-solo-texto p-4 text-center"><i class="bi bi-file-earmark-text display-4 text-muted"></i><p class="mt-3 mb-0">Este tipo de archivo no puede previsualizarse aquí. Puede abrirlo en una nueva pestaña para consultarlo.</p></div>';
            // footer.innerHTML = '<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir en nueva pestaña</a>';
        }

        overlay.removeAttribute('inert');
        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { if (closeBtn) closeBtn.focus(); }, 50);
    }

    function closeDocViewer() {
        if (lastFocusedTrigger && lastFocusedTrigger.offsetParent !== null) {
            lastFocusedTrigger.focus();
        }
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('inert', '');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.doc-viewer-trigger').forEach(function(trigger) {
        if (trigger._docViewerBound) return;
        trigger._docViewerBound = true;
        trigger.addEventListener('click', function() { openDocViewer(trigger); });
    });
    if (closeBtn) closeBtn.addEventListener('click', closeDocViewer);
    if (backdrop) backdrop.addEventListener('click', closeDocViewer);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('active')) closeDocViewer();
    });
}

function initDocumentoInteresPage() {
    initModuloAcordeon();
    initDocumentoViewer();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDocumentoInteresPage);
} else {
    initDocumentoInteresPage();
}
</script>

<?php require_once 'includes/footer.php'; ?>
