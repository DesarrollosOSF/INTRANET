<?php
/**
 * Configuración general de la aplicación
 */

// Configuración de sesión
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Cambiar a 1 en producción con HTTPS
session_start();

// Zona horaria
date_default_timezone_set('America/Bogota');

// Override opcional: crear config/local.php con define('BASE_URL', '...'); para forzar una ruta
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

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

// Configuración de archivos y límites de tamaño (10 MB para todos: PDF, video, imagen)
define('MAX_FILE_SIZE', 10 * 1024 * 1024);   // 10MB límite genérico
define('MAX_IMAGE_SIZE', 10 * 1024 * 1024);  // 10MB para imágenes
define('MAX_DOCUMENT_SIZE', 10 * 1024 * 1024); // 10MB para documentos/PDFs/videos
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/ogg']);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_DOCUMENT_TYPES', ['application/pdf']);
// Documentos de interés: PDF, Word, Excel
define('UPLOAD_PATH_DOCUMENTOS_INTERES', UPLOAD_PATH . 'documentos_interes/');
define('ALLOWED_DOCUMENTOS_INTERES_EXT', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);
define('ALLOWED_DOCUMENTOS_INTERES_MIMES', [
    'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
]);

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
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as tiene
            FROM usuario_perfiles up
            INNER JOIN perfil_permisos pp ON up.perfil_id = pp.perfil_id
            INNER JOIN permisos p ON pp.permiso_id = p.id
            WHERE up.usuario_id = ? AND p.nombre = ?
        ");
        
        $stmt->execute([$_SESSION['usuario_id'], $permiso_nombre]);
        $result = $stmt->fetch();
        
        return $result['tiene'] > 0;
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
 * @param string $tipo 'imagen' o 'documento' (10MB máx para ambos: PDFs, videos, imágenes)
 * @return array ['valido' => bool, 'mensaje' => string]
 */
function validarTamanoSubida($size_bytes, $tipo = 'imagen') {
    $limite = ($tipo === 'documento') ? MAX_DOCUMENT_SIZE : MAX_IMAGE_SIZE;
    $limite_mb = $limite / (1024 * 1024);
    if ($size_bytes > $limite) {
        return ['valido' => false, 'mensaje' => "El archivo supera el tamaño máximo permitido ({$limite_mb}MB)."];
    }
    return ['valido' => true, 'mensaje' => ''];
}
