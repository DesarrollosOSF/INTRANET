<?php
// Requiere que $pdo y $_SESSION['usuario_id'] ya existan antes de incluir este archivo.
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT tutorial_visto FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$mostrar_tour_automatico = ((int)$stmt->fetchColumn() === 0);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>

<style>
/* ===== Tour de bienvenida OSF — tema colorido ===== */
.driver-popover.tour-osf-theme {
    border: none;
    border-radius: 16px;
    padding: 0;
    overflow: hidden;
    max-width: 340px;
    box-shadow: 0 16px 40px rgba(20,20,45,.25);
    animation: tourPopIn .28s cubic-bezier(.34,1.56,.64,1);
}
@keyframes tourPopIn {
    from { opacity: 0; transform: scale(.9) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.driver-popover.tour-osf-theme .driver-popover-title {
    background: linear-gradient(135deg, var(--tour-a1, #667eea), var(--tour-a2, #764ba2));
    color: #fff;
    margin: 0;
    padding: 18px 42px 16px 20px;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.3;
}

.driver-popover.tour-osf-theme .driver-popover-description {
    padding: 16px 20px 6px;
    color: #3d3d3a;
    font-size: 14px;
    line-height: 1.55;
}

.driver-popover.tour-osf-theme .driver-popover-footer {
    padding: 14px 20px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.driver-popover.tour-osf-theme .driver-popover-progress-text {
    background: linear-gradient(135deg, var(--tour-a1, #667eea), var(--tour-a2, #764ba2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    font-weight: 700;
    font-size: 12px;
}

.driver-popover.tour-osf-theme .driver-popover-navigation-btns { display: flex; gap: 8px; }

.driver-popover.tour-osf-theme button {
    border-radius: 8px !important;
    font-weight: 600 !important;
    font-size: 13px !important;
    padding: 7px 16px !important;
    border: none !important;
    text-shadow: none !important;
    transition: transform .15s ease, box-shadow .15s ease !important;
}

.driver-popover.tour-osf-theme .driver-popover-next-btn {
    background: linear-gradient(135deg, var(--tour-a1, #667eea), var(--tour-a2, #764ba2)) !important;
    color: #fff !important;
}
.driver-popover.tour-osf-theme .driver-popover-next-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,.2); }

.driver-popover.tour-osf-theme .driver-popover-prev-btn { background: #eef0f6 !important; color: #555 !important; }
.driver-popover.tour-osf-theme .driver-popover-prev-btn:hover { background: #e2e5ee !important; }

.driver-popover.tour-osf-theme .driver-popover-close-btn {
    color: #fff !important;
    opacity: .85;
    font-size: 20px !important;
    top: 12px !important;
    right: 14px !important;
}
.driver-popover.tour-osf-theme .driver-popover-close-btn:hover { opacity: 1; transform: rotate(90deg); }

.driver-popover.tour-osf-theme .driver-popover-arrow-side-left.driver-popover-arrow  { border-left-color: var(--tour-a1, #667eea) !important; }
.driver-popover.tour-osf-theme .driver-popover-arrow-side-right.driver-popover-arrow { border-right-color: var(--tour-a1, #667eea) !important; }
.driver-popover.tour-osf-theme .driver-popover-arrow-side-top.driver-popover-arrow   { border-top-color: var(--tour-a1, #667eea) !important; }
.driver-popover.tour-osf-theme .driver-popover-arrow-side-bottom.driver-popover-arrow{ border-bottom-color: var(--tour-a1, #667eea) !important; }

/* Resalta el elemento señalado con un glow de color */
.driver-active-element {
    border-radius: 10px !important;
    box-shadow: 0 0 0 4px rgba(102,126,234,.35), 0 0 28px rgba(102,126,234,.35) !important;
    transition: box-shadow .2s ease !important;
}
.driver-overlay { background: rgba(15,15,35,.6) !important; }

/* Un color de gradiente distinto por sección, para que se sienta vivo */
.tour-step-bienvenida   { --tour-a1:#667eea; --tour-a2:#764ba2; }
.tour-step-comunicados  { --tour-a1:#f7971e; --tour-a2:#ffd200; }
.tour-step-colaborador  { --tour-a1:#f857a6; --tour-a2:#ff5858; }
.tour-step-cursos       { --tour-a1:#11998e; --tour-a2:#38ef7d; }
.tour-step-formacion    { --tour-a1:#8e2de2; --tour-a2:#4a00e0; }
.tour-step-documentos   { --tour-a1:#2193b0; --tour-a2:#6dd5ed; }
.tour-step-experiencia  { --tour-a1:#ee0979; --tour-a2:#ff6a00; }
.tour-step-sst          { --tour-a1:#56ab2f; --tour-a2:#a8e063; }
.tour-step-manual       { --tour-a1:#4568dc; --tour-a2:#b06ab3; }
.tour-step-ayuda        { --tour-a1:#eb3349; --tour-a2:#f45c43; }

/* Botón flotante de ayuda: un poco de vida también */
#btnAyudaFlotante {
    background: linear-gradient(135deg, #667eea, #764ba2) !important;
    border: none !important;
    animation: tourPulso 2.4s ease-in-out infinite;
}
#btnAyudaFlotante:hover { animation: none; transform: scale(1.08); }
@keyframes tourPulso {
    0%, 100% { box-shadow: 0 4px 14px rgba(102,126,234,.5); }
    50%      { box-shadow: 0 4px 22px rgba(102,126,234,.85); }
}
</style>

<button type="button" id="btnAyudaFlotante" class="btn rounded-circle shadow"
        style="position:fixed; bottom:24px; right:24px; width:52px; height:52px; z-index:1050; font-size:1.2rem; color:#fff;"
        title="Ver tour de ayuda" onclick="window.iniciarTourBienvenida()">
    <i class="bi bi-question-lg"></i>
</button>

<script>
window.iniciarTourBienvenida = function () {
    // Cada paso solo se incluye si el elemento existe en la página actual —
    // así el tour no se rompe si falta alguno (por ejemplo, si no hay colaborador del mes).
    var pasosDefinidos = [
        {
            popover: {
                title: '👋 ¡Bienvenido a la Intranet OSF!',
                description: 'Te mostramos rápidamente las secciones principales. Puedes cerrar este tour en cualquier momento y volver a verlo cuando quieras desde el botón inferior derecho con el signo de pregunta.',
                popoverClass: 'tour-osf-theme tour-step-bienvenida'
            }
        },
        {
            element: '#comunicadosGrid',
            popover: {
                title: '📢 Comunicados importantes',
                description: 'Aquí aparecen los anuncios, noticias y eventos vigentes. Haz clic en cualquier tarjeta para verla completa.',
                side: 'top',
                popoverClass: 'tour-osf-theme tour-step-comunicados'
            }
        },
        {
            element: '#tourColaboradorMes',
            popover: {
                title: '🏆 Colaborador del mes',
                description: 'Cuando esté configurado, aquí verás el reconocimiento del mes.',
                side: 'bottom',
                popoverClass: 'tour-osf-theme tour-step-colaborador'
            }
        },
        {
            element: '#menu-cursos',
            popover: {
                title: '📘 Cursos',
                description: 'Tus cursos internos, organizados por la Organización.',
                side: 'right',
                popoverClass: 'tour-osf-theme tour-step-cursos'
            }
        },
        {
            element: '#menu-formacion',
            popover: {
                title: '🎓 Formación',
                description: 'Tus cursos obligatorios y opcionales, organizados por entidad (OSF, SENA, etc.).',
                side: 'right',
                popoverClass: 'tour-osf-theme tour-step-formacion'
            }
        },
        {
            element: '#menu-documentos',
            popover: {
                title: '📂 Documentos de interés',
                description: 'Formatos y plantillas oficiales de cada dependencia.',
                side: 'right',
                popoverClass: 'tour-osf-theme tour-step-documentos'
            }
        },
        {
            element: '#menu-experiencia',
            popover: {
                title: '💜 Experiencia',
                description: 'Formatos, plantillas y documentos de interés para mejorar y crecer.',
                side: 'right',
                popoverClass: 'tour-osf-theme tour-step-experiencia'
            }
        },
        {
            element: '#menu-sst',
            popover: {
                title: '🛡️ SST',
                description: 'Pausas activas y contenido de seguridad y salud en el trabajo.',
                side: 'right',
                popoverClass: 'tour-osf-theme tour-step-sst'
            }
        },
        {
            element: '#menu-manual',
            popover: {
                title: '📖 Guía de usuario',
                description: 'Aquí encontrarás una guía con información de cada sección.',
                side: 'right',
                popoverClass: 'tour-osf-theme tour-step-manual'
            }
        },
        {
            element: '#btnAyudaFlotante',
            popover: {
                title: '💬 ¿Necesitas repasar esto?',
                description: 'Haz clic aquí cuando quieras volver a ver este tour. También puedes visitar el Manual de usuario completo desde el menú.',
                side: 'left',
                popoverClass: 'tour-osf-theme tour-step-ayuda'
            }
        }
    ];

    var pasos = pasosDefinidos.filter(function (p) {
        return !p.element || document.querySelector(p.element);
    });

    var driverObj = window.driver.js.driver({
        showProgress: true,
        animate: true,
        smoothScroll: true,
        stagePadding: 8,
        stageRadius: 12,
        overlayOpacity: 0.6,
        nextBtnText: 'Siguiente →',
        prevBtnText: '← Anterior',
        doneBtnText: '¡Listo! 🎉',
        steps: pasos,
        onDestroyed: function () {
            fetch('api/marcar_tutorial_visto.php', { method: 'POST' });
        }
    });
    driverObj.drive();
};

<?php if ($mostrar_tour_automatico): ?>
document.addEventListener('DOMContentLoaded', function () {
    window.iniciarTourBienvenida();
});
<?php endif; ?>
</script>
