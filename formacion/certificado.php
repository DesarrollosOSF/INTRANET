<?php

require_once __DIR__ . '/../config/config.php';
requerirPermiso('ver_formacion');
$pdo = getDBConnection();

$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$inscripcion_id = (int)($_GET['inscripcion_id'] ?? 0);


if ($usuario_id <= 0) {
    http_response_code(401);
    exit('Sesión no válida.');
}

/*
|--------------------------------------------------------------------------
| Validar inscripción
|--------------------------------------------------------------------------
*/

if ($inscripcion_id <= 0) {
    http_response_code(400);
    exit('Inscripción no válida.');
}


/*
|--------------------------------------------------------------------------
| Obtener información del certificado
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        fi.fecha_completado,
        fc.nombre AS curso_nombre,
        fc.tipo_entidad,
        fc.entidad_otro,
        u.nombre_completo,
        d.nombre AS dependencia_nombre

    FROM formacion_inscripciones fi

    INNER JOIN formacion_cursos fc
        ON fc.id = fi.curso_id

    INNER JOIN usuarios u
        ON u.id = fi.usuario_id

    LEFT JOIN dependencias d
        ON d.id = u.dependencia_id

    WHERE fi.id = ?
      AND fi.usuario_id = ?
      AND fi.completado = 1

    LIMIT 1
");

$stmt->execute([
    $inscripcion_id,
    $usuario_id
]);

$datos = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Verificar que el certificado exista
|--------------------------------------------------------------------------
*/

if (!$datos) {
    http_response_code(404);

    exit(
        'Certificado no disponible: ' .
        'el curso no está completado o el certificado no te pertenece.'
    );
}


/*
|--------------------------------------------------------------------------
| Entidad que dictó el curso
|--------------------------------------------------------------------------
*/

$etiquetas_entidad = [
    'osf'      => 'Organización San Francisco',
    'sena'     => 'SENA',
    'comfaboy' => 'Comfaboy',
    'positiva' => 'ARL Positiva',
    'otro'     => !empty($datos['entidad_otro'])
                    ? $datos['entidad_otro']
                    : 'Entidad externa'
];

$tipo_entidad = strtolower(
    trim((string)($datos['tipo_entidad'] ?? ''))
);

$entidad_nombre = $etiquetas_entidad[$tipo_entidad]
    ?? 'Organización San Francisco';


/*
|--------------------------------------------------------------------------
| Fecha de finalización
|--------------------------------------------------------------------------
*/

if (!empty($datos['fecha_completado'])) {

    $timestamp = strtotime($datos['fecha_completado']);

    if ($timestamp !== false) {

        $meses = [
            1  => 'enero',
            2  => 'febrero',
            3  => 'marzo',
            4  => 'abril',
            5  => 'mayo',
            6  => 'junio',
            7  => 'julio',
            8  => 'agosto',
            9  => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre'
        ];

        $dia = date('d', $timestamp);
        $mes = $meses[(int)date('m', $timestamp)];
        $anio = date('Y', $timestamp);

        $fecha_completado =
            $dia . ' de ' . $mes . ' de ' . $anio;

    } else {

        $fecha_completado = 'Fecha no disponible';

    }

} else {

    $fecha_completado = 'Fecha no disponible';

}


/*
|--------------------------------------------------------------------------
| Código único del certificado
|--------------------------------------------------------------------------
*/

$codigo = 'OSF-' . str_pad(
    (string)$inscripcion_id,
    6,
    '0',
    STR_PAD_LEFT
);


/*
|--------------------------------------------------------------------------
| Protección de datos para HTML
|--------------------------------------------------------------------------
*/

$nombre_usuario = htmlspecialchars(
    (string)$datos['nombre_completo'],
    ENT_QUOTES,
    'UTF-8'
);

$dependencia = htmlspecialchars(
    !empty($datos['dependencia_nombre'])
        ? $datos['dependencia_nombre']
        : 'Sin dependencia asignada',
    ENT_QUOTES,
    'UTF-8'
);

$curso_nombre = htmlspecialchars(
    (string)$datos['curso_nombre'],
    ENT_QUOTES,
    'UTF-8'
);

$entidad_nombre_html = htmlspecialchars(
    (string)$entidad_nombre,
    ENT_QUOTES,
    'UTF-8'
);

$codigo_html = htmlspecialchars(
    $codigo,
    ENT_QUOTES,
    'UTF-8'
);


/*
|--------------------------------------------------------------------------
| Registrar actividad
|--------------------------------------------------------------------------
*/

if (function_exists('registrarLog')) {

    registrarLog(
        $usuario_id,
        'Visualizar certificado de formación',
        'Formación',
        "Inscripción ID: $inscripcion_id"
    );
}


/*
|--------------------------------------------------------------------------
| HTML DEL CERTIFICADO
|--------------------------------------------------------------------------
*/

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
        Certificado - <?= $curso_nombre ?>
    </title>


    <style>

        /*
        |--------------------------------------------------------------------------
        | Configuración general
        |--------------------------------------------------------------------------
        */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #eeeeee;

            color: #2b2b2b;
        }


        /*
        |--------------------------------------------------------------------------
        | Barra superior
        |--------------------------------------------------------------------------
        */

        .acciones {

            width: 100%;

            padding: 20px;

            background: #ffffff;

            border-bottom:
                1px solid #dddddd;

            text-align: center;
        }


        .btn {

            display: inline-block;

            padding:
                12px 24px;

            border: none;

            border-radius: 6px;

            background: #1a3c6e;

            color: #ffffff;

            font-size: 14px;

            font-weight: bold;

            cursor: pointer;

            transition:
                background 0.2s ease;
        }


        .btn:hover {

            background: #142f57;
        }


        /*
        |--------------------------------------------------------------------------
        | Contenedor del certificado
        |--------------------------------------------------------------------------
        */

        .certificado {

            width: 11in;

            min-height: 8.5in;

            margin:
                30px auto;

            padding: 50px;

            background: #ffffff;

            box-shadow:
                0 5px 25px
                rgba(0, 0, 0, 0.15);
        }


        /*
        |--------------------------------------------------------------------------
        | Marco exterior
        |--------------------------------------------------------------------------
        */

        .marco {

            width: 100%;

            min-height:
                7.1in;

            padding: 35px;

            border:
                3px solid #1a3c6e;
        }


        /*
        |--------------------------------------------------------------------------
        | Marco interior
        |--------------------------------------------------------------------------
        */

        .marco-interno {

            min-height:
                6.5in;

            padding: 40px;

            border:
                1px solid #b7c4d9;

            text-align: center;
        }


        /*
        |--------------------------------------------------------------------------
        | Encabezado
        |--------------------------------------------------------------------------
        */

        .eyebrow {

            margin-bottom: 8px;

            color: #777777;

            font-size: 12px;

            font-weight: normal;

            letter-spacing: 5px;

            text-transform: uppercase;
        }


        h1 {

            margin:
                0 0 28px;

            color: #1a3c6e;

            font-size: 28px;
        }


        /*
        |--------------------------------------------------------------------------
        | Textos
        |--------------------------------------------------------------------------
        */

        .intro {

            margin:
                10px 0;

            color: #555555;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | Nombre del usuario
        |--------------------------------------------------------------------------
        */

        .nombre {

            display: inline-block;

            margin:
                18px 0 10px;

            padding-bottom: 7px;

            border-bottom:
                2px solid #1a3c6e;

            color: #1a3c6e;

            font-size: 26px;

            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | Dependencia
        |--------------------------------------------------------------------------
        */

        .dependencia {

            margin:
                0 0 20px;

            color: #666666;

            font-size: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | Curso
        |--------------------------------------------------------------------------
        */

        .curso-label {

            margin-top: 15px;

            color: #777777;

            font-size: 12px;

            text-transform: uppercase;
        }


        .curso-nombre {

            margin:
                8px 0 18px;

            color: #2b2b2b;

            font-size: 21px;

            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | Entidad
        |--------------------------------------------------------------------------
        */

        .entidad {

            margin:
                10px 0;

            color: #444444;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | Fecha
        |--------------------------------------------------------------------------
        */

        .fecha {

            margin-top: 10px;

            color: #555555;

            font-size: 13px;
        }


        /*
        |--------------------------------------------------------------------------
        | Firmas
        |--------------------------------------------------------------------------
        */

        .footer {

            width: 100%;

            margin-top: 50px;

            display: table;

            table-layout: fixed;
        }


        .footer-cell {

            width: 50%;

            display: table-cell;

            text-align: center;

            color: #555555;

            font-size: 11px;
        }


        .firma-linea {

            width: 220px;

            margin:
                35px auto 6px;

            border-top:
                1px solid #999999;
        }


        /*
        |--------------------------------------------------------------------------
        | Código
        |--------------------------------------------------------------------------
        */

        .codigo {

            margin-top: 15px;

            text-align: right;

            color: #999999;

            font-size: 9px;
        }


        /*
        |--------------------------------------------------------------------------
        | Adaptación para pantallas pequeñas
        |--------------------------------------------------------------------------
        */

        @media screen and (max-width: 1200px) {

            .certificado {

                width: 95%;

                min-height: auto;

                padding: 25px;
            }

            .marco {

                min-height: auto;

                padding: 25px;
            }

            .marco-interno {

                min-height: auto;

                padding: 25px;
            }

        }


        /*
        |--------------------------------------------------------------------------
        | Impresión
        |--------------------------------------------------------------------------
        */

        @media print {

            @page {

                size: letter landscape;

                margin: 0;
            }


            html,
            body {

                width: 11in;

                height: 8.5in;

                margin: 0;

                padding: 0;

                background: #ffffff;
            }


            .acciones {

                display: none !important;
            }


            .certificado {

                width: 11in;

                height: 8.5in;

                min-height: 8.5in;

                margin: 0;

                padding: 40px;

                box-shadow: none;
            }


            .marco {

                min-height:
                    7.5in;

                padding: 30px;
            }


            .marco-interno {

                min-height:
                    6.9in;

                padding: 35px;
            }


            .nombre {

                color: #1a3c6e;
            }


            .codigo {

                color: #777777;
            }

        }

    </style>

</head>


<body>


    <!--
    |--------------------------------------------------------------------------
    | Botón de impresión
    |--------------------------------------------------------------------------
    -->

    <div class="acciones">

        <button
            type="button"
            class="btn"
            onclick="window.print()"
        >
            🖨️ Imprimir / Guardar como PDF
        </button>

    </div>


    <!--
    |--------------------------------------------------------------------------
    | Certificado
    |--------------------------------------------------------------------------
    -->

    <div class="certificado">

        <div class="marco">

            <div class="marco-interno">


                <!-- Encabezado -->

                <div class="eyebrow">
                    Certificado de asistencia
                </div>


                <h1>
                    Organización San Francisco
                </h1>


                <!-- Usuario -->

                <p class="intro">
                    Se certifica que
                </p>


                <div class="nombre">
                    <?= $nombre_usuario ?>
                </div>


                <!-- Dependencia -->

                <p class="dependencia">
                    <?= $dependencia ?>
                </p>


                <!-- Curso -->

                <p class="intro">
                    completó satisfactoriamente el curso de formación
                </p>


                <div class="curso-label">
                    Curso
                </div>


                <div class="curso-nombre">
                    <?= $curso_nombre ?>
                </div>


                <!-- Entidad -->

                <p class="entidad">
                    Dictado por
                    <strong>
                        <?= $entidad_nombre_html ?>
                    </strong>
                </p>


                <!-- Fecha -->

                <p class="fecha">
                    Fecha de finalización:
                    <?= htmlspecialchars(
                        $fecha_completado,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>


                <!-- Firmas -->

                <div class="footer">


                    <div class="footer-cell">

                        <div class="firma-linea"></div>

                        Dirección de Talento Humano

                    </div>


                    <div class="footer-cell">

                        <div class="firma-linea"></div>

                        Sistema Integrado de Gestión

                    </div>


                </div>


                <!-- Código -->

                <div class="codigo">
                    <?= $codigo_html ?>
                </div>


            </div>

        </div>

    </div>


</body>

</html>
```
