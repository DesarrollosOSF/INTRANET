-- Control de acceso por usuario individual a documentos (archivos) de Experiencia.
-- El permiso es por combinación módulo + documento, para que el mismo archivo
-- en distintos módulos pueda tener usuarios autorizados diferentes.
-- Sin filas para un par (modulo_id, archivo_id) = visible para todos los que vean el módulo.

CREATE TABLE IF NOT EXISTS experiencia_archivo_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_id INT NOT NULL,
    archivo_id INT NOT NULL,
    usuario_id INT NOT NULL,
    UNIQUE KEY uk_experiencia_modulo_archivo_usuario (modulo_id, archivo_id, usuario_id),
    CONSTRAINT fk_experiencia_archivo_usuario_modulo
        FOREIGN KEY (modulo_id) REFERENCES experiencia_modulos(id) ON DELETE CASCADE,
    CONSTRAINT fk_experiencia_archivo_usuario_archivo
        FOREIGN KEY (archivo_id) REFERENCES experiencia_archivos(id) ON DELETE CASCADE,
    CONSTRAINT fk_experiencia_archivo_usuario_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
