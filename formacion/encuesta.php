<?php
require_once '../config/config.php';
requerirPermiso('ver_formacion');

$pdo = getDBConnection();
$usuario_id = (int)$_SESSION['usuario_id'];
$inscripcion_id = (int)($_GET['id'] ?? 0);

// Debe ser una inscripción propia, del curso ya completado
$stmt = $pdo->prepare("
    SELECT fi.*, fc.nombre AS curso_nombre, fc.id AS curso_id
    FROM formacion_inscripciones fi
    JOIN formacion_cursos fc ON fc.id = fi.curso_id
    WHERE fi.id = ? AND fi.usuario_id = ? AND fi.completado = 1
");
$stmt->execute([$inscripcion_id, $usuario_id]);
$inscripcion = $stmt->fetch();
if (!$inscripcion) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM formacion_encuestas WHERE curso_id = ? AND activo = 1");
$stmt->execute([$inscripcion['curso_id']]);
$encuesta = $stmt->fetch();
if (!$encuesta) { header('Location: detalle_curso.php?id=' . $inscripcion['curso_id']); exit; }

$page_title = 'Encuesta: ' . $inscripcion['curso_nombre'];
require_once '../includes/header.php';

$mensaje = ''; $tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $respuestas = $_POST['respuesta'] ?? [];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM formacion_encuesta_preguntas WHERE encuesta_id = ?");
        $stmt->execute([$encuesta['id']]);
        $preguntas = $stmt->fetchAll();

        $stmtInsert = $pdo->prepare("
            INSERT INTO formacion_encuesta_respuestas (inscripcion_id, pregunta_id, opcion_id, valor_escala, texto_libre)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE opcion_id = VALUES(opcion_id), valor_escala = VALUES(valor_escala), texto_libre = VALUES(texto_libre)
        ");
        foreach ($preguntas as $p) {
            $valor = $respuestas[$p['id']] ?? null;
            if ($valor === null || $valor === '') continue;
            if ($p['tipo'] === 'escala_1_5') {
                $stmtInsert->execute([$inscripcion_id, $p['id'], null, (int)$valor, null]);
            } elseif ($p['tipo'] === 'opcion_multiple') {
                $stmtInsert->execute([$inscripcion_id, $p['id'], (int)$valor, null, null]);
            } else {
                $stmtInsert->execute([$inscripcion_id, $p['id'], null, null, sanitizar($valor)]);
            }
        }
        $pdo->prepare("UPDATE formacion_inscripciones SET encuesta_completada = 1 WHERE id = ?")->execute([$inscripcion_id]);
        $pdo->commit();
        registrarLog($usuario_id, 'Responder encuesta formación', 'Formación', "Inscripción ID: $inscripcion_id");
        $mensaje = '¡Gracias por tus respuestas!'; $tipo_mensaje = 'success';
        $inscripcion['encuesta_completada'] = 1;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = 'Error al guardar tus respuestas: ' . $e->getMessage(); $tipo_mensaje = 'danger';
    }
}

$stmt = $pdo->prepare("SELECT * FROM formacion_encuesta_preguntas WHERE encuesta_id = ? ORDER BY orden");
$stmt->execute([$encuesta['id']]);
$preguntas = $stmt->fetchAll();

$opciones_por_pregunta = [];
if (!empty($preguntas)) {
    $ids = array_column($preguntas, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM formacion_encuesta_opciones WHERE pregunta_id IN ($ph) ORDER BY orden");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $o) $opciones_por_pregunta[$o['pregunta_id']][] = $o;
}
?>

<div class="container-fluid mt-4" style="max-width:700px;">
    <a href="detalle_curso.php?id=<?php echo $inscripcion['curso_id']; ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Volver al curso</a>

    <div class="card shadow-sm">
        <div class="card-body">
            <h4><?php echo htmlspecialchars($encuesta['titulo']); ?></h4>
            <?php if ($encuesta['descripcion']): ?><p class="text-muted"><?php echo htmlspecialchars($encuesta['descripcion']); ?></p><?php endif; ?>

            <?php if ($mensaje): ?><div class="alert alert-<?php echo $tipo_mensaje; ?>"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>

            <?php if ($inscripcion['encuesta_completada']): ?>
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0">Ya respondiste esta encuesta. ¡Gracias por tu tiempo!</p>
                </div>
            <?php else: ?>
                <form method="POST">
                    <?php foreach ($preguntas as $i => $p): ?>
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?php echo $i + 1; ?>. <?php echo htmlspecialchars($p['pregunta']); ?></label>

                        <?php if ($p['tipo'] === 'escala_1_5'): ?>
                            <div class="d-flex gap-3">
                                <?php for ($v = 1; $v <= 5; $v++): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="respuesta[<?php echo $p['id']; ?>]" value="<?php echo $v; ?>" id="p<?php echo $p['id']; ?>_<?php echo $v; ?>" required>
                                        <label class="form-check-label" for="p<?php echo $p['id']; ?>_<?php echo $v; ?>"><?php echo $v; ?></label>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <div class="d-flex justify-content-between small text-muted"><span>Muy en desacuerdo</span><span>Muy de acuerdo</span></div>

                        <?php elseif ($p['tipo'] === 'opcion_multiple'): ?>
                            <?php foreach (($opciones_por_pregunta[$p['id']] ?? []) as $op): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="respuesta[<?php echo $p['id']; ?>]" value="<?php echo $op['id']; ?>" id="op<?php echo $op['id']; ?>" required>
                                    <label class="form-check-label" for="op<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['texto']); ?></label>
                                </div>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <textarea class="form-control" name="respuesta[<?php echo $p['id']; ?>]" rows="3" placeholder="Escribe tu respuesta..."></textarea>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Enviar respuestas</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
