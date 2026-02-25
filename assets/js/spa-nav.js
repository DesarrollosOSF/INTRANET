/**
 * Navegación sin recarga de pestaña (SPA-like).
 * Intercepta clics en enlaces con clase .spa-nav-link y carga el contenido por fetch,
 * evitando el parpadeo del favicon y la recarga completa de la página.
 */
(function() {
    'use strict';

    var BASE = typeof window.APP_BASE_URL !== 'undefined' ? window.APP_BASE_URL : '';
    var MAIN_ID = 'app-main-content';

    // URLs que siempre deben hacer recarga completa (logout, descargas, etc.)
    function shouldReloadFull(href) {
        if (!href) return true;
        var path = (href.split('?')[0] || '').toLowerCase();
        return path.indexOf('logout') !== -1 || path.indexOf('vaciar_archivos') !== -1;
    }

    function isSameOrigin(href) {
        try {
            var a = document.createElement('a');
            a.href = href;
            return a.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function runScripts(container) {
        var scripts = container.querySelectorAll('script');
        scripts.forEach(function(oldScript) {
            var newScript = document.createElement('script');
            if (oldScript.src) {
                newScript.src = oldScript.src;
            } else {
                newScript.textContent = oldScript.textContent || '';
            }
            if (oldScript.type) newScript.type = oldScript.type;
            oldScript.parentNode.removeChild(oldScript);
            document.body.appendChild(newScript);
        });
    }

    function injectStyles(doc) {
        var head = doc.querySelector('head');
        if (!head) return;
        var existingHrefs = {};
        document.querySelectorAll('head link[rel="stylesheet"]').forEach(function(l) {
            existingHrefs[l.href] = true;
        });
        head.querySelectorAll('link[rel="stylesheet"]').forEach(function(link) {
            var href = link.href;
            if (!href || existingHrefs[href]) return;
            if (href.indexOf(window.location.origin) !== 0) return;
            var existing = document.querySelector('head link[rel="stylesheet"][href="' + href + '"]');
            if (existing) return;
            var clone = link.cloneNode(true);
            clone.setAttribute('data-spa-added', '1');
            document.head.appendChild(clone);
        });
    }

    function removeSpaStyles() {
        document.querySelectorAll('head link[rel="stylesheet"][data-spa-added="1"]').forEach(function(l) {
            l.parentNode.removeChild(l);
        });
    }

    function loadPage(url, pushState) {
        var main = document.getElementById(MAIN_ID);
        if (!main) return;

        var loader = document.createElement('div');
        loader.id = 'spa-nav-loader';
        loader.className = 'spa-nav-loader';
        loader.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div>';
        main.style.minHeight = '120px';
        main.appendChild(loader);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newMain = doc.getElementById(MAIN_ID);
                if (!newMain) {
                    window.location.href = url;
                    return;
                }
                removeSpaStyles();
                injectStyles(doc);
                document.title = doc.querySelector('title') ? doc.querySelector('title').textContent : document.title;
                var content = newMain.innerHTML;
                loader.parentNode.removeChild(loader);
                main.style.minHeight = '';
                main.innerHTML = content;
                runScripts(main);
                if (pushState) {
                    window.history.pushState({ spa: true, url: url }, '', url);
                }
                window.scrollTo(0, 0);
            })
            .catch(function() {
                if (loader.parentNode) loader.parentNode.removeChild(loader);
                main.style.minHeight = '';
                window.location.href = url;
            });
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest('a.spa-nav-link');
        if (!link || link.target === '_blank' || link.getAttribute('data-no-spa')) return;
        var href = link.getAttribute('href');
        if (!href || href === '#' || href.indexOf('javascript:') === 0) return;
        if (shouldReloadFull(href)) return;
        if (!isSameOrigin(href)) return;
        e.preventDefault();
        loadPage(link.href, true);
    }, true);

    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.spa && e.state.url) {
            loadPage(e.state.url, false);
        }
    });

    // Al cargar, guardar estado inicial para que "Atrás" funcione
    if (window.history && window.history.state === null) {
        window.history.replaceState({ spa: true, url: window.location.href }, '', window.location.href);
    }
})();
