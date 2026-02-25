<?php
require_once '../config/config.php';
requerirPermiso('ver_cursos');

$pdo = getDBConnection();

// Procesar inscripción ANTES de enviar cualquier salida (para poder hacer redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inscribirse'])) {
    $curso_id = (int)$_POST['curso_id'];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO inscripciones (usuario_id, curso_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$_SESSION['usuario_id'], $curso_id]);
        registrarLog($_SESSION['usuario_id'], 'Inscribirse a curso', 'Cursos', "Curso ID: $curso_id");
        header('Location: ' . BASE_URL . 'cursos/index.php?inscrito=1');
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $mensaje = 'Ya estás inscrito en este curso';
        } else {
            $mensaje = 'Error al inscribirse';
        }
        $tipo_mensaje = 'warning';
    }
}

$page_title = 'Cursos Disponibles';
$additional_css = ['assets/css/cursos.css'];
$additional_js = ['assets/js/grid-pagination.js'];

require_once '../includes/header.php';

// Obtener cursos disponibles (misma query para todos los roles; super_admin ve todos)
$stmt = $pdo->prepare("
    SELECT c.*, d.nombre as dependencia_nombre,
           (SELECT COUNT(*) FROM inscripciones WHERE curso_id = c.id AND usuario_id = ?) as inscrito,
           (SELECT completado FROM inscripciones WHERE curso_id = c.id AND usuario_id = ?) as completado,
           (SELECT COUNT(*) FROM intentos_evaluacion ie
            INNER JOIN evaluaciones e ON ie.evaluacion_id = e.id
            INNER JOIN inscripciones i ON ie.inscripcion_id = i.id
            WHERE i.curso_id = c.id AND i.usuario_id = ? AND ie.estado = 'aprobado') as evaluacion_aprobada
    FROM cursos c
    LEFT JOIN dependencias d ON c.dependencia_id = d.id
    WHERE c.activo = 1
    ORDER BY c.fecha_creacion DESC
");
$stmt->execute([$_SESSION['usuario_id'], $_SESSION['usuario_id'], $_SESSION['usuario_id']]);
$cursos = $stmt->fetchAll();
?>

<div class="container-fluid dashboard-wrap mt-4">
    <h2 class="mb-3"><i class="bi bi-book me-2"></i>Cursos Disponibles</h2>

    <?php if (isset($_GET['inscrito'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Te has inscrito exitosamente al curso
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($mensaje)): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($cursos)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>No hay cursos disponibles en este momento
        </div>
    <?php else: ?>
        <div class="cursos-panel flex-grow-1 d-flex flex-column">
            <div class="cursos-panel-header">
                <span class="cursos-panel-title"><i class="bi bi-collection-play me-2"></i>Cursos</span>
            </div>
            <div class="cursos-panel-content cursos-grid-wrap">
                <div class="cursos-grid" id="cursosGrid" data-blocks-per-page="6">
                    <?php foreach ($cursos as $index => $curso):
                        $imagen_url = !empty($curso['imagen']) ? UPLOAD_URL . $curso['imagen'] : '';
                        $nombre = htmlspecialchars($curso['nombre']);
                        $descripcion = htmlspecialchars($curso['descripcion'] ?? '');
                        $dependencia = htmlspecialchars($curso['dependencia_nombre'] ?? 'General');
                        $inscrito = (int)($curso['inscrito'] ?? 0);
                        $evaluacion_aprobada = (int)($curso['evaluacion_aprobada'] ?? 0);
                    ?>
                        <div class="curso-block"
                             data-index="<?php echo $index; ?>"
                             data-curso-id="<?php echo (int)$curso['id']; ?>"
                             data-nombre="<?php echo $nombre; ?>"
                             data-descripcion="<?php echo $descripcion; ?>"
                             data-imagen="<?php echo htmlspecialchars($imagen_url); ?>"
                             data-dependencia="<?php echo $dependencia; ?>"
                             data-inscrito="<?php echo $inscrito; ?>"
                             data-evaluacion-aprobada="<?php echo $evaluacion_aprobada; ?>"
                             role="button" tabindex="0" title="Clic para ver detalle">
                            <div class="curso-block-inner">
                                <?php if ($imagen_url): ?>
                                    <div class="curso-block-preview" style="background-image: url('<?php echo htmlspecialchars($imagen_url); ?>');"></div>
                                <?php else: ?>
                                    <div class="curso-block-preview curso-block-preview-icon">
                                        <i class="bi bi-book"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="curso-block-info">
                                    <strong><?php echo $nombre; ?></strong>
                                    <span><i class="bi bi-diagram-3 me-1"></i><?php echo $dependencia; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <nav class="cursos-pagination-wrap mt-3" id="cursosPagination" aria-label="Paginación de cursos"></nav>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($cursos)): ?>
<!-- Modal fullscreen para detalle del curso -->
<div class="curso-fullscreen-overlay" id="cursoFullscreen" aria-hidden="true" aria-modal="true" role="dialog" aria-labelledby="cursoFullscreenTitulo" inert>
    <div class="curso-fullscreen-backdrop"></div>
    <div class="curso-fullscreen-content">
        <button type="button" class="curso-fullscreen-close" id="cursoFullscreenClose" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="curso-fullscreen-header">
            <h4 id="cursoFullscreenTitulo"></h4>
            <span id="cursoFullscreenDependencia"></span>
        </div>
        <div class="curso-fullscreen-body" id="cursoFullscreenBody"></div>
        <div class="curso-fullscreen-footer" id="cursoFullscreenFooter"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var grid = document.getElementById('cursosGrid');
    var overlay = document.getElementById('cursoFullscreen');
    var closeBtn = document.getElementById('cursoFullscreenClose');
    var backdrop = overlay ? overlay.querySelector('.curso-fullscreen-backdrop') : null;
    var lastFocusedBlock = null;

    function openFullscreen(block) {
        if (!overlay) return;
        lastFocusedBlock = block;
        var nombre = block.getAttribute('data-nombre') || '';
        var descripcion = block.getAttribute('data-descripcion') || '';
        var imagen = block.getAttribute('data-imagen') || '';
        var dependencia = block.getAttribute('data-dependencia') || '';
        var cursoId = block.getAttribute('data-curso-id') || '';
        var inscrito = block.getAttribute('data-inscrito') === '1';
        var evaluacionAprobada = parseInt(block.getAttribute('data-evaluacion-aprobada') || '0', 10);

        document.getElementById('cursoFullscreenTitulo').textContent = nombre;
        document.getElementById('cursoFullscreenDependencia').innerHTML = '<i class="bi bi-diagram-3 me-1"></i>' + dependencia.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var body = document.getElementById('cursoFullscreenBody');
        var footer = document.getElementById('cursoFullscreenFooter');

        if (imagen) {
            body.innerHTML = '<img src="' + imagen.replace(/"/g, '&quot;') + '" alt="' + nombre.replace(/"/g, '&quot;') + '" class="curso-fullscreen-imagen">';
        } else {
            body.innerHTML = '<div class="curso-fullscreen-sin-imagen"><i class="bi bi-book"></i></div>';
        }
        body.innerHTML += '<div class="curso-fullscreen-descripcion"><p>' + (descripcion || 'Sin descripción.').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</p></div>';

        if (inscrito) {
            if (evaluacionAprobada > 0) {
                footer.innerHTML = '<span class="badge bg-success me-2"><i class="bi bi-trophy-fill me-1"></i>Finalizado</span><a href="ver_curso.php?id=' + cursoId + '" class="btn btn-light btn-sm"><i class="bi bi-play-circle me-1"></i>Ver curso</a>';
            } else {
                footer.innerHTML = '<span class="badge bg-info me-2"><i class="bi bi-check-circle me-1"></i>Inscrito</span><a href="ver_curso.php?id=' + cursoId + '" class="btn btn-primary btn-sm"><i class="bi bi-play-circle me-1"></i>Continuar</a>';
            }
        } else {
            footer.innerHTML = '<form method="POST" class="d-inline"><input type="hidden" name="curso_id" value="' + cursoId + '"><button type="submit" name="inscribirse" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Inscribirse</button></form>';
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
        grid.querySelectorAll('.curso-block').forEach(function(block) {
            block.addEventListener('click', function(e) {
                if (e.target.closest('a') || e.target.closest('button') || e.target.closest('form')) return;
                openFullscreen(block);
            });
            block.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFullscreen(block); } });
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', closeFullscreen);
    if (backdrop) backdrop.addEventListener('click', closeFullscreen);
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeFullscreen(); });

    initGridPagination({ grid: '#cursosGrid', paginationWrap: '#cursosPagination' });
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
