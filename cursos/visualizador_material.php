<?php
// Este archivo se incluye desde ver_curso.php
// Variables disponibles: $material_actual, $inscripcion, $curso_id, $curso_finalizado_para_visualizador

$pdo = getDBConnection();
$curso_finalizado = isset($curso_finalizado_para_visualizador) ? $curso_finalizado_para_visualizador : false;

// Obtener o crear progreso
$stmt = $pdo->prepare("
    SELECT * FROM progreso_material
    WHERE inscripcion_id = ? AND material_id = ?
");
$stmt->execute([$inscripcion['id'], $material_actual['id']]);
$progreso = $stmt->fetch();

if (!$progreso) {
    $stmt = $pdo->prepare("
        INSERT INTO progreso_material (inscripcion_id, material_id, fecha_inicio)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$inscripcion['id'], $material_actual['id']]);
    $progreso_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM progreso_material WHERE id = ?");
    $stmt->execute([$progreso_id]);
    $progreso = $stmt->fetch();
}

$archivo_url = UPLOAD_URL . $material_actual['archivo'];
$completado = $progreso['completado'] ?? 0;

// Tipo efectivo: columna BD + extensión (materiales viejos o mal etiquetados)
$archivo_relativo = $material_actual['archivo'] ?? '';
$ext = strtolower(pathinfo($archivo_relativo, PATHINFO_EXTENSION));
$tipo_bd = $material_actual['tipo'] ?? '';

$es_video = ($tipo_bd === 'video');
$es_pdf = ($tipo_bd === 'pdf' || $ext === 'pdf');
$es_imagen = ($tipo_bd === 'imagen' || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true));
$es_word = ($tipo_bd === 'word' || in_array($ext, ['doc', 'docx'], true));
$es_ppt = ($tipo_bd === 'ppt' || in_array($ext, ['ppt', 'pptx'], true));
$tienes_archivo = $archivo_relativo !== '';

$nombre_descarga = basename(str_replace('\\', '/', $archivo_relativo));
if ($nombre_descarga === '' || $nombre_descarga === '.') {
    $nombre_descarga = 'material';
}

if ($es_video) {
    $icono_header = 'play-circle';
} elseif ($es_pdf) {
    $icono_header = 'file-pdf';
} elseif ($es_word) {
    $icono_header = 'file-earmark-word';
} elseif ($es_ppt) {
    $icono_header = 'file-earmark-slides';
} elseif ($es_imagen) {
    $icono_header = 'image';
} else {
    $icono_header = 'file-earmark';
}
?>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-<?php echo $icono_header; ?> me-2"></i>
            <?php echo htmlspecialchars($material_actual['titulo']); ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if ($material_actual['descripcion']): ?>
            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars($material_actual['descripcion'])); ?></p>
        <?php endif; ?>

        <?php if (!$curso_finalizado): ?>
            <?php if ($completado): ?>
                <div class="alert alert-success mb-3">
                    <i class="bi bi-check-circle me-2"></i>Material completado
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnMarcarCompletado" onclick="marcarCompletado()">
                        <i class="bi bi-check-circle me-1"></i>Marcar como completado
                    </button>
                </div>
            <?php endif; ?>
        <?php else: ?>
        <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-2"></i>Curso finalizado - Puedes revisar el material sin restricciones
        </div>
        <?php endif; ?>

        <div class="material-viewer">
            <?php if ($es_video): ?>
                <video id="videoPlayer"
                       class="w-100"
                       controls
                       controlsList="nodownload"
                       oncontextmenu="return false;"
                       style="max-height: 600px;">
                    <source src="<?php echo htmlspecialchars($archivo_url); ?>" type="video/mp4">
                    Tu navegador no soporta videos HTML5.
                </video>

            <?php elseif ($es_pdf): ?>
                <div class="pdf-viewer-container">
                    <iframe src="<?php echo htmlspecialchars($archivo_url); ?>#toolbar=0&navpanes=0"
                            class="w-100"
                            style="height: 600px; border: 1px solid #ddd;"
                            oncontextmenu="return false;"
                            id="pdfViewer"
                            data-src="<?php echo htmlspecialchars($archivo_url); ?>"></iframe>
                </div>
                

            <?php elseif ($es_word): ?>
                <div class="border rounded p-4 bg-light text-center" style="min-height: 280px;">
                    <i class="bi bi-file-earmark-word text-primary" style="font-size: 3rem;"></i>
                    <h6 class="mt-3 mb-2">Documento Word</h6>
                    <p class="text-muted mb-3">
                        Descargue el archivo y ábralo con Word u otro programa compatible para visualizarlo.
                    </p>
                    <div class="text-center">
                        <a href="<?php echo htmlspecialchars($archivo_url); ?>"
                           class="btn btn-primary"
                           download="<?php echo htmlspecialchars($nombre_descarga); ?>">
                            <i class="bi bi-download me-1"></i>Descargar documento
                        </a>
                    </div>
                </div>

            <?php elseif ($es_ppt): ?>
                <div class="border rounded p-4 bg-light text-center" style="min-height: 280px;">
                    <i class="bi bi-file-earmark-slides text-primary" style="font-size: 3rem;"></i>
                    <h6 class="mt-3 mb-2">Presentación PowerPoint</h6>
                    <p class="text-muted mb-3">
                        Descargue el archivo y ábralo con PowerPoint u otro programa compatible para visualizarlo.
                    </p>
                    <div class="text-center">
                        <a href="<?php echo htmlspecialchars($archivo_url); ?>"
                           class="btn btn-primary"
                           download="<?php echo htmlspecialchars($nombre_descarga); ?>">
                            <i class="bi bi-download me-1"></i>Descargar presentación
                        </a>
                    </div>
                </div>

            <?php elseif ($es_imagen): ?>
                <div class="text-center">
                    <img src="<?php echo htmlspecialchars($archivo_url); ?>"
                         class="img-fluid"
                         style="max-height: 600px;"
                         oncontextmenu="return false;"
                         id="imagenViewer"
                         alt="">
                </div>

            <?php elseif ($tienes_archivo): ?>
                <div class="border rounded p-4 bg-light text-center">
                    <p class="text-muted mb-3">Este material no tiene vista previa en el navegador. Puede descargarlo para abrirlo en su equipo.</p>
                    <a href="<?php echo htmlspecialchars($archivo_url); ?>"
                       class="btn btn-primary"
                       download="<?php echo htmlspecialchars($nombre_descarga); ?>">
                        <i class="bi bi-download me-1"></i>Descargar archivo
                    </a>
                </div>

            <?php else: ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    No hay archivo asociado a este material.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const materialId = <?php echo $material_actual['id']; ?>;
const inscripcionId = <?php echo $inscripcion['id']; ?>;

function marcarCompletado() {
    const btn = document.getElementById('btnMarcarCompletado');
    if (btn) btn.disabled = true;
    fetch('<?php echo BASE_URL; ?>api/completar_material.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ inscripcion_id: inscripcionId, material_id: materialId })
    }).then(() => {
        const esPDF = <?php echo $es_pdf ? 'true' : 'false'; ?>;
        if (!esPDF) {
            location.reload();
        } else {
            if (btn) btn.remove();
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success mb-3';
            alertDiv.innerHTML = '<i class="bi bi-check-circle me-2"></i>Material completado';
            document.querySelector('.card-body').insertBefore(alertDiv, document.querySelector('.material-viewer'));
        }
    }).catch(err => { console.error('Error al marcar completado:', err); if (btn) btn.disabled = false; });
}

// Prevenir descarga
document.addEventListener('contextmenu', function(e) {
    if (e.target.tagName === 'VIDEO' || e.target.tagName === 'IMG' || e.target.closest('iframe')) {
        e.preventDefault();
        return false;
    }
});

// Prevenir atajos de teclado
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && (e.key === 's' || e.key === 'p')) || e.key === 'F12') {
        e.preventDefault();
        return false;
    }
});
</script>
