-- ============================================================
-- Migración: agregar url_conexion a tabla eventos
-- Fecha: 2026-05-22
-- Rama: feature/tipo-evento-presencial-registro
-- Descripción: Permite registrar una URL opcional de acceso
--              al evento (especialmente para eventos virtuales
--              o híbridos). Si se diligencia, se incluye en el
--              correo de confirmación al inscrito.
-- ============================================================
-- INSTRUCCIONES:
--   1. Ejecutar primero en BD local Laragon para verificar.
--   2. Verificar que el campo aparece en la tabla eventos.
--   3. Ejecutar en producción durante una ventana de bajo tráfico.
--   4. No requiere backfill: NULL por defecto es correcto.
-- ============================================================

ALTER TABLE eventos
  ADD COLUMN url_conexion VARCHAR(500) NULL
  AFTER lugar_personalizado;

-- Verificación post-migración (ejecutar para confirmar):
-- DESCRIBE eventos;
-- SELECT id, nombre, url_conexion FROM eventos LIMIT 5;
