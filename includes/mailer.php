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

if (!function_exists('sanearDebugSmtp')) {
    /**
     * Quita credenciales (AUTH LOGIN en base64 y la clave literal) del log de PHPMailer.
     */
    function sanearDebugSmtp(string $log): string
    {
        $lineas = explode("\n", $log);
        $out = [];
        $ocultar = 0;
        foreach ($lineas as $ln) {
            if (stripos($ln, 'AUTH LOGIN') !== false) {
                $out[] = $ln;
                $ocultar = 2;
                continue;
            }
            if ($ocultar > 0 && strpos($ln, 'CLIENT -> SERVER') !== false
                && preg_match('/[A-Za-z0-9+\/=]{12,}\s*$/', $ln)) {
                $out[] = preg_replace('/[A-Za-z0-9+\/=]{12,}\s*$/', '[credencial oculta]', $ln);
                $ocultar--;
                continue;
            }
            $out[] = $ln;
        }
        $texto = implode("\n", $out);
        if (defined('SMTP_PASS') && SMTP_PASS !== '' && SMTP_PASS !== 'tu_password') {
            $texto = str_replace((string)SMTP_PASS, '[clave oculta]', $texto);
        }
        return $texto;
    }
}

if (!function_exists('probarConexionSmtp')) {
    /**
     * Etapa 1 del diagnóstico: ¿el servidor puede abrir conexión TCP al SMTP?
     * Si falla aquí, el hosting bloquea SMTP saliente (muy común en compartidos).
     * @return array{ok:bool, error:string|null, latencia_ms:int|null, saludo:string|null}
     */
    function probarConexionSmtp(int $timeout = 10): array
    {
        $host = defined('SMTP_HOST') ? trim((string)SMTP_HOST) : '';
        $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        if ($host === '') {
            return ['ok' => false, 'error' => 'SMTP_HOST vacío', 'latencia_ms' => null, 'saludo' => null];
        }
        $t0 = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (!$fp) {
            return [
                'ok' => false,
                'error' => "No se pudo conectar a {$host}:{$port} ({$ms} ms). Detalle: [{$errno}] {$errstr}. "
                    . 'Causa típica: el hosting bloquea conexiones SMTP externas.',
                'latencia_ms' => $ms,
                'saludo' => null,
            ];
        }
        stream_set_timeout($fp, 5);
        $saludo = fgets($fp, 512);
        fclose($fp);
        return ['ok' => true, 'error' => null, 'latencia_ms' => $ms, 'saludo' => trim((string)$saludo)];
    }
}

if (!function_exists('probarEnvioSmtp')) {
    /**
     * Etapa 2 del diagnóstico: intento de envío SOLO por SMTP (sin fallback),
     * devolviendo el error exacto de PHPMailer y su log saneado.
     * @return array{ok:bool, error:string|null, debug:string}
     */
    function probarEnvioSmtp(string $destinatario): array
    {
        $destinatario = trim($destinatario);
        if (!validarEmailDestino($destinatario)) {
            return ['ok' => false, 'error' => 'Email destinatario inválido', 'debug' => ''];
        }
        if (!mailerSmtpConfigurado()) {
            return ['ok' => false, 'error' => 'SMTP no configurado (SMTP_PASS sin definir)', 'debug' => ''];
        }
        if (!mailerPhpmailerDisponible()) {
            return ['ok' => false, 'error' => 'PHPMailer no instalado (falta vendor/)', 'debug' => ''];
        }
        $log = '';
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
            $mail->Timeout = 15;
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str) use (&$log) {
                $log .= $str . "\n";
            };

            $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : SMTP_USER;
            $fromNombre = defined('SMTP_FROM_NOMBRE') ? SMTP_FROM_NOMBRE : 'Intranet OSF';
            $mail->setFrom($fromEmail, $fromNombre);
            $mail->addAddress($destinatario);
            $mail->isHTML(true);
            $mail->Subject = 'Prueba SMTP — Intranet OSF (' . date('H:i:s') . ')';
            $mail->Body = plantillaCorreoGeneral('Prueba SMTP — Intranet OSF', '
                <p>Si recibiste este correo, el envío SMTP funciona correctamente.</p>
                <p>Fecha: ' . date('d/m/Y H:i:s') . '</p>');
            $mail->AltBody = 'Si recibiste este correo, el envío SMTP funciona correctamente.';

            $mail->send();
            return ['ok' => true, 'error' => null, 'debug' => sanearDebugSmtp($log)];
        } catch (Throwable $e) {
            $detalle = sanearDebugSmtp($log);
            // Anexa la última respuesta 5xx del servidor al error para que el
            // diagnóstico muestre la causa real (el getMessage() suele ser genérico).
            $msg = $e->getMessage();
            if (preg_match_all('/SERVER -> CLIENT:\s*(5\d\d[^\r\n]*)/i', $log, $m) && !empty($m[1])) {
                $ultima = trim(end($m[1]));
                $msg .= ' | Respuesta del servidor: ' . $ultima;
            }
            error_log('Mailer OSF (prueba SMTP): ' . $msg);
            return ['ok' => false, 'error' => $msg, 'debug' => $detalle];
        }
    }
}

if (!function_exists('interpretarErrorSmtp')) {
    /**
     * Traduce los errores típicos de SMTP a causa probable y solución.
     */
    function interpretarErrorSmtp(string $error): string
    {
        $e = strtolower($error);
        if (strpos($e, 'could not connect') !== false || strpos($e, 'connection timed out') !== false
            || strpos($e, 'failed to connect') !== false || strpos($e, 'connection refused') !== false) {
            return 'El servidor no logra hablar con el SMTP (puerto bloqueado o sin internet saliente). '
                . 'Soluciones: pedir al hosting que abra el puerto 587 hacia smtp.gmail.com, '
                . 'o usar el SMTP del propio hosting en lugar del de Gmail.';
        }
        if (strpos($e, 'password not accepted') !== false || strpos($e, '535') !== false
            || strpos($e, 'authentication failed') !== false || strpos($e, 'invalid credentials') !== false) {
            return 'Gmail rechazó usuario/clave. Revisa: (1) verificación en 2 pasos activa, '
                . '(2) usar la contraseña de aplicación de 16 letras SIN espacios, no la clave normal, '
                . '(3) que SMTP_USER sea el Gmail completo.';
        }
        if (strpos($e, 'must issue a starttls') !== false) {
            return 'Falta STARTTLS: usa puerto 587 con seguridad tls.';
        }
        if (strpos($e, 'daily sending quota') !== false || strpos($e, 'quota exceeded') !== false) {
            return 'Se superó el límite diario de Gmail (~500/día). Espera 24h o usa otro remitente.';
        }
        if (strpos($e, 'application-specific password required') !== false) {
            return 'Google exige contraseña de aplicación: actívala en myaccount.google.com → Seguridad.';
        }
        return 'Revisa el detalle técnico y el error_log del servidor para más información.';
    }
}
