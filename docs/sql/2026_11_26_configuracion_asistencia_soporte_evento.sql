-- =====================================================================
-- Configuración por evento: asistencia virtual y mensajes de soporte de pago
-- Fecha: 2026-11-26
-- Rama: feature/configuracion-asistencia-soporte-evento
-- =====================================================================
--
-- QUÉ HACE
-- Agrega dos columnas a `eventos`. Es aditivo: no modifica ni borra datos,
-- no toca `inscritos` y no toca el ENUM `tipo_evento_presencial`.
--
--   permite_asistencia_virtual      TINYINT(1) NOT NULL DEFAULT 1
--     Solo Presencial + Seminario. 1 = el inscrito puede elegir 100% virtual
--     (SEM_100_VIRTUAL). 0 = exclusivamente presencial.
--
--   solicitar_soporte_confirmacion  TINYINT(1) NOT NULL DEFAULT 1
--     Todas las modalidades. 1 = los correos conservan los mensajes de soporte
--     de pago (solicitud al participante y advertencia "no adjuntó soporte" a la
--     comercial). 0 = se omiten esos mensajes. No cambia el campo de carga ni las
--     validaciones del soporte, y un soporte adjunto se sigue enviando.
--
-- Con DEFAULT 1 todos los eventos existentes conservan su comportamiento actual.
--
-- ORDEN OBLIGATORIO EN PRODUCCIÓN
--   1. Respaldo  2. Este SQL  3. Verificación  4. Deploy del código
-- (El código tolera que las columnas falten y usa 1, pero el orden oficial es este.)
--
-- AVISO: producción (MariaDB 10.6) NO usa sql_mode estricto. El código valida 0/1
-- en PHP antes de escribir.
-- =====================================================================

-- PASO 0 — lectura: las columnas NO deben existir (esperado: 0 filas)
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'eventos'
  AND COLUMN_NAME IN ('permite_asistencia_virtual', 'solicitar_soporte_confirmacion');

-- PASO 0b — lectura: confirmar que congreso_virtual existe (referencia del AFTER)
SELECT COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'congreso_virtual';

-- PASO 1 — respaldo (estructura de eventos sin datos personales)
CREATE TABLE IF NOT EXISTS _bk_eventos_config_20261126 AS
SELECT id, modalidad, tipo_evento_presencial, congreso_presencial, congreso_virtual
FROM eventos;

-- PASO 2 — columnas nuevas
ALTER TABLE eventos
  ADD COLUMN permite_asistencia_virtual TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Presencial+Seminario: 1 = ofrece 100% virtual al inscrito'
    AFTER congreso_virtual,
  ADD COLUMN solicitar_soporte_confirmacion TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = correos con mensajes de soporte de pago; 0 = sin ellos'
    AFTER permite_asistencia_virtual;

-- PASO 3 — verificación de definición
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'eventos'
  AND COLUMN_NAME IN ('permite_asistencia_virtual', 'solicitar_soporte_confirmacion');
-- Esperado: tinyint(1) / NO / 1 para ambas

-- PASO 4 — verificación de datos: todas las filas en 1/1 (una sola fila de resultado)
SELECT permite_asistencia_virtual, solicitar_soporte_confirmacion, COUNT(*) AS n
FROM eventos
GROUP BY permite_asistencia_virtual, solicitar_soporte_confirmacion;

-- PASO 5 — ninguna otra columna cambió (esperado: 0 filas)
SELECT e.id
FROM eventos e
JOIN _bk_eventos_config_20261126 b ON b.id = e.id
WHERE NOT (e.modalidad <=> b.modalidad)
   OR NOT (e.tipo_evento_presencial <=> b.tipo_evento_presencial)
   OR NOT (e.congreso_presencial <=> b.congreso_presencial)
   OR NOT (e.congreso_virtual <=> b.congreso_virtual);

-- NOTA: la configuración de los eventos 230 y 231 se hace desde el ADMIN
-- (Editar evento), no con este script.

-- ROLLBACK (solo si se autoriza; el código funciona con o sin las columnas)
-- ALTER TABLE eventos DROP COLUMN solicitar_soporte_confirmacion, DROP COLUMN permite_asistencia_virtual;
