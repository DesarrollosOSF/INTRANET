<?php
/**
 * Panel lateral del módulo Experiencia.
 * Variables esperadas: $secciones, $seccion_actual (null = inicio/bienvenida)
 */
$seccion_activa = $seccion_actual['slug'] ?? '';
?>
<aside class="experiencia-sidebar">
    <div class="experiencia-sidebar-brand">
        <i class="bi bi-heart-fill"></i>
        <span>Experiencia</span>
    </div>
    <div class="experiencia-sidebar-label">Secciones</div>
    <nav class="experiencia-sidebar-nav" aria-label="Menú Experiencia">
        <a href="<?php echo BASE_URL; ?>experiencia/index.php"
           class="experiencia-sidebar-link spa-nav-link<?php echo $seccion_activa === '' ? ' active' : ''; ?>"
           title="Página de bienvenida de Experiencia">
            <i class="bi bi-house-door"></i>
            <span>Inicio</span>
        </a>
        <?php foreach ($secciones as $seccion):
            $titulo_menu = $seccion['titulo_menu'] ?? $seccion['titulo'];
            $activa = $seccion_activa === $seccion['slug'];
            $tooltip = $seccion['intro'] ?? ($seccion['tooltip'] ?? '');
        ?>
            <a href="<?php echo BASE_URL; ?>experiencia/index.php?seccion=<?php echo urlencode($seccion['slug']); ?>"
               class="experiencia-sidebar-link spa-nav-link<?php echo $activa ? ' active' : ''; ?><?php echo $tooltip !== '' ? ' experiencia-sidebar-link--tooltip' : ''; ?>"
               <?php echo $activa ? 'aria-current="page"' : ''; ?>
               <?php if ($tooltip !== ''): ?>
               data-tooltip="<?php echo htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8'); ?>"
               <?php endif; ?>>
                <i class="bi <?php echo htmlspecialchars($seccion['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($titulo_menu); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
