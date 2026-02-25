<?php
require_once '../config/config.php';
requerirPermiso('presentar_evaluaciones');

$page_title = 'Evaluación';
$additional_css = ['assets/css/evaluacion.css'];

$pdo = getDBConnection();
$curso_id = (int)($_GET['curso_id'] ?? 0);
$intento_id = isset($_GET['intento_id']) ? (int)$_GET['intento_id'] : null;

if (!$curso_id) {
    header('Location: index.php');
    exit;
}

// Verificar inscripción y completitud
$stmt = $pdo->prepare("
    SELECT i.*, c.nombre as curso_nombre
    FROM inscripciones i
    INNER JOIN cursos c ON i.curso_id = c.id
    WHERE i.usuario_id = ? AND i.curso_id = ?
");
$stmt->execute([$_SESSION['usuario_id'], $curso_id]);
$inscripcion = $stmt->fetch();

if (!$inscripcion || !$inscripcion['completado']) {
    header('Location: ver_curso.php?id=' . $curso_id);
    exit;
}

// Obtener evaluación
$stmt = $pdo->prepare("SELECT * FROM evaluaciones WHERE curso_id = ? AND activo = 1");
$stmt->execute([$curso_id]);
$evaluacion = $stmt->fetch();

if (!$evaluacion) {
    header('Location: ver_curso.php?id=' . $curso_id);
    exit;
}

// Verificar intentos: solo contar finalizados (aprobado/reprobado), no los en_proceso
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_finalizados FROM intentos_evaluacion
    WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado IN ('aprobado', 'reprobado')
");
$stmt->execute([$inscripcion['id'], $evaluacion['id']]);
$intentos_info = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT id FROM intentos_evaluacion
    WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado = 'en_proceso'
    ORDER BY fecha_inicio DESC LIMIT 1
");
$stmt->execute([$inscripcion['id'], $evaluacion['id']]);
$id_proceso = $stmt->fetchColumn();
$intento_en_proceso_id = ($id_proceso !== false && $id_proceso !== null) ? (int)$id_proceso : null;

$stmt = $pdo->prepare("
    SELECT COALESCE(MAX(numero_intento), 0) as ultimo_intento FROM intentos_evaluacion
    WHERE inscripcion_id = ? AND evaluacion_id = ?
");
$stmt->execute([$inscripcion['id'], $evaluacion['id']]);
$intentos_info['ultimo_intento'] = (int)$stmt->fetch()['ultimo_intento'];

$intentos_usados = (int)($intentos_info['total_finalizados'] ?? 0);
$intentos_restantes = $evaluacion['numero_intentos'] - $intentos_usados;

if ($intentos_restantes <= 0 && !$intento_en_proceso_id) {
    header('Location: ver_curso.php?id=' . $curso_id . '&error=sin_intentos');
    exit;
}

// Obtener o crear intento
if ($intento_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM intentos_evaluacion
        WHERE id = ? AND inscripcion_id = ? AND estado = 'en_proceso'
    ");
    $stmt->execute([$intento_id, $inscripcion['id']]);
    $intento = $stmt->fetch();
    
    if (!$intento) {
        header('Location: ver_curso.php?id=' . $curso_id);
        exit;
    }
} else {
    // Si ya hay un intento en proceso, reutilizarlo (evita doble clic/recarga consumiendo otro intento)
    if ($intento_en_proceso_id) {
        header('Location: evaluacion.php?curso_id=' . $curso_id . '&intento_id=' . $intento_en_proceso_id);
        exit;
    }
    // Crear nuevo intento
    $numero_intento = (int)($intentos_info['ultimo_intento'] ?? 0) + 1;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO intentos_evaluacion (inscripcion_id, evaluacion_id, numero_intento, estado)
            VALUES (?, ?, ?, 'en_proceso')
        ");
        $stmt->execute([$inscripcion['id'], $evaluacion['id'], $numero_intento]);
        $intento_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("SELECT * FROM intentos_evaluacion WHERE id = ?");
        $stmt->execute([$intento_id]);
        $intento = $stmt->fetch();
        
        registrarLog($_SESSION['usuario_id'], 'Iniciar evaluación', 'Evaluaciones', "Intento ID: $intento_id");
        // Redirigir a la URL con intento_id para que al enviar el formulario (AJAX) se actualice este intento y no se cree otro
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: evaluacion.php?curso_id=' . $curso_id . '&intento_id=' . $intento_id);
            exit;
        }
    } catch (Exception $e) {
        header('Location: ver_curso.php?id=' . $curso_id);
        exit;
    }
}

// Obtener preguntas
$stmt = $pdo->prepare("
    SELECT p.*
    FROM preguntas p
    WHERE p.evaluacion_id = ?
    ORDER BY p.orden ASC, p.id ASC
");
$stmt->execute([$evaluacion['id']]);
$preguntas = $stmt->fetchAll();

// Obtener opciones para cada pregunta
foreach ($preguntas as &$pregunta) {
    $stmt = $pdo->prepare("
        SELECT * FROM opciones_respuesta
        WHERE pregunta_id = ?
        ORDER BY orden ASC
    ");
    $stmt->execute([$pregunta['id']]);
    $pregunta['opciones'] = $stmt->fetchAll();
}
unset($pregunta);
// Obtener respuestas guardadas
$stmt = $pdo->prepare("
    SELECT pregunta_id, opcion_id
    FROM respuestas_usuario
    WHERE intento_id = ?
");
$stmt->execute([$intento['id']]);
$respuestas_guardadas = [];
while ($row = $stmt->fetch()) {
    $respuestas_guardadas[$row['pregunta_id']] = $row['opcion_id'];
}

// Procesar envío (detectar AJAX por cabecera o por campo POST por si el servidor no reenvía la cabecera)
$es_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
           !empty($_POST['enviar_ajax']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_evaluacion'])) {
    $respuestas = $_POST['respuestas'] ?? [];
    $puntaje_total = 0;
    $puntaje_obtenido = 0;
    
    // Calcular puntaje
    foreach ($preguntas as $pregunta) {
        $puntaje_total += $pregunta['puntos'];
        $respuesta_usuario = $respuestas[$pregunta['id']] ?? null;
        
        if ($respuesta_usuario) {
            // Guardar respuesta
            $stmt = $pdo->prepare("
                INSERT INTO respuestas_usuario (intento_id, pregunta_id, opcion_id)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE opcion_id = ?
            ");
            $stmt->execute([$intento['id'], $pregunta['id'], $respuesta_usuario, $respuesta_usuario]);
            
            // Verificar si es correcta
            $stmt = $pdo->prepare("SELECT es_correcta FROM opciones_respuesta WHERE id = ?");
            $stmt->execute([$respuesta_usuario]);
            $opcion = $stmt->fetch();
            
            if ($opcion && $opcion['es_correcta']) {
                $puntaje_obtenido += $pregunta['puntos'];
            }
        }
    }
    
    // Calcular porcentaje
    $porcentaje = $puntaje_total > 0 ? ($puntaje_obtenido / $puntaje_total) * 100 : 0;
    $estado = $porcentaje >= $evaluacion['puntaje_minimo'] ? 'aprobado' : 'reprobado';
    
    // Actualizar intento
    $stmt = $pdo->prepare("
        UPDATE intentos_evaluacion
        SET puntaje_obtenido = ?, puntaje_total = ?, estado = ?, fecha_finalizacion = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$puntaje_obtenido, $puntaje_total, $estado, $intento['id']]);
    
    registrarLog($_SESSION['usuario_id'], 'Finalizar evaluación', 'Evaluaciones', 
                 "Intento ID: {$intento['id']}, Estado: $estado, Puntaje: $porcentaje%");
    
    if ($es_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'aprobado' => $estado === 'aprobado',
            'estado' => $estado,
            'porcentaje' => round($porcentaje, 1),
            'puntaje_obtenido' => (int)$puntaje_obtenido,
            'puntaje_total' => (int)$puntaje_total,
            'puntaje_minimo' => (int)$evaluacion['puntaje_minimo'],
            'curso_id' => $curso_id,
            'mensaje' => $estado === 'aprobado' 
                ? '¡Felicitaciones! Has aprobado la evaluación.' 
                : 'No has alcanzado el puntaje mínimo para aprobar.'
        ]);
        exit;
    }
    
    header('Location: resultado_evaluacion.php?intento_id=' . $intento['id']);
    exit;
}

require_once '../includes/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><?php echo htmlspecialchars($evaluacion['nombre']); ?></h5>
                    <small><?php echo htmlspecialchars($inscripcion['curso_nombre']); ?></small>
                </div>
                <div class="text-end">
                    <small>Intento <?php echo $intento['numero_intento']; ?> de <?php echo $evaluacion['numero_intentos']; ?></small><br>
                    <small>Puntaje mínimo: <?php echo $evaluacion['puntaje_minimo']; ?>%</small>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($evaluacion['descripcion']): ?>
                <div class="alert alert-info">
                    <?php echo nl2br(htmlspecialchars($evaluacion['descripcion'])); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="formEvaluacion" action="evaluacion.php?curso_id=<?php echo $curso_id; ?>&intento_id=<?php echo (int)$intento['id']; ?>" onsubmit="return false;">
                <?php foreach ($preguntas as $index => $pregunta): ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <h6 class="mb-3">
                                Pregunta <?php echo $index + 1; ?> de <?php echo count($preguntas); ?>
                                <span class="badge bg-secondary float-end"><?php echo $pregunta['puntos']; ?> puntos</span>
                            </h6>
                            <p class="mb-3"><?php echo nl2br(htmlspecialchars($pregunta['pregunta'])); ?></p>
                            
                            <div class="opciones-respuesta">
                                <div class="row">
                                    <?php 
                                    $opciones = $pregunta['opciones'];
                                    $total_opciones = count($opciones);
                                    $mitad = ceil($total_opciones / 2);
                                    $columna1 = array_slice($opciones, 0, $mitad);
                                    $columna2 = array_slice($opciones, $mitad);
                                    ?>
                                    <div class="col-md-6">
                                        <?php foreach ($columna1 as $opcion): ?>
                                            <div class="form-check mb-3 opcion-respuesta-item">
                                                <input class="form-check-input opcion-radio" 
                                                       type="radio" 
                                                       name="respuestas[<?php echo $pregunta['id']; ?>]" 
                                                       id="opcion_<?php echo $opcion['id']; ?>"
                                                       value="<?php echo $opcion['id']; ?>"
                                                       <?php echo (isset($respuestas_guardadas[$pregunta['id']]) && $respuestas_guardadas[$pregunta['id']] == $opcion['id']) ? 'checked' : ''; ?>
                                                       required>
                                                <label class="form-check-label opcion-label" for="opcion_<?php echo $opcion['id']; ?>">
                                                    <?php echo htmlspecialchars($opcion['texto']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?php foreach ($columna2 as $opcion): ?>
                                            <div class="form-check mb-3 opcion-respuesta-item">
                                                <input class="form-check-input opcion-radio" 
                                                       type="radio" 
                                                       name="respuestas[<?php echo $pregunta['id']; ?>]" 
                                                       id="opcion_<?php echo $opcion['id']; ?>"
                                                       value="<?php echo $opcion['id']; ?>"
                                                       <?php echo (isset($respuestas_guardadas[$pregunta['id']]) && $respuestas_guardadas[$pregunta['id']] == $opcion['id']) ? 'checked' : ''; ?>
                                                       required>
                                                <label class="form-check-label opcion-label" for="opcion_<?php echo $opcion['id']; ?>">
                                                    <?php echo htmlspecialchars($opcion['texto']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Importante:</strong> Una vez que envíes la evaluación, no podrás modificar tus respuestas.
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="ver_curso.php?id=<?php echo $curso_id; ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Cancelar
                    </a>
                    <button type="button" name="enviar_evaluacion" id="btnEnviarEvaluacion" class="btn btn-primary">
                        <i class="bi bi-send me-2"></i>Enviar Evaluación
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal resultado (Aprobado / No aprobado) -->
<div class="modal fade" id="modalResultado" tabindex="-1" aria-labelledby="modalResultadoLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="modalResultadoHeader">
                <h5 class="modal-title" id="modalResultadoLabel">
                    <i class="bi bi-check-circle-fill me-2" id="iconResultado"></i>
                    <span id="tituloResultado">Resultado</span>
                </h5>
                <button type="button" class="btn-close" style="display: none;" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center">
                <p class="lead mb-2" id="mensajeResultado"></p>
                <p class="text-muted mb-1">
                    Puntaje: <strong id="puntajeResultado"></strong> (<span id="porcentajeResultado"></span>%)
                </p>
                <p class="small text-muted">Mínimo para aprobar: <span id="minimoResultado"></span>%</p>
            </div>
            <div class="modal-footer justify-content-center">
                <a href="<?php echo BASE_URL; ?>index.php" class="btn btn-primary" id="btnVolverCurso">
                    <i class="bi bi-house me-2"></i>Ir al inicio
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Opciones de respuesta: mismo tipo de letra, sin círculo del radio, fondo azul al seleccionar */
.opciones-respuesta .opcion-respuesta-item,
.opciones-respuesta .opcion-label {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 1rem;
}

/* Ocultar el círculo del radio; la opción sigue siendo seleccionable con clic en el texto */
.opcion-respuesta-item .form-check-input.opcion-radio {
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
    margin: 0;
    pointer-events: none;
}

.opcion-respuesta-item {
    transition: all 0.3s ease;
    padding: 12px 14px;
    border-radius: 8px;
    border: 2px solid transparent;
}

.opcion-respuesta-item:hover {
    background-color: #f8f9fa;
}

.opcion-label {
    cursor: pointer;
    width: 100%;
    padding: 0;
    margin: 0;
    border-radius: 5px;
    transition: all 0.2s ease;
}
</style>

<script>
var evaluacionEnviada = false;

function actualizarEstilosSeleccion(radio) {
    if (!radio || !radio.name) return;
    var m = radio.name.match(/\[(\d+)\]/);
    if (!m) return;
    var preguntaContainer = radio.closest('.card-body');
    if (!preguntaContainer) return;
    preguntaContainer.querySelectorAll('.opcion-respuesta-item').forEach(function(item) {
        item.style.backgroundColor = '';
        item.style.borderColor = '';
        var label = item.querySelector('.opcion-label');
        if (label) {
            label.style.backgroundColor = '';
            label.style.color = '';
            label.style.fontWeight = '';
        }
    });
    if (radio.checked) {
        var item = radio.closest('.opcion-respuesta-item');
        if (item) {
            item.style.backgroundColor = '#0d6efd';
            item.style.borderColor = '#0a58ca';
            var label = item.querySelector('.opcion-label');
            if (label) {
                label.style.backgroundColor = 'transparent';
                label.style.color = '#fff';
                label.style.fontWeight = '600';
            }
        }
    }
}

function guardarRespuesta(pregunta, opcion) {
    var pid = (pregunta || '').split('[')[1];
    if (pid) pid = pid.split(']')[0];
    if (!pid) return;
    fetch('<?php echo BASE_URL; ?>api/guardar_respuesta.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            intento_id: <?php echo $intento['id']; ?>,
            pregunta_id: pid,
            opcion_id: opcion
        })
    }).catch(function(err) { console.error('Error al guardar respuesta:', err); });
}

document.addEventListener('DOMContentLoaded', function() {
    // Radios: solo aplicar estilo azul cuando el usuario hace clic (change), no al cargar
    document.querySelectorAll('input[type="radio"].opcion-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            guardarRespuesta(this.name, this.value);
            actualizarEstilosSeleccion(this);
        });
    });

    // Prevenir salida accidental (solo antes de enviar)
    window.addEventListener('beforeunload', function(e) {
        if (evaluacionEnviada) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // Botón Enviar Evaluación y modal de resultado
    var formEvaluacion = document.getElementById('formEvaluacion');
    var btnEnviar = document.getElementById('btnEnviarEvaluacion');
    var modalEl = document.getElementById('modalResultado');
    if (!formEvaluacion || !btnEnviar || !modalEl) return;

    var modalResultado = typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? new bootstrap.Modal(modalEl)
        : { show: function() {} };
    var modalResultadoHeader = document.getElementById('modalResultadoHeader');
    var iconResultado = document.getElementById('iconResultado');
    var tituloResultado = document.getElementById('tituloResultado');
    var mensajeResultado = document.getElementById('mensajeResultado');
    var puntajeResultado = document.getElementById('puntajeResultado');
    var porcentajeResultado = document.getElementById('porcentajeResultado');
    var minimoResultado = document.getElementById('minimoResultado');
    var btnVolverCurso = document.getElementById('btnVolverCurso');

    function enviarEvaluacion() {
        if (!confirm('¿Estás seguro de enviar la evaluación? No podrás modificar tus respuestas.')) {
            return;
        }
        btnEnviar.disabled = true;
        btnEnviar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';
        var formData = new FormData(formEvaluacion);
        formData.append('enviar_evaluacion', '1');
        formData.append('enviar_ajax', '1');
        var url = formEvaluacion.action || window.location.href;
        fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var aprobado = data.aprobado === true;
            if (modalResultadoHeader) modalResultadoHeader.className = 'modal-header text-white ' + (aprobado ? 'bg-success' : 'bg-danger');
            if (iconResultado) iconResultado.className = 'bi me-2 ' + (aprobado ? 'bi-trophy-fill' : 'bi-x-circle-fill');
            if (tituloResultado) tituloResultado.textContent = aprobado ? '¡Aprobado!' : 'No aprobado';
            if (mensajeResultado) mensajeResultado.textContent = data.mensaje || (aprobado ? 'Has aprobado la evaluación.' : 'No alcanzaste el puntaje mínimo.');
            if (puntajeResultado) puntajeResultado.textContent = data.puntaje_obtenido + ' / ' + data.puntaje_total;
            if (porcentajeResultado) porcentajeResultado.textContent = data.porcentaje;
            if (minimoResultado) minimoResultado.textContent = data.puntaje_minimo;
            if (btnVolverCurso) btnVolverCurso.href = '<?php echo BASE_URL; ?>index.php';
            evaluacionEnviada = true;
            modalResultado.show();
        })
        .catch(function(err) {
            console.error(err);
            alert('Error al enviar la evaluación. Intenta de nuevo.');
        })
        .finally(function() {
            btnEnviar.disabled = false;
            btnEnviar.innerHTML = '<i class="bi bi-send me-2"></i>Enviar Evaluación';
        });
    }

    btnEnviar.addEventListener('click', enviarEvaluacion);
});
</script>

<?php require_once '../includes/footer.php'; ?>
