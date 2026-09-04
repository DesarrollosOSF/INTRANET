<?php
/**
 * Ejecutar UNA VEZ AL DÍA vía Cron Job (cPanel > Cron Jobs), por ejemplo a las 7:00am:
 *   php /home/tu_usuario/public_html/cron/notificaciones_formacion.php
 *
 * Este script:
 *   1) Envía el correo de "inicio" a cualquier inscripción que por alguna razón
 *      no lo haya recibido al momento de inscribirse (red de seguridad).
 *   2) Envía el recordatorio cuando faltan exactamente 3 días para el cierre.
 *   3) Marca como "vencido" (correo informativo) lo que cerró sin completarse.
 *
 * No requiere que nadie visite la página: corre solo, en el servidor.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/formacion_mailer.php';

$pdo = getDBConnection();
$enviados = ['inicio' => 0, 'recordatorio_3_dias' => 0, 'vencido' => 0];

// ---------- 1) Red de seguridad: correos de inicio pendientes ----------
$stmt = $pdo->query("
    SELECT fi.id
    FROM formacion_inscripciones fi
    WHERE fi.id NOT IN (SELECT inscripcion_id FROM notificaciones_formacion WHERE tipo = 'inicio')
");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $inscripcion_id) {
    if (enviarCorreoInicioFormacion($pdo, (int)$inscripcion_id)) {
        $enviados['inicio']++;
    }
}

// ---------- 2) Recordatorio a 3 días del cierre ----------
$stmt = $pdo->query("
    SELECT fi.id, u.email, u.nombre_completo, fc.id AS curso_id, fc.nombre AS curso, fc.fecha_cierre
    FROM formacion_inscripciones fi
    JOIN usuarios u ON u.id = fi.usuario_id
    JOIN formacion_cursos fc ON fc.id = fi.curso_id
    WHERE fi.completado = 0
      AND fc.fecha_cierre = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
      AND fi.id NOT IN (SELECT inscripcion_id FROM notificaciones_formacion WHERE tipo = 'recordatorio_3_dias')
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $link = (defined('BASE_URL') ? BASE_URL : '') . '/formacion/detalle_curso.php?id=' . $d['curso_id'];
    $cuerpo = plantillaCorreoFormacion('Te quedan 3 días para completar tu curso', "
        <p>Hola <strong>" . htmlspecialchars($d['nombre_completo']) . "</strong>,</p>
        <p>El curso <strong>" . htmlspecialchars($d['curso']) . "</strong> cierra el
           <strong>" . date('d/m/Y', strtotime($d['fecha_cierre'])) . "</strong> y aún no lo has completado.</p>
        <p style='margin-top:20px;'>
            <a href='" . htmlspecialchars($link) . "' style='background:#c0392b;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;'>Completar ahora</a>
        </p>
    ");

    if (enviarCorreoFormacion($d['email'], 'Recordatorio: ' . $d['curso'] . ' cierra en 3 días', $cuerpo)) {
        $enviados['recordatorio_3_dias']++;
    }
    $pdo->prepare("INSERT IGNORE INTO notificaciones_formacion (inscripcion_id, tipo) VALUES (?, 'recordatorio_3_dias')")
        ->execute([$d['id']]);
}

// ---------- 3) Aviso de curso vencido (sin completar) ----------
$stmt = $pdo->query("
    SELECT fi.id, u.email, u.nombre_completo, fc.nombre AS curso, fc.fecha_cierre
    FROM formacion_inscripciones fi
    JOIN usuarios u ON u.id = fi.usuario_id
    JOIN formacion_cursos fc ON fc.id = fi.curso_id
    WHERE fi.completado = 0
      AND fc.fecha_cierre IS NOT NULL
      AND fc.fecha_cierre < CURDATE()
      AND fi.id NOT IN (SELECT inscripcion_id FROM notificaciones_formacion WHERE tipo = 'vencido')
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $cuerpo = plantillaCorreoFormacion('Curso de Formación vencido', "
        <p>Hola <strong>" . htmlspecialchars($d['nombre_completo']) . "</strong>,</p>
        <p>El plazo para completar el curso <strong>" . htmlspecialchars($d['curso']) . "</strong>
           venció el " . date('d/m/Y', strtotime($d['fecha_cierre'])) . " sin que lo finalizaras.</p>
        <p>Por favor contacta a Talento Humano para conocer los próximos pasos.</p>
    ");

    if (enviarCorreoFormacion($d['email'], 'Vencido: ' . $d['curso'], $cuerpo)) {
        $enviados['vencido']++;
    }
    $pdo->prepare("INSERT IGNORE INTO notificaciones_formacion (inscripcion_id, tipo) VALUES (?, 'vencido')")
        ->execute([$d['id']]);
}

echo "Notificaciones de Formación — " . date('Y-m-d H:i:s') . "\n";
echo "  Inicio (respaldo): {$enviados['inicio']}\n";
echo "  Recordatorio 3 días: {$enviados['recordatorio_3_dias']}\n";
echo "  Vencidos: {$enviados['vencido']}\n";
