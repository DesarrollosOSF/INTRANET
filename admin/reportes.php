<?php
require_once '../config/config.php';
requerirPermiso('ver_reportes');

$page_title = 'Reportes y Estadísticas';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

$pdo = getDBConnection();

// Filtros
$curso_id = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;
$dependencia_id = isset($_GET['dependencia_id']) ? (int)$_GET['dependencia_id'] : 0;
$usuarios_por_pagina = 3;
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
                                        if ($puntaje !== null && $puntaje !== ''): ?>
                                            <span class="badge bg-<?php echo $es_aprobado ? 'success' : 'danger'; ?>">
                                                <?php echo number_format((float)$puntaje, 1); ?>%
                                            </span>
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

<?php require_once '../includes/footer.php'; ?>
