<?php
require_once '../config/config.php';
requerirPermiso('presentar_evaluaciones');

$page_title = 'Resultado de Evaluación';
$additional_css = ['assets/css/evaluacion.css'];

$pdo = getDBConnection();
$intento_id = (int)($_GET['intento_id'] ?? 0);

if (!$intento_id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT ie.*, e.nombre as evaluacion_nombre, e.puntaje_minimo,
           i.curso_id, c.nombre as curso_nombre
    FROM intentos_evaluacion ie
    INNER JOIN evaluaciones e ON ie.evaluacion_id = e.id
    INNER JOIN inscripciones i ON ie.inscripcion_id = i.id
    INNER JOIN cursos c ON i.curso_id = c.id
    WHERE ie.id = ? AND i.usuario_id = ?
");
$stmt->execute([$intento_id, $_SESSION['usuario_id']]);
$intento = $stmt->fetch();

if (!$intento) {
    header('Location: index.php');
    exit;
}

$porcentaje = $intento['puntaje_total'] > 0 
    ? ($intento['puntaje_obtenido'] / $intento['puntaje_total']) * 100 
    : 0;

// Verificar intentos restantes (solo contar finalizados: aprobado/reprobado)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM intentos_evaluacion
    WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado IN ('aprobado', 'reprobado')
");
$stmt->execute([$intento['inscripcion_id'], $intento['evaluacion_id']]);
$total_intentos = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT numero_intentos FROM evaluaciones WHERE id = ?");
$stmt->execute([$intento['evaluacion_id']]);
$intentos_permitidos = $stmt->fetch()['numero_intentos'];

$intentos_restantes = $intentos_permitidos - $total_intentos;

require_once '../includes/header.php';
?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-<?php echo $intento['estado'] === 'aprobado' ? 'success' : 'danger'; ?> text-white">
            <h5 class="mb-0">
                <i class="bi bi-<?php echo $intento['estado'] === 'aprobado' ? 'check-circle' : 'x-circle'; ?> me-2"></i>
                Resultado de la Evaluación
            </h5>
        </div>
        <div class="card-body text-center">
            <div class="resultado-circle mb-4">
                <div class="circle-progress" data-percent="<?php echo round($porcentaje); ?>">
                    <span class="percent-text"><?php echo round($porcentaje); ?>%</span>
                </div>
            </div>
            
            <h4 class="mb-3">
                <?php echo $intento['estado'] === 'aprobado' ? '¡Felicitaciones! Has aprobado' : 'No has alcanzado el puntaje mínimo'; ?>
            </h4>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-muted">Puntaje Obtenido</h6>
                            <h3><?php echo $intento['puntaje_obtenido']; ?> / <?php echo $intento['puntaje_total']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-muted">Puntaje Mínimo Requerido</h6>
                            <h3><?php echo $intento['puntaje_minimo']; ?>%</h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-4">
                <p><strong>Curso:</strong> <?php echo htmlspecialchars($intento['curso_nombre']); ?></p>
                <p><strong>Evaluación:</strong> <?php echo htmlspecialchars($intento['evaluacion_nombre']); ?></p>
                <p><strong>Intento:</strong> <?php echo $intento['numero_intento']; ?> de <?php echo $intentos_permitidos; ?></p>
                <p class="text-muted">
                    <small>Fecha: <?php echo date('d/m/Y H:i', strtotime($intento['fecha_finalizacion'])); ?></small>
                </p>
            </div>
            
            <?php if ($intento['estado'] === 'reprobado' && $intentos_restantes > 0): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle me-2"></i>
                    Tienes <?php echo $intentos_restantes; ?> intento(s) restante(s). Puedes volver a presentar la evaluación.
                </div>
                <a href="evaluacion.php?curso_id=<?php echo $intento['curso_id']; ?>" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-2"></i>Intentar Nuevamente
                </a>
            <?php elseif ($intento['estado'] === 'reprobado'): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Has agotado todos los intentos disponibles.
                </div>
            <?php endif; ?>
            
            <div class="mt-4">
                <a href="ver_curso.php?id=<?php echo $intento['curso_id']; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Volver al Curso
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.resultado-circle {
    display: flex;
    justify-content: center;
    align-items: center;
}

.circle-progress {
    width: 200px;
    height: 200px;
    border-radius: 50%;
    background: conic-gradient(
        <?php echo $intento['estado'] === 'aprobado' ? '#10b981' : '#ef4444'; ?> 0deg <?php echo $porcentaje * 3.6; ?>deg,
        #e5e7eb <?php echo $porcentaje * 3.6; ?>deg 360deg
    );
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.circle-progress::before {
    content: '';
    width: 160px;
    height: 160px;
    border-radius: 50%;
    background: white;
    position: absolute;
}

.percent-text {
    position: relative;
    z-index: 1;
    font-size: 2.5rem;
    font-weight: 700;
    color: <?php echo $intento['estado'] === 'aprobado' ? '#10b981' : '#ef4444'; ?>;
}
</style>

<?php require_once '../includes/footer.php'; ?>
