<?php
require_once '../config/config.php';
require_once '../includes/formacion_mailer.php';
requerirPermiso('ver_formacion');

$pdo = getDBConnection();
$usuario_id = (int)$_SESSION['usuario_id'];

$stmt = $pdo->prepare("SELECT nombre_completo, dependencia_id FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario_actual = $stmt->fetch();
$dependencia_usuario = (int)($usuario_actual['dependencia_id'] ?? 0);
$nombre_usuario = $usuario_actual['nombre_completo'] ?? '';

// ==========================================================
// Auto-inscripción a curso voluntario (antes del header para poder redirigir)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'inscribirse') {
    $curso_id_inscribir = (int)$_POST['curso_id'];

    $stmt = $pdo->prepare("
        SELECT fc.id
        FROM formacion_cursos fc
        JOIN formacion_curso_dependencias fcd ON fcd.curso_id = fc.id
        WHERE fc.id = ? AND fc.activo = 1 AND fc.tipo_inscripcion = 'voluntaria'
          AND fcd.dependencia_id = ?
    ");
    $stmt->execute([$curso_id_inscribir, $dependencia_usuario]);

    if ($stmt->fetch()) {
        $pdo->prepare("INSERT IGNORE INTO formacion_inscripciones (usuario_id, curso_id) VALUES (?, ?)")
            ->execute([$usuario_id, $curso_id_inscribir]);
        registrarLog($usuario_id, 'Inscripción voluntaria a formación', 'Formación', "Curso ID: $curso_id_inscribir");

        $stmtInsc = $pdo->prepare("SELECT id FROM formacion_inscripciones WHERE usuario_id = ? AND curso_id = ?");
        $stmtInsc->execute([$usuario_id, $curso_id_inscribir]);
        $nueva_inscripcion_id = $stmtInsc->fetchColumn();
        if ($nueva_inscripcion_id) {
            enviarCorreoInicioFormacion($pdo, (int)$nueva_inscripcion_id);
        }
    }

    $qs = array_filter(['entidad' => $_GET['entidad'] ?? '', 'filtro' => $_GET['filtro'] ?? '']);
    header('Location: index.php' . (!empty($qs) ? '?' . http_build_query($qs) : ''));
    exit;
}

$page_title = 'Formación';
$additional_css = ['assets/css/formacion.css'];
require_once '../includes/header.php';

$entidad_actual = $_GET['entidad'] ?? '';
$filtro = $_GET['filtro'] ?? 'todos';

$etiquetas_entidad = ['osf' => 'OSF', 'sena' => 'SENA', 'comfaboy' => 'Comfaboy', 'positiva' => 'Positiva', 'otro' => 'Otro'];
$iconos_entidad = ['osf' => 'bi-building', 'sena' => 'bi-mortarboard', 'comfaboy' => 'bi-briefcase', 'positiva' => 'bi-heart-pulse', 'otro' => 'bi-three-dots'];
$badges_entidad = ['osf' => 'primary', 'sena' => 'success', 'comfaboy' => 'info', 'positiva' => 'warning', 'otro' => 'secondary'];

// ==========================================================
// Cursos en los que ya estoy inscrito
// ==========================================================
$stmt = $pdo->prepare("
    SELECT fc.*, fi.id AS inscripcion_id, fi.progreso, fi.completado, fi.fecha_completado
    FROM formacion_inscripciones fi
    JOIN formacion_cursos fc ON fc.id = fi.curso_id
    WHERE fi.usuario_id = ? AND fc.activo = 1
    ORDER BY fc.obligatorio DESC, (fc.fecha_cierre IS NULL), fc.fecha_cierre ASC
");
$stmt->execute([$usuario_id]);
$todos_cursos = $stmt->fetchAll();

// ==========================================================
// Cursos voluntarios disponibles (dirigidos a mi dependencia, sin inscribirme aún)
// ==========================================================
$disponibles = [];
if ($dependencia_usuario) {
    $stmt = $pdo->prepare("
        SELECT fc.*
        FROM formacion_cursos fc
        JOIN formacion_curso_dependencias fcd ON fcd.curso_id = fc.id
        WHERE fc.activo = 1 AND fc.tipo_inscripcion = 'voluntaria' AND fcd.dependencia_id = ?
          AND fc.id NOT IN (SELECT curso_id FROM formacion_inscripciones WHERE usuario_id = ?)
        ORDER BY fc.obligatorio DESC, fc.fecha_creacion DESC
    ");
    $stmt->execute([$dependencia_usuario, $usuario_id]);
    $disponibles = $stmt->fetchAll();
}

// ==========================================================
// Calcular estado visual + conteos por entidad (para el menú lateral)
// ==========================================================
$hoy = new DateTime();
$conteo_por_entidad = array_fill_keys(array_keys($etiquetas_entidad), 0);
$total_obligatorios_pendientes = 0;

foreach ($todos_cursos as &$c) {
    $estado = 'pendiente';
    $dias_restantes = null;

    if ($c['completado']) {
        $estado = 'completado';
    } elseif ($c['fecha_cierre']) {
        $cierre = new DateTime($c['fecha_cierre']);
        $dias_restantes = (int)$hoy->diff($cierre)->format('%r%a');
        if ($dias_restantes < 0) { $estado = 'vencido'; }
        elseif ($dias_restantes <= 3) { $estado = 'por_vencer'; }
        elseif ($c['progreso'] > 0) { $estado = 'en_curso'; }
    } elseif ($c['progreso'] > 0) {
        $estado = 'en_curso';
    }
    $c['estado_visual'] = $estado;
    $c['dias_restantes'] = $dias_restantes;

    if (!$c['completado'] && $c['obligatorio']) $total_obligatorios_pendientes++;
    $conteo_por_entidad[$c['tipo_entidad']] = ($conteo_por_entidad[$c['tipo_entidad']] ?? 0) + 1;
}
unset($c);

foreach ($disponibles as $d) {
    $conteo_por_entidad[$d['tipo_entidad']] = ($conteo_por_entidad[$d['tipo_entidad']] ?? 0) + 1;
}
$total_general = array_sum($conteo_por_entidad);

// ==========================================================
// Aplicar filtros de la vista actual (entidad + estado)
// ==========================================================
function pasaFiltroEstado(array $c, string $filtro): bool {
    return match ($filtro) {
        'pendientes' => !$c['completado'],
        'completados' => (bool)$c['completado'],
        'obligatorios' => (bool)$c['obligatorio'],
        default => true,
    };
}

$cursos_mostrar = [];
$disponibles_mostrar = [];
if ($entidad_actual !== '') {
    foreach ($todos_cursos as $c) {
        if ($c['tipo_entidad'] === $entidad_actual && pasaFiltroEstado($c, $filtro)) {
            $cursos_mostrar[] = $c;
        }
    }
    foreach ($disponibles as $d) {
        if ($d['tipo_entidad'] === $entidad_actual) {
            $disponibles_mostrar[] = $d;
        }
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row g-4">
        <!-- ============ MENÚ LATERAL ============ -->
        <div class="col-12 col-md-3">
            <div class="card shadow-sm formacion-sidebar">
                <div class="list-group list-group-flush">
                    <a href="index.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $entidad_actual === '' ? 'active' : ''; ?>">
                        <span><i class="bi bi-house-door me-2"></i>Inicio</span>
                    </a>
                    <?php foreach ($etiquetas_entidad as $val => $label): ?>
                        <a href="?entidad=<?php echo $val; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $entidad_actual === $val ? 'active' : ''; ?>">
                            <span><i class="bi <?php echo $iconos_entidad[$val]; ?> me-2"></i><?php echo $label; ?></span>
                            <span class="badge <?php echo $entidad_actual === $val ? 'bg-light text-dark' : 'bg-' . $badges_entidad[$val]; ?> rounded-pill"><?php echo $conteo_por_entidad[$val]; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($total_obligatorios_pendientes > 0): ?>
                <div class="alert alert-danger mt-3 small mb-0">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    Tienes <strong><?php echo $total_obligatorios_pendientes; ?></strong> curso(s) obligatorio(s) pendiente(s).
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ CONTENIDO PRINCIPAL ============ -->
        <div class="col-12 col-md-9">
            <?php if ($entidad_actual === ''): ?>
                <!-- ---------- BIENVENIDA ---------- -->
                <div class="card shadow-sm formacion-bienvenida mb-4">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-mortarboard-fill text-primary" style="font-size:2.8rem;"></i>
                        <h2 class="mt-3">¡Bienvenido<?php echo $nombre_usuario ? ', ' . htmlspecialchars(explode(' ', $nombre_usuario)[0]) : ''; ?>!</h2>
                        <p class="text-muted mb-0">
                            Este es tu espacio de formación y capacitación. Selecciona una entidad en el menú de la
                            izquierda para ver los cursos disponibles según tu dependencia.
                        </p>
                    </div>
                </div>

                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <div class="card shadow-sm py-3">
                            <div class="fs-3 fw-bold"><?php echo $total_general; ?></div>
                            <div class="small text-muted">Cursos disponibles</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card shadow-sm py-3">
                            <div class="fs-3 fw-bold text-danger"><?php echo $total_obligatorios_pendientes; ?></div>
                            <div class="small text-muted">Obligatorios pendientes</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card shadow-sm py-3">
                            <div class="fs-3 fw-bold text-success"><?php echo count(array_filter($todos_cursos, fn($c) => $c['completado'])); ?></div>
                            <div class="small text-muted">Completados</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card shadow-sm py-3">
                            <div class="fs-3 fw-bold text-primary"><?php echo count($disponibles); ?></div>
                            <div class="small text-muted">Para inscribirte</div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- ---------- LISTADO DE CURSOS DE LA ENTIDAD ---------- -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h4 class="mb-0"><i class="bi <?php echo $iconos_entidad[$entidad_actual]; ?> me-2"></i><?php echo $etiquetas_entidad[$entidad_actual]; ?></h4>
                    <div class="btn-group">
                        <?php foreach (['todos' => 'Todos', 'pendientes' => 'Pendientes', 'obligatorios' => 'Obligatorios', 'completados' => 'Completados'] as $val => $label): ?>
                            <a href="?entidad=<?php echo $entidad_actual; ?>&filtro=<?php echo $val; ?>" class="btn btn-sm <?php echo $filtro === $val ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $label; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($disponibles_mostrar)): ?>
                <div class="card border-primary mb-4">
                    <div class="card-header bg-primary-subtle"><i class="bi bi-plus-circle me-2"></i>Disponibles para inscribirte</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($disponibles_mostrar as $d): ?>
                            <div class="col-12 col-sm-6 col-lg-4">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <?php if ($d['obligatorio']): ?><span class="badge bg-dark mb-2">Obligatorio</span><?php endif; ?>
                                        <h6 class="card-title"><?php echo htmlspecialchars($d['nombre']); ?></h6>
                                        <p class="small text-muted"><?php echo htmlspecialchars(mb_strimwidth($d['descripcion'] ?? '', 0, 90, '...')); ?></p>
                                        <form method="POST">
                                            <input type="hidden" name="accion" value="inscribirse">
                                            <input type="hidden" name="curso_id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-pencil-square me-1"></i>Inscribirme</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3">
                    <?php foreach ($cursos_mostrar as $c): ?>
                    <div class="col-12 col-sm-6 col-lg-4">
                        <a href="detalle_curso.php?id=<?php echo $c['id']; ?>" class="text-decoration-none">
                            <div class="card h-100 shadow-sm formacion-card">
                                <div class="formacion-card-cover
                                    <?php echo $c['estado_visual'] === 'completado' ? 'bg-success-subtle' : ($c['estado_visual'] === 'vencido' ? 'bg-danger-subtle' : ($c['estado_visual'] === 'por_vencer' ? 'bg-warning-subtle' : 'bg-light')); ?>"
                                    <?php if ($c['imagen']): ?>style="background-image:url('../uploads/<?php echo htmlspecialchars($c['imagen']); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
                                </div>
                                <div class="card-body">
                                    <?php if ($c['obligatorio']): ?><span class="badge bg-dark mb-1">Obligatorio</span><?php endif; ?>
                                    <h6 class="card-title mb-1"><?php echo htmlspecialchars($c['nombre']); ?></h6>

                                    <?php if ($c['estado_visual'] === 'completado'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Completado</span>
                                    <?php elseif ($c['estado_visual'] === 'vencido'): ?>
                                        <span class="badge bg-danger">Vencido</span>
                                    <?php elseif ($c['estado_visual'] === 'por_vencer'): ?>
                                        <span class="badge bg-warning text-dark">Vence en <?php echo max(0, $c['dias_restantes']); ?> día(s)</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo $c['progreso'] > 0 ? 'En curso' : 'Sin iniciar'; ?></span>
                                    <?php endif; ?>

                                    <div class="progress mt-2" style="height:5px;">
                                        <div class="progress-bar <?php echo $c['completado'] ? 'bg-success' : 'bg-primary'; ?>" style="width:<?php echo (float)$c['progreso']; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($cursos_mostrar) && empty($disponibles_mostrar)): ?>
                        <div class="col-12"><p class="text-muted text-center py-5">No hay cursos de <?php echo $etiquetas_entidad[$entidad_actual]; ?> para tu dependencia.</p></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.formacion-sidebar .list-group-item { border-left: none; border-right: none; cursor: pointer; }
.formacion-sidebar .list-group-item.active { background-color: #0d6efd; border-color: #0d6efd; }
.formacion-bienvenida { background: linear-gradient(135deg, #f5f8ff 0%, #ffffff 100%); }
.formacion-card { transition: transform .15s ease, box-shadow .15s ease; }
.formacion-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }
.formacion-card-cover { height: 100px; border-radius: .375rem .375rem 0 0; }
</style>

<?php require_once '../includes/footer.php'; ?>
