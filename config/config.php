<?php
/**
 * Configuración general de la aplicación
 */

// Configuración de sesión
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Cambiar a 1 en producción con HTTPS
session_start();


// Override local opcional: config/local.php puede definir SMTP_*, BASE_URL, etc.
// Se carga ANTES para que tenga prioridad sobre los valores por defecto.
// Ese archivo está en .gitignore, así las claves no se suben al repo.
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'mail.osf.com.co'); 
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', 'notificaciones@osf.com.co'); //Aqui se peude poner un correo especial de intranet
}
// Aqui la contraseña de ese correo pero para no dejar la contraseña
// se puede ir a la configuracion en 2 pasos de la cuenta y generar una clave de 16 caracteres para ponerla aqui
// Esa clave no deberia subirse al repositorio git por seguridad 
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'tu_password'); 
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('SMTP_SEGURIDAD')) {
    define('SMTP_SEGURIDAD', 'tls');
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', 'no-responder@osf.com.co');
}
if (!defined('SMTP_FROM_NOMBRE')) {
    define('SMTP_FROM_NOMBRE', 'Intranet OSF');
}
// define('BASE_URL', 'https://intranet.osf.com.co'); // sin slash al final

// Zona horaria
date_default_timezone_set('America/Bogota');

// Rutas: BASE_URL se calcula según la ubicación del proyecto (funciona en local y en servidor)
if (!defined('BASE_URL')) {
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $basePath = trim(str_replace($docRoot, '', $projectRoot), '/');
    $basePath = $basePath === '' ? '/' : '/' . $basePath . '/';
    define('BASE_URL', $basePath);
}
define('BASE_PATH', __DIR__ . '/../');

// Rutas de archivos
define('UPLOAD_PATH', BASE_PATH . 'uploads/');
define('UPLOAD_URL', BASE_URL . 'uploads/');

// Configuración de archivos y límites de tamaño
// El techo de la app es 600 MB; PHP debe permitir al menos eso (ver .htaccess / .user.ini: 650M)
define('MAX_FILE_SIZE', 600 * 1024 * 1024);     // 600MB límite genérico
define('MAX_IMAGE_SIZE', 600 * 1024 * 1024);    // 600MB para imágenes
define('MAX_DOCUMENT_SIZE', 600 * 1024 * 1024); // 600MB para documentos (PDF, Word, PowerPoint, imágenes)
// Vídeos en materiales de curso (ajustar también php.ini: upload_max_filesize y post_max_size >= este valor)
define('MAX_VIDEO_SIZE', 600 * 1024 * 1024);  // 600 MB
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/ogg']);
// MIME alternativos que algunos servidores reportan para videos válidos (p. ej. WhatsApp / iPhone)
define('ALLOWED_VIDEO_MIMES', array_merge(ALLOWED_VIDEO_TYPES, [
    'video/quicktime',
    'video/x-msvideo',
    'application/octet-stream',
    'application/mp4',
]));
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_DOCUMENT_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'
]);
// Documentos de interés: PDF, Office y videos
define('UPLOAD_PATH_DOCUMENTOS_INTERES', UPLOAD_PATH . 'documentos_interes/');
define('UPLOAD_PATH_SST', UPLOAD_PATH . 'sst/');
define('UPLOAD_URL_SST', UPLOAD_URL . 'sst/');
define('UPLOAD_PATH_EXPERIENCIA', UPLOAD_PATH . 'experiencia/');
define('UPLOAD_URL_EXPERIENCIA', UPLOAD_URL . 'experiencia/');
define('ALLOWED_VIDEO_EXT', ['mp4', 'webm', 'ogg']);
define('ALLOWED_DOCUMENTOS_INTERES_EXT', array_merge(
    ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
    ALLOWED_VIDEO_EXT
));
define('ALLOWED_DOCUMENTOS_INTERES_MIMES', array_merge([
    'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
], ALLOWED_VIDEO_TYPES));
// Experiencia: documentos + vídeos (solo visualización en visor integrado)
define('ALLOWED_EXPERIENCIA_EXT', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp4', 'webm', 'ogg']);
define('ALLOWED_EXPERIENCIA_MIMES', array_merge(ALLOWED_DOCUMENTOS_INTERES_MIMES, ALLOWED_VIDEO_TYPES));
define('EXPERIENCIA_VIEWER_TOKEN_TTL', 3600);

// Configuración de seguridad
define('SESSION_TIMEOUT', 3600); // 1 hora en segundos
define('MAX_INTENTOS_LOGIN', 3);   // Intentos fallidos antes de bloquear
define('BLOQUEO_LOGIN_HORAS', 3); // Horas de bloqueo tras superar intentos
define('RESET_INTENTOS_SIN_ACTIVIDAD_HORAS', 5); // Sin intentos durante X horas: se resetean y vuelve a tener 3 intentos

// Incluir configuración de base de datos
require_once __DIR__ . '/database.php';

// Función para registrar logs
function registrarLog($usuario_id, $accion, $modulo = null, $detalles = null) {
    try {
        $pdo = getDBConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, modulo, detalles, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$usuario_id, $accion, $modulo, $detalles, $ip]);
    } catch (Exception $e) {
        error_log("Error al registrar log: " . $e->getMessage());
    }
}

/**
 * Devuelve los posibles valores de permisos.nombre que se consideran equivalentes al slug usado en código.
 * Así la BD puede tener "Gestionar Cursos" y el código usa 'gestionar_cursos'.
 */
function nombresPermisoParaSlug($slug) {
    static $variantes = [
        'gestionar_usuarios'           => ['gestionar_usuarios', 'Gestionar Usuarios', 'Gestionar usuarios'],
        'gestionar_perfiles_permisos' => ['gestionar_perfiles_permisos', 'Gestionar Perfiles y Permisos', 'Gestionar perfiles y permisos'],
        'gestionar_cursos'            => ['gestionar_cursos', 'Gestionar Cursos', 'Gestionar cursos'],
        'gestionar_comunicados'        => ['gestionar_comunicados', 'Gestionar Comunicados', 'Gestionar comunicados'],
        'gestionar_dependencias'       => ['gestionar_dependencias', 'Gestionar Dependencias', 'Gestionar dependencias'],
        'gestionar_documentos_interes' => ['gestionar_documentos_interes', 'Gestionar Documentos de Interés', 'Gestionar Documentos Interés', 'Gestionar documentos de interés'],
        'gestionar_experiencia'        => ['gestionar_experiencia', 'Gestionar Experiencia', 'Gestionar experiencia'],
        'gestionar_sst'                => ['gestionar_sst', 'Gestionar SST', 'Gestionar sst'],
        'ver_reportes'                => ['ver_reportes', 'Ver Reportes', 'Ver reportes'],
        'ver_cursos'                  => ['ver_cursos', 'Ver Cursos', 'Ver cursos'],
        'ver_datos_interes'            => ['ver_datos_interes', 'Ver Datos de Interés', 'Ver datos de interés'],
        'ver_documentos_interes'      => ['ver_documentos_interes', 'Ver Documentos de Interés', 'Ver Documentos de interés'],
        'ver_sst'                     => ['ver_sst', 'Ver SST', 'Ver sst'],
        'ver_dashboard'               => ['ver_dashboard', 'Ver Dashboard', 'Ver dashboard'],
        'presentar_evaluaciones'      => ['presentar_evaluaciones', 'Presentar Evaluaciones', 'Presentar evaluaciones'],
        'ver_documentos'              => ['ver_documentos', 'Ver Documentos', 'Ver documentos'],
        'inscribirse_cursos'          => ['inscribirse_cursos', 'Inscribirse Cursos', 'Inscribirse cursos'],
    ];
    return $variantes[$slug] ?? [$slug];
}

/** Convierte nombre de permiso (slug o legible) a etiqueta para mostrar en pantalla. */
function etiquetaPermiso($nombre) {
    if (strpos($nombre, '_') !== false) {
        return ucwords(str_replace('_', ' ', $nombre));
    }
    return $nombre;
}

// Función para verificar permisos
function tienePermiso($permiso_nombre) {
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }
    
    // Super admin tiene todos los permisos
    if ($_SESSION['rol'] === 'super_admin') {
        return true;
    }
    
    try {
        $pdo = getDBConnection();
        $nombres = nombresPermisoParaSlug($permiso_nombre);
        $placeholders = implode(',', array_fill(0, count($nombres), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as tiene
            FROM usuario_perfiles up
            INNER JOIN perfil_permisos pp ON up.perfil_id = pp.perfil_id
            INNER JOIN permisos p ON pp.permiso_id = p.id
            WHERE up.usuario_id = ? AND p.nombre IN ($placeholders)
        ");
        $params = array_merge([$_SESSION['usuario_id']], $nombres);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        if ($result['tiene'] > 0) {
            return true;
        }
        
        // Usuarios legacy: tienen rol pero no tienen perfiles asignados (usuario_perfiles vacío).
        // Se les permite acceso básico al dashboard, cursos y evaluaciones para no bloquearlos.
        $permisos_legacy = ['ver_dashboard', 'ver_cursos', 'presentar_evaluaciones'];
        if (in_array($permiso_nombre, $permisos_legacy, true)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuario_perfiles WHERE usuario_id = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            $row = $stmt->fetch();
            if ((int)$row['total'] === 0) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error al verificar permiso: " . $e->getMessage());
        return false;
    }
}

// Función para requerir autenticación (soporta múltiples usuarios: cada uno tiene su propia sesión)
function requerirAutenticacion() {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
    // Verificar que el usuario siga activo (solo esta sesión; no afecta a otros usuarios)
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT activo FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $row = $stmt->fetch();
        if ($row && (int)$row['activo'] === 0) {
            $_SESSION = [];
            header('Location: ' . BASE_URL . 'login.php?error=cuenta_desactivada');
            exit;
        }
    } catch (Exception $e) {
        error_log("Error al verificar usuario activo: " . $e->getMessage());
    }
}

// Función para requerir permiso (solo redirige; la limpieza de sesión se hace en login.php solo para ese navegador)
function requerirPermiso($permiso_nombre) {
    requerirAutenticacion();
    
    if (!tienePermiso($permiso_nombre)) {
        header('Location: ' . BASE_URL . 'login.php?error=sin_permiso');
        exit;
    }
}

/**
 * Comprueba si un email está bloqueado por intentos fallidos de login.
 * Resetea automáticamente los intentos si: (1) ya pasó el tiempo de bloqueo, o
 * (2) no ha habido intentos en RESET_INTENTOS_SIN_ACTIVIDAD_HORAS (el usuario vuelve a tener 3 intentos).
 * @param string $email Email del usuario
 * @return array ['bloqueado' => bool, 'bloqueado_hasta' => string|null] bloqueado_hasta en formato Y-m-d H:i:s
 */
function estaUsuarioBloqueadoLogin($email) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT intentos_fallidos, bloqueado_hasta, ultimo_intento
            FROM intentos_login
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['bloqueado' => false, 'bloqueado_hasta' => null];
        }

        $ahora = date('Y-m-d H:i:s');
        $bloqueadoHasta = $row['bloqueado_hasta'];
        $ultimoIntento = $row['ultimo_intento'];

        // 1) Si el bloqueo ya expiró: resetear y dar 3 intentos de nuevo
        if ($bloqueadoHasta !== null && $ahora >= $bloqueadoHasta) {
            limpiarIntentosFallidosLogin($email);
            return ['bloqueado' => false, 'bloqueado_hasta' => null];
        }

        // 2) Si hace más de X horas que no intenta: resetear intentos (vuelve a tener 3)
        if ($ultimoIntento !== null) {
            $limiteActividad = date('Y-m-d H:i:s', strtotime('-' . RESET_INTENTOS_SIN_ACTIVIDAD_HORAS . ' hours'));
            if ($ultimoIntento <= $limiteActividad) {
                limpiarIntentosFallidosLogin($email);
                return ['bloqueado' => false, 'bloqueado_hasta' => null];
            }
        }

        // 3) Sigue bloqueado o con intentos pendientes
        if ($bloqueadoHasta === null) {
            return ['bloqueado' => false, 'bloqueado_hasta' => null];
        }
        return ['bloqueado' => true, 'bloqueado_hasta' => $bloqueadoHasta];
    } catch (Exception $e) {
        error_log("Error al verificar bloqueo de login: " . $e->getMessage());
        return ['bloqueado' => false, 'bloqueado_hasta' => null];
    }
}

/**
 * Registra un intento fallido de login. Si alcanza MAX_INTENTOS_LOGIN, bloquea el usuario por BLOQUEO_LOGIN_HORAS.
 * @param string $email Email del usuario
 * @return array ['bloqueado' => bool, 'intentos_restantes' => int|null, 'bloqueado_hasta' => string|null]
 */
function registrarIntentoFallidoLogin($email) {
    try {
        $pdo = getDBConnection();
        $ahora = date('Y-m-d H:i:s');
        // Insertar o actualizar (MySQL: INSERT ... ON DUPLICATE KEY UPDATE)
        $stmt = $pdo->prepare("
            INSERT INTO intentos_login (email, intentos_fallidos, ultimo_intento)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE
                intentos_fallidos = intentos_fallidos + 1,
                ultimo_intento = ?
        ");
        $stmt->execute([$email, $ahora, $ahora]);

        $stmt = $pdo->prepare("
            SELECT intentos_fallidos FROM intentos_login WHERE email = ?
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $intentos = (int) ($row['intentos_fallidos'] ?? 0);

        if ($intentos >= MAX_INTENTOS_LOGIN) {
            $bloqueadoHasta = date('Y-m-d H:i:s', strtotime("+" . BLOQUEO_LOGIN_HORAS . " hours"));
            $stmt = $pdo->prepare("
                UPDATE intentos_login SET bloqueado_hasta = ? WHERE email = ?
            ");
            $stmt->execute([$bloqueadoHasta, $email]);
            return [
                'bloqueado' => true,
                'intentos_restantes' => 0,
                'bloqueado_hasta' => $bloqueadoHasta
            ];
        }

        $restantes = max(0, MAX_INTENTOS_LOGIN - $intentos);
        return [
            'bloqueado' => false,
            'intentos_restantes' => $restantes,
            'bloqueado_hasta' => null
        ];
    } catch (Exception $e) {
        error_log("Error al registrar intento fallido: " . $e->getMessage());
        return ['bloqueado' => false, 'intentos_restantes' => null, 'bloqueado_hasta' => null];
    }
}

/**
 * Limpia los intentos fallidos para un email (llamar tras login exitoso).
 * @param string $email Email del usuario
 */
function limpiarIntentosFallidosLogin($email) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            DELETE FROM intentos_login WHERE email = ?
        ");
        $stmt->execute([$email]);
    } catch (Exception $e) {
        error_log("Error al limpiar intentos fallidos: " . $e->getMessage());
    }
}

// Función para sanitizar entrada
function sanitizar($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Valida el tamaño de un archivo subido según el tipo.
 * @param int $size_bytes Tamaño en bytes ($_FILES['campo']['size'])
 * @param string $tipo 'imagen', 'documento' o 'video'
 * @return array ['valido' => bool, 'mensaje' => string]
 */
function validarTamanoSubida($size_bytes, $tipo = 'imagen') {
    if ($tipo === 'video') {
        $limite = MAX_VIDEO_SIZE;
    } elseif ($tipo === 'documento') {
        $limite = MAX_DOCUMENT_SIZE;
    } else {
        $limite = MAX_IMAGE_SIZE;
    }
    $limite_mb = $limite / (1024 * 1024);
    if ($size_bytes > $limite) {
        return ['valido' => false, 'mensaje' => "El archivo supera el tamaño máximo permitido ({$limite_mb}MB)."];
    }
    return ['valido' => true, 'mensaje' => ''];
}

/**
 * Indica si PHP rechazó el POST por superar post_max_size (formulario y archivos vacíos).
 */
function subidaRechazadaPorLimiteServidor() {
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && empty($_POST)
        && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/**
 * Mensaje legible para códigos UPLOAD_ERR_* de PHP.
 */
function mensajeErrorSubidaPhp($error_code) {
    switch ((int) $error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'El archivo supera upload_max_filesize del servidor (php.ini). Solicite al hosting aumentar ese límite para videos.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el límite permitido por el formulario o post_max_size del servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'La subida se interrumpió. Intente de nuevo con conexión estable.';
        case UPLOAD_ERR_NO_FILE:
            return 'No se recibió ningún archivo. Seleccione un archivo e intente de nuevo.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'El servidor no tiene carpeta temporal para subidas (upload_tmp_dir). Contacte al administrador.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'No se pudo escribir el archivo en disco. Revise permisos del servidor.';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extensión de PHP bloqueó la subida del archivo.';
        default:
            return 'Error desconocido al subir el archivo (código ' . (int) $error_code . ').';
    }
}

/**
 * Obtiene MIME del archivo subido; tolera servidores sin extensión fileinfo.
 */
function mimeArchivoSubido($tmp_path, $nombre_original = '') {
    if ($tmp_path && is_readable($tmp_path) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmp_path);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }
    }
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    if (in_array($ext, ALLOWED_VIDEO_EXT, true)) {
        return 'video/' . ($ext === 'ogg' ? 'ogg' : $ext);
    }
    return 'application/octet-stream';
}

/**
 * Valida extensión y MIME de un archivo de documentos de interés (incluye videos).
 */
function validarArchivoDocumentosInteres($file) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = mimeArchivoSubido($file['tmp_name'], $file['name']);
    $es_video = in_array($ext, ALLOWED_VIDEO_EXT, true);
    $max_size = $es_video ? MAX_VIDEO_SIZE : MAX_DOCUMENT_SIZE;

    if (!in_array($ext, ALLOWED_DOCUMENTOS_INTERES_EXT, true)) {
        return ['valido' => false, 'mensaje' => 'Tipo no permitido. Use PDF, Word, Excel, PowerPoint o video (MP4, WebM, OGG).', 'ext' => $ext, 'es_video' => $es_video];
    }

    $mimes_ok = $es_video ? ALLOWED_VIDEO_MIMES : ALLOWED_DOCUMENTOS_INTERES_MIMES;
    if (!in_array($mime, $mimes_ok, true)) {
        // Si la extensión es video conocida, aceptar aunque el MIME sea genérico (común en producción)
        if (!$es_video || !in_array($mime, ['application/octet-stream', 'binary/octet-stream'], true)) {
            return ['valido' => false, 'mensaje' => 'Tipo MIME no reconocido (' . $mime . '). Extensión: .' . $ext . '. Si es un video válido, contacte soporte.', 'ext' => $ext, 'es_video' => $es_video];
        }
    }

    if ((int) $file['size'] > $max_size) {
        $max_mb = (int) ceil($max_size / (1024 * 1024));
        return ['valido' => false, 'mensaje' => 'El archivo supera ' . $max_mb . ' MB.', 'ext' => $ext, 'es_video' => $es_video];
    }

    return ['valido' => true, 'mensaje' => '', 'ext' => $ext, 'es_video' => $es_video];
}

/**
 * Mensaje cuando el hosting rechaza peticiones grandes (post_max_size / nginx client_max_body_size).
 */
function mensajeLimiteSubidaServidor($contexto = 'video') {
    $max_video_mb = (int) ceil(MAX_VIDEO_SIZE / (1024 * 1024));
    $base = 'La subida fue rechazada por el servidor antes de procesarse.';
    if ($contexto === 'video') {
        return $base . ' Límite de la aplicación para videos: ' . $max_video_mb . ' MB. En producción revise php.ini (upload_max_filesize, post_max_size), .user.ini o límites del servidor web (p. ej. client_max_body_size en Nginx).';
    }
    return $base . ' Revise upload_max_filesize y post_max_size en php.ini del hosting.';
}
