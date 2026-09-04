<?php
require_once '../config/config.php';
requerirPermiso('gestionar_formacion');

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

$curso_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM formacion_cursos WHERE id = ?");
$stmt->execute([$curso_id]);
$curso = $stmt->fetch();

if (!$curso) {
    header('Location: formacion.php');
    exit;
}

$es_solo_evaluacion = $curso['modalidad'] === 'solo_evaluacion';

$page_title = 'Contenido: ' . $curso['nombre'];
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

function subirMaterialFormacion(array $archivo, string $tipo): string
{
    if (!isset($archivo['error']) || $archivo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Debes seleccionar un archivo válido.');
    }
    $limites = [
        'video' => ['video/mp4', 'video/webm'],
        'pdf' => ['application/pdf'],
        'imagen' => ['image/jpeg', 'image/png', 'image/webp'],
    ];
    $tamanos_max = [
        'video' => MAX_VIDEO_SIZE,
        'pdf' => MAX_DOCUMENT_SIZE,
        'imagen' => MAX_IMAGE_SIZE,
    ];

    $mime = mime_content_type($archivo['tmp_name']);
    if (!in_array($mime, $limites[$tipo] ?? [], true)) {
        throw new Exception('El tipo de archivo no coincide con el material seleccionado (' . $tipo . ').');
    }
    if ($archivo['size'] > ($tamanos_max[$tipo] ?? 0)) {
        throw new Exception('El archivo supera el tamaño máximo permitido para ' . $tipo . '.');
    }

    $carpeta = __DIR__ . '/../uploads/formacion/materiales/';
    if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);

    $ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);
    $nombre = uniqid('mat_') . '.' . strtolower($ext);
    move_uploaded_file($archivo['tmp_name'], $carpeta . $nombre);

    return 'formacion/materiales/' . $nombre;
}

// ==========================================================
// Procesar acciones (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ---------- Módulos ----------
    if ($accion === 'crear_modulo') {
        $titulo = sanitizar($_POST['titulo']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),0)+1 AS n FROM formacion_modulos WHERE curso_id = $curso_id")->fetch()['n'];
        $pdo->prepare("INSERT INTO formacion_modulos (curso_id, titulo, descripcion, orden) VALUES (?, ?, ?, ?)")
            ->execute([$curso_id, $titulo, $descripcion, $orden]);
        $mensaje = 'Módulo creado.'; $tipo_mensaje = 'success';
    } elseif ($accion === 'editar_modulo') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE formacion_modulos SET titulo = ?, descripcion = ? WHERE id = ? AND curso_id = ?")
            ->execute([sanitizar($_POST['titulo']), sanitizar($_POST['descripcion'] ?? ''), $id, $curso_id]);
        $mensaje = 'Módulo actualizado.'; $tipo_mensaje = 'success';
    } elseif ($accion === 'eliminar_modulo') {
        $pdo->prepare("DELETE FROM formacion_modulos WHERE id = ? AND curso_id = ?")->execute([(int)$_POST['id'], $curso_id]);
        $mensaje = 'Módulo eliminado (junto con sus materiales y su quiz).'; $tipo_mensaje = 'success';

    // ---------- Materiales ----------
    } elseif ($accion === 'crear_material') {
        $modulo_id = (int)$_POST['modulo_id'];
        $tipo = in_array($_POST['tipo'] ?? '', ['video', 'pdf', 'imagen'], true) ? $_POST['tipo'] : 'pdf';
        try {
            $archivo = subirMaterialFormacion($_FILES['archivo'] ?? [], $tipo);
            $orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),0)+1 AS n FROM formacion_materiales WHERE modulo_id = $modulo_id")->fetch()['n'];
            $pdo->prepare("
                INSERT INTO formacion_materiales (curso_id, modulo_id, tipo, titulo, descripcion, archivo, tiempo_minimo, orden, solo_visualizacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $curso_id, $modulo_id, $tipo, sanitizar($_POST['titulo']), sanitizar($_POST['descripcion'] ?? ''),
                $archivo, (int)($_POST['tiempo_minimo'] ?? 0), $orden, isset($_POST['solo_visualizacion']) ? 1 : 0,
            ]);
            $mensaje = 'Material agregado.'; $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al subir el material: ' . $e->getMessage(); $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'eliminar_material') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT archivo FROM formacion_materiales WHERE id = ? AND curso_id = ?");
        $stmt->execute([$id, $curso_id]);
        if ($mat = $stmt->fetch()) {
            $ruta = __DIR__ . '/../uploads/' . $mat['archivo'];
            if (is_file($ruta)) @unlink($ruta);
            $pdo->prepare("DELETE FROM formacion_materiales WHERE id = ?")->execute([$id]);
            $mensaje = 'Material eliminado.'; $tipo_mensaje = 'success';
        }

    // ---------- Evaluación (general o de un módulo) ----------
    } elseif ($accion === 'guardar_evaluacion') {
        $nombre = sanitizar($_POST['nombre']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $puntaje_minimo = (int)$_POST['puntaje_minimo'];
        $numero_intentos = (int)$_POST['numero_intentos'];
        $modulo_id = !empty($_POST['modulo_id']) ? (int)$_POST['modulo_id'] : null;

        $existente = $pdo->prepare("SELECT id FROM formacion_evaluaciones WHERE curso_id = ? AND modulo_id <=> ?");
        $existente->execute([$curso_id, $modulo_id]);
        $eval_id = $existente->fetchColumn();

        if ($eval_id) {
            $pdo->prepare("UPDATE formacion_evaluaciones SET nombre=?, descripcion=?, puntaje_minimo=?, numero_intentos=? WHERE id=?")
                ->execute([$nombre, $descripcion, $puntaje_minimo, $numero_intentos, $eval_id]);
            $mensaje = 'Evaluación actualizada.';
        } else {
            $pdo->prepare("INSERT INTO formacion_evaluaciones (curso_id, modulo_id, nombre, descripcion, puntaje_minimo, numero_intentos) VALUES (?,?,?,?,?,?)")
                ->execute([$curso_id, $modulo_id, $nombre, $descripcion, $puntaje_minimo, $numero_intentos]);
            $mensaje = 'Evaluación creada.';
        }
        $tipo_mensaje = 'success';

    // ---------- Preguntas de evaluación ----------
    } elseif ($accion === 'guardar_pregunta') {
        $evaluacion_id = (int)$_POST['evaluacion_id'];
        $texto_pregunta = sanitizar($_POST['pregunta']);
        $puntos = (int)$_POST['puntos'];
        $opciones = $_POST['opciones_texto'] ?? [];
        $correcta_idx = (int)($_POST['correcta'] ?? -1);
        $pregunta_id = (int)($_POST['pregunta_id'] ?? 0);
        $opciones_validas = array_filter($opciones, fn($o) => trim($o) !== '');

        if ($texto_pregunta === '' || count($opciones_validas) < 2 || $correcta_idx < 0) {
            $mensaje = 'La pregunta necesita texto, al menos 2 opciones y una marcada como correcta.';
            $tipo_mensaje = 'danger';
        } else {
            try {
                $pdo->beginTransaction();
                if ($pregunta_id) {
                    $pdo->prepare("UPDATE formacion_preguntas SET pregunta = ?, puntos = ? WHERE id = ?")->execute([$texto_pregunta, $puntos, $pregunta_id]);
                    $pdo->prepare("DELETE FROM formacion_opciones_respuesta WHERE pregunta_id = ?")->execute([$pregunta_id]);
                } else {
                    $orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),0)+1 AS n FROM formacion_preguntas WHERE evaluacion_id = $evaluacion_id")->fetch()['n'];
                    $pdo->prepare("INSERT INTO formacion_preguntas (evaluacion_id, pregunta, puntos, orden) VALUES (?,?,?,?)")->execute([$evaluacion_id, $texto_pregunta, $puntos, $orden]);
                    $pregunta_id = (int)$pdo->lastInsertId();
                }
                $stmtOpc = $pdo->prepare("INSERT INTO formacion_opciones_respuesta (pregunta_id, texto, es_correcta, orden) VALUES (?,?,?,?)");
                $i = 0;
                foreach ($opciones as $idx => $texto_opcion) {
                    if (trim($texto_opcion) === '') continue;
                    $stmtOpc->execute([$pregunta_id, sanitizar($texto_opcion), $idx == $correcta_idx ? 1 : 0, $i++]);
                }
                $pdo->commit();
                $mensaje = 'Pregunta guardada.'; $tipo_mensaje = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensaje = 'Error al guardar la pregunta: ' . $e->getMessage(); $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'eliminar_pregunta') {
        $pdo->prepare("DELETE FROM formacion_preguntas WHERE id = ?")->execute([(int)$_POST['id']]);
        $mensaje = 'Pregunta eliminada.'; $tipo_mensaje = 'success';

    // ---------- Encuesta de satisfacción ----------
    } elseif ($accion === 'guardar_encuesta') {
        $titulo = sanitizar($_POST['titulo']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $existente = $pdo->prepare("SELECT id FROM formacion_encuestas WHERE curso_id = ?");
        $existente->execute([$curso_id]);
        if ($enc_id = $existente->fetchColumn()) {
            $pdo->prepare("UPDATE formacion_encuestas SET titulo = ?, descripcion = ? WHERE id = ?")->execute([$titulo, $descripcion, $enc_id]);
            $mensaje = 'Encuesta actualizada.';
        } else {
            $pdo->prepare("INSERT INTO formacion_encuestas (curso_id, titulo, descripcion) VALUES (?,?,?)")->execute([$curso_id, $titulo, $descripcion]);
            $mensaje = 'Encuesta creada.';
        }
        $tipo_mensaje = 'success';
    } elseif ($accion === 'guardar_pregunta_encuesta') {
        $encuesta_id = (int)$_POST['encuesta_id'];
        $texto = sanitizar($_POST['pregunta']);
        $tipo_pregunta = in_array($_POST['tipo'] ?? '', ['escala_1_5', 'opcion_multiple', 'texto_libre'], true) ? $_POST['tipo'] : 'escala_1_5';
        $pregunta_id = (int)($_POST['pregunta_id'] ?? 0);
        $opciones = $_POST['opciones_texto'] ?? [];

        if ($texto === '') {
            $mensaje = 'La pregunta necesita un texto.'; $tipo_mensaje = 'danger';
        } else {
            try {
                $pdo->beginTransaction();
                if ($pregunta_id) {
                    $pdo->prepare("UPDATE formacion_encuesta_preguntas SET pregunta = ?, tipo = ? WHERE id = ?")->execute([$texto, $tipo_pregunta, $pregunta_id]);
                    $pdo->prepare("DELETE FROM formacion_encuesta_opciones WHERE pregunta_id = ?")->execute([$pregunta_id]);
                } else {
                    $orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),0)+1 AS n FROM formacion_encuesta_preguntas WHERE encuesta_id = $encuesta_id")->fetch()['n'];
                    $pdo->prepare("INSERT INTO formacion_encuesta_preguntas (encuesta_id, pregunta, tipo, orden) VALUES (?,?,?,?)")->execute([$encuesta_id, $texto, $tipo_pregunta, $orden]);
                    $pregunta_id = (int)$pdo->lastInsertId();
                }
                if ($tipo_pregunta === 'opcion_multiple') {
                    $stmtOpc = $pdo->prepare("INSERT INTO formacion_encuesta_opciones (pregunta_id, texto, orden) VALUES (?,?,?)");
                    $i = 0;
                    foreach ($opciones as $texto_opcion) {
                        if (trim($texto_opcion) === '') continue;
                        $stmtOpc->execute([$pregunta_id, sanitizar($texto_opcion), $i++]);
                    }
                }
                $pdo->commit();
                $mensaje = 'Pregunta de la encuesta guardada.'; $tipo_mensaje = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensaje = 'Error al guardar la pregunta: ' . $e->getMessage(); $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'eliminar_pregunta_encuesta') {
        $pdo->prepare("DELETE FROM formacion_encuesta_preguntas WHERE id = ?")->execute([(int)$_POST['id']]);
        $mensaje = 'Pregunta de encuesta eliminada.'; $tipo_mensaje = 'success';
    }
}

// ==========================================================
// Cargar datos para la vista
// ==========================================================
$modulos = [];
$materiales_por_modulo = [];
if (!$es_solo_evaluacion) {
    $stmt = $pdo->prepare("SELECT * FROM formacion_modulos WHERE curso_id = ? ORDER BY orden");
    $stmt->execute([$curso_id]);
    $modulos = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM formacion_materiales WHERE curso_id = ? ORDER BY orden");
    $stmt->execute([$curso_id]);
    foreach ($stmt->fetchAll() as $m) $materiales_por_modulo[$m['modulo_id']][] = $m;
}

// Todas las evaluaciones del curso (una por módulo + la general con modulo_id NULL)
$stmt = $pdo->prepare("SELECT * FROM formacion_evaluaciones WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$evaluaciones_por_modulo = [];
$evaluacion_general = null;
foreach ($stmt->fetchAll() as $e) {
    if ($e['modulo_id'] === null) $evaluacion_general = $e;
    else $evaluaciones_por_modulo[$e['modulo_id']] = $e;
}

// Preguntas + opciones de TODAS las evaluaciones del curso, indexadas por evaluacion_id
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

// Encuesta del curso
$stmt = $pdo->prepare("SELECT * FROM formacion_encuestas WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$encuesta = $stmt->fetch();
$preguntas_encuesta = [];
$opciones_encuesta_por_pregunta = [];
if ($encuesta) {
    $stmt = $pdo->prepare("SELECT * FROM formacion_encuesta_preguntas WHERE encuesta_id = ? ORDER BY orden");
    $stmt->execute([$encuesta['id']]);
    $preguntas_encuesta = $stmt->fetchAll();
    if (!empty($preguntas_encuesta)) {
        $ids = array_column($preguntas_encuesta, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM formacion_encuesta_opciones WHERE pregunta_id IN ($ph) ORDER BY orden");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $o) $opciones_encuesta_por_pregunta[$o['pregunta_id']][] = $o;
    }
}

$iconos_tipo = ['video' => 'bi-camera-video', 'pdf' => 'bi-file-earmark-pdf', 'imagen' => 'bi-image'];
$etiquetas_tipo_pregunta = ['escala_1_5' => 'Escala 1 a 5', 'opcion_multiple' => 'Opción múltiple', 'texto_libre' => 'Texto libre'];

/** Imprime el listado de preguntas + botón agregar, para una evaluación de quiz (módulo o general) */
function renderPreguntas(?array $evaluacion, array $preguntas_por_evaluacion, array $opciones_por_pregunta): void
{
    if (!$evaluacion) return;
    $preguntas = $preguntas_por_evaluacion[$evaluacion['id']] ?? [];
    echo '<div class="row mb-2 small text-muted">';
    echo '<div class="col-md-4">Puntaje mínimo: <strong>' . $evaluacion['puntaje_minimo'] . '%</strong></div>';
    echo '<div class="col-md-4">Intentos: <strong>' . $evaluacion['numero_intentos'] . '</strong></div>';
    echo '<div class="col-md-4">Preguntas: <strong>' . count($preguntas) . '</strong></div>';
    echo '</div>';
    echo '<button type="button" class="btn btn-sm btn-success mb-2" onclick="nuevaPregunta(' . $evaluacion['id'] . ')"><i class="bi bi-plus-circle me-1"></i>Agregar pregunta</button>';
    foreach ($preguntas as $i => $p) {
        $opciones = $opciones_por_pregunta[$p['id']] ?? [];
        echo '<div class="card mb-2"><div class="card-body py-2"><div class="d-flex justify-content-between align-items-start">';
        echo '<div><strong>' . ($i + 1) . '. ' . htmlspecialchars($p['pregunta']) . '</strong> <span class="badge bg-secondary">' . $p['puntos'] . ' pts</span><ul class="small text-muted mb-0 mt-1">';
        foreach ($opciones as $op) {
            echo '<li class="' . ($op['es_correcta'] ? 'text-success fw-semibold' : '') . '">' . htmlspecialchars($op['texto']) . ($op['es_correcta'] ? ' <i class="bi bi-check-circle-fill"></i>' : '') . '</li>';
        }
        echo '</ul></div><div class="d-flex gap-1">';
        echo '<button type="button" class="btn btn-sm btn-outline-primary" onclick=\'editarPregunta(' . json_encode($p + ['opciones' => $opciones]) . ')\'><i class="bi bi-pencil"></i></button>';
        echo '<form method="POST" onsubmit="return confirm(\'¿Eliminar esta pregunta?\');"><input type="hidden" name="accion" value="eliminar_pregunta"><input type="hidden" name="id" value="' . $p['id'] . '"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>';
        echo '</div></div></div></div>';
    }
    if (empty($preguntas)) echo '<p class="text-muted small">Sin preguntas todavía.</p>';
}
?>

<div class="container-fluid mt-4">
    <a href="formacion.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Volver a Formación</a>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="mb-1"><?php echo htmlspecialchars($curso['nombre']); ?></h2>
            <span class="badge bg-<?php echo $es_solo_evaluacion ? 'warning text-dark' : 'secondary'; ?>">
                <?php echo $es_solo_evaluacion ? 'Solo evaluación y encuesta' : 'Contenido en la plataforma'; ?>
            </span>
        </div>
        <a href="formacion_reportes.php?curso_id=<?php echo $curso_id; ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-bar-chart me-1"></i>Ver informe / reportes
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ============ MÓDULOS, MATERIALES Y QUIZ POR MÓDULO ============ -->
    <?php if (!$es_solo_evaluacion): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-collection-play me-2"></i>Módulos, materiales y quiz por módulo</span>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalModulo" onclick="nuevoModulo()">
                <i class="bi bi-plus-circle me-1"></i>Nuevo módulo
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($modulos)): ?>
                <p class="text-muted text-center py-3 mb-0">Aún no hay módulos.</p>
            <?php endif; ?>

            <div class="accordion" id="accordionModulos">
                <?php foreach ($modulos as $idx => $modulo): ?>
                <?php $eval_mod = $evaluaciones_por_modulo[$modulo['id']] ?? null; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?php echo $idx > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#modColl<?php echo $modulo['id']; ?>">
                            <?php echo htmlspecialchars($modulo['titulo']); ?>
                            <span class="badge bg-secondary ms-2"><?php echo count($materiales_por_modulo[$modulo['id']] ?? []); ?> materiales</span>
                            <?php if ($eval_mod): ?><span class="badge bg-info text-dark ms-1">Con quiz</span><?php endif; ?>
                        </button>
                    </h2>
                    <div id="modColl<?php echo $modulo['id']; ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" data-bs-parent="#accordionModulos">
                        <div class="accordion-body">
                            <div class="d-flex gap-2 mb-3 flex-wrap">
                                <button class="btn btn-sm btn-outline-primary" onclick='editarModulo(<?php echo json_encode($modulo); ?>)'><i class="bi bi-pencil me-1"></i>Editar módulo</button>
                                <button class="btn btn-sm btn-outline-success" onclick="nuevoMaterial(<?php echo $modulo['id']; ?>)"><i class="bi bi-file-earmark-plus me-1"></i>Agregar material</button>
                                <form method="POST" onsubmit="return confirm('¿Eliminar este módulo, sus materiales y su quiz?');">
                                    <input type="hidden" name="accion" value="eliminar_modulo"><input type="hidden" name="id" value="<?php echo $modulo['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                                <button class="btn btn-sm btn-outline-info ms-auto" onclick='abrirModalEvaluacion(<?php echo $modulo['id']; ?>, <?php echo $eval_mod ? json_encode($eval_mod) : "null"; ?>, "<?php echo htmlspecialchars($modulo['titulo']); ?>")'>
                                    <i class="bi bi-patch-question me-1"></i><?php echo $eval_mod ? 'Editar quiz del módulo' : 'Configurar quiz del módulo'; ?>
                                </button>
                            </div>

                            <ul class="list-group mb-3">
                                <?php foreach (($materiales_por_modulo[$modulo['id']] ?? []) as $mat): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi <?php echo $iconos_tipo[$mat['tipo']] ?? 'bi-file'; ?> me-2"></i><?php echo htmlspecialchars($mat['titulo']); ?></span>
                                    <form method="POST" onsubmit="return confirm('¿Eliminar este material?');"><input type="hidden" name="accion" value="eliminar_material"><input type="hidden" name="id" value="<?php echo $mat['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty($materiales_por_modulo[$modulo['id']] ?? [])): ?><li class="list-group-item text-muted small">Sin materiales todavía.</li><?php endif; ?>
                            </ul>

                            <?php if ($eval_mod): ?>
                                <hr>
                                <p class="fw-semibold small mb-2"><i class="bi bi-patch-question me-1"></i>Quiz de este módulo: <?php echo htmlspecialchars($eval_mod['nombre']); ?></p>
                                <?php renderPreguntas($eval_mod, $preguntas_por_evaluacion, $opciones_por_pregunta); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ EVALUACIÓN GENERAL ============ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-award me-2"></i>Evaluación general del curso</span>
            <button class="btn btn-sm btn-primary" onclick='abrirModalEvaluacion(null, <?php echo $evaluacion_general ? json_encode($evaluacion_general) : "null"; ?>, null)'>
                <i class="bi bi-gear me-1"></i><?php echo $evaluacion_general ? 'Editar configuración' : 'Configurar evaluación general'; ?>
            </button>
        </div>
        <div class="card-body">
            <?php if (!$evaluacion_general): ?>
                <p class="text-muted text-center py-3 mb-0">
                    Sin evaluación general configurada. <?php echo !$es_solo_evaluacion ? 'El curso se dará por completado cuando el trabajador vea todos los materiales y apruebe los quices de cada módulo.' : ''; ?>
                </p>
            <?php else: ?>
                <?php renderPreguntas($evaluacion_general, $preguntas_por_evaluacion, $opciones_por_pregunta); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ ENCUESTA DE SATISFACCIÓN ============ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clipboard-check me-2"></i>Encuesta de satisfacción</span>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEncuesta">
                <i class="bi bi-gear me-1"></i><?php echo $encuesta ? 'Editar encuesta' : 'Crear encuesta'; ?>
            </button>
        </div>
        <div class="card-body">
            <?php if (!$encuesta): ?>
                <p class="text-muted text-center py-3 mb-0">
                    Aún no hay encuesta de satisfacción para este curso. Si prefieres usar un formulario externo
                    (Google Forms, etc.), puedes seguir usando el campo "Link a encuesta" al editar el curso —
                    esta encuesta interna es opcional y, si la creas, tiene prioridad sobre el link externo.
                </p>
            <?php else: ?>
                <p class="fw-semibold"><?php echo htmlspecialchars($encuesta['titulo']); ?></p>
                <?php if ($encuesta['descripcion']): ?><p class="text-muted small"><?php echo htmlspecialchars($encuesta['descripcion']); ?></p><?php endif; ?>

                <button type="button" class="btn btn-sm btn-success mb-3" onclick="nuevaPreguntaEncuesta(<?php echo $encuesta['id']; ?>)">
                    <i class="bi bi-plus-circle me-1"></i>Agregar pregunta
                </button>

                <?php foreach ($preguntas_encuesta as $i => $p): ?>
                <div class="card mb-2">
                    <div class="card-body py-2 d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?php echo $i + 1; ?>. <?php echo htmlspecialchars($p['pregunta']); ?></strong>
                            <span class="badge bg-secondary ms-1"><?php echo $etiquetas_tipo_pregunta[$p['tipo']]; ?></span>
                            <?php if ($p['tipo'] === 'opcion_multiple'): ?>
                                <ul class="small text-muted mb-0 mt-1">
                                    <?php foreach (($opciones_encuesta_por_pregunta[$p['id']] ?? []) as $op): ?>
                                        <li><?php echo htmlspecialchars($op['texto']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='editarPreguntaEncuesta(<?php echo json_encode($p + ["opciones" => $opciones_encuesta_por_pregunta[$p['id']] ?? []]); ?>)'><i class="bi bi-pencil"></i></button>
                            <form method="POST" onsubmit="return confirm('¿Eliminar esta pregunta?');"><input type="hidden" name="accion" value="eliminar_pregunta_encuesta"><input type="hidden" name="id" value="<?php echo $p['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($preguntas_encuesta)): ?><p class="text-muted small">Sin preguntas todavía.</p><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Módulo -->
<div class="modal fade" id="modalModulo" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <div class="modal-header"><h5 class="modal-title" id="modalModuloTitle">Nuevo módulo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" id="accionModulo" value="crear_modulo"><input type="hidden" name="id" id="moduloId">
      <div class="mb-3"><label class="form-label">Título *</label><input type="text" class="form-control" name="titulo" id="tituloModulo" required></div>
      <div class="mb-3"><label class="form-label">Descripción</label><textarea class="form-control" name="descripcion" id="descripcionModulo" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
  </form>
</div></div></div>

<!-- Modal Material -->
<div class="modal fade" id="modalMaterial" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST" enctype="multipart/form-data">
    <div class="modal-header"><h5 class="modal-title">Nuevo material</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="crear_material"><input type="hidden" name="modulo_id" id="materialModuloId">
      <div class="mb-3"><label class="form-label">Tipo *</label><select class="form-select" name="tipo" required><option value="video">Video (mp4/webm)</option><option value="pdf">PDF</option><option value="imagen">Imagen</option></select></div>
      <div class="mb-3"><label class="form-label">Título *</label><input type="text" class="form-control" name="titulo" required></div>
      <div class="mb-3"><label class="form-label">Descripción</label><textarea class="form-control" name="descripcion" rows="2"></textarea></div>
      <div class="mb-3"><label class="form-label">Archivo *</label><input type="file" class="form-control" name="archivo" required></div>
      <div class="mb-3"><label class="form-label">Tiempo mínimo de visualización (segundos)</label><input type="number" class="form-control" name="tiempo_minimo" value="0" min="0"></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="solo_visualizacion" id="soloVisualizacion" checked><label class="form-check-label" for="soloVisualizacion">Solo visualización (no permitir descarga)</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Subir material</button></div>
  </form>
</div></div></div>

<!-- Modal Evaluación (sirve tanto para quiz de módulo como para la general) -->
<div class="modal fade" id="modalEvaluacion" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <div class="modal-header"><h5 class="modal-title" id="modalEvaluacionTitle">Configurar evaluación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="guardar_evaluacion">
      <input type="hidden" name="modulo_id" id="evalModuloId">
      <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" class="form-control" name="nombre" id="evalNombre" required></div>
      <div class="mb-3"><label class="form-label">Descripción</label><textarea class="form-control" name="descripcion" id="evalDescripcion" rows="2"></textarea></div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Puntaje mínimo (%)</label><input type="number" class="form-control" name="puntaje_minimo" id="evalPuntajeMinimo" min="0" max="100" value="70"></div>
        <div class="col-md-6 mb-3"><label class="form-label">Intentos permitidos</label><input type="number" class="form-control" name="numero_intentos" id="evalNumeroIntentos" min="1" value="3"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
  </form>
</div></div></div>

<!-- Modal Pregunta de evaluación -->
<div class="modal fade" id="modalPregunta" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST" id="formPregunta">
    <div class="modal-header"><h5 class="modal-title" id="modalPreguntaTitle">Nueva pregunta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="guardar_pregunta">
      <input type="hidden" name="evaluacion_id" id="preguntaEvaluacionId"><input type="hidden" name="pregunta_id" id="preguntaId" value="">
      <div class="mb-3"><label class="form-label">Pregunta *</label><textarea class="form-control" name="pregunta" id="textoPregunta" rows="2" required></textarea></div>
      <div class="mb-3" style="max-width:150px;"><label class="form-label">Puntos</label><input type="number" class="form-control" name="puntos" id="puntosPregunta" value="10" min="1"></div>
      <label class="form-label">Opciones * <span class="text-muted small">(marca la correcta)</span></label>
      <div id="opcionesContainer"></div>
      <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="agregarOpcion()"><i class="bi bi-plus-circle me-1"></i>Agregar opción</button>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar pregunta</button></div>
  </form>
</div></div></div>

<!-- Modal Encuesta (configuración) -->
<div class="modal fade" id="modalEncuesta" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <div class="modal-header"><h5 class="modal-title">Configurar encuesta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="guardar_encuesta">
      <div class="mb-3"><label class="form-label">Título *</label><input type="text" class="form-control" name="titulo" required value="<?php echo htmlspecialchars($encuesta['titulo'] ?? 'Encuesta de satisfacción - ' . $curso['nombre']); ?>"></div>
      <div class="mb-3"><label class="form-label">Descripción</label><textarea class="form-control" name="descripcion" rows="2"><?php echo htmlspecialchars($encuesta['descripcion'] ?? ''); ?></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
  </form>
</div></div></div>

<!-- Modal Pregunta de encuesta -->
<div class="modal fade" id="modalPreguntaEncuesta" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST" id="formPreguntaEncuesta">
    <div class="modal-header"><h5 class="modal-title" id="modalPreguntaEncuestaTitle">Nueva pregunta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="guardar_pregunta_encuesta">
      <input type="hidden" name="encuesta_id" id="pregEncEncuestaId"><input type="hidden" name="pregunta_id" id="pregEncId" value="">
      <div class="mb-3"><label class="form-label">Pregunta *</label><textarea class="form-control" name="pregunta" id="pregEncTexto" rows="2" required></textarea></div>
      <div class="mb-3">
        <label class="form-label">Tipo de respuesta *</label>
        <select class="form-select" name="tipo" id="pregEncTipo" onchange="alternarOpcionesEncuesta()">
          <option value="escala_1_5">Escala 1 a 5</option>
          <option value="opcion_multiple">Opción múltiple</option>
          <option value="texto_libre">Texto libre</option>
        </select>
      </div>
      <div id="bloqueOpcionesEncuesta" class="d-none">
        <label class="form-label">Opciones de respuesta</label>
        <div id="opcionesEncuestaContainer"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="agregarOpcionEncuesta()"><i class="bi bi-plus-circle me-1"></i>Agregar opción</button>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar pregunta</button></div>
  </form>
</div></div></div>

<script>
// ---------- Módulos ----------
function nuevoModulo() {
    document.getElementById('modalModuloTitle').textContent = 'Nuevo módulo';
    document.getElementById('accionModulo').value = 'crear_modulo';
    document.getElementById('moduloId').value = '';
    document.getElementById('tituloModulo').value = '';
    document.getElementById('descripcionModulo').value = '';
}
function editarModulo(modulo) {
    document.getElementById('modalModuloTitle').textContent = 'Editar módulo';
    document.getElementById('accionModulo').value = 'editar_modulo';
    document.getElementById('moduloId').value = modulo.id;
    document.getElementById('tituloModulo').value = modulo.titulo;
    document.getElementById('descripcionModulo').value = modulo.descripcion || '';
    new bootstrap.Modal(document.getElementById('modalModulo')).show();
}
function nuevoMaterial(moduloId) {
    document.getElementById('materialModuloId').value = moduloId;
    new bootstrap.Modal(document.getElementById('modalMaterial')).show();
}

// ---------- Evaluación (módulo o general) ----------
function abrirModalEvaluacion(moduloId, evaluacion, tituloModulo) {
    document.getElementById('modalEvaluacionTitle').textContent = tituloModulo ? ('Quiz del módulo: ' + tituloModulo) : 'Evaluación general del curso';
    document.getElementById('evalModuloId').value = moduloId || '';
    document.getElementById('evalNombre').value = evaluacion ? evaluacion.nombre : (tituloModulo ? ('Quiz: ' + tituloModulo) : 'Evaluación general');
    document.getElementById('evalDescripcion').value = evaluacion ? (evaluacion.descripcion || '') : '';
    document.getElementById('evalPuntajeMinimo').value = evaluacion ? evaluacion.puntaje_minimo : 70;
    document.getElementById('evalNumeroIntentos').value = evaluacion ? evaluacion.numero_intentos : 3;
    new bootstrap.Modal(document.getElementById('modalEvaluacion')).show();
}

// ---------- Preguntas de evaluación ----------
let contadorOpciones = 0;
function filaOpcion(texto, esCorrecta) {
    const idx = contadorOpciones++;
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `<div class="input-group-text"><input class="form-check-input mt-0" type="radio" name="correcta" value="${idx}" ${esCorrecta ? 'checked' : ''}></div>
        <input type="text" class="form-control" name="opciones_texto[]" value="${texto.replace(/"/g, '&quot;')}" placeholder="Texto de la opción">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-x"></i></button>`;
    return div;
}
function agregarOpcion(texto = '', esCorrecta = false) { document.getElementById('opcionesContainer').appendChild(filaOpcion(texto, esCorrecta)); }
function nuevaPregunta(evaluacionId) {
    document.getElementById('modalPreguntaTitle').textContent = 'Nueva pregunta';
    document.getElementById('preguntaEvaluacionId').value = evaluacionId;
    document.getElementById('preguntaId').value = '';
    document.getElementById('textoPregunta').value = '';
    document.getElementById('puntosPregunta').value = 10;
    document.getElementById('opcionesContainer').innerHTML = ''; contadorOpciones = 0;
    agregarOpcion(); agregarOpcion(); agregarOpcion(); agregarOpcion();
    new bootstrap.Modal(document.getElementById('modalPregunta')).show();
}
function editarPregunta(pregunta) {
    document.getElementById('modalPreguntaTitle').textContent = 'Editar pregunta';
    document.getElementById('preguntaEvaluacionId').value = pregunta.evaluacion_id;
    document.getElementById('preguntaId').value = pregunta.id;
    document.getElementById('textoPregunta').value = pregunta.pregunta;
    document.getElementById('puntosPregunta').value = pregunta.puntos;
    document.getElementById('opcionesContainer').innerHTML = ''; contadorOpciones = 0;
    (pregunta.opciones || []).forEach(op => agregarOpcion(op.texto, op.es_correcta == 1));
    new bootstrap.Modal(document.getElementById('modalPregunta')).show();
}
document.getElementById('formPregunta').addEventListener('submit', function (e) {
    const marcada = document.querySelector('input[name="correcta"]:checked');
    const opcionesLlenas = document.querySelectorAll('input[name="opciones_texto[]"]').length;
    if (!marcada) { e.preventDefault(); alert('Marca cuál opción es la correcta.'); }
    else if (opcionesLlenas < 2) { e.preventDefault(); alert('Agrega al menos 2 opciones.'); }
});

// ---------- Preguntas de encuesta ----------
let contadorOpcionesEncuesta = 0;
function filaOpcionEncuesta(texto) {
    const idx = contadorOpcionesEncuesta++;
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `<input type="text" class="form-control" name="opciones_texto[]" value="${texto.replace(/"/g, '&quot;')}" placeholder="Texto de la opción">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="bi bi-x"></i></button>`;
    return div;
}
function agregarOpcionEncuesta(texto = '') { document.getElementById('opcionesEncuestaContainer').appendChild(filaOpcionEncuesta(texto)); }
function alternarOpcionesEncuesta() {
    document.getElementById('bloqueOpcionesEncuesta').classList.toggle('d-none', document.getElementById('pregEncTipo').value !== 'opcion_multiple');
}
function nuevaPreguntaEncuesta(encuestaId) {
    document.getElementById('modalPreguntaEncuestaTitle').textContent = 'Nueva pregunta';
    document.getElementById('pregEncEncuestaId').value = encuestaId;
    document.getElementById('pregEncId').value = '';
    document.getElementById('pregEncTexto').value = '';
    document.getElementById('pregEncTipo').value = 'escala_1_5';
    document.getElementById('opcionesEncuestaContainer').innerHTML = ''; contadorOpcionesEncuesta = 0;
    alternarOpcionesEncuesta();
    new bootstrap.Modal(document.getElementById('modalPreguntaEncuesta')).show();
}
function editarPreguntaEncuesta(pregunta) {
    document.getElementById('modalPreguntaEncuestaTitle').textContent = 'Editar pregunta';
    document.getElementById('pregEncEncuestaId').value = pregunta.encuesta_id;
    document.getElementById('pregEncId').value = pregunta.id;
    document.getElementById('pregEncTexto').value = pregunta.pregunta;
    document.getElementById('pregEncTipo').value = pregunta.tipo;
    document.getElementById('opcionesEncuestaContainer').innerHTML = ''; contadorOpcionesEncuesta = 0;
    (pregunta.opciones || []).forEach(op => agregarOpcionEncuesta(op.texto));
    alternarOpcionesEncuesta();
    new bootstrap.Modal(document.getElementById('modalPreguntaEncuesta')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
