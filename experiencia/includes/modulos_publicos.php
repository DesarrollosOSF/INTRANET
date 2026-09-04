<?php

/**
 * Vista pública de módulos y archivos de Experiencia.
 * Variables: $modulos, $archivos_por_modulo, $puede_gestionar (opcional), $seccion_slug (opcional)
 */
$puede_gestionar = $puede_gestionar ?? false;
$seccion_slug = $seccion_slug ?? '';
$resultado_solicitud = $_GET['solicitud'] ?? '';
$grupos_criterios = criteriosCalidadDocumentalExperiencia();
?>

<?php if ($resultado_solicitud === 'ok'): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-1"></i> Tu solicitud de cambio fue enviada correctamente. El equipo de Experiencia la revisará.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php elseif ($resultado_solicitud === 'sin_permiso'): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        No tienes permiso para solicitar cambios sobre ese documento.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php elseif ($resultado_solicitud === 'sin_migracion'): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        La función de solicitudes de cambio aún no está instalada. Ejecute <code>docs/experiencia_solicitudes_cambio.sql</code>.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php elseif ($resultado_solicitud === 'checklist_incompleto'): ?>

    <div class="alert alert-warning alert-dismissible fade show">

        <i class="bi bi-exclamation-triangle me-1"></i>

        Debes responder <strong>Sí o No</strong> en todos los
        criterios de revisión de calidad documental.

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert">
        </button>

    </div>
<?php elseif ($resultado_solicitud === 'error'): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        No fue posible enviar tu solicitud. Intenta nuevamente.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="experiencia-modulos-wrap">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Módulos y archivos</h5>
        <?php if ($puede_gestionar && $seccion_slug !== ''): ?>
            <a href="<?php echo BASE_URL; ?>admin/experiencia_detalle.php?seccion=<?php echo urlencode($seccion_slug); ?>"
                class="btn btn-sm btn-primary spa-nav-link">
                <i class="bi bi-gear me-1"></i>Gestionar contenido
            </a>

        <?php endif; ?>
    </div>

    <?php if (!empty($sin_modulos_visibles)): ?>
        <div class="experiencia-content-card">
            <p class="text-muted mb-0">
                <i class="bi bi-shield-lock me-1"></i>
                No tiene permiso para ver los módulos de esta sección. Si necesita acceso, contacte al administrador.
            </p>
        </div>
    <?php elseif (empty($modulos)): ?>
        <div class="experiencia-content-card">
            <p class="text-muted mb-0">Aún no hay módulos ni archivos publicados para esta sección.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($modulos as $mod):
                $archivos = $archivos_por_modulo[$mod['id']] ?? [];
                $solo_visualizacion = !empty($mod['solo_visualizacion']);
            ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card shadow-sm experiencia-modulo-card" data-modulo-id="<?php echo (int)$mod['id']; ?>">
                        <button type="button"
                            class="experiencia-modulo-toggle card-header bg-white border-bottom-0 w-100 text-start"
                            aria-expanded="false"
                            aria-controls="experiencia-modulo-panel-<?php echo (int)$mod['id']; ?>">
                            <span class="d-flex align-items-start gap-2 w-100">
                                <i class="bi bi-chevron-right experiencia-modulo-chevron flex-shrink-0" aria-hidden="true"></i>
                                <span class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary flex-shrink-0 experiencia-modulo-icon">
                                    <i class="bi bi-layers-half"></i>
                                </span>
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-block fw-semibold text-primary experiencia-modulo-titulo">
                                        <?php echo htmlspecialchars($mod['titulo']); ?>
                                        <?php if ($solo_visualizacion): ?>
                                            <span class="badge bg-info text-dark ms-1 align-middle experiencia-modulo-badge">Solo visualización</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($mod['descripcion'])): ?>
                                        <span class="d-block small text-muted mt-1"><?php echo htmlspecialchars($mod['descripcion']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($archivos)): ?>
                                        <span class="d-block small text-muted mt-1">
                                            <i class="bi bi-paperclip me-1"></i><?php echo count($archivos); ?> archivo<?php echo count($archivos) === 1 ? '' : 's'; ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </button>
                        <div class="experiencia-modulo-panel collapse"
                            id="experiencia-modulo-panel-<?php echo (int)$mod['id']; ?>">
                            <div class="card-body pt-2 border-top">
                                <?php if (empty($archivos)): ?>
                                    <div class="alert alert-light border mb-0 small text-muted">
                                        <i class="bi bi-info-circle me-1"></i>Sin archivos en este módulo.
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($archivos as $ar):
                                            $ext = strtolower(pathinfo($ar['archivo'], PATHINFO_EXTENSION));
                                            $icono = iconoArchivoExperiencia($ar['archivo']);
                                            $viewer_url = urlVisorExperienciaArchivo((int)$ar['id'], (int)$mod['id']);
                                            $office_url = urlAbsolutaVisorExperienciaArchivo((int)$ar['id'], (int)$mod['id']);
                                        ?>
                                            <div class="list-group-item px-0">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <?php if ($solo_visualizacion): ?>
                                                        <button type="button"
                                                            class="btn btn-link text-decoration-none d-flex justify-content-between align-items-start gap-2 p-0 border-0 text-start flex-grow-1 doc-viewer-trigger"
                                                            data-nombre="<?php echo htmlspecialchars($ar['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-descripcion="<?php echo htmlspecialchars($ar['descripcion'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-viewer-url="<?php echo htmlspecialchars($viewer_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-office-url="<?php echo htmlspecialchars($office_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-ext="<?php echo htmlspecialchars($ext, ENT_QUOTES, 'UTF-8'); ?>"
                                                            title="Clic para ver en pantalla completa">
                                                            <div class="d-flex align-items-start gap-2 flex-grow-1">
                                                                <i class="bi bi-<?php echo $icono; ?> text-primary mt-1"></i>
                                                                <div class="flex-grow-1">
                                                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($ar['nombre']); ?></div>
                                                                    <?php if (!empty($ar['descripcion'])): ?>
                                                                        <div class="small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <i class="bi bi-arrows-fullscreen text-muted mt-1 flex-shrink-0"></i>
                                                        </button>
                                                    <?php else:
                                                        $url = UPLOAD_URL_EXPERIENCIA . $ar['archivo'];
                                                    ?>
                                                        <a href="<?php echo htmlspecialchars($url); ?>" class="text-decoration-none d-flex justify-content-between align-items-start gap-2 flex-grow-1" download>
                                                            <div class="d-flex align-items-start gap-2 flex-grow-1">
                                                                <i class="bi bi-<?php echo $icono; ?> text-primary mt-1"></i>
                                                                <div class="flex-grow-1">
                                                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($ar['nombre']); ?></div>
                                                                    <?php if (!empty($ar['descripcion'])): ?>
                                                                        <div class="small text-muted"><?php echo htmlspecialchars($ar['descripcion']); ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <i class="bi bi-download text-muted mt-1 flex-shrink-0"></i>
                                                        </a>
                                                    <?php endif; ?>

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-warning flex-shrink-0 btn-solicitar-cambio"
                                                        title="Solicitar cambio o actualización de este documento"
                                                        data-archivo-id="<?php echo (int)$ar['id']; ?>"
                                                        data-modulo-id="<?php echo (int)$mod['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars(
                                                                            $ar['nombre'],
                                                                            ENT_QUOTES,
                                                                            'UTF-8'
                                                                        ); ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal único de "Solicitar cambio" -->
<div class="modal fade" id="modalSolicitudCambio" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST"
                action="<?php echo BASE_URL; ?>experiencia/solicitar_cambio.php" id="formSolicitudCambio">
                <!-- Datos ocultos -->
                <input type="hidden"
                    name="seccion_slug"
                    value="<?php echo htmlspecialchars($seccion_slug, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden"
                    name="archivo_id"
                    id="scArchivoId"
                    value="">
                <input type="hidden"
                    name="modulo_id"
                    id="scModuloId"
                    value="">
                <!-- =====================================================
                     HEADER FIJO
                     ===================================================== -->
                <div class="modal-header bg-light">
                    <div>
                        <h5 class="modal-title mb-1"> <i class="bi bi-pencil-square me-2"></i>
                            Solicitar cambio / Revisión documental
                        </h5>
                        <small class="text-muted">
                            Complete la información y verifique los criterios
                            de calidad documental.
                        </small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"> </button>

                </div>
                <!-- =====================================================
                     CUERPO CON SCROLL
                     ===================================================== -->
                <div class="modal-body experiencia-modal-scroll">
                    <!-- Documento -->
                    <div class="alert alert-primary d-flex align-items-start gap-2">
                        <i class="bi bi-file-earmark-text fs-5"></i>
                        <div>
                            <div class="small text-muted">Documento</div>
                            <strong id="scDocumentoNombre"></strong>
                        </div>
                    </div>

                    <!-- Tipo de solicitud -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Tipo de solicitud *</label>
                        <select class="form-select" name="tipo_solicitud" required>
                            <option value="">Seleccione una opción</option>
                            <option value="actualizacion">Actualización de contenido</option>
                            <option value="correccion">Corrección de error</option>
                            <option value="eliminacion">Solicitud de eliminación</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>

                    <!-- Comentario -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Describe el cambio que necesitas *</label>
                        <textarea
                            name="comentario"
                            id="scComentario"
                            class="form-control"
                            rows="3"
                            required
                            placeholder="Ej: Este formato cambió de versión, favor actualizar con la V2..."></textarea>
                    </div>
                    <!-- =====================================================
         CRITERIOS DE REVISIÓN DE CALIDAD DOCUMENTAL
         ===================================================== -->

                    <div class="mb-4">
                        <div class="alert alert-info">
                            <h6 class="mb-2">
                                <i class="bi bi-clipboard-check me-1"></i>
                                CRITERIOS DE REVISIÓN DE CALIDAD DOCUMENTAL
                            </h6>
                            <p class="mb-0 small">
                                Antes de enviar la solicitud, verifique cada uno
                                de los siguientes criterios y seleccione Sí o No.
                            </p>
                        </div>

                        <?php foreach ($grupos_criterios as $grupo): ?>
                            <div class="card border mb-3">
                                <div class="card-header bg-light">
                                    <strong>
                                        <?php echo htmlspecialchars($grupo['titulo']); ?>
                                    </strong>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($grupo['items'] as $key => $pregunta): ?>
                                        <?php $id_criterio = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key); ?>
                                        <div class="criterio-calidad-item border-bottom pb-3 mb-3">
                                            <div class="mb-2">
                                                <strong>
                                                    <?php echo htmlspecialchars($key); ?>
                                                </strong>

                                                <span class="ms-1">
                                                    <?php echo htmlspecialchars($pregunta); ?>
                                                </span>
                                            </div>

                                            <div class="d-flex gap-4">
                                                <!-- SÍ -->
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input criterio-respuesta" name="criterios[<?php echo htmlspecialchars($key); ?>]" value="si" id="criterio_<?php echo $id_criterio; ?>_si" required>

                                                    <label
                                                        class="form-check-label text-success fw-semibold"
                                                        for="criterio_<?php echo $id_criterio; ?>_si">
                                                        <i class="bi bi-check-circle me-1"></i>
                                                        Sí
                                                    </label>
                                                </div>

                                                <!-- NO -->
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input criterio-respuesta"
                                                        name="criterios[<?php echo htmlspecialchars($key); ?>]"
                                                        value="no"
                                                        id="criterio_<?php echo $id_criterio; ?>_no"
                                                        required>

                                                    <label
                                                        class="form-check-label text-danger fw-semibold"
                                                        for="criterio_<?php echo $id_criterio; ?>_no">
                                                        <i class="bi bi-x-circle me-1"></i>
                                                        No
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- =====================================================
     FOOTER FIJO
     ===================================================== -->
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-send me-1"></i>
                        Enviar solicitud
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
