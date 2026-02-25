// JavaScript principal de la aplicación

$(document).ready(function() {
    // Auto-hide alerts después de 5 segundos (solo alerts del contenido principal, no los que están dentro de modales)
    setTimeout(function() {
        $('.alert').not('.modal .alert').fadeOut('slow', function() {
            $(this).remove();
        });
    }, 5000);
    
    // Confirmación para acciones destructivas
    $('.btn-danger, .delete-btn').on('click', function(e) {
        if (!confirm('¿Está seguro de realizar esta acción?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Tooltips de Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Popovers de Bootstrap
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Validación de formularios
    $('form').on('submit', function(e) {
        var form = $(this)[0];
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        $(this).addClass('was-validated');
    });
});

// Función para mostrar loading
function showLoading() {
    $('body').append('<div class="spinner-overlay"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div></div>');
}

// Función para ocultar loading
function hideLoading() {
    $('.spinner-overlay').remove();
}

// Función para hacer peticiones AJAX
function ajaxRequest(url, method, data, successCallback, errorCallback) {
    showLoading();
    $.ajax({
        url: url,
        method: method,
        data: data,
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (successCallback) successCallback(response);
        },
        error: function(xhr, status, error) {
            hideLoading();
            console.error('Error:', error);
            if (errorCallback) {
                errorCallback(xhr, status, error);
            } else {
                alert('Error al procesar la solicitud. Por favor, intente nuevamente.');
            }
        }
    });
}
