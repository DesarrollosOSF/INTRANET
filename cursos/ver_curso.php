<?php
require_once '../config/config.php';
requerirPermiso('ver_cursos');

$page_title = 'Ver Curso';
$additional_css = ['assets/css/curso-viewer.css'];

$pdo = getDBConnection();
$curso_id = (int)($_GET['id'] ?? 0);

if (!$curso_id) {
    header('Location: index.php');
    exit;
}

// Verificar inscripción
$stmt = $pdo->prepare("
    SELECT i.*, c.nombre as curso_nombre, c.descripcion as curso_descripcion, c.imagen as curso_imagen
    FROM inscripciones i
    INNER JOIN cursos c ON i.curso_id = c.id
    WHERE i.usuario_id = ? AND i.curso_id = ?
");
$stmt->execute([$_SESSION['usuario_id'], $curso_id]);
$inscripcion = $stmt->fetch();

if (!$inscripcion) {
    header('Location: index.php');
    exit;
}

// Obtener módulos del curso (si existe la tabla)
$modulos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$curso_id]);
    $modulos = $stmt->fetchAll();
} catch (Exception $e) {
    $modulos = [];
}

// Obtener materiales ordenados por módulo y orden (si existe modulo_id)
try {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               COALESCE(pm.tiempo_visualizado, 0) as tiempo_visualizado,
               COALESCE(pm.completado, 0) as completado
        FROM materiales m
        LEFT JOIN progreso_material pm ON m.id = pm.material_id AND pm.inscripcion_id = ?
        LEFT JOIN modulos mo ON m.modulo_id = mo.id
        WHERE m.curso_id = ?
        ORDER BY COALESCE(mo.orden, 999) ASC, m.orden ASC, m.fecha_creacion ASC
    ");
    $stmt->execute([$inscripcion['id'], $curso_id]);
    $materiales = $stmt->fetchAll();
} catch (Exception $e) {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               COALESCE(pm.tiempo_visualizado, 0) as tiempo_visualizado,
               COALESCE(pm.completado, 0) as completado
        FROM materiales m
        LEFT JOIN progreso_material pm ON m.id = pm.material_id AND pm.inscripcion_id = ?
        WHERE m.curso_id = ?
        ORDER BY m.orden ASC, m.fecha_creacion ASC
    ");
    $stmt->execute([$inscripcion['id'], $curso_id]);
    $materiales = $stmt->fetchAll();
}

// Agrupar materiales por módulo para la sidebar
$materiales_por_modulo = [];
$materiales_sin_modulo = [];
foreach ($materiales as $m) {
    $mid = isset($m['modulo_id']) ? $m['modulo_id'] : null;
    if ($mid) {
        if (!isset($materiales_por_modulo[$mid])) $materiales_por_modulo[$mid] = [];
        $materiales_por_modulo[$mid][] = $m;
    } else {
        $materiales_sin_modulo[] = $m;
    }
}

// Obtener evaluación (intentos_realizados = solo finalizados aprobado/reprobado, no en_proceso)
$stmt = $pdo->prepare("
    SELECT e.*,
           (SELECT COUNT(*) FROM intentos_evaluacion 
            WHERE inscripcion_id = ? AND evaluacion_id = e.id AND estado IN ('aprobado', 'reprobado')) as intentos_realizados,
           (SELECT COUNT(*) FROM intentos_evaluacion 
            WHERE inscripcion_id = ? AND evaluacion_id = e.id AND estado = 'aprobado') as evaluacion_aprobada
    FROM evaluaciones e
    WHERE e.curso_id = ? AND e.activo = 1
");
$stmt->execute([$inscripcion['id'], $inscripcion['id'], $curso_id]);
$evaluacion = $stmt->fetch();

// Verificar si el curso está finalizado (evaluación aprobada)
$curso_finalizado = false;
if ($evaluacion && ($evaluacion['evaluacion_aprobada'] ?? 0) > 0) {
    $curso_finalizado = true;
}

// Verificar si puede presentar evaluación (solo si no está finalizado)
$puede_evaluar = false;
if ($evaluacion && $inscripcion['completado'] && !$curso_finalizado) {
    $intentos_restantes = $evaluacion['numero_intentos'] - ($evaluacion['intentos_realizados'] ?? 0);
    $puede_evaluar = $intentos_restantes > 0;
}

// Verificar si hay un intento en proceso
$intento_activo = null;
if ($evaluacion) {
    $stmt = $pdo->prepare("
        SELECT * FROM intentos_evaluacion
        WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado = 'en_proceso'
        ORDER BY fecha_inicio DESC
        LIMIT 1
    ");
    $stmt->execute([$inscripcion['id'], $evaluacion['id']]);
    $intento_activo = $stmt->fetch();
}

// Intentos restantes e intentos agotados
$intentos_restantes = $evaluacion ? ($evaluacion['numero_intentos'] - ($evaluacion['intentos_realizados'] ?? 0)) : 0;
$intentos_agotados = $evaluacion && $inscripcion['completado'] && !$curso_finalizado && !$intento_activo && $intentos_restantes <= 0;
// Continuar evaluación pero con intentos agotados: tiene intento en proceso pero ya no le quedan intentos (al entrar lo redirigirían)
$continuar_pero_intentos_agotados = $evaluacion && $intento_activo && $intentos_restantes <= 0;
$mostrar_modal_intentos = (isset($_GET['error']) && $_GET['error'] === 'sin_intentos' && $intentos_agotados) || $continuar_pero_intentos_agotados;

require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-3">
            <div class="card shadow-sm sticky-top" style="top: 20px;">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><?php echo htmlspecialchars($inscripcion['curso_nombre']); ?></h5>
                </div>
                <div class="card-body">
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" 
                             style="width: <?php echo $inscripcion['progreso']; ?>%">
                            <?php echo number_format($inscripcion['progreso'], 1); ?>%
                        </div>
                    </div>
                    
                    <h6 class="mb-3">Contenido del Curso</h6>
                    <div class="list-group list-group-flush">
                        <?php if (!empty($modulos)): ?>
                            <?php foreach ($modulos as $mod): 
                                $mat_list = isset($materiales_por_modulo[$mod['id']]) ? $materiales_por_modulo[$mod['id']] : [];
                            ?>
                                <div class="list-group-item list-group-item-secondary py-2 small fw-bold">
                                    <i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?>
                                </div>
                                <?php foreach ($mat_list as $material): ?>
                                    <a href="?id=<?php echo $curso_id; ?>&material=<?php echo $material['id']; ?>" 
                                       class="list-group-item list-group-item-action list-group-item-light <?php echo (isset($_GET['material']) && $_GET['material'] == $material['id']) ? 'active' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="bi bi-<?php echo $material['tipo'] === 'video' ? 'play-circle' : ($material['tipo'] === 'pdf' ? 'file-pdf' : 'image'); ?> me-2"></i>
                                                <small><?php echo htmlspecialchars($material['titulo']); ?></small>
                                            </div>
                                            <?php if ($material['completado']): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <?php foreach ($materiales_sin_modulo as $material): ?>
                                <a href="?id=<?php echo $curso_id; ?>&material=<?php echo $material['id']; ?>" 
                                   class="list-group-item list-group-item-action <?php echo (isset($_GET['material']) && $_GET['material'] == $material['id']) ? 'active' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-<?php echo $material['tipo'] === 'video' ? 'play-circle' : ($material['tipo'] === 'pdf' ? 'file-pdf' : 'image'); ?> me-2"></i>
                                            <small><?php echo htmlspecialchars($material['titulo']); ?></small>
                                        </div>
                                        <?php if ($material['completado']): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($materiales as $material): ?>
                                <a href="?id=<?php echo $curso_id; ?>&material=<?php echo $material['id']; ?>" 
                                   class="list-group-item list-group-item-action <?php echo (isset($_GET['material']) && $_GET['material'] == $material['id']) ? 'active' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-<?php echo $material['tipo'] === 'video' ? 'play-circle' : ($material['tipo'] === 'pdf' ? 'file-pdf' : 'image'); ?> me-2"></i>
                                            <small><?php echo htmlspecialchars($material['titulo']); ?></small>
                                        </div>
                                        <?php if ($material['completado']): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($evaluacion): ?>
                        <hr>
                        <div class="text-center">
                            <?php if ($curso_finalizado): ?>
                                <div class="alert alert-success mb-2 py-2">
                                    <i class="bi bi-trophy-fill me-1"></i>
                                    <small>Curso Finalizado</small>
                                </div>
                            <?php elseif ($intento_activo): ?>
                                <?php if ($continuar_pero_intentos_agotados): ?>
                                <button type="button" class="btn btn-warning btn-sm w-100" data-bs-toggle="modal" data-bs-target="#modalIntentosAgotados">
                                    <i class="bi bi-clock-history me-1"></i>Continuar Evaluación
                                </button>
                                <?php else: ?>
                                <a href="evaluacion.php?curso_id=<?php echo $curso_id; ?>&intento_id=<?php echo $intento_activo['id']; ?>" 
                                   class="btn btn-warning btn-sm w-100">
                                    <i class="bi bi-clock-history me-1"></i>Continuar Evaluación
                                </a>
                                <?php endif; ?>
                            <?php elseif ($puede_evaluar): ?>
                                <a href="evaluacion.php?curso_id=<?php echo $curso_id; ?>" 
                                   class="btn btn-primary btn-sm w-100" id="btnPresentarEvaluacion" data-loading-text="Cargando...">
                                    <i class="bi bi-clipboard-check me-1"></i>Presentar Evaluación
                                </a>
                            <?php else: ?>
                                <?php if ($intentos_agotados): ?>
                                <button type="button" class="btn btn-secondary btn-sm w-100" id="btnInfoIntentosAgotados" data-bs-toggle="modal" data-bs-target="#modalIntentosAgotados">
                                    <i class="bi bi-lock me-1"></i>Evaluación no disponible
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm w-100" disabled>
                                    <i class="bi bi-lock me-1"></i>Evaluación no disponible
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <?php
            $material_id = isset($_GET['material']) ? (int)$_GET['material'] : null;
            $material_actual = null;
            
            if ($material_id) {
                foreach ($materiales as $m) {
                    if ($m['id'] == $material_id) {
                        $material_actual = $m;
                        break;
                    }
                }
            } else {
                $material_actual = !empty($materiales) ? $materiales[0] : null;
            }
            
            if ($material_actual):
                // Aviso si hay intento en proceso (sin bloquear el contenido)
                if ($intento_activo && !$curso_finalizado) {
                    echo '<div class="alert alert-info alert-dismissible fade show mb-3">';
                    echo '<i class="bi bi-info-circle me-2"></i>';
                    echo 'Tienes una evaluación en proceso. Puedes revisar el material y cuando estés listo ';
                    echo '<a href="evaluacion.php?curso_id=' . $curso_id . '&intento_id=' . $intento_activo['id'] . '" class="alert-link">Continuar Evaluación</a>.';
                    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>';
                    echo '</div>';
                }
                // Siempre permitir ver el material
                $curso_finalizado_para_visualizador = $curso_finalizado;
                include 'visualizador_material.php';
            else:
            ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-book text-muted" style="font-size: 4rem;"></i>
                        <p class="text-muted mt-3">Este curso no tiene materiales disponibles</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($evaluacion && ($intentos_agotados || $continuar_pero_intentos_agotados)): ?>
<!-- Modal: Intentos agotados (al hacer clic en "Continuar Evaluación" o "Evaluación no disponible" o al llegar con error=sin_intentos) -->
<div class="modal fade" id="modalIntentosAgotados" tabindex="-1" aria-labelledby="modalIntentosAgotadosLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title d-flex align-items-center" id="modalIntentosAgotadosLabel">
                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center me-3 bg-warning bg-opacity-25" style="width: 48px; height: 48px;">
                        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 1.5rem;"></i>
                    </span>
                    <span>Intentos agotados</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body pt-2 pb-4">
                <p class="text-muted mb-4">
                    <?php if ($continuar_pero_intentos_agotados): ?>
                    Has agotado los intentos permitidos para esta evaluación.
                    <?php else: ?>
                    Has utilizado todos los intentos disponibles para la evaluación de este curso y no has aprobado.
                    <?php endif; ?>
                </p>
                <div class="bg-light rounded-3 p-3 mb-4">
                    <p class="mb-0 small text-muted">Intentos que tenías permitidos</p>
                    <p class="mb-0 fs-4 fw-bold text-dark"><?php echo (int)$evaluacion['numero_intentos']; ?> intento(s)</p>
                </div>
                <div class="alert alert-info mb-0 d-flex align-items-start">
                    <i class="bi bi-info-circle-fill me-2 mt-1 flex-shrink-0"></i>
                    <div>
                        <strong>¿Qué puedes hacer?</strong><br>
                        Por favor comuníquese con un Administrador para que le vuelva a activar el cuestionario y poder presentar la evaluación nuevamente.
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var modalEl = document.getElementById('modalIntentosAgotados');
    if (!modalEl) return;
    var showOnLoad = <?php echo (isset($_GET['error']) && $_GET['error'] === 'sin_intentos' && $intentos_agotados) ? 'true' : 'false'; ?>;
    if (showOnLoad && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('error');
            window.history.replaceState({}, '', url.toString());
        }
    }
});
</script>
<?php endif; ?>

<?php if ($evaluacion && $puede_evaluar): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('btnPresentarEvaluacion');
    if (btn && !btn.dataset.clicked) {
        btn.addEventListener('click', function(e) {
            if (btn.dataset.clicked === '1') {
                e.preventDefault();
                return false;
            }
            btn.dataset.clicked = '1';
            btn.classList.add('disabled');
            var href = btn.getAttribute('href');
            if (href) {
                btn.querySelector('i').className = 'bi bi-hourglass-split me-1';
                btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Cargando...';
                window.location.href = href;
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
