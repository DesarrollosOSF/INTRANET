<?php
require_once '../config/config.php';
require_once '../includes/mailer.php';
requerirPermiso('gestionar_usuarios');

$page_title = 'Probar envío de correos';
$additional_css = ['assets/css/admin.css'];
require_once '../includes/header.php';

$resultados = [];
$diagnostico = [
    'PHPMailer instalado' => mailerPhpmailerDisponible() ? 'Sí' : 'No (composer require phpmailer/phpmailer)',
    'SMTP configurado' => mailerSmtpConfigurado() ? 'Sí (' . SMTP_HOST . ' / ' . SMTP_USER . ')' : 'NO — SMTP_PASS sigue siendo "tu_password" o falta config',
    'Remitente' => (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '?') . ' / ' . (defined('SMTP_FROM_NOMBRE') ? SMTP_FROM_NOMBRE : '?'),
    'mail() nativa' => function_exists('mail') ? 'Disponible (en local Laragon llega a Mailpit http://localhost:8025)' : 'No disponible',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destinos = [
        'osf' => trim($_POST['email_osf'] ?? ''),
        'gmail' => trim($_POST['email_gmail'] ?? ''),
    ];
    foreach ($destinos as $tipo => $email) {
        if ($email === '') {
            continue;
        }
        if (!validarEmailDestino($email)) {
            $resultados[] = ['destino' => $email, 'ok' => false, 'metodo' => 'ninguno', 'error' => 'Email inválido'];
            continue;
        }
        $cuerpo = plantillaCorreoGeneral('Correo de prueba — Intranet OSF', "
            <p>Hola,</p>
            <p>Este es un correo de <strong>prueba</strong> enviado desde la Intranet OSF.</p>
            <p>Si lo recibiste, el envío a <strong>" . htmlspecialchars($email) . "</strong> funciona correctamente.</p>
            <p>Fecha: " . date('d/m/Y H:i') . "</p>
        ");
        $r = enviarCorreoGeneral($email, 'Prueba de correo — Intranet OSF', $cuerpo);
        $resultados[] = ['destino' => $email, 'ok' => $r['ok'], 'metodo' => $r['metodo'], 'error' => $r['error']];
        registrarLog($_SESSION['usuario_id'] ?? 0, 'Probar correo', 'Usuarios', "Destino: $email => " . ($r['ok'] ? 'OK(' . $r['metodo'] . ')' : 'FALLO: ' . $r['error']));
    }
}
?>

<div class="container-fluid mt-4">
    <h2><i class="bi bi-envelope-check me-2"></i>Probar envío de correos</h2>
    <p class="text-muted">Verifica que los correos lleguen a <code>@osf.com.co</code> y a <code>@gmail.com</code>. El código trata ambos dominios igual; si uno falla suele ser filtro anti-spam o SMTP sin autenticar.</p>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Diagnóstico actual</strong></div>
        <div class="card-body">
            <ul class="mb-0">
                <?php foreach ($diagnostico as $k => $v): ?>
                    <li><strong><?php echo htmlspecialchars($k); ?>:</strong> <?php echo htmlspecialchars($v); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!mailerSmtpConfigurado()): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <strong>En local:</strong> los correos van a <a href="http://localhost:8025" target="_blank">Mailpit (http://localhost:8025)</a>, no a Gmail/OSF reales. Es lo esperado.<br>
                    <strong>En producción:</strong> configura en <code>config/local.php</code> el <code>SMTP_PASS</code> real del buzón remitente
                    e instala PHPMailer (<code>composer require phpmailer/phpmailer</code>).
                    Sin eso, Gmail suele rechazar o mandar a spam los correos de <code>mail()</code> sin SPF/DKIM.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($resultados as $r): ?>
        <div class="alert alert-<?php echo $r['ok'] ? 'success' : 'danger'; ?>">
            <strong><?php echo htmlspecialchars($r['destino']); ?>:</strong>
            <?php echo $r['ok'] ? 'Enviado (' . htmlspecialchars($r['metodo']) . '). Revisa bandeja de entrada y spam.' : 'FALLO: ' . htmlspecialchars($r['error'] ?? 'desconocido'); ?>
        </div>
    <?php endforeach; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Correo @osf.com.co</label>
                    <input type="email" class="form-control" name="email_osf" placeholder="usuario@osf.com.co">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Correo @gmail.com</label>
                    <input type="email" class="form-control" name="email_gmail" placeholder="usuario@gmail.com">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-2"></i>Enviar pruebas</button>
                    <a href="usuarios.php" class="btn btn-secondary">Volver a Usuarios</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
