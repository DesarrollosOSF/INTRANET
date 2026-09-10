<?php
/**
 * Mailer general de la Intranet OSF.
 *
 * - Usa PHPMailer + SMTP si está instalado (vendor/autoload.php + constantes SMTP_*).
 * - Si no, usa mail() nativa (en local Laragon cae a Mailpit, en producción
 *   requiere que el servidor tenga sendmail/postfix bien configurado).
 * - Valida el destinatario, funciona igual para @osf.com.co y @gmail.com.
 *   La diferencia de entrega NO es el código: es SPF/DKIM/DMARC del dominio
 *   remitente y la reputación IP. Ver notas al final.
 */

if (!function_exists('validarEmailDestino')) {
    function validarEmailDestino(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('mailerSmtpConfigurado')) {
    /**
     * ¿Hay SMTP real configurado? Detecta el placeholder 'tu_password'.
     */
    function mailerSmtpConfigurado(): bool
    {
        if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
            return false;
        }
        if (trim((string)SMTP_PASS) === '' || SMTP_PASS === 'tu_password') {
            return false;
        }
        if (trim((string)SMTP_HOST) === '' || trim((string)SMTP_USER) === '') {
            return false;
        }
        return true;
    }
}

if (!function_exists('mailerPhpmailerDisponible')) {
    function mailerPhpmailerDisponible(): bool
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        return class_exists('PHPMailer\PHPMailer\PHPMailer');
    }
}

if (!function_exists('enviarCorreoGeneral')) {
    /**
     * Envía un correo HTML.
     * @return array{ok:bool, metodo:string, error:string|null}
     */
    function enviarCorreoGeneral(string $destinatario, string $asunto, string $cuerpoHtml): array
    {
        $destinatario = trim($destinatario);
        if (!validarEmailDestino($destinatario)) {
            $msg = 'Email destinatario inválido: ' . $destinatario;
            error_log('Mailer OSF: ' . $msg);
            return ['ok' => false, 'metodo' => 'ninguno', 'error' => $msg];
        }
        if (trim($asunto) === '') {
            return ['ok' => false, 'metodo' => 'ninguno', 'error' => 'Asunto vacío'];
        }

        // 1) Intentar PHPMailer + SMTP (ideal para producción: Gmail y OSF reciben bien)
        if (mailerSmtpConfigurado() && mailerPhpmailerDisponible()) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
                $mail->SMTPSecure = defined('SMTP_SEGURIDAD') ? SMTP_SEGURIDAD : 'tls';
                $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
                $mail->CharSet = 'UTF-8';
                // Tiempo de espera razonable para no colgar la creación de usuarios
                $mail->Timeout = 15;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                    ],
                ];

                $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : SMTP_USER;
                $fromNombre = defined('SMTP_FROM_NOMBRE') ? SMTP_FROM_NOMBRE : 'Intranet OSF';
                $mail->setFrom($fromEmail, $fromNombre);
                $mail->addAddress($destinatario);
                // Reply-To igual al From para que los usuarios no respondan a un no-reply perdido
                $mail->addReplyTo($fromEmail, $fromNombre);
                $mail->isHTML(true);
                $mail->Subject = $asunto;
                $mail->Body = $cuerpoHtml;
                // Versión texto plano automática (mejora entrega en Gmail)
                $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $cuerpoHtml)));

                $mail->send();
                return ['ok' => true, 'metodo' => 'smtp-phpmailer', 'error' => null];
            } catch (Throwable $e) {
                $msg = 'Error PHPMailer (SMTP ' . SMTP_HOST . '): ' . $e->getMessage();
                error_log('Mailer OSF: ' . $msg);
                // Se sigue al fallback mail() para no perder el correo en local/Mailpit
            }
        } else {
            if (!mailerSmtpConfigurado()) {
                error_log('Mailer OSF: SMTP no configurado (revisa SMTP_PASS en config/config.php). Se usará mail() nativa.');
            } elseif (!mailerPhpmailerDisponible()) {
                error_log('Mailer OSF: PHPMailer no instalado (composer require phpmailer/phpmailer). Se usará mail() nativa.');
            }
        }

        // 2) Fallback: mail() nativa.
        // En Laragon local esto llega a Mailpit (http://localhost:8025), NO a Gmail/OSF reales.
        // En producción solo funciona si el servidor tiene sendmail configurado + SPF del dominio.
        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'no-responder@osf.com.co';
        $fromNombre = defined('SMTP_FROM_NOMBRE') ? SMTP_FROM_NOMBRE : 'Intranet OSF';
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . $fromNombre . ' <' . $fromEmail . ">\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
        $headers .= "X-Mailer: Intranet-OSF\r\n";

        $ok = @mail($destinatario, $asunto, $cuerpoHtml, $headers);
        if ($ok) {
            return ['ok' => true, 'metodo' => 'mail-nativa', 'error' => null];
        }
        $msg = 'mail() nativa devolvió false para ' . $destinatario;
        error_log('Mailer OSF: ' . $msg);
        return ['ok' => false, 'metodo' => 'mail-nativa', 'error' => $msg];
    }
}

if (!function_exists('plantillaCorreoGeneral')) {
    function plantillaCorreoGeneral(string $titulo, string $mensajeHtml): string
    {
        return '
        <div style="font-family: Arial, sans-serif; max-width:560px; margin:0 auto; border:1px solid #e2e2e2; border-radius:8px; overflow:hidden;">
            <div style="background:#1a3c6e; color:#fff; padding:20px 24px;">
                <h2 style="margin:0; font-size:18px;">' . htmlspecialchars($titulo) . '</h2>
            </div>
            <div style="padding:24px; color:#333; font-size:14px; line-height:1.5;">
                ' . $mensajeHtml . '
                <p style="margin-top:24px; font-size:12px; color:#888;">
                    Este es un mensaje automático de la Intranet OSF. Por favor no respondas a este correo.
                </p>
            </div>
        </div>';
    }
}

if (!function_exists('plantillaCorreoBienvenida')) {
    /**
     * Plantilla de bienvenida / cuenta activada.
     * $passwordPlana es opcional: solo se incluye si el admin la creó y quiere compartirla.
     */
    function plantillaCorreoBienvenida(string $nombre, string $email, ?string $passwordPlana = null): string
    {
        $base = defined('BASE_URL') ? (string)BASE_URL : '';
        // BASE_URL en este proyecto termina en '/', así que evitamos doble slash
        $loginUrl = rtrim($base, '/') . '/login.php';
        $passHtml = '';
        if ($passwordPlana !== null && $passwordPlana !== '') {
            $passHtml = "<p>Tu contraseña temporal es: <strong>" . htmlspecialchars($passwordPlana) . "</strong><br>"
                . "<span style=\"color:#c0392b;\">Cámbiala después de iniciar sesión (menú Perfil).</span></p>";
        }
        return plantillaCorreoGeneral('¡Bienvenido a la Intranet OSF!', "
            <p>Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
            <p>Tu cuenta de la Intranet OSF (<strong>" . htmlspecialchars($email) . "</strong>) ya está activa.</p>
            {$passHtml}
            <p>Puedes iniciar sesión aquí:</p>
            <p style='margin:20px 0;'>
                <a href='" . htmlspecialchars($loginUrl) . "' style='background:#1a3c6e;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;'>Iniciar sesión</a>
            </p>
            <p>Si no solicitaste esta cuenta, ignora este mensaje o contacta a soporte.</p>
        ");
    }
}

if (!function_exists('plantillaCorreoSolicitudRecibida')) {
    function plantillaCorreoSolicitudRecibida(string $nombre): string
    {
        return plantillaCorreoGeneral('Recibimos tu solicitud de cuenta', "
            <p>Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
            <p>Recibimos tu solicitud de registro en la Intranet OSF.</p>
            <p>Un administrador la revisará y te avisaremos por este mismo correo cuando tu cuenta sea activada.</p>
        ");
    }
}
