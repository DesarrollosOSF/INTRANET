<?php
require_once '../config/config.php';
require_once '../includes/mailer.php';
requerirPermiso('gestionar_usuarios');

$page_title = 'Probar envío de correos';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

// ---- Diagnóstico base (siempre visible) ----
$diagnostico = [
    'PHPMailer instalado' => mailerPhpmailerDisponible() ? 'Sí' : 'No — falta subir vendor/ al servidor',
    'SMTP configurado' => mailerSmtpConfigurado()
        ? 'Sí (' . SMTP_HOST . ':' . SMTP_PORT . ' / ' . SMTP_USER . ')'
        : 'NO — falta config/local.php con SMTP_PASS o sigue "tu_password"',
    'Remitente' => (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '?') . ' / ' . (defined('SMTP_FROM_NOMBRE') ? SMTP_FROM_NOMBRE : '?'),
    'mail() nativa' => function_exists('mail')
        ? 'Disponible (sendmail: ' . (ini_get('sendmail_path') ?: 'por defecto') . ')'
        : 'No disponible',
    'PHP' => PHP_VERSION . ' | OpenSSL: ' . (extension_loaded('openssl') ? 'sí' : 'NO'),
];

$etapas = [];   // resultados por etapas del diagnóstico de envío
$debug_smtp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email_prueba'] ?? '');
    if (!validarEmailDestino($email)) {
        $etapas[] = ['titulo' => 'Destino', 'ok' => false, 'detalle' => 'Email inválido o vacío.'];
    } else {
        // Etapa 1: conectividad TCP al SMTP
        if (mailerSmtpConfigurado()) {
            $con = probarConexionSmtp(10);
            $etapas[] = [
                'titulo' => 'Etapa 1 — Conexión a ' . SMTP_HOST . ':' . SMTP_PORT,
                'ok' => $con['ok'],
                'detalle' => $con['ok']
                    ? "Conexión OK ({$con['latencia_ms']} ms). Saludo: " . ($con['saludo'] ?: '(vacío)')
                    : $con['error'],
            ];
            // Etapa 2: envío solo-SMTP (con error exacto)
            if ($con['ok']) {
                $env = probarEnvioSmtp($email);
                $debug_smtp = $env['debug'];
                $etapas[] = [
                    'titulo' => 'Etapa 2 — Envío por SMTP a ' . $email,
                    'ok' => $env['ok'],
                    'detalle' => $env['ok']
                        ? 'Enviado por SMTP. Revisa bandeja de entrada y spam.'
                        : 'FALLO SMTP: ' . ($env['error'] ?? 'desconocido')
                            . "\nCausa probable: " . interpretarErrorSmtp($env['error'] ?? ''),
                ];
                registrarLog($_SESSION['usuario_id'] ?? 0, 'Probar correo SMTP', 'Usuarios',
                    "Destino: $email => " . ($env['ok'] ? 'OK' : 'FALLO: ' . ($env['error'] ?? '')));
            } else {
                $etapas[] = [
                    'titulo' => 'Etapa 2 — Envío por SMTP',
                    'ok' => false,
                    'detalle' => 'No se intenta porque falló la conexión (etapa 1). '
                        . 'Causa probable: el hosting bloquea SMTP saliente. '
                        . 'Pide que abran el puerto ' . SMTP_PORT . ' hacia ' . SMTP_HOST
                        . ' o usa el SMTP del propio hosting.',
                ];
            }
        } else {
            $etapas[] = [
                'titulo' => 'Etapa 1-2 — SMTP',
                'ok' => false,
                'detalle' => 'SMTP sin configurar en este servidor (falta config/local.php). '
                    . 'Se probará solo el envío básico mail().',
            ];
        }

        // Etapa 3: envío general (SMTP si hay, si no mail() nativa)
        $cuerpo = plantillaCorreoGeneral('Correo de prueba — Intranet OSF', "
            <p>Hola,</p>
            <p>Este es un correo de <strong>prueba</strong> enviado desde la Intranet OSF.</p>
            <p>Fecha: " . date('d/m/Y H:i:s') . "</p>
        ");
        $r = enviarCorreoGeneral($email, 'Prueba de correo — Intranet OSF', $cuerpo);
        $etapas[] = [
            'titulo' => 'Etapa 3 — Envío general a ' . $email,
            'ok' => $r['ok'],
            'detalle' => $r['ok']
                ? 'Aceptado vía ' . $r['metodo'] . '. Si no llega, revisa spam y la cuarentena de Outlook.'
                : 'FALLO: ' . ($r['error'] ?? 'desconocido'),
        ];
        registrarLog($_SESSION['usuario_id'] ?? 0, 'Probar correo', 'Usuarios',
            "Destino: $email => " . ($r['ok'] ? 'OK(' . $r['metodo'] . ')' : 'FALLO: ' . ($r['error'] ?? '')));
    }
}
?>

<div class="container-fluid mt-4">
    <h2><i class="bi bi-envelope-check me-2"></i>Probar envío de correos</h2>
    <p class="text-muted">Diagnóstico por etapas: primero verifica la conexión al SMTP, luego el envío autenticado y al final el envío general (el mismo que usan bienvenidas e inscripciones).</p>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Diagnóstico de este servidor</strong></div>
        <div class="card-body">
            <ul class="mb-0">
                <?php foreach ($diagnostico as $k => $v): ?>
                    <li><strong><?php echo htmlspecialchars($k); ?>:</strong> <?php echo htmlspecialchars($v); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!mailerSmtpConfigurado()): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    Crea <code>config/local.php</code> en <strong>este servidor</strong> con los datos SMTP del Gmail
                    (contraseña de aplicación de 16 letras, sin espacios). En local los correos van a
                    <a href="http://localhost:8025" target="_blank">Mailpit</a>; en producción deben salir por SMTP.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($etapas as $et): ?>
        <div class="alert alert-<?php echo $et['ok'] ? 'success' : 'danger'; ?>">
            <strong><?php echo htmlspecialchars($et['titulo']); ?></strong><br>
            <?php echo nl2br(htmlspecialchars($et['detalle'])); ?>
        </div>
    <?php endforeach; ?>

    <?php if ($debug_smtp !== ''): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><strong>Detalle técnico SMTP</strong> <span class="text-muted small">(credenciales ocultas)</span></div>
            <div class="card-body">
                <pre class="small mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($debug_smtp); ?></pre>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Correo de prueba (vale @osf.com.co o @gmail.com)</label>
                    <input type="email" class="form-control" name="email_prueba" placeholder="usuario@osf.com.co" required>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-2"></i>Diagnosticar y probar</button>
                    <a href="usuarios.php" class="btn btn-secondary">Volver</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
