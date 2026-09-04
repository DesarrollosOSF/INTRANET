<?php
/**
 * experiencia/solicitar_cambio.php
 */
require_once dirname(__DIR__) . '/config/config.php';
requerirAutenticacion();
require_once __DIR__ . '/helpers.php';

$seccion_redirect = trim( $_POST['seccion_slug'] ?? $_POST['seccion_redirect'] ?? '' );

$archivo_id = (int)( $_POST['archivo_id'] ?? 0 );
$modulo_id = (int)( $_POST['modulo_id'] ?? 0 );

$tipo = trim( $_POST['tipo_solicitud'] ?? '' );

$comentario = trim( $_POST['comentario'] ?? '' );
$criterios = [];


if ( isset($_POST['criterios']) && is_array($_POST['criterios']) ) {
    $criterios = $_POST['criterios'];
}

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || $archivo_id <= 0 || $modulo_id <= 0 ) {
    header( 'Location: ' . BASE_URL . 'experiencia/index.php?solicitud=error' );
    exit;
}

$pdo = getDBConnection();
require_once '../includes/auditoria_helpers.php'; // ajusta la ruta según la profundidad del archivo
registrarVista($pdo, $_SESSION['usuario_id'], 'experiencia', $archivo_id);

if (!tablaSolicitudesCambioOk($pdo)) {

    $redir =
        $seccion_redirect !== '' ?
        BASE_URL . 'experiencia/index.php?seccion=' . urlencode( $seccion_redirect ) : BASE_URL . 'experiencia/index.php';

    header(
        'Location: ' . $redir . ( strpos( $redir, '?' ) !== false ? '&' : '?' ) . 'solicitud=sin_migracion'
    );
    exit;
}

/*
 * Obtener documento.
 */
$row = getArchivoExperienciaConModulo( $pdo, $archivo_id, $modulo_id );

if (!$row) {
    header(
        'Location: '
            . BASE_URL
            . 'experiencia/index.php?solicitud=error'
    );
    exit;
}

$contenido_id = (int)$row['contenido_id'];

/*
 * Determinar sección.
 */
if ($seccion_redirect === '') {

    $stmt =
        $pdo->prepare("
            SELECT seccion_slug
            FROM experiencia_contenidos
            WHERE id = ?
        ");
    $stmt->execute([ $contenido_id ]);
    $seccion_redirect = (string)$stmt->fetchColumn();
}

$redir = BASE_URL . 'experiencia/index.php?seccion=' . urlencode( $seccion_redirect );

/*
 * Verificar permisos.
 */
$usuario_id_actual = $_SESSION['usuario_id'] ?? 0;

if ( !usuarioPuedeGestionarExperiencia()) {

    $restricciones_modulos = cargarRestriccionesModulosExperiencia( $pdo, $contenido_id );
    $restricciones_archivos = cargarRestriccionesArchivosExperiencia( $pdo, $contenido_id );

    $puede_ver = usuarioPuedeVerModuloExperiencia(
            $modulo_id,
            $restricciones_modulos,
            $usuario_id_actual )
        &&
        usuarioPuedeVerArchivoExperiencia(
            $modulo_id,
            $archivo_id,
            $restricciones_archivos,
            $usuario_id_actual
        );

    if (!$puede_ver) {
        header(
            'Location: '
                . $redir
                . '&solicitud=sin_permiso'
        );
        exit;
    }
}

/*
 * Validaciones básicas.
 */
if ( $comentario === '' || $tipo === '' ) {
    header(
        'Location: '
            . $redir
            . '&solicitud=error'
    );
    exit;
}

/*
 * Validar todos los criterios.
 *
 * Cada criterio debe tener
 * una respuesta: si o no.
 */
$criterios_plano = criteriosCalidadDocumentalPlano();

foreach ( $criterios_plano as $key => $label ) {

    if ( !isset($criterios[$key]) || !in_array( strtolower( trim( (string)$criterios[$key] ) ), ['si', 'no'], true )) {
        header( 'Location: ' . $redir . '&solicitud=checklist_incompleto' );
        exit;
    }
}

/*
 * Crear solicitud.
 */
try { crearSolicitudCambioExperiencia(
        $pdo,
        $archivo_id,
        $modulo_id,
        $contenido_id,
        $usuario_id_actual,
        $tipo,
        $comentario,
        $criterios
    );

    header( 'Location: ' . $redir . '&solicitud=ok' );

    exit;
} catch (Exception $e) { error_log( 'Error solicitud Experiencia: ' . $e->getMessage() );

    header( 'Location: ' . $redir . '&solicitud=error' );
    exit;
}

