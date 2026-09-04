<?php
require_once 'config/config.php';
requerirAutenticacion();
$pdo = getDBConnection();

$page_title = 'Manual de usuario';
require_once 'includes/header.php';

$es_admin = in_array($_SESSION['rol'] ?? '', ['super_admin', 'administrador'], true);
?>

<div class="container mt-4" style="max-width: 900px;">
    <div class="text-center mb-4">
        <i class="bi bi-life-preserver text-primary" style="font-size: 2.5rem;"></i>
        <h2 class="mt-2 mb-1">Manual de usuario</h2>
        <p class="text-muted">Guía rápida de cada sección de la Intranet OSF</p>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="if(window.iniciarTourBienvenida) window.iniciarTourBienvenida(true)">
            <i class="bi bi-play-circle me-1"></i>Ver el tour guiado de nuevo
        </button>
    </div>

    <div class="accordion" id="manualAccordion">

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#manDashboard">
                    <i class="bi bi-house-door me-2"></i>Dashboard (Inicio)
                </button>
            </h2>
            <div id="manDashboard" class="accordion-collapse collapse show" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>Es la primera pantalla que ves al entrar. Aquí encuentras:</p>
                    <ul>
                        <li><strong>Colaborador del mes</strong>:Reconocimiento mensual, cuando esté configurado.</li>
                        <li><strong>Comunicados importantes</strong>: anuncios, noticias y eventos vigentes de la organización.
                            Haz clic sobre cualquier tarjeta para verlo en pantalla completa.</li>

                    </ul>
                    <button type="button" class="btn btn-outline-primary btn-sm" >
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>index.php">
                        <i class="bi bi-house me-1"></i>Dashboard
                    </a>
                    </button>
                </div>
            </div>
        </div>


        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manCursos">
                    <i class="bi bi-collection-play me-2"></i>Cursos
                </button>
            </h2>
            <div id="manCursos" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>Cursos internos preparados por distintas áreas de la organización, con su propio contenido y
                        cuestionario de evaluación. Funciona de forma independiente al módulo de Formación.</p>
                    <button type="button" class="btn btn-outline-primary btn-sm" >
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>cursos/index.php" id="menu-cursos">
                            <i class="bi bi-book me-1"></i>Cursos
                        </a>
                    </button>

                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manFormacion">
                    <i class="bi bi-mortarboard me-2"></i>Formación
                </button>
            </h2>
            <div id="manFormacion" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>Aquí encuentras los cursos y capacitaciones que debes o puedes tomar:</p>
                    <ul>
                        <li>El menú de la izquierda agrupa los cursos por entidad: OSF, SENA, Comfaboy, Positiva u otra.</li>
                        <li>Los cursos marcados como <strong>Obligatorio</strong> tienen fecha límite — te llega un correo al inscribirte y otro 3 días antes del cierre.</li>
                        <li>Algunos cursos requieren inscribirte tú mismo (verás un botón "Inscribirme"); otros ya quedan asignados automáticamente.</li>
                        <li>Dentro de un curso, completas los materiales de cada módulo y su quiz correspondiente; al terminar todos los módulos, presentas la evaluación general.</li>
                        <li>Al aprobar, puedes descargar tu <strong>certificado de asistencia</strong> y responder la <strong>encuesta de satisfacción</strong>.</li>
                    </ul>
                    <button type="button" class="btn btn-outline-primary btn-sm" >
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>formacion/index.php" id="menu-formacion">
                        <i class="bi bi-book me-1"></i>Formacion
                    </a>
                    </button>
                    
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manDocumentos">
                    <i class="bi bi-file-earmark-text me-2"></i>Documentos de interés
                </button>
            </h2>
            <div id="manDocumentos" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>Formatos y plantillas oficiales organizados por dependencia: permisos, solicitudes de
                        vacaciones, cesantías, código de vestimenta y demás documentos que necesitas consultar o descargar.</p>
                    <button type="button" class="btn btn-outline-primary btn-sm" >
                           <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>datos_interes.php" id="menu-documentos">
                        <i class="bi bi-folder2-open me-1"></i>Documentos de interés
                    </a>
                    </button>
                     
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manExperiencia">
                    <i class="bi bi-stars me-2"></i>Experiencia
                </button>
            </h2>
            <div id="manExperiencia" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>Documentación del Sistema de Gestión de Calidad, plantillas institucionales, protocolos de
                        comunicación y boletines de la Dirección de Experiencia y Calidad.</p>
                    <button type="button" class="btn btn-outline-primary btn-sm" >
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>experiencia/index.php" id="menu-experiencia">
                        <i class="bi bi-heart me-1"></i>Experiencia
                    </a>
                    </button>
                    
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manSst">
                    <i class="bi bi-heart-pulse me-2"></i>SST (Seguridad y Salud en el Trabajo)
                </button>
            </h2>
            <div id="manSst" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>Material de bienestar y prevención: pausas activas, recomendaciones de seguridad y salud
                        en el trabajo, actualizado periódicamente por el área encargada.</p>
                        <button type="button" class="btn btn-outline-primary btn-sm" >
                        <a class="nav-link spa-nav-link" href="<?php echo BASE_URL; ?>sst/index.php" id="menu-sst">
                        <i class="bi bi-shield-check me-1"></i>SST
                    </a>
                    </button>
                </div>
            </div>
        </div>


        <?php if ($es_admin): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#manAdmin">
                        <i class="bi bi-gear me-2"></i>Panel administrativo (solo administradores)
                    </button>
                </h2>
                <div id="manAdmin" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                    <div class="accordion-body">
                        <p>Desde el panel de administración puedes:</p>
                        <ul>
                            <li>Gestionar <strong>usuarios</strong>, dependencias y permisos por perfil.</li>
                            <li>Publicar <strong>comunicados</strong>, documentos de interés, contenido de SST y de Experiencia.</li>
                            <li>Crear y administrar <strong>cursos de Formación</strong>: módulos, materiales, evaluaciones por
                                módulo y general, y la encuesta de satisfacción.</li>
                            <li>Consultar <strong>reportes</strong> de Formación (aprobación, histogramas de la encuesta,
                                descarga de respuestas individuales) y de <strong>Auditoría</strong> (accesos a la plataforma y
                                qué contenido ve cada colaborador).</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="text-center text-muted small mt-4 mb-5">
        ¿Tienes dudas que no resuelve esta guía? Contacta a Talento Humano o al área de Sistemas.
    </div>
</div>

<?php require_once 'includes/tour_bienvenida.php'; ?>

<?php require_once 'includes/footer.php'; ?>