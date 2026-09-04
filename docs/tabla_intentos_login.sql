-- Tabla para controlar intentos fallidos de login y bloqueo temporal (seguridad).
-- Ejecutar una sola vez en la base de datos intranet_osf.

CREATE TABLE IF NOT EXISTS intentos_login (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  intentos_fallidos INT NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL COMMENT 'Si no es NULL, el usuario no puede iniciar sesión hasta esta fecha/hora',
  ultimo_intento DATETIME NULL,
  UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
