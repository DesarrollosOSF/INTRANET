-- Módulo SST (misma lógica que Documentos de interés)

CREATE TABLE IF NOT EXISTS sst_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    dependencia_id INT NOT NULL,
    descripcion TEXT,
    imagen VARCHAR(255) DEFAULT NULL,
    usuario_id INT DEFAULT NULL,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sst_documento_dependencia (nombre, dependencia_id),
    CONSTRAINT fk_sst_documento_dependencia
        FOREIGN KEY (dependencia_id) REFERENCES dependencias(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modulos_sst (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sst_documento_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT,
    orden INT NOT NULL DEFAULT 0,
    solo_visualizacion TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=descarga, 1=solo visualizar',
    CONSTRAINT fk_modulo_sst_documento
        FOREIGN KEY (sst_documento_id) REFERENCES sst_documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archivos_sst (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    archivo VARCHAR(255) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    usuario_id INT DEFAULT NULL,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_archivo_sst_modulo
        FOREIGN KEY (modulo_id) REFERENCES modulos_sst(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archivos_sst_imagenes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archivo_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_sst_imagen_archivo
        FOREIGN KEY (archivo_id) REFERENCES archivos_sst(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permisos (nombre, descripcion)
SELECT 'ver_sst', 'Ver y consultar documentos del módulo SST'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'ver_sst');

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_sst', 'Crear, editar y administrar documentos SST'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE nombre = 'gestionar_sst');
