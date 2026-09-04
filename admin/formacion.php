<?php
require_once '../config/config.php';
require_once '../includes/formacion_mailer.php';
requerirPermiso('gestionar_formacion');

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

$etiquetas_entidad = [
    'osf' => 'OSF (interno)',
    'sena' => 'SENA',
    'comfaboy' => 'Comfaboy',
    'positiva' => 'Positiva',
    'otro' => 'Otro',
];
$etiquetas_modalidad = [
    'plataforma' => 'Contenido en la plataforma',
    'solo_evaluacion' => 'Solo evaluación y encuesta',
];
$etiquetas_inscripcion = [
    'automatica' => 'Automática (se inscriben todos al crear el curso)',
    'voluntaria' => 'Voluntaria (cada colaborador se inscribe)',
];

$page_title = 'Gestión de Formación';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

/**
 * Sube la imagen de portada del curso (si se envió) y devuelve el nombre
 * de archivo guardado, o null si no se subió nada.
 */
function subirImagenFormacion(array $archivo): ?string
{
    if (!isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir la imagen.');
    }

    $permitidos = ['image/jpeg', 'image/png', 'image/webp'];
    $tipo = mime_content_type($archivo['tmp_name']);
    if (!in_array($tipo, $permitidos, true)) {
        throw new Exception('La imagen debe ser JPG, PNG o WEBP.');
    }
    if ($archivo['size'] > 3 * 1024 * 1024) {
        throw new Exception('La imagen no debe superar 3MB.');
    }

    $carpeta = __DIR__ . '/../uploads/formacion/';
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }

    $ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);
    $nombre = uniqid('formacion_') . '.' . strtolower($ext);
    move_uploaded_file($archivo['tmp_name'], $carpeta . $nombre);

    return 'formacion/' . $nombre;
}

/**
 * Sincroniza los usuarios destinatarios del curso directamente con las inscripciones.
 * Los usuarios seleccionados por el administrador quedan inscritos automáticamente.
 * En edición se conservan las inscripciones de los usuarios que siguen seleccionados
 * y se eliminan las que ya no hacen parte del listado destinatario.
 */
function sincronizarInscripcionesFormacion(PDO $pdo, int $curso_id, array $usuarios_sel): void
{
    $usuarios_sel = array_values(array_unique(array_map('intval', $usuarios_sel)));

    // Obtener inscripciones actuales para identificar cuáles son nuevas y cuáles se retiran.
    $stmt = $pdo->prepare("SELECT id, usuario_id FROM formacion_inscripciones WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $actuales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actuales_por_usuario = [];
    foreach ($actuales as $inscripcion) {
        $actuales_por_usuario[(int)$inscripcion['usuario_id']] = (int)$inscripcion['id'];
    }

    // Eliminar únicamente las inscripciones de usuarios que ya no fueron seleccionados.
    foreach ($actuales_por_usuario as $usuario_id => $inscripcion_id) {
        if (!in_array($usuario_id, $usuarios_sel, true)) {
            $pdo->prepare("DELETE FROM formacion_inscripciones WHERE id = ?")->execute([$inscripcion_id]);
        }
    }

    // Crear las nuevas inscripciones. INSERT IGNORE evita duplicados.
    if (!empty($usuarios_sel)) {
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO formacion_inscripciones (usuario_id, curso_id) VALUES (?, ?)");
        foreach ($usuarios_sel as $usuario_id) {
            $stmtInsert->execute([$usuario_id, $curso_id]);
        }
    }

    // Enviar correo solo a las inscripciones que todavía no tienen notificación de inicio.
    $stmt = $pdo->prepare("
        SELECT id FROM formacion_inscripciones
        WHERE curso_id = ?
          AND id NOT IN (SELECT inscripcion_id FROM notificaciones_formacion WHERE tipo = 'inicio')
    ");
    $stmt->execute([$curso_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $insc_id) {
        enviarCorreoInicioFormacion($pdo, (int)$insc_id);
    }
}

// ==========================================================
// Procesar acciones (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = sanitizar($_POST['nombre']);
        $descripcion = sanitizar($_POST['descripcion'] ?? '');
        $tipo_entidad = in_array($_POST['tipo_entidad'] ?? '', array_keys($etiquetas_entidad), true)
            ? $_POST['tipo_entidad'] : 'osf';
        $entidad_otro = $tipo_entidad === 'otro' ? sanitizar($_POST['entidad_otro'] ?? '') : null;
        $modalidad = ($_POST['modalidad'] ?? '') === 'solo_evaluacion' ? 'solo_evaluacion' : 'plataforma';
        // La asignación del curso ahora se hace usuario por usuario.
        // Se conserva 'automatica' en la BD para compatibilidad con cursos existentes.
        $tipo_inscripcion = 'automatica';
        $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;
        $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
        $fecha_cierre = !empty($_POST['fecha_cierre']) ? $_POST['fecha_cierre'] : null;
        $encuesta_url = !empty($_POST['encuesta_url']) ? sanitizar($_POST['encuesta_url']) : null;
        $usuarios_sel = isset($_POST['usuarios']) && is_array($_POST['usuarios'])
            ? array_values(array_unique(array_map('intval', $_POST['usuarios']))) : [];

        // Solo se pueden asignar usuarios activos y realmente existentes.
        if (!empty($usuarios_sel)) {
            $placeholdersUsuarios = implode(',', array_fill(0, count($usuarios_sel), '?'));
            $stmtUsuariosValidos = $pdo->prepare("SELECT id FROM usuarios WHERE activo = 1 AND id IN ($placeholdersUsuarios)");
            $stmtUsuariosValidos->execute($usuarios_sel);
            $usuarios_validos = array_map('intval', $stmtUsuariosValidos->fetchAll(PDO::FETCH_COLUMN));
            $usuarios_sel = array_values(array_intersect($usuarios_sel, $usuarios_validos));
        }

        if ($nombre === '' || empty($usuarios_sel)) {
            $mensaje = 'El nombre y al menos un usuario activo destinatario son obligatorios.';
            $tipo_mensaje = 'danger';
        } elseif ($fecha_inicio && $fecha_cierre && $fecha_cierre < $fecha_inicio) {
            $mensaje = 'La fecha de cierre no puede ser anterior a la fecha de inicio.';
            $tipo_mensaje = 'danger';
        } else {
            try {
                $imagen = subirImagenFormacion($_FILES['imagen'] ?? []);
                $pdo->beginTransaction();

                if ($accion === 'crear') {
                    $stmt = $pdo->prepare("
                        INSERT INTO formacion_cursos
                            (nombre, descripcion, imagen, tipo_entidad, entidad_otro, modalidad, tipo_inscripcion,
                             obligatorio, fecha_inicio, fecha_cierre, encuesta_url, activo)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $nombre, $descripcion, $imagen, $tipo_entidad, $entidad_otro, $modalidad, $tipo_inscripcion,
                        $obligatorio, $fecha_inicio, $fecha_cierre, $encuesta_url,
                    ]);
                    $curso_id = (int)$pdo->lastInsertId();
                    $accion_log = 'Crear curso de formación';
                } else {
                    $curso_id = (int)$_POST['id'];

                    if ($imagen !== null) {
                        $stmt = $pdo->prepare("
                            UPDATE formacion_cursos SET
                                nombre = ?, descripcion = ?, imagen = ?, tipo_entidad = ?, entidad_otro = ?,
                                modalidad = ?, tipo_inscripcion = ?, obligatorio = ?, fecha_inicio = ?, fecha_cierre = ?, encuesta_url = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $nombre, $descripcion, $imagen, $tipo_entidad, $entidad_otro,
                            $modalidad, $tipo_inscripcion, $obligatorio, $fecha_inicio, $fecha_cierre, $encuesta_url, $curso_id,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE formacion_cursos SET
                                nombre = ?, descripcion = ?, tipo_entidad = ?, entidad_otro = ?,
                                modalidad = ?, tipo_inscripcion = ?, obligatorio = ?, fecha_inicio = ?, fecha_cierre = ?, encuesta_url = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $nombre, $descripcion, $tipo_entidad, $entidad_otro,
                            $modalidad, $tipo_inscripcion, $obligatorio, $fecha_inicio, $fecha_cierre, $encuesta_url, $curso_id,
                        ]);
                    }
                    $accion_log = 'Editar curso de formación';
                }

                // Asignar directamente los usuarios destinatarios del curso.
                // Se deja la tabla de dependencias intacta para no romper cursos antiguos.
                sincronizarInscripcionesFormacion($pdo, $curso_id, $usuarios_sel);

                $pdo->commit();
                registrarLog($_SESSION['usuario_id'], $accion_log, 'Formación', "Curso ID: $curso_id");
                $mensaje = $accion === 'crear' ? 'Curso de formación creado exitosamente.' : 'Curso actualizado exitosamente.';
                $tipo_mensaje = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $mensaje = 'Error al guardar el curso: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'cambiar_estado') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("UPDATE formacion_cursos SET activo = NOT activo WHERE id = ?")->execute([$id]);
            registrarLog($_SESSION['usuario_id'], 'Cambiar estado curso formación', 'Formación', "Curso ID: $id");
            $mensaje = 'Estado del curso actualizado.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'No se pudo cambiar el estado.';
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("DELETE FROM formacion_cursos WHERE id = ?")->execute([$id]);
            registrarLog($_SESSION['usuario_id'], 'Eliminar curso formación', 'Formación', "Curso ID: $id");
            $mensaje = 'Curso eliminado exitosamente.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'No se pudo eliminar el curso: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// ==========================================================
// Filtros de búsqueda (GET)
// ==========================================================
$q = trim($_GET['q'] ?? '');
$entidad_filtro = trim($_GET['tipo_entidad'] ?? '');
$modalidad_filtro = trim($_GET['modalidad'] ?? '');
$estado_filtro = trim($_GET['estado'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "c.nombre LIKE ?";
    $params[] = '%' . $q . '%';
}
if ($entidad_filtro !== '' && isset($etiquetas_entidad[$entidad_filtro])) {
    $where[] = "c.tipo_entidad = ?";
    $params[] = $entidad_filtro;
}
if ($modalidad_filtro !== '' && isset($etiquetas_modalidad[$modalidad_filtro])) {
    $where[] = "c.modalidad = ?";
    $params[] = $modalidad_filtro;
}
if ($estado_filtro === '1' || $estado_filtro === '0') {
    $where[] = "c.activo = ?";
    $params[] = (int)$estado_filtro;
}
$where_sql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// Paginación: 10 filas por página (mismo criterio que usuarios.php)
$filas_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM formacion_cursos c $where_sql");
$stmt->execute($params);
$total_cursos = (int)($stmt->fetch()['total'] ?? 0);
$total_paginas = $total_cursos > 0 ? (int)ceil($total_cursos / $filas_por_pagina) : 1;
$pagina_actual = min($pagina_actual, $total_paginas);
$offset = ($pagina_actual - 1) * $filas_por_pagina;

$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM formacion_inscripciones fi WHERE fi.curso_id = c.id) AS total_inscritos,
           (SELECT COUNT(*) FROM formacion_inscripciones fi WHERE fi.curso_id = c.id AND fi.completado = 1) AS total_completados,
           (SELECT GROUP_CONCAT(u.nombre_completo ORDER BY u.nombre_completo SEPARATOR ', ')
              FROM formacion_inscripciones fi
              JOIN usuarios u ON u.id = fi.usuario_id
             WHERE fi.curso_id = c.id) AS usuarios_nombres
    FROM formacion_cursos c
    $where_sql
    ORDER BY c.fecha_creacion DESC
    LIMIT ? OFFSET ?
");
$i = 1;
foreach ($params as $p) { $stmt->bindValue($i++, $p); }
$stmt->bindValue($i++, (int)$filas_por_pagina, PDO::PARAM_INT);
$stmt->bindValue($i, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$cursos = $stmt->fetchAll();

// Usuarios activos/registrados disponibles para asignar a un curso.
// Se muestran todos los usuarios de la plataforma; los inactivos aparecen identificados.
$usuarios = $pdo->query("
    SELECT u.id, u.nombre_completo, u.email, u.activo, d.nombre AS dependencia
    FROM usuarios u
    LEFT JOIN dependencias d ON d.id = u.dependencia_id
    ORDER BY u.activo DESC, u.nombre_completo ASC
")->fetchAll();

// Usuarios asignados por curso para preseleccionar al editar.
$usuarios_por_curso = [];
if (!empty($cursos)) {
    $ids = array_column($cursos, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT curso_id, usuario_id FROM formacion_inscripciones WHERE curso_id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $usuarios_por_curso[$row['curso_id']][] = (int)$row['usuario_id'];
    }
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Gestión de Formación</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCurso">
            <i class="bi bi-plus-circle me-2"></i>Nuevo curso de formación
        </button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1">Buscar por nombre</label>
                    <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Ej: Primeros auxilios">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1">Entidad</label>
                    <select class="form-select" name="tipo_entidad">
                        <option value="">Todas</option>
                        <?php foreach ($etiquetas_entidad as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $entidad_filtro === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1">Modalidad</label>
                    <select class="form-select" name="modalidad">
                        <option value="">Todas</option>
                        <?php foreach ($etiquetas_modalidad as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $modalidad_filtro === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1">Estado</label>
                    <select class="form-select" name="estado">
                        <option value="" <?php echo $estado_filtro === '' ? 'selected' : ''; ?>>Todos</option>
                        <option value="1" <?php echo $estado_filtro === '1' ? 'selected' : ''; ?>>Activo</option>
                        <option value="0" <?php echo $estado_filtro === '0' ? 'selected' : ''; ?>>Inactivo</option>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                    <a class="btn btn-outline-secondary w-100" href="formacion.php"><i class="bi bi-x-circle"></i></a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Curso</th>
                            <th>Entidad</th>
                            <th>Modalidad</th>
                            <th>Asignación</th>
                            <th>Usuarios destinatarios</th>
                            <th>Fechas</th>
                            <th>Avance</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
    <?php foreach ($cursos as $curso): ?>
        <?php
        $entidad_label = $etiquetas_entidad[$curso['tipo_entidad']] ?? $curso['tipo_entidad'];
        if ($curso['tipo_entidad'] === 'otro' && !empty($curso['entidad_otro'])) {
            $entidad_label = htmlspecialchars($curso['entidad_otro']);
        }
        $pct = $curso['total_inscritos'] > 0
            ? round(($curso['total_completados'] / $curso['total_inscritos']) * 100)
            : 0;
        ?>
        <tr>
            <td>
                <div class="fw-semibold">
                    <?php echo htmlspecialchars($curso['nombre']); ?>
                    <?php if ($curso['obligatorio']): ?>
                        <span class="badge bg-danger ms-1">Obligatorio</span>
                    <?php endif; ?>
                </div>
            </td>
            <td><span class="badge bg-info text-dark"><?php echo $entidad_label; ?></span></td>
            <td>
                <?php if ($curso['modalidad'] === 'solo_evaluacion'): ?>
                    <span class="badge bg-warning text-dark">Solo evaluación</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Plataforma</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge bg-dark-subtle text-dark-emphasis">Usuarios seleccionados</span>
            </td>
            <td class="small text-muted" style="max-width:220px;">
                <?php echo htmlspecialchars($curso['usuarios_nombres'] ?? 'Sin asignar'); ?>
            </td>
            <td class="small text-nowrap">
                <?php echo $curso['fecha_inicio'] ? date('d/m/Y', strtotime($curso['fecha_inicio'])) : '—'; ?>
                &rarr;
                <?php echo $curso['fecha_cierre'] ? date('d/m/Y', strtotime($curso['fecha_cierre'])) : '—'; ?>
            </td>
            <td style="min-width:120px;">
                <div class="progress" style="height:6px;">
                    <div class="progress-bar" style="width:<?php echo $pct; ?>%"></div>
                </div>
                <div class="small text-muted"><?php echo $curso['total_completados']; ?>/<?php echo $curso['total_inscritos']; ?> (<?php echo $pct; ?>%)</div>
            </td>
            <td>
                <span class="badge bg-<?php echo $curso['activo'] ? 'success' : 'secondary'; ?>">
                    <?php echo $curso['activo'] ? 'Activo' : 'Inactivo'; ?>
                </span>
            </td>
            <td class="text-nowrap">
                <a href="formacion_detalle.php?id=<?php echo $curso['id']; ?>" class="btn btn-sm btn-outline-primary" title="Gestionar contenido y evaluación">
                    <i class="bi bi-folder2-open"></i>
                </a>
                
                <!-- BOTÓN DE EDITAR -->
                <button type="button" class="btn btn-sm btn-warning" onclick='editarCurso(<?php echo json_encode($curso + ['usuarios' => $usuarios_por_curso[$curso['id']] ?? []], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Editar curso">
                    <i class="bi bi-pencil"></i>
                </button>

                <form method="POST" class="d-inline">
                    <input type="hidden" name="accion" value="cambiar_estado">
                    <input type="hidden" name="id" value="<?php echo $curso['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Activar/Desactivar">
                        <i class="bi bi-power"></i>
                    </button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar el curso «<?php echo htmlspecialchars(addslashes($curso['nombre'])); ?>»? Se perderán inscripciones y avances asociados.');">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?php echo $curso['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Eliminar curso"><i class="bi bi-trash"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>
                </table>
            </div>

            <?php if ($total_paginas > 1): ?>
            <nav class="d-flex justify-content-center mt-3">
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $base_query = array_filter(['q' => $q, 'tipo_entidad' => $entidad_filtro, 'modalidad' => $modalidad_filtro, 'estado' => $estado_filtro]);
                    for ($p = 1; $p <= $total_paginas; $p++):
                        $p_query = $base_query + ['pagina' => $p];
                    ?>
                        <li class="page-item <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($p_query)); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Curso de Formación (con pasos) -->
<div class="modal fade" id="modalCurso" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="formCurso" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title" id="modalCursoTitle">Nuevo curso de formación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="accion" id="accionCurso" value="crear">
          <input type="hidden" name="id" id="cursoId">

          <!-- Indicador de pasos -->
          <ul class="nav nav-pills nav-fill mb-4" id="pasosWizard">
            <li class="nav-item"><button type="button" class="nav-link active" data-paso="1">1. Entidad</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-paso="2">2. Usuarios destinatarios</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-paso="3">3. Modalidad</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-paso="4">4. Fechas</button></li>
          </ul>

          <!-- Paso 1: Entidad -->
          <div class="paso-form" data-paso="1">
            <div class="mb-3">
              <label class="form-label">Nombre del curso *</label>
              <input type="text" class="form-control" name="nombre" id="nombreCurso" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Descripción</label>
              <textarea class="form-control" name="descripcion" id="descripcionCurso" rows="3"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Imagen de portada</label>
              <input type="file" class="form-control" name="imagen" accept="image/*">
            </div>
            <div class="mb-3">
              <label class="form-label">Entidad que dicta el curso *</label>
              <select class="form-select" name="tipo_entidad" id="tipoEntidad">
                <?php foreach ($etiquetas_entidad as $val => $label): ?>
                  <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3 d-none" id="campoEntidadOtro">
              <label class="form-label">¿Cuál entidad?</label>
              <input type="text" class="form-control" name="entidad_otro" id="entidadOtro" placeholder="Ej: Cruz Roja">
            </div>
          </div>

          <!-- Paso 2: Usuarios destinatarios -->
          <div class="paso-form d-none" data-paso="2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <label class="form-label mb-1">¿A qué usuarios va dirigido el curso? *</label>
                <p class="small text-muted mb-0">Selecciona únicamente los usuarios que deberán realizar este curso.</p>
              </div>
              <span class="badge bg-primary" id="contadorUsuarios">0 seleccionados</span>
            </div>

            <div class="input-group mb-3">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="search" class="form-control" id="buscadorUsuarios" placeholder="Buscar por nombre, correo o dependencia..." autocomplete="off">
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="small text-muted" id="resultadoUsuarios"></div>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btnSeleccionarVisibles">Seleccionar visibles</button>
            </div>

            <div class="border rounded p-2" id="listaUsuarios" style="max-height: 330px; overflow-y: auto;">
              <?php foreach ($usuarios as $usuario): ?>
                <label class="usuario-item d-flex align-items-center gap-3 p-2 rounded border-bottom mb-0"
                       data-nombre="<?php echo htmlspecialchars(mb_strtolower($usuario['nombre_completo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       data-email="<?php echo htmlspecialchars(mb_strtolower($usuario['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       data-dependencia="<?php echo htmlspecialchars(mb_strtolower($usuario['dependencia'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                  <input class="form-check-input usuario-check flex-shrink-0" type="checkbox" name="usuarios[]" value="<?php echo (int)$usuario['id']; ?>" <?php echo !$usuario['activo'] ? 'disabled' : ''; ?>>
                  <span class="flex-grow-1">
                    <span class="d-block fw-semibold"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></span>
                    <span class="d-block small text-muted">
                      <?php echo htmlspecialchars($usuario['email'] ?? 'Sin correo'); ?>
                      <?php if (!empty($usuario['dependencia'])): ?>
                        &middot; <?php echo htmlspecialchars($usuario['dependencia']); ?>
                      <?php endif; ?>
                    </span>
                  </span>
                  <?php if (!$usuario['activo']): ?>
                    <span class="badge bg-secondary">Inactivo</span>
                  <?php endif; ?>
                </label>
              <?php endforeach; ?>
              <?php if (empty($usuarios)): ?>
                <div class="text-center text-muted py-4">No hay usuarios registrados en la plataforma.</div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Paso 3: Modalidad -->
          <div class="paso-form d-none" data-paso="3">
            <label class="form-label d-block mb-2">¿Cómo se realiza el curso? *</label>
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="modalidad" id="modPlataforma" value="plataforma" checked>
              <label class="form-check-label" for="modPlataforma">
                <strong>Contenido en la plataforma</strong> — el colaborador ve el material y presenta la evaluación aquí.
              </label>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="radio" name="modalidad" id="modSoloEval" value="solo_evaluacion">
              <label class="form-check-label" for="modSoloEval">
                <strong>Solo evaluación y encuesta</strong> — el curso se realiza fuera de la Intranet (ej. plataforma SENA); aquí solo se presenta la evaluación y la encuesta.
              </label>
            </div>
            <div class="alert alert-light border small">
              El contenido (módulos, materiales, evaluación) se administra desde <em>«Gestionar contenido»</em> una vez creado el curso.
            </div>
          </div>

          <!-- Paso 4: Fechas -->
          <div class="paso-form d-none" data-paso="4">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Fecha de inicio</label>
                <input type="date" class="form-control" name="fecha_inicio">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Fecha de cierre</label>
                <input type="date" class="form-control" name="fecha_cierre">
              </div>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="obligatorio" id="obligatorioCurso" checked>
              <label class="form-check-label" for="obligatorioCurso">Curso obligatorio</label>
            </div>
          </div>
        </div>

        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-outline-secondary" id="btnPasoAnterior" disabled>
            <i class="bi bi-arrow-left"></i> Anterior
          </button>
          <div>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btnPasoSiguiente">Siguiente <i class="bi bi-arrow-right"></i></button>
            <button type="submit" class="btn btn-success d-none" id="btnGuardarCurso">
              <i class="bi bi-check-circle me-1"></i>Publicar curso
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  const totalPasos = 4;
  let pasoActual = 1;

  const pasos = document.querySelectorAll('.paso-form');
  const navPasos = document.querySelectorAll('#pasosWizard .nav-link');
  const btnAnterior = document.getElementById('btnPasoAnterior');
  const btnSiguiente = document.getElementById('btnPasoSiguiente');
  const btnGuardar = document.getElementById('btnGuardarCurso');

  function mostrarPaso(n) {
    pasoActual = n;
    pasos.forEach(p => p.classList.toggle('d-none', parseInt(p.dataset.paso) !== n));
    navPasos.forEach(b => b.classList.toggle('active', parseInt(b.dataset.paso) === n));
    btnAnterior.disabled = n === 1;
    btnSiguiente.classList.toggle('d-none', n === totalPasos);
    btnGuardar.classList.toggle('d-none', n !== totalPasos);
  }

  function validarPasoActual() {
    if (pasoActual === 1) {
      const nombre = document.getElementById('nombreCurso');
      if (!nombre.value.trim()) { nombre.reportValidity(); return false; }
    }
    if (pasoActual === 2) {
      const marcados = document.querySelectorAll('.usuario-check:checked').length;
      if (marcados === 0) { alert('Selecciona al menos un usuario destinatario.'); return false; }
    }
    return true;
  }

  btnSiguiente.addEventListener('click', function () {
    if (!validarPasoActual()) return;
    if (pasoActual < totalPasos) mostrarPaso(pasoActual + 1);
  });
  btnAnterior.addEventListener('click', function () {
    if (pasoActual > 1) mostrarPaso(pasoActual - 1);
  });
  document.querySelectorAll('#pasosWizard .nav-link').forEach(btn => {
    btn.addEventListener('click', function () { mostrarPaso(parseInt(this.dataset.paso)); });
  });

  document.getElementById('tipoEntidad').addEventListener('change', function () {
    document.getElementById('campoEntidadOtro').classList.toggle('d-none', this.value !== 'otro');
  });

  const buscadorUsuarios = document.getElementById('buscadorUsuarios');
  const contadorUsuarios = document.getElementById('contadorUsuarios');
  const resultadoUsuarios = document.getElementById('resultadoUsuarios');
  const listaUsuarios = document.getElementById('listaUsuarios');
  const btnSeleccionarVisibles = document.getElementById('btnSeleccionarVisibles');

  function actualizarUsuariosVisibles() {
    const termino = (buscadorUsuarios.value || '').trim().toLowerCase();
    const items = listaUsuarios.querySelectorAll('.usuario-item');
    let visibles = 0;

    items.forEach(item => {
      const texto = [item.dataset.nombre, item.dataset.email, item.dataset.dependencia].join(' ');
      const coincide = !termino || texto.includes(termino);
      item.classList.toggle('d-none', !coincide);
      if (coincide) visibles++;
    });

    resultadoUsuarios.textContent = `${visibles} usuario${visibles === 1 ? '' : 's'} encontrado${visibles === 1 ? '' : 's'}`;
    actualizarContadorUsuarios();
  }

  function actualizarContadorUsuarios() {
    const total = document.querySelectorAll('.usuario-check:checked').length;
    contadorUsuarios.textContent = `${total} seleccionado${total === 1 ? '' : 's'}`;
  }

  buscadorUsuarios.addEventListener('input', actualizarUsuariosVisibles);
  listaUsuarios.addEventListener('change', actualizarContadorUsuarios);

  btnSeleccionarVisibles.addEventListener('click', function () {
    const visibles = listaUsuarios.querySelectorAll('.usuario-item:not(.d-none) .usuario-check:not(:disabled)');
    const hayNoSeleccionados = Array.from(visibles).some(chk => !chk.checked);
    visibles.forEach(chk => { chk.checked = hayNoSeleccionados; });
    actualizarContadorUsuarios();
  });

  window.editarCurso = function (curso) {
    document.getElementById('modalCursoTitle').textContent = 'Editar curso de formación';
    document.getElementById('accionCurso').value = 'editar';
    document.getElementById('cursoId').value = curso.id;
    document.getElementById('nombreCurso').value = curso.nombre;
    document.getElementById('descripcionCurso').value = curso.descripcion || '';
    document.getElementById('tipoEntidad').value = curso.tipo_entidad;
    document.getElementById('campoEntidadOtro').classList.toggle('d-none', curso.tipo_entidad !== 'otro');
    document.getElementById('entidadOtro').value = curso.entidad_otro || '';
    document.querySelector('input[name="modalidad"][value="' + curso.modalidad + '"]').checked = true;
    document.querySelector('input[name="fecha_inicio"]').value = curso.fecha_inicio || '';
    document.querySelector('input[name="fecha_cierre"]').value = curso.fecha_cierre || '';
    document.getElementById('obligatorioCurso').checked = curso.obligatorio == 1;

    document.querySelectorAll('.usuario-check').forEach(chk => {
      chk.checked = (curso.usuarios || []).includes(parseInt(chk.value));
    });
    buscadorUsuarios.value = '';
    actualizarUsuariosVisibles();

    mostrarPaso(1);
    new bootstrap.Modal(document.getElementById('modalCurso')).show();
  };

  document.getElementById('modalCurso').addEventListener('hidden.bs.modal', function () {
    document.getElementById('formCurso').reset();
    document.getElementById('modalCursoTitle').textContent = 'Nuevo curso de formación';
    document.getElementById('accionCurso').value = 'crear';
    document.getElementById('cursoId').value = '';
    document.getElementById('campoEntidadOtro').classList.add('d-none');
    buscadorUsuarios.value = '';
    document.querySelectorAll('.usuario-check').forEach(chk => { chk.checked = false; });
    actualizarUsuariosVisibles();
    mostrarPaso(1);
  });
})();
</script>

<?php require_once '../includes/footer.php'; ?>