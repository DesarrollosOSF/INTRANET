-- OBSOLETO: use docs/experiencia_modulo_usuarios.sql (control por usuario, no por perfil).
-- Control de acceso por módulo individual dentro de Experiencia.
-- Si un módulo NO tiene filas aquí, es visible para todos los usuarios autenticados.
-- Si tiene perfiles asignados, solo usuarios con al menos uno de esos perfiles lo verán.

CREATE TABLE IF NOT EXISTS experiencia_modulo_perfiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_id INT NOT NULL,
    perfil_id INT NOT NULL,
    UNIQUE KEY uk_experiencia_modulo_perfil (modulo_id, perfil_id),
    CONSTRAINT fk_experiencia_modulo_perfil_modulo
        FOREIGN KEY (modulo_id) REFERENCES experiencia_modulos(id) ON DELETE CASCADE,
    CONSTRAINT fk_experiencia_modulo_perfil_perfil
        FOREIGN KEY (perfil_id) REFERENCES perfiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
