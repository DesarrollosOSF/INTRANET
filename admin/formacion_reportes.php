<?php
require_once '../config/config.php';
requerirPermiso('ver_reportes_formacion');

$pdo = getDBConnection();
$curso_id = (int)($_GET['curso_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM formacion_cursos WHERE id = ?");
$stmt->execute([$curso_id]);
$curso = $stmt->fetch();
if (!$curso) { header('Location: formacion.php'); exit; }

// ==========================================================
// Capturar filtros de la URL y Paginaciones Independientes
// ==========================================================
$filtro_tipo = $_GET['tipo_evaluacion'] ?? 'general';
$busqueda = trim($_GET['buscar'] ?? '');
$por_pagina = 8;

// Paginación 1: Tabla Usuarios de Dependencias
$pagina_dep = max(1, (int)($_GET['pagina_dep'] ?? 1));
$offset_dep = ($pagina_dep - 1) * $por_pagina;

// Paginación 2: Tabla Detalle por Colaborador / Intentos
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($pagina_actual - 1) * $por_pagina;

// ==========================================================
// Exportación nativa a .xls (Sin Librerías)
// ==========================================================
if (isset($_GET['exportar'])) {
    $nombre_archivo = '';
    $encabezados = [];
    $filas = [];

    if ($_GET['exportar'] === 'evaluaciones') {
        $nombre_archivo = 'evaluaciones_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $curso['nombre']) . '.xls';
        $encabezados = ['Usuario', 'Email', 'Dependencia', 'Evaluación', 'Intento', 'Puntaje', 'Total', 'Porcentaje', 'Estado', 'Fecha'];
        
        $sqlCsv = "
            SELECT u.nombre_completo, u.email, d.nombre AS dependencia,
                   COALESCE(fm.titulo, 'Evaluación general') AS evaluacion_nombre,
                   ie.numero_intento, ie.puntaje_obtenido, ie.puntaje_total, ie.estado, ie.fecha_finalizacion
            FROM formacion_intentos_evaluacion ie
            JOIN formacion_inscripciones fi ON fi.id = ie.inscripcion_id
            JOIN usuarios u ON u.id = fi.usuario_id
            LEFT JOIN dependencias d ON d.id = u.dependencia_id
            JOIN formacion_evaluaciones fe ON fe.id = ie.evaluacion_id
            LEFT JOIN formacion_modulos fm ON fm.id = fe.modulo_id
            WHERE fi.curso_id = ?
        ";
        $paramsCsv = [$curso_id];

        if ($filtro_tipo === 'general') {
            $sqlCsv .= " AND fe.modulo_id IS NULL";
        }
        if (!empty($busqueda)) {
            $sqlCsv .= " AND (u.nombre_completo LIKE ? OR d.nombre LIKE ?)";
            $paramsCsv[] = "%$busqueda%";
            $paramsCsv[] = "%$busqueda%";
        }

        $sqlCsv .= " ORDER BY u.nombre_completo, ie.numero_intento";
        $stmt = $pdo->prepare($sqlCsv);
        $stmt->execute($paramsCsv);

        foreach ($stmt->fetchAll() as $r) {
            $pct = $r['puntaje_total'] > 0 ? round(($r['puntaje_obtenido'] / $r['puntaje_total']) * 100) : 0;
            $filas[] = [
                $r['nombre_completo'],
                $r['email'],
                $r['dependencia'] ?? 'Sin asignar',
                $r['evaluacion_nombre'],
                $r['numero_intento'],
                $r['puntaje_obtenido'],
                $r['puntaje_total'],
                $pct . '%',
                ucfirst($r['estado']),
                $r['fecha_finalizacion'] ? date('d/m/Y H:i', strtotime($r['fecha_finalizacion'])) : '—'
            ];
        }

    } elseif ($_GET['exportar'] === 'encuesta') {
        $nombre_archivo = 'encuesta_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $curso['nombre']) . '.xls';
        $encabezados = ['Usuario', 'Email', 'Dependencia', 'Pregunta', 'Respuesta', 'Fecha'];
        
        $stmt = $pdo->prepare("
            SELECT u.nombre_completo, u.email, d.nombre AS dependencia, ep.pregunta, ep.tipo,
                   er.valor_escala, er.texto_libre, eo.texto AS opcion_texto, er.fecha_respuesta
            FROM formacion_encuesta_respuestas er
            JOIN formacion_inscripciones fi ON fi.id = er.inscripcion_id
            JOIN usuarios u ON u.id = fi.usuario_id
            LEFT JOIN dependencias d ON d.id = u.dependencia_id
            JOIN formacion_encuesta_preguntas ep ON ep.id = er.pregunta_id
            LEFT JOIN formacion_encuesta_opciones eo ON eo.id = er.opcion_id
            WHERE fi.curso_id = ?
            ORDER BY u.nombre_completo, ep.orden
        ");
        $stmt->execute([$curso_id]);
        
        foreach ($stmt->fetchAll() as $r) {
            $respuesta = $r['tipo'] === 'escala_1_5' ? $r['valor_escala'] : ($r['tipo'] === 'opcion_multiple' ? $r['opcion_texto'] : $r['texto_libre']);
            $filas[] = [
                $r['nombre_completo'],
                $r['email'],
                $r['dependencia'] ?? 'Sin asignar',
                $r['pregunta'],
                $respuesta,
                $r['fecha_respuesta'] ? date('d/m/Y H:i', strtotime($r['fecha_respuesta'])) : '—'
            ];
        }
    }

    if ($nombre_archivo && !empty($encabezados)) {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="UTF-8"></head>';
        echo '<body>';
        echo '<table border="1">';
        
        // Encabezado con estilo
        echo '<tr style="background-color: #0d6efd; color: #ffffff; font-weight: bold; text-align: center;">';
        foreach ($encabezados as $header) {
            echo '<th style="padding: 5px;">' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';

        // Filas de datos
        foreach ($filas as $fila) {
            echo '<tr>';
            foreach ($fila as $celda) {
                echo '<td style="padding: 5px;">' . htmlspecialchars($celda) . '</td>';
            }
            echo '</tr>';
        }

        echo '</table>';
        echo '</body>';
        echo '</html>';
        exit;
    }
}

$page_title = 'Reportes: ' . $curso['nombre'];
require_once '../includes/header.php';

// ==========================================================
// Estadísticas de evaluaciones (módulo + general)
// ==========================================================
$stmt = $pdo->prepare("
    SELECT 
        fe.id,
        fe.nombre,
        fm.titulo AS modulo_titulo,
        COUNT(DISTINCT fi_eval.id) AS total_presentaron,
        SUM(
            CASE 
                WHEN fi_eval.id IS NOT NULL 
                 AND ie.estado = 'aprobado' 
                THEN 1 
                ELSE 0 
            END
        ) AS total_aprobados,
        AVG(
            CASE 
                WHEN fi_eval.id IS NOT NULL 
                 AND ie.puntaje_total > 0 
                THEN (ie.puntaje_obtenido / ie.puntaje_total) * 100 
                ELSE NULL 
            END
        ) AS promedio_pct
    FROM formacion_evaluaciones fe
    LEFT JOIN formacion_modulos fm 
        ON fm.id = fe.modulo_id
    LEFT JOIN formacion_intentos_evaluacion ie 
        ON ie.evaluacion_id = fe.id
    LEFT JOIN formacion_inscripciones fi_eval 
        ON fi_eval.id = ie.inscripcion_id 
        AND fi_eval.curso_id = ?
    WHERE fe.curso_id = ?
    GROUP BY fe.id, fe.nombre, fm.titulo
");
$stmt->execute([$curso_id, $curso_id]);
$stats_evaluaciones = $stmt->fetchAll();
$stmt->execute([$curso_id, $curso_id]);
$stats_evaluaciones = $stmt->fetchAll();

// ==========================================================
// Encuesta: preguntas + distribución de respuestas
// ==========================================================
$stmt = $pdo->prepare("SELECT id FROM formacion_encuestas WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$encuesta_id = $stmt->fetchColumn();

$preguntas_encuesta = [];
$respuestas_texto_libre = [];
$distribuciones = [];
$total_respuestas_encuesta = 0;

if ($encuesta_id) {
    $stmt = $pdo->prepare("SELECT * FROM formacion_encuesta_preguntas WHERE encuesta_id = ? ORDER BY orden");
    $stmt->execute([$encuesta_id]);
    $preguntas_encuesta = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT inscripcion_id) FROM formacion_encuesta_respuestas er JOIN formacion_encuesta_preguntas ep ON ep.id = er.pregunta_id WHERE ep.encuesta_id = ?");
    $stmt->execute([$encuesta_id]);
    $total_respuestas_encuesta = (int)$stmt->fetchColumn();

    foreach ($preguntas_encuesta as $p) {
        if ($p['tipo'] === 'escala_1_5') {
            $stmt = $pdo->prepare("SELECT er.valor_escala, COUNT(*) AS n
            FROM formacion_encuesta_respuestas er
            INNER JOIN formacion_inscripciones fi ON fi.id = er.inscripcion_id AND fi.curso_id = ?
            WHERE er.pregunta_id = ?
            GROUP BY er.valor_escala");
            $stmt->execute([$curso_id, $p['id']]);
            $conteo = array_fill(1, 5, 0);
            foreach ($stmt->fetchAll() as $r) $conteo[(int)$r['valor_escala']] = (int)$r['n'];
            $distribuciones[$p['id']] = ['labels' => ['1', '2', '3', '4', '5'], 'valores' => array_values($conteo)];
        } elseif ($p['tipo'] === 'opcion_multiple') {
            $stmt = $pdo->prepare("
                SELECT eo.texto, COUNT(er.id) AS n
                FROM formacion_encuesta_opciones eo
                LEFT JOIN formacion_encuesta_respuestas er ON er.opcion_id = eo.id
                LEFT JOIN formacion_inscripciones fi ON fi.id = er.inscripcion_id AND fi.curso_id = ?
                WHERE eo.pregunta_id = ? AND (er.id IS NULL OR fi.id IS NOT NULL)
                GROUP BY eo.id ORDER BY eo.orden
            ");
            $stmt->execute([$curso_id, $p['id']]);
            $labels = []; $valores = [];
            foreach ($stmt->fetchAll() as $r) { $labels[] = $r['texto']; $valores[] = (int)$r['n']; }
            $distribuciones[$p['id']] = ['labels' => $labels, 'valores' => $valores];
        } else {
            $stmt = $pdo->prepare("SELECT er.texto_libre
            FROM formacion_encuesta_respuestas er
            INNER JOIN formacion_inscripciones fi ON fi.id = er.inscripcion_id AND fi.curso_id = ?
            WHERE er.pregunta_id = ? AND er.texto_libre IS NOT NULL AND er.texto_libre != ''
            ORDER BY er.fecha_respuesta DESC");
            $stmt->execute([$curso_id, $p['id']]);
            $respuestas_texto_libre[$p['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// ==========================================================
// INDICADORES GENERALES DEL CURSO
// ==========================================================
// IMPORTANTE:
// Desde que el curso se asigna usuario por usuario, el público
// objetivo del curso ya NO se obtiene desde
// `formacion_curso_dependencias`.
// La fuente de verdad es `formacion_inscripciones`.
//
// Cada fila de formacion_inscripciones representa un usuario
// destinatario del curso.

$total_inscritos_objetivo = 0;
$total_no_inscritos = 0;
$total_completados = 0;
$total_en_progreso = 0;
$total_no_iniciados = 0;
$promedio_progreso = 0;
$usuarios_curso = [];
$total_paginasDep = 0;

// ==========================================================
// USUARIOS DESTINATARIOS DEL CURSO
// ==========================================================
// Se muestran únicamente los usuarios que fueron seleccionados
// al crear/editar el curso y que por tanto tienen una inscripción
// para este curso.

$stmt = $pdo->prepare("
    SELECT
        fi.id AS inscripcion_id,
        fi.usuario_id,
        fi.fecha_inscripcion,
        fi.progreso,
        fi.completado,
        u.nombre_completo,
        u.email,
        d.nombre AS dependencia_nombre
    FROM formacion_inscripciones fi
    INNER JOIN usuarios u ON u.id = fi.usuario_id
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    WHERE fi.curso_id = ?
    ORDER BY
        COALESCE(d.nombre, 'Sin asignar'),
        u.nombre_completo
");
$stmt->execute([$curso_id]);
$todos_usuarios_curso = $stmt->fetchAll();

// Total de usuarios que realmente fueron asignados al curso.
$total_inscritos_objetivo = count($todos_usuarios_curso);

// Como ahora el objetivo se define seleccionando directamente
// a los usuarios, no existen "usuarios objetivo no inscritos".
$total_no_inscritos = 0;

// Paginación de la tabla de destinatarios.
$total_usuarios_objetivo = $total_inscritos_objetivo;
$total_paginasDep = $total_usuarios_objetivo > 0
    ? (int)ceil($total_usuarios_objetivo / $por_pagina)
    : 0;

if ($total_usuarios_objetivo > 0) {
    $usuarios_curso = array_slice(
        $todos_usuarios_curso,
        $offset_dep,
        $por_pagina
    );
}

// Indicadores de avance calculados exclusivamente sobre los
// usuarios seleccionados para este curso.
if ($total_inscritos_objetivo > 0) {
    foreach ($todos_usuarios_curso as $usuario) {
        if ((int)$usuario['completado'] === 1) {
            $total_completados++;
        } elseif ((float)($usuario['progreso'] ?? 0) > 0) {
            $total_en_progreso++;
        } else {
            $total_no_iniciados++;
        }
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(AVG(COALESCE(progreso, 0)), 0)
        FROM formacion_inscripciones
        WHERE curso_id = ?
    ");
    $stmt->execute([$curso_id]);
    $promedio_progreso = (float)$stmt->fetchColumn();
}

// Cobertura: al seleccionar directamente los destinatarios,
// todos los usuarios objetivo ya están inscritos.
$porcentaje_cobertura = $total_inscritos_objetivo > 0 ? 100 : 0;
$porcentaje_finalizacion = $total_inscritos_objetivo > 0
    ? round(($total_completados / $total_inscritos_objetivo) * 100, 1)
    : 0;

// ==========================================================
// INDICADORES POR DEPENDENCIA
// ==========================================================
// La dependencia se utiliza solamente como dato informativo.
// NO determina quién recibe el curso.
//
// Por ejemplo, si se seleccionan 3 personas de una dependencia
// que tiene 100 empleados, aquí deben aparecer 3 como objetivo,
// no 100.
$stats_dependencias = [];

$stmt = $pdo->prepare("
    SELECT
        COALESCE(d.nombre, 'Sin asignar') AS nombre,
        COUNT(DISTINCT fi.usuario_id) AS usuarios_objetivo,
        COUNT(DISTINCT fi.usuario_id) AS inscritos,
        0 AS no_inscritos,
        COUNT(DISTINCT CASE
            WHEN fi.completado = 1 THEN fi.usuario_id
        END) AS completados,
        COUNT(DISTINCT CASE
            WHEN fi.completado = 0
             AND COALESCE(fi.progreso, 0) > 0
            THEN fi.usuario_id
        END) AS en_progreso,
        COUNT(DISTINCT CASE
            WHEN fi.completado = 0
             AND COALESCE(fi.progreso, 0) = 0
            THEN fi.usuario_id
        END) AS no_iniciados,
        COALESCE(AVG(fi.progreso), 0) AS promedio_progreso
    FROM formacion_inscripciones fi
    INNER JOIN usuarios u ON u.id = fi.usuario_id
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    WHERE fi.curso_id = ?
    GROUP BY d.id, d.nombre
    ORDER BY COALESCE(d.nombre, 'Sin asignar')
");
$stmt->execute([$curso_id]);
$stats_dependencias = $stmt->fetchAll();

// ==========================================================
// RESUMEN Y DISTRIBUCIONES
// ==========================================================
$total_aprobados = 0; $total_reprobados = 0; $total_en_proceso_eval = 0; $promedio_evaluaciones = 0; $porcentaje_aprobacion = 0;
$stmt = $pdo->prepare("SELECT
        COUNT(*) AS total_intentos,
        SUM(CASE WHEN ie.estado = 'aprobado' THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN ie.estado = 'reprobado' THEN 1 ELSE 0 END) AS reprobados,
        SUM(CASE WHEN ie.estado = 'en_proceso' THEN 1 ELSE 0 END) AS en_proceso,
        AVG(CASE WHEN ie.puntaje_total > 0 THEN (ie.puntaje_obtenido / ie.puntaje_total) * 100 ELSE NULL END) AS promedio
    FROM formacion_intentos_evaluacion ie
    INNER JOIN formacion_inscripciones fi ON fi.id = ie.inscripcion_id
    WHERE fi.curso_id = ?");
$stmt->execute([$curso_id]);
$resumen_eval = $stmt->fetch() ?: [];
$total_intentos = (int)($resumen_eval['total_intentos'] ?? 0);
$total_aprobados = (int)($resumen_eval['aprobados'] ?? 0);
$total_reprobados = (int)($resumen_eval['reprobados'] ?? 0);
$total_en_proceso_eval = (int)($resumen_eval['en_proceso'] ?? 0);
$promedio_evaluaciones = round((float)($resumen_eval['promedio'] ?? 0), 1);
$porcentaje_aprobacion = $total_intentos > 0 ? round(($total_aprobados / $total_intentos) * 100, 1) : 0;

$distribucion_progreso = [0, 0, 0, 0, 0];
$stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN COALESCE(progreso,0) = 0 THEN 1 ELSE 0 END) AS p0,
        SUM(CASE WHEN COALESCE(progreso,0) > 0 AND COALESCE(progreso,0) < 25 THEN 1 ELSE 0 END) AS p1,
        SUM(CASE WHEN COALESCE(progreso,0) >= 25 AND COALESCE(progreso,0) < 50 THEN 1 ELSE 0 END) AS p2,
        SUM(CASE WHEN COALESCE(progreso,0) >= 50 AND COALESCE(progreso,0) < 75 THEN 1 ELSE 0 END) AS p3,
        SUM(CASE WHEN COALESCE(progreso,0) >= 75 THEN 1 ELSE 0 END) AS p4
    FROM formacion_inscripciones
    WHERE curso_id = ?");
$stmt->execute([$curso_id]);
$dist = $stmt->fetch() ?: [];
$distribucion_progreso = [
    (int)($dist['p0'] ?? 0),
    (int)($dist['p1'] ?? 0),
    (int)($dist['p2'] ?? 0),
    (int)($dist['p3'] ?? 0),
    (int)($dist['p4'] ?? 0)
];

// ==========================================================
// Detalle por usuario / intento (Tabla 2 con Filtros y Paginación)
// ==========================================================
$whereClauses = ["fi.curso_id = ?"];
$queryParams = [$curso_id];

if ($filtro_tipo === 'general') {
    $whereClauses[] = "fe.modulo_id IS NULL";
}

if (!empty($busqueda)) {
    $whereClauses[] = "(u.nombre_completo LIKE ? OR d.nombre LIKE ?)";
    $queryParams[] = "%$busqueda%";
    $queryParams[] = "%$busqueda%";
}

$whereSQL = implode(" AND ", $whereClauses);

$sqlCount = "
    SELECT COUNT(*) 
    FROM formacion_intentos_evaluacion ie
    JOIN formacion_inscripciones fi ON fi.id = ie.inscripcion_id
    JOIN usuarios u ON u.id = fi.usuario_id
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    JOIN formacion_evaluaciones fe ON fe.id = ie.evaluacion_id
    WHERE $whereSQL
";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($queryParams);
$total_registros = (int)$stmtCount->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

$sqlDetalle = "
    SELECT ie.id AS intento_id, ie.numero_intento, ie.puntaje_obtenido, ie.puntaje_total, ie.estado, ie.fecha_finalizacion,
           u.nombre_completo, d.nombre AS dependencia_nombre,
           fi.id AS inscripcion_id,
           COALESCE(fm.titulo, 'Evaluación general') AS evaluacion_nombre
    FROM formacion_intentos_evaluacion ie
    JOIN formacion_inscripciones fi ON fi.id = ie.inscripcion_id
    JOIN usuarios u ON u.id = fi.usuario_id
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    JOIN formacion_evaluaciones fe ON fe.id = ie.evaluacion_id
    LEFT JOIN formacion_modulos fm ON fm.id = fe.modulo_id
    WHERE $whereSQL
    ORDER BY u.nombre_completo, ie.numero_intento
    LIMIT ? OFFSET ?
";

$paramsDetalle = array_merge($queryParams, [$por_pagina, $offset]);
$stmt = $pdo->prepare($sqlDetalle);
foreach ($paramsDetalle as $k => $val) {
    $stmt->bindValue($k + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$detalle_intentos = $stmt->fetchAll();
?>

<div class="container-fluid mt-4 mb-5" style="max-width: 1200px;">
    <!-- NAVEGACIÓN Y TÍTULO -->
    <div class="mb-3">
        <a href="formacion_detalle.php?id=<?php echo $curso_id; ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Volver al curso
        </a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="h3 fw-bold mb-1 text-dark">
                <i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Reportes de Formación
            </h2>
            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($curso['nombre']); ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="?curso_id=<?php echo $curso_id; ?>&exportar=evaluaciones&tipo_evaluacion=<?php echo $filtro_tipo; ?>&buscar=<?php echo urlencode($busqueda); ?>" class="btn btn-sm btn-success d-inline-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-file-earmark-excel"></i> Excel Evaluaciones
            </a>
            <?php if ($encuesta_id): ?>
                <a href="?curso_id=<?php echo $curso_id; ?>&exportar=encuesta" class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-file-earmark-excel"></i> Excel Encuesta
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- TARJETAS INDICADORES (KPIs) -->
    <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-5 g-3 mb-4 text-center">
        <?php
        $indicadores = [
            ['Destinatarios', $total_usuarios_objetivo, 'people-fill', 'primary'],
            ['No iniciados', $total_no_iniciados, 'hourglass-split', 'secondary'],
            ['En progreso', $total_en_progreso, 'arrow-repeat', 'warning'],
            ['Completados', $total_completados, 'trophy-fill', 'success'],
            ['Encuestas', $total_respuestas_encuesta, 'clipboard-check-fill', 'info']
        ];
        foreach ($indicadores as $ind):
        ?>
            <div class="col">
                <div class="card shadow-sm border-0 h-100 py-2">
                    <div class="card-body p-2">
                        <div class="text-<?php echo $ind[3]; ?> mb-1">
                            <i class="bi bi-<?php echo $ind[2]; ?> fs-4"></i>
                        </div>
                        <div class="fs-3 fw-bold text-dark lh-1 mb-1">
                            <?php echo htmlspecialchars((string)$ind[1]); ?>
                        </div>
                        <div class="small text-muted fw-semibold" style="font-size: 0.8rem;">
                            <?php echo htmlspecialchars($ind[0]); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- GRÁFICAS PRINCIPALES -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
                    <i class="bi bi-pie-chart-fill me-2 text-primary"></i>Estado de formación
                </div>
                <div class="card-body">
                    <canvas id="chartEstadoFormacion" height="200"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
                    <i class="bi bi-speedometer2 me-2 text-primary"></i>Distribución del progreso
                </div>
                <div class="card-body">
                    <canvas id="chartProgreso" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- DISTRIBUCIÓN POR DEPENDENCIA -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
            <i class="bi bi-building me-2 text-primary"></i>Distribución de destinatarios por dependencia
        </div>
        <div class="card-body">
            <?php if (!empty($stats_dependencias)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th>Dependencia</th>
                                <th class="text-center">Destinatarios</th>
                                <th class="text-center">Inscritos</th>
                                <th class="text-center">En progreso</th>
                                <th class="text-center">Completados</th>
                                <th class="text-end">Promedio progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats_dependencias as $dep):
                            $obj = (int)$dep['usuarios_objetivo'];
                            $ins = (int)$dep['inscritos'];
                        ?>
                            <tr>
                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($dep['nombre']); ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $obj; ?></span></td>
                                <td class="text-center"><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?php echo $ins; ?></span></td>
                                <td class="text-center"><?php echo (int)$dep['en_progreso']; ?></td>
                                <td class="text-center"><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?php echo (int)$dep['completados']; ?></span></td>
                                <td class="text-end fw-bold"><?php echo round((float)$dep['promedio_progreso'], 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4"><canvas id="chartDependencias" height="100"></canvas></div>
            <?php else: ?>
                <div class="text-center text-muted py-4">No hay usuarios destinatarios asignados a este curso.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- EVALUACIONES: RESUMEN Y RENDIMIENTO -->
    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
                    <i class="bi bi-patch-question-fill me-2 text-primary"></i>Resumen de evaluaciones
                </div>
                <div class="card-body d-flex flex-column justify-content-between">
                    <div class="row text-center g-2 py-2 bg-light rounded-3 mb-3">
                        <div class="col-4">
                            <div class="fs-4 fw-bold text-success"><?php echo $total_aprobados; ?></div>
                            <small class="text-muted fw-semibold">Aprobados</small>
                        </div>
                        <div class="col-4 border-start border-end">
                            <div class="fs-4 fw-bold text-danger"><?php echo $total_reprobados; ?></div>
                            <small class="text-muted fw-semibold">Reprobados</small>
                        </div>
                        <div class="col-4">
                            <div class="fs-4 fw-bold text-warning"><?php echo $total_en_proceso_eval; ?></div>
                            <small class="text-muted fw-semibold">En proceso</small>
                        </div>
                    </div>
                    
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <div class="fs-5 fw-bold text-dark"><?php echo $promedio_evaluaciones; ?>%</div>
                            <small class="text-muted">Promedio general</small>
                        </div>
                        <div class="col-6 border-start">
                            <div class="fs-5 fw-bold text-dark"><?php echo $porcentaje_aprobacion; ?>%</div>
                            <small class="text-muted">Tasa aprobación</small>
                        </div>
                    </div>

                    <div>
                        <canvas id="chartEvaluacionesResumen" height="120"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
                    <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Rendimiento por evaluación
                </div>
                <div class="card-body">
                    <canvas id="chartEvaluaciones" height="210"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLA 1: USUARIOS DESTINATARIOS -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="fw-semibold text-secondary"><i class="bi bi-people-fill me-2 text-primary"></i>Usuarios destinatarios</div>
            <span class="badge bg-secondary-subtle text-secondary border"><?php echo $total_usuarios_objetivo; ?> usuarios</span>
        </div>
        <div class="card-body">
            <?php if (!empty($usuarios_curso)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th>Usuario</th>
                                <th>Dependencia</th>
                                <th>Estado inscripción</th>
                                <th>Fecha inscripción</th>
                                <th style="width: 180px;">Progreso</th>
                                <th>Completado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios_curso as $usuario): ?>
                                <?php $esta_inscrito = !empty($usuario['inscripcion_id']); ?>
                                <tr>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($usuario['dependencia_nombre']); ?></span></td>
                                    <td>
                                        <?php if ($esta_inscrito): ?>
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check-circle me-1"></i> Inscrito</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><i class="bi bi-x-circle me-1"></i> No inscrito</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo !empty($usuario['fecha_inscripcion']) ? date('d/m/Y H:i', strtotime($usuario['fecha_inscripcion'])) : '—'; ?></td>
                                    <td>
                                        <?php if ($esta_inscrito): ?>
                                            <?php $progreso = (float)($usuario['progreso'] ?? 0); ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $progreso; ?>%;"></div>
                                                </div>
                                                <small class="fw-bold text-secondary" style="min-width: 35px;"><?php echo round($progreso); ?>%</small>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$esta_inscrito): ?>
                                            <span class="text-muted">—</span>
                                        <?php elseif ((int)$usuario['completado'] === 1): ?>
                                            <span class="badge bg-success">Sí</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINACIÓN TABLA 1 -->
                <?php if ($total_paginasDep > 1): ?>
                    <?php 
                        $queryDep = $_GET;
                        unset($queryDep['pagina_dep']);
                        $linkDepBase = '?' . http_build_query($queryDep) . '&pagina_dep=';
                    ?>
                    <nav class="d-flex justify-content-between align-items-center mt-3">
                        <span class="small text-muted">Página <?php echo $pagina_dep; ?> de <?php echo $total_paginasDep; ?></span>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $pagina_dep <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $linkDepBase . ($pagina_dep - 1); ?>">Anterior</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_paginasDep; $i++): ?>
                                <li class="page-item <?php echo $i === $pagina_dep ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo $linkDepBase . $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $pagina_dep >= $total_paginasDep ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $linkDepBase . ($pagina_dep + 1); ?>">Siguiente</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center text-muted py-4">No hay usuarios destinatarios asignados a este curso.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TABLA DE RESULTADOS DE EVALUACIÓN -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
            <i class="bi bi-clipboard2-check-fill me-2 text-primary"></i>Resultados por evaluación
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th>Evaluación</th>
                            <th class="text-center">Presentaron</th>
                            <th class="text-center">Aprobados</th>
                            <th class="text-center">% Aprobación</th>
                            <th class="text-end">Promedio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats_evaluaciones as $s): ?>
                        <tr>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($s['modulo_titulo'] ? 'Quiz: ' . $s['modulo_titulo'] : $s['nombre']); ?></td>
                            <td class="text-center"><?php echo (int)$s['total_presentaron']; ?></td>
                            <td class="text-center"><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?php echo (int)$s['total_aprobados']; ?></span></td>
                            <td class="text-center fw-semibold"><?php echo $s['total_presentaron'] > 0 ? round(($s['total_aprobados'] / $s['total_presentaron']) * 100) . '%' : '—'; ?></td>
                            <td class="text-end fw-bold"><?php echo $s['promedio_pct'] !== null ? round($s['promedio_pct']) . '%' : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stats_evaluaciones)): ?>
                            <tr><td colspan="5" class="text-muted text-center py-3">Sin evaluaciones configuradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DETALLE POR USUARIO / INTENTO -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
            <i class="bi bi-person-bounding-box me-2 text-primary"></i>Detalle por colaborador
        </div>
        <div class="card-body">
            <!-- FILTROS -->
            <form method="GET" class="row g-2 mb-3 align-items-center">
                <input type="hidden" name="curso_id" value="<?php echo $curso_id; ?>">
                
                <div class="col-md-4">
                    <select name="tipo_evaluacion" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="general" <?php echo $filtro_tipo === 'general' ? 'selected' : ''; ?>>Solo Evaluación General</option>
                        <option value="todas" <?php echo $filtro_tipo === 'todas' ? 'selected' : ''; ?>>Todas las Evaluaciones</option>
                    </select>
                </div>
                
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control" placeholder="Buscar colaborador o dependencia..." value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    </div>
                </div>

                <?php if (!empty($busqueda) || $filtro_tipo !== 'general'): ?>
                    <div class="col-md-3 text-end">
                        <a href="?curso_id=<?php echo $curso_id; ?>" class="btn btn-sm btn-light text-danger border">
                            <i class="bi bi-x-circle me-1"></i>Limpiar filtros
                        </a>
                    </div>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th>Usuario</th>
                            <th>Dependencia</th>
                            <th>Evaluación</th>
                            <th class="text-center">Intento</th>
                            <th class="text-center">Puntaje</th>
                            <th class="text-center">Porcentaje</th>
                            <th class="text-center">Estado</th>
                            <th>Fecha</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalle_intentos as $r): ?>
                        <?php $pct = $r['puntaje_total'] > 0 ? round(($r['puntaje_obtenido'] / $r['puntaje_total']) * 100) : 0; ?>
                        <tr>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($r['nombre_completo']); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($r['dependencia_nombre'] ?? 'Sin asignar'); ?></td>
                            <td><span class="small"><?php echo htmlspecialchars($r['evaluacion_nombre']); ?></span></td>
                            <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $r['numero_intento']; ?></span></td>
                            <td class="text-center small"><?php echo $r['puntaje_obtenido']; ?> / <?php echo $r['puntaje_total']; ?></td>
                            <td class="text-center fw-bold"><?php echo $pct; ?>%</td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $r['estado'] === 'aprobado' ? 'success' : 'danger'; ?>-subtle text-<?php echo $r['estado'] === 'aprobado' ? 'success' : 'danger'; ?>-emphasis border border-<?php echo $r['estado'] === 'aprobado' ? 'success' : 'danger'; ?>-subtle">
                                    <?php echo $r['estado'] === 'aprobado' ? 'Aprobado' : 'Reprobado'; ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?php echo $r['fecha_finalizacion'] ? date('d/m/Y H:i', strtotime($r['fecha_finalizacion'])) : '—'; ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="formacion_export_intento.php?intento_id=<?php echo $r['intento_id']; ?>" target="_blank"
                                       class="btn btn-outline-primary" title="Ver preguntas y respuestas de este intento">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </a>
                                    <a href="formacion_reporte_usuario.php?inscripcion_id=<?php echo $r['inscripcion_id']; ?>" target="_blank"
                                       class="btn btn-outline-secondary" title="Ver reporte individual completo">
                                        <i class="bi bi-person-lines-fill"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($detalle_intentos)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No se encontraron registros con los criterios ingresados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINACIÓN -->
            <?php if ($total_paginas > 1): ?>
                <nav class="d-flex justify-content-between align-items-center mt-3">
                    <span class="small text-muted">Mostrando página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> (<?php echo $total_registros; ?> registros)</span>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?curso_id=<?php echo $curso_id; ?>&tipo_evaluacion=<?php echo $filtro_tipo; ?>&buscar=<?php echo urlencode($busqueda); ?>&pagina=<?php echo $pagina_actual - 1; ?>">Anterior</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                            <li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
                                <a class="page-link" href="?curso_id=<?php echo $curso_id; ?>&tipo_evaluacion=<?php echo $filtro_tipo; ?>&buscar=<?php echo urlencode($busqueda); ?>&pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?curso_id=<?php echo $curso_id; ?>&tipo_evaluacion=<?php echo $filtro_tipo; ?>&buscar=<?php echo urlencode($busqueda); ?>&pagina=<?php echo $pagina_actual + 1; ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

            <div class="mt-3 pt-2 border-top d-flex gap-3 small text-muted">
                <span><i class="bi bi-file-earmark-text text-primary me-1"></i> Intento puntual</span>
                <span><i class="bi bi-person-lines-fill text-secondary me-1"></i> Reporte completo del colaborador</span>
            </div>
        </div>
    </div>

    <!-- ENCUESTA DE SATISFACCIÓN -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-3 fw-semibold text-secondary">
            <i class="bi bi-chat-heart-fill me-2 text-primary"></i>Encuesta de satisfacción
        </div>
        <div class="card-body">
            <?php if (!$encuesta_id): ?>
                <p class="text-muted text-center py-4 mb-0">Este curso no tiene encuesta configurada.</p>
            <?php else: ?>
                <?php foreach ($preguntas_encuesta as $i => $p): ?>
                    <div class="mb-4 p-3 bg-light rounded-3">
                        <p class="fw-semibold text-dark mb-2"><?php echo $i + 1; ?>. <?php echo htmlspecialchars($p['pregunta']); ?></p>
                        <?php if ($p['tipo'] === 'texto_libre'): ?>
                            <?php $resp = $respuestas_texto_libre[$p['id']] ?? []; ?>
                            <?php if (empty($resp)): ?>
                                <p class="text-muted small mb-0">Sin respuestas todavía.</p>
                            <?php else: ?>
                                <ul class="list-group list-group-flush rounded-3 border">
                                    <?php foreach (array_slice($resp, 0, 10) as $t): ?>
                                        <li class="list-group-item small bg-white"><?php echo htmlspecialchars($t); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (count($resp) > 10): ?>
                                    <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Y <?php echo count($resp) - 10; ?> más… descarga el Excel para verlas todas.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="bg-white p-2 rounded-2 border">
                                <canvas id="chart_<?php echo $p['id']; ?>" height="90"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }
};

new Chart(document.getElementById('chartCobertura'), {
    type: 'doughnut',
    data: {
        labels: ['Inscritos', 'No inscritos'],
        datasets: [{ data: [<?php echo $total_inscritos_objetivo; ?>, <?php echo $total_no_inscritos; ?>] }]
    },
    options: chartDefaults
});

new Chart(document.getElementById('chartEstadoFormacion'), {
    type: 'bar',
    data: {
        labels: ['Completados', 'En progreso', 'No iniciados'],
        datasets: [{ label: 'Usuarios', data: [<?php echo $total_completados; ?>, <?php echo $total_en_progreso; ?>, <?php echo $total_no_iniciados; ?>] }]
    },
    options: { ...chartDefaults, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chartProgreso'), {
    type: 'doughnut',
    data: {
        labels: ['0%', '1–24%', '25–49%', '50–74%', '75–100%'],
        datasets: [{ data: <?php echo json_encode($distribucion_progreso); ?> }]
    },
    options: chartDefaults
});

<?php if (!empty($stats_dependencias)): ?>
new Chart(document.getElementById('chartDependencias'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($stats_dependencias, 'nombre'), JSON_UNESCAPED_UNICODE); ?>,
        datasets: [
            { label: 'Destinatarios', data: <?php echo json_encode(array_map('intval', array_column($stats_dependencias, 'usuarios_objetivo'))); ?> },
            { label: 'Completados', data: <?php echo json_encode(array_map('intval', array_column($stats_dependencias, 'completados'))); ?> },
            { label: 'En progreso', data: <?php echo json_encode(array_map('intval', array_column($stats_dependencias, 'en_progreso'))); ?> }
        ]
    },
    options: { ...chartDefaults, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>

new Chart(document.getElementById('chartEvaluacionesResumen'), {
    type: 'doughnut',
    data: {
        labels: ['Aprobados', 'Reprobados', 'En proceso'],
        datasets: [{ data: [<?php echo $total_aprobados; ?>, <?php echo $total_reprobados; ?>, <?php echo $total_en_proceso_eval; ?>] }]
    },
    options: chartDefaults
});

new Chart(document.getElementById('chartEvaluaciones'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(function($s) { return $s['modulo_titulo'] ? 'Quiz: ' . $s['modulo_titulo'] : $s['nombre']; }, $stats_evaluaciones), JSON_UNESCAPED_UNICODE); ?>,
        datasets: [
            { label: 'Presentaron', data: <?php echo json_encode(array_map('intval', array_column($stats_evaluaciones, 'total_presentaron'))); ?> },
            { label: 'Aprobados', data: <?php echo json_encode(array_map('intval', array_column($stats_evaluaciones, 'total_aprobados'))); ?> },
            { label: 'Promedio %', data: <?php echo json_encode(array_map(function($s) { return $s['promedio_pct'] !== null ? round((float)$s['promedio_pct'], 1) : 0; }, $stats_evaluaciones)); ?> }
        ]
    },
    options: { ...chartDefaults, scales: { y: { beginAtZero: true } } }
});
</script>

<?php if ($encuesta_id && !empty($distribuciones)): ?>
<script>
const distribuciones = <?php echo json_encode($distribuciones); ?>;
Object.entries(distribuciones).forEach(([pid, d]) => {
    const el = document.getElementById('chart_' + pid);
    if (!el) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: d.labels,
            datasets: [{ data: d.valores, backgroundColor: '#0d6efd' }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
