<?php
require_once dirname(__DIR__) . '/config/config.php';
requerirAutenticacion();
require_once __DIR__ . '/helpers.php';

$secciones = require __DIR__ . '/sections.php';
$pdo = getDBConnection();

$slug = isset($_GET['seccion']) ? trim($_GET['seccion']) : '';
$seccion_actual = null;

if ($slug !== '') {
    $seccion_actual = experienciaSeccionPorSlug($slug);
    // Si entró con un slug antiguo, redirigir al nombre correcto en la URL
    if ($seccion_actual && $slug !== $seccion_actual['slug']) {
        header('Location: ' . BASE_URL . 'experiencia/index.php?seccion=' . urlencode($seccion_actual['slug']));
        exit;
    }
}

$modulos = [];
$archivos_por_modulo = [];
$sin_modulos_visibles = false;
$total_modulos_seccion = 0;
$puede_gestionar = usuarioPuedeGestionarExperiencia();
$tiene_contenido_db = true;

if ($seccion_actual) {
    try {
        $contenido = getExperienciaContenido($pdo, $seccion_actual['slug'], false);
        if ($contenido) {
            [$modulos_todos, $archivos_por_modulo_todos] = cargarModulosExperiencia($pdo, $contenido['id']);
            $total_modulos_seccion = count($modulos_todos);
            [$modulos, $archivos_por_modulo] = filtrarModulosExperienciaPorAcceso(
                $pdo,
                $modulos_todos,
                $archivos_por_modulo_todos,
                null,
                (int)$contenido['id']
            );
            $sin_modulos_visibles = !$puede_gestionar && $total_modulos_seccion > 0 && empty($modulos);
        }
    } catch (Exception $e) {
        $tiene_contenido_db = false;
    }
}

$page_title = $seccion_actual ? $seccion_actual['titulo'] : 'Experiencia';
$additional_css = ['assets/css/experiencia.css', 'assets/css/dashboard.css'];


require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="experiencia-layout">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>

    <main class="experiencia-main<?php echo $seccion_actual ? ' experiencia-main--section' : ''; ?>">
        <?php if ($seccion_actual): ?>
            <div class="experiencia-section-header experiencia-section-header--page">
                <h1>
                    <i class="bi <?php echo htmlspecialchars($seccion_actual['icon']); ?> me-2 text-primary"></i>
                    <?php echo htmlspecialchars($seccion_actual['titulo']); ?>
                </h1>
            </div>

            <div class="experiencia-section-content">
                <?php if (!$tiene_contenido_db): ?>
                    <div class="alert alert-info">El contenido dinámico aún no está instalado. Ejecute docs/experiencia_contenido.sql en la base de datos.</div>
                <?php else: ?>
                    <?php
                    $seccion_slug = $seccion_actual['slug'];
                    require __DIR__ . '/includes/modulos_publicos.php';
                    ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="experiencia-welcome">
                <div class="experiencia-welcome-icon">
                    <i class="bi bi-stars"></i>
                </div>
                <h1>Bienvenido a Experiencia Memorable</h1>
                <p>Este es un espacio creado para aprender, compartir, mejorar y crecer juntos. Aquí encontrarás herramientas, conocimiento, recursos y experiencias que fortalecen nuestra cultura de servicio, la calidad de nuestros procesos y nuestro compromiso con las familias, clientes y compañeros que confían en nosotros cada día.</p>
                <p class="mb-0">Te invitamos a explorar cada sección, participar activamente y descubrir cómo, desde tu rol, contribuyes a construir experiencias memorables que dejan huella. ✨</p>
                
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($seccion_actual): ?>
    <?php require __DIR__ . '/includes/viewer.php'; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.3/dist/docx-preview.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/experiencia.js"></script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>