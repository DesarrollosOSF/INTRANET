(function() {
    'use strict';

    var activeFloatingTooltip = null;
    var activeTooltipLink = null;
    var officeExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    var videoExts = ['mp4', 'webm', 'ogg'];
    var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    function removeFloatingTooltip() {
        if (activeFloatingTooltip && activeFloatingTooltip.parentNode) {
            activeFloatingTooltip.parentNode.removeChild(activeFloatingTooltip);
        }
        activeFloatingTooltip = null;
        activeTooltipLink = null;
    }

    function positionFloatingTooltip(link, tip) {
        var rect = link.getBoundingClientRect();
        var gap = 12;
        var left = rect.right + gap;
        var top = rect.top + (rect.height / 2) - (tip.offsetHeight / 2);

        if (top < 8) top = 8;
        if (left + tip.offsetWidth > window.innerWidth - 8) {
            left = rect.left - tip.offsetWidth - gap;
        }
        if (top + tip.offsetHeight > window.innerHeight - 8) {
            top = window.innerHeight - tip.offsetHeight - 8;
        }

        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }

    function showFloatingTooltip(link) {
        var text = link.getAttribute('data-tooltip');
        if (!text) return;

        removeFloatingTooltip();

        var tip = document.createElement('div');
        tip.className = 'experiencia-floating-tooltip';
        tip.setAttribute('role', 'tooltip');
        tip.textContent = text;
        document.body.appendChild(tip);
        positionFloatingTooltip(link, tip);

        activeFloatingTooltip = tip;
        activeTooltipLink = link;
    }

    function hideAllExperienciaTooltips() {
        removeFloatingTooltip();
        document.querySelectorAll('.experiencia-floating-tooltip, .tooltip.experiencia-sidebar-tooltip, .tooltip').forEach(function(el) {
            if (el.parentNode) el.parentNode.removeChild(el);
        });
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                var inst = bootstrap.Tooltip.getInstance(el);
                if (inst) inst.dispose();
            });
        }
    }

    function initExperienciaTooltips() {
        hideAllExperienciaTooltips();

        document.querySelectorAll('.experiencia-sidebar-link[data-tooltip]').forEach(function(link) {
            if (link._experienciaTooltipBound) return;
            link._experienciaTooltipBound = true;

            link.addEventListener('mouseenter', function() {
                showFloatingTooltip(link);
            });

            link.addEventListener('mouseleave', function() {
                if (activeTooltipLink === link) removeFloatingTooltip();
            });

            link.addEventListener('mousedown', removeFloatingTooltip);
            link.addEventListener('click', removeFloatingTooltip);
        });
    }

    function escHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/\n/g, '<br>');
    }

    function escAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    function resolveViewerUrl(pathOrUrl) {
        if (!pathOrUrl) return '';
        if (/^https?:\/\//i.test(pathOrUrl)) return pathOrUrl;
        if (pathOrUrl.charAt(0) === '/') return window.location.origin + pathOrUrl;
        var base = window.location.origin + window.location.pathname.replace(/[^/]+$/, '');
        return base + pathOrUrl.replace(/^\//, '');
    }

    function showViewerLoading(body, message) {
        body.innerHTML = '<div class="experiencia-viewer-loading"><div class="spinner-border text-light" role="status"></div><p class="mt-3 mb-0">' + escHtml(message || 'Cargando documento…') + '</p></div>';
    }

    function showViewerError(body, message) {
        body.innerHTML = '<div class="experiencia-viewer-error"><i class="bi bi-exclamation-circle display-4"></i><p class="mt-3 mb-0">' + escHtml(message) + '</p></div>';
    }

    function officeEmbedUrl(absoluteUrl) {
        return 'https://view.officeapps.live.com/op/embed.aspx?src=' + encodeURIComponent(absoluteUrl);
    }

    function renderPdf(body, viewUrl, nombre) {
        body.innerHTML = '<iframe src="' + escAttr(viewUrl) + '#toolbar=0&navpanes=0" class="comunicado-contenido-full experiencia-viewer-iframe" title="' + escAttr(nombre) + '"></iframe>';
    }

    function renderVideo(body, viewUrl, ext) {
        var mime = ext === 'webm' ? 'video/webm' : (ext === 'ogg' ? 'video/ogg' : 'video/mp4');
        body.innerHTML = '<video class="experiencia-viewer-video comunicado-contenido-full" controls controlsList="nodownload noplaybackrate" disablePictureInPicture oncontextmenu="return false;" playsinline><source src="' + escAttr(viewUrl) + '" type="' + mime + '">Su navegador no soporta la reproducción de video.</video>';
    }

    function renderImage(body, viewUrl, nombre) {
        body.innerHTML = '<img src="' + escAttr(viewUrl) + '" alt="' + escAttr(nombre) + '" class="comunicado-contenido-full comunicado-imagen-full experiencia-viewer-image" oncontextmenu="return false;">';
    }

    function renderOfficeEmbed(body, officeUrl) {
        body.innerHTML = '<iframe src="' + escAttr(officeEmbedUrl(officeUrl)) + '" class="comunicado-contenido-full experiencia-viewer-iframe" title="Visor Office"></iframe>';
    }

    function fetchViewerBlob(viewUrl) {
        return fetch(viewUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function(res) {
            if (!res.ok) throw new Error('No se pudo cargar el archivo.');
            return res.blob();
        });
    }

    function renderDocx(body, viewUrl, officeUrl) {
        showViewerLoading(body, 'Cargando documento Word…');
        if (typeof docx === 'undefined') {
            if (officeUrl) {
                renderOfficeEmbed(body, officeUrl);
            } else {
                showViewerError(body, 'No se pudo cargar el visor de Word.');
            }
            return;
        }
        fetchViewerBlob(viewUrl).then(function(blob) {
            var container = document.createElement('div');
            container.className = 'experiencia-docx-viewer';
            body.innerHTML = '';
            body.appendChild(container);
            return docx.renderAsync(blob, container, null, {
                className: 'docx-preview-content',
                inWrapper: true,
                ignoreWidth: false,
                ignoreHeight: false,
                breakPages: true
            });
        }).catch(function() {
            if (officeUrl) {
                renderOfficeEmbed(body, officeUrl);
            } else {
                showViewerError(body, 'No se pudo mostrar el documento Word.');
            }
        });
    }

    function renderXlsx(body, viewUrl, officeUrl) {
        showViewerLoading(body, 'Cargando hoja de cálculo…');
        if (typeof XLSX === 'undefined') {
            showViewerError(body, 'No se pudo cargar el visor de Excel.');
            return;
        }
        fetch(viewUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function(res) {
                if (!res.ok) throw new Error('fetch failed');
                return res.arrayBuffer();
            })
            .then(function(data) {
                var workbook = XLSX.read(data, { type: 'array' });
                var sheetName = workbook.SheetNames[0];
                var html = XLSX.utils.sheet_to_html(workbook.Sheets[sheetName], { editable: false });
                body.innerHTML = '<div class="experiencia-xlsx-viewer">' + html + '</div>';
            })
            .catch(function() {
                if (officeUrl) {
                    renderOfficeEmbed(body, officeUrl);
                } else {
                    showViewerError(body, 'No se pudo mostrar la hoja de cálculo.');
                }
            });
    }

    function renderByExtension(body, ext, viewUrl, officeUrl, nombre) {
        ext = (ext || '').toLowerCase();

        if (ext === 'pdf') {
            renderPdf(body, viewUrl, nombre);
            return;
        }
        if (videoExts.indexOf(ext) !== -1) {
            renderVideo(body, viewUrl, ext);
            return;
        }
        if (imageExts.indexOf(ext) !== -1) {
            renderImage(body, viewUrl, nombre);
            return;
        }
        if (ext === 'docx') {
            renderDocx(body, viewUrl, officeUrl);
            return;
        }
        if (ext === 'xlsx') {
            renderXlsx(body, viewUrl, officeUrl);
            return;
        }
        if (officeExts.indexOf(ext) !== -1) {
            showViewerLoading(body, 'Abriendo visor de Office…');
            renderOfficeEmbed(body, officeUrl || viewUrl);
            return;
        }

        showViewerError(body, 'Formato no soportado para visualización protegida.');
    }

    function initDocumentoViewer() {
        var overlay = document.getElementById('docFullscreen');
        if (!overlay) return;

        var closeBtn = document.getElementById('docFullscreenClose');
        var backdrop = overlay.querySelector('.comunicado-fullscreen-backdrop');
        var lastFocusedTrigger = null;

        function openDocViewer(trigger) {
            lastFocusedTrigger = trigger;
            var nombre = trigger.getAttribute('data-nombre') || '';
            var descripcion = trigger.getAttribute('data-descripcion') || '';
            var viewUrl = resolveViewerUrl(trigger.getAttribute('data-viewer-url') || trigger.getAttribute('data-url') || '');
            var officeUrl = trigger.getAttribute('data-office-url') || viewUrl;
            var ext = (trigger.getAttribute('data-ext') || '').toLowerCase();

            document.getElementById('docFullscreenTitulo').textContent = nombre;
            document.getElementById('docFullscreenDescripcion').innerHTML = descripcion
                ? escHtml(descripcion)
                : '<span class="text-muted">Documento de consulta — solo visualización</span>';

            var body = document.getElementById('docFullscreenBody');
            var footer = document.getElementById('docFullscreenFooter');
            body.innerHTML = '';
            footer.innerHTML = '';
            body.className = 'comunicado-fullscreen-body experiencia-viewer-body';

            renderByExtension(body, ext, viewUrl, officeUrl, nombre);

            overlay.removeAttribute('inert');
            overlay.setAttribute('aria-hidden', 'false');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(function() { if (closeBtn) closeBtn.focus(); }, 50);
        }

        function closeDocViewer() {
            var body = document.getElementById('docFullscreenBody');
            var video = body ? body.querySelector('video') : null;
            if (video) {
                video.pause();
                video.removeAttribute('src');
                video.load();
            }
            if (lastFocusedTrigger && lastFocusedTrigger.offsetParent !== null) {
                lastFocusedTrigger.focus();
            }
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('inert', '');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.doc-viewer-trigger').forEach(function(trigger) {
            if (trigger._docViewerBound) return;
            trigger._docViewerBound = true;
            trigger.addEventListener('click', function() { openDocViewer(trigger); });
        });

        if (closeBtn) closeBtn.addEventListener('click', closeDocViewer);
        if (backdrop) backdrop.addEventListener('click', closeDocViewer);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeDocViewer();
            if (overlay.classList.contains('active') && (e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'p')) {
                e.preventDefault();
            }
        });

        overlay.addEventListener('contextmenu', function(e) {
            if (overlay.classList.contains('active')) {
                e.preventDefault();
            }
        });
    }

    function initModuloAcordeon() {
        document.querySelectorAll('.experiencia-modulo-toggle').forEach(function(toggle) {
            if (toggle._moduloAcordeonBound) return;
            toggle._moduloAcordeonBound = true;

            toggle.addEventListener('click', function() {
                var card = toggle.closest('.experiencia-modulo-card');
                var panel = card ? card.querySelector('.experiencia-modulo-panel') : null;
                if (!panel) return;

                var isOpen = panel.classList.contains('show');
                if (isOpen) {
                    panel.classList.remove('show');
                    panel.classList.add('collapse');
                    toggle.setAttribute('aria-expanded', 'false');
                    if (card) card.classList.remove('is-open');
                } else {
                    panel.classList.add('show');
                    panel.classList.remove('collapse');
                    toggle.setAttribute('aria-expanded', 'true');
                    if (card) card.classList.add('is-open');
                }
            });
        });
    }

    function initExperienciaPage() {
        hideAllExperienciaTooltips();
        initExperienciaTooltips();
        initModuloAcordeon();
        initDocumentoViewer();
        initSolicitudCambio();
    }

    /* ============================================================
     * MODAL "SOLICITAR CAMBIO"
     * ============================================================
     * Usa delegación de eventos sobre document.body, que nunca se
     * destruye entre navegaciones AJAX/SPA. Se registra UNA sola vez
     * (guard con document._experienciaSolicitudBound), sin necesidad
     * de volver a llamarse en cada init de página.
     */
    function initSolicitudCambio() {
        if (document._experienciaSolicitudBound) return;
        document._experienciaSolicitudBound = true;

        document.body.addEventListener('click', function(event) {
            var btn = event.target.closest('.btn-solicitar-cambio');
            if (!btn) return;

            event.preventDefault();
            event.stopPropagation();

            var modalElement = document.getElementById('modalSolicitudCambio');
            if (!modalElement) {
                alert('No se pudo abrir el formulario de solicitud.');
                return;
            }

            var archivoId = btn.getAttribute('data-archivo-id');
            var moduloId = btn.getAttribute('data-modulo-id');
            var nombre = btn.getAttribute('data-nombre');

            var archivoInput = document.getElementById('scArchivoId');
            var moduloInput = document.getElementById('scModuloId');
            var documentoNombre = document.getElementById('scDocumentoNombre');

            if (archivoInput) archivoInput.value = archivoId || '';
            if (moduloInput) moduloInput.value = moduloId || '';
            if (documentoNombre) documentoNombre.textContent = nombre || 'Documento seleccionado';

            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                alert('No se pudo abrir el formulario porque Bootstrap no está cargado.');
                return;
            }

            var modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
            modalInstance.show();
        });

        document.body.addEventListener('hide.bs.modal', function(event) {
            var modalElement = event.target;
            if (document.activeElement && modalElement.contains(document.activeElement)) {
                document.activeElement.blur();
            }
        });

        document.body.addEventListener('hidden.bs.modal', function() {
            document.querySelectorAll('.modal-backdrop').forEach(function(bd) {
                bd.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        });

        document.body.addEventListener('submit', function(event) {
            var formulario = event.target.closest('#formSolicitudCambio');
            if (!formulario) return;

            var criterios = formulario.querySelectorAll('.criterio-calidad-item');
            var todosRespondidos = true;

            criterios.forEach(function(criterio) {
                var seleccionado = criterio.querySelector('input[type="radio"]:checked');
                if (!seleccionado) {
                    todosRespondidos = false;
                    criterio.classList.add('border', 'border-danger', 'rounded', 'p-2');
                } else {
                    criterio.classList.remove('border-danger');
                }
            });

            if (!todosRespondidos) {
                event.preventDefault();
                alert('Debe responder Sí o No en todos los criterios de revisión de calidad documental.');
                return false;
            }
        });
    }

    window.hideAllExperienciaTooltips = hideAllExperienciaTooltips;
    window.initExperienciaPage = initExperienciaPage;

    if (!document._experienciaTooltipGlobalBound) {
        document._experienciaTooltipGlobalBound = true;

        document.addEventListener('click', function(e) {
            if (e.target.closest('.experiencia-sidebar-link')) {
                hideAllExperienciaTooltips();
            }
        }, true);

        document.addEventListener('scroll', hideAllExperienciaTooltips, true);
        window.addEventListener('resize', hideAllExperienciaTooltips);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initExperienciaPage);
    } else {
        initExperienciaPage();
    }
})();
