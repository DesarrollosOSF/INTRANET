<?php
require_once '../config/config.php';
requerirPermiso('gestionar_cursos');

$page_title = 'Gestión de Evaluaciones';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

$pdo = getDBConnection();
$curso_id = (int)($_GET['curso_id'] ?? 0);
$accion = $_GET['accion'] ?? '';

if (!$curso_id) {
    header('Location: cursos.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
$stmt->execute([$curso_id]);
$curso = $stmt->fetch();

if (!$curso) {
    header('Location: cursos.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion_post = $_POST['accion'] ?? '';
    
    if ($accion_post === 'crear_evaluacion' || $accion_post === 'editar_evaluacion') {  
        $nombre = sanitizar($_POST['nombre']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $puntaje_minimo = (int)$_POST['puntaje_minimo'];
        $numero_intentos = (int)$_POST['numero_intentos'];
        $evaluacion_id = $accion_post === 'editar_evaluacion' ? (int)$_POST['evaluacion_id'] : null;
        
        try {
            if ($accion_post === 'crear_evaluacion') {
                $stmt = $pdo->prepare("
                    INSERT INTO evaluaciones (curso_id, nombre, descripcion, puntaje_minimo, numero_intentos)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$curso_id, $nombre, $descripcion, $puntaje_minimo, $numero_intentos]);
                $evaluacion_id = $pdo->lastInsertId();
                registrarLog($_SESSION['usuario_id'], 'Crear evaluación', 'Evaluaciones', "Curso ID: $curso_id");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE evaluaciones
                    SET nombre = ?, descripcion = ?, puntaje_minimo = ?, numero_intentos = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $descripcion, $puntaje_minimo, $numero_intentos, $evaluacion_id]);
                registrarLog($_SESSION['usuario_id'], 'Editar evaluación', 'Evaluaciones', "Evaluación ID: $evaluacion_id");
            }
            $mensaje = 'Evaluación guardada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion_post === 'agregar_pregunta') {
        $evaluacion_id = (int)$_POST['evaluacion_id'];
        $pregunta = sanitizar($_POST['pregunta']);
        $puntos = (int)$_POST['puntos'];
        $orden = (int)$_POST['orden'];
        $opciones = $_POST['opciones'] ?? [];
        $correcta = (int)$_POST['correcta'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO preguntas (evaluacion_id, pregunta, puntos, orden)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$evaluacion_id, $pregunta, $puntos, $orden]);
            $pregunta_id = $pdo->lastInsertId();
            
            // Agregar opciones
            foreach ($opciones as $index => $texto) {
                $es_correcta = ($index == $correcta) ? 1 : 0;
                $stmt = $pdo->prepare("
                    INSERT INTO opciones_respuesta (pregunta_id, texto, es_correcta, orden)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$pregunta_id, sanitizar($texto), $es_correcta, $index]);
            }
            
            registrarLog($_SESSION['usuario_id'], 'Agregar pregunta', 'Evaluaciones', "Pregunta ID: $pregunta_id");
            $mensaje = 'Pregunta agregada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion_post === 'eliminar_pregunta') {
        $pregunta_id = (int)$_POST['pregunta_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM preguntas WHERE id = ?");
            $stmt->execute([$pregunta_id]);
            registrarLog($_SESSION['usuario_id'], 'Eliminar pregunta', 'Evaluaciones', "Pregunta ID: $pregunta_id");
            $mensaje = 'Pregunta eliminada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al eliminar pregunta';
            $tipo_mensaje = 'danger';
        }
    }
}

// Obtener evaluación
$stmt = $pdo->prepare("SELECT * FROM evaluaciones WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$evaluacion = $stmt->fetch();

if (!$evaluacion && $accion !== 'crear') {
    $accion = 'crear';
}

// Obtener preguntas si existe evaluación
$preguntas = [];
if ($evaluacion) {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               (SELECT COUNT(*) FROM opciones_respuesta WHERE pregunta_id = p.id) as num_opciones
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
    unset($pregunta); // Romper referencia para no sobrescribir el último elemento en el siguiente foreach
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-clipboard-check me-2"></i>Evaluación del Curso</h2>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($curso['nombre']); ?></p>
        </div>
        <a href="cursos_detalle.php?id=<?php echo $curso_id; ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>Volver
        </a>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Formulario crear/editar evaluación: el super administrador puede ajustar número de intentos para reactivar el cuestionario -->
    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo $evaluacion ? 'Editar' : 'Crear'; ?> Evaluación</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="accion" value="<?php echo $evaluacion ? 'editar_evaluacion' : 'crear_evaluacion'; ?>">
                <?php if ($evaluacion): ?>
                    <input type="hidden" name="evaluacion_id" value="<?php echo $evaluacion['id']; ?>">
                <?php endif; ?>
                
                <div class="mb-3">
                    <label class="form-label">Nombre de la Evaluación *</label>
                    <input type="text" class="form-control" name="nombre" 
                           value="<?php echo htmlspecialchars($evaluacion['nombre'] ?? ''); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" name="descripcion" rows="3"><?php echo htmlspecialchars($evaluacion['descripcion'] ?? ''); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Puntaje Mínimo para Aprobar (%) *</label>
                            <input type="number" class="form-control" name="puntaje_minimo" 
                                   value="<?php echo $evaluacion['puntaje_minimo'] ?? 70; ?>" 
                                   min="0" max="100" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Número de Intentos Permitidos *</label>
                            <input type="number" class="form-control" name="numero_intentos" 
                                   value="<?php echo $evaluacion['numero_intentos'] ?? 3; ?>" 
                                   min="1" max="99" required>
                            <small class="form-text text-muted">Aumente este valor para dar más intentos a usuarios que agotaron los actuales (reactivar cuestionario).</small>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Guardar Evaluación
                </button>
            </form>
        </div>
    </div>
    
    <?php if ($evaluacion): ?>
        <!-- Gestión de preguntas -->
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Preguntas de la Evaluación</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalPregunta">
                    <i class="bi bi-plus-circle me-1"></i>Agregar Pregunta
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($preguntas)): ?>
                    <p class="text-muted">No hay preguntas agregadas aún</p>
                <?php else: ?>
                    <?php foreach ($preguntas as $index => $pregunta): ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="flex-grow-1">
                                        <h6>Pregunta <?php echo $index + 1; ?> (<?php echo $pregunta['puntos']; ?> puntos)</h6>
                                        <p class="mb-2"><?php echo nl2br(htmlspecialchars($pregunta['pregunta'])); ?></p>
                                        <ul class="list-unstyled">
                                            <?php foreach ($pregunta['opciones'] as $opcion): ?>
                                                <li class="mb-1">
                                                    <?php if ($opcion['es_correcta']): ?>
                                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-circle me-2"></i>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($opcion['texto']); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta pregunta?');">
                                        <input type="hidden" name="accion" value="eliminar_pregunta">
                                        <input type="hidden" name="pregunta_id" value="<?php echo $pregunta['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Pregunta -->
<?php if ($evaluacion): ?>
<?php $siguienteOrden = count($preguntas); ?>
<div class="modal fade" id="modalPregunta" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="formPregunta">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar Pregunta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="agregar_pregunta">
                    <input type="hidden" name="evaluacion_id" value="<?php echo $evaluacion['id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Pregunta *</label>
                        <textarea class="form-control" name="pregunta" id="inputPregunta" rows="3" required></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Puntos *</label>
                                <input type="number" class="form-control" name="puntos" id="inputPuntos" value="1" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Orden</label>
                                <input type="number" class="form-control" name="orden" id="inputOrden" value="<?php echo $siguienteOrden; ?>" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    <h6>Opciones de Respuesta</h6>
                    <p class="text-muted small mb-2">
                        <i class="bi bi-info-circle me-1"></i>Marque con el círculo la opción que es la <strong>respuesta correcta</strong>. Al presentar el examen se comparará con la respuesta del usuario para aprobar o reprobar.
                    </p>
                    <div id="opcionesContainer">
                        <div class="opcion-item mb-3">
                            <div class="input-group">
                                <span class="input-group-text" title="Marcar como respuesta correcta">
                                    <input type="radio" name="correcta" value="0" checked aria-label="Respuesta correcta opción 1">
                                </span>
                                <input type="text" class="form-control" name="opciones[]" placeholder="Opción 1" required>
                            </div>
                        </div>
                        <div class="opcion-item mb-3">
                            <div class="input-group">
                                <span class="input-group-text" title="Marcar como respuesta correcta">
                                    <input type="radio" name="correcta" value="1" aria-label="Respuesta correcta opción 2">
                                </span>
                                <input type="text" class="form-control" name="opciones[]" placeholder="Opción 2" required>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarOpcion()">
                        <i class="bi bi-plus-circle me-1"></i>Agregar Opción
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Pregunta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let contadorOpciones = 2;
const siguienteOrdenInicial = <?php echo (int)$siguienteOrden; ?>;

function agregarOpcion() {
    const container = document.getElementById('opcionesContainer');
    const nuevaOpcion = document.createElement('div');
    nuevaOpcion.className = 'opcion-item mb-3';
    nuevaOpcion.innerHTML = `
        <div class="input-group">
            <span class="input-group-text" title="Marcar como respuesta correcta">
                <input type="radio" name="correcta" value="${contadorOpciones}" aria-label="Respuesta correcta opción ${contadorOpciones + 1}">
            </span>
            <input type="text" class="form-control" name="opciones[]" placeholder="Opción ${contadorOpciones + 1}" required>
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.opcion-item').remove()">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(nuevaOpcion);
    contadorOpciones++;
}

function resetearFormularioPregunta() {
    const form = document.getElementById('formPregunta');
    const container = document.getElementById('opcionesContainer');
    
    form.reset();
    
    // Restaurar solo 2 opciones vacías y reiniciar contador
    container.innerHTML = `
        <div class="opcion-item mb-3">
            <div class="input-group">
                <span class="input-group-text" title="Marcar como respuesta correcta">
                    <input type="radio" name="correcta" value="0" checked aria-label="Respuesta correcta opción 1">
                </span>
                <input type="text" class="form-control" name="opciones[]" placeholder="Opción 1" required>
            </div>
        </div>
        <div class="opcion-item mb-3">
            <div class="input-group">
                <span class="input-group-text" title="Marcar como respuesta correcta">
                    <input type="radio" name="correcta" value="1" aria-label="Respuesta correcta opción 2">
                </span>
                <input type="text" class="form-control" name="opciones[]" placeholder="Opción 2" required>
            </div>
        </div>
    `;
    contadorOpciones = 2;
    
    document.getElementById('inputPuntos').value = 1;
    document.getElementById('inputOrden').value = siguienteOrdenInicial;
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('modalPregunta');
    if (modal) {
        modal.addEventListener('show.bs.modal', function() {
            resetearFormularioPregunta();
        });
    }
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
