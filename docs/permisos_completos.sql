-- Poblar la tabla permisos con todos los permisos que usa la aplicación.
-- Ejecutar en producción (phpMyAdmin o cliente MySQL) si la página
-- "Perfiles y permisos" aparece sin lista de permisos.
-- Los nombres deben coincidir con los usados en el código (slug) o con las variantes en español.

-- Evitar duplicados: solo inserta si no existe ya un permiso con ese nombre.
-- En servidores Linux, el nombre de la tabla puede ser sensible a mayúsculas (permisos vs Permisos).

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_dashboard', 'Ver el dashboard principal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_dashboard');

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_cursos', 'Ver y acceder a cursos'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_cursos');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_cursos', 'Crear, editar y administrar cursos'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_cursos');

INSERT INTO permisos (nombre, descripcion)
SELECT 'presentar_evaluaciones', 'Presentar evaluaciones de cursos'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'presentar_evaluaciones');

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_datos_interes', 'Ver datos de interés'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_datos_interes');

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_documentos_interes', 'Ver y descargar documentos de interés (solo consulta)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_documentos_interes');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_documentos_interes', 'Crear, editar y administrar documentos de interés'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_documentos_interes');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_comunicados', 'Crear, editar y publicar comunicados y novedades'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_comunicados');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_dependencias', 'Gestionar dependencias o departamentos'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_dependencias');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_usuarios', 'Crear, editar y desactivar usuarios'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_usuarios');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_perfiles_permisos', 'Asignar permisos a cada perfil (Super Administrador)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_perfiles_permisos');

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_reportes', 'Visualizar reportes y estadísticas'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_reportes');

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_documentos', 'Ver y acceder a documentos'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_documentos');

INSERT INTO permisos (nombre, descripcion)
SELECT 'inscribirse_cursos', 'Inscribirse a cursos disponibles'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'inscribirse_cursos');
