-- Galería de imágenes para un archivo SST (varias fotos en un mismo ítem).
-- Ejecutar si ya aplicó docs/sst_contenido.sql antes de este cambio.

CREATE TABLE IF NOT EXISTS archivos_sst_imagenes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archivo_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_sst_imagen_archivo
        FOREIGN KEY (archivo_id) REFERENCES archivos_sst(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
