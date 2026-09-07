<?php
require_once '../config/config.php';
require_once '../includes/formacion_helpers.php';
requerirPermiso('ver_formacion');

$pdo = getDBConnection();
$usuario_id = (int)$_SESSION['usuario_id'];
$curso_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT fc.*, fi.id AS inscripcion_id, fi.progreso, fi.completado, fi.fecha_completado
    FROM formacion_inscripciones fi
    JOIN formacion_cursos fc ON fc.id = fi.curso_id
    WHERE fi.usuario_id = ? AND fc.id = ?
");
$stmt->execute([$usuario_id, $curso_id]);
$curso = $stmt->fetch();

if (!$curso) { header('Location: index.php'); exit; }

$inscripcion_id = (int)$curso['inscripcion_id'];
$es_solo_evaluacion = $curso['modalidad'] === 'solo_evaluacion';
$mensaje = '';
$tipo_mensaje = '';

// ==========================================================
// Curso vencido: si ya pasó fecha_cierre y no está completado,
// se bloquea todo acceso a contenido y evaluaciones.
// ==========================================================
$vencido = false;
if (!$curso['completado'] && $curso['fecha_cierre']) {
    $hoy_vencido = new DateTime();
    $cierre_vencido = new DateTime($curso['fecha_cierre']);
    if ($cierre_vencido < $hoy_vencido) {
        $vencido = true;
    }
}

$page_title = $curso['nombre'];
require_once '../includes/header.php';

// ==========================================================
// Contenido: módulos, materiales y progreso
// ==========================================================
$modulos = [];
$materiales_por_modulo = [];
$total_materiales = 0;
$materiales_completados = 0;

if (!$es_solo_evaluacion) {
    $stmt = $pdo->prepare("SELECT * FROM formacion_modulos WHERE curso_id = ? ORDER BY orden");
    $stmt->execute([$curso_id]);
    $modulos = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT fm.*, pm.completado AS visto
        FROM formacion_materiales fm
        LEFT JOIN formacion_progreso_material pm ON pm.material_id = fm.id AND pm.inscripcion_id = ?
        WHERE fm.curso_id = ? ORDER BY fm.orden
    ");
    $stmt->execute([$inscripcion_id, $curso_id]);
    foreach ($stmt->fetchAll() as $m) {
        $materiales_por_modulo[$m['modulo_id']][] = $m;
        $total_materiales++;
        if ($m['visto']) $materiales_completados++;
    }
}

// ==========================================================
// Evaluaciones del curso (una por módulo + la general)
// ==========================================================
$stmt = $pdo->prepare("SELECT * FROM formacion_evaluaciones WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$evaluaciones_por_modulo = [];
$evaluacion_general = null;
foreach ($stmt->fetchAll() as $e) {
    if ($e['modulo_id'] === null) $evaluacion_general = $e; else $evaluaciones_por_modulo[$e['modulo_id']] = $e;
}

$preguntas_por_evaluacion = [];
$opciones_por_pregunta = [];
$stmt = $pdo->prepare("SELECT p.* FROM formacion_preguntas p JOIN formacion_evaluaciones e ON e.id = p.evaluacion_id WHERE e.curso_id = ? ORDER BY p.orden");
$stmt->execute([$curso_id]);
$todas_preguntas = $stmt->fetchAll();
foreach ($todas_preguntas as $p) $preguntas_por_evaluacion[$p['evaluacion_id']][] = $p;
if (!empty($todas_preguntas)) {
    $ids = array_column($todas_preguntas, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM formacion_opciones_respuesta WHERE pregunta_id IN ($ph) ORDER BY orden");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $o) $opciones_por_pregunta[$o['pregunta_id']][] = $o;
}

/** Devuelve ['intentos'=>n, 'aprobado'=>bool] para una evaluación y esta inscripción */
function estadoEvaluacion(PDO $pdo, int $inscripcion_id, int $evaluacion_id): array
{
    $stmt = $pdo->prepare("SELECT estado FROM formacion_intentos_evaluacion WHERE inscripcion_id = ? AND evaluacion_id = ? ORDER BY numero_intento");
    $stmt->execute([$inscripcion_id, $evaluacion_id]);
    $intentos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return ['intentos' => count($intentos), 'aprobado' => in_array('aprobado', $intentos, true)];
}

// ==========================================================
// Procesar envío de un quiz (de módulo o general) — llega con evaluacion_id
// ==========================================================
$resultado_intento = null;
$evaluacion_recien_enviada = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'presentar_evaluacion' && $vencido) {
    $mensaje = 'Este curso está vencido. Ya no es posible presentar evaluaciones.';
    $tipo_mensaje = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'presentar_evaluacion' && !$vencido) {
    $evaluacion_id = (int)$_POST['evaluacion_id'];
    $stmt = $pdo->prepare("SELECT * FROM formacion_evaluaciones WHERE id = ? AND curso_id = ?");
    $stmt->execute([$evaluacion_id, $curso_id]);
    $evaluacion_actual = $stmt->fetch();

    if ($evaluacion_actual) {
        $preguntas_actuales = $preguntas_por_evaluacion[$evaluacion_id] ?? [];
        $estado_previo = estadoEvaluacion($pdo, $inscripcion_id, $evaluacion_id);

        if (!$estado_previo['aprobado'] && $estado_previo['intentos'] < $evaluacion_actual['numero_intentos']) {
            $respuestas_enviadas = $_POST['respuestas'] ?? [];
            $puntaje_obtenido = 0; $puntaje_total = 0; $detalle_respuestas = [];

            foreach ($preguntas_actuales as $p) {
                $puntaje_total += (int)$p['puntos'];
                $opcion_marcada = isset($respuestas_enviadas[$p['id']]) ? (int)$respuestas_enviadas[$p['id']] : null;
                $es_correcta = false;
                foreach (($opciones_por_pregunta[$p['id']] ?? []) as $op) {
                    if ((int)$op['id'] === $opcion_marcada && $op['es_correcta']) $es_correcta = true;
                }
                if ($es_correcta) $puntaje_obtenido += (int)$p['puntos'];
                $detalle_respuestas[] = ['pregunta_id' => $p['id'], 'opcion_id' => $opcion_marcada];
            }

            $porcentaje = $puntaje_total > 0 ? round(($puntaje_obtenido / $puntaje_total) * 100) : 0;
            $aprobado = $porcentaje >= $evaluacion_actual['puntaje_minimo'];

            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO formacion_intentos_evaluacion (inscripcion_id, evaluacion_id, numero_intento, puntaje_obtenido, puntaje_total, estado, fecha_finalizacion)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$inscripcion_id, $evaluacion_id, $estado_previo['intentos'] + 1, $puntaje_obtenido, $puntaje_total, $aprobado ? 'aprobado' : 'reprobado']);
                $intento_id = (int)$pdo->lastInsertId();

                $stmtResp = $pdo->prepare("INSERT INTO formacion_respuestas_usuario (intento_id, pregunta_id, opcion_id) VALUES (?, ?, ?)");
                foreach ($detalle_respuestas as $r) $stmtResp->execute([$intento_id, $r['pregunta_id'], $r['opcion_id']]);

                $pdo->commit();
                registrarLog($usuario_id, 'Presentar evaluación formación', 'Formación', "Curso ID: $curso_id, Evaluación ID: $evaluacion_id, Puntaje: $porcentaje%");

                if ($aprobado) verificarCompletadoCurso($pdo, $curso_id, $inscripcion_id);

                $resultado_intento = ['aprobado' => $aprobado, 'porcentaje' => $porcentaje];
                $evaluacion_recien_enviada = $evaluacion_id;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensaje = 'Error al procesar la evaluación: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }

    $stmt = $pdo->prepare("SELECT progreso, completado, fecha_completado FROM formacion_inscripciones WHERE id = ?");
    $stmt->execute([$inscripcion_id]);
    $refresh = $stmt->fetch();
    $curso['progreso'] = $refresh['progreso'];
    $curso['completado'] = $refresh['completado'];
    $curso['fecha_completado'] = $refresh['fecha_completado'];
}

// ==========================================================
// Encuesta: interna (si el admin la configuró) o externa (link)
// ==========================================================
$stmt = $pdo->prepare("SELECT * FROM formacion_encuestas WHERE curso_id = ? AND activo = 1");
$stmt->execute([$curso_id]);
$encuesta_interna = $stmt->fetch();

$encuesta_ya_respondida = false;
if ($encuesta_interna) {
    $stmt = $pdo->prepare("
        SELECT 1 FROM formacion_encuesta_respuestas fer
        JOIN formacion_encuesta_preguntas fep ON fep.id = fer.pregunta_id
        WHERE fer.inscripcion_id = ? AND fep.encuesta_id = ? LIMIT 1
    ");
    $stmt->execute([$inscripcion_id, $encuesta_interna['id']]);
    $encuesta_ya_respondida = (bool)$stmt->fetchColumn();
}

require_once '../includes/auditoria_helpers.php';
registrarVista($pdo, $_SESSION['usuario_id'], 'formacion', $curso_id);

$iconos_tipo = ['video' => 'bi-camera-video', 'pdf' => 'bi-file-earmark-pdf', 'imagen' => 'bi-image'];

// ==========================================================
// Estado agregado (para el botón "Presentar Evaluación" y el panel por defecto)
// ==========================================================
$todos_quices_modulo_ok = true;
foreach ($evaluaciones_por_modulo as $ev) {
    if (!estadoEvaluacion($pdo, $inscripcion_id, $ev['id'])['aprobado']) { $todos_quices_modulo_ok = false; break; }
}
$estado_general = $evaluacion_general ? estadoEvaluacion($pdo, $inscripcion_id, $evaluacion_general['id']) : null;
$puede_evaluacion_general = $evaluacion_general
    ? ($es_solo_evaluacion || ($materiales_completados >= $total_materiales && $todos_quices_modulo_ok))
    : false;

$pct_materiales = $total_materiales > 0 ? round(($materiales_completados / $total_materiales) * 100, 1) : 100.0;

// ---------- Panel que se muestra por defecto al entrar (retoma donde se quedó) ----------
$panel_por_defecto = null;
if ($es_solo_evaluacion) {
    $panel_por_defecto = 'panel-general';
} else {
    foreach ($modulos as $modulo) {
        foreach (($materiales_por_modulo[$modulo['id']] ?? []) as $mat) {
            if (!$mat['visto'] && !$panel_por_defecto) $panel_por_defecto = 'panel-mat-' . $mat['id'];
        }
        if (isset($evaluaciones_por_modulo[$modulo['id']])) {
            $st = estadoEvaluacion($pdo, $inscripcion_id, $evaluaciones_por_modulo[$modulo['id']]['id']);
            if (!$st['aprobado'] && !$panel_por_defecto) $panel_por_defecto = 'panel-quiz-' . $modulo['id'];
        }
    }
    if (!$panel_por_defecto && $evaluacion_general && !($estado_general['aprobado'] ?? false)) {
        $panel_por_defecto = 'panel-general';
    }
    if (!$panel_por_defecto) {
        // Todo completado: cae en el último elemento disponible, para poder repasar
        if ($evaluacion_general) {
            $panel_por_defecto = 'panel-general';
        } elseif (!empty($modulos)) {
            $ultimo_modulo = end($modulos);
            $mats_ultimo = $materiales_por_modulo[$ultimo_modulo['id']] ?? [];
            if (!empty($mats_ultimo)) {
                $ultimo_mat = end($mats_ultimo);
                $panel_por_defecto = 'panel-mat-' . $ultimo_mat['id'];
            }
        }
    }
}
?>

<div class="container-fluid mt-4" style="max-width: 1140px;">
    <a href="index.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Volver a Formación</a>

    <?php if ($mensaje): ?><div class="alert alert-<?php echo $tipo_mensaje; ?>"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>

    <?php if ($curso['completado']): ?>
        <div class="card border-success shadow-sm mb-4">
            <div class="card-body text-center py-4">
                <i class="bi bi-patch-check-fill text-success" style="font-size:2.5rem;"></i>
                <h4 class="mt-2">¡Curso completado!</h4>
                <p class="text-muted">Finalizado el <?php echo date('d/m/Y', strtotime($curso['fecha_completado'])); ?></p>
                <div class="d-flex justify-content-center gap-2 flex-wrap mt-3">
                    <a href="certificado.php?inscripcion_id=<?php echo $inscripcion_id; ?>" target="_blank" class="btn btn-outline-primary">
                        <i class="bi bi-download me-1"></i>Descargar asistencia
                    </a>
                    <?php if (!$evaluacion_general): ?>
                        <?php // Sin evaluación general no hay botón "Presentar Evaluación" en el sidebar,
                              // así que la encuesta se ofrece aquí en el banner. Si hay evaluación general,
                              // la encuesta se muestra en el menú lateral justo después de ese botón. ?>
                        <?php if ($encuesta_interna && !$encuesta_ya_respondida): ?>
                            <a href="encuesta.php?id=<?php echo $inscripcion_id; ?>" class="btn btn-primary"><i class="bi bi-clipboard-check me-1"></i>Responder encuesta de satisfacción</a>
                        <?php elseif ($encuesta_interna && $encuesta_ya_respondida): ?>
                            <span class="btn btn-outline-success disabled"><i class="bi bi-check-circle me-1"></i>Encuesta respondida</span>
                        <?php elseif (!$encuesta_interna && $curso['encuesta_url']): ?>
                            <a href="registrar_encuesta.php?inscripcion_id=<?php echo $inscripcion_id; ?>" target="_blank" class="btn btn-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Responder encuesta de satisfacción</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($vencido): ?>
        <div class="card border-danger shadow-sm mb-4">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar-x text-danger" style="font-size:2.8rem;"></i>
                <h4 class="mt-3">Este curso venció</h4>
                <p class="text-muted mb-0">
                    La fecha límite era el <strong><?php echo date('d/m/Y', strtotime($curso['fecha_cierre'])); ?></strong>.
                    Ya no es posible acceder al contenido ni presentar evaluaciones de este curso.
                    Si consideras que esto es un error, comunícate con Talento Humano.
                </p>
            </div>
        </div>
    <?php else: ?>
    <div class="row g-3 formacion-layout">
        <!-- ======================= SIDEBAR ======================= -->
        <div class="col-12 col-lg-4 col-xl-3">
            <div class="card shadow-sm formacion-sidebar-card">
                <div class="formacion-sidebar-header"><?php echo htmlspecialchars($curso['nombre']); ?></div>

                <?php if (!$es_solo_evaluacion): ?>
                <div class="formacion-sidebar-progress">
                    <div class="progress" style="height:26px;">
                        <div class="progress-bar progress-bar-striped bg-primary" style="width:<?php echo $pct_materiales; ?>%">
                            <?php echo number_format($pct_materiales, 1); ?>%
                        </div>
                    </div>
                </div>

                <p class="formacion-sidebar-label">Contenido del Curso</p>
                <div class="formacion-nav-list">
                    <?php foreach ($modulos as $modulo): ?>
                        <div class="formacion-nav-group-title"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($modulo['titulo']); ?></div>

                        <?php foreach (($materiales_por_modulo[$modulo['id']] ?? []) as $mat): ?>
                            <button type="button" class="formacion-nav-item" data-target="panel-mat-<?php echo $mat['id']; ?>">
                                <i class="bi <?php echo $iconos_tipo[$mat['tipo']] ?? 'bi-file'; ?>"></i>
                                <span class="formacion-nav-item-text"><?php echo htmlspecialchars($mat['titulo']); ?></span>
                                <?php if ($mat['visto']): ?><i class="bi bi-check-circle-fill text-success nav-check"></i><?php endif; ?>
                            </button>
                        <?php endforeach; ?>

                        <?php if (isset($evaluaciones_por_modulo[$modulo['id']])):
                            $eval_mod = $evaluaciones_por_modulo[$modulo['id']];
                            $st_mod = estadoEvaluacion($pdo, $inscripcion_id, $eval_mod['id']);
                            $mats_mod = $materiales_por_modulo[$modulo['id']] ?? [];
                            $mod_completo = empty($mats_mod) || count(array_filter($mats_mod, fn($m) => $m['visto'])) >= count($mats_mod);
                            $quiz_bloqueado = !$st_mod['aprobado'] && !$mod_completo;
                        ?>
                            <button type="button" class="formacion-nav-item formacion-nav-quiz <?php echo $quiz_bloqueado ? 'locked' : ''; ?>"
                                    <?php echo $quiz_bloqueado ? 'disabled title="Termina de ver los materiales de este módulo"' : ''; ?>
                                    data-target="panel-quiz-<?php echo $modulo['id']; ?>">
                                <i class="bi bi-patch-question"></i>
                                <span class="formacion-nav-item-text">Quiz del módulo</span>
                                <?php if ($st_mod['aprobado']): ?>
                                    <i class="bi bi-check-circle-fill text-success nav-check"></i>
                                <?php elseif ($quiz_bloqueado): ?>
                                    <i class="bi bi-lock-fill text-muted nav-check"></i>
                                <?php endif; ?>
                            </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($evaluacion_general): ?>
                <div class="formacion-sidebar-footer">
                    <?php if ($estado_general['aprobado'] ?? false): ?>
                        <span class="btn btn-success w-100 disabled"><i class="bi bi-check-circle me-1"></i>Evaluación aprobada</span>
                    <?php elseif (!$puede_evaluacion_general): ?>
                        <button type="button" class="btn btn-outline-secondary w-100" disabled title="Completa el contenido y los quices de módulo primero">
                            <i class="bi bi-lock-fill me-1"></i>Presentar Evaluación
                        </button>
                    <?php elseif ($estado_general['intentos'] >= $evaluacion_general['numero_intentos']): ?>
                        <button type="button" class="btn btn-outline-danger w-100" disabled>Sin intentos disponibles</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary w-100" data-target="panel-general" id="btnPresentarEvaluacionGeneral">
                            <i class="bi bi-clipboard-check me-1"></i>Presentar Evaluación
                        </button>
                    <?php endif; ?>

                    <?php if ($encuesta_interna): ?>
                        <?php if ($encuesta_ya_respondida): ?>
                            <span class="btn btn-outline-success w-100 disabled mt-2"><i class="bi bi-check-circle me-1"></i>Encuesta respondida</span>
                        <?php else: ?>
                            <a href="encuesta.php?id=<?php echo $inscripcion_id; ?>" class="btn btn-outline-primary w-100 mt-2"><i class="bi bi-clipboard-check me-1"></i>Encuesta de satisfacción</a>
                        <?php endif; ?>
                    <?php elseif ($curso['encuesta_url']): ?>
                        <a href="registrar_encuesta.php?inscripcion_id=<?php echo $inscripcion_id; ?>" target="_blank" class="btn btn-outline-primary w-100 mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>Encuesta de satisfacción</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ======================= VISOR PRINCIPAL ======================= -->
        <div class="col-12 col-lg-8 col-xl-9">
            <div class="card shadow-sm formacion-main-card">
                <div class="card-body">

                    <?php foreach ($modulos as $modulo): foreach (($materiales_por_modulo[$modulo['id']] ?? []) as $mat): ?>
                    <div class="formacion-panel" id="panel-mat-<?php echo $mat['id']; ?>">
                        <div class="formacion-main-header"><i class="bi <?php echo $iconos_tipo[$mat['tipo']] ?? 'bi-file'; ?> me-2"></i><?php echo htmlspecialchars($mat['titulo']); ?></div>

                        <div class="material-item" data-material-id="<?php echo $mat['id']; ?>" data-tiempo-minimo="<?php echo $mat['tiempo_minimo']; ?>">
                            <?php if ($mat['tipo'] === 'video'): ?>
                                <video controls controlsList="nodownload noremoteplayback" disablepictureinpicture oncontextmenu="return false" style="width:100%; max-height:480px; background:#000; border-radius:8px;" class="material-video">
                                    <?php if (!$mat['visto']): ?>
                                    <div class="mt-2"><button class="btn btn-sm btn-outline-success btn-marcar-visto" data-material-id="<?php echo $mat['id']; ?>"><i class="bi bi-check2 me-1"></i>Marcar como visto</button></div>
                                <?php endif; ?>
                                <source src="visualizador_material.php?id=<?php echo $mat['id']; ?>" type="video/mp4">
                                </video>
                            <?php elseif ($mat['tipo'] === 'pdf'): ?>
                                <div class="pdf-viewer-container" data-pdf-url="visualizador_material.php?id=<?php echo $mat['id']; ?>">
                                    <div class="pdf-viewer-toolbar">
                                        <span class="pdf-page-info"><i class="bi bi-file-earmark-pdf me-1"></i>Documento PDF completo</span>
                                        <div class="ms-auto d-flex gap-1 align-items-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary pdf-zoom-out" title="Reducir zoom"><i class="bi bi-dash-lg"></i></button>
                                            <span class="pdf-zoom-info">100%</span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary pdf-zoom-in" title="Aumentar zoom"><i class="bi bi-plus-lg"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-success btn-marcar-visto <?php echo $mat['visto'] ? 'd-none' : ''; ?>" data-material-id="<?php echo $mat['id']; ?>">
                                                <i class="bi bi-check2 me-1"></i>Marcar como visto
                                            </button>
                                        </div>
                                    </div>
                                    <div class="pdf-viewer">
                                        <div class="pdf-loading">
                                            <div class="spinner-border text-primary" role="status"></div>
                                            <p class="mt-2 mb-0">Cargando documento completo...</p>
                                        </div>
                                        <div class="pdf-pages"></div>
                                    </div>
                                </div>
                            <?php elseif ($mat['tipo'] === 'imagen'): ?>
                                <img src="visualizador_material.php?id=<?php echo $mat['id']; ?>" class="img-fluid rounded" oncontextmenu="return false">
                                <?php if (!$mat['visto']): ?>
                                    <div class="mt-2"><button class="btn btn-sm btn-outline-success btn-marcar-visto" data-material-id="<?php echo $mat['id']; ?>"><i class="bi bi-check2 me-1"></i>Marcar como visto</button></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                        </div>
                    </div>
                    <?php endforeach; endforeach; ?>

                    <!-- ---- Paneles de quiz por módulo ---- -->
                    <?php foreach ($modulos as $modulo): if (!isset($evaluaciones_por_modulo[$modulo['id']])) continue;
                        $eval_mod = $evaluaciones_por_modulo[$modulo['id']];
                        $st_mod = estadoEvaluacion($pdo, $inscripcion_id, $eval_mod['id']);
                        $mats_mod = $materiales_por_modulo[$modulo['id']] ?? [];
                        $mod_completo = empty($mats_mod) || count(array_filter($mats_mod, fn($m) => $m['visto'])) >= count($mats_mod);
                    ?>
                    <div class="formacion-panel" id="panel-quiz-<?php echo $modulo['id']; ?>">
                        <div class="formacion-main-header"><i class="bi bi-patch-question me-2"></i>Quiz: <?php echo htmlspecialchars($modulo['titulo']); ?></div>

                        <?php if ($st_mod['aprobado']): ?>
                            <p class="text-success"><i class="bi bi-check-circle me-1"></i>Ya aprobaste el quiz de este módulo.</p>
                        <?php elseif (!$mod_completo): ?>
                            <div class="alert alert-warning"><i class="bi bi-lock me-1"></i>Termina de ver los materiales de este módulo para habilitar el quiz.</div>
                        <?php elseif ($st_mod['intentos'] >= $eval_mod['numero_intentos']): ?>
                            <div class="alert alert-danger">Alcanzaste el máximo de <?php echo $eval_mod['numero_intentos']; ?> intento(s) sin aprobar. Contacta a Talento Humano.</div>
                        <?php else: ?>
                            <?php if ($evaluacion_recien_enviada === $eval_mod['id'] && $resultado_intento && !$resultado_intento['aprobado']): ?>
                                <div class="alert alert-danger">Obtuviste <?php echo $resultado_intento['porcentaje']; ?>% (mínimo <?php echo $eval_mod['puntaje_minimo']; ?>%). Te quedan <?php echo $eval_mod['numero_intentos'] - $st_mod['intentos']; ?> intento(s).</div>
                            <?php endif; ?>
                            <p class="text-muted small">Mínimo para aprobar: <?php echo $eval_mod['puntaje_minimo']; ?>% · Intento <?php echo $st_mod['intentos'] + 1; ?> de <?php echo $eval_mod['numero_intentos']; ?></p>
                            <form method="POST">
                                <input type="hidden" name="accion" value="presentar_evaluacion">
                                <input type="hidden" name="evaluacion_id" value="<?php echo $eval_mod['id']; ?>">
                                <?php foreach (($preguntas_por_evaluacion[$eval_mod['id']] ?? []) as $i => $p): ?>
                                <div class="card mb-2"><div class="card-body py-2">
                                    <p class="fw-semibold small mb-2"><?php echo $i + 1; ?>. <?php echo htmlspecialchars($p['pregunta']); ?></p>
                                    <?php foreach (($opciones_por_pregunta[$p['id']] ?? []) as $op): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="respuestas[<?php echo $p['id']; ?>]" value="<?php echo $op['id']; ?>" id="mo<?php echo $op['id']; ?>" required>
                                            <label class="form-check-label small" for="mo<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['texto']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div></div>
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Enviar quiz del módulo</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <!-- ---- Panel de evaluación general ---- -->
                    <?php if ($evaluacion_general): ?>
                    <div class="formacion-panel" id="panel-general">
                        <div class="formacion-main-header"><i class="bi bi-clipboard-check me-2"></i>Evaluación general del curso</div>

                        <?php if ($curso['completado'] || ($estado_general['aprobado'] ?? false)): ?>
                            <p class="text-success"><i class="bi bi-check-circle me-1"></i>Ya aprobaste la evaluación general.</p>
                        <?php elseif (!$puede_evaluacion_general): ?>
                            <div class="alert alert-warning"><i class="bi bi-lock me-1"></i>Completa todos los materiales y aprueba el quiz de cada módulo para habilitar esta evaluación.</div>
                        <?php elseif ($estado_general['intentos'] >= $evaluacion_general['numero_intentos']): ?>
                            <div class="alert alert-danger">Alcanzaste el máximo de <?php echo $evaluacion_general['numero_intentos']; ?> intento(s) sin aprobar. Contacta a Talento Humano.</div>
                        <?php else: ?>
                            <?php if ($evaluacion_recien_enviada === $evaluacion_general['id'] && $resultado_intento && !$resultado_intento['aprobado']): ?>
                                <div class="alert alert-danger">Obtuviste <?php echo $resultado_intento['porcentaje']; ?>% (mínimo <?php echo $evaluacion_general['puntaje_minimo']; ?>%). Te quedan <?php echo $evaluacion_general['numero_intentos'] - $estado_general['intentos']; ?> intento(s).</div>
                            <?php endif; ?>
                            <p class="text-muted small">Mínimo para aprobar: <strong><?php echo $evaluacion_general['puntaje_minimo']; ?>%</strong> · Intento <?php echo $estado_general['intentos'] + 1; ?> de <?php echo $evaluacion_general['numero_intentos']; ?></p>
                            <form method="POST">
                                <input type="hidden" name="accion" value="presentar_evaluacion">
                                <input type="hidden" name="evaluacion_id" value="<?php echo $evaluacion_general['id']; ?>">
                                <?php foreach (($preguntas_por_evaluacion[$evaluacion_general['id']] ?? []) as $i => $p): ?>
                                <div class="card mb-3"><div class="card-body">
                                    <p class="fw-semibold"><?php echo $i + 1; ?>. <?php echo htmlspecialchars($p['pregunta']); ?></p>
                                    <?php foreach (($opciones_por_pregunta[$p['id']] ?? []) as $op): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="respuestas[<?php echo $p['id']; ?>]" value="<?php echo $op['id']; ?>" id="ge<?php echo $op['id']; ?>" required>
                                            <label class="form-check-label" for="ge<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['texto']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div></div>
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Enviar evaluación general</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.formacion-sidebar-card { border-radius: 12px; overflow: hidden; border: none; }
.formacion-sidebar-header {
    background: linear-gradient(135deg, #0d6efd, #0b5ed7);
    color: #fff; padding: 16px 18px; font-weight: 700; font-size: 1.05rem; line-height: 1.3;
}
.formacion-sidebar-progress { padding: 16px 18px 4px; }
.formacion-sidebar-progress .progress-bar { font-weight: 600; font-size: .85rem; }
.formacion-sidebar-label {
    padding: 14px 18px 4px; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
    color: #6c757d; font-weight: 700; margin: 0;
}
.formacion-nav-list { max-height: 480px; overflow-y: auto; padding-bottom: 8px; }
.formacion-nav-group-title {
    padding: 12px 18px 4px; font-size: .74rem; color: #8a8f98; font-weight: 700; text-transform: uppercase;
}
.formacion-nav-item {
    display: flex; align-items: center; gap: 9px; width: 100%; text-align: left;
    border: none; background: none; padding: 9px 18px; font-size: .9rem; color: #333; cursor: pointer;
}
.formacion-nav-item i:first-child { flex-shrink: 0; opacity: .75; }
.formacion-nav-item-text { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.formacion-nav-item:hover:not(.locked) { background: #f1f5fb; }
.formacion-nav-item.active { background: #0d6efd; color: #fff; }
.formacion-nav-item.active i:first-child { opacity: 1; }
.formacion-nav-item.locked { opacity: .55; cursor: not-allowed; }
.formacion-nav-quiz { border-top: 1px dashed #eee; }
.formacion-sidebar-footer { padding: 14px 18px 18px; border-top: 1px solid #eee; }
.formacion-main-card { min-height: 420px; border-radius: 12px; border: none; }
.formacion-main-header { display: flex; align-items: center; font-weight: 700; font-size: 1.1rem; margin-bottom: 14px; color: #222; }
.formacion-panel { display: none; }
.formacion-panel.active { display: block; animation: formacionFadeIn .18s ease; }
@keyframes formacionFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

.pdf-viewer-container { width: 100%; border: 1px solid #dee2e6; border-radius: 10px; overflow: hidden; background: #f1f3f5; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.pdf-viewer-toolbar { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #ffffff; border-bottom: 1px solid #dee2e6; position: sticky; top: 0; z-index: 5; }
.pdf-page-info { font-size: .85rem; color: #6c757d; }
.pdf-zoom-info { font-size: .8rem; color: #6c757d; min-width: 42px; text-align: center; }
.pdf-viewer { width: 100%; height: 75vh; min-height: 650px; overflow: auto; padding: 24px; background: #e9ecef; }
.pdf-pages { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 18px; }
.pdf-page-wrapper { display: flex; flex-direction: column; align-items: center; width: 100%; }
.pdf-page-wrapper canvas { display: block; background: #fff; box-shadow: 0 3px 15px rgba(0,0,0,.20); max-width: none; height: auto; }
.pdf-page-number { margin-top: 6px; font-size: .72rem; color: #6c757d; }
.pdf-loading { text-align: center; color: #6c757d; padding: 50px 10px; width: 100%; }
@media (max-width: 991px) {
    .formacion-nav-list { max-height: 260px; }
    .pdf-viewer { height: 70vh; min-height: 480px; padding: 10px; }
}

@media (max-width: 991px) {
    .formacion-nav-list { max-height: 260px; }
    .pdf-viewer { height: 420px; padding: 10px; }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

/* =========================================================
   NAVEGACIÓN DEL SIDEBAR: muestra un solo panel a la vez
========================================================= */
function activarPanelFormacion(targetId) {
    document.querySelectorAll('.formacion-panel').forEach(function (p) { p.classList.remove('active'); });
    document.querySelectorAll('.formacion-nav-item').forEach(function (n) { n.classList.remove('active'); });
    var panel = document.getElementById(targetId);
    if (panel) panel.classList.add('active');
    var navItem = document.querySelector('.formacion-nav-item[data-target="' + targetId + '"]');
    if (navItem) navItem.classList.add('active');
}

document.querySelectorAll('.formacion-nav-item:not(.locked):not([disabled])').forEach(function (item) {
    item.addEventListener('click', function () { activarPanelFormacion(this.dataset.target); });
});
var btnEvalGeneral = document.getElementById('btnPresentarEvaluacionGeneral');
if (btnEvalGeneral) btnEvalGeneral.addEventListener('click', function () { activarPanelFormacion('panel-general'); });

activarPanelFormacion(<?php echo json_encode($panel_por_defecto); ?>);

/* =========================================================
   RECARGA SUAVE (sin salir de la página ni perder el scroll)
========================================================= */
function ejecutarScriptsInyectados(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
        var newScript = document.createElement('script');
        if (oldScript.src) { newScript.src = oldScript.src; } else { newScript.textContent = oldScript.textContent || ''; }
        oldScript.parentNode.replaceChild(newScript, oldScript);
    });
}

function recargarContenidoSuave(url, opcionesFetch) {
    var main = document.getElementById('app-main-content');
    if (!main) { window.location.reload(); return Promise.resolve(); }

    var config = Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' } }, opcionesFetch || {});

    return fetch(url || window.location.href, config)
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nuevoMain = doc.getElementById('app-main-content');
            if (!nuevoMain) { window.location.reload(); return; }
            main.innerHTML = nuevoMain.innerHTML;
            ejecutarScriptsInyectados(main);
        })
        .catch(function () { window.location.reload(); });
}

/* =========================================================
   MARCAR PROGRESO
========================================================= */
function marcarProgreso(materialId) {
    fetch('marcar_progreso.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'material_id=' + encodeURIComponent(materialId)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { if (data.ok) recargarContenidoSuave(); })
    .catch(function (error) { console.error('Error al marcar progreso:', error); });
}

document.querySelectorAll('.btn-marcar-visto').forEach(function (btn) {
    btn.addEventListener('click', function () { marcarProgreso(this.dataset.materialId); });
});

document.querySelectorAll('.material-video').forEach(function (video) {
    var item = video.closest('.material-item');
    var materialId = item.dataset.materialId;
    var tiempoMinimo = parseInt(item.dataset.tiempoMinimo || '0', 10);
    var yaMarcado = !item.querySelector('.btn-marcar-visto');

    function intentarMarcar() {
        if (yaMarcado) return;
        if (tiempoMinimo === 0 ? video.ended : video.currentTime >= tiempoMinimo) {
            yaMarcado = true;
            marcarProgreso(materialId);
        }
    }
    video.addEventListener('timeupdate', intentarMarcar);
    video.addEventListener('ended', intentarMarcar);
});

/* =========================================================
   ENVIAR QUIZ / EVALUACIÓN GENERAL por AJAX
========================================================= */
if (!window.__formacionQuizHandlerBound) {
    window.__formacionQuizHandlerBound = true;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.querySelector) return;
        var esQuiz = form.querySelector('input[name="accion"][value="presentar_evaluacion"]');
        if (!esQuiz) return;

        e.preventDefault();
        var boton = form.querySelector('button[type="submit"]');
        var textoOriginal = boton ? boton.innerHTML : '';
        if (boton) { boton.disabled = true; boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...'; }

        recargarContenidoSuave(window.location.href, { method: 'POST', body: new FormData(form) })
            .catch(function () { if (boton) { boton.disabled = false; boton.innerHTML = textoOriginal; } });
    }, true);
}

/* =========================================================
   PDF.JS - VISOR COMPLETO: todas las páginas en desplazamiento
========================================================= */
document.querySelectorAll('.pdf-viewer-container').forEach(function (container) {
    var pdfUrl = container.dataset.pdfUrl;
    var pagesContainer = container.querySelector('.pdf-pages');
    var loading = container.querySelector('.pdf-loading');
    var btnZoomIn = container.querySelector('.pdf-zoom-in');
    var btnZoomOut = container.querySelector('.pdf-zoom-out');
    var zoomInfo = container.querySelector('.pdf-zoom-info');

    var pdfDocument = null;
    var scale = 1.15;
    var rendering = false;

    function actualizarZoomInfo() {
        if (zoomInfo) zoomInfo.textContent = Math.round((scale / 1.15) * 100) + '%';
    }

    function renderAllPages() {
        if (!pdfDocument || rendering) return;
        rendering = true;
        loading.style.display = 'block';
        pagesContainer.innerHTML = '';

        var promesas = [];

        for (let pageNumber = 1; pageNumber <= pdfDocument.numPages; pageNumber++) {
            promesas.push(
                pdfDocument.getPage(pageNumber).then(function (page) {
                    var wrapper = document.createElement('div');
                    wrapper.className = 'pdf-page-wrapper';
                    wrapper.dataset.page = pageNumber;

                    var canvas = document.createElement('canvas');
                    canvas.className = 'pdf-page-canvas';

                    var label = document.createElement('div');
                    label.className = 'pdf-page-number';
                    label.textContent = 'Página ' + pageNumber + ' de ' + pdfDocument.numPages;

                    wrapper.appendChild(canvas);
                    wrapper.appendChild(label);
                    pagesContainer.appendChild(wrapper);

                    var viewport = page.getViewport({ scale: scale });
                    canvas.width = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);

                    return page.render({
                        canvasContext: canvas.getContext('2d'),
                        viewport: viewport
                    }).promise;
                })
            );
        }

        Promise.all(promesas).then(function () {
            loading.style.display = 'none';
            rendering = false;
            actualizarZoomInfo();
        }).catch(function (error) {
            console.error('Error renderizando PDF:', error);
            loading.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No fue posible mostrar todas las páginas del documento.</div>';
            rendering = false;
        });
    }

    pdfjsLib.getDocument({
        url: pdfUrl,
        disableAutoFetch: false,
        disableStream: false
    }).promise.then(function (pdf) {
        pdfDocument = pdf;
        renderAllPages();
    }).catch(function (error) {
        console.error('Error cargando PDF:', error);
        loading.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No fue posible cargar el documento.</div>';
    });

    btnZoomIn.addEventListener('click', function () {
        if (scale < 2.5 && !rendering) {
            scale += 0.15;
            renderAllPages();
        }
    });

    btnZoomOut.addEventListener('click', function () {
        if (scale > 0.6 && !rendering) {
            scale -= 0.15;
            renderAllPages();
        }
    });

    actualizarZoomInfo();
});

</script>

<?php require_once '../includes/footer.php'; ?>