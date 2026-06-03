-- ============================================================
-- Migración: soporte para Congreso Presencial y/o Virtual en Curso Especial
-- Proyecto: inscripcion
-- Fecha: 2026-06-03
-- Rama: feature/congreso-virtual-curso-especial
-- ------------------------------------------------------------
--
-- Objetivo:
--   1. Dos banderas independientes en eventos para Curso Especial:
--      - congreso_presencial: 1 = el evento ofrece congreso presencial
--      - congreso_virtual:    1 = el evento ofrece congreso virtual
--      Ambas pueden estar activas al mismo tiempo.
--   2. Ampliar inscritos.asistencia_tipo para nuevos códigos seguros.
--
-- Combinaciones válidas para Curso Especial:
--   congreso_presencial=1, congreso_virtual=0 → solo congreso presencial
--   congreso_presencial=0, congreso_virtual=1 → solo congreso virtual
--   congreso_presencial=1, congreso_virtual=1 → ambos congresos disponibles
--
-- Nuevos valores de asistencia_tipo:
--   CONG_VIRTUAL    (12 chars) - Congreso virtual sin módulos
--   CONG_VIRT_MODS  (14 chars) - Congreso virtual + todos los módulos
--
-- ------------------------------------------------------------
-- INSTRUCCIONES:
--   1. Verificar columnas antes de ejecutar:
--        SHOW COLUMNS FROM eventos LIKE 'congreso_presencial';
--        SHOW COLUMNS FROM eventos LIKE 'congreso_virtual';
--        SHOW COLUMNS FROM inscritos LIKE 'asistencia_tipo';
--   2. Ejecutar primero en BD local Laragon para verificar.
--   3. Ejecutar en producción durante ventana de bajo tráfico.
--   4. No requiere backfill:
--      - DEFAULT 1 en congreso_presencial → todos los CE existentes
--        quedan como "solo presencial" (backward compatible).
--      - DEFAULT 0 en congreso_virtual → los CE existentes no
--        tienen congreso virtual activo (backward compatible).
--      - VARCHAR(25) es expansión sin pérdida de datos.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Nueva columna congreso_presencial
--    Solo para Curso Especial: indica si el congreso presencial está activo.
--    DEFAULT 1 garantiza compatibilidad con todos los CE ya creados.
-- ------------------------------------------------------------
ALTER TABLE eventos
  ADD COLUMN congreso_presencial TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Solo Curso Especial: 1 = congreso presencial disponible'
  AFTER tipo_evento_presencial;

-- ------------------------------------------------------------
-- 2. Nueva columna congreso_virtual
--    Solo para Curso Especial: indica si el congreso virtual está activo.
--    DEFAULT 0 garantiza compatibilidad con todos los CE ya creados.
-- ------------------------------------------------------------
ALTER TABLE eventos
  ADD COLUMN congreso_virtual TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Solo Curso Especial: 1 = congreso virtual disponible'
  AFTER congreso_presencial;

-- ------------------------------------------------------------
-- 3. Ampliar margen de asistencia_tipo
--    CONGRESO_MAS_MODULOS ya ocupa 20 chars (el límite antiguo).
--    Se amplía a 25 para dar margen a valores presentes y futuros.
-- ------------------------------------------------------------
ALTER TABLE inscritos
  MODIFY COLUMN asistencia_tipo VARCHAR(25) NULL;

-- ============================================================
-- Verificación post-migración (ejecutar para confirmar):
-- ============================================================
-- SHOW COLUMNS FROM eventos LIKE 'congreso_presencial';
-- SHOW COLUMNS FROM eventos LIKE 'congreso_virtual';
-- SHOW COLUMNS FROM inscritos LIKE 'asistencia_tipo';
--
-- SELECT id, nombre, modalidad, congreso_presencial, congreso_virtual
-- FROM eventos
-- WHERE modalidad = 'Curso_Especial'
-- ORDER BY id DESC
-- LIMIT 10;
-- ============================================================
