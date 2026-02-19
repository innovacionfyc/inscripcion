<?php
// correo/enviar_correo.php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// === MODO PREVIEW LOCAL (no afecta producción) ===
if (!defined('MAIL_PREVIEW')) {
    define(
        'MAIL_PREVIEW',
        isset($_SERVER['HTTP_HOST']) &&
        (
            $_SERVER['HTTP_HOST'] === 'localhost' ||
            $_SERVER['HTTP_HOST'] === '127.0.0.1'
        )
    );
}

class CorreoDenuncia
{
    // -----------------------------
    // Helpers internos
    // -----------------------------
    private function getConn()
    {
        // intenta reutilizar $conn global
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            return $GLOBALS['conn'];
        }
        if (isset($conn) && $conn instanceof mysqli) {
            return $conn;
        }

        // intenta incluir conexión si no existe
        @require_once dirname(__DIR__) . '/db/conexion.php';
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            return $GLOBALS['conn'];
        }
        return null;
    }

    private function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function normModalidad($m)
    {
        $m = strtolower(trim((string) $m));
        $m = str_replace([' ', '-'], '_', $m);
        return $m;
    }

    private function fmtFechaES($ymd)
    {
        if (empty($ymd))
            return '';
        $ts = strtotime($ymd);
        if ($ts === false)
            return $ymd;
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $d = (int) date('j', $ts);
        $m = $meses[(int) date('n', $ts) - 1] ?? '';
        $y = date('Y', $ts);
        return $d . ' de ' . $m . ' de ' . $y;
    }

    private function fmtHora($ymd, $hms)
    {
        if (empty($ymd) || empty($hms))
            return '';
        $ts = strtotime($ymd . ' ' . $hms);
        if ($ts === false)
            return '';
        $t = date('g:i a', $ts);
        $t = str_replace(['am', 'pm'], ['a. m.', 'p. m.'], strtolower($t));
        return $t;
    }

    private function buildBox($title, $htmlBody)
    {
        return "
          <div style='margin:12px 0 18px; padding:14px; background:#fafafa; border:1px solid #eee; border-radius:12px;'>
            <div style='font-weight:800; color:#111827; margin-bottom:8px; font-size:14px;'>{$title}</div>
            <div style='font-size:14px; color:#111827; line-height:1.5;'>{$htmlBody}</div>
          </div>
        ";
    }

    private function getEventoFechas($evento_id, $tipo = null)
    {
        $conn = $this->getConn();
        if (!$conn)
            return [];

        $evento_id = (int) $evento_id;
        $rows = [];

        if ($tipo === null) {
            $sql = "SELECT tipo, fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('i', $evento_id);
                if ($st->execute()) {
                    $st->bind_result($t, $f, $hi, $hf);
                    while ($st->fetch()) {
                        $rows[] = ['tipo' => $t, 'fecha' => $f, 'hora_inicio' => $hi, 'hora_fin' => $hf];
                    }
                }
                $st->close();
            }
            return $rows;
        }

        $sql = "SELECT tipo, fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? AND LOWER(tipo)=LOWER(?) ORDER BY fecha ASC";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('is', $evento_id, $tipo);
            if ($st->execute()) {
                $st->bind_result($t, $f, $hi, $hf);
                while ($st->fetch()) {
                    $rows[] = ['tipo' => $t, 'fecha' => $f, 'hora_inicio' => $hi, 'hora_fin' => $hf];
                }
            }
            $st->close();
        }
        return $rows;
    }

    private function getCursoEspecialModulos($evento_id, $idsCsvOrALL)
    {
        $conn = $this->getConn();
        if (!$conn)
            return [];

        $evento_id = (int) $evento_id;
        $mods = [];

        $idsCsvOrALL = trim((string) $idsCsvOrALL);
        $all = (strtoupper($idsCsvOrALL) === 'ALL');

        $ids = [];
        if (!$all && $idsCsvOrALL !== '') {
            $parts = explode(',', $idsCsvOrALL);
            foreach ($parts as $p) {
                $id = (int) trim($p);
                if ($id > 0)
                    $ids[] = $id;
            }
            $ids = array_values(array_unique($ids));
        }

        if ($all) {
            $sql = "SELECT id, orden, fecha, hora_inicio, hora_fin, nombre
                    FROM eventos_modulos_virtuales
                    WHERE evento_id = ? AND activo = 1
                    ORDER BY orden ASC, fecha ASC";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('i', $evento_id);
                if ($st->execute()) {
                    $st->bind_result($id, $ord, $f, $hi, $hf, $nom);
                    while ($st->fetch()) {
                        $mods[] = ['id' => (int) $id, 'orden' => (int) $ord, 'fecha' => $f, 'hora_inicio' => $hi, 'hora_fin' => $hf, 'nombre' => $nom];
                    }
                }
                $st->close();
            }
            return $mods;
        }

        if (empty($ids))
            return [];

        // IN dinámico
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $sql = "SELECT id, orden, fecha, hora_inicio, hora_fin, nombre
                FROM eventos_modulos_virtuales
                WHERE evento_id = ? AND activo = 1 AND id IN ($placeholders)
                ORDER BY orden ASC, fecha ASC";

        if ($st = $conn->prepare($sql)) {
            $params = array_merge([$evento_id], $ids);

            // bind_param con referencias
            $bindNames = [];
            $bindNames[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bindNames[] = &$params[$i];
            }
            call_user_func_array([$st, 'bind_param'], $bindNames);

            if ($st->execute()) {
                $st->bind_result($id, $ord, $f, $hi, $hf, $nom);
                while ($st->fetch()) {
                    $mods[] = ['id' => (int) $id, 'orden' => (int) $ord, 'fecha' => $f, 'hora_inicio' => $hi, 'hora_fin' => $hf, 'nombre' => $nom];
                }
            }
            $st->close();
        }

        return $mods;
    }

    private function buildListaFechasHtml($rows, $labelDia = 'Día')
    {
        if (empty($rows))
            return "<div style='color:#6b7280'>—</div>";

        $ul = "<ul style='margin:0; padding-left:18px;'>";
        for ($i = 0; $i < count($rows); $i++) {
            $r = $rows[$i];
            $f = $this->fmtFechaES($r['fecha']);
            $hi = $this->fmtHora($r['fecha'], $r['hora_inicio']);
            $hf = $this->fmtHora($r['fecha'], $r['hora_fin']);
            $hor = ($hi && $hf) ? (" — <strong>{$hi}</strong> a <strong>{$hf}</strong>") : "";
            $ul .= "<li>{$labelDia} " . ($i + 1) . ": {$f}{$hor}</li>";
        }
        $ul .= "</ul>";
        return $ul;
    }

    private function buildListaModulosCEHtml($mods)
    {
        if (empty($mods))
            return "<div style='color:#6b7280'>—</div>";

        $ul = "<ul style='margin:0; padding-left:18px;'>";
        for ($i = 0; $i < count($mods); $i++) {
            $m = $mods[$i];
            $f = $this->fmtFechaES($m['fecha']);
            $hi = $this->fmtHora($m['fecha'], $m['hora_inicio']);
            $hf = $this->fmtHora($m['fecha'], $m['hora_fin']);
            $hor = ($hi && $hf) ? (" — <strong>{$hi}</strong> a <strong>{$hf}</strong>") : "";
            $ul .= "<li><strong>" . $this->h($m['nombre']) . "</strong> ({$f}){$hor}</li>";
        }
        $ul .= "</ul>";
        return $ul;
    }

    private function buildResumenSeleccionHtml($data)
    {
        $modalidadLow = $this->normModalidad($data['modalidad'] ?? '');

        $asistencia_tipo = strtoupper(trim((string) ($data['asistencia_tipo'] ?? '')));
        $modulos_texto = trim((string) ($data['modulos_texto'] ?? ''));
        $incluyeP = (strtoupper((string) ($data['incluye_presencial'] ?? 'NO')) === 'SI');
        $incluyeV = (strtoupper((string) ($data['incluye_virtual'] ?? 'NO')) === 'SI');

        // etiqueta bonita de la selección
        $line1 = '';
        if ($modalidadLow === 'curso_especial') {
            $map = [
                'CONGRESO' => 'Caso 1: Congreso (Presencial)',
                'CURSO_COMPLETO' => 'Caso 2: Curso completo (Presencial + todos los módulos virtuales)',
                'MODULOS_VIRTUALES' => 'Caso 3: Módulos virtuales (sin presencial)',
                'CONGRESO_MAS_MODULOS' => 'Caso 4: Congreso (Presencial) + módulos virtuales',
            ];
            $line1 = $map[$asistencia_tipo] ?? ('Selección: ' . $this->h($asistencia_tipo));
        } else {
            if ($asistencia_tipo === 'MODULOS') {
                $line1 = 'Asistencia: Por módulos';
            } elseif ($asistencia_tipo === 'COMPLETO') {
                $line1 = 'Asistencia: Curso completo';
            } else {
                $line1 = 'Asistencia: ' . $this->h($asistencia_tipo ?: 'COMPLETO');
            }
        }

        $tags = [];
        if ($incluyeP)
            $tags[] = "<span style='display:inline-block;padding:6px 10px;border-radius:999px;background:#fee2e2;border:1px solid #fecaca;color:#991b1b;font-weight:700;font-size:12px'>Presencial</span>";
        if ($incluyeV)
            $tags[] = "<span style='display:inline-block;padding:6px 10px;border-radius:999px;background:#dbeafe;border:1px solid #bfdbfe;color:#1e3a8a;font-weight:700;font-size:12px'>Virtual</span>";
        if (empty($tags))
            $tags[] = "<span style='display:inline-block;padding:6px 10px;border-radius:999px;background:#e5e7eb;border:1px solid #d1d5db;color:#111827;font-weight:700;font-size:12px'>General</span>";

        $modsLine = '';
        if (!empty($modulos_texto)) {
            $modsLine = "<div style='margin-top:8px;'><strong>Módulos seleccionados:</strong> " . $this->h($modulos_texto) . "</div>";
        }

        $html = "
          <div style='display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 0'>" . implode(' ', $tags) . "</div>
          <div style='margin-top:10px; font-size:15px; color:#111827;'><strong>{$this->h($line1)}</strong></div>
          {$modsLine}
        ";

        return $this->buildBox('✅ Tu selección', $html);
    }

    /**
     * Envía confirmación de inscripción a un evento (PHPMailer + Composer).
     */
    public function sendConfirmacionInscripcion($nombre, $correo, array $data)
    {
        $mail = new PHPMailer(true);

        // ====== OPCIONAL: completar desde BD (evento → comercial) si faltan datos ======
        $comWaDb = null;       // whatsapp del comercial (solo dígitos)
        $comFirmaPath = null;  // "uploads/firmas/xxx.png" o solo "xxx.png"
        $comNombre = null;     // nombre comercial

        if (
            (!isset($data['whatsapp_numero']) || $data['whatsapp_numero'] === '')
            || (empty($data['firma_file']) && empty($data['firma_url_public']))
            || (empty($data['encargado_nombre']))
        ) {
            if (!empty($data['evento_id'])) {
                $conn = $this->getConn();
                if ($conn instanceof mysqli) {
                    $sql = "SELECT u.nombre, u.whatsapp, u.firma_path
                            FROM eventos e
                            LEFT JOIN usuarios u ON u.id = e.comercial_user_id
                            WHERE e.id = ? LIMIT 1";
                    if ($st = $conn->prepare($sql)) {
                        $eid = (int) $data['evento_id'];
                        $st->bind_param('i', $eid);
                        if ($st->execute()) {
                            $st->bind_result($rNom, $rWa, $rFirma);
                            if ($st->fetch()) {
                                $comNombre = $rNom ? $rNom : null;
                                $comWaDb = $rWa ? preg_replace('/\D+/', '', $rWa) : null;
                                $comFirmaPath = $rFirma ? $rFirma : null;
                            }
                        }
                        $st->close();
                    }
                }

                // base_url() si está disponible
                $baseUrlFn = null;
                if (is_file(dirname(__DIR__) . '/config/url.php')) {
                    @require_once dirname(__DIR__) . '/config/url.php';
                    if (function_exists('base_url')) {
                        $baseUrlFn = 'base_url';
                    }
                }

                if (empty($data['whatsapp_numero']) && !empty($comWaDb)) {
                    $data['whatsapp_numero'] = $comWaDb;
                }
                if (empty($data['encargado_nombre']) && !empty($comNombre)) {
                    $data['encargado_nombre'] = $comNombre;
                }

                // Completar firma: intentar embebido local (CID); si no, URL pública
                if (empty($data['firma_file']) && empty($data['firma_url_public']) && !empty($comFirmaPath)) {
                    $p = trim($comFirmaPath);
                    if (strpos($p, 'uploads/firmas/') === false && strpos($p, '/uploads/firmas/') === false) {
                        $p = 'uploads/firmas/' . ltrim($p, '/');
                    }

                    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : rtrim(dirname(__DIR__), '/');
                    $abs = $docRoot . '/' . ltrim($p, '/');

                    if (is_file($abs)) {
                        $data['firma_file'] = $abs;
                    } elseif ($baseUrlFn) {
                        $data['firma_url_public'] = call_user_func($baseUrlFn, $p);
                    }
                }
            }
        }
        // ====== FIN completar desde BD ======

        try {
            // ====== SMTP (tus valores actuales) ======
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = 'html';
            $mail->isSMTP();
            $mail->Host = 'smtp-relay.gmail.com';
            $mail->Port = 25;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = false;
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Credenciales / remitente
            $smtpUser = 'innovacionti@fycconsultores.com';
            $smtpPass = 'zicy idns chmv fmqr'; // APP PASSWORD
            $fromEmail = 'alerts@fycconsultores.com';
            $fromName = 'F&C Consultores';

            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;

            // Encabezados
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($correo, $nombre);
            $mail->addReplyTo($fromEmail, $fromName);

            // ====== Embebidos / Adjuntos ======
            // Imagen del evento embebida (si existe)
            $cidImg = null;
            if (!empty($data['url_imagen']) && is_file($data['url_imagen'])) {
                $cidImg = 'evento_' . md5($data['url_imagen']);
                $mail->addEmbeddedImage($data['url_imagen'], $cidImg);
            }

            // PDF adjunto (si existe)
            if (!empty($data['adjunto_pdf'])) {
                if (!file_exists($data['adjunto_pdf'])) {
                    error_log("PDF no encontrado: " . $data['adjunto_pdf']);
                } else {
                    $mail->addAttachment($data['adjunto_pdf'], basename($data['adjunto_pdf']));
                }
            }

            // === ADJUNTOS AUTOMÁTICOS POR TIPO (virtual/presencial) ===
            // Nota: ahora NO dependemos solo del texto "modalidad", sino de flags:
            // incluye_presencial / incluye_virtual (viene desde registro.php)
            $tryDirs = array();
            $baseDocs = dirname(__DIR__) . '/docs';

            $eid = isset($data['evento_id']) ? (int) $data['evento_id'] : 0;
            $incluyeP = (strtoupper((string) ($data['incluye_presencial'] ?? '')) === 'SI');
            $incluyeV = (strtoupper((string) ($data['incluye_virtual'] ?? '')) === 'SI');

            // fallback por si no vinieron flags (compatibilidad)
            $modalidadLow = isset($data['modalidad']) ? strtolower($data['modalidad']) : '';
            if (!$incluyeP && !$incluyeV) {
                if ($modalidadLow === 'hibrida') {
                    $incluyeP = true;
                    $incluyeV = true;
                } elseif ($modalidadLow === 'presencial') {
                    $incluyeP = true;
                } elseif ($modalidadLow === 'virtual') {
                    $incluyeV = true;
                }
            }

            if ($incluyeV) {
                if ($eid > 0)
                    $tryDirs[] = $baseDocs . '/evento_virtual/' . $eid;
                $tryDirs[] = $baseDocs . '/evento_virtual';
            }
            if ($incluyeP) {
                if ($eid > 0)
                    $tryDirs[] = $baseDocs . '/evento_presencial/' . $eid;
                $tryDirs[] = $baseDocs . '/evento_presencial';
            }

            $permitidas = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp');
            $maxAdjuntos = 20;
            $adjCount = 0;

            foreach ($tryDirs as $dir) {
                if ($adjCount >= $maxAdjuntos)
                    break;
                if (!is_dir($dir))
                    continue;

                $files = @glob($dir . '/*');
                if (!$files)
                    continue;

                foreach ($files as $abs) {
                    if ($adjCount >= $maxAdjuntos)
                        break;
                    if (!is_file($abs))
                        continue;

                    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                    if (!in_array($ext, $permitidas))
                        continue;

                    $size = @filesize($abs);
                    if ($size !== false && $size > 25 * 1024 * 1024) {
                        error_log('[ADJUNTOS_AUTO] Archivo muy grande, omitido: ' . $abs);
                        continue;
                    }

                    $mail->addAttachment($abs, basename($abs));
                    $adjCount++;
                }
            }

            // ====== Contenido ======
            $mail->isHTML(true);

            $modalidadTxt = isset($data['modalidad']) ? $this->h($data['modalidad']) : '';
            $nombreEvento = isset($data['nombre_evento']) ? $this->h($data['nombre_evento']) : '';
            $resumenFechas = isset($data['resumen_fechas']) ? (string) $data['resumen_fechas'] : '';
            $detalleHorario = isset($data['detalle_horario']) ? (string) $data['detalle_horario'] : '';
            $fechaLimite = isset($data['fecha_limite']) ? (string) $data['fecha_limite'] : '';

            $modalidadLowNorm = $this->normModalidad($data['modalidad'] ?? '');

            // Bloques especiales de agenda (Curso Especial)
            $agendaHtml = "";
            if (!empty($data['evento_id'])) {
                $evento_id = (int) $data['evento_id'];

                if ($modalidadLowNorm === 'curso_especial') {
                    // agenda presencial
                    $pres = $this->getEventoFechas($evento_id, 'presencial');
                    $agendaPres = $this->buildListaFechasHtml($pres, 'Día');

                    // agenda módulos seleccionados (según asistencia_tipo)
                    $asist = strtoupper(trim((string) ($data['asistencia_tipo'] ?? '')));
                    $modsCsv = (string) ($data['modulos_fechas'] ?? '');
                    $mods = [];

                    if ($asist === 'CURSO_COMPLETO') {
                        $mods = $this->getCursoEspecialModulos($evento_id, 'ALL');
                    } elseif ($asist === 'MODULOS_VIRTUALES' || $asist === 'CONGRESO_MAS_MODULOS') {
                        $mods = $this->getCursoEspecialModulos($evento_id, $modsCsv);
                    } else {
                        $mods = [];
                    }

                    $agendaMods = $this->buildListaModulosCEHtml($mods);

                    $agendaHtml .= $this->buildBox('📌 Agenda (Presencial)', $agendaPres);

                    // solo mostramos módulos si aplica virtual en la selección
                    $incluyeV2 = (strtoupper((string) ($data['incluye_virtual'] ?? 'NO')) === 'SI');
                    if ($incluyeV2) {
                        $agendaHtml .= $this->buildBox('💻 Agenda (Módulos virtuales)', $agendaMods);
                    }
                } else {
                    // comportamiento original: si el usuario eligió MODULOS (virtual clásico), mostrar solo esos
                    $detalleHorarioFinal = $detalleHorario;

                    if (
                        !empty($data['asistencia_tipo'])
                        && strtoupper($data['asistencia_tipo']) === 'MODULOS'
                        && !empty($data['modulos_fechas'])
                    ) {
                        $selMap = array();
                        $csv = explode(',', $data['modulos_fechas']); // 'YYYY-mm-dd,YYYY-mm-dd'
                        foreach ($csv as $d) {
                            $d = trim($d);
                            if ($d !== '')
                                $selMap[$d] = true;
                        }

                        $rowsSel = array();
                        $all = $this->getEventoFechas($evento_id, null);
                        foreach ($all as $r) {
                            if (!empty($r['fecha']) && isset($selMap[$r['fecha']])) {
                                $rowsSel[] = $r;
                            }
                        }

                        if (!empty($rowsSel)) {
                            $detalleHorarioFinal = $this->buildListaFechasHtml($rowsSel, 'Día');
                        }
                    }

                    // bloque agenda general
                    if (!empty($detalleHorarioFinal)) {
                        $agendaHtml .= $this->buildBox('📌 Fecha y horario', $detalleHorarioFinal);
                    }
                }
            }

            // Bloque selección (lo que eligió)
            $seleccionHtml = $this->buildResumenSeleccionHtml($data);

            // Lugar (solo si aplica presencial)
            $lugarHtml = !empty($data['lugar'])
                ? "<div style='margin-top:10px;'><strong>📍 Lugar:</strong><br>{$data['lugar']}</div>"
                : "";

            // PDF aviso
            $pdfAviso = (!empty($data['adjunto_pdf']) && is_file($data['adjunto_pdf']))
                ? "<div style='margin-top:12px; font-size:13px; color:#065f46;'>📎 Se adjunta la guía hotelera en PDF.</div>"
                : '';

            $fechaLimiteTxt = '';
            if (!empty($fechaLimite)) {
                $ts = strtotime($fechaLimite);
                if ($ts !== false)
                    $fechaLimiteTxt = date('d/m/Y', $ts);
            }

            // Encabezado “Señores: (Entidad) (Nombre)”
            $encabezado = '';
            if (!empty($data['entidad_empresa']) || !empty($data['nombre_inscrito'])) {
                $encabezado = "<div style='margin:0 0 10px'>
                    <strong>Señores:</strong><br>" .
                    (!empty($data['entidad_empresa']) ? $this->h($data['entidad_empresa']) . "<br>" : "") .
                    (!empty($data['nombre_inscrito']) ? $this->h($data['nombre_inscrito']) : "") .
                    "</div>";
            }

            // Botón de WhatsApp
            $btnWhatsapp = '';
            $waNum = !empty($data['whatsapp_numero']) ? preg_replace('/\D/', '', $data['whatsapp_numero']) : '';
            if (empty($waNum) && !empty($comWaDb))
                $waNum = $comWaDb;

            if (!empty($waNum)) {
                $txt = rawurlencode('Hola, tengo una consulta sobre el evento ' . ($data['nombre_evento'] ?? ''));
                $btnWhatsapp = '<div style="margin:16px 0 0; text-align:center;">
                    <a href="https://wa.me/' . $waNum . '?text=' . $txt . '" target="_blank"
                       style="display:inline-block;background:#25D366;color:#fff;padding:12px 20px;border-radius:12px;text-decoration:none;font-weight:800">
                      💬 Escribir por WhatsApp
                    </a>
                  </div>';
            }

            // Firma (preferir CID; si no, URL)
            $firmaHtml = '';
            $firmaImgTag = '';
            $encargadoNombre = !empty($data['encargado_nombre']) ? $data['encargado_nombre'] : ($comNombre ?: '');

            if (!empty($data['firma_file']) && is_file($data['firma_file'])) {
                $cidFirma = 'firma_' . md5($data['firma_file']);
                $mime = function_exists('mime_content_type') ? mime_content_type($data['firma_file']) : 'image/png';
                $mail->addEmbeddedImage($data['firma_file'], $cidFirma, basename($data['firma_file']), 'base64', $mime);
                $firmaImgTag = "<img src='cid:{$cidFirma}' alt='Firma' width='520' style='width:100%; max-width:520px; height:auto; display:block; margin:12px auto 0;'>";
            } elseif (!empty($data['firma_url_public'])) {
                $firmaImgTag = '<img src="' . $this->h($data['firma_url_public']) . '" alt="Firma" width="520" style="width:100%; max-width:520px; height:auto; display:block; margin:12px auto 0;">';
            }
            if ($firmaImgTag || $encargadoNombre) {
                $firmaHtml = '<div style="margin-top:18px; text-align:center;">'
                    . ($encargadoNombre ? '<div style="font-weight:800;margin-bottom:6px;color:#111827;">' . $this->h($encargadoNombre) . '</div>' : '')
                    . $firmaImgTag
                    . '</div>';
            }

            // Subject
            $mail->Subject = 'Confirmación de inscripción – ' . $nombreEvento;

            // Banner
            $bannerImg = $cidImg
                ? "<img src='cid:{$cidImg}' alt='Evento' style='width:100%; max-height:280px; object-fit:cover; border-radius:14px 14px 0 0'>"
                : '';

            // Autoestudio block
            $autoHtml = (!empty($data['autoestudio']) && (int) $data['autoestudio'] === 1)
                ? "<div style='margin:10px 0 14px; padding:12px; border:1px solid #bbf7d0; background:#ecfdf5; border-radius:12px;'>
                     <strong style='color:#166534;'>📘 Este evento incluye Autoestudio</strong><br>
                     <span style='color:#166534; font-size:13px;'>
                       Recibirás el material correspondiente a través de los canales oficiales del evento.
                     </span>
                   </div>"
                : "";

            // Cuerpo principal (limpio y entendible)
            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:720px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:14px;overflow:hidden'>
                {$bannerImg}

                <div style='padding:24px 26px 0 26px'>
                  <h1 style='margin:0 0 8px 0; color:#000000; font-size:26px; line-height:1.25; font-weight:800; text-align:center;'>
                    {$nombreEvento}
                  </h1>
                  <div style='margin:0 0 16px 0; text-align:center; color:#6b7280; font-size:14px;'>
                    {$modalidadTxt}" . ($resumenFechas ? " · " . $this->h($resumenFechas) : "") . "
                  </div>
                </div>

                <div style='padding:0 28px 28px 28px; font-size:15px; color:#111827; line-height:1.55;'>
                  {$encabezado}

                  <p style='margin:0 0 10px'>
                    Tu inscripción ha sido confirmada. A continuación te compartimos el resumen del evento y <strong>tu selección</strong>.
                  </p>

                  <div style='margin:10px 0 0;'>
                    <div style='font-weight:800; color:#111827; margin-bottom:6px;'>Modalidad del evento:</div>
                    <div style='color:#111827; margin-bottom:6px;'>{$modalidadTxt}</div>
                  </div>

                  {$seleccionHtml}

                  {$autoHtml}

                  {$agendaHtml}

                  " . ($lugarHtml ? $this->buildBox('📍 Información del lugar', $lugarHtml) : "") . "

                  <div style='margin:10px 0 0;'>
                    <div style='font-weight:800; margin-bottom:6px;'>🔹 Tenga en cuenta</div>
                    <ul style='margin:8px 0 0; padding-left:18px; color:#111827'>
                      <li>Para garantizar su reserva, por favor envíe con anticipación el soporte de pago o autorización correspondiente.</li>
                      " . (!empty($fechaLimiteTxt) ? "<li>Confirma tu asistencia antes del <strong>{$fechaLimiteTxt}</strong>.</li>" : "") . "
                      <li>Un día antes del evento recibirás el cronograma detallado.</li>
                    </ul>
                  </div>

                  {$btnWhatsapp}

                  <div style='margin-top:22px'>
                    <p style='margin:0'>¡Nos vemos pronto!</p>
                    <p style='margin:0'>Cordialmente,</p>
                    {$firmaHtml}
                  </div>

                  {$pdfAviso}

                  <p style='font-size:12px;color:#6b7280;margin-top:22px'>
                    Este es un mensaje automático, por favor no responder.
                  </p>
                </div>

                <div style='background:#f1f1f1;text-align:center;padding:12px;font-size:12px;color:#6b7280'>
                  F&C Consultores © " . date('Y') . "
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);

            // AltBody robusto
            $altNombreEvento = $data['nombre_evento'] ?? '';
            $altModalidad = $data['modalidad'] ?? '';
            $altResumen = strip_tags((string) $resumenFechas);
            $altSel = $data['asistencia_tipo'] ?? '';
            $altMods = $data['modulos_texto'] ?? '';
            $altAuto = (!empty($data['autoestudio']) && (int) $data['autoestudio'] === 1) ? " Incluye Autoestudio." : "";
            $mail->AltBody = "Inscripción confirmada a {$altNombreEvento} ({$altModalidad}). Fechas: {$altResumen}. Selección: {$altSel}. Módulos: {$altMods}." . $altAuto;

            return $mail->send();
        } catch (Exception $e) {
            error_log('No se pudo enviar correo: ' . $mail->ErrorInfo);
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Aviso interno al comercial: nueva inscripción (adjunta soporte si existe)
    // ---------------------------------------------------------------------
    public function sendAvisoNuevaInscripcion($correoDestino, $data)
    {
        $mail = new PHPMailer(true);
        try {
            // ====== SMTP (mismos valores que ya usas) ======
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = 'html';
            $mail->isSMTP();
            $mail->Host = 'smtp-relay.gmail.com';
            $mail->Port = 25;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = false;
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $smtpUser = 'innovacionti@fycconsultores.com';
            $smtpPass = 'zicy idns chmv fmqr';
            $fromEmail = 'alerts@fycconsultores.com';
            $fromName = 'F&C Consultores';

            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;

            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($correoDestino);

            // Reply-To al correo del inscrito
            $replyTo = !empty($data['email_corporativo']) ? $data['email_corporativo']
                : (!empty($data['email_personal']) ? $data['email_personal'] : null);
            if (!empty($replyTo)) {
                $mail->addReplyTo($replyTo, isset($data['inscrito_nombre']) ? $data['inscrito_nombre'] : '');
            }

            // Adjuntar soporte si existe
            $hayAdjunto = false;
            $rel = '';
            if (!empty($data['soporte_pago'])) {
                $rel = $data['soporte_pago'];
            } elseif (!empty($data['soporte_rel'])) {
                $rel = $data['soporte_rel'];
            }

            if (!empty($rel)) {
                $abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($rel, '/');
                if (is_file($abs)) {
                    $mail->addAttachment($abs, basename($rel));
                    $hayAdjunto = true;
                } else {
                    error_log('[AVISO_COMERCIAL] Soporte no encontrado en disco: ' . $abs);
                }
            }

            $mail->isHTML(true);

            $ev = isset($data['nombre_evento']) ? $this->h($data['nombre_evento']) : '';
            $mod = isset($data['modalidad']) ? $this->h($data['modalidad']) : '';
            $sum = isset($data['resumen_fechas']) ? (string) $data['resumen_fechas'] : '';
            $now = date('Y-m-d H:i:s');

            // Selección del inscrito (bonita)
            $asist = strtoupper(trim((string) ($data['asistencia_tipo'] ?? '')));
            $modsTxt = trim((string) ($data['modulos_texto'] ?? ''));
            $incluyeP = (strtoupper((string) ($data['incluye_presencial'] ?? 'NO')) === 'SI');
            $incluyeV = (strtoupper((string) ($data['incluye_virtual'] ?? 'NO')) === 'SI');

            $mapCE = [
                'CONGRESO' => 'Caso 1: Congreso (Presencial)',
                'CURSO_COMPLETO' => 'Caso 2: Curso completo (Presencial + todos los módulos virtuales)',
                'MODULOS_VIRTUALES' => 'Caso 3: Módulos virtuales (sin presencial)',
                'CONGRESO_MAS_MODULOS' => 'Caso 4: Congreso (Presencial) + módulos virtuales',
            ];
            $selNice = isset($mapCE[$asist]) ? $mapCE[$asist] : ($asist ?: 'COMPLETO');

            $tags = [];
            if ($incluyeP)
                $tags[] = "<span style='display:inline-block;padding:6px 10px;border-radius:999px;background:#fee2e2;border:1px solid #fecaca;color:#991b1b;font-weight:800;font-size:12px'>Presencial</span>";
            if ($incluyeV)
                $tags[] = "<span style='display:inline-block;padding:6px 10px;border-radius:999px;background:#dbeafe;border:1px solid #bfdbfe;color:#1e3a8a;font-weight:800;font-size:12px'>Virtual</span>";
            if (empty($tags))
                $tags[] = "<span style='display:inline-block;padding:6px 10px;border-radius:999px;background:#e5e7eb;border:1px solid #d1d5db;color:#111827;font-weight:800;font-size:12px'>General</span>";

            $seleccionComercial = "
              <div style='display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 0'>" . implode(' ', $tags) . "</div>
              <div style='margin-top:10px;'><strong>Selección:</strong> " . $this->h($selNice) . "</div>
              " . (!empty($modsTxt) ? "<div style='margin-top:8px;'><strong>Módulos:</strong> " . $this->h($modsTxt) . "</div>" : "<div style='margin-top:8px;color:#6b7280;'>Módulos: —</div>") . "
            ";

            $mail->Subject = 'Nueva inscripción – ' . $ev;

            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:720px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:14px;overflow:hidden'>
                <div style='padding:22px 26px; font-size:14px; color:#111827'>
                  <h2 style='margin:0 0 12px;color:#d32f57;font-weight:900'>Nueva inscripción recibida</h2>

                  <div style='margin-bottom:14px;'>
                    <div><strong>Evento:</strong> {$ev}</div>
                    <div><strong>Modalidad:</strong> {$mod}</div>
                    " . (!empty($sum) ? "<div><strong>Fechas:</strong> " . $this->h($sum) . "</div>" : "") . "
                  </div>

                  <div style='margin:14px 0; padding:14px; background:#fafafa; border:1px solid #eee; border-radius:12px;'>
                    <div style='font-weight:900;margin-bottom:10px;'>👤 Datos del inscrito</div>
                    <div><strong>Tipo:</strong> " . $this->h(isset($data['tipo_inscripcion']) ? $data['tipo_inscripcion'] : '') . "</div>
                    <div><strong>Nombre:</strong> " . $this->h(isset($data['inscrito_nombre']) ? $data['inscrito_nombre'] : '') . "</div>
                    <div><strong>Cédula:</strong> " . $this->h(isset($data['cedula']) ? $data['cedula'] : '') . "</div>
                    <div><strong>Cargo:</strong> " . $this->h(isset($data['cargo']) ? $data['cargo'] : '') . "</div>
                    <div><strong>Entidad:</strong> " . $this->h(isset($data['entidad']) ? $data['entidad'] : '') . "</div>
                    <div><strong>Ciudad:</strong> " . $this->h(isset($data['ciudad']) ? $data['ciudad'] : '') . "</div>
                    <div><strong>Celular:</strong> " . $this->h(isset($data['celular']) ? $data['celular'] : '') . "</div>
                    <div><strong>Email corporativo:</strong> " . $this->h(isset($data['email_corporativo']) ? $data['email_corporativo'] : '') . "</div>
                    " . (!empty($data['email_personal']) ? "<div><strong>Email personal:</strong> " . $this->h($data['email_personal']) . "</div>" : "") . "
                    " . (!empty($data['medio']) ? "<div><strong>Medio por el que se enteró:</strong> " . $this->h($data['medio']) . "</div>" : "") . "
                  </div>

                  <div style='margin:14px 0; padding:14px; background:#fff; border:1px solid #e5e7eb; border-radius:12px;'>
                    <div style='font-weight:900;margin-bottom:10px;'>✅ Selección del inscrito</div>
                    {$seleccionComercial}
                  </div>

                  " . ($hayAdjunto
                ? "<div style='margin:10px 0 0; padding:12px; border-radius:12px; border:1px solid #bbf7d0; background:#ecfdf5; color:#166534; font-weight:800;'>📎 Se adjuntó el Soporte de Asistencia.</div>"
                : "<div style='margin:10px 0 0; padding:12px; border-radius:12px; border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; font-weight:800;'>⚠️ El inscrito no adjuntó soporte.</div>"
            ) . "

                  <div style='margin:12px 0 0; font-size:12px; color:#6b7280'>
                    Recibido: {$now}
                  </div>
                </div>

                <div style='background:#f1f1f1;text-align:center;padding:12px;font-size:12px;color:#6b7280'>
                  F&C Consultores © " . date('Y') . "
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);
            $mail->AltBody = "Nueva inscripción en {$ev}. Inscrito: " .
                (isset($data['inscrito_nombre']) ? $data['inscrito_nombre'] : '') . " - " .
                (isset($data['email_corporativo']) ? $data['email_corporativo'] : '') .
                ". Selección: {$selNice}. Módulos: {$modsTxt}." .
                ($hayAdjunto ? ' (se adjunta soporte)' : ' (sin soporte)');

            return $mail->send();
        } catch (Exception $e) {
            error_log('No se pudo enviar AVISO a comercial: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
