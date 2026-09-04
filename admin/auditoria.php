<?php
require_once '../config/config.php';
requerirPermiso('ver_auditoria');
require_once '../includes/auditoria_helpers.php';

$pdo = getDBConnection();

$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
$fecha_desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$usuario_detalle_id = (int)($_GET['usuario_id'] ?? 0);

// ==========================================================
// Exportar CSV (antes de imprimir cualquier HTML)
// ==========================================================
if (($_GET['accion'] ?? '') === 'exportar_accesos') {
    $stmt = $pdo->prepare("
        SELECT la.fecha, u.nombre_completo, u.email, d.nombre AS dependencia, la.ip_address
        FROM logs_actividad la
        JOIN usuarios u ON u.id = la.usuario_id
        LEFT JOIN dependencias d ON d.id = u.dependencia_id
        WHERE la.accion = 'Inicio de sesión' AND la.fecha BETWEEN ? AND ?
        ORDER BY la.fecha DESC
    ");
    $stmt->execute([$fecha_desde . ' 00:00:00', $fecha_hasta . ' 23:59:59']);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="accesos_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Nombre', 'Email', 'Dependencia', 'IP'], ';');
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [$r['fecha'], $r['nombre_completo'], $r['email'], $r['dependencia'] ?? '', $r['ip_address']], ';');
    }
    fclose($out);
    exit;
}

if (($_GET['accion'] ?? '') === 'exportar_vistas') {
    $stmt = $pdo->prepare("
        SELECT vc.fecha, u.nombre_completo, u.email, d.nombre AS dependencia, vc.tipo_contenido, vc.contenido_id
        FROM vistas_contenido vc
        JOIN usuarios u ON u.id = vc.usuario_id
        LEFT JOIN dependencias d ON d.id = u.dependencia_id
        WHERE vc.fecha BETWEEN ? AND ?
        ORDER BY vc.fecha DESC
    ");
    $stmt->execute([$fecha_desde . ' 00:00:00', $fecha_hasta . ' 23:59:59']);
    $filas = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vistas_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Nombre', 'Email', 'Dependencia', 'Tipo', 'Contenido'], ';');
    foreach ($filas as $r) {
        $nombre_contenido = obtenerNombreContenido($pdo, $r['tipo_contenido'], (int)$r['contenido_id']);
        fputcsv($out, [$r['fecha'], $r['nombre_completo'], $r['email'], $r['dependencia'] ?? '', etiquetaTipoContenido($r['tipo_contenido']), $nombre_contenido], ';');
    }
    fclose($out);
    exit;
}

$page_title = 'Auditoría de accesos y visualizaciones';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

$desde_sql = $fecha_desde . ' 00:00:00';
$hasta_sql = $fecha_hasta . ' 23:59:59';

// ---------- KPIs ----------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM logs_actividad WHERE accion = 'Inicio de sesión' AND fecha BETWEEN ? AND ?");
$stmt->execute([$desde_sql, $hasta_sql]);
$total_accesos = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT usuario_id) FROM logs_actividad WHERE accion = 'Inicio de sesión' AND fecha BETWEEN ? AND ?");
$stmt->execute([$desde_sql, $hasta_sql]);
$usuarios_unicos = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM vistas_contenido WHERE fecha BETWEEN ? AND ?");
$stmt->execute([$desde_sql, $hasta_sql]);
$total_vistas = (int)$stmt->fetchColumn();

$total_usuarios_activos = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();

// ---------- Accesos por día (gráfica de línea) ----------
$stmt = $pdo->prepare("
    SELECT DATE(fecha) AS dia, COUNT(*) AS total
    FROM logs_actividad
    WHERE accion = 'Inicio de sesión' AND fecha BETWEEN ? AND ?
    GROUP BY DATE(fecha) ORDER BY dia
");
$stmt->execute([$desde_sql, $hasta_sql]);
$accesos_por_dia = $stmt->fetchAll();

// ---------- Vistas por tipo de contenido (gráfica de barras) ----------
$stmt = $pdo->prepare("
    SELECT tipo_contenido, COUNT(*) AS total
    FROM vistas_contenido
    WHERE fecha BETWEEN ? AND ?
    GROUP BY tipo_contenido
");
$stmt->execute([$desde_sql, $hasta_sql]);
$vistas_por_tipo = $stmt->fetchAll();

// ---------- Top 10 contenido más visto ----------
$stmt = $pdo->prepare("
    SELECT tipo_contenido, contenido_id, COUNT(*) AS veces
    FROM vistas_contenido
    WHERE fecha BETWEEN ? AND ?
    GROUP BY tipo_contenido, contenido_id
    ORDER BY veces DESC
    LIMIT 10
");
$stmt->execute([$desde_sql, $hasta_sql]);
$top_contenido = $stmt->fetchAll();
foreach ($top_contenido as &$tc) {
    $tc['nombre'] = obtenerNombreContenido($pdo, $tc['tipo_contenido'], (int)$tc['contenido_id']);
}
unset($tc);

// ---------- Top 10 usuarios más activos (por accesos) ----------
$stmt = $pdo->prepare("
    SELECT u.id, u.nombre_completo, d.nombre AS dependencia, COUNT(*) AS accesos
    FROM logs_actividad la
    JOIN usuarios u ON u.id = la.usuario_id
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    WHERE la.accion = 'Inicio de sesión' AND la.fecha BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY accesos DESC
    LIMIT 10
");
$stmt->execute([$desde_sql, $hasta_sql]);
$top_usuarios = $stmt->fetchAll();

// ---------- Últimos accesos ----------
$stmt = $pdo->prepare("
    SELECT la.fecha, u.nombre_completo, d.nombre AS dependencia, la.ip_address
    FROM logs_actividad la
    JOIN usuarios u ON u.id = la.usuario_id
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    WHERE la.accion = 'Inicio de sesión' AND la.fecha BETWEEN ? AND ?
    ORDER BY la.fecha DESC
    LIMIT 50
");
$stmt->execute([$desde_sql, $hasta_sql]);
$ultimos_accesos = $stmt->fetchAll();

// ---------- Detalle por usuario (si se seleccionó uno) ----------
$detalle_usuario = null;
$detalle_vistas = [];
$detalle_accesos = [];
if ($usuario_detalle_id) {
    $stmt = $pdo->prepare("SELECT u.*, d.nombre AS dependencia FROM usuarios u LEFT JOIN dependencias d ON d.id = u.dependencia_id WHERE u.id = ?");
    $stmt->execute([$usuario_detalle_id]);
    $detalle_usuario = $stmt->fetch();

    if ($detalle_usuario) {
        $stmt = $pdo->prepare("
            SELECT tipo_contenido, contenido_id, fecha FROM vistas_contenido
            WHERE usuario_id = ? AND fecha BETWEEN ? AND ?
            ORDER BY fecha DESC LIMIT 100
        ");
        $stmt->execute([$usuario_detalle_id, $desde_sql, $hasta_sql]);
        $detalle_vistas = $stmt->fetchAll();
        foreach ($detalle_vistas as &$dv) {
            $dv['nombre'] = obtenerNombreContenido($pdo, $dv['tipo_contenido'], (int)$dv['contenido_id']);
        }
        unset($dv);

        $stmt = $pdo->prepare("
            SELECT fecha, ip_address FROM logs_actividad
            WHERE usuario_id = ? AND accion = 'Inicio de sesión' AND fecha BETWEEN ? AND ?
            ORDER BY fecha DESC LIMIT 50
        ");
        $stmt->execute([$usuario_detalle_id, $desde_sql, $hasta_sql]);
        $detalle_accesos = $stmt->fetchAll();
    }
}

$usuarios_dropdown = $pdo->query("SELECT id, nombre_completo FROM usuarios WHERE activo = 1 ORDER BY nombre_completo")->fetchAll();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0"><i class="bi bi-shield-check me-2"></i>Auditoría de accesos y visualizaciones</h2>
    </div>

    <form method="GET" class="row g-2 align-items-end mb-4">
        <div class="col-6 col-md-3">
            <label class="form-label mb-1">Desde</label>
            <input type="date" class="form-control" name="desde" value="<?php echo htmlspecialchars($fecha_desde); ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label mb-1">Hasta</label>
            <input type="date" class="form-control" name="hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
        </div>
        <div class="col-12 col-md-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            <a href="auditoria.php" class="btn btn-outline-secondary">Últimos 30 días</a>
        </div>
        <!-- <div class="col-12 col-md-3 text-md-end">
            <a href="?accion=exportar_accesos&desde=<?php echo $fecha_desde; ?>&hasta=<?php echo $fecha_hasta; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Accesos CSV</a>
            <a href="?accion=exportar_vistas&desde=<?php echo $fecha_desde; ?>&hasta=<?php echo $fecha_hasta; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Vistas CSV</a>
        </div> -->
    </form>

    <!-- ============ KPIs ============ -->
    <div class="row g-3 mb-4 text-center">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm py-3"><div class="fs-3 fw-bold text-primary"><?php echo $total_accesos; ?></div><div class="small text-muted">Inicios de sesión</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm py-3"><div class="fs-3 fw-bold text-success"><?php echo $usuarios_unicos; ?> / <?php echo $total_usuarios_activos; ?></div><div class="small text-muted">Usuarios únicos que ingresaron</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm py-3"><div class="fs-3 fw-bold text-info"><?php echo $total_vistas; ?></div><div class="small text-muted">Vistas de contenido</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm py-3"><div class="fs-3 fw-bold text-warning"><?php echo $top_contenido[0]['nombre'] ?? '—'; ?></div><div class="small text-muted">Contenido más visto</div></div>
        </div>
    </div>

    <!-- ============ GRÁFICAS ============ -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header">Accesos por día</div>
                <div class="card-body"><canvas id="chartAccesos" height="90"></canvas></div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header">Vistas por tipo de contenido</div>
                <div class="card-body"><canvas id="chartTipos" height="90"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ============ TOP CONTENIDO / TOP USUARIOS ============ -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header">Top 10 contenido más visto</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Tipo</th><th>Contenido</th><th class="text-end">Vistas</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_contenido as $tc): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?php echo etiquetaTipoContenido($tc['tipo_contenido']); ?></span></td>
                                <td><?php echo htmlspecialchars($tc['nombre']); ?></td>
                                <td class="text-end fw-semibold"><?php echo $tc['veces']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($top_contenido)): ?><tr><td colspan="3" class="text-center text-muted py-3">Sin datos en este rango.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header">Top 10 usuarios más activos</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Usuario</th><th>Dependencia</th><th class="text-end">Accesos</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($top_usuarios as $tu): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tu['nombre_completo']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($tu['dependencia'] ?? 'Sin asignar'); ?></td>
                                <td class="text-end fw-semibold"><?php echo $tu['accesos']; ?></td>
                                <td><a href="?desde=<?php echo $fecha_desde; ?>&hasta=<?php echo $fecha_hasta; ?>&usuario_id=<?php echo $tu['id']; ?>#detalle-usuario" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($top_usuarios)): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin datos en este rango.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ ÚLTIMOS ACCESOS ============ 
    <div class="card shadow-sm mb-4">
        <div class="card-header">Últimos accesos a la plataforma (máx. 50)</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Dependencia</th><th>IP</th></tr></thead>
                <tbody>
                    <?php foreach ($ultimos_accesos as $a): ?>
                    <tr>
                        <td class="small"><?php echo date('d/m/Y H:i', strtotime($a['fecha'])); ?></td>
                        <td><?php echo htmlspecialchars($a['nombre_completo']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($a['dependencia'] ?? 'Sin asignar'); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($a['ip_address']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ultimos_accesos)): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin accesos en este rango.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> -->

    <!-- ============ DETALLE POR USUARIO ============ -->
    <div class="card shadow-sm mb-4" id="detalle-usuario">
        <div class="card-header">Detalle por usuario</div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1">Selecciona un usuario</label>
                    <select class="form-select" name="usuario_id" onchange="this.form.submit()">
                        <option value="">— Selecciona —</option>
                        <?php foreach ($usuarios_dropdown as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $usuario_detalle_id === (int)$u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['nombre_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($detalle_usuario): ?>
                <p class="text-muted small">
                    <strong><?php echo htmlspecialchars($detalle_usuario['nombre_completo']); ?></strong>
                    (<?php echo htmlspecialchars($detalle_usuario['dependencia'] ?? 'Sin dependencia'); ?>) —
                    <?php echo count($detalle_accesos); ?> accesos, <?php echo count($detalle_vistas); ?> vistas de contenido en el rango seleccionado.
                </p>
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <p class="fw-semibold small mb-1">Accesos</p>
                        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                            <table class="table table-sm">
                                <tbody>
                                    <?php foreach ($detalle_accesos as $a): ?>
                                        <tr><td class="small"><?php echo date('d/m/Y H:i', strtotime($a['fecha'])); ?></td><td class="small text-muted"><?php echo htmlspecialchars($a['ip_address']); ?></td></tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($detalle_accesos)): ?><tr><td class="text-muted small">Sin accesos registrados.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <p class="fw-semibold small mb-1">Contenido visto</p>
                        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                            <table class="table table-sm">
                                <tbody>
                                    <?php foreach ($detalle_vistas as $v): ?>
                                        <tr>
                                            <td class="small"><?php echo date('d/m/Y H:i', strtotime($v['fecha'])); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo etiquetaTipoContenido($v['tipo_contenido']); ?></span></td>
                                            <td class="small"><?php echo htmlspecialchars($v['nombre']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($detalle_vistas)): ?><tr><td class="text-muted small">Sin vistas registradas.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3 mb-0">Selecciona un usuario para ver su detalle.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('chartAccesos'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($accesos_por_dia, 'dia')); ?>,
        datasets: [{ label: 'Accesos', data: <?php echo json_encode(array_map('intval', array_column($accesos_por_dia, 'total'))); ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.1)', fill: true, tension: .3 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chartTipos'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map('etiquetaTipoContenido', array_column($vistas_por_tipo, 'tipo_contenido'))); ?>,
        datasets: [{ label: 'Vistas', data: <?php echo json_encode(array_map('intval', array_column($vistas_por_tipo, 'total'))); ?>, backgroundColor: '#0dcaf0' }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>

<?php require_once '../includes/footer.php'; ?>
