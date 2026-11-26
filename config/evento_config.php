<?php
/**
 * Configuración por evento (columnas de `eventos`):
 *
 * - permite_asistencia_virtual (TINYINT 0/1, DEFAULT 1)
 *     Solo aplica a Presencial + Seminario. 1 = el inscrito puede elegir 100% virtual
 *     (SEM_100_VIRTUAL). 0 = el evento es exclusivamente presencial.
 *
 * - solicitar_soporte_confirmacion (TINYINT 0/1, DEFAULT 1)
 *     Aplica a todas las modalidades. 1 = los correos conservan sus mensajes de soporte de pago
 *     (solicitud al participante y advertencia "no adjuntó soporte" a la comercial).
 *     0 = se omiten esos mensajes. No cambia el campo de carga ni las validaciones del soporte.
 *
 * Si las columnas aún no existen (código desplegado antes del SQL), todo funciona con el
 * valor por defecto 1, que reproduce el comportamiento histórico.
 */

if (!function_exists('evento_config_columnas')) {
  /** Devuelve las columnas de configuración que existen realmente en `eventos` (con caché). */
  function evento_config_columnas($conn)
  {
    static $cache = null;
    if ($cache !== null) {
      return $cache;
    }
    $cache = array();
    try {
      $res = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos'
                             AND COLUMN_NAME IN ('permite_asistencia_virtual', 'solicitar_soporte_confirmacion')");
      if ($res) {
        while ($row = $res->fetch_row()) {
          $cache[$row[0]] = true;
        }
        $res->free();
      }
    } catch (Throwable $e) {
      // Sin información de columnas: se trabaja con los valores por defecto
      error_log('[EVENTO_CONFIG] No se pudo consultar las columnas de configuración: ' . $e->getMessage());
      $cache = array();
    }
    return $cache;
  }
}

if (!function_exists('evento_config_flag')) {
  /** Normaliza un valor leído de BD a 0/1. Solo 0 explícito desactiva; cualquier otro valor = 1. */
  function evento_config_flag($valor)
  {
    return ((string) $valor === '0') ? 0 : 1;
  }
}

if (!function_exists('evento_config_post')) {
  /**
   * Lee un 0/1 del POST de forma estricta. Devuelve null si el valor no llegó o no es
   * exactamente '0' o '1' (el llamador conserva entonces el valor actual o el defecto).
   */
  function evento_config_post($campo)
  {
    if (!isset($_POST[$campo]) || !is_string($_POST[$campo])) {
      return null;
    }
    $v = trim($_POST[$campo]);
    if ($v === '1') {
      return 1;
    }
    if ($v === '0') {
      return 0;
    }
    return null;
  }
}

if (!function_exists('evento_config_guardar')) {
  /**
   * Guarda valores 0/1 de configuración en un evento. Solo escribe columnas de la lista blanca
   * que existan realmente. Devuelve false si alguna escritura falla (incluidas excepciones de
   * mysqli), para que el admin no informe un guardado que no ocurrió.
   */
  function evento_config_guardar($conn, $evento_id, $valores)
  {
    $permitidas = array('permite_asistencia_virtual', 'solicitar_soporte_confirmacion');
    $cols = evento_config_columnas($conn);
    $ok = true;
    $id = (int) $evento_id;
    foreach ($valores as $col => $val) {
      if (!in_array($col, $permitidas, true) || !isset($cols[$col])) {
        continue;
      }
      $val = ((int) $val === 0) ? 0 : 1;
      try {
        $stmt = $conn->prepare("UPDATE eventos SET $col = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
          $ok = false;
          error_log('[EVENTO_CONFIG] No se pudo preparar la actualización de ' . $col . ' en el evento ' . $id);
          continue;
        }
        $stmt->bind_param('ii', $val, $id);
        if (!$stmt->execute()) {
          $ok = false;
          error_log('[EVENTO_CONFIG] Falló la actualización de ' . $col . ' en el evento ' . $id . ': ' . $stmt->error);
        }
        $stmt->close();
      } catch (Throwable $e) {
        $ok = false;
        error_log('[EVENTO_CONFIG] Error al actualizar ' . $col . ' en el evento ' . $id . ': ' . $e->getMessage());
      }
    }
    return $ok;
  }
}

if (!function_exists('evento_config_cargar')) {
  /** Lee la configuración de un evento. Devuelve los defectos (1/1) si las columnas no existen. */
  function evento_config_cargar($conn, $evento_id)
  {
    $cfg = array('permite_asistencia_virtual' => 1, 'solicitar_soporte_confirmacion' => 1);
    $cols = array_keys(evento_config_columnas($conn));
    if (empty($cols)) {
      return $cfg;
    }
    $leido = $cfg;
    try {
      $stmt = $conn->prepare('SELECT ' . implode(', ', $cols) . ' FROM eventos WHERE id = ? LIMIT 1');
      if (!$stmt) {
        return $cfg;
      }
      $id = (int) $evento_id;
      $stmt->bind_param('i', $id);
      $v1 = $v2 = null;
      if ($stmt->execute()) {
        if (count($cols) === 2) {
          $stmt->bind_result($v1, $v2);
        } else {
          $stmt->bind_result($v1);
        }
        if ($stmt->fetch()) {
          $leido[$cols[0]] = evento_config_flag($v1);
          if (count($cols) === 2) {
            $leido[$cols[1]] = evento_config_flag($v2);
          }
        }
      }
      $stmt->close();
    } catch (Throwable $e) {
      // Ante cualquier fallo de lectura se usa el comportamiento histórico (1/1)
      error_log('[EVENTO_CONFIG] No se pudo leer la configuración del evento ' . (int) $evento_id . ': ' . $e->getMessage());
      return $cfg;
    }
    return $leido;
  }
}
