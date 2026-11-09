-- =====================================================================
-- Agrega 'Jornada' a eventos.tipo_evento_presencial
-- Fecha: 2026-11-09
-- Rama: feature/tipo-evento-jornada-presencial
-- =====================================================================
--
-- MOTIVO
-- `tipo_evento_presencial` es un ENUM, no un VARCHAR. Para que el admin
-- pueda guardar el nuevo tipo "Jornada" hay que ampliar la lista de valores.
-- Sin este ALTER, guardar una Jornada falla (sql_mode incluye
-- STRICT_TRANS_TABLES, así que el valor inválido da error, no queda vacío).
--
-- ---------------------------------------------------------------------
-- AVISO IMPORTANTE — NO COPIAR EL ENUM DE LA BD LOCAL
-- ---------------------------------------------------------------------
-- La BD LOCAL tiene un valor extra, 'Diplomado', que proviene de la rama
-- NO mergeada `feature/diplomado-curso-especial` y que NO existe en
-- producción. Ese valor NO debe llegar a producción por esta vía.
--
-- ENUM local  (2026-11-09): enum('Congreso','Seminario','Diplomado')
-- ENUM producción esperado: enum('Congreso','Seminario')
--
-- PASO 0 OBLIGATORIO — verificar el ENUM REAL de producción antes del ALTER:
--
--   SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--   FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME   = 'eventos'
--     AND COLUMN_NAME  = 'tipo_evento_presencial';
--
-- Si el resultado NO es exactamente enum('Congreso','Seminario'):
--   DETENERSE. Reconstruir el ALTER conservando TODOS los valores que
--   produccion tenga realmente y agregando unicamente 'Jornada'.
--   Un MODIFY que omita un valor existente lo elimina del ENUM.
--
-- =====================================================================

-- PASO 1 — respaldo de los datos de la columna (por si hiciera falta revertir)
-- (no altera nada; crea una tabla auxiliar con el estado previo)
CREATE TABLE IF NOT EXISTS _bk_eventos_tipo_pres_20261109 AS
SELECT id, modalidad, tipo_evento_presencial
FROM eventos;

-- PASO 2 — ampliar el ENUM (válido solo si el PASO 0 devolvió
--          enum('Congreso','Seminario'))
ALTER TABLE eventos
  MODIFY COLUMN tipo_evento_presencial
  ENUM('Congreso','Seminario','Jornada') NULL DEFAULT NULL;

-- PASO 3 — verificar la definición resultante
SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'eventos'
  AND COLUMN_NAME  = 'tipo_evento_presencial';
-- Esperado: enum('Congreso','Seminario','Jornada') / YES / NULL

-- PASO 4 — verificar que ninguna fila cambió de valor
SELECT tipo_evento_presencial, COUNT(*) AS n
FROM eventos
GROUP BY tipo_evento_presencial
ORDER BY tipo_evento_presencial;

-- PASO 5 — comparar contra el respaldo: debe devolver 0 filas
SELECT e.id, b.tipo_evento_presencial AS antes, e.tipo_evento_presencial AS despues
FROM eventos e
JOIN _bk_eventos_tipo_pres_20261109 b ON b.id = e.id
WHERE NOT (e.tipo_evento_presencial <=> b.tipo_evento_presencial);

-- NOTA: este ALTER no modifica ningún evento. Cambiar los tres eventos
-- "JORNADA DE ACTUALIZACIÓN..." a Jornada se hace desde el ADMIN, no por SQL.
