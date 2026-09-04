<?php
require_once '../config/config.php';
requerirPermiso('gestionar_usuarios');

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';
$es_super_admin = (isset($_SESSION['rol']) && $_SESSION['rol'] === 'super_admin');

/** Columnas disponibles para exportar (clave interna => etiqueta). */
$columnas_exportables = [
    'id' => 'ID',
    'nombre_completo' => 'Nombre',
    'email' => 'Email',
    'rol' => 'Rol',
    'dependencia_nombre' => 'Dependencia',
    'activo' => 'Estado',
    'fecha_creacion' => 'Fecha de creación',
];

$rol_etiquetas_export = [
    'super_admin' => 'Super Administrador',
    'administrador' => 'Administrador',
    'usuario' => 'Usuario básico',
];

function construirFiltrosUsuariosExport() {
    $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
    $dependencia_filtro = isset($_POST['dependencia_id']) ? (int)$_POST['dependencia_id'] : (isset($_GET['dependencia_id']) ? (int)$_GET['dependencia_id'] : 0);
    $estado_filtro = isset($_POST['estado']) ? trim((string)$_POST['estado']) : (isset($_GET['estado']) ? trim((string)$_GET['estado']) : '');

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = "(u.nombre_completo LIKE ? OR u.email LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($dependencia_filtro > 0) {
        $where[] = "u.dependencia_id = ?";
        $params[] = $dependencia_filtro;
    }
    if ($estado_filtro !== '' && ($estado_filtro === '1' || $estado_filtro === '0')) {
        $where[] = "u.activo = ?";
        $params[] = (int)$estado_filtro;
    }

    return [
        'where_sql' => !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '',
        'params' => $params,
        'q' => $q,
        'dependencia_filtro' => $dependencia_filtro,
        'estado_filtro' => $estado_filtro,
    ];
}

// Exportar CSV antes de enviar HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'exportar_usuarios') {
    $columnas_solicitadas = isset($_POST['columnas']) && is_array($_POST['columnas']) ? $_POST['columnas'] : [];
    $columnas_validas = [];
    foreach ($columnas_solicitadas as $col) {
        $col = (string)$col;
        if (isset($columnas_exportables[$col])) {
            $columnas_validas[] = $col;
        }
    }

    if (empty($columnas_validas)) {
        $mensaje = 'Seleccione al menos una columna para descargar.';
        $tipo_mensaje = 'danger';
    } else {
        $filtros = construirFiltrosUsuariosExport();
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre_completo, u.email, u.activo, u.fecha_creacion,
                   d.nombre AS dependencia_nombre, r.nombre AS rol
            FROM usuarios u
            LEFT JOIN dependencias d ON u.dependencia_id = d.id
            LEFT JOIN roles r ON u.rol_id = r.id
            {$filtros['where_sql']}
            ORDER BY u.fecha_creacion DESC
        ");
        $stmt->execute($filtros['params']);
        $filas = $stmt->fetchAll();

        $nombre_archivo = 'usuarios_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM para que Excel abra bien acentos
        fwrite($out, "\xEF\xBB\xBF");

        $encabezados = [];
        foreach ($columnas_validas as $col) {
            $encabezados[] = $columnas_exportables[$col];
        }
        fputcsv($out, $encabezados, ';');

        foreach ($filas as $fila) {
            $linea = [];
            foreach ($columnas_validas as $col) {
                if ($col === 'rol') {
                    $rol_raw = $fila['rol'] ?? '';
                    $linea[] = $rol_etiquetas_export[$rol_raw] ?? $rol_raw;
                } elseif ($col === 'dependencia_nombre') {
                    $linea[] = $fila['dependencia_nombre'] ?? 'Sin asignar';
                } elseif ($col === 'activo') {
                    $linea[] = !empty($fila['activo']) ? 'Activo' : 'Inactivo';
                } else {
                    $linea[] = $fila[$col] ?? '';
                }
            }
            fputcsv($out, $linea, ';');
        }

        fclose($out);
        registrarLog($_SESSION['usuario_id'], 'Exportar usuarios', 'Usuarios', 'Columnas: ' . implode(', ', $columnas_validas));
        exit;
    }
}

$page_title = 'Gestión de Usuarios';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear') {
        $nombre = sanitizar($_POST['nombre_completo']);
        $email = sanitizar($_POST['email']);
        $password = $_POST['password'];
        $rol_id = (int)$_POST['rol_id'];
        $dependencia_id = !empty($_POST['dependencia_id']) ? (int)$_POST['dependencia_id'] : null;
        
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre_completo, email, password, rol_id, dependencia_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $email, $password_hash, $rol_id, $dependencia_id]);
            
            $usuario_id = $pdo->lastInsertId();
            
            // Asignar perfil según rol: 1=Super Admin, 2=Colaborador/Usuario básico, 3=Administrador
            $perfil_id = $rol_id;
            $stmt = $pdo->prepare("INSERT INTO usuario_perfiles (usuario_id, perfil_id) VALUES (?, ?)");
            $stmt->execute([$usuario_id, $perfil_id]);
            
            registrarLog($_SESSION['usuario_id'], 'Crear usuario', 'Usuarios', "Usuario: $email");
            $mensaje = 'Usuario creado exitosamente';
            $tipo_mensaje = 'success';
        } catch (PDOException $e) {
            $mensaje = 'Error al crear usuario: ' . ($e->getCode() == 23000 ? 'El email ya existe' : $e->getMessage());
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $nombre = sanitizar($_POST['nombre_completo']);
        $email = sanitizar($_POST['email']);
        $rol_id = (int)$_POST['rol_id'];
        $dependencia_id = !empty($_POST['dependencia_id']) ? (int)$_POST['dependencia_id'] : null;
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                UPDATE usuarios 
                SET nombre_completo = ?, email = ?, rol_id = ?, dependencia_id = ?, activo = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $email, $rol_id, $dependencia_id, $activo, $id]);
            
            // Actualizar perfil según rol: 1=Super Admin, 2=Colaborador, 3=Administrador
            $perfil_id = $rol_id;
            $stmt = $pdo->prepare("DELETE FROM usuario_perfiles WHERE usuario_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("INSERT INTO usuario_perfiles (usuario_id, perfil_id) VALUES (?, ?)");
            $stmt->execute([$id, $perfil_id]);
            
            registrarLog($_SESSION['usuario_id'], 'Editar usuario', 'Usuarios', "Usuario ID: $id");
            $mensaje = 'Usuario actualizado exitosamente';
            $tipo_mensaje = 'success';
        } catch (PDOException $e) {
            $mensaje = 'Error al actualizar usuario: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'cambiar_password') {
        $id = (int)$_POST['id'];
        $password = $_POST['password'];
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt->execute([$password_hash, $id]);
            registrarLog($_SESSION['usuario_id'], 'Cambiar contraseña', 'Usuarios', "Usuario ID: $id");
            $mensaje = 'Contraseña actualizada exitosamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'Error al actualizar contraseña';
            $tipo_mensaje = 'danger';
        }
    } elseif ($accion === 'eliminar') {
        if (!$es_super_admin) {
            $mensaje = 'No autorizado para eliminar usuarios.';
            $tipo_mensaje = 'danger';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                $mensaje = 'Usuario inválido.';
                $tipo_mensaje = 'danger';
            } elseif ((int)($_SESSION['usuario_id'] ?? 0) === $id) {
                $mensaje = 'No puedes eliminar tu propio usuario.';
                $tipo_mensaje = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Obtener datos básicos para limpieza secundaria (intentos_login por email)
                    $stmt = $pdo->prepare("SELECT id, email FROM usuarios WHERE id = ?");
                    $stmt->execute([$id]);
                    $u = $stmt->fetch();
                    if (!$u) {
                        throw new Exception('Usuario no encontrado.');
                    }
                    $email_usuario = $u['email'];

                    // Eliminar relaciones conocidas (best-effort por si alguna tabla no existe en algunas instalaciones)
                    try {
                        $stmt = $pdo->prepare("DELETE FROM usuario_perfiles WHERE usuario_id = ?");
                        $stmt->execute([$id]);
                    } catch (Exception $e) {}

                    try {
                        $stmt = $pdo->prepare("DELETE FROM logs_actividad WHERE usuario_id = ?");
                        $stmt->execute([$id]);
                    } catch (Exception $e) {}

                    // Documentos de interés creados por el usuario
                    try {
                        $stmt = $pdo->prepare("DELETE FROM archivos_documento_interes WHERE usuario_id = ?");
                        $stmt->execute([$id]);
                    } catch (Exception $e) {}

                    try {
                        $stmt = $pdo->prepare("DELETE FROM documentos_interes WHERE usuario_id = ?");
                        $stmt->execute([$id]);
                    } catch (Exception $e) {}

                    // Inscripciones y progreso/evaluaciones
                    try {
                        $stmt = $pdo->prepare("SELECT id FROM inscripciones WHERE usuario_id = ?");
                        $stmt->execute([$id]);
                        $ins_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($ins_ids)) {
                            $placeholders = implode(',', array_fill(0, count($ins_ids), '?'));

                            // Respuestas y intentos de evaluación (si existen)
                            try {
                                $stmt = $pdo->prepare("SELECT id FROM intentos_evaluacion WHERE inscripcion_id IN ($placeholders)");
                                $stmt->execute($ins_ids);
                                $intento_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                if (!empty($intento_ids)) {
                                    $ph2 = implode(',', array_fill(0, count($intento_ids), '?'));
                                    try {
                                        $stmt = $pdo->prepare("DELETE FROM respuestas_usuario WHERE intento_id IN ($ph2)");
                                        $stmt->execute($intento_ids);
                                    } catch (Exception $e) {}
                                    try {
                                        $stmt = $pdo->prepare("DELETE FROM intentos_evaluacion WHERE id IN ($ph2)");
                                        $stmt->execute($intento_ids);
                                    } catch (Exception $e) {}
                                }
                            } catch (Exception $e) {}

                            // Progreso material
                            try {
                                $stmt = $pdo->prepare("DELETE FROM progreso_material WHERE inscripcion_id IN ($placeholders)");
                                $stmt->execute($ins_ids);
                            } catch (Exception $e) {}

                            // Inscripciones
                            $stmt = $pdo->prepare("DELETE FROM inscripciones WHERE id IN ($placeholders)");
                            $stmt->execute($ins_ids);
                        }
                    } catch (Exception $e) {}

                    // Intentos de login (por email)
                    try {
                        $stmt = $pdo->prepare("DELETE FROM intentos_login WHERE email = ?");
                        $stmt->execute([$email_usuario]);
                    } catch (Exception $e) {}

                    // Finalmente eliminar el usuario
                    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
                    $stmt->execute([$id]);

                    $pdo->commit();
                    registrarLog($_SESSION['usuario_id'], 'Eliminar usuario', 'Usuarios', "Usuario ID: $id / Email: $email_usuario");
                    $mensaje = 'Usuario eliminado exitosamente.';
                    $tipo_mensaje = 'success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $mensaje = 'No se pudo eliminar el usuario: ' . $e->getMessage();
                    $tipo_mensaje = 'danger';
                }
            }
        }
    }
}

// Filtros de búsqueda
$q = trim($_GET['q'] ?? '');
$dependencia_filtro = isset($_GET['dependencia_id']) ? (int)$_GET['dependencia_id'] : 0;
$estado_filtro = isset($_GET['estado']) ? trim($_GET['estado']) : '';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(u.nombre_completo LIKE ? OR u.email LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($dependencia_filtro > 0) {
    $where[] = "u.dependencia_id = ?";
    $params[] = $dependencia_filtro;
}
if ($estado_filtro !== '' && ($estado_filtro === '1' || $estado_filtro === '0')) {
    $where[] = "u.activo = ?";
    $params[] = (int)$estado_filtro;
}
$where_sql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// Paginación: 10 filas por página
$filas_por_pagina = 10;
$pagina_actual = isset($_GET['pagina_usuarios']) ? max(1, (int)$_GET['pagina_usuarios']) : 1;

// Conteo total con filtros
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM usuarios u
    $where_sql
");
$stmt->execute($params);
$total_usuarios = (int)($stmt->fetch()['total'] ?? 0);
$total_paginas = $total_usuarios > 0 ? (int)ceil($total_usuarios / $filas_por_pagina) : 1;
$pagina_actual = min($pagina_actual, $total_paginas);
$offset = ($pagina_actual - 1) * $filas_por_pagina;

// Obtener usuarios (paginado) con filtros
$stmt = $pdo->prepare("
    SELECT u.*, d.nombre as dependencia_nombre, r.nombre as rol
    FROM usuarios u
    LEFT JOIN dependencias d ON u.dependencia_id = d.id
    LEFT JOIN roles r ON u.rol_id = r.id
    $where_sql
    ORDER BY u.fecha_creacion DESC
    LIMIT ? OFFSET ?
");
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i, $p);
    $i++;
}
$stmt->bindValue($i, (int)$filas_por_pagina, PDO::PARAM_INT);
$stmt->bindValue($i + 1, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$usuarios = $stmt->fetchAll();

// Obtener dependencias
$stmt = $pdo->query("SELECT * FROM dependencias WHERE activo = 1 ORDER BY nombre");
$dependencias = $stmt->fetchAll();

// Obtener roles
$stmt = $pdo->query("SELECT * FROM roles WHERE activo = 1 ORDER BY nombre");
$roles = $stmt->fetchAll();
?>

<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0"><i class="bi bi-people me-2"></i>Gestión de Usuarios</h2>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalExportarUsuarios">
                <i class="bi bi-download me-2"></i>Descargar
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUsuario">
                <i class="bi bi-person-plus me-2"></i>Nuevo Usuario
            </button>
        </div>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1">Buscar (Nombre o Email)</label>
                    <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Ej: Juan Pérez o correo@dominio.com">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Dependencia</label>
                    <select class="form-select" name="dependencia_id">
                        <option value="0">Todas</option>
                        <?php foreach ($dependencias as $dep): ?>
                            <option value="<?php echo (int)$dep['id']; ?>" <?php echo $dependencia_filtro === (int)$dep['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dep['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label mb-1">Estado</label>
                    <select class="form-select" name="estado">
                        <option value="" <?php echo $estado_filtro === '' ? 'selected' : ''; ?>>Todos</option>
                        <option value="1" <?php echo $estado_filtro === '1' ? 'selected' : ''; ?>>Activo</option>
                        <option value="0" <?php echo $estado_filtro === '0' ? 'selected' : ''; ?>>Inactivo</option>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>Buscar
                    </button>
                    <a class="btn btn-outline-secondary w-100" href="usuarios.php">
                        <i class="bi bi-x-circle me-1"></i>Limpiar
                    </a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Dependencia</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td><?php echo $usuario['id']; ?></td>
                                <td><?php echo htmlspecialchars($usuario['nombre_completo']); ?></td>
                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                <td>
                                    <?php
                                    $rol_badge = ['super_admin' => ['danger', 'Super Administrador'], 'administrador' => ['warning', 'Administrador'], 'usuario' => ['primary', 'Usuario básico']];
                                    $r = $rol_badge[$usuario['rol']] ?? ['secondary', $usuario['rol']];
                                    ?>
                                    <span class="badge bg-<?php echo $r[0]; ?>"><?php echo htmlspecialchars($r[1]); ?></span>
                                </td>
                                <td><?php echo $usuario['dependencia_nombre'] ?? 'Sin asignar'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $usuario['activo'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editarUsuario(<?php echo htmlspecialchars(json_encode($usuario)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="cambiarPassword(<?php echo $usuario['id']; ?>)">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <?php if ($es_super_admin): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirmarEliminacionUsuario(<?php echo (int)$usuario['id']; ?>, <?php echo json_encode($usuario['email']); ?>);">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id" value="<?php echo (int)$usuario['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Eliminar usuario">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $rango_inicio = $total_usuarios > 0 ? ($offset + 1) : 0;
            $rango_fin = $total_usuarios > 0 ? min($offset + $filas_por_pagina, $total_usuarios) : 0;
            ?>
            <div class="row align-items-center mt-3 g-2">
                <div class="col-12 col-md-4">
                    <div class="small text-muted text-center text-md-start">
                        <?php echo $rango_inicio; ?>-<?php echo $rango_fin; ?> de <?php echo $total_usuarios; ?>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <?php if ($total_paginas > 1): ?>
                    <nav aria-label="Paginación de usuarios" class="d-flex justify-content-center">
                        <ul class="pagination pagination-sm mb-0">
                        <?php
                        $base_query = [];
                        if ($q !== '') $base_query['q'] = $q;
                        if ($dependencia_filtro > 0) $base_query['dependencia_id'] = $dependencia_filtro;
                        if ($estado_filtro !== '') $base_query['estado'] = $estado_filtro;
                        ?>
                        <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                            <?php $prev_query = $base_query + ['pagina_usuarios' => $pagina_actual - 1]; ?>
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($prev_query)); ?>" aria-label="Anterior" title="Anterior">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php for ($p = 1; $p <= $total_paginas; $p++): ?>
                            <li class="page-item <?php echo $p === $pagina_actual ? 'active' : ''; ?>">
                                <?php $p_query = $base_query + ['pagina_usuarios' => $p]; ?>
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($p_query)); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                            <?php $next_query = $base_query + ['pagina_usuarios' => $pagina_actual + 1]; ?>
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($next_query)); ?>" aria-label="Siguiente" title="Siguiente">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-4"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Exportar usuarios -->
<div class="modal fade" id="modalExportarUsuarios" tabindex="-1" aria-labelledby="modalExportarUsuariosLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formExportarUsuarios">
                <input type="hidden" name="accion" value="exportar_usuarios">
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                <input type="hidden" name="dependencia_id" value="<?php echo (int)$dependencia_filtro; ?>">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($estado_filtro); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalExportarUsuariosLabel">
                        <i class="bi bi-download me-2"></i>Descargar usuarios
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Seleccione las columnas que desea incluir en el archivo CSV.
                        Se exportarán los usuarios según los filtros aplicados actualmente
                        (<?php echo (int)$total_usuarios; ?> registro<?php echo $total_usuarios === 1 ? '' : 's'; ?>).
                    </p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnMarcarTodasColumnas">Marcar todas</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDesmarcarTodasColumnas">Desmarcar todas</button>
                    </div>
                    <div class="list-group">
                        <?php foreach ($columnas_exportables as $clave => $etiqueta): ?>
                            <label class="list-group-item d-flex gap-2 align-items-center">
                                <input class="form-check-input mt-0 flex-shrink-0 export-columna-check"
                                       type="checkbox"
                                       name="columnas[]"
                                       value="<?php echo htmlspecialchars($clave); ?>"
                                       checked>
                                <span><?php echo htmlspecialchars($etiqueta); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-download me-1"></i>Descargar CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Usuario -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formUsuario">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalUsuarioTitle">Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionUsuario" value="crear">
                    <input type="hidden" name="id" id="usuarioId">
                    
                    <div class="mb-3">
                        <label class="form-label">Nombre Completo *</label>
                        <input type="text" class="form-control" name="nombre_completo" id="nombreCompleto" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" class="form-control" name="email" id="emailUsuario" required>
                    </div>
                    
                    <div class="mb-3" id="passwordField">
                        <label class="form-label">Contraseña *</label>
                        <input type="password" class="form-control" name="password" id="passwordUsuario" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Rol *</label>
                        <select class="form-select" name="rol_id" id="rolUsuario" required>
                            <?php
                            $rol_etiquetas = ['super_admin' => 'Super Administrador', 'administrador' => 'Administrador', 'usuario' => 'Usuario básico'];
                            foreach ($roles as $rol):
                                $etiqueta = $rol_etiquetas[$rol['nombre']] ?? $rol['nombre'];
                            ?>
                                <option value="<?php echo $rol['id']; ?>"><?php echo htmlspecialchars($etiqueta); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Dependencia</label>
                        <select class="form-select" name="dependencia_id" id="dependenciaUsuario">
                            <option value="">Sin asignar</option>
                            <?php foreach ($dependencias as $dep): ?>
                                <option value="<?php echo $dep['id']; ?>"><?php echo htmlspecialchars($dep['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="activoField" style="display: none;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="activo" id="activoUsuario" checked>
                            <label class="form-check-label" for="activoUsuario">Usuario Activo</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Cambiar Password -->
<div class="modal fade" id="modalPassword" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar Contraseña</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="cambiar_password">
                    <input type="hidden" name="id" id="passwordUsuarioId">
                    
                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña *</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cambiar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editarUsuario(usuario) {
    document.getElementById('modalUsuarioTitle').textContent = 'Editar Usuario';
    document.getElementById('accionUsuario').value = 'editar';
    document.getElementById('usuarioId').value = usuario.id;
    document.getElementById('nombreCompleto').value = usuario.nombre_completo;
    document.getElementById('emailUsuario').value = usuario.email;
    document.getElementById('rolUsuario').value = usuario.rol_id;
    document.getElementById('dependenciaUsuario').value = usuario.dependencia_id || '';
    document.getElementById('activoUsuario').checked = usuario.activo == 1;
    document.getElementById('passwordField').style.display = 'none';
    document.getElementById('passwordUsuario').removeAttribute('required');
    document.getElementById('activoField').style.display = 'block';
    
    new bootstrap.Modal(document.getElementById('modalUsuario')).show();
}

function cambiarPassword(usuarioId) {
    document.getElementById('passwordUsuarioId').value = usuarioId;
    new bootstrap.Modal(document.getElementById('modalPassword')).show();
}

function confirmarEliminacionUsuario(id, email) {
    if (id === <?php echo (int)($_SESSION['usuario_id'] ?? 0); ?>) {
        alert('No puedes eliminar tu propio usuario.');
        return false;
    }
    return confirm('¿Eliminar el usuario ' + email + ' (ID: ' + id + ')?\\n\\nEsta acción es irreversible y se usará solo por duplicidad u otros motivos.');
}

document.getElementById('btnMarcarTodasColumnas')?.addEventListener('click', function() {
    document.querySelectorAll('.export-columna-check').forEach(function(cb) { cb.checked = true; });
});
document.getElementById('btnDesmarcarTodasColumnas')?.addEventListener('click', function() {
    document.querySelectorAll('.export-columna-check').forEach(function(cb) { cb.checked = false; });
});
document.getElementById('formExportarUsuarios')?.addEventListener('submit', function(e) {
    var marcadas = document.querySelectorAll('.export-columna-check:checked').length;
    if (marcadas === 0) {
        e.preventDefault();
        alert('Seleccione al menos una columna para descargar.');
    }
});

// Reset modal al cerrar
document.getElementById('modalUsuario').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formUsuario').reset();
    document.getElementById('modalUsuarioTitle').textContent = 'Nuevo Usuario';
    document.getElementById('accionUsuario').value = 'crear';
    document.getElementById('passwordField').style.display = 'block';
    document.getElementById('passwordUsuario').setAttribute('required', 'required');
    document.getElementById('activoField').style.display = 'none';
});
</script>

<?php require_once '../includes/footer.php'; ?>
