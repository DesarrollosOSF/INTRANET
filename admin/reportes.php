<?php
require_once '../config/config.php';
requerirPermiso('ver_reportes');

$page_title = 'Reportes y Estadísticas';
$additional_css = ['assets/css/admin.css'];

$pdo = getDBConnection();

// Endpoint AJAX para detalle de preguntas por intento
if (isset($_GET['ajax_detalle_intento'])) {
    header('Content-Type: application/json; charset=utf-8');
    $intento_id = isset($_GET['intento_id']) ? (int)$_GET['intento_id'] : 0;
    if ($intento_id <= 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Intento inválido']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            p.id as pregunta_id,
            p.pregunta,
            ru_ult.opcion_id as respuesta_opcion_id,
            ou.texto as respuesta_usuario,
            COALESCE(ou.es_correcta, 0) as correcta
        FROM intentos_evaluacion ie
        INNER JOIN preguntas p ON p.evaluacion_id = ie.evaluacion_id
        LEFT JOIN (
            SELECT ru1.intento_id, ru1.pregunta_id, ru1.opcion_id
            FROM respuestas_usuario ru1
            INNER JOIN (
                SELECT intento_id, pregunta_id, MAX(id) AS max_id
                FROM respuestas_usuario
                WHERE intento_id = ?
                GROUP BY intento_id, pregunta_id
            ) ru2 ON ru1.id = ru2.max_id
        ) ru_ult ON ru_ult.intento_id = ie.id AND ru_ult.pregunta_id = p.id
        LEFT JOIN opciones_respuesta ou ON ou.id = ru_ult.opcion_id
        WHERE ie.id = ?
        ORDER BY p.orden ASC, p.id ASC
    ");
    $stmt->execute([$intento_id, $intento_id]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        echo json_encode(['ok' => false, 'mensaje' => 'No se encontró detalle de preguntas para este intento']);
        exit;
    }

    $detalle = [];
    foreach ($rows as $row) {
        $es_correcta = (int)$row['correcta'] === 1;
        $detalle[] = [
            'pregunta' => (string)$row['pregunta'],
            'respuesta_usuario' => $row['respuesta_usuario'] !== null && $row['respuesta_usuario'] !== '' ? (string)$row['respuesta_usuario'] : 'Sin respuesta',
            'correcta' => $es_correcta,
            'resultado_texto' => $es_correcta ? '1 de 1' : '0 de 1'
        ];
    }

    echo json_encode(['ok' => true, 'detalle_preguntas' => $detalle]);
    exit;
}

// Filtros
$curso_id = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;
$dependencia_id = isset($_GET['dependencia_id']) ? (int)$_GET['dependencia_id'] : 0;
$usuarios_por_pagina = 20;
$pagina_detalle = isset($_GET['pagina_detalle']) ? max(1, (int)$_GET['pagina_detalle']) : 1;

// Obtener cursos
$stmt = $pdo->query("SELECT * FROM cursos WHERE activo = 1 ORDER BY nombre");
$cursos = $stmt->fetchAll();

// Obtener dependencias
$stmt = $pdo->query("SELECT * FROM dependencias WHERE activo = 1 ORDER BY nombre");
$dependencias = $stmt->fetchAll();

// Estadísticas generales
$stats = [];

// Total de usuarios
$stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1");
$stats['usuarios'] = $stmt->fetch()['total'];

// Total de cursos
$stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE activo = 1");
$stats['cursos'] = $stmt->fetch()['total'];

// Total de inscripciones
$stmt = $pdo->query("SELECT COUNT(*) as total FROM inscripciones");
$stats['inscripciones'] = $stmt->fetch()['total'];

// Cursos completados
$stmt = $pdo->query("SELECT COUNT(*) as total FROM inscripciones WHERE completado = 1");
$stats['completados'] = $stmt->fetch()['total'];

// Reporte por curso
$reporte_curso = [];
if ($curso_id) {
    $stmt = $pdo->prepare("
        SELECT 
            c.nombre as curso_nombre,
            COUNT(DISTINCT i.id) as total_inscritos,
            COUNT(DISTINCT CASE WHEN i.completado = 1 THEN i.id END) as completados,
            COUNT(DISTINCT ie.id) as total_intentos,
            COUNT(DISTINCT CASE WHEN ie.estado = 'aprobado' THEN i.id END) as aprobados,
            COUNT(DISTINCT CASE WHEN ie.id IS NOT NULL THEN i.id END) as inscritos_con_intento,
            AVG(CASE WHEN ie.estado != 'en_proceso' THEN (ie.puntaje_obtenido / ie.puntaje_total * 100) END) as promedio_puntaje
        FROM cursos c
        LEFT JOIN inscripciones i ON c.id = i.curso_id
        LEFT JOIN evaluaciones e ON c.id = e.curso_id
        LEFT JOIN intentos_evaluacion ie ON e.id = ie.evaluacion_id AND i.id = ie.inscripcion_id
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$curso_id]);
    $reporte_curso = $stmt->fetch();
    // Reprobados = inscritos que presentaron al menos un intento pero nunca aprobaron
    $reporte_curso['reprobados'] = max(0, (int)$reporte_curso['inscritos_con_intento'] - (int)$reporte_curso['aprobados']);
    
    // Paginación del detalle por usuario (5 por página)
    $total_usuarios_detalle = (int)$reporte_curso['total_inscritos'];
    $total_paginas_detalle = $total_usuarios_detalle > 0 ? (int)ceil($total_usuarios_detalle / $usuarios_por_pagina) : 1;
    $pagina_detalle = min(max(1, $pagina_detalle), $total_paginas_detalle);
    $offset_detalle = ($pagina_detalle - 1) * $usuarios_por_pagina;
    
    // Detalle de usuarios por curso (con LIMIT y OFFSET)
    $stmt = $pdo->prepare("
        SELECT 
            i.id as inscripcion_id,
            u.nombre_completo,
            u.email,
            i.fecha_inscripcion,
            i.progreso,
            i.completado,
            i.fecha_completado,
            COUNT(ie.id) as intentos,
            MAX(CASE WHEN ie.estado = 'aprobado' THEN ie.puntaje_obtenido / ie.puntaje_total * 100 END) as mejor_puntaje,
            MAX(ie.puntaje_obtenido / ie.puntaje_total * 100) as mejor_puntaje_cualquiera,
            MAX(CASE WHEN ie.estado = 'aprobado' THEN ie.estado END) as estado_final
        FROM inscripciones i
        INNER JOIN usuarios u ON i.usuario_id = u.id
        LEFT JOIN evaluaciones e ON i.curso_id = e.curso_id
        LEFT JOIN intentos_evaluacion ie ON e.id = ie.evaluacion_id AND i.id = ie.inscripcion_id
        WHERE i.curso_id = ?
        GROUP BY i.id, u.id
        ORDER BY i.fecha_inscripcion DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$curso_id, $usuarios_por_pagina, $offset_detalle]);
    $detalle_usuarios = $stmt->fetchAll();

    foreach ($detalle_usuarios as &$detalle_usuario) {
        $stmt_intento = $pdo->prepare("
            SELECT ie.id
            FROM intentos_evaluacion ie
            INNER JOIN evaluaciones e ON e.id = ie.evaluacion_id
            WHERE ie.inscripcion_id = ? AND e.curso_id = ? AND ie.estado <> 'en_proceso'
            ORDER BY ie.fecha_finalizacion DESC, ie.id DESC
            LIMIT 1
        ");
        $stmt_intento->execute([(int)$detalle_usuario['inscripcion_id'], $curso_id]);
        $detalle_usuario['ultimo_intento_id'] = (int)$stmt_intento->fetchColumn();
    }
    unset($detalle_usuario);
}

// Reporte por dependencia
$reporte_dependencia = [];
if ($dependencia_id) {
    $stmt = $pdo->prepare("
        SELECT 
            d.nombre as dependencia_nombre,
            COUNT(DISTINCT u.id) as total_usuarios,
            COUNT(DISTINCT i.id) as total_inscripciones,
            COUNT(DISTINCT CASE WHEN i.completado = 1 THEN i.id END) as cursos_completados
        FROM dependencias d
        LEFT JOIN usuarios u ON d.id = u.dependencia_id AND u.activo = 1
        LEFT JOIN inscripciones i ON u.id = i.usuario_id
        WHERE d.id = ?
        GROUP BY d.id
    ");
    $stmt->execute([$dependencia_id]);
    $reporte_dependencia = $stmt->fetch();
}

require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-4"><i class="bi bi-graph-up me-2"></i>Reportes y Estadísticas</h2>
    
    <!-- Estadísticas generales -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="bi bi-people fs-1 text-primary"></i>
                    <h3 class="mt-2"><?php echo $stats['usuarios']; ?></h3>
                    <p class="text-muted mb-0">Usuarios Activos</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="bi bi-book fs-1 text-success"></i>
                    <h3 class="mt-2"><?php echo $stats['cursos']; ?></h3>
                    <p class="text-muted mb-0">Cursos Disponibles</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="bi bi-person-check fs-1 text-info"></i>
                    <h3 class="mt-2"><?php echo $stats['inscripciones']; ?></h3>
                    <p class="text-muted mb-0">Inscripciones</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <i class="bi bi-check-circle fs-1 text-warning"></i>
                    <h3 class="mt-2"><?php echo $stats['completados']; ?></h3>
                    <p class="text-muted mb-0">Cursos Completados</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filtros de Reporte</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Curso</label>
                    <select class="form-select" name="curso_id">
                        <option value="0">Todos los cursos</option>
                        <?php foreach ($cursos as $curso): ?>
                            <option value="<?php echo $curso['id']; ?>" <?php echo $curso_id == $curso['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curso['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Dependencia</label>
                    <select class="form-select" name="dependencia_id">
                        <option value="0">Todas las dependencias</option>
                        <?php foreach ($dependencias as $dep): ?>
                            <option value="<?php echo $dep['id']; ?>" <?php echo $dependencia_id == $dep['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dep['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reporte por curso -->
    <?php if ($curso_id && $reporte_curso): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Reporte del Curso: <?php echo htmlspecialchars($reporte_curso['curso_nombre']); ?></h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4><?php echo $reporte_curso['total_inscritos']; ?></h4>
                            <p class="text-muted mb-0">Inscritos</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4><?php echo $reporte_curso['completados']; ?></h4>
                            <p class="text-muted mb-0">Completados</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4><?php echo $reporte_curso['aprobados']; ?></h4>
                            <p class="text-muted mb-0">Aprobados</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4><?php echo $reporte_curso['reprobados']; ?></h4>
                            <p class="text-muted mb-0">Reprobados</p>
                        </div>
                    </div>
                </div>
                
                <?php if ($reporte_curso['promedio_puntaje']): ?>
                    <div class="alert alert-info">
                        <strong>Promedio de Puntaje:</strong> <?php echo number_format($reporte_curso['promedio_puntaje'], 2); ?>%
                    </div>
                <?php endif; ?>
                
                <h6 class="mb-3">Detalle por Usuario</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Progreso</th>
                                <th>Estado</th>
                                <th>Intentos</th>
                                <th>Mejor Puntaje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detalle_usuarios as $detalle): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($detalle['nombre_completo']); ?></td>
                                    <td><?php echo htmlspecialchars($detalle['email']); ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" style="width: <?php echo $detalle['progreso']; ?>%">
                                                <?php echo number_format($detalle['progreso'], 1); ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($detalle['estado_final']) && $detalle['estado_final'] === 'aprobado'): ?>
                                            <span class="badge bg-success">Aprobado</span>
                                        <?php elseif ((int)$detalle['intentos'] > 0): ?>
                                            <span class="badge bg-danger">Reprobado</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Sin evaluar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $detalle['intentos']; ?></td>
                                    <td>
                                        <?php
                                        $es_aprobado = !empty($detalle['estado_final']) && $detalle['estado_final'] === 'aprobado';
                                        $puntaje = $es_aprobado ? $detalle['mejor_puntaje'] : $detalle['mejor_puntaje_cualquiera'];
                                        $ultimo_intento_id = isset($detalle['ultimo_intento_id']) ? (int)$detalle['ultimo_intento_id'] : 0;
                                        if ($puntaje !== null && $puntaje !== ''): ?>
                                            <button type="button"
                                                    class="badge bg-<?php echo $es_aprobado ? 'success' : 'danger'; ?> border-0 btn-ver-detalle-puntaje"
                                                    data-intento-id="<?php echo $ultimo_intento_id; ?>"
                                                    title="Ver detalle del último intento">
                                                <?php echo number_format((float)$puntaje, 1); ?>%
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_paginas_detalle > 1): 
                    $url_base = '?curso_id=' . $curso_id . '&dependencia_id=' . $dependencia_id;
                ?>
                    <nav class="mt-3" aria-label="Paginación detalle por usuario">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?php echo $pagina_detalle <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $url_base . '&pagina_detalle=' . ($pagina_detalle - 1); ?>">Anterior</a>
                            </li>
                            <?php for ($p = 1; $p <= $total_paginas_detalle; $p++): ?>
                                <li class="page-item <?php echo $p === $pagina_detalle ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo $url_base . '&pagina_detalle=' . $p; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $pagina_detalle >= $total_paginas_detalle ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $url_base . '&pagina_detalle=' . ($pagina_detalle + 1); ?>">Siguiente</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Reporte por dependencia -->
    <?php if ($dependencia_id && $reporte_dependencia): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Reporte de Dependencia: <?php echo htmlspecialchars($reporte_dependencia['dependencia_nombre']); ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center">
                            <h4><?php echo $reporte_dependencia['total_usuarios']; ?></h4>
                            <p class="text-muted mb-0">Usuarios</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <h4><?php echo $reporte_dependencia['total_inscripciones']; ?></h4>
                            <p class="text-muted mb-0">Inscripciones</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <h4><?php echo $reporte_dependencia['cursos_completados']; ?></h4>
                            <p class="text-muted mb-0">Cursos Completados</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalDetallePreguntas" tabindex="-1" aria-labelledby="modalDetallePreguntasLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalDetallePreguntasLabel">
                    <i class="bi bi-journal-check me-2"></i>Detalle de preguntas
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="contenidoDetallePreguntas">
                <div class="text-center text-muted">Selecciona un puntaje para ver el detalle.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<style>
.item-detalle-pregunta {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 12px;
}

.badge-resultado-pregunta {
    min-width: 70px;
    text-align: center;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var modalEl = document.getElementById('modalDetallePreguntas');
    var contenido = document.getElementById('contenidoDetallePreguntas');
    if (!modalEl || !contenido) return;

    var modal = (typeof bootstrap !== 'undefined' && bootstrap.Modal) ? new bootstrap.Modal(modalEl) : null;

    function escapeHtml(texto) {
        return String(texto || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderDetalle(detallePreguntas) {
        if (!Array.isArray(detallePreguntas) || detallePreguntas.length === 0) {
            contenido.innerHTML = '<div class="alert alert-info mb-0">No hay preguntas para mostrar.</div>';
            return;
        }
        var html = '';
        detallePreguntas.forEach(function(item, index) {
            var resultadoClass = item.correcta ? 'bg-success' : 'bg-danger';
            var resultadoTexto = item.resultado_texto || (item.correcta ? '1 de 1' : '0 de 1');
            html += ''
                + '<div class="item-detalle-pregunta">'
                + '  <div class="d-flex align-items-start justify-content-between gap-3">'
                + '    <span class="badge ' + resultadoClass + ' badge-resultado-pregunta">' + escapeHtml(resultadoTexto) + '</span>'
                + '    <div class="flex-grow-1">'
                + '      <p class="mb-2"><strong>Pregunta ' + (index + 1) + ':</strong> ' + escapeHtml(item.pregunta || '') + '</p>'
                + '      <p class="mb-0 text-muted"><strong>Respuesta del usuario:</strong> ' + escapeHtml(item.respuesta_usuario || 'Sin respuesta') + '</p>'
                + '    </div>'
                + '  </div>'
                + '</div>';
        });
        contenido.innerHTML = html;
    }

    document.querySelectorAll('.btn-ver-detalle-puntaje').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var intentoId = parseInt(this.getAttribute('data-intento-id') || '0', 10);
            if (!intentoId) {
                contenido.innerHTML = '<div class="alert alert-warning mb-0">No se encontró un intento para este usuario.</div>';
                if (modal) modal.show();
                return;
            }

            contenido.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Cargando detalle...</div>';
            if (modal) modal.show();

            var url = 'reportes.php?curso_id=<?php echo (int)$curso_id; ?>&dependencia_id=<?php echo (int)$dependencia_id; ?>&pagina_detalle=<?php echo (int)$pagina_detalle; ?>&ajax_detalle_intento=1&intento_id=' + intentoId;
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || data.ok !== true) {
                        contenido.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml((data && data.mensaje) ? data.mensaje : 'No fue posible cargar el detalle.') + '</div>';
                        return;
                    }
                    renderDetalle(data.detalle_preguntas || []);
                })
                .catch(function() {
                    contenido.innerHTML = '<div class="alert alert-danger mb-0">Error al cargar el detalle de preguntas.</div>';
                });
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
