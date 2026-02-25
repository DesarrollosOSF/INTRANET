<?php
require_once 'config/config.php';
requerirPermiso('ver_dashboard');

$page_title = 'Dashboard';
$additional_css = ['assets/css/dashboard.css'];
$additional_js = ['assets/js/grid-pagination.js'];

require_once 'includes/header.php';

// Obtener comunicados destacados
$mensaje = '';
$tipo_mensaje = '';

try {
    $pdo = getDBConnection();
    
    // Comunicados importantes (solo informativos en el Dashboard)
    $stmt = $pdo->prepare("
        SELECT * FROM comunicados 
        WHERE activo = 1 
        AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
        ORDER BY destacado DESC, fecha_publicacion DESC
    ");
    $stmt->execute();
    $comunicados = $stmt->fetchAll();
    
    // Colaborador del mes
    $mes_actual = date('n');
    $anio_actual = date('Y');
    $stmt = $pdo->prepare("
        SELECT cm.*, u.nombre_completo, u.email
        FROM colaborador_mes cm
        INNER JOIN usuarios u ON cm.usuario_id = u.id
        WHERE cm.mes = ? AND cm.anio = ?
    ");
    $stmt->execute([$mes_actual, $anio_actual]);
    $colaborador_mes = $stmt->fetch();
    
    // Estadísticas rápidas
    $stats = [];
    
    // Total de cursos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE activo = 1");
    $stats['cursos'] = $stmt->fetch()['total'];
    
    // Cursos inscritos del usuario
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM inscripciones 
        WHERE usuario_id = ?
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $stats['mis_cursos'] = $stmt->fetch()['total'];
    
    // Cursos completados
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM inscripciones 
        WHERE usuario_id = ? AND completado = 1
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $stats['completados'] = $stmt->fetch()['total'];
    
    // Eventos próximos
    $stmt = $pdo->prepare("
        SELECT * FROM comunicados 
        WHERE tipo = 'evento' 
        AND activo = 1 
        AND fecha_publicacion >= NOW()
        ORDER BY fecha_publicacion ASC 
        LIMIT 5
    ");
    $stmt->execute();
    $eventos = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error en dashboard: " . $e->getMessage());
    $comunicados = [];
    $colaborador_mes = null;
    $stats = ['cursos' => 0, 'mis_cursos' => 0, 'completados' => 0];
    $eventos = [];
}
?>

<div class="container-fluid dashboard-wrap mt-4">
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row row-comunicados">
        <!-- Colaborador del Mes -->
        <?php if ($colaborador_mes): ?>
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card h-100 shadow-sm animate__animated animate__fadeInLeft">
                <div class="card-header bg-gradient-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-trophy-fill me-2"></i>Colaborador del Mes</h5>
                </div>
                <div class="card-body text-center">
                    <div class="colaborador-avatar mb-3">
                        <i class="bi bi-person-circle" style="font-size: 80px; color: #667eea;"></i>
                    </div>
                    <h4><?php echo htmlspecialchars($colaborador_mes['nombre_completo']); ?></h4>
                    <p class="text-muted"><?php echo htmlspecialchars($colaborador_mes['email']); ?></p>
                    <?php if ($colaborador_mes['motivo']): ?>
                        <p class="mt-3"><?php echo htmlspecialchars($colaborador_mes['motivo']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Comunicados Importantes: 3 columnas × 2 filas, clic = pantalla completa -->
        <div class="col-lg-<?php echo $colaborador_mes ? '8' : '12'; ?> d-flex comunicados-col">
            <div class="comunicados-panel flex-grow-1 d-flex flex-column">
                <div class="comunicados-panel-header">
                    <span class="comunicados-panel-title"><i class="bi bi-megaphone-fill me-2"></i>Comunicados Importantes</span>
                </div>
                <div class="comunicados-panel-content comunicados-grid-wrap">
                    <?php if (empty($comunicados)): ?>
                        <p class="text-muted text-center py-5">No hay comunicados disponibles</p>
                    <?php else: ?>
                        <div class="comunicados-grid" id="comunicadosGrid" data-blocks-per-page="6">
                            <?php foreach ($comunicados as $index => $comunicado): 
                                $archivo_url = !empty($comunicado['imagen']) ? UPLOAD_URL . $comunicado['imagen'] : '';
                                $es_pdf = $archivo_url && (strtolower(pathinfo($comunicado['imagen'], PATHINFO_EXTENSION)) === 'pdf');
                                $titulo = htmlspecialchars($comunicado['titulo']);
                                $fecha = date('d/m/Y', strtotime($comunicado['fecha_publicacion']));
                            ?>
                                <div class="comunicado-block" 
                                     data-index="<?php echo $index; ?>"
                                     data-titulo="<?php echo $titulo; ?>"
                                     data-fecha="<?php echo $fecha; ?>"
                                     data-pdf="<?php echo $es_pdf ? '1' : '0'; ?>"
                                     data-url="<?php echo htmlspecialchars($archivo_url); ?>"
                                     role="button" tabindex="0" title="Clic para ver en pantalla completa">
                                    <div class="d-none comunicado-block-raw-contenido"><?php echo htmlspecialchars($comunicado['contenido'] ?? ''); ?></div>
                                    <div class="comunicado-block-inner">
                                        <?php if ($archivo_url): ?>
                                            <?php if ($es_pdf): ?>
                                                <div class="comunicado-block-preview comunicado-block-preview-pdf">
                                                    <i class="bi bi-file-earmark-pdf-fill"></i>
                                                    <span>PDF</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="comunicado-block-preview" style="background-image: url('<?php echo htmlspecialchars($archivo_url); ?>');"></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="comunicado-block-preview comunicado-block-preview-texto">
                                                <i class="bi bi-card-text"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="comunicado-block-info">
                                            <strong><?php echo $titulo; ?></strong>
                                            <span><i class="bi bi-calendar me-1"></i><?php echo $fecha; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <nav class="comunicados-pagination-wrap mt-3" id="comunicadosPagination" aria-label="Paginación de comunicados"></nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal fullscreen para comunicado (aria-hidden e inert se gestionan en JS al abrir/cerrar) -->
    <div class="comunicado-fullscreen-overlay" id="comunicadoFullscreen" aria-hidden="true" aria-modal="true" role="dialog" aria-labelledby="comunicadoFullscreenTitulo" inert>
        <div class="comunicado-fullscreen-backdrop"></div>
        <div class="comunicado-fullscreen-content">
            <button type="button" class="comunicado-fullscreen-close" id="comunicadoFullscreenClose" aria-label="Cerrar">
                <i class="bi bi-x-lg"></i>
            </button>
            <div class="comunicado-fullscreen-header">
                <h4 id="comunicadoFullscreenTitulo"></h4>
                <span id="comunicadoFullscreenFecha"></span>
            </div>
            <div class="comunicado-fullscreen-body" id="comunicadoFullscreenBody"></div>
            <div class="comunicado-fullscreen-footer" id="comunicadoFullscreenFooter"></div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var grid = document.getElementById('comunicadosGrid');
        var overlay = document.getElementById('comunicadoFullscreen');
        var closeBtn = document.getElementById('comunicadoFullscreenClose');
        var backdrop = overlay ? overlay.querySelector('.comunicado-fullscreen-backdrop') : null;
        var lastFocusedBlock = null;

        function openFullscreen(block) {
            if (!overlay) return;
            lastFocusedBlock = block;
            var titulo = block.getAttribute('data-titulo') || '';
            var fecha = block.getAttribute('data-fecha') || '';
            var url = block.getAttribute('data-url') || '';
            var esPdf = block.getAttribute('data-pdf') === '1';
            var rawEl = block.querySelector('.comunicado-block-raw-contenido');
            var contenido = rawEl ? rawEl.textContent : '';

            document.getElementById('comunicadoFullscreenTitulo').textContent = titulo;
            document.getElementById('comunicadoFullscreenFecha').textContent = fecha;
            var body = document.getElementById('comunicadoFullscreenBody');
            var footer = document.getElementById('comunicadoFullscreenFooter');
            body.innerHTML = '';
            footer.innerHTML = '';

            if (url) {
                if (esPdf) {
                    body.innerHTML = '<iframe src="' + url.replace(/"/g, '&quot;') + '#toolbar=0" class="comunicado-contenido-full" title="' + titulo.replace(/"/g, '&quot;') + '"></iframe>';
                    footer.innerHTML = '<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir en nueva pestaña</a>';
                } else {
                    body.innerHTML = '<img src="' + url.replace(/"/g, '&quot;') + '" alt="' + titulo.replace(/"/g, '&quot;') + '" class="comunicado-contenido-full comunicado-imagen-full">';
                }
            } else {
                var esc = contenido.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/\n/g, '<br>');
                body.innerHTML = '<div class="comunicado-solo-texto p-4"><p class="text-muted">' + esc + '</p></div>';
            }

            overlay.removeAttribute('inert');
            overlay.setAttribute('aria-hidden', 'false');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(function() { if (closeBtn) closeBtn.focus(); }, 50);
        }

        function closeFullscreen() {
            if (!overlay) return;
            if (lastFocusedBlock && lastFocusedBlock.offsetParent !== null) {
                lastFocusedBlock.focus();
            }
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('inert', '');
            document.body.style.overflow = '';
        }

        if (grid) {
            grid.querySelectorAll('.comunicado-block').forEach(function(block) {
                block.addEventListener('click', function() { openFullscreen(block); });
                block.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFullscreen(block); } });
            });
        }
        if (closeBtn) closeBtn.addEventListener('click', closeFullscreen);
        if (backdrop) backdrop.addEventListener('click', closeFullscreen);
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeFullscreen(); });

        initGridPagination({ grid: '#comunicadosGrid', paginationWrap: '#comunicadosPagination' });
    });
    </script>
    
</div>

<?php require_once 'includes/footer.php'; ?>
