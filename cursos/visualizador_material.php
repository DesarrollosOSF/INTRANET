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
?>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-<?php 
                echo $material_actual['tipo'] === 'video' ? 'play-circle' : 
                    ($material_actual['tipo'] === 'pdf' ? 'file-pdf' : 'image'); 
            ?> me-2"></i>
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
            <?php if ($material_actual['tipo'] === 'video'): ?>
                <video id="videoPlayer" 
                       class="w-100" 
                       controls 
                       controlsList="nodownload"
                       oncontextmenu="return false;"
                       style="max-height: 600px;">
                    <source src="<?php echo $archivo_url; ?>" type="video/mp4">
                    Tu navegador no soporta videos HTML5.
                </video>
                
            <?php elseif ($material_actual['tipo'] === 'pdf'): ?>
                <div class="pdf-viewer-container">
                    <iframe src="<?php echo $archivo_url; ?>#toolbar=0&navpanes=0" 
                            class="w-100" 
                            style="height: 600px; border: 1px solid #ddd;"
                            oncontextmenu="return false;"
                            id="pdfViewer"
                            data-src="<?php echo htmlspecialchars($archivo_url); ?>"></iframe>
                </div>
                
            <?php elseif ($material_actual['tipo'] === 'imagen'): ?>
                <div class="text-center">
                    <img src="<?php echo $archivo_url; ?>" 
                         class="img-fluid" 
                         style="max-height: 600px;"
                         oncontextmenu="return false;"
                         id="imagenViewer">
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
        const esPDF = <?php echo $material_actual['tipo'] === 'pdf' ? 'true' : 'false'; ?>;
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
