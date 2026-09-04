<?php

require_once dirname(__DIR__) . '/config/config.php';

requerirPermiso('gestionar_experiencia');
require_once __DIR__ . '/helpers.php';

$pdo = getDBConnection();

if (!tablaSolicitudesCambioOk($pdo)) {
    die('La tabla experiencia_solicitudes_cambio no existe.');
}

/*
 * Filtros (mismos que en experiencia_informe.php)
 */
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
$estados = estadosSolicitudExperiencia();
$nombre_archivo = 'informe_solicitudes_experiencia_' . date('Y-m-d_H-i-s') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Cache-Control: max-age=0, must-revalidate');
header('Pragma: public');

function excel_h($texto)
{
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}

/**
 * Celda de texto puro forzado (evita que Excel reinterprete
 * fechas, números con ceros a la izquierda, códigos, etc. como
 * otro tipo de dato).
 */
function celda_texto($valor)
{
    return '<td style="mso-number-format:\'\@\';">' . excel_h($valor) . '</td>';
}

/**
 * Celda numérica real (para que sumas/promedios funcionen en Excel).
 */
function celda_numero($valor)
{
    if ($valor === null || $valor === '') {
        return '<td>—</td>';
    }
    return '<td style="mso-number-format:\'General\';">' . excel_h($valor) . '</td>';
}

/**
 * Celda de fecha/hora, formateada como texto legible pero
 * marcada para que Excel no intente reinterpretarla con su
 * propio parser regional (que a veces invierte día/mes).
 */
function celda_fecha($valor)
{
    if (empty($valor)) {
        return '<td>—</td>';
    }
    $formateada = date('d/m/Y H:i', strtotime($valor));
    return '<td style="mso-number-format:\'\@\';">' . excel_h($formateada) . '</td>';
}

// Resumen textual de los filtros aplicados
$resumen_filtros = [];
if (!empty($filtros['fecha_desde'])) $resumen_filtros[] = 'Desde ' . $filtros['fecha_desde'];
if (!empty($filtros['fecha_hasta'])) $resumen_filtros[] = 'Hasta ' . $filtros['fecha_hasta'];
if (!empty($filtros['estado'])) $resumen_filtros[] = 'Estado: ' . ($estados[$filtros['estado']]['label'] ?? $filtros['estado']);
if (!empty($filtros['seccion_slug'])) $resumen_filtros[] = 'Sección: ' . $filtros['seccion_slug'];
$texto_filtros = !empty($resumen_filtros) ? implode(' | ', $resumen_filtros) : 'Sin filtros — incluye todo el histórico';

// BOM UTF-8: evita que tildes/ñ se vean corruptas al abrir en Excel
echo "\xEF\xBB\xBF";

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Informe Experiencia</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
<x:FreezePanes/>
<x:FrozenNoSplit/>
<x:SplitHorizontal>1</x:SplitHorizontal>
<x:TopRowBottomPane>1</x:TopRowBottomPane>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
table {
    border-collapse: collapse;
    width: 100%;
}

th {
    background: #d9eaf7;
    font-weight: bold;
}

th,
td {
    border: 1px solid #999;
    padding: 6px;
}

.titulo {
    font-size: 18px;
    font-weight: bold;
    background: #0d6efd;
    color: #ffffff;
}

.subtitulo {
    font-size: 12px;
    color: #555555;
}

</style>
</head>

<body>

<table>
<tr>
    <td colspan="14" class="titulo">
        INFORME DE GESTIÓN DE SOLICITUDES DE CAMBIO DOCUMENTAL
    </td>
</tr>

<tr>
    <td colspan="14" class="subtitulo">
        Generado: <?php echo excel_h(date('d/m/Y H:i:s')); ?>
    </td>
</tr>

<tr>
    <td colspan="14" class="subtitulo">
        Filtros aplicados: <?php echo excel_h($texto_filtros); ?>
    </td>
</tr>
</table>
<br>

<!-- =====================================================
     RESUMEN
     ===================================================== -->

<table>
<tr>
    <th colspan="2">RESUMEN</th>
</tr>
<tr>
    <td>Total solicitudes</td>
    <?php echo celda_numero(array_sum($datos['por_estado'])); ?>
</tr>
<tr>
    <td>Pendientes</td>
    <?php echo celda_numero($datos['por_estado']['pendiente']); ?>
</tr>
<tr>
    <td>En proceso</td>
    <?php echo celda_numero($datos['por_estado']['en_proceso']); ?>
</tr>
<tr>
    <td>Atendidas</td>
    <?php echo celda_numero($datos['por_estado']['atendida']); ?>
</tr>
<tr>
    <td>Rechazadas</td>
    <?php echo celda_numero($datos['por_estado']['rechazada']); ?>
</tr>
<tr>
    <td>Tiempo promedio total (horas)</td>
    <?php echo celda_numero($datos['tiempo_respuesta']['global']); ?>
</tr>
<tr>
    <td>Tiempo hasta iniciar atención (horas)</td>
    <?php echo celda_numero($datos['tiempo_respuesta']['inicio_atencion']); ?>
</tr>
<tr>
    <td>Tiempo promedio en proceso (horas)</td>
    <?php echo celda_numero($datos['tiempo_respuesta']['en_proceso']); ?>
</tr>
<tr>
    <td>Total documentos</td>
    <?php echo celda_numero($datos['total_documentos']); ?>
</tr>
</table>
<br>

<!-- =====================================================
     ESTADOS
     ===================================================== -->

<table>
<tr>
    <th colspan="2">SOLICITUDES POR ESTADO</th>
</tr>
<tr>
    <th>Estado</th>
    <th>Total</th>
</tr>
<?php foreach ($datos['por_estado'] as $estado => $cantidad): ?>
<tr>
    <?php echo celda_texto($estados[$estado]['label'] ?? $estado); ?>
    <?php echo celda_numero($cantidad); ?>
</tr>
<?php endforeach; ?>
</table>
<br>

<!-- =====================================================
     TIPOS
     ===================================================== -->

<table>
<tr>
    <th colspan="2">SOLICITUDES POR TIPO</th>
</tr>
<tr>
    <th>Tipo</th>
    <th>Total</th>
</tr>
<?php foreach ($datos['por_tipo'] as $tipo => $cantidad): ?>
<tr>
    <?php echo celda_texto($tipo); ?>
    <?php echo celda_numero($cantidad); ?>
</tr>
<?php endforeach; ?>
</table>
<br>

<!-- =====================================================
     DEPENDENCIAS
     ===================================================== -->

<table>
<tr>
    <th colspan="2">SOLICITUDES POR DEPENDENCIA</th>
</tr>
<tr>
    <th>Dependencia</th>
    <th>Total</th>
</tr>
<?php foreach ($datos['por_dependencia'] as $row): ?>
<tr>
    <?php echo celda_texto($row['dependencia']); ?>
    <?php echo celda_numero((int)$row['total']); ?>
</tr>
<?php endforeach; ?>
</table>
<br>

<!-- =====================================================
     MÓDULOS
     ===================================================== -->

<table>
<tr>
    <th colspan="2">MÓDULOS CON MÁS SOLICITUDES</th>
</tr>
<tr>
    <th>Módulo</th>
    <th>Total</th>
</tr>
<?php foreach ($datos['por_modulo'] as $row): ?>
<tr>
    <?php echo celda_texto($row['titulo']); ?>
    <?php echo celda_numero((int)$row['total']); ?>
</tr>
<?php endforeach; ?>
</table>
<br>

<!-- =====================================================
     TIEMPO POR TIPO
     ===================================================== -->

<table>
<tr>
    <th colspan="2">TIEMPO PROMEDIO POR TIPO</th>
</tr>
<tr>
    <th>Tipo</th>
    <th>Horas promedio</th>
</tr>
<?php foreach ($datos['tiempo_respuesta']['por_tipo'] as $row): ?>
<tr>
    <?php echo celda_texto($row['tipo']); ?>
    <?php echo celda_numero($row['promedio']); ?>
</tr>
<?php endforeach; ?>
</table>
<br>

<!-- =====================================================
     CHECKLIST
     ===================================================== -->

<table>
<tr>
    <th colspan="4">CUMPLIMIENTO DE CRITERIOS DE CALIDAD DOCUMENTAL</th>
</tr>
<tr>
    <th>Criterio</th>
    <th>Cumple</th>
    <th>Total</th>
    <th>Porcentaje</th>
</tr>
<?php foreach ($datos['cumplimiento_criterios'] as $criterio): ?>
<tr>
    <?php echo celda_texto($criterio['label']); ?>
    <?php echo celda_numero($criterio['cumple']); ?>
    <?php echo celda_numero($criterio['total']); ?>
    <?php echo celda_texto($criterio['porcentaje'] . '%'); ?>
</tr>
<?php endforeach; ?>
</table>
<br>

<!-- =====================================================
     DETALLE
     ===================================================== -->

<table>
<tr>
    <th colspan="14">DETALLE DE SOLICITUDES</th>
</tr>

<tr>
    <th>ID</th>
    <th>Fecha solicitud</th>
    <th>Sección</th>
    <th>Módulo</th>
    <th>Documento</th>
    <th>Solicitante</th>
    <th>Email</th>
    <th>Dependencia</th>
    <th>Tipo</th>
    <th>Estado</th>
    <th>Fecha en proceso</th>
    <th>Fecha respuesta</th>
    <th>Horas respuesta</th>
    <th>Comentario</th>
</tr>

<?php foreach ($datos['listado'] as $s): ?>
<?php $fecha_final = $s['fecha_atencion'] ?? $s['fecha_rechazada'] ?? null; ?>
<tr>
    <?php echo celda_numero((int)$s['id']); ?>
    <?php echo celda_fecha($s['fecha_solicitud']); ?>
    <?php echo celda_texto($s['seccion_titulo'] ?? '—'); ?>
    <?php echo celda_texto($s['modulo_titulo'] ?? '—'); ?>
    <?php echo celda_texto($s['archivo_nombre'] ?? '(archivo eliminado)'); ?>
    <?php echo celda_texto($s['solicitante_nombre'] ?? '—'); ?>
    <?php echo celda_texto($s['solicitante_email'] ?? '—'); ?>
    <?php echo celda_texto($s['dependencia_nombre'] ?? 'Sin dependencia'); ?>
    <?php echo celda_texto($s['tipo_solicitud']); ?>
    <?php echo celda_texto($estados[$s['estado']]['label'] ?? $s['estado']); ?>
    <?php echo celda_fecha($s['fecha_en_proceso']); ?>
    <?php echo celda_fecha($fecha_final); ?>
    <?php echo celda_numero($s['horas_respuesta']); ?>
    <?php echo celda_texto($s['comentario'] ?? ''); ?>
</tr>
<?php endforeach; ?>

</table>

</body>
</html>