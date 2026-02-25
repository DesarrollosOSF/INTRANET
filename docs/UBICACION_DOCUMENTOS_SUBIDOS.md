# Dónde se guardan y administran los documentos subidos

## Ubicación en el servidor

Todos los archivos que se suben en la intranet (imágenes, PDFs, videos) se guardan **en el disco**, dentro del proyecto, en la carpeta:

```
Intranet-OSF/uploads/
```

Ruta absoluta típica en tu equipo (XAMPP):

- **Windows:** `C:\xampp\htdocs\DesarrollosDuvan\Intranet-OSF\uploads\`
- La configuración usa `BASE_PATH . 'uploads/'`, definida en `config/config.php` (constante `UPLOAD_PATH`).

## Estructura de carpetas dentro de `uploads/`

| Carpeta           | Uso                                                                 | Dónde se sube desde                          |
|-------------------|---------------------------------------------------------------------|---------------------------------------------|
| `uploads/cursos/` | Imágenes de portada de los cursos                                   | Admin → Gestión de Cursos (imagen del curso) |
| `uploads/comunicados/` | Imágenes o PDFs adjuntos a comunicados/novedades              | Admin → Gestión de Comunicados              |
| `uploads/materiales/` | Videos, PDFs e imágenes de los materiales de cada curso       | Admin → Gestión de Cursos → detalle del curso (materiales) |

Cada archivo se guarda con un nombre único (p. ej. `uniqid()` + extensión) para evitar colisiones.  
En la **base de datos** no se guarda el archivo en sí, solo la **ruta relativa** (ej. `cursos/abc123.jpg`, `materiales/def456.pdf`).

## Cómo se sirven (URL)

La URL pública para acceder a un archivo subido es:

```
[tu dominio o localhost]/DesarrollosDuvan/Intranet-OSF/uploads/[carpeta]/[nombre_archivo]
```

Ejemplo:

- Ruta en disco: `uploads/comunicados/abc123.pdf`
- URL: `http://localhost/DesarrollosDuvan/Intranet-OSF/uploads/comunicados/abc123.pdf`

En el código se usa la constante `UPLOAD_URL` (definida en `config/config.php`) para construir estas URLs.

## Resumen de administración

- **Dónde se administran:**  
  - Los archivos se **crean** al subirlos desde:
    - Gestión de Cursos (imagen del curso y materiales),
    - Gestión de Comunicados (imagen/PDF del comunicado).
  - No hay en la intranet una pantalla tipo “gestor de archivos” que liste todos los documentos; cada uno está ligado a su curso o comunicado en la base de datos.

- **Dónde se guardan:**  
  - Siempre en la carpeta `uploads/` del proyecto, en las subcarpetas `cursos/`, `comunicados/` y `materiales/` según el tipo de contenido.

- **Backups:**  
  - Para respaldar los documentos, hay que copiar la carpeta `uploads/` completa (y la base de datos, donde están las rutas).

- **Cambiar la ubicación:**  
  - Se puede cambiar definiendo otra ruta en `config/config.php` para `UPLOAD_PATH` y `UPLOAD_URL` y moviendo el contenido de `uploads/` a la nueva ruta; el resto del código ya usa esas constantes.

---

## Despliegue en el servidor de la empresa

- **Carpetas por defecto:** La configuración es la misma en local y en servidor. `UPLOAD_PATH` y `UPLOAD_URL` se calculan a partir de `BASE_PATH` y `BASE_URL`, así que las carpetas `uploads/cursos/`, `uploads/comunicados/` y `uploads/materiales/` funcionan igual al subir el proyecto al servidor. Solo hay que ajustar `BASE_URL` en `config/config.php` si la URL de la intranet en el servidor es distinta (por ejemplo `https://intranet.empresa.com/`).

- **Archivos de prueba:** Antes de subir el proyecto al servidor conviene eliminar los archivos de prueba de `uploads/`:
  - **Opción 1 (recomendada):** En la intranet, como Super Administrador, ir a **Administración → Vaciar archivos de prueba**. Eso borra solo los archivos dentro de `cursos/`, `comunicados/` y `materiales/` (mantiene la estructura y el `.htaccess`).
  - **Opción 2:** Si usas Git, el contenido de `uploads/` está en `.gitignore`; al hacer deploy el servidor tendrá las carpetas vacías (solo `.gitkeep` y `.htaccess`). Los archivos de prueba se quedan solo en tu máquina.
  - **Opción 3:** Si subes el proyecto por FTP o copiando, borra manualmente el contenido de `uploads/cursos/`, `uploads/comunicados/` y `uploads/materiales/` antes o después de subir (no borres `.htaccess` ni `.gitkeep`).
