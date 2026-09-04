-- Control de acceso por temática (sección) del módulo Experiencia.
-- Si una sección NO tiene filas aquí, es visible para todos los usuarios autenticados.
-- Si tiene perfiles asignados, solo usuarios con al menos uno de esos perfiles pueden verla.

CREATE TABLE IF NOT EXISTS experiencia_seccion_perfiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seccion_slug VARCHAR(120) NOT NULL,
    perfil_id INT NOT NULL,
    UNIQUE KEY uk_experiencia_seccion_perfil (seccion_slug, perfil_id),
    CONSTRAINT fk_experiencia_seccion_perfil
        FOREIGN KEY (perfil_id) REFERENCES perfiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
