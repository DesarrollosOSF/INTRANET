<?php
/**
 * Envío de correos del módulo Formación.
 *
 * Requiere que definas estas constantes en config/config.php:
 *   define('SMTP_HOST', 'mail.tudominio.com');
 *   define('SMTP_USER', 'notificaciones@tudominio.com');
 *   define('SMTP_PASS', 'tu_password');
 *   define('SMTP_PORT', 587);
 *   define('SMTP_SEGURIDAD', 'tls');
 *   define('SMTP_FROM_EMAIL', 'notificaciones@tudominio.com');
 *   define('SMTP_FROM_NOMBRE', 'Intranet OSF');
 *   define('BASE_URL', 'https://intranet.tudominio.com'); // sin slash final
 *
 * Y tener instalado PHPMailer:  composer require phpmailer/phpmailer
 * Si no está instalado, cae automáticamente a la función mail() nativa de PHP.
 */

function enviarCorreoFormacion(string $destinatario, string $asunto, string $cuerpoHtml): bool
{
    require_once __DIR__ . '/mailer.php';
    $r = enviarCorreoGeneral($destinatario, $asunto, $cuerpoHtml);
    return $r['ok'];
    // NOTA: implementación SMTP/PHPMailer anterior migrada a includes/mailer.php
    // para compartir configuración y servir a @osf.com.co y @gmail.com por igual.
}

function plantillaCorreoFormacion(string $titulo, string $mensajeHtml): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    return '
    <div style="font-family: Arial, sans-serif; max-width:560px; margin:0 auto; border:1px solid #e2e2e2; border-radius:8px; overflow:hidden;">
        <div style="background:#1a3c6e; color:#fff; padding:20px 24px;">
            <h2 style="margin:0; font-size:18px;">' . htmlspecialchars($titulo) . '</h2>
        </div>
        <div style="padding:24px; color:#333; font-size:14px; line-height:1.5;">
            ' . $mensajeHtml . '
            <p style="margin-top:24px; font-size:12px; color:#888;">
                Este es un mensaje automático del módulo de Formación de la Intranet OSF. No respondas a este correo.
            </p>
        </div>
    </div>';
}

/**
 * Envía el correo de "curso iniciado" para una inscripción puntual y registra
 * en notificaciones_formacion que ya se envió (para no duplicar).
 * Es seguro llamarla varias veces: si ya se envió, no hace nada.
 */
function enviarCorreoInicioFormacion(PDO $pdo, int $inscripcion_id): bool
{
    $yaEnviado = $pdo->prepare("SELECT 1 FROM notificaciones_formacion WHERE inscripcion_id = ? AND tipo = 'inicio'");
    $yaEnviado->execute([$inscripcion_id]);
    if ($yaEnviado->fetchColumn()) {
        return true; // ya se había enviado, no reenviar
    }

    $stmt = $pdo->prepare("
        SELECT u.email, u.nombre_completo, fc.id AS curso_id, fc.nombre AS curso, fc.fecha_cierre
        FROM formacion_inscripciones fi
        JOIN usuarios u ON u.id = fi.usuario_id
        JOIN formacion_cursos fc ON fc.id = fi.curso_id
        WHERE fi.id = ?
    ");
    $stmt->execute([$inscripcion_id]);
    $d = $stmt->fetch();
    if (!$d || empty($d['email'])) {
        return false;
    }

    $base = defined('BASE_URL') ? BASE_URL : '';
    $link = $base . '/formacion/detalle_curso.php?id=' . $d['curso_id'];
    $cierre_txt = $d['fecha_cierre'] ? date('d/m/Y', strtotime($d['fecha_cierre'])) : 'sin fecha límite';

    $cuerpo = plantillaCorreoFormacion('Te inscribiste a un curso de Formación', "
        <p>Hola <strong>" . htmlspecialchars($d['nombre_completo']) . "</strong>,</p>
        <p>Quedaste inscrito al curso <strong>" . htmlspecialchars($d['curso']) . "</strong>.</p>
        <p>Fecha límite para completarlo: <strong>{$cierre_txt}</strong>.</p>
        <p style='margin-top:20px;'>
            <a href='" . htmlspecialchars($link) . "' style='background:#1a3c6e;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;'>Ir al curso</a>
        </p>
    ");

    $enviado = enviarCorreoFormacion($d['email'], 'Curso de Formación: ' . $d['curso'], $cuerpo);

    try {
        $pdo->prepare("INSERT IGNORE INTO notificaciones_formacion (inscripcion_id, tipo) VALUES (?, 'inicio')")
            ->execute([$inscripcion_id]);
    } catch (Exception $e) {
        error_log('No se pudo registrar notificación de inicio: ' . $e->getMessage());
    }

    return $enviado;
}
