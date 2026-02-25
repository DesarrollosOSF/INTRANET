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

// Rutas
//define('BASE_URL', 'http://localhost/DesarrollosDuvan/Intranet-OSF/');
define('BASE_URL', '/DesarrollosDuvan/Intranet-OSF/');
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
