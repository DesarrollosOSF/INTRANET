<?php
require_once '../config/config.php';

requerirPermiso('ver_reportes_formacion');

$pdo = getDBConnection();

$intento_id = (int)($_GET['intento_id'] ?? 0);

if ($intento_id <= 0) {
    http_response_code(400);
    exit('ID de intento no válido.');
}

/* =========================================================
   INFORMACIÓN GENERAL DEL INTENTO
   ========================================================= */

$stmt = $pdo->prepare("
    SELECT
        ie.*,
        fe.nombre AS evaluacion_nombre,
        fe.puntaje_minimo,
        fm.titulo AS modulo_titulo,
        u.nombre_completo,
        u.email,
        d.nombre AS dependencia_nombre,
        fc.nombre AS curso_nombre
    FROM formacion_intentos_evaluacion ie

    INNER JOIN formacion_evaluaciones fe
        ON fe.id = ie.evaluacion_id

    LEFT JOIN formacion_modulos fm
        ON fm.id = fe.modulo_id

    INNER JOIN formacion_inscripciones fi
        ON fi.id = ie.inscripcion_id

    INNER JOIN formacion_cursos fc
        ON fc.id = fi.curso_id

    INNER JOIN usuarios u
        ON u.id = fi.usuario_id

    LEFT JOIN dependencias d
        ON d.id = u.dependencia_id

    WHERE ie.id = ?
    LIMIT 1
");

$stmt->execute([$intento_id]);

$intento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$intento) {
    http_response_code(404);
    exit('Intento no encontrado.');
}

/* =========================================================
   DETALLE DE RESPUESTAS
   ========================================================= */

$stmt = $pdo->prepare("
    SELECT
        p.id AS pregunta_id,
        p.pregunta,
        p.puntos,

        ru.opcion_id AS opcion_marcada_id,

        op_marcada.texto AS texto_marcado,
        op_marcada.es_correcta AS marcada_es_correcta,

        op_correcta.texto AS texto_correcta

    FROM formacion_respuestas_usuario ru

    INNER JOIN formacion_preguntas p
        ON p.id = ru.pregunta_id

    LEFT JOIN formacion_opciones_respuesta op_marcada
        ON op_marcada.id = ru.opcion_id

    LEFT JOIN formacion_opciones_respuesta op_correcta
        ON op_correcta.pregunta_id = p.id
        AND op_correcta.es_correcta = 1

    WHERE ru.intento_id = ?

    ORDER BY p.orden ASC
");

$stmt->execute([$intento_id]);

$detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   CÁLCULOS
   ========================================================= */

$puntaje_obtenido = (float)($intento['puntaje_obtenido'] ?? 0);
$puntaje_total = (float)($intento['puntaje_total'] ?? 0);
$puntaje_minimo = (float)($intento['puntaje_minimo'] ?? 0);

$porcentaje = $puntaje_total > 0
    ? round(($puntaje_obtenido / $puntaje_total) * 100)
    : 0;

$aprobado = ($intento['estado'] ?? '') === 'aprobado';

$total_preguntas = count($detalle);
$respuestas_correctas = 0;
$respuestas_incorrectas = 0;

foreach ($detalle as $respuesta) {

    if ((int)$respuesta['marcada_es_correcta'] === 1) {
        $respuestas_correctas++;
    } else {
        $respuestas_incorrectas++;
    }
}


/* =========================================================
   DATOS PARA MOSTRAR
   ========================================================= */

if (!empty($intento['modulo_titulo'])) {
    $evaluacion_nombre = 'Quiz: ' . $intento['modulo_titulo'];
} else {
    $evaluacion_nombre = $intento['evaluacion_nombre'] ?? 'Evaluación general';
}

$nombre_colaborador = $intento['nombre_completo'] ?? 'Sin nombre';

$email = $intento['email'] ?? 'Sin correo';

$dependencia = !empty($intento['dependencia_nombre'])
    ? $intento['dependencia_nombre']
    : 'Sin dependencia asignada';

$curso_nombre = $intento['curso_nombre'] ?? 'Curso no especificado';

$numero_intento = (int)($intento['numero_intento'] ?? 1);

$fecha_finalizacion = !empty($intento['fecha_finalizacion'])
    ? date('d/m/Y H:i', strtotime($intento['fecha_finalizacion']))
    : 'Sin fecha';


/* =========================================================
   REGISTRAR LOG
   ========================================================= */

registrarLog(
    $_SESSION['usuario_id'],
    'Consultar detalle de intento (Formación)',
    'Formación',
    "Intento ID: $intento_id"
);

?>

<!DOCTYPE html>

<html lang="es">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
    Detalle de evaluación - <?php echo htmlspecialchars($nombre_colaborador); ?>
</title>

<style>

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 0;
        background: #f1f3f6;
        color: #2b2b2b;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 14px;
    }

    .contenedor {
        max-width: 1100px;
        margin: 30px auto;
        padding: 0 20px;
    }

    /* =====================================================
       ENCABEZADO
       ===================================================== */

    .encabezado {
        background: #ffffff;
        border-radius: 12px;
        padding: 25px 30px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,.08);
        border-left: 5px solid #1a3c6e;
    }

    .encabezado-superior {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
    }

    .titulo {
        margin: 0;
        color: #1a3c6e;
        font-size: 25px;
    }

    .subtitulo {
        margin-top: 7px;
        color: #666;
        font-size: 14px;
    }

    .estado {
        padding: 9px 16px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: bold;
        white-space: nowrap;
    }

    .estado-aprobado {
        background: #dff4e7;
        color: #176b38;
    }

    .estado-reprobado {
        background: #fde3e3;
        color: #a32020;
    }

    /* =====================================================
       BOTONES
       ===================================================== */

    .acciones {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .btn {
        border: none;
        border-radius: 7px;
        padding: 10px 17px;
        font-size: 14px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .btn-imprimir {
        background: #1a3c6e;
        color: white;
    }

    .btn-imprimir:hover {
        background: #122d52;
    }

    .btn-volver {
        background: #ffffff;
        color: #444;
        border: 1px solid #ccc;
    }

    .btn-volver:hover {
        background: #f5f5f5;
    }

    /* =====================================================
       INFORMACIÓN
       ===================================================== */

    .tarjeta {
        background: #ffffff;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,.07);
        overflow: hidden;
    }

    .tarjeta-header {
        padding: 15px 20px;
        background: #f7f8fa;
        border-bottom: 1px solid #e5e7eb;
        font-weight: bold;
        color: #1a3c6e;
    }

    .tarjeta-body {
        padding: 20px;
    }

    .datos-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 18px 30px;
    }

    .dato-label {
        font-size: 11px;
        text-transform: uppercase;
        color: #888;
        margin-bottom: 4px;
        letter-spacing: .5px;
    }

    .dato-valor {
        font-size: 14px;
        font-weight: 600;
        color: #333;
    }

    /* =====================================================
       RESUMEN
       ===================================================== */

    .resumen-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
    }

    .estadistica {
        text-align: center;
        border: 1px solid #e2e5e9;
        border-radius: 10px;
        padding: 18px 10px;
        background: #fafbfc;
    }

    .estadistica-numero {
        font-size: 25px;
        font-weight: bold;
        color: #1a3c6e;
    }

    .estadistica-label {
        font-size: 11px;
        color: #777;
        margin-top: 5px;
    }

    .barra-contenedor {
        width: 100%;
        height: 12px;
        background: #e9ecef;
        border-radius: 20px;
        overflow: hidden;
        margin-top: 20px;
    }

    .barra {
        height: 100%;
        border-radius: 20px;
    }

    .barra-aprobado {
        background: #198754;
    }

    .barra-reprobado {
        background: #dc3545;
    }

    /* =====================================================
       TABLA
       ===================================================== */

    .tabla-contenedor {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        background: #1a3c6e;
        color: white;
        text-align: left;
        padding: 12px 10px;
        font-size: 12px;
    }

    td {
        padding: 12px 10px;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: top;
        font-size: 13px;
    }

    tbody tr:nth-child(even) {
        background: #fafafa;
    }

    .pregunta {
        font-weight: 600;
        color: #333;
    }

    .respuesta {
        color: #555;
    }

    .sin-respuesta {
        color: #999;
        font-style: italic;
    }

    .correcta {
        color: #198754;
        font-weight: bold;
    }

    .incorrecta {
        color: #dc3545;
        font-weight: bold;
    }

    .resultado-icono {
        text-align: center;
        font-size: 18px;
    }

    .puntos {
        text-align: center;
        white-space: nowrap;
    }

    /* =====================================================
       PIE
       ===================================================== */

    .pie {
        text-align: center;
        color: #888;
        font-size: 11px;
        margin: 25px 0 35px;
    }

    /* =====================================================
       RESPONSIVE
       ===================================================== */

    @media (max-width: 768px) {

        .contenedor {
            margin: 15px auto;
            padding: 0 10px;
        }

        .encabezado-superior {
            flex-direction: column;
        }

        .datos-grid {
            grid-template-columns: 1fr;
        }

        .resumen-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .titulo {
            font-size: 20px;
        }

    }

    /* =====================================================
       IMPRESIÓN / PDF
       ===================================================== */

    @media print {

        @page {
            size: Letter portrait;
            margin: 12mm;
        }

        body {
            background: white;
            font-size: 11px;
        }

        .contenedor {
            max-width: none;
            margin: 0;
            padding: 0;
        }

        .acciones {
            display: none !important;
        }

        .encabezado,
        .tarjeta {
            box-shadow: none;
            border-radius: 0;
        }

        .encabezado {
            border-left: 3px solid #1a3c6e;
        }

        .tarjeta {
            break-inside: avoid;
            margin-bottom: 12px;
        }

        .tarjeta-header {
            background: #f1f3f6 !important;
            color: #1a3c6e !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        th {
            background: #1a3c6e !important;
            color: white !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .estado-aprobado {
            background: #dff4e7 !important;
            color: #176b38 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .estado-reprobado {
            background: #fde3e3 !important;
            color: #a32020 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .barra-aprobado {
            background: #198754 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .barra-reprobado {
            background: #dc3545 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        table {
            page-break-inside: auto;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        thead {
            display: table-header-group;
        }

        .pie {
            margin-top: 20px;
        }

    }

</style>

</head>

<body>

<div class="contenedor">
<!-- =====================================================
     ENCABEZADO
     ===================================================== -->

<div class="encabezado">

    <div class="encabezado-superior">

        <div>

            <h1 class="titulo">
                Detalle de evaluación
            </h1>

            <div class="subtitulo">
                <?php echo htmlspecialchars($evaluacion_nombre); ?>
            </div>

        </div>

        <?php if ($aprobado): ?>

            <div class="estado estado-aprobado">
                ✓ APROBADO
            </div>

        <?php else: ?>

            <div class="estado estado-reprobado">
                ✕ REPROBADO
            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =====================================================
     ACCIONES
     ===================================================== -->

<div class="acciones">

    <button
        type="button"
        class="btn btn-imprimir"
        onclick="window.print();">

        🖨 Imprimir / Guardar como PDF

    </button>

    <button
        type="button"
        class="btn btn-volver"
        onclick="window.history.back();">

        ← Volver

    </button>

</div>


<!-- =====================================================
     INFORMACIÓN DEL COLABORADOR
     ===================================================== -->

<div class="tarjeta">

    <div class="tarjeta-header">
        Información del colaborador
    </div>

    <div class="tarjeta-body">

        <div class="datos-grid">

            <div>

                <div class="dato-label">
                    Nombre completo
                </div>

                <div class="dato-valor">
                    <?php echo htmlspecialchars($nombre_colaborador); ?>
                </div>

            </div>

            <div>

                <div class="dato-label">
                    Correo electrónico
                </div>

                <div class="dato-valor">
                    <?php echo htmlspecialchars($email); ?>
                </div>

            </div>

            <div>

                <div class="dato-label">
                    Dependencia
                </div>

                <div class="dato-valor">
                    <?php echo htmlspecialchars($dependencia); ?>
                </div>

            </div>

            <div>

                <div class="dato-label">
                    Curso
                </div>

                <div class="dato-valor">
                    <?php echo htmlspecialchars($curso_nombre); ?>
                </div>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     INFORMACIÓN DE LA EVALUACIÓN
     ===================================================== -->

<div class="tarjeta">

    <div class="tarjeta-header">
        Información de la evaluación
    </div>

    <div class="tarjeta-body">

        <div class="datos-grid">

            <div>

                <div class="dato-label">
                    Evaluación
                </div>

                <div class="dato-valor">
                    <?php echo htmlspecialchars($evaluacion_nombre); ?>
                </div>

            </div>

            <div>

                <div class="dato-label">
                    Número de intento
                </div>

                <div class="dato-valor">
                    Intento <?php echo $numero_intento; ?>
                </div>

            </div>

            <div>

                <div class="dato-label">
                    Fecha de finalización
                </div>

                <div class="dato-valor">
                    <?php echo htmlspecialchars($fecha_finalizacion); ?>
                </div>

            </div>

            <div>

                <div class="dato-label">
                    Puntaje mínimo requerido
                </div>

                <div class="dato-valor">
                    <?php echo $puntaje_minimo; ?>%
                </div>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     RESUMEN
     ===================================================== -->

<div class="tarjeta">

    <div class="tarjeta-header">
        Resumen del resultado
    </div>

    <div class="tarjeta-body">

        <div class="resumen-grid">

            <div class="estadistica">

                <div class="estadistica-numero">
                    <?php echo $puntaje_obtenido; ?>
                </div>

                <div class="estadistica-label">
                    Puntos obtenidos
                </div>

            </div>

            <div class="estadistica">

                <div class="estadistica-numero">
                    <?php echo $puntaje_total; ?>
                </div>

                <div class="estadistica-label">
                    Puntos totales
                </div>

            </div>

            <div class="estadistica">

                <div class="estadistica-numero">
                    <?php echo $porcentaje; ?>%
                </div>

                <div class="estadistica-label">
                    Porcentaje
                </div>

            </div>

            <div class="estadistica">

                <div class="estadistica-numero">
                    <?php echo $respuestas_correctas; ?>/<?php echo $total_preguntas; ?>
                </div>

                <div class="estadistica-label">
                    Respuestas correctas
                </div>

            </div>

        </div>


        <div class="barra-contenedor">

            <div
                class="barra <?php echo $aprobado ? 'barra-aprobado' : 'barra-reprobado'; ?>"
                style="width: <?php echo min(100, max(0, $porcentaje)); ?>%;">

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     DETALLE DE RESPUESTAS
     ===================================================== -->

<div class="tarjeta">

    <div class="tarjeta-header">
        Detalle de respuestas
    </div>

    <div class="tarjeta-body">

        <?php if (empty($detalle)): ?>

            <p style="color:#777; text-align:center;">
                No se encontraron respuestas registradas para este intento.
            </p>

        <?php else: ?>

            <div class="tabla-contenedor">

                <table>

                    <thead>

                        <tr>

                            <th style="width: 35%;">
                                Pregunta
                            </th>

                            <th style="width: 25%;">
                                Respuesta dada
                            </th>

                            <th style="width: 25%;">
                                Respuesta correcta
                            </th>

                            <th style="width: 8%; text-align:center;">
                                Puntos
                            </th>

                            <th style="width: 7%; text-align:center;">
                                Resultado
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($detalle as $i => $respuesta): ?>

                        <?php

                        $es_correcta =
                            (int)$respuesta['marcada_es_correcta'] === 1;

                        ?>

                        <tr>

                            <td>

                                <div class="pregunta">

                                    <?php echo ($i + 1); ?>.
                                    <?php echo htmlspecialchars($respuesta['pregunta']); ?>

                                </div>

                            </td>


                            <td>

                                <?php if (!empty($respuesta['texto_marcado'])): ?>

                                    <span class="<?php echo $es_correcta ? 'correcta' : 'respuesta'; ?>">

                                        <?php
                                        echo htmlspecialchars(
                                            $respuesta['texto_marcado']
                                        );
                                        ?>

                                    </span>

                                <?php else: ?>

                                    <span class="sin-respuesta">
                                        Sin respuesta
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if (!empty($respuesta['texto_correcta'])): ?>

                                    <span class="correcta">

                                        <?php
                                        echo htmlspecialchars(
                                            $respuesta['texto_correcta']
                                        );
                                        ?>

                                    </span>

                                <?php else: ?>

                                    <span class="sin-respuesta">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td class="puntos">

                                <?php
                                echo $es_correcta
                                    ? '+' . htmlspecialchars($respuesta['puntos'])
                                    : '0';
                                ?>

                            </td>


                            <td class="resultado-icono">

                                <?php if ($es_correcta): ?>

                                    <span class="correcta">
                                        ✓
                                    </span>

                                <?php else: ?>

                                    <span class="incorrecta">
                                        ✕
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =====================================================
     PIE
     ===================================================== -->

<div class="pie">

    Organización San Francisco · Sistema de Formación

    <br>

    Informe generado el
    <?php echo date('d/m/Y H:i'); ?>

    · Intento #<?php echo $intento_id; ?>

</div>

</div>

<script>

/*
 * Atajo de teclado:
 * Ctrl + P abre directamente la ventana de impresión.
 */

document.addEventListener('keydown', function(event) {

    if (event.ctrlKey && event.key.toLowerCase() === 'p') {

        event.preventDefault();

        window.print();

    }

});

</script>

</body>

</html>
