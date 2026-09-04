-- Agregar el permiso "gestionar_comunicados" si no existe (para que aparezca en Perfiles y permisos
-- y se pueda asignar al perfil Administrador y así ver la opción "Gestión de Comunicados" en el menú).

-- Insertar el permiso (ajustar columnas si tu tabla permisos tiene otras; normalmente: id, nombre, descripcion)
INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_comunicados', 'Crear, editar y publicar comunicados y novedades'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_comunicados');

-- Asignar el permiso al perfil Administrador (ID 3). Si tu perfil tiene otro ID, cambia el 3.
INSERT INTO perfil_permisos (perfil_id, permiso_id)
SELECT 3, p.id
FROM permisos p
WHERE p.nombre = 'gestionar_comunicados'
  AND NOT EXISTS (
    SELECT 1 FROM perfil_permisos pp
    WHERE pp.perfil_id = 3 AND pp.permiso_id = p.id
  )
LIMIT 1;
