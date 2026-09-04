-- Asignar perfil a usuarios que no tienen fila en usuario_perfiles.
-- Así los permisos del perfil (Perfiles y permisos) aplican correctamente.
-- Perfil 1 = Super Admin, 2 = Colaborador/Usuario, 3 = Administrador (según roles.id).

INSERT INTO usuario_perfiles (usuario_id, perfil_id)
SELECT u.id, u.rol_id
FROM usuarios u
WHERE NOT EXISTS (
    SELECT 1 FROM usuario_perfiles up WHERE up.usuario_id = u.id
);
