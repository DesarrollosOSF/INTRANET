<?php
/**
 * Revisa si, con el estado actual, la inscripción ya cumple todo lo necesario
 * para marcarse como completada, y si es así la marca. Es seguro llamarla
 * después de: marcar un material como visto, o aprobar cualquier evaluación
 * (de módulo o general). No hace nada si ya estaba completada.
 */
function verificarCompletadoCurso(PDO $pdo, int $curso_id, int $inscripcion_id): void
{
    $stmt = $pdo->prepare("SELECT completado FROM formacion_inscripciones WHERE id = ?");
    $stmt->execute([$inscripcion_id]);
    if ((int)$stmt->fetchColumn() === 1) {
        return; // ya estaba completo, nada que hacer
    }

    // 1) Todos los materiales deben estar vistos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM formacion_materiales WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $totalMat = (int)$stmt->fetchColumn();

    if ($totalMat > 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM formacion_progreso_material pm
            JOIN formacion_materiales fm ON fm.id = pm.material_id
            WHERE pm.inscripcion_id = ? AND pm.completado = 1 AND fm.curso_id = ?
        ");
        $stmt->execute([$inscripcion_id, $curso_id]);
        $vistoMat = (int)$stmt->fetchColumn();
        if ($vistoMat < $totalMat) return;
    }

    // 2) Todos los quices por módulo deben estar aprobados
    $stmt = $pdo->prepare("SELECT id FROM formacion_evaluaciones WHERE curso_id = ? AND modulo_id IS NOT NULL");
    $stmt->execute([$curso_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eval_modulo_id) {
        $chk = $pdo->prepare("SELECT 1 FROM formacion_intentos_evaluacion WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado = 'aprobado' LIMIT 1");
        $chk->execute([$inscripcion_id, $eval_modulo_id]);
        if (!$chk->fetchColumn()) return;
    }

    // 3) Si existe evaluación general, también debe estar aprobada
    $stmt = $pdo->prepare("SELECT id FROM formacion_evaluaciones WHERE curso_id = ? AND modulo_id IS NULL");
    $stmt->execute([$curso_id]);
    $eval_general_id = $stmt->fetchColumn();
    if ($eval_general_id) {
        $chk = $pdo->prepare("SELECT 1 FROM formacion_intentos_evaluacion WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado = 'aprobado' LIMIT 1");
        $chk->execute([$inscripcion_id, $eval_general_id]);
        if (!$chk->fetchColumn()) return;
    }

    // Todo listo: completar
    $pdo->prepare("UPDATE formacion_inscripciones SET completado = 1, progreso = 100, fecha_completado = NOW() WHERE id = ?")
        ->execute([$inscripcion_id]);
}

/** Devuelve la evaluación de un módulo (o la general si $modulo_id es null), creándola si no existe. */
function obtenerOcrearEvaluacion(PDO $pdo, int $curso_id, ?int $modulo_id, string $nombreDefault): array
{
    if ($modulo_id === null) {
        $stmt = $pdo->prepare("SELECT * FROM formacion_evaluaciones WHERE curso_id = ? AND modulo_id IS NULL LIMIT 1");
        $stmt->execute([$curso_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM formacion_evaluaciones WHERE curso_id = ? AND modulo_id = ? LIMIT 1");
        $stmt->execute([$curso_id, $modulo_id]);
    }
    $eval = $stmt->fetch();
    if ($eval) return $eval;

    $stmt = $pdo->prepare("INSERT INTO formacion_evaluaciones (curso_id, modulo_id, nombre, puntaje_minimo, numero_intentos) VALUES (?, ?, ?, 70, 3)");
    $stmt->execute([$curso_id, $modulo_id, $nombreDefault]);
    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM formacion_evaluaciones WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Devuelve [completados, total] de materiales vistos dentro de un módulo específico. */
function contarProgresoModulo(PDO $pdo, int $inscripcion_id, int $modulo_id): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM formacion_materiales WHERE modulo_id = ?");
    $stmt->execute([$modulo_id]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM formacion_progreso_material pm
        JOIN formacion_materiales fm ON fm.id = pm.material_id
        WHERE pm.inscripcion_id = ? AND pm.completado = 1 AND fm.modulo_id = ?
    ");
    $stmt->execute([$inscripcion_id, $modulo_id]);
    $completados = (int)$stmt->fetchColumn();

    return [$completados, $total];
}

function evaluacionTienePreguntas(PDO $pdo, int $evaluacion_id): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM formacion_preguntas WHERE evaluacion_id = ?");
    $stmt->execute([$evaluacion_id]);
    return (int)$stmt->fetchColumn() > 0;
}

function evaluacionAprobada(PDO $pdo, int $inscripcion_id, int $evaluacion_id): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM formacion_intentos_evaluacion WHERE inscripcion_id = ? AND evaluacion_id = ? AND estado = 'aprobado' LIMIT 1");
    $stmt->execute([$inscripcion_id, $evaluacion_id]);
    return (bool)$stmt->fetchColumn();
}

function contarIntentosUsados(PDO $pdo, int $inscripcion_id, int $evaluacion_id): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM formacion_intentos_evaluacion WHERE inscripcion_id = ? AND evaluacion_id = ?");
    $stmt->execute([$inscripcion_id, $evaluacion_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Corrige y guarda un intento de evaluación (módulo o general). $respuestas_enviadas es
 * [pregunta_id => opcion_id] tal como llega de $_POST['respuestas'].
 * Devuelve ['aprobado' => bool, 'porcentaje' => int]. Al final revisa si esto completa el curso.
 */
function presentarEvaluacion(PDO $pdo, int $curso_id, int $inscripcion_id, int $evaluacion_id, int $puntaje_minimo, array $respuestas_enviadas): array
{
    $stmt = $pdo->prepare("SELECT * FROM formacion_preguntas WHERE evaluacion_id = ? ORDER BY orden");
    $stmt->execute([$evaluacion_id]);
    $preguntas = $stmt->fetchAll();

    $puntaje_obtenido = 0;
    $puntaje_total = 0;
    $detalle = [];

    foreach ($preguntas as $p) {
        $puntaje_total += (int)$p['puntos'];
        $opcion_marcada = isset($respuestas_enviadas[$p['id']]) ? (int)$respuestas_enviadas[$p['id']] : null;

        $es_correcta = false;
        if ($opcion_marcada) {
            $stmtOp = $pdo->prepare("SELECT es_correcta FROM formacion_opciones_respuesta WHERE id = ? AND pregunta_id = ?");
            $stmtOp->execute([$opcion_marcada, $p['id']]);
            $es_correcta = (bool)$stmtOp->fetchColumn();
        }
        if ($es_correcta) $puntaje_obtenido += (int)$p['puntos'];
        $detalle[] = ['pregunta_id' => $p['id'], 'opcion_id' => $opcion_marcada];
    }

    $porcentaje = $puntaje_total > 0 ? (int)round(($puntaje_obtenido / $puntaje_total) * 100) : 0;
    $aprobado = $porcentaje >= $puntaje_minimo;

    $numero_intento = contarIntentosUsados($pdo, $inscripcion_id, $evaluacion_id) + 1;

    $pdo->prepare("
        INSERT INTO formacion_intentos_evaluacion
            (inscripcion_id, evaluacion_id, numero_intento, puntaje_obtenido, puntaje_total, estado, fecha_finalizacion)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$inscripcion_id, $evaluacion_id, $numero_intento, $puntaje_obtenido, $puntaje_total, $aprobado ? 'aprobado' : 'reprobado']);
    $intento_id = (int)$pdo->lastInsertId();

    $stmtResp = $pdo->prepare("INSERT INTO formacion_respuestas_usuario (intento_id, pregunta_id, opcion_id) VALUES (?, ?, ?)");
    foreach ($detalle as $d) {
        $stmtResp->execute([$intento_id, $d['pregunta_id'], $d['opcion_id']]);
    }

    if ($aprobado) {
        verificarCompletadoCurso($pdo, $curso_id, $inscripcion_id);
    }

    return ['aprobado' => $aprobado, 'porcentaje' => $porcentaje];
}

/** Devuelve la encuesta del curso, creándola vacía si aún no existe. */
function obtenerOcrearEncuesta(PDO $pdo, int $curso_id, string $curso_nombre): array
{
    $stmt = $pdo->prepare("SELECT * FROM formacion_encuestas WHERE curso_id = ? LIMIT 1");
    $stmt->execute([$curso_id]);
    $enc = $stmt->fetch();
    if ($enc) return $enc;

    $stmt = $pdo->prepare("INSERT INTO formacion_encuestas (curso_id, titulo) VALUES (?, ?)");
    $stmt->execute([$curso_id, 'Encuesta de satisfacción — ' . $curso_nombre]);
    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM formacion_encuestas WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}
