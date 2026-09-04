-- Tablas para contenido de Experiencia (documentos, módulos y archivos por sección)

CREATE TABLE IF NOT EXISTS experiencia_contenidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seccion_slug VARCHAR(120) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_experiencia_seccion_slug (seccion_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS experiencia_modulos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenido_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT,
    orden INT NOT NULL DEFAULT 0,
    solo_visualizacion TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=descarga, 1=solo visualizar',
    CONSTRAINT fk_experiencia_modulo_contenido
        FOREIGN KEY (contenido_id) REFERENCES experiencia_contenidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS experiencia_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    archivo VARCHAR(255) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    usuario_id INT DEFAULT NULL,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_experiencia_archivo_modulo
        FOREIGN KEY (modulo_id) REFERENCES experiencia_modulos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permiso de administración
INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_experiencia', 'Crear, editar y administrar contenido del módulo Experiencia'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_experiencia');
