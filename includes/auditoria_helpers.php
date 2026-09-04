<?php
/**
 * Registra que un usuario vio una pieza de contenido (comunicado, documento, curso,
 * sst, experiencia o formación). Nunca lanza excepción hacia afuera: si falla el
 * registro, no debe romper la página que el usuario está viendo.
 */
function registrarVista(PDO $pdo, int $usuario_id, string $tipo_contenido, int $contenido_id): void
{
    $tipos_validos = ['comunicado', 'documento_interes', 'curso', 'sst', 'experiencia', 'formacion'];
    if (!in_array($tipo_contenido, $tipos_validos, true) || !$contenido_id || !$usuario_id) {
        return;
    }
    try {
        $pdo->prepare("INSERT INTO vistas_contenido (usuario_id, tipo_contenido, contenido_id) VALUES (?, ?, ?)")
            ->execute([$usuario_id, $tipo_contenido, $contenido_id]);
    } catch (Exception $e) {
        error_log('No se pudo registrar vista de contenido: ' . $e->getMessage());
    }
}

/**
 * Resuelve el nombre/título legible de una pieza de contenido a partir de su tipo e id,
 * para mostrarlo en los reportes. $tipo se valida contra una lista fija, nunca se usa
 * texto libre del usuario para construir la consulta.
 */
function obtenerNombreContenido(PDO $pdo, string $tipo, int $id): string
{
    $mapa = [
        'comunicado' => ['comunicados', 'titulo'],
        'datos_interes' => ['datos_interes', 'nombre'],
        'curso' => ['cursos', 'nombre'],
        'sst' => ['sst_documentos', 'nombre'],
        'experiencia' => ['experiencia_contenidos', 'titulo'],
        'formacion' => ['formacion_cursos', 'nombre'],
    ];
    if (!isset($mapa[$tipo])) return '(tipo desconocido)';

    [$tabla, $campo] = $mapa[$tipo];
    $stmt = $pdo->prepare("SELECT `$campo` FROM `$tabla` WHERE id = ?");
    $stmt->execute([$id]);
    $nombre = $stmt->fetchColumn();
    return $nombre !== false ? $nombre : '(contenido eliminado)';
}

function etiquetaTipoContenido(string $tipo): string
{
    $etiquetas = [
        'comunicado' => 'Comunicado',
        'datos_interes' => 'Documento de interés',
        'curso' => 'Curso',
        'sst' => 'SST',
        'experiencia' => 'Experiencia',
        'formacion' => 'Formación',
    ];
    return $etiquetas[$tipo] ?? $tipo;
}
