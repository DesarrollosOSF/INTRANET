<?php

require_once dirname(__DIR__) . '/config/config.php';

requerirPermiso( 'gestionar_experiencia' );
require_once __DIR__ . '/helpers.php';

$pdo = getDBConnection();

if ( !tablaSolicitudesCambioOk($pdo)) {
    die( 'La tabla experiencia_solicitudes_cambio no existe.' );
}

$filtros = [];
if (!empty($_GET['estado']) && $_GET['estado'] !== 'todas') {
    $filtros['estado'] = $_GET['estado'];
}
if (!empty($_GET['seccion_slug'])) {
    $filtros['seccion_slug'] = $_GET['seccion_slug'];
}
if (!empty($_GET['fecha_desde'])) {
    $filtros['fecha_desde'] = $_GET['fecha_desde'];
}
if (!empty($_GET['fecha_hasta'])) {
    $filtros['fecha_hasta'] = $_GET['fecha_hasta'];
}

$datos = obtenerDatosInformeSolicitudesExperiencia($pdo, $filtros);
$secciones_lista = require __DIR__ . '/sections.php';
$estados = estadosSolicitudExperiencia();
$fecha = date( 'd/m/Y H:i' );

function h($texto){
    return htmlspecialchars( (string)$texto, ENT_QUOTES, 'UTF-8' );
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title> Informe de solicitudes de cambio documental </title>

<style>
body {
    font-family: Arial, Helvetica, sans-serif;
    margin: 30px;
    color: #222;
    font-size: 12px;
}

h1 {
    text-align: center;
    font-size: 22px;
    margin-bottom: 5px;
}

h2 {
    font-size: 16px;
    border-bottom: 2px solid #333;
    padding-bottom: 5px;
    margin-top: 30px;
}

.subtitulo {
    text-align: center;
    color: #666;
    margin-bottom: 25px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.kpi {
    border: 1px solid #ddd;
    padding: 12px;
    text-align: center;
    border-radius: 5px;
}

.kpi strong {
    display: block;
    font-size: 20px;
    margin-bottom: 4px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

th,
td {
    border: 1px solid #ccc;
    padding: 6px;
    vertical-align: top;
}

th {
    background: #f1f1f1;
    font-weight: bold;
}

.progress {
    width: 100%;
    background: #eee;
    height: 10px;
    border-radius: 5px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: #198754;
}

.page-break {
    page-break-before: always;
}

@media print {
    .no-print {
        display: none !important;
    }
    body {
        margin: 15mm;
    }
}

</style>
</head>
<body>

<div class="no-print" style=" text-align:right; margin-bottom:20px; ">
    <div class="no-print" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <label style="font-size:13px;">Desde:
            <input type="date" name="fecha_desde" value="<?php echo h($_GET['fecha_desde'] ?? ''); ?>">
        </label>
        <label style="font-size:13px;">Hasta:
            <input type="date" name="fecha_hasta" value="<?php echo h($_GET['fecha_hasta'] ?? ''); ?>">
        </label>
        <select name="estado" style="padding:4px;">
            <option value="todas">Todos los estados</option>
            <?php foreach ($estados as $val => $info): ?>
                <option value="<?php echo h($val); ?>" <?php echo ($_GET['estado'] ?? '') === $val ? 'selected' : ''; ?>>
                    <?php echo h($info['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="seccion_slug" style="padding:4px;">
            <option value="">Todas las secciones</option>
            <?php foreach ($secciones_lista as $sec): ?>
                <option value="<?php echo h($sec['slug']); ?>" <?php echo ($_GET['seccion_slug'] ?? '') === $sec['slug'] ? 'selected' : ''; ?>>
                    <?php echo h($sec['titulo']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" style="padding:6px 14px;">Filtrar</button>
        <?php if (!empty($_GET)): ?>
            <a href="experiencia_informe.php" style="font-size:13px;">Limpiar</a>
        <?php endif; ?>
    </form>

    <button onclick="window.print()" style="padding:10px 18px; cursor:pointer;">
        Imprimir / Guardar como PDF
    </button>
</div>
</div>


<h1> INFORME DE GESTIÓN DE SOLICITUDES DE CAMBIO DOCUMENTAL </h1>
<div class="subtitulo"> Módulo de Experiencia
    <br>
    Fecha de generación:
    <?php echo h($fecha); ?>
</div>

<?php if (!empty($filtros)): ?>
    <div class="subtitulo" style="font-size:11px;">
        Filtros aplicados:
        <?php
        $partes = [];
        if (!empty($filtros['fecha_desde'])) $partes[] = 'Desde ' . h($filtros['fecha_desde']);
        if (!empty($filtros['fecha_hasta'])) $partes[] = 'Hasta ' . h($filtros['fecha_hasta']);
        if (!empty($filtros['estado'])) $partes[] = 'Estado: ' . h($estados[$filtros['estado']]['label'] ?? $filtros['estado']);
        if (!empty($filtros['seccion_slug'])) $partes[] = 'Sección: ' . h($filtros['seccion_slug']);
        echo implode(' · ', $partes);
        ?>
    </div>
<?php else: ?>
    <div class="subtitulo" style="font-size:11px;">Sin filtros — incluye todo el histórico</div>
<?php endif; ?>

<!-- =====================================================
     RESUMEN
     ===================================================== -->

<h2> 1. Resumen general </h2>
<div class="grid">
    <div class="kpi">
        <strong>
            <?php echo array_sum( $datos['por_estado']); ?>
        </strong>
        Solicitudes totales
    </div>

    <div class="kpi">
        <strong>
            <?php echo $datos['por_estado']['pendiente']; ?>
        </strong>
        Pendientes
    </div>

    <div class="kpi">
        <strong> <?php
            echo $datos['por_estado']['en_proceso']; ?>
        </strong>
        En proceso
    </div>

    <div class="kpi">
        <strong>
            <?php echo $datos['por_estado']['atendida']; ?>
        </strong>
        Atendidas
    </div>

    <div class="kpi">
        <strong> <?php echo $datos['por_estado']['rechazada']; ?> </strong>
        Rechazadas
    </div>

    <div class="kpi">
        <strong>
            <?php echo $datos['tiempo_respuesta']['global'] !== null ? $datos['tiempo_respuesta']['global'] . ' h' : '—'; ?>
        </strong>
        Tiempo total promedio
    </div>

    <div class="kpi">
        <strong>
            <?php echo $datos['tiempo_respuesta']['inicio_atencion'] !== null ? $datos['tiempo_respuesta']['inicio_atencion'] . ' h' : '—'; ?>
        </strong>
        Inicio de atención
    </div>

    <div class="kpi">
        <strong>
            <?php echo $datos['total_documentos']; ?>
        </strong>
        Documentos
    </div>
</div>

<!-- =====================================================
     ESTADOS
     ===================================================== -->

<h2> 2. Solicitudes por estado </h2>

<table>
<thead>
<tr>
    <th>Estado</th>
    <th>Total</th>
    <th>Porcentaje</th>
</tr>
</thead>

<tbody>
<?php

$total_solicitudes = array_sum( $datos['por_estado'] );

foreach ( $datos['por_estado'] as $estado => $cantidad ):
    $porcentaje = $total_solicitudes > 0 ? round( ( $cantidad / $total_solicitudes ) * 100, 1 ) : 0;
?>

<tr>
    <td>
        <?php echo h( $estados[$estado]['label'] ?? $estado ); ?>
    </td>
    <td>
        <?php echo $cantidad; ?>
    </td>
    <td>
        <?php echo $porcentaje; ?>%
    </td>
</tr>

<?php endforeach; ?>

</tbody>

</table>


<!-- =====================================================
     TIPO
     ===================================================== -->

<h2>3. Solicitudes por tipo</h2>

<table>
<thead>
<tr>
    <th>Tipo</th>
    <th>Total</th>
</tr>
</thead>

<tbody>
<?php foreach ( $datos['por_tipo'] as $tipo => $cantidad ): ?>

<tr>
    <td> <?php echo h($tipo); ?> </td>
    <td> <?php echo $cantidad; ?> </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- =====================================================
     DEPENDENCIA
     ===================================================== -->

<h2> 4. Solicitudes por dependencia </h2>

<table>
<thead>
<tr>
    <th>Dependencia</th>
    <th>Total</th>
</tr>
</thead>

<tbody>
<?php foreach ( $datos['por_dependencia'] as $row ): ?>

<tr>
    <td>
        <?php echo h( $row['dependencia'] ); ?>
    </td>
    <td>
        <?php echo (int)$row['total']; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- =====================================================
     MÓDULOS
     ===================================================== -->

<h2>5. Módulos con mayor cantidad de solicitudes</h2>

<table>
<thead>
<tr>
    <th>Módulo</th>
    <th>Total</th>
</tr>
</thead>

<tbody>
<?php foreach (
    $datos['por_modulo']
    as $row
): ?>

<tr>
    <td>
        <?php echo h( $row['titulo'] ); ?>
    </td>
    <td>
        <?php echo (int)$row['total']; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- =====================================================
     TIEMPO
     ===================================================== -->

<h2>6. Rendimiento y tiempos de respuesta</h2>
<table>
<thead>
<tr>
    <th>Indicador</th>
    <th>Promedio</th>
</tr>
</thead>

<tbody>

<tr>
    <td>Tiempo hasta iniciar atención</td>
    <td>
        <?php echo $datos['tiempo_respuesta'] ['inicio_atencion'] !== null ? $datos['tiempo_respuesta'] ['inicio_atencion'] . ' horas' : '—'; ?>
    </td>
</tr>

<tr>
    <td>Tiempo dentro del proceso</td>
    <td>
        <?php echo $datos['tiempo_respuesta'] ['en_proceso'] !== null ? $datos['tiempo_respuesta'] ['en_proceso'] . ' horas' : '—'; ?>
    </td>
</tr>

<tr>
    <td>Tiempo total de respuesta</td>
    <td>
        <?php echo $datos['tiempo_respuesta'] ['global'] !== null ? $datos['tiempo_respuesta'] ['global'] . ' horas' : '—'; ?>
    </td>
</tr>
</tbody>
</table>

<h3>Tiempo promedio por tipo</h3>

<table>
<thead>
<tr>
    <th>Tipo</th>
    <th>Horas promedio</th>
</tr>
</thead>

<tbody>
<?php foreach ( $datos['tiempo_respuesta'] ['por_tipo'] as $row): ?>

<tr>
    <td>
        <?php echo h( $row['tipo'] ); ?>
    </td>
    <td>
        <?php echo $row['promedio'] . ' h'; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- =====================================================
     CHECKLIST
     ===================================================== -->

<div class="page-break"></div>
<h2>7. Cumplimiento de criterios de calidad documental</h2>

<table>
<thead>
<tr>
    <th>Criterio</th>
    <th>Cumple</th>
    <th>Total</th>
    <th>Porcentaje</th>
</tr>
</thead>

<tbody>
<?php foreach ( $datos['cumplimiento_criterios'] as $criterio ): ?>

<tr>
    <td>
        <?php echo h( $criterio['label'] ); ?>
    </td>
    <td>
        <?php echo $criterio['cumple']; ?>
    </td>

    <td>
        <?php echo $criterio['total']; ?>
    </td>

    <td>
        <?php echo $criterio['porcentaje'] . '%'; ?>
        <div class="progress">
            <div class="progress-bar" style=" width: <?php echo $criterio['porcentaje']; ?>%;" ></div>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- =====================================================
     DETALLE
     ===================================================== -->

<div class="page-break"></div>

<h2>8. Detalle de solicitudes</h2>

<table>
<thead>
<tr>
    <th>ID</th>
    <th>Fecha solicitud</th>
    <th>Documento</th>
    <th>Solicitante</th>
    <th>Dependencia</th>
    <th>Tipo</th>
    <th>Estado</th>
    <th>En proceso</th>
    <th>Atendida</th>
    <th>Rechazada</th>
    <th>Tiempo</th>
</tr>
</thead>

<tbody>
<?php foreach ( $datos['listado'] as $s ): ?>

<tr>
    <td>
        <?php echo (int)$s['id']; ?>
    </td> 
    <td>
        <?php echo date( 'd/m/Y H:i', strtotime( $s['fecha_solicitud'])); ?>
    </td>
    <td>
        <?php echo h( $s['archivo_nombre'] ?? '—' ); ?>
    </td>
    <td>
        <?php echo h( $s['solicitante_nombre'] ?? '—' ); ?>
    </td>
    <td>
        <?php echo h( $s['dependencia_nombre'] ?? 'Sin dependencia' ); ?>
    </td>
    <td>
        <?php echo h( $s['tipo_solicitud'] ); ?>
    </td>
    <td>
        <?php echo h( $estados[ $s['estado'] ]['label'] ?? $s['estado'] ); ?>
    </td>
    <td>
        <?php echo !empty( $s['fecha_en_proceso'] ) ? date( 'd/m/Y H:i', strtotime( $s['fecha_en_proceso'] )) : '—'; ?>
    </td>
    <td>
        <?php echo !empty( $s['fecha_atencion'] ) ? date( 'd/m/Y H:i', strtotime( $s['fecha_atencion'] ) ) : '—'; ?>
    </td>
    <td>
        <?php echo !empty( $s['fecha_rechazada'] ) ? date( 'd/m/Y H:i', strtotime( $s['fecha_rechazada'] )) : '—'; ?>
    </td>
    <td>
        <?php echo $s['horas_respuesta'] !== null ? $s['horas_respuesta'] . ' h' : '—'; ?>
    </td>
</tr>

<?php endforeach; ?>
</tbody>
</table>

<div style=" margin-top:30px; text-align:center; color:#777; ">
    Informe generado automáticamente por el módulo de Experiencia.
</div>
</body>
</html>