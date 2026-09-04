-- Alinea seccion_slug con los títulos del menú de Experiencia.
-- Ejecutar una sola vez en HeidiSQL después de actualizar el código.
-- Orden importante: evita choques de UNIQUE en seccion_slug.

-- 1) Renombrar primero el slug que se reutiliza
UPDATE experiencia_contenidos
SET seccion_slug = 'centro-documental-del-sgc',
    titulo = 'Centro Documental del SGC'
WHERE seccion_slug = 'todos-somos-parte-del-cambio';

UPDATE experiencia_seccion_perfiles
SET seccion_slug = 'centro-documental-del-sgc'
WHERE seccion_slug = 'todos-somos-parte-del-cambio';

-- 2) Resto de renombres
UPDATE experiencia_contenidos SET seccion_slug = 'conoce-experiencia-san-francisco', titulo = 'Conoce Experiencia San Francisco' WHERE seccion_slug = 'somos-experiencia';
UPDATE experiencia_contenidos SET seccion_slug = 'asi-nos-comunicamos-mejor', titulo = 'Así nos comunicamos mejor' WHERE seccion_slug = 'herramientas-para-la-atencion';
UPDATE experiencia_contenidos SET seccion_slug = 'escuchamos-para-mejorar', titulo = 'Escuchamos para Mejorar' WHERE seccion_slug = 'medimos-para-mejorar';
UPDATE experiencia_contenidos SET seccion_slug = 'nuestra-dupla-medimos-y-mejoramos', titulo = 'Nuestra dupla: Medimos y Mejoramos' WHERE seccion_slug = 'tips-y-recursos';
UPDATE experiencia_contenidos SET seccion_slug = 'escuela-de-experiencia-tips-y-recursos', titulo = 'Escuela de Experiencia, tips y recursos' WHERE seccion_slug = 'de-las-ideas-a-la-accion';
UPDATE experiencia_contenidos SET seccion_slug = 'todos-somos-parte-del-cambio', titulo = 'Todos Somos Parte del Cambio' WHERE seccion_slug = 'historias-mercen-ser-contadas';

UPDATE experiencia_seccion_perfiles SET seccion_slug = 'conoce-experiencia-san-francisco' WHERE seccion_slug = 'somos-experiencia';
UPDATE experiencia_seccion_perfiles SET seccion_slug = 'asi-nos-comunicamos-mejor' WHERE seccion_slug = 'herramientas-para-la-atencion';
UPDATE experiencia_seccion_perfiles SET seccion_slug = 'escuchamos-para-mejorar' WHERE seccion_slug = 'medimos-para-mejorar';
UPDATE experiencia_seccion_perfiles SET seccion_slug = 'nuestra-dupla-medimos-y-mejoramos' WHERE seccion_slug = 'tips-y-recursos';
UPDATE experiencia_seccion_perfiles SET seccion_slug = 'escuela-de-experiencia-tips-y-recursos' WHERE seccion_slug = 'de-las-ideas-a-la-accion';
UPDATE experiencia_seccion_perfiles SET seccion_slug = 'todos-somos-parte-del-cambio' WHERE seccion_slug = 'historias-mercen-ser-contadas';
