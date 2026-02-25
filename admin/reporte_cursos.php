<?php
require_once '../config/config.php';
requerirPermiso('ver_reportes');

$page_title = 'Reportes de Cursos';
$additional_css = ['assets/css/admin.css'];

$pdo = getDBConnection();
$stmt = $pdo->query("
    SELECT c.*, 
           COUNT(DISTINCT i.id) as total_inscritos,
           COUNT(DISTINCT CASE WHEN ie.estado = 'aprobado' THEN i.id END) as total_aprobados
    FROM cursos c
    LEFT JOIN inscripciones i ON c.id = i.curso_id
    LEFT JOIN evaluaciones e ON c.id = e.curso_id
    LEFT JOIN intentos_evaluacion ie ON e.id = ie.evaluacion_id AND i.id = ie.inscripcion_id
    WHERE c.activo = 1
    GROUP BY c.id
    ORDER BY c.nombre
");
$cursos_reporte = $stmt->fetchAll();

require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-bar-chart me-2"></i>Reportes de Cursos</h2>
        <a href="<?php echo BASE_URL; ?>index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Volver al Dashboard
        </a>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Usuarios inscritos y aprobados por curso</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Curso</th>
                            <th>Total Inscritos</th>
                            <th>Total Aprobados</th>
                            <th>Porcentaje Aprobación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cursos_reporte)): ?>
                            <tr><td colspan="5" class="text-muted text-center">No hay cursos activos.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cursos_reporte as $curso): 
                                $porcentaje = $curso['total_inscritos'] > 0 
                                    ? round(($curso['total_aprobados'] / $curso['total_inscritos']) * 100, 2) 
                                    : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($curso['nombre']); ?></td>
                                    <td><span class="badge bg-info"><?php echo $curso['total_inscritos']; ?></span></td>
                                    <td><span class="badge bg-success"><?php echo $curso['total_aprobados']; ?></span></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $porcentaje; ?>%">
                                                <?php echo $porcentaje; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>admin/reportes.php?curso_id=<?php echo $curso['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye me-1"></i>Ver Detalle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
