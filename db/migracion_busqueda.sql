-- SIGAD · migración de búsqueda (CUI + nombre de proyecto + FULLTEXT)
-- Para instalaciones YA existentes. En phpMyAdmin: seleccionar la BD y ejecutar.
-- Las instalaciones nuevas no necesitan este archivo: db/sigad.sql ya lo incluye.
-- La aplicación también aplica estos cambios sola al iniciar sesión (si el usuario
-- de BD tiene permiso ALTER).

ALTER TABLE expedientes
  ADD COLUMN IF NOT EXISTS cui VARCHAR(40) NOT NULL DEFAULT '' AFTER nro_expediente,
  ADD COLUMN IF NOT EXISTS nombre_proyecto VARCHAR(255) NOT NULL DEFAULT '' AFTER asunto;

ALTER TABLE documentos
  ADD COLUMN IF NOT EXISTS texto_origen VARCHAR(10) NOT NULL DEFAULT '' AFTER texto;

-- Índices (ignorar el error "Duplicate key name" si ya existen)
ALTER TABLE expedientes ADD INDEX idx_cui (cui);
ALTER TABLE expedientes ADD FULLTEXT INDEX ft_exp (nro_expediente, cui, nombre_proyecto, asunto, area_origen);
ALTER TABLE documentos  ADD FULLTEXT INDEX ft_texto (texto);
