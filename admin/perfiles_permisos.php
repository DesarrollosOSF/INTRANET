<?php
require_once '../config/config.php';
requerirPermiso('gestionar_perfiles_permisos');

$page_title = 'Perfiles y permisos';
$additional_css = ['assets/css/admin.css'];

require_once '../includes/header.php';

$pdo = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';

// Guardar permisos del perfil seleccionado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perfil_id'])) {
    $perfil_id = (int)$_POST['perfil_id'];
    $permisos_ids = isset($_POST['permiso_id']) && is_array($_POST['permiso_id'])
        ? array_map('intval', $_POST['permiso_id'])
        : [];
    
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM perfil_permisos WHERE perfil_id = ?");
        $stmt->execute([$perfil_id]);
        
        if (!empty($permisos_ids)) {
            $stmt = $pdo->prepare("INSERT INTO perfil_permisos (perfil_id, permiso_id) VALUES (?, ?)");
            foreach ($permisos_ids as $permiso_id) {
                if ($permiso_id > 0) {
                    $stmt->execute([$perfil_id, $permiso_id]);
                }
            }
        }
        
        $pdo->commit();
        registrarLog($_SESSION['usuario_id'], 'Actualizar permisos del perfil', 'Perfiles', "Perfil ID: $perfil_id");
        $mensaje = 'Permisos del perfil actualizados correctamente.';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = 'Error al guardar: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Perfil seleccionado (GET o POST para recargar, o primer perfil)
$perfil_seleccionado = isset($_GET['perfil_id']) ? (int)$_GET['perfil_id'] : (isset($_POST['perfil_id']) ? (int)$_POST['perfil_id'] : 0);

$perfiles = $pdo->query("SELECT * FROM perfiles ORDER BY id")->fetchAll();
$permisos = $pdo->query("SELECT * FROM permisos ORDER BY nombre")->fetchAll();

// Si hay perfiles y no hay selección, usar el primero
if (!empty($perfiles) && $perfil_seleccionado === 0) {
    $perfil_seleccionado = (int)$perfiles[0]['id'];
}

// Permisos actuales del perfil seleccionado
$permisos_del_perfil = [];
if ($perfil_seleccionado > 0) {
    $stmt = $pdo->prepare("SELECT permiso_id FROM perfil_permisos WHERE perfil_id = ?");
    $stmt->execute([$perfil_seleccionado]);
    $permisos_del_perfil = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-shield-lock me-2"></i>Perfiles y permisos</h2>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
            <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="get" action="" class="mb-4">
                <label class="form-label fw-bold">Seleccionar perfil</label>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <select name="perfil_id" class="form-select" style="max-width: 320px;" onchange="this.form.submit()">
                        <?php foreach ($perfiles as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($perfil_seleccionado === (int)$p['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['nombre']); ?> (ID <?php echo $p['id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-muted small"><?php echo count($perfiles); ?> perfil(es)</span>
                </div>
                <?php if ($perfil_seleccionado > 0): ?>
                    <?php 
                    $perfil_actual = null;
                    foreach ($perfiles as $p) { if ((int)$p['id'] === $perfil_seleccionado) { $perfil_actual = $p; break; } }
                    if ($perfil_actual && !empty($perfil_actual['descripcion'])): ?>
                        <p class="text-muted small mt-1 mb-0"><?php echo htmlspecialchars($perfil_actual['descripcion']); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
            
            <?php if ($perfil_seleccionado > 0): ?>
            <form method="post" action="">
                <input type="hidden" name="perfil_id" value="<?php echo $perfil_seleccionado; ?>">
                <p class="text-muted mb-3">Marque los permisos que tendrá este perfil.</p>
                <div class="row g-3">
                    <?php foreach ($permisos as $perm): 
                        $permiso_id = (int)$perm['id'];
                        $marcado = in_array($permiso_id, $permisos_del_perfil);
                    ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="permiso_id[]" value="<?php echo $permiso_id; ?>" id="perm_<?php echo $permiso_id; ?>" <?php echo $marcado ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="perm_<?php echo $permiso_id; ?>">
                                    <?php echo htmlspecialchars($perm['nombre']); ?>
                                    <?php if (!empty($perm['descripcion'])): ?>
                                        <br><span class="text-muted small"><?php echo htmlspecialchars($perm['descripcion']); ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-square me-2"></i>Guardar permisos de este perfil
                    </button>
                    <a href="<?php echo BASE_URL; ?>admin/usuarios.php" class="btn btn-outline-secondary ms-2">Volver a Usuarios</a>
                </div>
            </form>
            <?php else: ?>
                <p class="text-muted">No hay perfiles definidos.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
