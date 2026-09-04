-- Agrega modo de acceso por módulo en documentos de interés.
-- 0 = los archivos se pueden descargar (comportamiento por defecto).
-- 1 = solo visualización en pantalla completa (sin descarga directa).

ALTER TABLE modulos_documento_interes
ADD COLUMN solo_visualizacion TINYINT(1) NOT NULL DEFAULT 0
COMMENT '0=descarga, 1=solo visualizar'
AFTER orden;
