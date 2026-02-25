# Intranet OSF - Organización San Francisco

Sistema de intranet corporativa para la gestión de usuarios, comunicación institucional y formación interna.

## Características Principales

- ✅ Sistema de autenticación y gestión de usuarios con roles
- ✅ Dashboard informativo y animado
- ✅ Gestión de dependencias/departamentos
- ✅ Plataforma educativa interna
- ✅ Control de progreso y tiempo de visualización
- ✅ Sistema de evaluaciones con control de intentos
- ✅ Reportes y estadísticas detalladas
- ✅ Diseño responsive
- ✅ Sistema de logs y trazabilidad

## Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Apache con mod_rewrite habilitado
- Extensiones PHP: PDO, PDO_MySQL, GD (opcional para imágenes)

## Instalación

1. **Clonar o copiar los archivos** al directorio de tu servidor web (ej: `htdocs/Intranet-OSF`)

2. **Crear la base de datos:**  
   El esquema y las migraciones de la base de datos se entregan por separado (fuera de este repositorio). Importe el script SQL proporcionado en su MySQL (por ejemplo con `mysql -u root -p < script_bd.sql` o desde phpMyAdmin).

3. **Configurar la conexión:**
   Editar `config/database.php` con tus credenciales de base de datos si es necesario:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'intranet_osf');
   ```

4. **Crear directorio de uploads:**
   ```bash
   mkdir uploads
   mkdir uploads/cursos
   mkdir uploads/materiales
   chmod 755 uploads uploads/cursos uploads/materiales
   ```

5. **Acceder al sistema:**
   - URL: `http://localhost/DesarrollosDuvan/Intranet-OSF/`
   - Usuario por defecto: `admin@osf.com`
   - Contraseña: `admin123`

## Estructura del Proyecto

```
Intranet-OSF/
├── admin/              # Módulos de administración
│   ├── usuarios.php
│   ├── dependencias.php
│   ├── cursos.php
│   ├── cursos_detalle.php
│   ├── evaluaciones.php
│   └── reportes.php
├── api/                # Endpoints API
│   ├── guardar_progreso.php
│   ├── completar_material.php
│   └── guardar_respuesta.php
├── assets/             # Recursos estáticos
│   ├── css/
│   └── js/
├── config/             # Configuración
│   ├── config.php
│   └── database.php
├── cursos/             # Módulo educativo
│   ├── index.php
│   ├── ver_curso.php
│   ├── visualizador_material.php
│   ├── evaluacion.php
│   └── resultado_evaluacion.php
├── includes/           # Componentes reutilizables
│   ├── header.php
│   └── footer.php
├── uploads/            # Archivos subidos (crear manualmente)
├── index.php          # Dashboard
├── login.php
├── logout.php
├── perfil.php
└── .htaccess
```

## Roles del Sistema

### Super Administrador
- Gestión completa de usuarios
- Gestión de dependencias
- Creación y administración de cursos
- Gestión de evaluaciones
- Acceso a reportes y estadísticas

### Usuario (Colaborador)
- Visualización del dashboard
- Inscripción a cursos
- Visualización de contenido educativo
- Presentación de evaluaciones
- Visualización de su perfil

## Funcionalidades

### Dashboard
- Comunicados importantes con carrusel
- Colaborador del mes
- Eventos institucionales
- Noticias internas
- Estadísticas rápidas

### Módulo Educativo
- Cursos por dependencia
- Materiales: videos, PDFs, imágenes
- Control de tiempo mínimo de visualización
- Seguimiento de progreso
- Bloqueo de descarga de contenido

### Evaluaciones
- Cuestionarios de opción múltiple
- Control de intentos
- Puntaje mínimo configurable
- Bloqueo de contenido durante intento activo
- Historial de resultados

### Reportes
- Estadísticas generales
- Reportes por curso
- Reportes por dependencia
- Gráficos de cursos populares
- Detalle de usuarios y resultados

## Seguridad

- Autenticación obligatoria
- Control de roles y permisos
- Protección de contenido educativo
- Bloqueo de descarga de archivos
- Registro de actividad (logs)
- Sanitización de entradas
- Prepared statements (SQL injection prevention)

## Diseño Responsive

El sistema está diseñado para funcionar correctamente en:
- Computadores de escritorio
- Portátiles
- Tablets
- Dispositivos móviles (smartphones)

## Tecnologías Utilizadas

- **Backend:** PHP 7.4+
- **Base de Datos:** MySQL
- **Frontend:** HTML5, CSS3, JavaScript
- **Framework CSS:** Bootstrap 5.3
- **Iconos:** Bootstrap Icons
- **Animaciones:** Animate.css
- **Gráficos:** Chart.js

## Notas Importantes

1. **Contraseña por defecto:** Cambiar la contraseña del usuario admin después de la primera sesión
2. **Permisos de archivos:** Asegurar que el directorio `uploads/` tenga permisos de escritura
3. **Tamaño de archivos:** Configurar `upload_max_filesize` y `post_max_size` en PHP según necesidades
4. **Base de datos:** Realizar respaldos periódicos de la base de datos

## Soporte

Para más información o soporte, contactar al equipo de desarrollo.

---

© 2024 Organización San Francisco - Intranet Corporativa
