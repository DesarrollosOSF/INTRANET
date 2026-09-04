
<?php
require_once '../config/config.php';
requerirPermiso('ver_reportes_formacion');

$pdo = getDBConnection();

$inscripcion_id = (int)($_GET['inscripcion_id'] ?? 0);

if ($inscripcion_id <= 0) {
    http_response_code(400);
    exit('ID de inscripción no válido.');
}

/* ==========================================================
   DATOS DE LA INSCRIPCIÓN
   ========================================================== */
$stmt = $pdo->prepare("
    SELECT 
        fi.*,
        u.nombre_completo,
        u.email,
        d.nombre AS dependencia_nombre,
        fc.nombre AS curso_nombre,
        fc.id AS curso_id
    FROM formacion_inscripciones fi
    JOIN usuarios u 
        ON u.id = fi.usuario_id
    LEFT JOIN dependencias d 
        ON d.id = u.dependencia_id
    JOIN formacion_cursos fc 
        ON fc.id = fi.curso_id
    WHERE fi.id = ?
");

$stmt->execute([$inscripcion_id]);
$insc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$insc) {
    http_response_code(404);
    exit('Inscripción no encontrada.');
}


/* ==========================================================
   TODOS LOS INTENTOS DE EVALUACIÓN
   ========================================================== */
$stmt = $pdo->prepare("
    SELECT 
        ie.*,
        fe.nombre AS evaluacion_nombre,
        fe.puntaje_minimo,
        fm.titulo AS modulo_titulo,
        fm.orden AS modulo_orden
    FROM formacion_intentos_evaluacion ie
    JOIN formacion_evaluaciones fe 
        ON fe.id = ie.evaluacion_id
    LEFT JOIN formacion_modulos fm 
        ON fm.id = fe.modulo_id
    WHERE ie.inscripcion_id = ?
    ORDER BY 
        (fm.orden IS NULL),
        fm.orden,
        ie.evaluacion_id,
        ie.numero_intento
");

$stmt->execute([$inscripcion_id]);
$intentos = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* ==========================================================
   DETALLE DE PREGUNTAS Y RESPUESTAS
   ========================================================== */
$detalle_por_intento = [];

if (!empty($intentos)) {

    $ids = array_column($intentos, 'id');

    $placeholders = implode(
        ',',
        array_fill(0, count($ids), '?')
    );

    $stmt = $pdo->prepare("
        SELECT 
            ru.intento_id,
            p.pregunta,
            p.puntos,
            ru.opcion_id AS opcion_marcada_id,

            op_marcada.texto AS texto_marcado,
            op_marcada.es_correcta AS marcada_es_correcta,

            op_correcta.texto AS texto_correcta

        FROM formacion_respuestas_usuario ru

        JOIN formacion_preguntas p
            ON p.id = ru.pregunta_id

        LEFT JOIN formacion_opciones_respuesta op_marcada
            ON op_marcada.id = ru.opcion_id

        LEFT JOIN formacion_opciones_respuesta op_correcta
            ON op_correcta.pregunta_id = p.id
            AND op_correcta.es_correcta = 1

        WHERE ru.intento_id IN ($placeholders)

        ORDER BY p.orden
    ");

    $stmt->execute($ids);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $detalle) {
        $detalle_por_intento[$detalle['intento_id']][] = $detalle;
    }
}


/* ==========================================================
   PROGRESO DE MATERIALES
   ========================================================== */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM formacion_materiales
    WHERE curso_id = ?
");

$stmt->execute([$insc['curso_id']]);

$total_materiales = (int)$stmt->fetchColumn();


$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM formacion_progreso_material pm

    JOIN formacion_materiales fm
        ON fm.id = pm.material_id

    WHERE pm.inscripcion_id = ?
      AND pm.completado = 1
      AND fm.curso_id = ?
");

$stmt->execute([
    $inscripcion_id,
    $insc['curso_id']
]);

$materiales_vistos = (int)$stmt->fetchColumn();

$pct_materiales = $total_materiales > 0
    ? round(($materiales_vistos / $total_materiales) * 100)
    : 0;


/* ==========================================================
   RESPUESTAS DE ENCUESTA
   ========================================================== */
$stmt = $pdo->prepare("
    SELECT 
        ep.pregunta,
        ep.tipo,
        er.valor_escala,
        er.texto_libre,
        eo.texto AS opcion_texto

    FROM formacion_encuesta_respuestas er

    JOIN formacion_encuesta_preguntas ep
        ON ep.id = er.pregunta_id

    LEFT JOIN formacion_encuesta_opciones eo
        ON eo.id = er.opcion_id

    WHERE er.inscripcion_id = ?

    ORDER BY ep.orden
");

$stmt->execute([$inscripcion_id]);

$respuestas_encuesta = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* ==========================================================
   FUNCIONES DE SEGURIDAD
   ========================================================== */
function e($valor)
{
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/* ==========================================================
   INFORMACIÓN GENERAL
   ========================================================== */
$nombre_colaborador = $insc['nombre_completo'];
$email = $insc['email'];
$dependencia = $insc['dependencia_nombre'] ?: 'Sin asignar';
$curso_nombre = $insc['curso_nombre'];

$estado_curso = $insc['completado']
    ? 'COMPLETADO'
    : 'EN CURSO';

$clase_estado = $insc['completado']
    ? 'estado-completado'
    : 'estado-curso';


/* ==========================================================
   FECHAS
   ========================================================== */
$fecha_inscripcion = !empty($insc['fecha_inscripcion'])
    ? date('d/m/Y', strtotime($insc['fecha_inscripcion']))
    : 'No registrada';

$fecha_completado = !empty($insc['fecha_completado'])
    ? date('d/m/Y H:i', strtotime($insc['fecha_completado']))
    : 'No finalizado';


/* ==========================================================
   GENERAR HTML DE EVALUACIONES
   ========================================================== */
$bloques_evaluacion = '';

foreach ($intentos as $it) {

    $nombre_eval = !empty($it['modulo_titulo'])
        ? 'Quiz: ' . $it['modulo_titulo']
        : ($it['evaluacion_nombre'] ?: 'Evaluación general');

    $puntaje_total = (float)$it['puntaje_total'];
    $puntaje_obtenido = (float)$it['puntaje_obtenido'];

    $pct = $puntaje_total > 0
        ? round(($puntaje_obtenido / $puntaje_total) * 100)
        : 0;

    $aprobado = $it['estado'] === 'aprobado';

    $estado_texto = $aprobado
        ? 'APROBADO'
        : 'REPROBADO';

    $estado_clase = $aprobado
        ? 'resultado-aprobado'
        : 'resultado-reprobado';


    /* ---------- Preguntas ---------- */

    $filas = '';

    foreach (
        ($detalle_por_intento[$it['id']] ?? [])
        as $index => $d
    ) {

        $correcta = (bool)$d['marcada_es_correcta'];

        $respuesta = !empty($d['texto_marcado'])
            ? $d['texto_marcado']
            : 'Sin respuesta';

        $correcta_texto = !empty($d['texto_correcta'])
            ? $d['texto_correcta']
            : 'No disponible';

        $icono = $correcta ? '✓' : '✕';

        $clase_respuesta = $correcta
            ? 'respuesta-correcta'
            : 'respuesta-incorrecta';


        $filas .= '
        <tr>

            <td class="numero-pregunta">
                ' . ($index + 1) . '
            </td>

            <td>
                ' . e($d['pregunta']) . '
            </td>

            <td class="' . $clase_respuesta . '">
                ' . e($respuesta) . '
            </td>

            <td>
                ' . e($correcta_texto) . '
            </td>

            <td class="resultado-icono ' . $clase_respuesta . '">
                ' . $icono . '
            </td>

        </tr>';
    }


    if ($filas === '') {

        $filas = '
        <tr>
            <td colspan="5" class="sin-datos">
                No hay respuestas registradas para este intento.
            </td>
        </tr>';
    }


    /* ---------- Bloque completo ---------- */

    $bloques_evaluacion .= '

    <section class="evaluacion">

        <div class="evaluacion-header">

            <div>
                <h3>
                    ' . e($nombre_eval) . '
                </h3>

                <div class="evaluacion-subtitulo">
                    Intento ' . e($it['numero_intento']) . '
                </div>
            </div>

            <div class="' . $estado_clase . '">
                ' . $estado_texto . '
            </div>

        </div>


        <div class="evaluacion-resumen">

            <div class="dato-evaluacion">
                <span>Puntaje</span>
                <strong>
                    ' . e($puntaje_obtenido) . '
                    /
                    ' . e($puntaje_total) . '
                </strong>
            </div>

            <div class="dato-evaluacion">
                <span>Resultado</span>
                <strong>
                    ' . $pct . '%
                </strong>
            </div>

            <div class="dato-evaluacion">
                <span>Mínimo</span>
                <strong>
                    ' . e($it['puntaje_minimo']) . '%
                </strong>
            </div>

            <div class="dato-evaluacion">
                <span>Fecha</span>
                <strong>
                    ' . (
                        !empty($it['fecha_finalizacion'])
                        ? date(
                            'd/m/Y H:i',
                            strtotime($it['fecha_finalizacion'])
                        )
                        : '—'
                    ) . '
                </strong>
            </div>

        </div>


        <div class="tabla-contenedor">

            <table class="tabla-respuestas">

                <thead>

                    <tr>

                        <th style="width:45px;">#</th>

                        <th>Pregunta</th>

                        <th>Respuesta dada</th>

                        <th>Respuesta correcta</th>

                        <th style="width:70px;">
                            Resultado
                        </th>

                    </tr>

                </thead>

                <tbody>

                    ' . $filas . '

                </tbody>

            </table>

        </div>

    </section>';
}


/* ==========================================================
   SIN EVALUACIONES
   ========================================================== */
if ($bloques_evaluacion === '') {

    $bloques_evaluacion = '

    <div class="sin-contenido">

        <div class="sin-icono">
            ✓
        </div>

        <h3>
            Sin intentos de evaluación
        </h3>

        <p>
            No se encuentran intentos de evaluación
            registrados para esta inscripción.
        </p>

    </div>';
}


/* ==========================================================
   ENCUESTA
   ========================================================== */
$bloque_encuesta = '';

if (!empty($respuestas_encuesta)) {

    $filas_encuesta = '';

    foreach (
        $respuestas_encuesta as $index => $r
    ) {

        if ($r['tipo'] === 'escala_1_5') {

            $valor = !empty($r['valor_escala'])
                ? $r['valor_escala'] . ' / 5'
                : 'Sin respuesta';

        } elseif ($r['tipo'] === 'opcion_multiple') {

            $valor = $r['opcion_texto'] ?: 'Sin respuesta';

        } else {

            $valor = $r['texto_libre'] ?: 'Sin respuesta';
        }


        $filas_encuesta .= '

        <tr>

            <td class="numero-pregunta">
                ' . ($index + 1) . '
            </td>

            <td>
                ' . e($r['pregunta']) . '
            </td>

            <td>
                ' . nl2br(e($valor)) . '
            </td>

        </tr>';
    }


    $bloque_encuesta = '

    <section class="seccion">

        <div class="seccion-titulo">

            <span class="seccion-icono">
                ★
            </span>

            <div>

                <h2>
                    Encuesta de satisfacción
                </h2>

                <p>
                    Respuestas registradas por el colaborador
                </p>

            </div>

        </div>


        <div class="tabla-contenedor">

            <table class="tabla-respuestas">

                <thead>

                    <tr>

                        <th style="width:45px;">#</th>

                        <th>Pregunta</th>

                        <th>Respuesta</th>

                    </tr>

                </thead>

                <tbody>

                    ' . $filas_encuesta . '

                </tbody>

            </table>

        </div>

    </section>';
} else {

    $bloque_encuesta = '

    <section class="seccion">

        <div class="seccion-titulo">

            <span class="seccion-icono">
                ★
            </span>

            <div>

                <h2>
                    Encuesta de satisfacción
                </h2>

                <p>
                    Estado de la encuesta
                </p>

            </div>

        </div>


        <div class="encuesta-no-respondida">

            <strong>
                Encuesta pendiente
            </strong>

            <span>
                El colaborador aún no ha respondido
                la encuesta de satisfacción.
            </span>

        </div>

    </section>';
}


/* ==========================================================
   REGISTRAR LOG
   ========================================================== */
registrarLog(
    $_SESSION['usuario_id'],
    'Visualizar reporte individual (Formación)',
    'Formación',
    "Inscripción ID: $inscripcion_id"
);

?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Reporte - <?php echo e($nombre_colaborador); ?>
    </title>


    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            padding: 30px;

            background: #eef1f5;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color: #252525;

            font-size: 13px;

        }


        /* =====================================================
           CONTENEDOR
           ===================================================== */

        .documento {

            max-width: 1100px;

            margin: 0 auto;

            background: #ffffff;

            padding: 45px;

            box-shadow:
                0 4px 20px
                rgba(0,0,0,.08);

        }


        /* =====================================================
           CABECERA
           ===================================================== */

        .cabecera {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 30px;

            border-bottom:
                3px solid #1a3c6e;

            padding-bottom: 20px;

            margin-bottom: 25px;

        }


        .empresa {

            flex: 1;

        }


        .empresa h1 {

            margin: 0 0 5px;

            font-size: 24px;

            color: #1a3c6e;

        }


        .empresa p {

            margin: 0;

            color: #687384;

            font-size: 12px;

        }


        .reporte-titulo {

            text-align: right;

        }


        .reporte-titulo h2 {

            margin: 0;

            font-size: 18px;

            color: #333;

        }


        .reporte-titulo span {

            display: block;

            margin-top: 5px;

            color: #7a8491;

            font-size: 11px;

        }


        /* =====================================================
           DATOS DEL COLABORADOR
           ===================================================== */

        .perfil {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 12px;

            margin-bottom: 25px;

        }


        .perfil-item {

            border: 1px solid #dfe4ea;

            border-radius: 7px;

            padding: 13px 15px;

            background: #fafbfc;

        }


        .perfil-item span {

            display: block;

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: .5px;

            color: #7c8795;

            margin-bottom: 4px;

        }


        .perfil-item strong {

            font-size: 13px;

            color: #26364a;

        }


        /* =====================================================
           RESUMEN
           ===================================================== */

        .resumen {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 12px;

            margin-bottom: 30px;

        }


        .resumen-card {

            border:
                1px solid #dfe4ea;

            border-radius: 8px;

            padding: 16px;

            text-align: center;

            background: #fff;

        }


        .resumen-card .numero {

            font-size: 24px;

            font-weight: bold;

            color: #1a3c6e;

        }


        .resumen-card .etiqueta {

            display: block;

            margin-top: 4px;

            color: #747f8d;

            font-size: 11px;

        }


        /* =====================================================
           ESTADO
           ===================================================== */

        .estado {

            display: inline-block;

            padding: 5px 12px;

            border-radius: 20px;

            font-size: 10px;

            font-weight: bold;

            letter-spacing: .4px;

        }


        .estado-completado {

            background: #dff4e5;

            color: #19733b;

        }


        .estado-curso {

            background: #fff1d6;

            color: #946200;

        }


        /* =====================================================
           SECCIONES
           ===================================================== */

        .seccion {

            margin-top: 30px;

            page-break-inside: avoid;

        }


        .seccion-titulo {

            display: flex;

            align-items: center;

            gap: 12px;

            border-bottom:
                1px solid #dfe4ea;

            padding-bottom: 10px;

            margin-bottom: 15px;

        }


        .seccion-icono {

            width: 34px;

            height: 34px;

            border-radius: 50%;

            background: #eaf0f8;

            color: #1a3c6e;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: bold;

        }


        .seccion-titulo h2 {

            margin: 0;

            font-size: 16px;

            color: #1a3c6e;

        }


        .seccion-titulo p {

            margin: 3px 0 0;

            font-size: 11px;

            color: #7b8490;

        }


        /* =====================================================
           EVALUACIONES
           ===================================================== */

        .evaluacion {

            border:
                1px solid #dfe4ea;

            border-radius: 8px;

            margin-bottom: 20px;

            overflow: hidden;

            page-break-inside: avoid;

        }


        .evaluacion-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            padding: 15px 18px;

            background: #f6f8fb;

            border-bottom:
                1px solid #dfe4ea;

        }


        .evaluacion-header h3 {

            margin: 0;

            font-size: 14px;

            color: #263b59;

        }


        .evaluacion-subtitulo {

            font-size: 10px;

            color: #7c8795;

            margin-top: 3px;

        }


        .resultado-aprobado,
        .resultado-reprobado {

            padding: 6px 12px;

            border-radius: 15px;

            font-size: 10px;

            font-weight: bold;

            white-space: nowrap;

        }


        .resultado-aprobado {

            background: #dff4e5;

            color: #19733b;

        }


        .resultado-reprobado {

            background: #fde5e5;

            color: #b32626;

        }


        .evaluacion-resumen {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            border-bottom:
                1px solid #e5e8ec;

        }


        .dato-evaluacion {

            padding: 12px;

            text-align: center;

            border-right:
                1px solid #e5e8ec;

        }


        .dato-evaluacion:last-child {

            border-right: 0;

        }


        .dato-evaluacion span {

            display: block;

            font-size: 9px;

            text-transform: uppercase;

            color: #818b98;

            margin-bottom: 3px;

        }


        .dato-evaluacion strong {

            font-size: 12px;

            color: #29394f;

        }


        /* =====================================================
           TABLAS
           ===================================================== */

        .tabla-contenedor {

            overflow-x: auto;

        }


        .tabla-respuestas {

            width: 100%;

            border-collapse: collapse;

            font-size: 11px;

        }


        .tabla-respuestas th {

            background: #1a3c6e;

            color: #fff;

            padding: 9px;

            text-align: left;

            font-size: 10px;

        }


        .tabla-respuestas td {

            padding: 9px;

            border-bottom:
                1px solid #e6e9ed;

            vertical-align: top;

        }


        .tabla-respuestas tr:nth-child(even) {

            background: #fafbfc;

        }


        .numero-pregunta {

            text-align: center;

            font-weight: bold;

            color: #687384;

        }


        .respuesta-correcta {

            color: #19733b;

            font-weight: 500;

        }


        .respuesta-incorrecta {

            color: #b32626;

            font-weight: 500;

        }


        .resultado-icono {

            text-align: center;

            font-size: 15px;

            font-weight: bold;

        }


        .sin-datos {

            text-align: center;

            color: #8b949e;

            padding: 20px !important;

        }


        /* =====================================================
           ENCUESTA
           ===================================================== */

        .encuesta-no-respondida {

            padding: 18px;

            border:
                1px solid #f0d9a7;

            background: #fff8e8;

            border-radius: 7px;

        }


        .encuesta-no-respondida strong {

            display: block;

            color: #8a6300;

            margin-bottom: 4px;

        }


        .encuesta-no-respondida span {

            color: #756a52;

            font-size: 11px;

        }


        /* =====================================================
           SIN CONTENIDO
           ===================================================== */

        .sin-contenido {

            text-align: center;

            border:
                1px dashed #cfd6df;

            padding: 35px;

            border-radius: 8px;

            color: #7b8490;

        }


        .sin-icono {

            width: 45px;

            height: 45px;

            border-radius: 50%;

            background: #edf2f7;

            display: flex;

            align-items: center;

            justify-content: center;

            margin: 0 auto 10px;

            color: #1a3c6e;

            font-weight: bold;

            font-size: 20px;

        }


        .sin-contenido h3 {

            margin: 0 0 5px;

            color: #465568;

            font-size: 14px;

        }


        .sin-contenido p {

            margin: 0;

            font-size: 11px;

        }


        /* =====================================================
           PIE DEL DOCUMENTO
           ===================================================== */

        .pie {

            margin-top: 40px;

            padding-top: 15px;

            border-top:
                1px solid #dfe4ea;

            display: flex;

            justify-content: space-between;

            font-size: 10px;

            color: #858e99;

        }


        /* =====================================================
           BOTONES DE PANTALLA
           ===================================================== */

        .acciones {

            max-width: 1100px;

            margin: 0 auto 15px;

            display: flex;

            justify-content: flex-end;

            gap: 8px;

        }


        .btn {

            border: 0;

            border-radius: 6px;

            padding: 9px 15px;

            cursor: pointer;

            font-size: 12px;

            text-decoration: none;

            display: inline-block;

        }


        .btn-imprimir {

            background: #1a3c6e;

            color: white;

        }


        .btn-volver {

            background: #ffffff;

            color: #374151;

            border: 1px solid #d1d5db;

        }


        /* =====================================================
           IMPRESIÓN
           ===================================================== */

        @media print {

            @page {

                size: Letter portrait;

                margin: 12mm;

            }


            body {

                background: #ffffff;

                padding: 0;

            }


            .acciones {

                display: none !important;

            }


            .documento {

                max-width: none;

                width: 100%;

                box-shadow: none;

                padding: 0;

            }


            .evaluacion {

                page-break-inside: avoid;

            }


            .seccion {

                page-break-inside: avoid;

            }


            .tabla-respuestas {

                page-break-inside: auto;

            }


            .tabla-respuestas tr {

                page-break-inside: avoid;

                page-break-after: auto;

            }


            .cabecera {

                margin-top: 0;

            }

        }


        /* =====================================================
           RESPONSIVE
           ===================================================== */

        @media (max-width: 768px) {

            body {

                padding: 10px;

            }


            .documento {

                padding: 20px;

            }


            .cabecera {

                flex-direction: column;

            }


            .reporte-titulo {

                text-align: left;

            }


            .perfil {

                grid-template-columns: 1fr;

            }


            .resumen {

                grid-template-columns:
                    repeat(2, 1fr);

            }


            .evaluacion-resumen {

                grid-template-columns:
                    repeat(2, 1fr);

            }


            .dato-evaluacion {

                border-bottom:
                    1px solid #e5e8ec;

            }

        }

    </style>

</head>


<body>


<!-- ========================================================
     BOTONES
     ======================================================== -->

<div class="acciones">

    <a
        href="javascript:history.back()"
        class="btn btn-volver"
    >
        ← Volver
    </a>


    <button
        type="button"
        class="btn btn-imprimir"
        onclick="window.print()"
    >
        🖨 Imprimir / Guardar PDF
    </button>

</div>


<!-- ========================================================
     DOCUMENTO
     ======================================================== -->

<div class="documento">


    <!-- CABECERA -->

    <header class="cabecera">

        <div class="empresa">

            <h1>
                Organización San Francisco
            </h1>

            <p>
                Sistema de Formación
            </p>

        </div>


        <div class="reporte-titulo">

            <h2>
                Reporte individual
            </h2>

            <span>
                Formación y evaluación
            </span>

        </div>

    </header>


    <!-- INFORMACIÓN DEL COLABORADOR -->

    <section>

        <div class="perfil">

            <div class="perfil-item">

                <span>
                    Colaborador
                </span>

                <strong>
                    <?php echo e($nombre_colaborador); ?>
                </strong>

            </div>


            <div class="perfil-item">

                <span>
                    Correo electrónico
                </span>

                <strong>
                    <?php echo e($email); ?>
                </strong>

            </div>


            <div class="perfil-item">

                <span>
                    Dependencia
                </span>

                <strong>
                    <?php echo e($dependencia); ?>
                </strong>

            </div>


            <div class="perfil-item">

                <span>
                    Curso
                </span>

                <strong>
                    <?php echo e($curso_nombre); ?>
                </strong>

            </div>

        </div>

    </section>


    <!-- RESUMEN -->

    <section class="resumen">

        <div class="resumen-card">

            <div class="numero">

                <span class="estado <?php echo $clase_estado; ?>">

                    <?php echo $estado_curso; ?>

                </span>

            </div>

            <span class="etiqueta">
                Estado del curso
            </span>

        </div>


        <div class="resumen-card">

            <div class="numero">
                <?php echo $materiales_vistos; ?>
                /
                <?php echo $total_materiales; ?>
            </div>

            <span class="etiqueta">
                Materiales completados
            </span>

        </div>


        <div class="resumen-card">

            <div class="numero">
                <?php echo $pct_materiales; ?>%
            </div>

            <span class="etiqueta">
                Progreso de materiales
            </span>

        </div>


        <div class="resumen-card">

            <div class="numero">
                <?php echo count($intentos); ?>
            </div>

            <span class="etiqueta">
                Intentos realizados
            </span>

        </div>

    </section>


    <!-- FECHAS -->

    <section class="seccion">

        <div class="seccion-titulo">

            <span class="seccion-icono">
                📅
            </span>

            <div>

                <h2>
                    Información del curso
                </h2>

                <p>
                    Fechas y estado de la inscripción
                </p>

            </div>

        </div>


        <div class="perfil">

            <div class="perfil-item">

                <span>
                    Fecha de inscripción
                </span>

                <strong>
                    <?php echo e($fecha_inscripcion); ?>
                </strong>

            </div>


            <div class="perfil-item">

                <span>
                    Fecha de finalización
                </span>

                <strong>
                    <?php echo e($fecha_completado); ?>
                </strong>

            </div>

        </div>

    </section>


    <!-- EVALUACIONES -->

    <section class="seccion">

        <div class="seccion-titulo">

            <span class="seccion-icono">
                ✓
            </span>

            <div>

                <h2>
                    Evaluaciones
                </h2>

                <p>
                    Historial detallado de intentos y respuestas
                </p>

            </div>

        </div>


        <?php echo $bloques_evaluacion; ?>

    </section>


    <!-- ENCUESTA -->

    <?php echo $bloque_encuesta; ?>


    <!-- PIE -->

    <footer class="pie">

        <span>
            Organización San Francisco · Sistema de Formación
        </span>

        <span>
            Generado el
            <?php echo date('d/m/Y H:i'); ?>
        </span>

    </footer>


</div>


<script>

/*
 * Al imprimir desde Chrome o Edge:
 *
 * 1. Presiona "Imprimir / Guardar PDF".
 * 2. Selecciona "Guardar como PDF".
 * 3. Elige la ubicación.
 *
 * También puedes utilizar Ctrl + P.
 */

</script>


</body>

</html>
