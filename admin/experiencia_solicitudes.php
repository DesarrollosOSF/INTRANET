<?php
/**
 * admin/experiencia_solicitudes.php
 */
require_once '../config/config.php';

requerirPermiso('gestionar_experiencia');
require_once dirname(__DIR__)
    . '/experiencia/helpers.php';

$pdo = getDBConnection();

$page_title = 'Solicitudes de Cambio - Experiencia';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';
/*
 * Verificar tabla.
 */
if ( !tablaSolicitudesCambioOk($pdo)) {
    echo '
        <div class="container-fluid mt-4">
            <div class="alert alert-danger">
                La tabla
                <strong>
                    experiencia_solicitudes_cambio
                </strong>
                no existe.
                Ejecute el archivo
                docs/experiencia_solicitudes_cambio.sql
                en la base de datos.
            </div>
        </div>
    ';
    require_once '../includes/footer.php';
    exit;
}

$mensaje = '';
$tipo_mensaje = '';
/*
 * Actualizar estado.
 */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_estado' ) {
    $solicitud_id =
        (int)( $_POST['solicitud_id'] ?? 0 );
    $estado = $_POST['estado'] ?? '';
    $respuesta = trim( $_POST['respuesta'] ?? '' );
    try { actualizarEstadoSolicitudCambio(
            $pdo,
            $solicitud_id,
            $estado,
            $respuesta,
            $_SESSION['usuario_id']
        );
        $mensaje = 'Solicitud actualizada correctamente.';
        $tipo_mensaje = 'success';
        if ( function_exists( 'registrarLog' ) ) {
            registrarLog(
                $_SESSION['usuario_id'],
                'Actualizar solicitud de cambio Experiencia',
                'Experiencia',
                "Solicitud ID: {$solicitud_id} -> {$estado}"
            );
        }
    } catch (Exception $e) {
        $mensaje = 'No se pudo actualizar la solicitud.';
        $tipo_mensaje = 'danger';
        error_log( $e->getMessage() );
    }
}

/*
 * Filtros.
 */
$filtros = [];
if (!empty($_GET['estado']) && $_GET['estado'] !== 'todas') {

    $filtros['estado'] = $_GET['estado'];
}

if ( !empty($_GET['seccion_slug'])) {

    $filtros['seccion_slug'] = $_GET['seccion_slug'];
}
if (!empty($_GET['fecha_desde'])) {
    $filtros['fecha_desde'] = $_GET['fecha_desde'];
}

if (!empty($_GET['fecha_hasta'])) {
    $filtros['fecha_hasta'] = $_GET['fecha_hasta'];
}

/*
 * Paginación.
 */
$por_pagina = 10;

$total_registros = contarTotalSolicitudesCambioExperiencia(
        $pdo,
        $filtros
    );

$total_paginas = max(1, (int)ceil($total_registros / $por_pagina));

$pagina_actual = (int)($_GET['pagina'] ?? 1);
if ($pagina_actual < 1) {
    $pagina_actual = 1;
}
if ($pagina_actual > $total_paginas) {
    $pagina_actual = $total_paginas;
}

$offset = ($pagina_actual - 1) * $por_pagina;

$solicitudes = listarSolicitudesCambioExperiencia(
        $pdo,
        $filtros,
        $por_pagina,
        $offset
    );

/*
 * Datos globales para gráficas.
 */
$por_estado = contarSolicitudesCambioPorEstado( $pdo );

$por_tipo = contarSolicitudesCambioPorTipo( $pdo );

$por_dependencia = contarSolicitudesCambioPorDependencia( $pdo, 10 );

$por_modulo = contarSolicitudesCambioPorModulo( $pdo, 10 );

$por_mes = contarSolicitudesCambioPorMes( $pdo, 6 );

$tiempo_respuesta = tiempoPromedioRespuestaExperiencia( $pdo );

$total_documentos = contarTotalDocumentosExperiencia( $pdo );

$cumplimiento_criterios = resumenCumplimientoCriteriosExperiencia( $pdo );

$estados_info = estadosSolicitudExperiencia();

$secciones_lista = require dirname(__DIR__) . '/experiencia/sections.php';

/*
 * FIX 1: los links de informe PDF/Excel deben conservar
 * los filtros activos.
 */
$query_informe = http_build_query([
    'estado' => $_GET['estado'] ?? '',
    'seccion_slug' => $_GET['seccion_slug'] ?? '',
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
]);

?>

<div class="container-fluid mt-4">
    <!-- ENCABEZADO -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2>
            <i class="bi bi-inbox me-2"></i>
            Solicitudes de Cambio - Experiencia
        </h2>

        <div class="d-flex gap-2 flex-wrap">
            <a href="../experiencia/experiencia_informe.php?<?php echo $query_informe; ?>" target="_blank" class="btn btn-outline-danger">
                <i class="bi bi-file-earmark-pdf me-1"></i>
                Informe PDF
            </a>

            <a href="../experiencia/experiencia_informe_excel.php?<?php echo $query_informe; ?>" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-excel me-1"></i>
                Informe Excel
            </a>

            <a href="experiencia.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-2"></i>
                Volver
            </a>
        </div>
    </div>

    <!-- MENSAJE -->
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars( $mensaje ); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ======================================================
         KPIs
         ====================================================== -->

    <div class="row mb-4">
        <!-- Por atender -->
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class=" bi bi-hourglass-split fs-1 text-warning "></i>
                    <h3 class="mt-2">
                        <?php echo $por_estado['pendiente'] + $por_estado['en_proceso']; ?>
                    </h3>
                    <p class="text-muted mb-0"> Por atender </p>
                </div>
            </div>
        </div>

        <!-- Atendidas -->
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body"> <i class="  bi bi-check-circle fs-1 text-success "></i>
                    <h3 class="mt-2">
                        <?php echo $por_estado['atendida']; ?>
                    </h3>
                    <p class="text-muted mb-0"> Atendidas </p>
                </div>
            </div>
        </div>

        <!-- Tiempo -->
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class=" bi bi-speedometer2 fs-1 text-info "></i>
                    <h3 class="mt-2">
                        <?php echo $tiempo_respuesta['global'] !== null ? $tiempo_respuesta['global'] . ' h' : '—'; ?>
                    </h3>
                    <p class="text-muted mb-0"> Tiempo promedio de respuesta </p>
                </div>
            </div>
        </div>

        <!-- Documentos -->
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card text-center shadow-sm h-100">
                <div class="card-body">
                    <i class=" bi bi-file-earmark-text fs-1 text-primary "></i>
                    <h3 class="mt-2">
                        <?php echo $total_documentos; ?>
                    </h3>
                    <p class="text-muted mb-0"> Total de documentos </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================
         MÉTRICAS DE TIEMPO
         ====================================================== -->

    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6> <i class="bi bi-clock"></i> Tiempo hasta iniciar atención </h6>
                    <h3>
                        <?php echo $tiempo_respuesta['inicio_atencion'] !== null ? $tiempo_respuesta['inicio_atencion'] . ' h' : '—'; ?>
                    </h3>
                </div>
            </div>
        </div>


        <div class="col-md-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6> <i class="bi bi-arrow-repeat"></i> Tiempo promedio en proceso </h6>
                    <h3>
                        <?php echo $tiempo_respuesta['en_proceso'] !== null ? $tiempo_respuesta['en_proceso'] . ' h' : '—'; ?>
                    </h3>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6> <i class="bi bi-speedometer2"></i> Tiempo total promedio </h6>
                    <!-- FIX 2: faltaba el sufijo " h" para que coincida con las otras dos tarjetas -->
                    <h3> <?php echo $tiempo_respuesta['global'] !== null ? $tiempo_respuesta['global'] . ' h' : '—'; ?>
                    </h3>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================
         GRÁFICAS
         ====================================================== -->

    <div class="row mb-4">
        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"> Solicitudes por estado</div>
                <div class="card-body"> <canvas id="chartEstado"></canvas> </div>
            </div>
        </div>

        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"> Solicitudes por tipo </div>
                <div class="card-body"> <canvas id="chartTipo"></canvas> </div>
            </div>
        </div>

        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"> Tendencia mensual </div>
                <div class="card-body"> <canvas id="chartMes"></canvas> </div>
            </div>
        </div>

        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"> Solicitudes por dependencia </div>
                <div class="card-body"> <canvas id="chartDependencia"></canvas> </div>
            </div>
        </div>

        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"> Módulos con más solicitudes </div>
                <div class="card-body"> <canvas id="chartModulo"></canvas> </div>
            </div>
        </div>

        <!-- Tiempo por tipo -->
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header"> Tiempo promedio por tipo de solicitud </div>
                <div class="card-body"> <canvas id="chartTiempoTipo"></canvas> </div>
            </div>
        </div>
    </div>

    <!-- ======================================================
         CHECKLIST
         ====================================================== -->

    <div class="card shadow-sm mb-4">
        <div class="card-header">
            <strong> <i class="bi bi-clipboard-check me-1"></i>
                Cumplimiento de criterios de calidad documental
            </strong>
        </div>

        <div class="card-body">
            <?php if (empty($cumplimiento_criterios)): ?>
                <p class="text-muted"> Todavía no existen datos del checklist. </p>
            <?php else: ?>
                <?php foreach ( $cumplimiento_criterios as $criterio ): ?>

                    <div class="mb-3">
                        <div class=" d-flex justify-content-between small mb-1 ">
                            <span> <?php echo htmlspecialchars( $criterio['label'] ); ?> </span>
                            <strong> <?php echo $criterio['porcentaje'] . '%'; ?> </strong>
                        </div>

                        <div class="progress" style="height:10px;">
                            <div class="progress-bar bg-success" style="
                                    width: <?php echo $criterio['porcentaje']; ?>%;"></div>
                        </div>

                        <small class="text-muted">
                            <?php echo $criterio['cumple'] . ' de ' . $criterio['total'] . ' solicitudes'; ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======================================================
     LISTADO
     ====================================================== -->
    <div class="card shadow-sm">
        <div class="card-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h5 class="mb-0"> Solicitudes registradas </h5>

        <?php if (!empty($filtros)): ?>
            <small class="text-muted">
                <i class="bi bi-funnel-fill me-1"></i>
                Filtros activos — el informe descargado reflejará esta misma vista
            </small>
        <?php endif; ?>
    </div>

    <form method="get" class="row g-2 align-items-end">

        <div class="col-auto">
            <label class="form-label small mb-1">Sección</label>
            <select name="seccion_slug" class="form-select form-select-sm">
                <option value=""> Todas las secciones </option>
                <?php foreach ( $secciones_lista as $sec ): ?>
                    <option
                        value="<?php echo htmlspecialchars( $sec['slug'] ); ?>"
                        <?php echo ( $_GET['seccion_slug'] ?? '' ) === $sec['slug'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars( $sec['titulo']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <label class="form-label small mb-1">Estado</label>
            <select name="estado" class="form-select form-select-sm">
                <option value="todas"> Todos los estados </option>
                <?php foreach ( $estados_info as $val => $info ): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>"
                        <?php echo ( $_GET['estado'] ?? '' ) === $val ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars( $info['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-auto">
            <label class="form-label small mb-1">Desde</label>
            <input type="date" name="fecha_desde" id="filtroFechaDesde" class="form-control form-control-sm"
                value="<?php echo htmlspecialchars($_GET['fecha_desde'] ?? ''); ?>">
        </div>

        <div class="col-auto">
            <label class="form-label small mb-1">Hasta</label>
            <input type="date" name="fecha_hasta" id="filtroFechaHasta" class="form-control form-control-sm"
                value="<?php echo htmlspecialchars($_GET['fecha_hasta'] ?? ''); ?>">
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-funnel me-1"></i>Filtrar
            </button>
        </div>

        <?php if (!empty($_GET['estado']) || !empty($_GET['seccion_slug']) || !empty($_GET['fecha_desde']) || !empty($_GET['fecha_hasta'])): ?>
            <div class="col-auto">
                <a href="experiencia_solicitudes.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-circle me-1"></i>Limpiar
                </a>
            </div>
        <?php endif; ?>

    </form>

    <!-- Atajos rápidos de fecha -->
    <div class="d-flex gap-2 flex-wrap mt-2">
        <button type="button" class="btn btn-sm btn-outline-secondary btn-atajo-fecha" data-rango="hoy">Hoy</button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-atajo-fecha" data-rango="semana">Últimos 7 días</button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-atajo-fecha" data-rango="mes">Este mes</button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-atajo-fecha" data-rango="trimestre">Últimos 3 meses</button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-atajo-fecha" data-rango="anio">Este año</button>
    </div>
</div>

        <div class="card-body p-0">
            <?php if ( empty($solicitudes) ): ?>
                <div class="p-4 text-center text-muted">
                    <i class=" bi bi-inbox fs-1 "></i>
                    <p class="mt-2"> No hay solicitudes registradas. </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class=" table table-hover mb-0 align-middle ">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Solicitud</th>
                                <th>Documento</th>
                                <th>Solicitante</th>
                                <th>Dependencia</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                                <th>Tiempo</th>
                                <th>Seguimiento</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $solicitudes as $s ):
                                $estado_actual = $estados_info[$s['estado']] ??
                                    [ 'label' => $s['estado'], 'color' => 'dark' ];
                            ?>
                                <tr>
                                    <!-- ID -->
                                    <td>
                                        <span class="badge bg-dark">
                                            #<?php echo (int)$s['id']; ?>
                                        </span>
                                    </td>

                                    <!-- SOLICITUD -->
                                    <td class="small">
                                        <strong>
                                            <?php echo htmlspecialchars( $s['seccion_titulo'] ?? '—' ); ?>
                                        </strong>
                                        <br>
                                        <span class="text-muted">
                                            <?php echo htmlspecialchars( $s['modulo_titulo'] ?? '—' ); ?>
                                        </span>
                                        <br>
                                        <small>
                                            <?php echo date( 'd/m/Y H:i', strtotime( $s['fecha_solicitud'] )); ?>
                                        </small>
                                    </td>

                                    <!-- DOCUMENTO -->
                                    <td class="small">
                                        <?php echo htmlspecialchars( $s['archivo_nombre'] ?? '(archivo eliminado)' );?>
                                    </td>

                                    <!-- SOLICITANTE -->
                                    <td class="small">
                                        <strong>
                                            <?php echo htmlspecialchars( $s['solicitante_nombre'] ?? '—' ); ?>
                                        </strong>

                                        <br>
                                        <span class="text-muted">
                                            <?php echo htmlspecialchars( $s['solicitante_email'] ?? '' ); ?>
                                        </span>
                                    </td>

                                    <!-- DEPENDENCIA -->
                                    <td class="small">
                                        <span class=" badge bg-light text-dark border ">
                                            <?php
                                            echo htmlspecialchars( $s['dependencia_nombre'] ?? 'Sin dependencia' ); ?>
                                        </span>
                                    </td>

                                    <!-- TIPO -->
                                    <td>
                                        <span class=" badge bg-light text-dark border ">
                                            <?php echo htmlspecialchars( $s['tipo_solicitud'] ); ?>
                                        </span>
                                    </td>

                                    <!-- ESTADO -->
                                    <td>
                                        <span class=" badge
                                    bg-<?php echo htmlspecialchars( $estado_actual['color'] ); ?> ">
                                            <?php echo htmlspecialchars( $estado_actual['label'] ); ?>
                                        </span>
                                    </td>

                                    <!-- TIEMPO -->
                                    <td class="small">
                                        <?php
                                        if ( $s['horas_respuesta'] !== null ) {
                                            echo htmlspecialchars( $s['horas_respuesta'] ) . ' h';
                                        } else { 
                                            echo '—'; }
                                        ?>

                                    </td>

                                    <!-- SEGUIMIENTO -->
                                    <td>
                                        <button type="button" class=" btn btn-sm btn-outline-info "
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalSeguimiento<?php echo (int)$s['id']; ?>"
                                            title="Ver seguimiento"> <i class=" bi bi-clock-history "></i> 
                                        </button>
                                    </td>

                                    <!-- ACCIONES -->
                                    <td>
                                        <button type="button" class=" btn btn-sm btn-outline-primary " data-bs-toggle="modal"
                                            data-bs-target="#modalGestionar<?php echo (int)$s['id']; ?>"
                                            title="Gestionar"> <i class=" bi bi-pencil "></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_paginas > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">

                <small class="text-muted">
                    Mostrando
                    <?php echo $offset + 1; ?>–<?php echo min($offset + $por_pagina, $total_registros); ?>
                    de <?php echo $total_registros; ?> solicitudes
                </small>

                <nav aria-label="Paginación de solicitudes">
                    <ul class="pagination pagination-sm mb-0">

                        <?php
                        // Construye la URL de una página, conservando los filtros actuales
                        $construirUrlPagina = function ($pagina) {
                            $params = $_GET;
                            $params['pagina'] = $pagina;
                            return '?' . http_build_query($params);
                        };
                        ?>

                        <!-- Anterior -->
                        <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $pagina_actual > 1 ? htmlspecialchars($construirUrlPagina($pagina_actual - 1)) : '#'; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>

                        <?php
                        // Ventana de páginas: máximo 5 números visibles alrededor de la actual
                        $rango_inicio = max(1, $pagina_actual - 2);
                        $rango_fin = min($total_paginas, $rango_inicio + 4);
                        $rango_inicio = max(1, $rango_fin - 4);
                        ?>

                        <?php if ($rango_inicio > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo htmlspecialchars($construirUrlPagina(1)); ?>">1</a>
                            </li>
                            <?php if ($rango_inicio > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $rango_inicio; $p <= $rango_fin; $p++): ?>
                            <li class="page-item <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($construirUrlPagina($p)); ?>">
                                    <?php echo $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($rango_fin < $total_paginas): ?>
                            <?php if ($rango_fin < $total_paginas - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo htmlspecialchars($construirUrlPagina($total_paginas)); ?>">
                                    <?php echo $total_paginas; ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Siguiente -->
                        <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $pagina_actual < $total_paginas ? htmlspecialchars($construirUrlPagina($pagina_actual + 1)) : '#'; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>

                    </ul>
                </nav>

            </div>
        <?php endif; ?>

    </div>

</div>


<!-- 
     MODALES -->

<?php foreach ( $solicitudes as $s ): ?>


    <div class="modal fade" id="modalSeguimiento<?php echo (int)$s['id']; ?>"
        tabindex="-1" aria-hidden="true">
        <div class=" modal-dialog modal-dialog-centered ">
            <div class="modal-content">

                <!-- HEADER -->
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class=" bi bi-clock-history me-1 "></i>
                        Seguimiento solicitud
                        #<?php echo (int)$s['id']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <!-- BODY -->
                <div class="modal-body">

                    <!-- DOCUMENTO -->
                    <div class="mb-3">
                        <strong> Documento: </strong>
                        <br>
                        <?php echo htmlspecialchars( $s['archivo_nombre'] ?? '—' );
                        ?>
                    </div>

                    <!-- PENDIENTE -->
                    <div class="mb-4">
                        <div class=" d-flex align-items-center ">
                            <i class=" bi bi-check-circle-fill text-warning me-2 "></i>
                            <strong> Pendiente </strong>
                        </div>
                        <div class=" small text-muted ms-4 ">
                            <?php echo date( 'd/m/Y H:i', strtotime( $s['fecha_solicitud'] ) );
                            ?>
                        </div>
                    </div>

                    <!-- EN PROCESO -->
                    <div class="mb-4">
                        <div class=" d-flex align-items-center "> 
                            <i class=" bi bi-circle-fill text-info me-2 "></i>
                            <strong> En proceso </strong>
                        </div>
                        <div class=" small text-muted ms-4 ">
                            <?php
                            if ( !empty($s['fecha_en_proceso']) ) {
                                echo date( 'd/m/Y H:i', strtotime( $s['fecha_en_proceso'] ) );
                            } else {
                                echo 'Aún no iniciado';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- ATENDIDA -->
                    <div class="mb-4">
                        <div class=" d-flex align-items-center ">
                            <i class=" bi bi-circle-fill text-success me-2 "></i>
                            <strong> Atendida </strong>
                        </div>
                        <div class=" small text-muted ms-4 ">
                            <?php
                            if ( !empty($s['fecha_atencion']) ) {
                                echo date( 'd/m/Y H:i', strtotime( $s['fecha_atencion'])
                                );
                            } else {
                                echo '—';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- RECHAZADA -->
                    <div class="mb-4">
                        <div class=" d-flex align-items-center ">
                            <i class=" bi bi-circle-fill text-secondary me-2 "></i>
                            <strong> Rechazada </strong>
                        </div>

                        <div class=" small text-muted ms-4 ">
                            <?php
                            if ( !empty($s['fecha_rechazada']) ) {
                                echo date( 'd/m/Y H:i', strtotime( $s['fecha_rechazada']));
                            } else {
                                echo '—';
                            }
                            ?>
                        </div>
                    </div>

                    <hr>

                    <!-- TIEMPOS -->
                    <div class="row">
                        <div class="col-md-6">
                            <strong> Tiempo hasta proceso </strong>
                            <br>
                            <?php
                            echo $s['horas_hasta_proceso'] !== null ? $s['horas_hasta_proceso'] . ' horas' : '—';
                            ?>
                        </div>

                        <div class="col-md-6">
                            <strong> Tiempo total </strong>
                            <br>
                            <?php
                            echo $s['horas_respuesta'] !== null ? $s['horas_respuesta'] . ' horas' : 'Pendiente';
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================================================
         MODAL GESTIONAR
         ================================================== -->

    <div class="modal fade" id="modalGestionar<?php echo (int)$s['id']; ?>"
        tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="accion" value="actualizar_estado">
                    <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">

                    <!-- HEADER -->
                    <div class="modal-header">
                        <h5 class="modal-title"> Gestionar solicitud
                            #<?php echo (int)$s['id']; ?>
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <!-- BODY -->
                    <div class="modal-body">

                        <!-- INFORMACIÓN -->
                        <div class=" alert alert-light border ">
                            <strong> Documento: </strong>
                            <?php echo htmlspecialchars( $s['archivo_nombre'] ?? '—' ); ?>
                            <br>
                            <strong> Solicitante: </strong>
                            <?php echo htmlspecialchars( $s['solicitante_nombre'] ?? '—' ); ?>

                            <br>

                            <strong> Dependencia: </strong>

                            <?php
                            echo htmlspecialchars(
                                $s['dependencia_nombre'] ?? 'Sin dependencia');
                            ?>

                        </div>
                        <?php
                        $checklist_decodificado = [];
                        if (!empty($s['checklist_calidad'])) {
                            $checklist_decodificado = json_decode($s['checklist_calidad'], true) ?: [];
                        }
                        $criterios_plano = criteriosCalidadDocumentalPlano();
                        ?>

                        <!-- CRITERIOS DE CALIDAD -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-clipboard-check me-1"></i>
                                Respuestas del checklist de calidad documental
                            </label>

                            <?php if (empty($checklist_decodificado)): ?>
                                <p class="text-muted small mb-0">Esta solicitud no tiene checklist registrado.</p>
                            <?php else: ?>
                                <div class="border rounded p-2" style="max-height:250px; overflow-y:auto;">
                                    <?php foreach ($criterios_plano as $key => $pregunta): ?>
                                        <?php if (array_key_exists($key, $checklist_decodificado)): ?>
                                            <?php $respuesta = $checklist_decodificado[$key]; ?>
                                            <div class="d-flex justify-content-between align-items-start small border-bottom py-1">
                                                <span>
                                                    <strong><?php echo htmlspecialchars($key); ?></strong>
                                                    <?php echo htmlspecialchars($pregunta); ?>
                                                </span>
                                                <?php if ($respuesta === true || $respuesta === 1 || $respuesta === '1'): ?>
                                                    <span class="badge bg-success ms-2">Sí</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger ms-2">No</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- COMENTARIO -->
                        <div class="mb-3">
                            <label class="form-label"> Comentario de la solicitud </label>
                            <div class=" border rounded p-3 bg-light ">
                                <?php echo nl2br( htmlspecialchars( $s['comentario'] ?? '' )); ?>
                            </div>
                        </div>

                        <!-- ESTADO -->
                        <div class="mb-3">
                            <label class="form-label">Estado </label>

                            <select name="estado" class="form-select" required>

                                <?php foreach ( $estados_info as $val => $info ): ?>
                                    <option
                                        value="<?php echo htmlspecialchars( $val ); ?>"
                                        <?php echo $s['estado'] === $val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars( $info['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- RESPUESTA -->
                        <div class="mb-3">
                            <label class="form-label">Respuesta / observación </label>
                            <textarea name="respuesta" class="form-control" rows="4"><?php echo htmlspecialchars( $s['respuesta'] ?? '' ); ?></textarea>
                        </div>
                    </div>

                    <!-- FOOTER -->
                    <div class="modal-footer">
                        <button type="button" class=" btn btn-secondary " data-bs-dismiss="modal"> Cancelar </button>

                        <button type="submit" class=" btn btn-primary ">
                            <i class=" bi bi-save me-1 "></i>
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endforeach; ?>

<!-- CHART.JS -->
<script src="
https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js
"></script>

<script>
    const colores = [
        '#f0ad4e',
        '#5bc0de',
        '#5cb85c',
        '#6c757d',
        '#0d6efd',
        '#dc3545',
        '#20c997',
        '#6610f2'
    ];

    /* ==========================================
     * ESTADOS
     * ========================================== */

    new Chart(
        document.getElementById('chartEstado'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode( array_column( $estados_info, 'label' ) ); ?>,
                datasets: [{
                    data: <?php echo json_encode( array_values( $por_estado ) ); ?>,
                    backgroundColor: colores
                }]
            }
        }
    );

    /* ==========================================
     * TIPO
     * ========================================== */

    new Chart( document.getElementById('chartTipo'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode( array_keys( $por_tipo ) ); ?>,
                datasets: [{ label: 'Solicitudes',
                    data: <?php echo json_encode( array_values( $por_tipo ) ); ?>,
                    backgroundColor: '#0d6efd'
                }]
            },
            options: {
                plugins: {legend: {display: false }},
                scales: {
                    /* FIX 3: stepSize:1 -> precision:0, evita cientos de marcas en el eje si crecen los datos */
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        }
    );


    /* ==========================================
     * MES
     * ========================================== */

    new Chart( document.getElementById('chartMes'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode( array_column($por_mes, 'etiqueta') ); ?>,
                datasets: [{ label: 'Solicitudes',
                    data: <?php echo json_encode( array_map( 'intval', array_column( $por_mes, 'total' ))); ?>,
                    borderColor: '#20c997',
                    backgroundColor: 'rgba(32,201,151,0.15)',
                    fill: true,
                    tension: 0.3
                }]
            },

            options: {
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        }
    );

    /* ==========================================
     * DEPENDENCIA
     * ========================================== */

    new Chart( document.getElementById('chartDependencia'), {
            type: 'bar',
            data: {labels: <?php echo json_encode( array_column( $por_dependencia, 'dependencia' ));?>,
                datasets: [{
                    label: 'Solicitudes',
                    data: <?php echo json_encode( array_map('intval', array_column( $por_dependencia, 'total' )));?>,
                    backgroundColor: '#6610f2'
                }]
            },
            options: {
                indexAxis: 'y', plugins: { legend: {display: false }},
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        }
    );


    /* ==========================================
     * MÓDULOS
     * ========================================== */

    new Chart( document.getElementById('chartModulo'), {

            type: 'bar',
            data: {
                labels: <?php echo json_encode( array_column( $por_modulo, 'titulo' )); ?>,
                datasets: [{ label: 'Solicitudes',
                    data: <?php echo json_encode( array_map( 'intval', array_column( $por_modulo, 'total'))); ?>,
                    backgroundColor: '#f0ad4e'
                }]
            },

            options: {
                indexAxis: 'y',
                plugins: { legend: {display: false }},
                scales: {
                    x: { beginAtZero: true,
                         ticks: { precision: 0 }
                    }
                }
            }
        }
    );

    // Atajos de rango de fechas para el filtro de solicitudes
    document.querySelectorAll('.btn-atajo-fecha').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var hoy = new Date();
            var desde = new Date();
            var formato = function(d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            };

            switch (btn.getAttribute('data-rango')) {
                case 'hoy':
                    // desde = hoy
                    break;
                case 'semana':
                    desde.setDate(hoy.getDate() - 6);
                    break;
                case 'mes':
                    desde = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                    break;
                case 'trimestre':
                    desde.setMonth(hoy.getMonth() - 3);
                    break;
                case 'anio':
                    desde = new Date(hoy.getFullYear(), 0, 1);
                    break;
            }

            document.getElementById('filtroFechaDesde').value = formato(desde);
            document.getElementById('filtroFechaHasta').value = formato(hoy);

            // Enviamos el formulario automáticamente
            btn.closest('form').submit();
        });
    });

    /* ==========================================
     * TIEMPO POR TIPO
     * ========================================== */

    new Chart( document.getElementById('chartTiempoTipo'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode( array_column( $tiempo_respuesta['por_tipo'], 'tipo' ) ); ?>,
                datasets: [{ label: 'Horas promedio',
                    data: <?php echo json_encode( array_column( $tiempo_respuesta['por_tipo'], 'promedio')); ?>,
                    backgroundColor: '#0dcaf0'
                }]
            },
            options: {
                plugins: { legend: { display: false }
                },
                scales: { y: { beginAtZero: true }
                }
            }
        }
    );
</script>


<?php
require_once '../includes/footer.php';
?>