<?php
require_once '../config/config.php';
requerirPermiso('gestionar_experiencia');
require_once dirname(__DIR__) . '/experiencia/helpers.php';

$seccion_slug_raw = trim($_GET['seccion'] ?? '');
$seccion = experienciaSeccionPorSlug($seccion_slug_raw);
if (!$seccion) {
    header('Location: experiencia.php');
    exit;
}
$seccion_slug = $seccion['slug'];
if ($seccion_slug_raw !== '' && $seccion_slug_raw !== $seccion_slug) {
    header('Location: experiencia_detalle.php?seccion=' . urlencode($seccion_slug));
    exit;
}

$pdo = getDBConnection();
$contenido = null;
$tablas_ok = true;
$archivos = [];
$modulos = [];


try {
    $contenido = getExperienciaContenido($pdo, $seccion_slug, true);
} catch (Exception $e) {
    $tablas_ok = false;
}

$page_title = 'Experiencia: ' . $seccion['titulo'];
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

if (!$tablas_ok || !$contenido) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Ejecute la migración docs/experiencia_contenido.sql en la base de datos.</div></div>';
    require_once '../includes/footer.php';
    exit;
}

$contenido_id = (int)$contenido['id'];
$upload_dir = rtrim(UPLOAD_PATH_EXPERIENCIA, '/\\') . DIRECTORY_SEPARATOR;
$ext_permitidas = ALLOWED_EXPERIENCIA_EXT;
$mimes_permitidos = ALLOWED_EXPERIENCIA_MIMES;
$max_size = MAX_DOCUMENT_SIZE;
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$mensaje = '';
$tipo_mensaje = '';
$tabla_acceso_modulo_ok = true;
$tabla_acceso_archivo_ok = true;

try {
    $pdo->query("SELECT 1 FROM experiencia_modulo_usuarios LIMIT 1");
} catch (Exception $e) {
    $tabla_acceso_modulo_ok = false;
}

try {
    $pdo->query("SELECT 1 FROM experiencia_archivo_usuarios LIMIT 1");
} catch (Exception $e) {
    $tabla_acceso_archivo_ok = false;
}

$usuarios_registrados = [];
try {
    $usuarios_registrados = $pdo->query("
        SELECT u.id, u.nombre_completo, u.email, u.activo, d.nombre AS dependencia_nombre
        FROM usuarios u
        LEFT JOIN dependencias d ON d.id = u.dependencia_id
        ORDER BY u.nombre_completo ASC, u.email ASC
    ")->fetchAll();
} catch (Exception $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar_acceso_modulo') {
        $modulo_id = (int)($_POST['modulo_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM experiencia_modulos WHERE id = ? AND contenido_id = ?");
        $stmt->execute([$modulo_id, $contenido_id]);
        if (!$stmt->fetch()) {
            $mensaje = 'Módulo no válido.';
            $tipo_mensaje = 'danger';
        } elseif (!$tabla_acceso_modulo_ok) {
            $mensaje = 'Ejecute la migración docs/experiencia_modulo_usuarios.sql en la base de datos.';
            $tipo_mensaje = 'danger';
        } else {
            $usuario_ids = isset($_POST['usuario_ids']) && is_array($_POST['usuario_ids']) ? $_POST['usuario_ids'] : [];
            try {
                guardarUsuariosModuloExperiencia($pdo, $modulo_id, $usuario_ids);
                $mensaje = 'Acceso del módulo actualizado.';
                $tipo_mensaje = 'success';
                registrarLog($_SESSION['usuario_id'], 'Actualizar acceso módulo Experiencia', 'Experiencia', "Módulo ID: $modulo_id");
            } catch (Exception $e) {
                $mensaje = 'Error al guardar el acceso del módulo.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'guardar_acceso_archivo') {
        $modulo_id = (int)($_POST['modulo_id'] ?? 0);
        $archivo_id = (int)($_POST['archivo_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT a.id FROM experiencia_archivos a
            INNER JOIN experiencia_modulos m ON m.id = a.modulo_id
            WHERE a.id = ? AND a.modulo_id = ? AND m.contenido_id = ?
        ");
        $stmt->execute([$archivo_id, $modulo_id, $contenido_id]);
        if (!$stmt->fetch()) {
            $mensaje = 'Documento o módulo no válido.';
            $tipo_mensaje = 'danger';
        } elseif (!$tabla_acceso_archivo_ok) {
            $mensaje = 'Ejecute la migración docs/experiencia_archivo_usuarios.sql en la base de datos.';
            $tipo_mensaje = 'danger';
        } else {
            $usuario_ids = isset($_POST['usuario_ids']) && is_array($_POST['usuario_ids']) ? $_POST['usuario_ids'] : [];
            try {
                guardarUsuariosArchivoExperiencia($pdo, $modulo_id, $archivo_id, $usuario_ids);
                $mensaje = 'Acceso del documento actualizado.';
                $tipo_mensaje = 'success';
                registrarLog($_SESSION['usuario_id'], 'Actualizar acceso documento Experiencia', 'Experiencia', "Módulo ID: $modulo_id, Archivo ID: $archivo_id");
            } catch (Exception $e) {
                $mensaje = 'Error al guardar el acceso del documento.';
                $tipo_mensaje = 'danger';
            }
        }
    } elseif ($accion === 'agregar_modulo') {
        $titulo = trim(sanitizar($_POST['titulo_modulo'] ?? ''));
        $descripcion = trim(sanitizar($_POST['descripcion_modulo'] ?? ''));
        $orden = normalizarOrdenExperienciaModulo($pdo, $contenido_id, $_POST['orden_modulo'] ?? 0);
        $solo_visualizacion = isset($_POST['solo_visualizacion']) ? 1 : 0;
        if ($titulo !== '') {
            $stmt = $pdo->prepare("INSERT INTO experiencia_modulos (contenido_id, titulo, descripcion, orden, solo_visualizacion) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$contenido_id, $titulo, $descripcion, $orden, $solo_visualizacion]);
            $mensaje = 'Módulo creado en la posición ' . $orden . '.';
            $tipo_mensaje = 'success';
        }
    } elseif ($accion === 'editar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $titulo = trim(sanitizar($_POST['titulo_modulo'] ?? ''));
        $descripcion = trim(sanitizar($_POST['descripcion_modulo'] ?? ''));
        $orden = max(1, (int)($_POST['orden_modulo'] ?? 1));
        $solo_visualizacion = isset($_POST['solo_visualizacion']) ? 1 : 0;
        if ($titulo !== '') {
            $stmt = $pdo->prepare("UPDATE experiencia_modulos SET titulo = ?, descripcion = ?, orden = ?, solo_visualizacion = ? WHERE id = ? AND contenido_id = ?");
            $stmt->execute([$titulo, $descripcion, $orden, $solo_visualizacion, $modulo_id, $contenido_id]);
            $mensaje = 'Módulo actualizado. Ahora aparece en la posición ' . $orden . '.';
            $tipo_mensaje = 'success';
        }
    } elseif ($accion === 'eliminar_modulo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $stmt = $pdo->prepare("DELETE FROM experiencia_modulos WHERE id = ? AND contenido_id = ?");
        $stmt->execute([$modulo_id, $contenido_id]);
        $mensaje = 'Módulo eliminado.';
        $tipo_mensaje = 'success';
    } elseif ($accion === 'agregar_archivo') {
        $modulo_id = (int)$_POST['modulo_id'];
        $nombre = trim(sanitizar($_POST['nombre_archivo'] ?? ''));
        $descripcion = trim($_POST['descripcion_archivo'] ?? '');
        $orden = (int)($_POST['orden_archivo'] ?? 0);

        if ($nombre === '') {
            $mensaje = 'Nombre del archivo es obligatorio.';
            $tipo_mensaje = 'danger';
        } elseif (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $mensaje = 'Debe seleccionar un archivo (PDF, Word, Excel o PowerPoint).';
            $tipo_mensaje = 'danger';
        } else {
            $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['archivo']['tmp_name']);
            finfo_close($finfo);
            $es_video = in_array($ext, ['mp4', 'webm', 'ogg'], true);
            $max_size = $es_video ? MAX_VIDEO_SIZE : MAX_DOCUMENT_SIZE;
            if (!in_array($ext, $ext_permitidas) || !in_array($mime, $mimes_permitidos)) {
                $mensaje = 'Tipo no permitido. Use PDF, Word, Excel, PowerPoint o video (MP4, WebM, OGG).';
                $tipo_mensaje = 'danger';
            } elseif ($_FILES['archivo']['size'] > $max_size) {
                $max_mb = round($max_size / (1024 * 1024));
                $mensaje = 'El archivo supera ' . $max_mb . ' MB.';
                $tipo_mensaje = 'danger';
            } else {
                $archivo_nombre = uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $upload_dir . $archivo_nombre)) {
                    $stmt = $pdo->prepare("INSERT INTO experiencia_archivos (modulo_id, nombre, descripcion, archivo, orden, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$modulo_id, $nombre, $descripcion ?: null, $archivo_nombre, $orden, $_SESSION['usuario_id']]);
                    $mensaje = 'Archivo agregado.';
                    $tipo_mensaje = 'success';
                } else {
                    $mensaje = 'Error al guardar el archivo.';
                    $tipo_mensaje = 'danger';
                }
            }
        }
    } elseif ($accion === 'eliminar_archivo') {
        $archivo_id = (int)$_POST['archivo_id'];
        $stmt = $pdo->prepare("
            SELECT a.archivo FROM experiencia_archivos a
            INNER JOIN experiencia_modulos m ON m.id = a.modulo_id
            WHERE a.id = ? AND m.contenido_id = ?
        ");
        $stmt->execute([$archivo_id, $contenido_id]);
        $row = $stmt->fetch();
        if ($row) {
            $stmt = $pdo->prepare("DELETE FROM experiencia_archivos WHERE id = ?");
            $stmt->execute([$archivo_id]);
            if ($row['archivo'] && file_exists($upload_dir . $row['archivo'])) @unlink($upload_dir . $row['archivo']);
            $mensaje = 'Archivo eliminado.';
            $tipo_mensaje = 'success';
        }
    }
}

[$modulos, $archivos_por_modulo] = cargarModulosExperiencia($pdo, $contenido_id);
//Busqueda de modulos y archivos
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($busqueda !== '') {
    $termino = mb_strtolower($busqueda, 'UTF-8');

    $modulos_visibles = [];
    $archivos_por_modulo_visibles = [];

    foreach ($modulos as $mod) {
        $coincide_modulo = mb_stripos($mod['titulo'], $termino) !== false
            || mb_stripos($mod['descripcion'] ?? '', $termino) !== false;

        $archivos_mod = $archivos_por_modulo[$mod['id']] ?? [];
        $archivos_coincidentes = array_values(array_filter($archivos_mod, function ($ar) use ($termino) {
            return mb_stripos($ar['nombre'], $termino) !== false
                || mb_stripos($ar['descripcion'] ?? '', $termino) !== false;
        }));

        // Se muestra el módulo si coincide su título/descripción,
        // o si alguno de sus archivos coincide con la búsqueda.
        if ($coincide_modulo || !empty($archivos_coincidentes)) {
            $modulos_visibles[] = $mod;
            $archivos_por_modulo_visibles[$mod['id']] = $coincide_modulo ? $archivos_mod : $archivos_coincidentes;
        }
    }
} else {
    $modulos_visibles = $modulos;
    $archivos_por_modulo_visibles = $archivos_por_modulo;
}
$restricciones_modulos = $tabla_acceso_modulo_ok ? cargarRestriccionesModulosExperiencia($pdo, $contenido_id) : [];
$restricciones_archivos = $tabla_acceso_archivo_ok ? cargarRestriccionesArchivosExperiencia($pdo, $contenido_id) : [];
$siguiente_orden_modulo = siguienteOrdenModuloExperiencia($pdo, $contenido_id);
$archivos = [];
foreach ($archivos_por_modulo as $lista) {
    foreach ($lista as $a) {
        $archivos[] = $a;
    }
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-heart me-2"></i><?php echo htmlspecialchars($seccion['titulo']); ?></h2>
            <p class="text-muted mb-0">Sección Experiencia</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>experiencia/index.php?seccion=<?php echo urlencode($seccion_slug); ?>" class="btn btn-outline-primary spa-nav-link" target="_blank" rel="noopener">
                <i class="bi bi-eye me-1"></i>Vista pública
            </a>
            <a href="experiencia.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Volver</a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$tabla_acceso_modulo_ok): ?>
        <div class="alert alert-warning">
            <i class="bi bi-shield-lock me-2"></i>
            Para restringir módulos por usuario, ejecute <code>docs/experiencia_modulo_usuarios.sql</code> en la base de datos.
        </div>
    <?php endif; ?>

    <?php if (!$tabla_acceso_archivo_ok): ?>
        <div class="alert alert-warning">
            <i class="bi bi-shield-lock me-2"></i>
            Para restringir documentos por usuario, ejecute <code>docs/experiencia_archivo_usuarios.sql</code>.
            Si ya creó la tabla sin <code>modulo_id</code>, use <code>docs/experiencia_archivo_usuarios_agregar_modulo.sql</code>.
        </div>
    <?php endif; ?>

    <p class="text-muted small mb-4">
        Use el icono de escudo en cada <strong>módulo</strong> o en cada <strong>documento</strong> para elegir qué usuarios pueden verlo.
        Si no selecciona usuarios, será visible para todos los usuarios autenticados (respetando también la restricción del módulo, si existe).
    </p>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos</h5>
                    <form method="get" action="" class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0" style="max-width: 400px;">
                        <input type="hidden" name="seccion" value="<?php echo htmlspecialchars($seccion_slug); ?>">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar módulo o archivo..." value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
                        <?php if ($busqueda !== ''): ?>
                            <a href="?seccion=<?php echo urlencode($seccion_slug); ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                        <?php endif; ?>
                    </form>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalModulo">
                        <i class="bi bi-plus-circle me-1"></i>Nuevo módulo
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($modulos_visibles)): ?>
                        <p class="text-muted mb-0">
                            <?php echo $busqueda !== '' ? 'No se encontraron módulos ni archivos para "' . htmlspecialchars($busqueda) . '".' : 'No hay módulos. Cree uno para organizar los archivos de esta sección.'; ?>
                        </p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($modulos_visibles as $mod):

                                $cant = count($archivos_por_modulo[$mod['id']] ?? []);
                                $usuarios_modulo = $restricciones_modulos[(int)$mod['id']] ?? [];
                                $acceso_restringido = !empty($usuarios_modulo);
                            ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <span class="badge bg-primary me-1" title="Posición de visualización">Orden <?php echo max(0, (int)($mod['orden'] ?? 0)); ?></span>
                                        <strong><?php echo htmlspecialchars($mod['titulo']); ?></strong>
                                        <span class="badge bg-secondary ms-2"><?php echo $cant; ?> archivo(s)</span>
                                        <?php if ($acceso_restringido): ?>
                                            <span class="badge bg-warning text-dark ms-1">
                                                Restringido (<?php echo count($usuarios_modulo); ?> usuario<?php echo count($usuarios_modulo) === 1 ? '' : 's'; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success ms-1">Todos los usuarios</span>
                                        <?php endif; ?>
                                        <?php if (!empty($mod['solo_visualizacion'])): ?>
                                            <span class="badge bg-info text-dark ms-1">Solo visualización</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border ms-1">Descarga</span>
                                        <?php endif; ?>
                                        <?php if (!empty($mod['descripcion'])): ?>
                                            <p class="mb-0 small text-muted"><?php echo htmlspecialchars($mod['descripcion']); ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex gap-1">
                                        <?php if ($tabla_acceso_modulo_ok && !empty($usuarios_registrados)): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalAccesoModulo"
                                                onclick="abrirAccesoModulo(<?php echo htmlspecialchars(json_encode([
                                                                                'id' => (int)$mod['id'],
                                                                                'titulo' => $mod['titulo'],
                                                                                'usuarios' => $usuarios_modulo,
                                                                            ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="bi bi-shield-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editarModulo(<?php echo htmlspecialchars(json_encode($mod)); ?>)"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este módulo y sus archivos?');">
                                            <input type="hidden" name="accion" value="eliminar_modulo">
                                            <input type="hidden" name="modulo_id" value="<?php echo $mod['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-file-earmark me-2"></i>Archivos por módulo</h5>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalArchivo" <?php echo empty($modulos) ? 'disabled title="Cree al menos un módulo"' : ''; ?>>
                        <i class="bi bi-plus-circle me-1"></i>Agregar archivo
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($modulos)): ?>
                        <p class="text-muted">Cree primero un módulo y luego agregue archivos.</p>
                    <?php elseif (empty($modulos_visibles)): ?>
                        <p class="text-muted">
                            <?php echo $busqueda !== '' ? 'Sin resultados para la búsqueda.' : 'No hay archivos. Use «Agregar archivo».'; ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($modulos_visibles as $mod):
                            $lista = $archivos_por_modulo_visibles[$mod['id']] ?? [];
                            if (empty($lista)) continue; // el módulo coincidió pero no tiene archivos que mostrar aquí
                        ?>

                            <h6 class="mt-3 mb-2 text-primary"><i class="bi bi-layers-half me-1"></i><?php echo htmlspecialchars($mod['titulo']); ?></h6>
                            <?php if (empty($lista)): ?>
                                <p class="small text-muted">Sin archivos.</p>
                            <?php else: ?>
                                <div class="list-group mb-3">
                                    <?php foreach ($lista as $ar):
                                        $url_download = UPLOAD_URL_EXPERIENCIA . $ar['archivo'];
                                        $icono = iconoArchivoExperiencia($ar['archivo']);
                                        $usuarios_archivo = $restricciones_archivos[(int)$mod['id']][(int)$ar['id']] ?? [];
                                        $archivo_restringido = !empty($usuarios_archivo);
                                    ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div class="flex-grow-1">
                                                <i class="bi bi-<?php echo $icono; ?> me-2"></i>
                                                <strong><?php echo htmlspecialchars($ar['nombre']); ?></strong>
                                                <?php if ($archivo_restringido): ?>
                                                    <span class="badge bg-warning text-dark ms-1">
                                                        Restringido (<?php echo count($usuarios_archivo); ?> usuario<?php echo count($usuarios_archivo) === 1 ? '' : 's'; ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-success ms-1">Todos los usuarios</span>
                                                <?php endif; ?>
                                                <?php if (!empty($ar['descripcion'])): ?>
                                                    <p class="mb-1 small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></p>
                                                <?php endif; ?>
                                            </div>
 
                                            <div class="d-flex gap-1">
                                                <?php if ($tabla_acceso_archivo_ok && !empty($usuarios_registrados)): ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalAccesoArchivo"
                                                        onclick="abrirAccesoArchivo(<?php echo htmlspecialchars(json_encode([
                                                                                        'modulo_id' => (int)$mod['id'],
                                                                                        'id' => (int)$ar['id'],
                                                                                        'titulo' => $ar['nombre'],
                                                                                        'usuarios' => $usuarios_archivo,
                                                                                    ]), ENT_QUOTES, 'UTF-8'); ?>)"
                                                        title="Control de acceso del documento">
                                                        <i class="bi bi-shield-lock"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="<?php echo htmlspecialchars($url_download); ?>" class="btn btn-sm btn-outline-primary" download><i class="bi bi-download"></i></a>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este archivo?');">
                                                    <input type="hidden" name="accion" value="eliminar_archivo">
                                                    <input type="hidden" name="archivo_id" value="<?php echo $ar['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                </form> 
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Sección</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($seccion['intro'])): ?>
                        <p class="small text-muted"><?php echo nl2br(htmlspecialchars($seccion['intro'])); ?></p>
                    <?php endif; ?>
                    <p class="small mb-0">Este texto aparece como información en el panel lateral (tooltip) y como encabezado en la vista pública.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($tabla_acceso_modulo_ok): ?>
    <div class="modal fade" id="modalAccesoModulo" tabindex="-1" aria-labelledby="modalAccesoModuloLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="accion" value="guardar_acceso_modulo">
                    <input type="hidden" name="modulo_id" id="accesoModuloId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAccesoModuloLabel">Control de acceso del módulo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            Módulo: <strong id="accesoModuloTitulo"></strong><br>
                            Seleccione los usuarios que podrán ver este módulo. Sin usuarios seleccionados = visible para todos.
                        </p>
                        <?php if (empty($usuarios_registrados)): ?>
                            <p class="text-muted mb-0">No hay usuarios registrados en el sistema.</p>
                        <?php else: ?>
                            <div class="mb-3">
                                <input type="search" class="form-control" id="buscarUsuarioAcceso" placeholder="Buscar por nombre, correo o dependencia…" autocomplete="off">
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btnSeleccionarUsuariosVisibles">Marcar visibles</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDesmarcarUsuariosAcceso">Desmarcar todos</button>
                            </div>
                            <div class="list-group" id="listaUsuariosAcceso">
                                <?php foreach ($usuarios_registrados as $usuario): ?>
                                    <label class="list-group-item d-flex gap-2 align-items-start acceso-usuario-item"
                                        data-busqueda="<?php echo htmlspecialchars(mb_strtolower(
                                                            ($usuario['nombre_completo'] ?? '') . ' ' .
                                                                ($usuario['email'] ?? '') . ' ' .
                                                                ($usuario['dependencia_nombre'] ?? '')
                                                        ), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input class="form-check-input mt-1 flex-shrink-0 acceso-modulo-usuario-check"
                                            type="checkbox"
                                            name="usuario_ids[]"
                                            value="<?php echo (int)$usuario['id']; ?>">
                                        <span class="flex-grow-1">
                                            <span class="fw-semibold"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></span>
                                            <?php if (!(int)$usuario['activo']): ?>
                                                <span class="badge bg-secondary ms-1">Inactivo</span>
                                            <?php endif; ?>
                                            <span class="d-block small text-muted"><?php echo htmlspecialchars($usuario['email']); ?></span>
                                            <?php if (!empty($usuario['dependencia_nombre'])): ?>
                                                <span class="d-block small text-muted"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($usuario['dependencia_nombre']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="small text-muted mt-2 mb-0" id="accesoUsuariosResumen"></p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" <?php echo empty($usuarios_registrados) ? 'disabled' : ''; ?>>
                            <i class="bi bi-check2 me-1"></i>Guardar acceso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tabla_acceso_archivo_ok): ?>
    <div class="modal fade" id="modalAccesoArchivo" tabindex="-1" aria-labelledby="modalAccesoArchivoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="accion" value="guardar_acceso_archivo">
                    <input type="hidden" name="modulo_id" id="accesoArchivoModuloId" value="">
                    <input type="hidden" name="archivo_id" id="accesoArchivoId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAccesoArchivoLabel">Control de acceso del documento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            Documento: <strong id="accesoArchivoTitulo"></strong><br>
                            Seleccione los usuarios que podrán ver o descargar este documento en este módulo. Sin usuarios seleccionados = visible para todos los que puedan ver el módulo.
                        </p>
                        <?php if (empty($usuarios_registrados)): ?>
                            <p class="text-muted mb-0">No hay usuarios registrados en el sistema.</p>
                        <?php else: ?>
                            <div class="mb-3">
                                <input type="search" class="form-control" id="buscarUsuarioAccesoArchivo" placeholder="Buscar por nombre, correo o dependencia…" autocomplete="off">
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btnSeleccionarUsuariosArchivoVisibles">Marcar visibles</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDesmarcarUsuariosArchivoAcceso">Desmarcar todos</button>
                            </div>
                            <div class="list-group" id="listaUsuariosAccesoArchivo">
                                <?php foreach ($usuarios_registrados as $usuario): ?>
                                    <label class="list-group-item d-flex gap-2 align-items-start acceso-usuario-archivo-item"
                                        data-busqueda="<?php echo htmlspecialchars(mb_strtolower(
                                                            ($usuario['nombre_completo'] ?? '') . ' ' .
                                                                ($usuario['email'] ?? '') . ' ' .
                                                                ($usuario['dependencia_nombre'] ?? '')
                                                        ), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input class="form-check-input mt-1 flex-shrink-0 acceso-archivo-usuario-check"
                                            type="checkbox"
                                            name="usuario_ids[]"
                                            value="<?php echo (int)$usuario['id']; ?>">
                                        <span class="flex-grow-1">
                                            <span class="fw-semibold"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></span>
                                            <?php if (!(int)$usuario['activo']): ?>
                                                <span class="badge bg-secondary ms-1">Inactivo</span>
                                            <?php endif; ?>
                                            <span class="d-block small text-muted"><?php echo htmlspecialchars($usuario['email']); ?></span>
                                            <?php if (!empty($usuario['dependencia_nombre'])): ?>
                                                <span class="d-block small text-muted"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($usuario['dependencia_nombre']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="small text-muted mt-2 mb-0" id="accesoArchivoUsuariosResumen"></p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" <?php echo empty($usuarios_registrados) ? 'disabled' : ''; ?>>
                            <i class="bi bi-check2 me-1"></i>Guardar acceso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="modalModulo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="formModulo">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalModuloTitle">Nuevo módulo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accionModulo" value="agregar_modulo">
                    <input type="hidden" name="modulo_id" id="moduloId">
                    <div class="mb-3">
                        <label class="form-label">Título del módulo *</label>
                        <input type="text" class="form-control" name="titulo_modulo" id="tituloModulo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion_modulo" id="descripcionModulo" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden de aparición *</label>
                        <input type="number" class="form-control" name="orden_modulo" id="ordenModulo" value="<?php echo (int)$siguiente_orden_modulo; ?>" min="1" required>
                        <div class="form-text">1 = primero, 2 = segundo, 3 = tercero, y así sucesivamente.</div>
                    </div>
                    <div class="mb-0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="solo_visualizacion" id="soloVisualizacionModulo" value="1">
                            <label class="form-check-label" for="soloVisualizacionModulo">Solo visualización</label>
                        </div>
                        <div class="form-text">Desactivado: descarga. Activado: visualización en pantalla completa.</div>
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

<div class="modal fade" id="modalArchivo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar archivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="agregar_archivo">
                    <div class="mb-3">
                        <label class="form-label">Módulo *</label>
                        <select class="form-select" name="modulo_id" required>
                            <option value="">Seleccione un módulo</option>
                            <?php foreach ($modulos as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['titulo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre del archivo *</label>
                        <input type="text" class="form-control" name="nombre_archivo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion_archivo" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo * (PDF, Word, Excel, PowerPoint, MP4/WebM/OGG. Máx. <?php echo (int) ceil(MAX_DOCUMENT_SIZE / (1024 * 1024)); ?> MB documentos / <?php echo (int) ceil(MAX_VIDEO_SIZE / (1024 * 1024)); ?> MB video)</label>
                        <input type="file" class="form-control" name="archivo" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.webm,.ogg,video/mp4,video/webm,video/ogg" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Orden</label>
                        <input type="number" class="form-control" name="orden_archivo" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Agregar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function abrirAccesoModulo(data) {
        document.getElementById('accesoModuloId').value = data.id;
        document.getElementById('accesoModuloTitulo').textContent = data.titulo;
        var seleccionados = (data.usuarios || []).map(String);
        document.querySelectorAll('.acceso-modulo-usuario-check').forEach(function(cb) {
            cb.checked = seleccionados.indexOf(cb.value) !== -1;
        });
        var buscar = document.getElementById('buscarUsuarioAcceso');
        if (buscar) {
            buscar.value = '';
            filtrarUsuariosAcceso('');
        }
        actualizarResumenUsuariosAcceso();
    }

    function filtrarUsuariosAcceso(termino) {
        termino = (termino || '').toLowerCase().trim();
        document.querySelectorAll('.acceso-usuario-item').forEach(function(item) {
            var texto = item.getAttribute('data-busqueda') || '';
            item.classList.toggle('d-none', termino !== '' && texto.indexOf(termino) === -1);
        });
    }

    function actualizarResumenUsuariosAcceso() {
        var resumen = document.getElementById('accesoUsuariosResumen');
        if (!resumen) return;
        var total = document.querySelectorAll('.acceso-modulo-usuario-check').length;
        var marcados = document.querySelectorAll('.acceso-modulo-usuario-check:checked').length;
        resumen.textContent = marcados + ' de ' + total + ' usuario(s) seleccionado(s).';
    }
    var buscarUsuarioAcceso = document.getElementById('buscarUsuarioAcceso');
    if (buscarUsuarioAcceso) {
        buscarUsuarioAcceso.addEventListener('input', function() {
            filtrarUsuariosAcceso(this.value);
        });
    }
    var btnSeleccionarVisibles = document.getElementById('btnSeleccionarUsuariosVisibles');
    if (btnSeleccionarVisibles) {
        btnSeleccionarVisibles.addEventListener('click', function() {
            document.querySelectorAll('.acceso-usuario-item:not(.d-none) .acceso-modulo-usuario-check').forEach(function(cb) {
                cb.checked = true;
            });
            actualizarResumenUsuariosAcceso();
        });
    }
    var btnDesmarcar = document.getElementById('btnDesmarcarUsuariosAcceso');
    if (btnDesmarcar) {
        btnDesmarcar.addEventListener('click', function() {
            document.querySelectorAll('.acceso-modulo-usuario-check').forEach(function(cb) {
                cb.checked = false;
            });
            actualizarResumenUsuariosAcceso();
        });
    }
    document.querySelectorAll('.acceso-modulo-usuario-check').forEach(function(cb) {
        cb.addEventListener('change', actualizarResumenUsuariosAcceso);
    });

    function abrirAccesoArchivo(data) {
        document.getElementById('accesoArchivoModuloId').value = data.modulo_id;
        document.getElementById('accesoArchivoId').value = data.id;
        document.getElementById('accesoArchivoTitulo').textContent = data.titulo;
        var seleccionados = (data.usuarios || []).map(String);
        document.querySelectorAll('.acceso-archivo-usuario-check').forEach(function(cb) {
            cb.checked = seleccionados.indexOf(cb.value) !== -1;
        });
        var buscar = document.getElementById('buscarUsuarioAccesoArchivo');
        if (buscar) {
            buscar.value = '';
            filtrarUsuariosAccesoArchivo('');
        }
        actualizarResumenUsuariosAccesoArchivo();
    }

    function filtrarUsuariosAccesoArchivo(termino) {
        termino = (termino || '').toLowerCase().trim();
        document.querySelectorAll('.acceso-usuario-archivo-item').forEach(function(item) {
            var texto = item.getAttribute('data-busqueda') || '';
            item.classList.toggle('d-none', termino !== '' && texto.indexOf(termino) === -1);
        });
    }

    function actualizarResumenUsuariosAccesoArchivo() {
        var resumen = document.getElementById('accesoArchivoUsuariosResumen');
        if (!resumen) return;
        var total = document.querySelectorAll('.acceso-archivo-usuario-check').length;
        var marcados = document.querySelectorAll('.acceso-archivo-usuario-check:checked').length;
        resumen.textContent = marcados + ' de ' + total + ' usuario(s) seleccionado(s).';
    }
    var buscarUsuarioAccesoArchivo = document.getElementById('buscarUsuarioAccesoArchivo');
    if (buscarUsuarioAccesoArchivo) {
        buscarUsuarioAccesoArchivo.addEventListener('input', function() {
            filtrarUsuariosAccesoArchivo(this.value);
        });
    }
    var btnSeleccionarArchivoVisibles = document.getElementById('btnSeleccionarUsuariosArchivoVisibles');
    if (btnSeleccionarArchivoVisibles) {
        btnSeleccionarArchivoVisibles.addEventListener('click', function() {
            document.querySelectorAll('.acceso-usuario-archivo-item:not(.d-none) .acceso-archivo-usuario-check').forEach(function(cb) {
                cb.checked = true;
            });
            actualizarResumenUsuariosAccesoArchivo();
        });
    }
    var btnDesmarcarArchivo = document.getElementById('btnDesmarcarUsuariosArchivoAcceso');
    if (btnDesmarcarArchivo) {
        btnDesmarcarArchivo.addEventListener('click', function() {
            document.querySelectorAll('.acceso-archivo-usuario-check').forEach(function(cb) {
                cb.checked = false;
            });
            actualizarResumenUsuariosAccesoArchivo();
        });
    }
    document.querySelectorAll('.acceso-archivo-usuario-check').forEach(function(cb) {
        cb.addEventListener('change', actualizarResumenUsuariosAccesoArchivo);
    });
    var siguienteOrdenModulo = <?php echo (int)$siguiente_orden_modulo; ?>;

    function editarModulo(mod) {
        document.getElementById('modalModuloTitle').textContent = 'Editar módulo';
        document.getElementById('accionModulo').value = 'editar_modulo';
        document.getElementById('moduloId').value = mod.id;
        document.getElementById('tituloModulo').value = mod.titulo;
        document.getElementById('descripcionModulo').value = mod.descripcion || '';
        var ordenActual = parseInt(mod.orden || 0, 10);
        document.getElementById('ordenModulo').value = ordenActual > 0 ? ordenActual : 1;
        document.getElementById('soloVisualizacionModulo').checked = !!parseInt(mod.solo_visualizacion || 0, 10);
        new bootstrap.Modal(document.getElementById('modalModulo')).show();
    }
    document.getElementById('modalModulo').addEventListener('hidden.bs.modal', function() {
        document.getElementById('formModulo').reset();
        document.getElementById('modalModuloTitle').textContent = 'Nuevo módulo';
        document.getElementById('accionModulo').value = 'agregar_modulo';
        document.getElementById('moduloId').value = '';
        document.getElementById('ordenModulo').value = siguienteOrdenModulo;
        document.getElementById('soloVisualizacionModulo').checked = false;
    });
    document.getElementById('modalModulo').addEventListener('show.bs.modal', function() {
        if (document.getElementById('accionModulo').value === 'agregar_modulo') {
            document.getElementById('ordenModulo').value = siguienteOrdenModulo;
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>