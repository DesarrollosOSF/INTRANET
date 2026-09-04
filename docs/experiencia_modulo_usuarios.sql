-- Control de acceso por usuario individual a módulos de Experiencia.
-- Si un módulo NO tiene filas aquí, es visible para todos los usuarios autenticados.
-- Si tiene usuarios asignados, solo esos usuarios podrán ver el módulo y sus archivos.

CREATE TABLE IF NOT EXISTS experiencia_modulo_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_id INT NOT NULL,
    usuario_id INT NOT NULL,
    UNIQUE KEY uk_experiencia_modulo_usuario (modulo_id, usuario_id),
    CONSTRAINT fk_experiencia_modulo_usuario_modulo
        FOREIGN KEY (modulo_id) REFERENCES experiencia_modulos(id) ON DELETE CASCADE,
    CONSTRAINT fk_experiencia_modulo_usuario_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
