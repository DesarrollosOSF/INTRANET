<?php
require_once '../config/config.php';
requerirPermiso('gestionar_experiencia');

require_once dirname(__DIR__) . '/experiencia/helpers.php';

$page_title = 'Gestión de Experiencia';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

$pdo = getDBConnection();
$secciones = require dirname(__DIR__) . '/experiencia/sections.php';
$secciones_info = [];

foreach ($secciones as $seccion) {
    $num_modulos = 0;
    try {
        $contenido = getExperienciaContenido($pdo, $seccion['slug'], false);
        if ($contenido) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM experiencia_modulos WHERE contenido_id = ?");
            $stmt->execute([$contenido['id']]);
            $num_modulos = (int)$stmt->fetchColumn();
        }
    } catch (Exception $e) {}

    $secciones_info[] = array_merge($seccion, [
        'num_modulos' => $num_modulos,
    ]);
}

$total_documentos = contarTotalDocumentosExperiencia($pdo);
$conteo_estados = contarSolicitudesCambioPorEstado($pdo);
$solicitudes_pendientes = $conteo_estados['pendiente'] + $conteo_estados['en_proceso'];

$tiempo_respuesta = tiempoPromedioRespuestaExperiencia( $pdo );
$por_dependencia = contarSolicitudesCambioPorDependencia( $pdo, 10 );
$por_mes = contarSolicitudesCambioPorMes( $pdo, 6 );

?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-heart me-2"></i>Gestión de Experiencia</h2>
        <div class="d-flex gap-2">
            <a href="experiencia_solicitudes.php" class="btn btn-outline-warning position-relative">
                <i class="bi bi-inbox me-1"></i>Solicitudes de cambio
                <?php if ($solicitudes_pendientes > 0): ?>
                    <span class="badge rounded-pill bg-danger ms-1"><?php echo $solicitudes_pendientes; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>experiencia/index.php" class="btn btn-outline-primary spa-nav-link">
                <i class="bi bi-box-arrow-up-right me-1"></i>Ver módulo público
            </a>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class="bi bi-file-earmark-text fs-1 text-primary"></i>
                    <h3 class="mt-2"><?php echo $total_documentos; ?></h3>
                    <p class="text-muted mb-0">Total de documentos</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class="bi bi-hourglass-split fs-1 text-warning"></i>
                    <h3 class="mt-2"><?php echo $solicitudes_pendientes; ?></h3>
                    <p class="text-muted mb-0">Solicitudes por atender</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class="bi bi-check-circle fs-1 text-success"></i>
                    <h3 class="mt-2"><?php echo $conteo_estados['atendida']; ?></h3>
                    <p class="text-muted mb-0">Solicitudes atendidas</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class="bi bi-layers fs-1 text-info"></i>
                    <h3 class="mt-2"><?php echo count($secciones_info); ?></h3>
                    <p class="text-muted mb-0">Secciones</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <i class=" bi bi-clock-history fs-2 text-primary "></i>
                <h4 class="mt-2">
                    <?php
                    echo $tiempo_respuesta['inicio_atencion'] !== null ? $tiempo_respuesta['inicio_atencion'] . ' h' : '—'; ?>
                </h4>
                <p class="text-muted mb-0"> Tiempo hasta iniciar atención </p>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <i class=" bi bi-arrow-repeat fs-2 text-info "></i>
                <h4 class="mt-2">
                    <?php echo $tiempo_respuesta['en_proceso'] !== null ? $tiempo_respuesta['en_proceso'] . ' h' : '—'; ?>
                </h4>

                <p class="text-muted mb-0">Tiempo promedio en proceso</p>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <i class=" bi bi-speedometer2 fs-2 text-success "></i>
                <h4 class="mt-2">
                    <?php
                    echo $tiempo_respuesta['global'] !== null ? $tiempo_respuesta['global'] . ' h' : '—'; ?>
                </h4>
                <p class="text-muted mb-0"> Tiempo total promedio </p>
            </div>
        </div>
    </div>
</div>

</div>

    <p class="text-muted">
        Administre el contenido de cada sección de Experiencia. En el detalle de cada sección puede crear módulos,
        subir archivos y elegir <strong>qué usuarios pueden ver cada módulo y cada documento</strong>.
    </p>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Sección</th>
                            <th>Módulos</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secciones_info as $seccion): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($seccion['titulo']); ?></div>
                                    <?php if (!empty($seccion['intro'])): ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars(mb_strimwidth($seccion['intro'], 0, 120, '...')); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo (int)$seccion['num_modulos']; ?></span></td>
                                <td class="text-end">
                                    <a href="experiencia_detalle.php?seccion=<?php echo urlencode($seccion['slug']); ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-layers me-1"></i>Gestionar módulos y acceso
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
