<?php
require_once dirname(__DIR__) . '/config/config.php';
requerirAutenticacion();
if (!tienePermiso('ver_sst')) {
    header('Location: ' . BASE_URL . 'login.php?error=sin_permiso');
    exit;
}

$documento_id = (int)($_GET['id'] ?? 0);
if (!$documento_id) {
    header('Location: ' . BASE_URL . 'sst/index.php');
    exit;
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT d.*, dep.nombre AS dependencia_nombre
    FROM sst_documentos d
    LEFT JOIN dependencias dep ON dep.id = d.dependencia_id
    WHERE d.id = ?
");
$stmt->execute([$documento_id]);
$documento = $stmt->fetch();
if (!$documento) {
    header('Location: ' . BASE_URL . 'sst/index.php');
    exit;
}

$modulos = [];
$archivos_por_modulo = [];
try {
    // Paginación de módulos (máximo 6 por página)
    $modulos_por_pagina = 6;
    $pagina_modulos = isset($_GET['pagina_modulos']) ? max(1, (int)$_GET['pagina_modulos']) : 1;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM modulos_sst WHERE sst_documento_id = ?");
    $stmt->execute([$documento_id]);
    $total_modulos = (int)($stmt->fetch()['total'] ?? 0);
    $total_paginas_modulos = $total_modulos > 0 ? (int)ceil($total_modulos / $modulos_por_pagina) : 1;
    $pagina_modulos = min($pagina_modulos, $total_paginas_modulos);
    $offset_modulos = ($pagina_modulos - 1) * $modulos_por_pagina;

    $stmt = $pdo->prepare("
        SELECT *
        FROM modulos_sst
        WHERE sst_documento_id = ?
        ORDER BY orden ASC, id ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, (int)$documento_id, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$modulos_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(3, (int)$offset_modulos, PDO::PARAM_INT);
    $stmt->execute();
    $modulos = $stmt->fetchAll();

    foreach ($modulos as $mod) {
        $stmt2 = $pdo->prepare("SELECT * FROM archivos_sst WHERE modulo_id = ? ORDER BY orden ASC, id ASC");
        $stmt2->execute([$mod['id']]);
        $archivos_por_modulo[$mod['id']] = $stmt2->fetchAll();
    }
} catch (Exception $e) {
}
require_once dirname(__DIR__) . '/includes/sst_helpers.php';
require_once dirname(__DIR__) . '/includes/auditoria_helpers.php';
registrarVista($pdo, $_SESSION['usuario_id'], 'sst', $documento_id);
$page_title = 'Documento: ' . $documento['nombre'];
$additional_css = ['assets/css/main.css', 'assets/css/dashboard.css'];
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="sst-page-wrap">
    <nav aria-label="breadcrumb" class="sst-breadcrumb-bar">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>sst/index.php">SST</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($documento['nombre']); ?></li>
        </ol>
    </nav>

    <div class="sst-doc-hero">
        <?php if (!empty($documento['imagen'])): ?>
            <img src="<?php echo UPLOAD_URL . htmlspecialchars($documento['imagen']); ?>" class="sst-doc-hero-img" alt="">
        <?php else: ?>
            <div class="sst-doc-hero-icon"><i class="bi bi-shield-check"></i></div>
        <?php endif; ?>
        <div class="sst-doc-hero-info">
            <span class="sst-doc-hero-badge"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($documento['dependencia_nombre'] ?? 'Sin dependencia'); ?></span>
            <h2 class="sst-doc-hero-title"><?php echo htmlspecialchars($documento['nombre']); ?></h2>
            <?php if (!empty($documento['descripcion'])): ?>
                <div class="sst-doc-hero-desc" id="sstHeroDesc"><?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?></div>
                <button type="button" class="sst-doc-hero-toggle" id="sstHeroToggle">Ver más <i class="bi bi-chevron-down"></i></button>
            <?php endif; ?>
        </div>
        <a href="<?php echo BASE_URL; ?>sst/index.php" class="btn btn-light btn-sm sst-doc-hero-back">
            <i class="bi bi-arrow-left me-1"></i>Volver
        </a>
    </div>

    <div class="sst-modulos-wrap">
        <?php if (empty($modulos)): ?>
            <div class="sst-empty-state">
                <p class="text-muted mb-0">Aún no hay módulos ni archivos publicados para este documento.</p>
            </div>
        <?php else: ?>
            <?php foreach ($modulos as $mod):
                $archivos = isset($archivos_por_modulo[$mod['id']]) ? $archivos_por_modulo[$mod['id']] : [];
                $solo_visualizacion = !empty($mod['solo_visualizacion']);
            ?>
                <section class="sst-modulo-section">
                    <?php if (count($modulos) > 1): ?>
                        <div class="sst-modulo-heading">
                            <h5 class="mb-0 text-primary"><?php echo htmlspecialchars($mod['titulo']); ?></h5>
                            <?php if (!empty($mod['descripcion'])): ?>
                                <span class="small text-muted"><?php echo htmlspecialchars($mod['descripcion']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($archivos)): ?>
                        <div class="sst-empty-state">
                            <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Sin archivos en este módulo.</p>
                        </div>
                    <?php else: ?>
                        <div class="sst-archivos-grid">
                            <?php foreach ($archivos as $ar):
                                $url = UPLOAD_URL_SST . $ar['archivo'];
                                $ext = strtolower(pathinfo($ar['archivo'], PATHINFO_EXTENSION));
                                $imagenes_galeria = obtenerImagenesArchivoSst($pdo, $ar);
                                $es_galeria = count($imagenes_galeria) > 1;
                                $urls_galeria = array_map(function ($img) {
                                    return UPLOAD_URL_SST . $img;
                                }, $imagenes_galeria);
                                $es_imagen = esExtensionImagenSst($ext) || $es_galeria;
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
                                $es_video = in_array($ext, ALLOWED_VIDEO_EXT, true);
                                $thumb_url = $es_galeria ? ($urls_galeria[0] ?? $url) : $url;
                            ?>
                                <div class="sst-archivo-card">
                                    <button type="button"
                                        class="sst-archivo-preview-btn doc-viewer-trigger"
                                        data-nombre="<?php echo htmlspecialchars($ar['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-descripcion="<?php echo htmlspecialchars($ar['descripcion'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-url="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-ext="<?php echo htmlspecialchars($es_galeria ? 'galeria' : $ext, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php if ($es_galeria || ($es_imagen && count($urls_galeria) >= 1)): ?>
                                        data-galeria="<?php echo htmlspecialchars(json_encode($urls_galeria, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php endif; ?>
                                        title="Clic para ver en pantalla completa">
                                        <?php if ($es_imagen): ?>
                                            <img src="<?php echo htmlspecialchars($thumb_url); ?>" alt="<?php echo htmlspecialchars($ar['nombre']); ?>" class="sst-archivo-imagen">
                                            <?php if ($es_galeria): ?>
                                                <span class="sst-archivo-galeria-badge"><i class="bi bi-images me-1"></i><?php echo count($imagenes_galeria); ?></span>
                                            <?php endif; ?>
                                        <?php elseif ($ext === 'pdf'): ?>
                                            <span class="sst-archivo-imagen sst-archivo-pdf-thumb-wrap">
                                                <canvas class="sst-archivo-pdf-thumb" data-pdf-url="<?php echo htmlspecialchars($url); ?>"></canvas>
                                            </span>
                                        <?php elseif ($es_video): ?>
                                            <span class="sst-archivo-imagen sst-archivo-imagen-icono">
                                                <i class="bi bi-play-circle-fill"></i>
                                            </span>
                                        <?php else: ?>
                                            <span class="sst-archivo-imagen sst-archivo-imagen-icono">
                                                <i class="bi bi-<?php echo $icono; ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                    </button>
                                    <div class="sst-archivo-footer">
                                        <span class="sst-archivo-titulo" title="<?php echo htmlspecialchars($ar['nombre']); ?>"><?php echo htmlspecialchars($ar['nombre']); ?></span>
                                        <?php if (!$solo_visualizacion && !$es_galeria): ?>
                                            <a href="<?php echo htmlspecialchars($url); ?>" class="sst-archivo-download" download title="Descargar">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if (($total_paginas_modulos ?? 1) > 1): ?>
                <?php
                $rango_inicio_mod = $total_modulos > 0 ? ($offset_modulos + 1) : 0;
                $rango_fin_mod = $total_modulos > 0 ? min($offset_modulos + $modulos_por_pagina, $total_modulos) : 0;
                $base_query = ['id' => $documento_id];
                ?>
                <div class="sst-pagination-wrap row align-items-center g-2">
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
</script>
<script>
    (function() {
        var toggle = document.getElementById('sstHeroToggle');
        var desc = document.getElementById('sstHeroDesc');
        if (!toggle || !desc) return;
        toggle.addEventListener('click', function() {
            var expanded = desc.classList.toggle('expanded');
            toggle.classList.toggle('expanded', expanded);
            toggle.innerHTML = expanded ? 'Ver menos <i class="bi bi-chevron-down"></i>' : 'Ver más <i class="bi bi-chevron-down"></i>';
        });
    })();

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
            var galeriaRaw = trigger.getAttribute('data-galeria') || '';
            var galeriaUrls = [];
            if (galeriaRaw) {
                try {
                    galeriaUrls = JSON.parse(galeriaRaw);
                } catch (e) {
                    galeriaUrls = [];
                }
            }

            document.getElementById('docFullscreenTitulo').textContent = nombre;

            var body = document.getElementById('docFullscreenBody');
            var footer = document.getElementById('docFullscreenFooter');
            footer.innerHTML = '';

            var imagenes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            var videos = ['mp4', 'webm', 'ogg'];
            var mediaHtml = '';
            var esGaleria = ext === 'galeria' || (Array.isArray(galeriaUrls) && galeriaUrls.length > 1);

            if (esGaleria && galeriaUrls.length) {
                var carouselId = 'sstGaleriaCarousel';
                var items = '';
                galeriaUrls.forEach(function(imgUrl, idx) {
                    items += '<div class="carousel-item' + (idx === 0 ? ' active' : '') + '">' +
                        '<img src="' + String(imgUrl).replace(/"/g, '&quot;') + '" class="comunicado-contenido-full comunicado-imagen-full" alt="' + escHtml(nombre) + ' (' + (idx + 1) + ')">' +
                        '</div>';
                });
                mediaHtml =
                    '<div id="' + carouselId + '" class="carousel slide comunicados-carousel-full sst-galeria-carousel" data-bs-ride="false">' +
                    '<div class="carousel-inner">' + items + '</div>' +
                    '<button class="carousel-control-prev" type="button" data-bs-target="#' + carouselId + '" data-bs-slide="prev">' +
                    '<span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Anterior</span></button>' +
                    '<button class="carousel-control-next" type="button" data-bs-target="#' + carouselId + '" data-bs-slide="next">' +
                    '<span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Siguiente</span></button>' +
                    '<div class="carousel-indicators">' +
                    galeriaUrls.map(function(_, idx) {
                        return '<button type="button" data-bs-target="#' + carouselId + '" data-bs-slide-to="' + idx + '"' +
                            (idx === 0 ? ' class="active" aria-current="true"' : '') + ' aria-label="Imagen ' + (idx + 1) + '"></button>';
                    }).join('') +
                    '</div></div>';
            } else if (ext === 'pdf') {
                mediaHtml = '<iframe src="' + url.replace(/"/g, '&quot;') + '#toolbar=0" class="comunicado-contenido-full" title="' + escHtml(nombre) + '"></iframe>';
            } else if (videos.indexOf(ext) !== -1) {
                mediaHtml = '<video class="comunicado-contenido-full comunicado-video-full" controls playsinline><source src="' + url.replace(/"/g, '&quot;') + '">Su navegador no soporta la reproducción de video.</video>';
            } else if (imagenes.indexOf(ext) !== -1 || (galeriaUrls.length === 1)) {
                var imgSrc = galeriaUrls.length === 1 ? galeriaUrls[0] : url;
                mediaHtml = '<img src="' + String(imgSrc).replace(/"/g, '&quot;') + '" alt="' + escHtml(nombre) + '" class="comunicado-contenido-full comunicado-imagen-full">';
            } else {
                mediaHtml = '<div class="comunicado-solo-texto p-4 text-center d-flex align-items-center justify-content-center h-100"><div><i class="bi bi-file-earmark-text display-4 text-muted"></i><p class="mt-3 mb-0">Vista previa no disponible para este tipo de archivo.</p></div></div>';
            }

            body.className = 'comunicado-fullscreen-body comunicado-fullscreen-body-dos-columnas';
            body.innerHTML =
                '<div class="comunicado-fullscreen-col comunicado-fullscreen-col-media">' + mediaHtml + '</div>' +
                '<div class="comunicado-fullscreen-col comunicado-fullscreen-col-contenido">' +
                '<div class="comunicado-contenido-label"><i class="bi bi-card-text me-1"></i>Contenido</div>' +
                '<div class="comunicado-contenido-texto p-3">' + (descripcion ? escHtml(descripcion) : '') +
                (esGaleria ? '<p class="small text-muted mt-3 mb-0"><i class="bi bi-images me-1"></i>' + galeriaUrls.length + ' imágenes · use las flechas para navegar</p>' : '') +
                '</div></div>';

            if (!esGaleria && ext !== 'pdf' && imagenes.indexOf(ext) === -1 && videos.indexOf(ext) === -1) {
                footer.innerHTML = '<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir en nueva pestaña</a>';
            }

            overlay.removeAttribute('inert');
            overlay.setAttribute('aria-hidden', 'false');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(function() {
                if (closeBtn) closeBtn.focus();
            }, 50);
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
            trigger.addEventListener('click', function() {
                openDocViewer(trigger);
            });
        });
        if (closeBtn) closeBtn.addEventListener('click', closeDocViewer);
        if (backdrop) backdrop.addEventListener('click', closeDocViewer);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeDocViewer();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDocumentoViewer);
    } else {
        initDocumentoViewer();
    }

    function renderPdfThumbnails() {
        var canvases = document.querySelectorAll('.sst-archivo-pdf-thumb');
        if (!canvases.length || typeof pdfjsLib === 'undefined') return;

        canvases.forEach(function(canvas) {
            var url = canvas.getAttribute('data-pdf-url');
            pdfjsLib.getDocument(url).promise.then(function(pdf) {
                return pdf.getPage(1);
            }).then(function(page) {
                var container = canvas.closest('.sst-archivo-pdf-thumb-wrap');
                var targetWidth = (container ? container.clientWidth : 240) || 240;
                var viewportBase = page.getViewport({
                    scale: 1
                });
                var scale = targetWidth / viewportBase.width;
                var viewport = page.getViewport({
                    scale: scale
                });

                canvas.width = viewport.width;
                canvas.height = viewport.height;
                var ctx = canvas.getContext('2d');
                page.render({
                    canvasContext: ctx,
                    viewport: viewport
                });
            }).catch(function() {
                // Si falla (ej. sin internet para el worker), deja el canvas vacío;
                // opcionalmente podrías reemplazarlo por el ícono aquí.
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderPdfThumbnails);
    } else {
        renderPdfThumbnails();
    }
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>