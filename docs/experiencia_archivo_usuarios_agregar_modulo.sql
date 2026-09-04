-- Migración: agregar modulo_id a experiencia_archivo_usuarios (instalaciones previas sin ese campo).
-- Ejecutar solo si la tabla ya existe con la estructura antigua (archivo_id + usuario_id).

ALTER TABLE experiencia_archivo_usuarios
    ADD COLUMN modulo_id INT NULL AFTER id;

UPDATE experiencia_archivo_usuarios au
INNER JOIN experiencia_archivos a ON a.id = au.archivo_id
SET au.modulo_id = a.modulo_id
WHERE au.modulo_id IS NULL;

ALTER TABLE experiencia_archivo_usuarios
    MODIFY COLUMN modulo_id INT NOT NULL;

ALTER TABLE experiencia_archivo_usuarios
    DROP INDEX uk_experiencia_archivo_usuario;

ALTER TABLE experiencia_archivo_usuarios
    ADD UNIQUE KEY uk_experiencia_modulo_archivo_usuario (modulo_id, archivo_id, usuario_id);

ALTER TABLE experiencia_archivo_usuarios
    ADD CONSTRAINT fk_experiencia_archivo_usuario_modulo
        FOREIGN KEY (modulo_id) REFERENCES experiencia_modulos(id) ON DELETE CASCADE;
