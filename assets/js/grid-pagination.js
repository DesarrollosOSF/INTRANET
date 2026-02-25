/**
 * Paginación reutilizable para grids de bloques (Dashboard comunicados, Cursos).
 * Uso: initGridPagination({ grid: '#comunicadosGrid', paginationWrap: '#comunicadosPagination' });
 * El grid puede tener data-blocks-per-page="6" (por defecto 6).
 */
function initGridPagination(options) {
    var grid = options.grid;
    var paginationWrap = options.paginationWrap;
    if (typeof grid === 'string') grid = document.querySelector(grid);
    if (typeof paginationWrap === 'string') paginationWrap = document.querySelector(paginationWrap);
    if (!grid || !paginationWrap) return;

    var blocks = Array.from(grid.children);
    var total = blocks.length;
    var blocksPerPage = parseInt(options.blocksPerPage || grid.getAttribute('data-blocks-per-page') || '6', 10);
    if (total <= blocksPerPage) return;

    var totalPages = Math.ceil(total / blocksPerPage);
    var currentPage = 1;

    function goToPage(page) {
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        blocks.forEach(function(el, i) {
            var show = i >= (currentPage - 1) * blocksPerPage && i < currentPage * blocksPerPage;
            el.style.display = show ? '' : 'none';
        });
        renderPagination();
    }

    function renderPagination() {
        var html = '<ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">';
        html += '<li class="page-item' + (currentPage === 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="prev" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a></li>';
        for (var p = 1; p <= totalPages; p++) {
            html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
        }
        html += '<li class="page-item' + (currentPage === totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="next" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></a></li>';
        html += '</ul>';
        paginationWrap.innerHTML = html;
        paginationWrap.querySelectorAll('.page-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                if (link.closest('.page-item').classList.contains('disabled')) return;
                var p = link.getAttribute('data-page');
                if (p === 'prev') goToPage(currentPage - 1);
                else if (p === 'next') goToPage(currentPage + 1);
                else goToPage(parseInt(p, 10));
            });
        });
    }

    goToPage(1);
}
