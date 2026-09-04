<?php
/**
 * Convierte slugs antiguos al slug canónico (alineado con el título del menú).
 * Así enlaces viejos siguen abriendo la sección correcta.
 */
function experienciaSlugCanonico($slug) {
    static $aliases = [
        'somos-experiencia' => 'conoce-experiencia-san-francisco',
        'herramientas-para-la-atencion' => 'asi-nos-comunicamos-mejor',
        'medimos-para-mejorar' => 'escuchamos-para-mejorar',
        'tips-y-recursos' => 'nuestra-dupla-medimos-y-mejoramos',
        'de-las-ideas-a-la-accion' => 'escuela-de-experiencia-tips-y-recursos',
        'historias-mercen-ser-contadas' => 'todos-somos-parte-del-cambio',
        // Antes "todos-somos-parte-del-cambio" era el Centro Documental del SGC
        'centro-documental-sgc' => 'centro-documental-del-sgc',
    ];
    return $aliases[$slug] ?? $slug;
}

function experienciaSeccionPorSlug($slug) {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        $secciones = require __DIR__ . '/sections.php';
        foreach ($secciones as $seccion) {
            $mapa[$seccion['slug']] = $seccion;
        }
    }
    $canonico = experienciaSlugCanonico($slug);
    return $mapa[$canonico] ?? null;
}

/**
 * Renombra slugs antiguos en BD para que coincidan con el menú.
 * Se ejecuta una vez por request como máximo.
 */
function migrarSlugsExperienciaSiNecesario(PDO $pdo) {
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $hecho = true;

    // Orden: primero liberar el slug que se reutiliza
    $renombres = [
        ['todos-somos-parte-del-cambio', 'centro-documental-del-sgc', 'Centro Documental del SGC'],
        ['somos-experiencia', 'conoce-experiencia-san-francisco', 'Conoce Experiencia San Francisco'],
        ['herramientas-para-la-atencion', 'asi-nos-comunicamos-mejor', 'Así nos comunicamos mejor'],
        ['medimos-para-mejorar', 'escuchamos-para-mejorar', 'Escuchamos para Mejorar'],
        ['tips-y-recursos', 'nuestra-dupla-medimos-y-mejoramos', 'Nuestra dupla: Medimos y Mejoramos'],
        ['de-las-ideas-a-la-accion', 'escuela-de-experiencia-tips-y-recursos', 'Escuela de Experiencia, tips y recursos'],
        ['historias-mercen-ser-contadas', 'todos-somos-parte-del-cambio', 'Todos Somos Parte del Cambio'],
    ];

    try {
        $existe = $pdo->prepare("SELECT id FROM experiencia_contenidos WHERE seccion_slug = ? LIMIT 1");
        $actualizar = $pdo->prepare("UPDATE experiencia_contenidos SET seccion_slug = ?, titulo = ? WHERE seccion_slug = ?");
        foreach ($renombres as $r) {
            [$viejo, $nuevo, $titulo] = $r;
            $existe->execute([$nuevo]);
            if ($existe->fetch()) {
                continue;
            }
            $existe->execute([$viejo]);
            if (!$existe->fetch()) {
                continue;
            }
            $actualizar->execute([$nuevo, $titulo, $viejo]);
        }

        try {
            $existePerfil = $pdo->prepare("SELECT id FROM experiencia_seccion_perfiles WHERE seccion_slug = ? LIMIT 1");
            $actualizarPerfil = $pdo->prepare("UPDATE experiencia_seccion_perfiles SET seccion_slug = ? WHERE seccion_slug = ?");
            foreach ($renombres as $r) {
                [$viejo, $nuevo] = $r;
                $existePerfil->execute([$nuevo]);
                if ($existePerfil->fetch()) {
                    continue;
                }
                $existePerfil->execute([$viejo]);
                if (!$existePerfil->fetch()) {
                    continue;
                }
                $actualizarPerfil->execute([$nuevo, $viejo]);
            }
        } catch (Exception $e) {
            // Tabla de perfiles por sección puede no existir
        }
    } catch (Exception $e) {
        // Si falla la migración, se sigue con el slug solicitado
    }
}

function getExperienciaContenido(PDO $pdo, $seccion_slug, $auto_crear = false) {
    $seccion_slug = experienciaSlugCanonico($seccion_slug);
    migrarSlugsExperienciaSiNecesario($pdo);

    $stmt = $pdo->prepare("SELECT * FROM experiencia_contenidos WHERE seccion_slug = ? LIMIT 1");
    $stmt->execute([$seccion_slug]);
    $contenido = $stmt->fetch();
    if ($contenido || !$auto_crear) {
        return $contenido ?: null;
    }

    $seccion = experienciaSeccionPorSlug($seccion_slug);
    if (!$seccion) {
        return null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO experiencia_contenidos (seccion_slug, titulo, descripcion, activo)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([
        $seccion_slug,
        $seccion['titulo'],
        $seccion['intro'] ?? null,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM experiencia_contenidos WHERE seccion_slug = ? LIMIT 1");
    $stmt->execute([$seccion_slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Orden de visualización: 1 = primero, 2 = segundo, etc.
 * Los módulos/archivos con orden 0 o vacío quedan al final.
 */
function sqlOrdenExperienciaAsc($columna = 'orden') {
    return "CASE WHEN {$columna} IS NULL OR {$columna} <= 0 THEN 999999 ELSE {$columna} END ASC, id ASC";
}

/** Siguiente número de orden disponible para un módulo de la sección. */
function siguienteOrdenModuloExperiencia(PDO $pdo, $contenido_id) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(orden), 0)
        FROM experiencia_modulos
        WHERE contenido_id = ? AND orden > 0
    ");
    $stmt->execute([(int)$contenido_id]);
    return (int)$stmt->fetchColumn() + 1;
}

/** Normaliza el orden: mínimo 1. Si viene vacío o 0, asigna el siguiente disponible. */
function normalizarOrdenExperienciaModulo(PDO $pdo, $contenido_id, $orden) {
    $orden = (int)$orden;
    if ($orden < 1) {
        return siguienteOrdenModuloExperiencia($pdo, $contenido_id);
    }
    return $orden;
}

function cargarModulosExperiencia(PDO $pdo, $contenido_id) {
    $modulos = [];
    $archivos_por_modulo = [];

    try {
        $orden_sql = sqlOrdenExperienciaAsc('orden');
        $stmt = $pdo->prepare("
            SELECT * FROM experiencia_modulos
            WHERE contenido_id = ?
            ORDER BY {$orden_sql}
        ");
        $stmt->execute([$contenido_id]);
        $modulos = $stmt->fetchAll();

        foreach ($modulos as $mod) {
            $stmt2 = $pdo->prepare("
                SELECT * FROM experiencia_archivos
                WHERE modulo_id = ?
                ORDER BY {$orden_sql}
            ");
            $stmt2->execute([$mod['id']]);
            $archivos_por_modulo[$mod['id']] = $stmt2->fetchAll();
        }
    } catch (Exception $e) {
        $modulos = [];
        $archivos_por_modulo = [];
    }

    return [$modulos, $archivos_por_modulo];
}

function iconoArchivoExperiencia($archivo) {
    $ext = strtolower(pathinfo($archivo ?? '', PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'file-earmark-pdf';
    }
    if (in_array($ext, ['doc', 'docx'], true)) {
        return 'file-earmark-word';
    }
    if (in_array($ext, ['xls', 'xlsx'], true)) {
        return 'file-earmark-excel';
    }
    if (in_array($ext, ['ppt', 'pptx'], true)) {
        return 'file-earmark-slides';
    }
    if (in_array($ext, ['mp4', 'webm', 'ogg'], true)) {
        return 'file-earmark-play';
    }
    return 'file-earmark';
}

function experienciaViewerSecret() {
    static $secret = null;
    if ($secret === null) {
        $secret = hash('sha256', (DB_NAME ?? 'osf') . '|experiencia_viewer|' . BASE_PATH);
    }
    return $secret;
}

function generarTokenVisorExperienciaArchivo($archivo_id, $modulo_id) {
    $exp = time() + EXPERIENCIA_VIEWER_TOKEN_TTL;
    $payload = (int)$archivo_id . '|' . (int)$modulo_id . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, experienciaViewerSecret());
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

function validarTokenVisorExperienciaArchivo($token, $archivo_id, $modulo_id) {
    if ($token === '' || $token === null) {
        return false;
    }
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);
    if ($decoded === false) {
        return false;
    }
    $parts = explode('|', $decoded);
    if (count($parts) !== 4) {
        return false;
    }
    [$id, $mod_id, $exp, $sig] = $parts;
    if ((int)$id !== (int)$archivo_id || (int)$mod_id !== (int)$modulo_id) {
        return false;
    }
    if ((int)$exp < time()) {
        return false;
    }
    $payload = $id . '|' . $mod_id . '|' . $exp;
    $expected = hash_hmac('sha256', $payload, experienciaViewerSecret());
    return hash_equals($expected, $sig);
}

function mimeTipoExperienciaArchivo($ext) {
    $map = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    return $map[strtolower($ext)] ?? 'application/octet-stream';
}

function urlVisorExperienciaArchivo($archivo_id, $modulo_id, $con_token = true) {
    $url = BASE_URL . 'experiencia/ver_archivo.php?id=' . (int)$archivo_id . '&modulo_id=' . (int)$modulo_id;
    if ($con_token) {
        $url .= '&token=' . urlencode(generarTokenVisorExperienciaArchivo($archivo_id, $modulo_id));
    }
    return $url;
}

function urlAbsolutaVisorExperienciaArchivo($archivo_id, $modulo_id) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = urlVisorExperienciaArchivo($archivo_id, $modulo_id, true);
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    return $scheme . '://' . $host . $path;
}

function getArchivoExperienciaConModulo(PDO $pdo, $archivo_id, $modulo_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, m.id AS modulo_id, m.contenido_id, m.solo_visualizacion
        FROM experiencia_archivos a
        INNER JOIN experiencia_modulos m ON m.id = a.modulo_id
        WHERE a.id = ? AND a.modulo_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$archivo_id, (int)$modulo_id]);
    return $stmt->fetch() ?: null;
}

function usuarioPuedeAccederArchivoExperiencia(PDO $pdo, $archivo_id, $modulo_id, $usuario_id = null) {
    if (usuarioPuedeGestionarExperiencia()) {
        return true;
    }
    $usuario_id = (int)($usuario_id ?? ($_SESSION['usuario_id'] ?? 0));
    if ($usuario_id <= 0) {
        return false;
    }
    $row = getArchivoExperienciaConModulo($pdo, $archivo_id, $modulo_id);
    if (!$row || empty($row['solo_visualizacion'])) {
        return false;
    }
    $restricciones_modulos = cargarRestriccionesModulosExperiencia($pdo, (int)$row['contenido_id']);
    $restricciones_archivos = cargarRestriccionesArchivosExperiencia($pdo, (int)$row['contenido_id']);
    if (!usuarioPuedeVerModuloExperiencia((int)$modulo_id, $restricciones_modulos, $usuario_id)) {
        return false;
    }
    return usuarioPuedeVerArchivoExperiencia((int)$modulo_id, (int)$archivo_id, $restricciones_archivos, $usuario_id);
}

/** Perfiles asignados al usuario en sesión. */
function getPerfilesUsuarioExperiencia(PDO $pdo, $usuario_id = null) {
    $usuario_id = $usuario_id ?? ($_SESSION['usuario_id'] ?? 0);
    if (!$usuario_id) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("SELECT perfil_id FROM usuario_perfiles WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/** Mapa seccion_slug => [perfil_id, ...] con restricciones activas. */
function cargarRestriccionesSeccionesExperiencia(PDO $pdo) {
    $mapa = [];
    try {
        $rows = $pdo->query("SELECT seccion_slug, perfil_id FROM experiencia_seccion_perfiles")->fetchAll();
        foreach ($rows as $row) {
            $slug = $row['seccion_slug'];
            $mapa[$slug][] = (int)$row['perfil_id'];
        }
    } catch (Exception $e) {
        return [];
    }
    return $mapa;
}

/** Perfiles autorizados para una sección (vacío = sin restricción). */
function getPerfilesSeccionExperiencia(PDO $pdo, $seccion_slug) {
    try {
        $stmt = $pdo->prepare("SELECT perfil_id FROM experiencia_seccion_perfiles WHERE seccion_slug = ? ORDER BY perfil_id");
        $stmt->execute([$seccion_slug]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

function guardarPerfilesSeccionExperiencia(PDO $pdo, $seccion_slug, array $perfil_ids) {
    $pdo->prepare("DELETE FROM experiencia_seccion_perfiles WHERE seccion_slug = ?")->execute([$seccion_slug]);
    $perfil_ids = array_values(array_unique(array_filter(array_map('intval', $perfil_ids), function ($id) {
        return $id > 0;
    })));
    if (empty($perfil_ids)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO experiencia_seccion_perfiles (seccion_slug, perfil_id) VALUES (?, ?)");
    foreach ($perfil_ids as $perfil_id) {
        $stmt->execute([$seccion_slug, $perfil_id]);
    }
}

function usuarioPuedeGestionarExperiencia() {
    return ($_SESSION['rol'] ?? '') === 'super_admin' || tienePermiso('gestionar_experiencia');
}

function usuarioPuedeVerSeccionExperiencia($seccion_slug, array $restricciones, array $perfiles_usuario, $puede_gestionar = null) {
    $puede_gestionar = $puede_gestionar ?? usuarioPuedeGestionarExperiencia();
    if ($puede_gestionar) {
        return true;
    }
    if (empty($restricciones[$seccion_slug])) {
        return true;
    }
    if (empty($perfiles_usuario)) {
        return false;
    }
    return count(array_intersect($restricciones[$seccion_slug], $perfiles_usuario)) > 0;
}

function filtrarSeccionesExperienciaPorAcceso(PDO $pdo, array $secciones, $usuario_id = null) {
    $puede_gestionar = usuarioPuedeGestionarExperiencia();
    if ($puede_gestionar) {
        return $secciones;
    }
    $restricciones = cargarRestriccionesSeccionesExperiencia($pdo);
    $perfiles = getPerfilesUsuarioExperiencia($pdo, $usuario_id);
    return array_values(array_filter($secciones, function ($seccion) use ($restricciones, $perfiles, $puede_gestionar) {
        return usuarioPuedeVerSeccionExperiencia($seccion['slug'], $restricciones, $perfiles, $puede_gestionar);
    }));
}

/** Mapa modulo_id => [usuario_id, ...] con restricciones activas. */
function cargarRestriccionesModulosExperiencia(PDO $pdo, $contenido_id = null) {
    $mapa = [];
    try {
        if ($contenido_id) {
            $stmt = $pdo->prepare("
                SELECT mu.modulo_id, mu.usuario_id
                FROM experiencia_modulo_usuarios mu
                INNER JOIN experiencia_modulos m ON m.id = mu.modulo_id
                WHERE m.contenido_id = ?
            ");
            $stmt->execute([(int)$contenido_id]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $pdo->query("SELECT modulo_id, usuario_id FROM experiencia_modulo_usuarios")->fetchAll();
        }
        foreach ($rows as $row) {
            $mapa[(int)$row['modulo_id']][] = (int)$row['usuario_id'];
        }
    } catch (Exception $e) {
        return [];
    }
    return $mapa;
}

function getUsuariosModuloExperiencia(PDO $pdo, $modulo_id) {
    try {
        $stmt = $pdo->prepare("SELECT usuario_id FROM experiencia_modulo_usuarios WHERE modulo_id = ? ORDER BY usuario_id");
        $stmt->execute([(int)$modulo_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

function guardarUsuariosModuloExperiencia(PDO $pdo, $modulo_id, array $usuario_ids) {
    $modulo_id = (int)$modulo_id;
    $pdo->prepare("DELETE FROM experiencia_modulo_usuarios WHERE modulo_id = ?")->execute([$modulo_id]);
    $usuario_ids = array_values(array_unique(array_filter(array_map('intval', $usuario_ids), function ($id) {
        return $id > 0;
    })));
    if (empty($usuario_ids)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO experiencia_modulo_usuarios (modulo_id, usuario_id) VALUES (?, ?)");
    foreach ($usuario_ids as $usuario_id) {
        $stmt->execute([$modulo_id, $usuario_id]);
    }
}

function usuarioPuedeVerModuloExperiencia($modulo_id, array $restricciones, $usuario_id, $puede_gestionar = null) {
    return usuarioPuedeVerRecursoExperiencia((int)$modulo_id, $restricciones, $usuario_id, $puede_gestionar);
}

function usuarioPuedeVerArchivoExperiencia($modulo_id, $archivo_id, array $restricciones, $usuario_id, $puede_gestionar = null) {
    $puede_gestionar = $puede_gestionar ?? usuarioPuedeGestionarExperiencia();
    if ($puede_gestionar) {
        return true;
    }
    $modulo_id = (int)$modulo_id;
    $archivo_id = (int)$archivo_id;
    $usuario_id = (int)$usuario_id;
    $usuarios_autorizados = $restricciones[$modulo_id][$archivo_id] ?? [];
    if (empty($usuarios_autorizados)) {
        return true;
    }
    if ($usuario_id <= 0) {
        return false;
    }
    return in_array($usuario_id, $usuarios_autorizados, true);
}

function usuarioPuedeVerRecursoExperiencia($resource_id, array $restricciones, $usuario_id, $puede_gestionar = null) {
    $puede_gestionar = $puede_gestionar ?? usuarioPuedeGestionarExperiencia();
    if ($puede_gestionar) {
        return true;
    }
    $resource_id = (int)$resource_id;
    $usuario_id = (int)$usuario_id;
    if (empty($restricciones[$resource_id])) {
        return true;
    }
    if ($usuario_id <= 0) {
        return false;
    }
    return in_array($usuario_id, $restricciones[$resource_id], true);
}

/** Mapa modulo_id => archivo_id => [usuario_id, ...] con restricciones activas. */
function cargarRestriccionesArchivosExperiencia(PDO $pdo, $contenido_id = null) {
    $mapa = [];
    try {
        if ($contenido_id) {
            $stmt = $pdo->prepare("
                SELECT au.modulo_id, au.archivo_id, au.usuario_id
                FROM experiencia_archivo_usuarios au
                INNER JOIN experiencia_modulos m ON m.id = au.modulo_id
                WHERE m.contenido_id = ?
            ");
            $stmt->execute([(int)$contenido_id]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $pdo->query("SELECT modulo_id, archivo_id, usuario_id FROM experiencia_archivo_usuarios")->fetchAll();
        }
        foreach ($rows as $row) {
            $modulo_id = (int)$row['modulo_id'];
            $archivo_id = (int)$row['archivo_id'];
            $mapa[$modulo_id][$archivo_id][] = (int)$row['usuario_id'];
        }
    } catch (Exception $e) {
        return [];
    }
    return $mapa;
}

function getUsuariosArchivoExperiencia(PDO $pdo, $modulo_id, $archivo_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT usuario_id FROM experiencia_archivo_usuarios
            WHERE modulo_id = ? AND archivo_id = ?
            ORDER BY usuario_id
        ");
        $stmt->execute([(int)$modulo_id, (int)$archivo_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

function guardarUsuariosArchivoExperiencia(PDO $pdo, $modulo_id, $archivo_id, array $usuario_ids) {
    $modulo_id = (int)$modulo_id;
    $archivo_id = (int)$archivo_id;
    $stmt = $pdo->prepare("SELECT id FROM experiencia_archivos WHERE id = ? AND modulo_id = ?");
    $stmt->execute([$archivo_id, $modulo_id]);
    if (!$stmt->fetch()) {
        throw new InvalidArgumentException('El documento no pertenece al módulo indicado.');
    }
    $pdo->prepare("DELETE FROM experiencia_archivo_usuarios WHERE modulo_id = ? AND archivo_id = ?")->execute([$modulo_id, $archivo_id]);
    $usuario_ids = array_values(array_unique(array_filter(array_map('intval', $usuario_ids), function ($id) {
        return $id > 0;
    })));
    if (empty($usuario_ids)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO experiencia_archivo_usuarios (modulo_id, archivo_id, usuario_id) VALUES (?, ?, ?)");
    foreach ($usuario_ids as $usuario_id) {
        $stmt->execute([$modulo_id, $archivo_id, $usuario_id]);
    }
}

function filtrarArchivosExperienciaPorAcceso(array $archivos, array $restricciones_archivos, $modulo_id, $usuario_id, $puede_gestionar = null) {
    $puede_gestionar = $puede_gestionar ?? usuarioPuedeGestionarExperiencia();
    if ($puede_gestionar) {
        return $archivos;
    }
    $modulo_id = (int)$modulo_id;
    return array_values(array_filter($archivos, function ($archivo) use ($restricciones_archivos, $modulo_id, $usuario_id, $puede_gestionar) {
        return usuarioPuedeVerArchivoExperiencia($modulo_id, (int)$archivo['id'], $restricciones_archivos, $usuario_id, $puede_gestionar);
    }));
}

/**
 * Filtra módulos y sus archivos según usuarios autorizados.
 * @return array{0: array, 1: array}
 */
function filtrarModulosExperienciaPorAcceso(PDO $pdo, array $modulos, array $archivos_por_modulo, $usuario_id = null, $contenido_id = null) {
    $puede_gestionar = usuarioPuedeGestionarExperiencia();
    if ($puede_gestionar) {
        return [$modulos, $archivos_por_modulo];
    }
    $usuario_id = (int)($usuario_id ?? ($_SESSION['usuario_id'] ?? 0));
    $restricciones_modulos = cargarRestriccionesModulosExperiencia($pdo, $contenido_id);
    $restricciones_archivos = cargarRestriccionesArchivosExperiencia($pdo, $contenido_id);
    $modulos_filtrados = [];
    $archivos_filtrados = [];
    foreach ($modulos as $mod) {
        if (!usuarioPuedeVerModuloExperiencia((int)$mod['id'], $restricciones_modulos, $usuario_id, $puede_gestionar)) {
            continue;
        }
        $modulos_filtrados[] = $mod;
        $archivos_mod = $archivos_por_modulo[$mod['id']] ?? [];
        $archivos_filtrados[$mod['id']] = filtrarArchivosExperienciaPorAcceso(
            $archivos_mod,
            $restricciones_archivos,
            (int)$mod['id'],
            $usuario_id,
            $puede_gestionar
        );
    }
    return [$modulos_filtrados, $archivos_filtrados];
}

/* ============================================================
 * SOLICITUDES DE CAMBIO - EXPERIENCIA
 * ============================================================
 */
function tablaSolicitudesCambioOk(PDO $pdo){
    static $ok = null;
    if ($ok === null) {
        try { $pdo->query( "SELECT 1 FROM experiencia_solicitudes_cambio LIMIT 1" );
            $ok = true;
        } catch (Exception $e) {
            $ok = false;
        }
    }
    return $ok;
}


/**
 * Tipos de solicitud disponibles.
 */
function tiposSolicitudExperiencia(){
    return [
        'actualizacion',
        'correccion',
        'eliminacion',
        'otro'
    ];
}

/**
 * Estados disponibles.
 */
function estadosSolicitudExperiencia(){
    return [
        'pendiente' => [
            'label' => 'Pendiente',
            'color' => 'warning'
        ],

        'en_proceso' => [
            'label' => 'En proceso',
            'color' => 'info'
        ],

        'atendida' => [
            'label' => 'Atendida',
            'color' => 'success'
        ],

        'rechazada' => [
            'label' => 'Rechazada',
            'color' => 'secondary'
        ]
    ];
}

/**
 * Criterios de revisión de calidad documental.
 */
function criteriosCalidadDocumentalExperiencia()
{
    return [

        '1' => [
            'titulo' => '1 Necesidad',

            'items' => [
                '1.1' =>
                    '¿El documento responde a una necesidad real?'
            ]
        ],

        '2' => [
            'titulo' => '2 Enfoque por procesos',

            'items' => [
                '2.1' =>
                    '¿Está asociado a un proceso claramente identificado?'
            ]
        ],

        '3' => [
            'titulo' => '3 Contenido',

            'items' => [
                '3.1' =>
                    '¿Tiene objetivo y alcance definidos?',

                '3.2' =>
                    '¿Las responsabilidades están claras?',

                '3.3' =>
                    '¿Las actividades son comprensibles?',

                '3.4' =>
                    '¿Se identificaron controles cuando son necesarios?',

                '3.5' =>
                    '¿Se definieron registros o evidencias?'
            ]
        ],

        '4' => [
            'titulo' => '4 Articulación',

            'items' => [
                '4.1' =>
                    '¿Está relacionado con otros documentos del proceso?',

                '4.2' =>
                    '¿Existen documentos duplicados que puedan eliminarse o integrarse?'
            ]
        ],

        '5' => [
            'titulo' => '5 Cumplimiento',

            'items' => [
                '5.1' =>
                    '¿Se consideraron requisitos legales, reglamentarios o normativos aplicables?'
            ]
        ],

        '6' => [
            'titulo' => '6 Operación',

            'items' => [
                '6.1' =>
                    '¿El documento refleja lo que realmente ocurre en el proceso?',

                '6.2' =>
                    '¿Es aplicable por los usuarios que deben ejecutarlo?'
            ]
        ],

        '7' => [
            'titulo' => '7 Control',

            'items' => [
                '7.1' =>
                    '¿Se utilizó la plantilla institucional?',

                '7.2' =>
                    '¿Cuenta con identificación y codificación?',

                '7.3' =>
                    '¿Se puede controlar su versión?'
            ]
        ]
    ];
}

/**
 * Convierte los criterios a:
 *
 * [
 *   '17.1.1' => 'Pregunta...',
 *   '17.1.2' => 'Pregunta...'
 * ]
 */
function criteriosCalidadDocumentalPlano(){
    $resultado = [];
    foreach ( criteriosCalidadDocumentalExperiencia() as $grupo) {
        foreach ( $grupo['items'] as $key => $label
        ) { $resultado[$key] = $label; }
    }
    return $resultado;
}


/**
 * Crea una solicitud.
 *
 * La dependencia se guarda como snapshot.
 */
function crearSolicitudCambioExperiencia(
    PDO $pdo,
    $archivo_id,
    $modulo_id,
    $contenido_id,
    $usuario_id,
    $tipo_solicitud,
    $comentario,
    array $criterios_respuestas = []
) {

    /*
     * Validar tipo de solicitud
     */
    if (!in_array(
        $tipo_solicitud,
        tiposSolicitudExperiencia(),
        true
    )) {
        throw new InvalidArgumentException(
            'Tipo de solicitud no válido.'
        );
    }
    /*
     * Validar comentario
     */
    $comentario = trim($comentario);
    if ($comentario === '') {
        throw new InvalidArgumentException(
            'El comentario es obligatorio.'
        );
    }

    /*
     * Verificar documento
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM experiencia_archivos
        WHERE id = ?
          AND modulo_id = ?
    ");

    $stmt->execute([
        (int)$archivo_id,
        (int)$modulo_id
    ]);

    if (!$stmt->fetch()) {
        throw new InvalidArgumentException(
            'El documento no pertenece al módulo indicado.'
        );
    }

    /*
     * Obtener dependencia actual del usuario
     */
    $dependencia_id = null;
    $stmt = $pdo->prepare("
        SELECT dependencia_id
        FROM usuarios
        WHERE id = ?
    ");

    $stmt->execute([
        (int)$usuario_id
    ]);

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($usuario) {
        $dependencia_id =
            $usuario['dependencia_id'] !== null
            ? (int)$usuario['dependencia_id']
            : null;
    }

    /*
     * Construir checklist completo
     *
     * Cada criterio queda almacenado como:
     * true  = Sí
     * false = No
     */
    $checklist = [];

    foreach (
        criteriosCalidadDocumentalPlano()
        as $key => $label
    ) {

        if (!array_key_exists(
                $key,
                $criterios_respuestas
            )
        ) {
            throw new InvalidArgumentException(
                'Debe responder todos los criterios de calidad documental.'
            );
        }

        $respuesta =
            strtolower(
                trim((string)$criterios_respuestas[$key])
            );
        if (
            $respuesta !== 'si'
            &&
            $respuesta !== 'no'
        ) {

            throw new InvalidArgumentException(
                'Respuesta de criterio no válida.'
            );
        }

        $checklist[$key] =
            $respuesta === 'si';
    }


    /*
     * Guardar solicitud
     */
    $stmt = $pdo->prepare("
        INSERT INTO experiencia_solicitudes_cambio
        (
            archivo_id,
            modulo_id,
            contenido_id,
            usuario_id,
            dependencia_id,
            tipo_solicitud,
            comentario,
            checklist_calidad,
            estado,
            fecha_solicitud
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW()
        )
    ");

    $stmt->execute([
        (int)$archivo_id,
        (int)$modulo_id,
        (int)$contenido_id,
        (int)$usuario_id,
        $dependencia_id,
        $tipo_solicitud,
        $comentario,
        json_encode($checklist, JSON_UNESCAPED_UNICODE)
    ]);
    return (int)$pdo->lastInsertId();
}


/**
 * Actualiza el estado.
 *
 * Cada estado guarda su fecha de activación.
 */
function actualizarEstadoSolicitudCambio(
    PDO $pdo,
    $solicitud_id,
    $estado,
    $respuesta,
    $usuario_id
) {

    $estados_validos = array_keys(estadosSolicitudExperiencia());
    if (
        !in_array(
            $estado,
            $estados_validos,
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Estado no válido.'
        );
    }


    /*
     * Obtener estado anterior.
     */
    $stmt = $pdo->prepare("
        SELECT estado
        FROM experiencia_solicitudes_cambio
        WHERE id = ?
    ");

    $stmt->execute([
        (int)$solicitud_id
    ]);

    $anterior = $stmt->fetchColumn();

    if ($anterior === false) {
        throw new InvalidArgumentException(
            'La solicitud no existe.'
        );
    }

    /*
     * Si vuelve a pendiente no se borra
     * ninguna fecha histórica.
     */
    if ($estado === 'pendiente') {

        $stmt = $pdo->prepare("
            UPDATE experiencia_solicitudes_cambio
            SET
                estado = ?,
                respuesta = ?,
                atendido_por = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $estado,
            $respuesta !== ''
                ? $respuesta
                : null,
            (int)$usuario_id,
            (int)$solicitud_id
        ]);

        return;
    }

    /*
     * En proceso
     */
    if ($estado === 'en_proceso') {

        $stmt = $pdo->prepare("
            UPDATE experiencia_solicitudes_cambio
            SET
                estado = ?,
                respuesta = ?,
                atendido_por = ?,

                fecha_en_proceso =
                    COALESCE(
                        fecha_en_proceso,
                        NOW()
                    )

            WHERE id = ?
        ");

        $stmt->execute([$estado, $respuesta !== '' ? $respuesta : null, (int)$usuario_id, (int)$solicitud_id]);
        return;
    }

    /*
     * Atendida
     */
    if ($estado === 'atendida') {
        $stmt = $pdo->prepare("
            UPDATE experiencia_solicitudes_cambio
            SET
                estado = ?,
                respuesta = ?,
                atendido_por = ?,

                fecha_atencion =
                    COALESCE(
                        fecha_atencion,
                        NOW()
                    )

            WHERE id = ?
        ");

        $stmt->execute([ $estado, $respuesta !== '' ? $respuesta : null, (int)$usuario_id, (int)$solicitud_id]);

        return;
    }

    /*
     * Rechazada
     */
    if ($estado === 'rechazada') {
        $stmt = $pdo->prepare("
            UPDATE experiencia_solicitudes_cambio
            SET
                estado = ?,
                respuesta = ?,
                atendido_por = ?,

                fecha_rechazada =
                    COALESCE(
                        fecha_rechazada,
                        NOW()
                    )

            WHERE id = ?
        ");

        $stmt->execute([$estado, $respuesta !== '' ? $respuesta : null, (int)$usuario_id, (int)$solicitud_id]);
    }
}

/**
 * Lista solicitudes.
 */
/**
 * Construye el WHERE + params compartido entre listar y contar.
 * Uso interno.
 */
function construirFiltrosSolicitudesCambio(array $filtros) {
    $where = [];
    $params = [];

    $map = [
        'archivo_id' => 'sc.archivo_id',
        'modulo_id' => 'sc.modulo_id',
        'contenido_id' => 'sc.contenido_id',
        'usuario_id' => 'sc.usuario_id',
        'estado' => 'sc.estado',
        'dependencia_id' => 'sc.dependencia_id'
    ];

    foreach ($map as $key => $column) {
        if (isset($filtros[$key]) && $filtros[$key] !== '' && $filtros[$key] !== null) {
            $where[] = $column . ' = ?';
            $params[] = $filtros[$key];
        }
    }

    if (!empty($filtros['seccion_slug'])) {
        $where[] = 'c.seccion_slug = ?';
        $params[] = $filtros['seccion_slug'];
    }
        if (!empty($filtros['fecha_desde'])) {
        $where[] = 'sc.fecha_solicitud >= ?';
        $params[] = $filtros['fecha_desde'] . ' 00:00:00';
    }

    if (!empty($filtros['fecha_hasta'])) {
        $where[] = 'sc.fecha_solicitud <= ?';
        $params[] = $filtros['fecha_hasta'] . ' 23:59:59';
    }

    return [$where, $params];

}

/**
 * Lista solicitudes, con paginación opcional.
 *
 * @param int|null $limite  Cantidad de registros por página. null = sin límite (trae todo).
 * @param int      $offset  Desde qué registro empezar (0 = primera página).
 */
function listarSolicitudesCambioExperiencia(PDO $pdo, array $filtros = [], $limite = null, $offset = 0) {

    if (!tablaSolicitudesCambioOk($pdo)) {
        return [];
    }

    [$where, $params] = construirFiltrosSolicitudesCambio($filtros);

    $sql = "
        SELECT
            sc.*,
            a.nombre AS archivo_nombre,
            m.titulo AS modulo_titulo,
            c.titulo AS seccion_titulo,
            c.seccion_slug,
            u.nombre_completo AS solicitante_nombre,
            u.email AS solicitante_email,
            d.nombre AS dependencia_nombre,
            TIMESTAMPDIFF(HOUR, sc.fecha_solicitud, COALESCE(sc.fecha_atencion, sc.fecha_rechazada)) AS horas_respuesta,
            TIMESTAMPDIFF(HOUR, sc.fecha_solicitud, sc.fecha_en_proceso) AS horas_hasta_proceso,
            TIMESTAMPDIFF(HOUR, sc.fecha_en_proceso, COALESCE(sc.fecha_atencion, sc.fecha_rechazada)) AS horas_en_proceso
        FROM experiencia_solicitudes_cambio sc
        LEFT JOIN experiencia_archivos a ON a.id = sc.archivo_id
        LEFT JOIN experiencia_modulos m ON m.id = sc.modulo_id
        LEFT JOIN experiencia_contenidos c ON c.id = sc.contenido_id
        LEFT JOIN usuarios u ON u.id = sc.usuario_id
        LEFT JOIN dependencias d ON d.id = sc.dependencia_id
    ";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN sc.estado = 'pendiente' THEN 1
                WHEN sc.estado = 'en_proceso' THEN 2
                ELSE 3
            END,
            sc.fecha_solicitud DESC
    ";

    // Paginación: LIMIT/OFFSET van como enteros directos en el SQL
    // (PDO con parámetros bind para LIMIT da problemas en algunos drivers, por eso se castean e insertan directo)
    if ($limite !== null) {
        $limite = max(1, (int)$limite);
        $offset = max(0, (int)$offset);
        $sql .= " LIMIT {$limite} OFFSET {$offset}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Cuenta el total de solicitudes que cumplen los filtros (para calcular
 * cuántas páginas hay). Usa el mismo WHERE que listarSolicitudesCambioExperiencia.
 */
function contarTotalSolicitudesCambioExperiencia(PDO $pdo, array $filtros = []) {
    if (!tablaSolicitudesCambioOk($pdo)) {
        return 0;
    }

    [$where, $params] = construirFiltrosSolicitudesCambio($filtros);

    $sql = "
        SELECT COUNT(*)
        FROM experiencia_solicitudes_cambio sc
        LEFT JOIN experiencia_contenidos c ON c.id = sc.contenido_id
    ";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

/**
 * Total documentos.
 */
function contarTotalDocumentosExperiencia(PDO $pdo)
{
    return (int)$pdo ->query(" SELECT COUNT(*) FROM experiencia_archivos  ") ->fetchColumn();
}

/**
 * Conteo por estado.
 */
function contarSolicitudesCambioPorEstado(PDO $pdo){
    $resultado = array_fill_keys(array_keys(estadosSolicitudExperiencia()),0);

    if (!tablaSolicitudesCambioOk($pdo)) {
        return $resultado;
    }

    $stmt = $pdo->query("
        SELECT
            estado,
            COUNT(*) AS total
        FROM experiencia_solicitudes_cambio
        GROUP BY estado
    ");

    foreach ($stmt-> fetchAll (PDO::FETCH_ASSOC) as $row
    ) {
        if (isset($resultado[$row['estado']])) {
            $resultado[ $row ['estado']] = (int)$row['total'];
        }
    }

    return $resultado;
}


/**
 * Conteo por tipo.
 */
function contarSolicitudesCambioPorTipo(PDO $pdo){
    $resultado = array_fill_keys( tiposSolicitudExperiencia(), 0 );

    if (!tablaSolicitudesCambioOk($pdo)) {
        return $resultado;
    }

    $stmt = $pdo->query("
        SELECT
            tipo_solicitud,
            COUNT(*) AS total

        FROM experiencia_solicitudes_cambio
        GROUP BY tipo_solicitud
    ");

    foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {

        if (isset($resultado[ $row['tipo_solicitud']])) {
            $resultado[ $row['tipo_solicitud']] = (int)$row['total'];
        }
    }
    return $resultado;
}

/**
 * Solicitudes por dependencia.
 */
function contarSolicitudesCambioPorDependencia(PDO $pdo, $limite = 10) {
    if (!tablaSolicitudesCambioOk($pdo)) {
        return [];
    }

    $limite = max(1, (int)$limite);

    $sql = "
        SELECT
            COALESCE(
                d.nombre,
                'Sin dependencia'
            ) AS dependencia,
            COUNT(*) AS total
        FROM experiencia_solicitudes_cambio sc
        LEFT JOIN dependencias d
            ON d.id = sc.dependencia_id
        GROUP BY
            COALESCE(
                d.nombre,
                'Sin dependencia'
            )
        ORDER BY total DESC
        LIMIT {$limite}
    ";

    return $pdo ->query($sql) ->fetchAll( PDO::FETCH_ASSOC );
}

/**
 * Solicitudes por módulo.
 */
function contarSolicitudesCambioPorModulo( PDO $pdo, $limite = 10) {

    if (!tablaSolicitudesCambioOk($pdo)) {
        return [];
    }

    $limite = max(1, (int)$limite );

    return $pdo ->query("
            SELECT
                m.titulo,
                COUNT(sc.id) AS total

            FROM experiencia_solicitudes_cambio sc

            INNER JOIN experiencia_modulos m
                ON m.id = sc.modulo_id

            GROUP BY
                m.id,
                m.titulo

            ORDER BY total DESC

            LIMIT {$limite}
        ") ->fetchAll( PDO::FETCH_ASSOC );
}


/**
 * Solicitudes por sección.
 */
function contarSolicitudesCambioPorSeccion( PDO $pdo ) {
    if (!tablaSolicitudesCambioOk($pdo)) {
        return [];
    }

    return $pdo ->query("
            SELECT
                c.titulo,
                COUNT(sc.id) AS total
            FROM experiencia_solicitudes_cambio sc
            INNER JOIN experiencia_contenidos c
                ON c.id = sc.contenido_id
            GROUP BY
                c.id,
                c.titulo
            ORDER BY total DESC
        ") ->fetchAll( PDO::FETCH_ASSOC );
}


/**
 * Solicitudes por mes.
 */
function contarSolicitudesCambioPorMes( PDO $pdo, $meses = 6 ) {

    if (!tablaSolicitudesCambioOk($pdo)) {
        return [];
    }

    $meses = max( 1, (int)$meses );

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(
                fecha_solicitud,
                '%Y-%m'
            ) AS mes,
            DATE_FORMAT(
                fecha_solicitud,
                '%m/%Y'
            ) AS etiqueta,
            COUNT(*) AS total
        FROM experiencia_solicitudes_cambio
        WHERE fecha_solicitud >=
            DATE_SUB(
                DATE_FORMAT(
                    CURDATE(),
                    '%Y-%m-01'
                ),
                INTERVAL ? MONTH
            )
        GROUP BY
            DATE_FORMAT(
                fecha_solicitud,
                '%Y-%m'
            ),
            DATE_FORMAT(
                fecha_solicitud,
                '%m/%Y'
            )
        ORDER BY mes ASC
    ");

    $stmt->execute([$meses - 1]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC );
}


/**
 * Tiempo promedio.
 *
 * Devuelve:
 * global
 * inicio_atencion
 * en_proceso
 * por_tipo
 */
function tiempoPromedioRespuestaExperiencia( PDO $pdo) {

    if (!tablaSolicitudesCambioOk($pdo)) {
        return [
            'global' => null,
            'inicio_atencion' => null,
            'en_proceso' => null,
            'por_tipo' => []
        ];
    }

    /*
     * Tiempo total.
     */
    $global = $pdo->query("
            SELECT
                AVG(
                    TIMESTAMPDIFF(
                        MINUTE,
                        fecha_solicitud,
                        COALESCE(
                            fecha_atencion,
                            fecha_rechazada
                        )
                    )
                ) / 60
            FROM experiencia_solicitudes_cambio
            WHERE
                fecha_atencion IS NOT NULL
                OR
                fecha_rechazada IS NOT NULL
        ") ->fetchColumn();

    /*
     * Tiempo hasta entrar en proceso.
     */
    $inicio = $pdo->query("
            SELECT
                AVG(
                    TIMESTAMPDIFF(
                        MINUTE,
                        fecha_solicitud,
                        fecha_en_proceso
                    )
                ) / 60
            FROM experiencia_solicitudes_cambio
            WHERE fecha_en_proceso IS NOT NULL
        ")
        ->fetchColumn();

    /*
     * Tiempo dentro de proceso.
     */
    $proceso =
        $pdo->query("
            SELECT
                AVG(
                    TIMESTAMPDIFF(
                        MINUTE,
                        fecha_en_proceso,
                        COALESCE(
                            fecha_atencion,
                            fecha_rechazada
                        )
                    )
                ) / 60
            FROM experiencia_solicitudes_cambio
            WHERE
                fecha_en_proceso IS NOT NULL
                AND
                (
                    fecha_atencion IS NOT NULL
                    OR
                    fecha_rechazada IS NOT NULL
                )
        ") ->fetchColumn();

    /*
     * Tiempo por tipo.
     */
    $stmt = $pdo->query("
        SELECT
            tipo_solicitud,
            AVG(
                TIMESTAMPDIFF( MINUTE, fecha_solicitud, COALESCE( fecha_atencion, fecha_rechazada))
            ) / 60 AS promedio
        FROM experiencia_solicitudes_cambio
        WHERE
            fecha_atencion IS NOT NULL
            OR
            fecha_rechazada IS NOT NULL
        GROUP BY tipo_solicitud
    ");

    $por_tipo = [];

    foreach ($stmt->fetchAll( PDO::FETCH_ASSOC)as $row ) {
        $por_tipo[] = [ 'tipo' => $row['tipo_solicitud'],
            'promedio' => round( (float)$row['promedio'], 1 )];
    }

    return [

        'global' => $global !== null ? round( (float)$global, 1 ) : null,
        'inicio_atencion' => $inicio !== null ? round( (float)$inicio, 1 ) : null,
        'en_proceso' => $proceso !== null ? round((float)$proceso, 1): null,
        'por_tipo' => $por_tipo
    ];
}


/**
 * Cumplimiento de cada criterio.
 */
function resumenCumplimientoCriteriosExperiencia( PDO $pdo ) {

    if (!tablaSolicitudesCambioOk($pdo)) {
        return [];
    }
    $criterios = criteriosCalidadDocumentalPlano();

    $resultado = [];

    $stmt = $pdo->query("
        SELECT checklist_calidad
        FROM experiencia_solicitudes_cambio
        WHERE checklist_calidad IS NOT NULL
          AND checklist_calidad <> '' ");

    $registros = $stmt->fetchAll( PDO::FETCH_ASSOC );

    foreach ( $criterios as $key => $label) {
        $total = 0;
        $cumple = 0;
        foreach ($registros as $row) {
            $checklist = json_decode( $row['checklist_calidad'], true );

            if (!is_array($checklist)) {
                continue;
            }

            if (array_key_exists( $key, $checklist )) {
                $total++;
                if ($checklist[$key] === true || $checklist[$key] === 1 || $checklist[$key] === '1' ) {
                    $cumple++;
                }
            }
        }

        $porcentaje = $total > 0 ? round( ($cumple / $total) * 100, 1 ) : 0;

        $resultado[] = [
            'key' => $key,
            'label' => $label,
            'cumple' => $cumple,
            'total' => $total,
            'porcentaje' => $porcentaje
        ];
    }
    return $resultado;
}

/**
 * Datos completos para informes.
 */
function obtenerDatosInformeSolicitudesExperiencia(PDO $pdo, array $filtros = []) {
    return [
        'listado' => listarSolicitudesCambioExperiencia($pdo, $filtros),
        'por_estado' => contarSolicitudesCambioPorEstado( $pdo ),
        'por_tipo' => contarSolicitudesCambioPorTipo( $pdo ),
        'por_dependencia' => contarSolicitudesCambioPorDependencia( $pdo, 15 ),
        'por_modulo' => contarSolicitudesCambioPorModulo( $pdo, 10 ),
        'por_seccion' => contarSolicitudesCambioPorSeccion( $pdo ),
        'por_mes' => contarSolicitudesCambioPorMes( $pdo, 6 ),
        'tiempo_respuesta' => tiempoPromedioRespuestaExperiencia( $pdo ),
        'cumplimiento_criterios' => resumenCumplimientoCriteriosExperiencia( $pdo ),
        'total_documentos' => contarTotalDocumentosExperiencia( $pdo ),
        'generado_en' => date('Y-m-d H:i:s')
    ];
}