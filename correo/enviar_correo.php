<?php
// correo/enviar_correo.php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CorreoDenuncia
{

    /**
     * Envía confirmación de inscripción a un evento (PHPMailer + Composer).
     *
     * @param string $nombre  Nombre del inscrito
     * @param string $correo  Correo del inscrito (corporativo o personal)
     * @param array  $data    Datos del evento:
     *  - (recomendado) evento_id
     *  - nombre_evento, modalidad, fecha_limite, resumen_fechas, detalle_horario
     *  - url_imagen (ruta ABS), url_imagen_public (opcional)
     *  - adjunto_pdf (ruta ABS opcional)
     *  - lugar, entidad_empresa, nombre_inscrito
     *  - whatsapp_numero (solo dígitos)
     *  - firma_file (ruta ABS) o firma_url_public (URL)
     *  - encargado_nombre
     * @return bool
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
                // Asegurar $conn aunque conexion.php ya se haya incluido en otro archivo
                if (!isset($conn) || !($conn instanceof mysqli)) {
                    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                        $conn = $GLOBALS['conn'];
                    } else {
                        @require_once dirname(__DIR__) . '/db/conexion.php';
                        if ((!isset($conn) || !($conn instanceof mysqli)) && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                            $conn = $GLOBALS['conn'];
                        }
                    }
                }

                if (isset($conn) && $conn instanceof mysqli) {
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

                // Completar whatsapp/encargado si faltan
                if (empty($data['whatsapp_numero']) && !empty($comWaDb)) {
                    $data['whatsapp_numero'] = $comWaDb;
                }
                if (empty($data['encargado_nombre']) && !empty($comNombre)) {
                    $data['encargado_nombre'] = $comNombre;
                }

                // Completar firma: intentar embebido local (CID); si no, URL pública
                if (empty($data['firma_file']) && empty($data['firma_url_public']) && !empty($comFirmaPath)) {
                    $p = trim($comFirmaPath);

                    // Si viene solo el nombre, anteponer la carpeta por defecto
                    if (strpos($p, 'uploads/firmas/') === false && strpos($p, '/uploads/firmas/') === false) {
                        $p = 'uploads/firmas/' . ltrim($p, '/');
                    }

                    // Ruta absoluta correcta en Plesk
                    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : rtrim(dirname(__DIR__), '/');
                    $abs = $docRoot . '/' . ltrim($p, '/');

                    if (is_file($abs)) {
                        $data['firma_file'] = $abs; // usar CID (embebido)
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
            $mail->Port = 25;              // sin TLS explícito
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
            $smtpUser = 'it@fycconsultores.com';
            $smtpPass = 'ecym cwbl dfkg maea'; // APP PASSWORD
            $fromEmail = 'certificados@fycconsultores.com';
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
            // Directorios candidatos (por evento y global)
            $tryDirs = array();
            $baseDocs = dirname(__DIR__) . '/docs';

            $modalidadLow = isset($data['modalidad']) ? strtolower($data['modalidad']) : '';
            $eid = isset($data['evento_id']) ? (int) $data['evento_id'] : 0;

            if ($modalidadLow === 'virtual') {
                if ($eid > 0)
                    $tryDirs[] = $baseDocs . '/evento_virtual/' . $eid; // por evento (recomendado)
                $tryDirs[] = $baseDocs . '/evento_virtual';                        // global
            } elseif ($modalidadLow === 'presencial') {
                if ($eid > 0)
                    $tryDirs[] = $baseDocs . '/evento_presencial/' . $eid; // por evento (recomendado)
                $tryDirs[] = $baseDocs . '/evento_presencial';                        // global
            }

            // Extensiones permitidas (agrega más si necesitas)
            $permitidas = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp');
            $maxAdjuntos = 20;   // tope sano
            $adjCount = 0;

            foreach ($tryDirs as $dir) {
                if ($adjCount >= $maxAdjuntos)
                    break;
                if (!is_dir($dir))
                    continue;

                // listado simple de archivos (no recursivo)
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

                    // (opcional) descartar archivos enormes
                    $size = @filesize($abs);
                    if ($size !== false && $size > 25 * 1024 * 1024) { // 25MB
                        error_log('[ADJUNTOS_AUTO] Archivo muy grande, omitido: ' . $abs);
                        continue;
                    }

                    $mail->addAttachment($abs, basename($abs));
                    $adjCount++;
                }
            }


            // ====== Contenido ======
            $mail->isHTML(true);

            $modalidadTxt = isset($data['modalidad']) ? htmlspecialchars($data['modalidad'], ENT_QUOTES, 'UTF-8') : '';
            $nombreEvento = isset($data['nombre_evento']) ? htmlspecialchars($data['nombre_evento'], ENT_QUOTES, 'UTF-8') : '';
            $resumenFechas = isset($data['resumen_fechas']) ? $data['resumen_fechas'] : '';
            $detalleHorario = isset($data['detalle_horario']) ? $data['detalle_horario'] : '';
            $fechaLimite = isset($data['fecha_limite']) ? $data['fecha_limite'] : '';

            // --- Si el usuario eligió "por módulos", mostrar solo esos días en el bloque de horario ---
            $detalleHorarioFinal = $detalleHorario;

            if (
                !empty($data['evento_id'])
                && !empty($data['asistencia_tipo'])
                && strtoupper($data['asistencia_tipo']) === 'MODULOS'
                && !empty($data['modulos_fechas'])
            ) {

                // Aseguramos conexión
                if (!isset($conn) || !($conn instanceof mysqli)) {
                    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                        $conn = $GLOBALS['conn'];
                    } else {
                        @require_once dirname(__DIR__) . '/db/conexion.php';
                        if ((!isset($conn) || !($conn instanceof mysqli)) && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                            $conn = $GLOBALS['conn'];
                        }
                    }
                }

                // Leemos todas las fechas del evento y filtramos por las seleccionadas
                $selMap = array();
                $csv = explode(',', $data['modulos_fechas']);  // 'YYYY-mm-dd,YYYY-mm-dd'
                foreach ($csv as $d) {
                    $d = trim($d);
                    if ($d !== '')
                        $selMap[$d] = true;
                }

                $rowsSel = array();
                if (isset($conn) && $conn instanceof mysqli) {
                    if ($stf = $conn->prepare("SELECT fecha, hora_inicio, hora_fin FROM eventos_fechas WHERE evento_id = ? ORDER BY fecha ASC")) {
                        $eid = (int) $data['evento_id'];
                        $stf->bind_param('i', $eid);
                        if ($stf->execute()) {
                            $stf->bind_result($f, $hi, $hf);
                            while ($stf->fetch()) {
                                if (isset($selMap[$f])) {
                                    $rowsSel[] = array('fecha' => $f, 'hora_inicio' => $hi, 'hora_fin' => $hf);
                                }
                            }
                        }
                        $stf->close();
                    }
                }

                // Formatear UL solo con los módulos elegidos (igual estilo que el original)
                if (!empty($rowsSel)) {
                    $meses = array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
                    $ul = "<ul style='margin:0;padding-left:18px'>";
                    for ($i = 0; $i < count($rowsSel); $i++) {
                        $r = $rowsSel[$i];
                        $d = (int) date('j', strtotime($r['fecha']));
                        $m = $meses[(int) date('n', strtotime($r['fecha'])) - 1];
                        $y = date('Y', strtotime($r['fecha']));
                        $hi = date('g:i a', strtotime($r['fecha'] . ' ' . $r['hora_inicio']));
                        $hf = date('g:i a', strtotime($r['fecha'] . ' ' . $r['hora_fin']));
                        $hi = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hi));
                        $hf = str_replace(array('am', 'pm'), array('a. m.', 'p. m.'), strtolower($hf));
                        $ul .= "<li>Día " . ($i + 1) . ": $d de $m de $y — <strong>$hi</strong> a <strong>$hf</strong></li>";
                    }
                    $ul .= "</ul>";
                    $detalleHorarioFinal = $ul;
                }
            }

            // --- NUEVO: Asistencia (curso completo vs módulos) ---
            $asistHtml = '';
            $altAsist = '';
            if (!empty($data['asistencia_tipo'])) {
                if (strtoupper($data['asistencia_tipo']) === 'COMPLETO') {
                    $asistHtml = "<p style='margin:0 0 12px'><strong>Asistencia:</strong> Curso completo</p>";
                    $altAsist = " Asistencia: Curso completo.";
                } else {
                    $mods = !empty($data['modulos_texto'])
                        ? htmlspecialchars($data['modulos_texto'], ENT_QUOTES, 'UTF-8')
                        : '';
                    $asistHtml = "<p style='margin:0 0 12px'><strong>Asistencia por módulos:</strong> " . $mods . "</p>";
                    $altAsist = " Asistencia por módulos: " . $mods . ".";
                }
            }


            $mail->Subject = 'Confirmación de inscripción – ' . $nombreEvento;

            $bannerImg = $cidImg
                ? "<img src='cid:{$cidImg}' alt='Evento' style='width:100%; max-height:260px; object-fit:cover; border-radius:12px 12px 0 0'>"
                : '';

            $lugarHtml = !empty($data['lugar'])
                ? "<p><strong>•  Lugar:</strong><br>{$data['lugar']}</p>"
                : '';

            $pdfAviso = (!empty($data['adjunto_pdf']) && is_file($data['adjunto_pdf']))
                ? "<p style='margin:0'>📎 Se adjunta la guía hotelera en PDF.</p>"
                : '';

            $fechaLimiteTxt = '';
            if (!empty($fechaLimite)) {
                $ts = strtotime($fechaLimite);
                if ($ts !== false) {
                    $fechaLimiteTxt = date('d/m/Y', $ts);
                }
            }

            // Encabezado “Señores: (Entidad) (Nombre)”
            $encabezado = '';
            if (!empty($data['entidad_empresa']) || !empty($data['nombre_inscrito'])) {
                $encabezado = "<p style='margin:0 0 10px'><strong>Señores:</strong><br>";
                if (!empty($data['entidad_empresa'])) {
                    $encabezado .= htmlspecialchars($data['entidad_empresa'], ENT_QUOTES, 'UTF-8') . "<br>";
                }
                if (!empty($data['nombre_inscrito'])) {
                    $encabezado .= htmlspecialchars($data['nombre_inscrito'], ENT_QUOTES, 'UTF-8');
                }
                $encabezado .= "</p>";
            }

            // Botón de WhatsApp (aparece si hay número, sin importar modalidad)
            $btnWhatsapp = '';
            $waNum = !empty($data['whatsapp_numero']) ? preg_replace('/\D/', '', $data['whatsapp_numero']) : '';
            if (empty($waNum) && !empty($comWaDb)) {
                $waNum = $comWaDb;
            }
            if (!empty($waNum)) {
                $txt = rawurlencode('Hola, tengo una consulta sobre el evento ' . ($data['nombre_evento'] ?? ''));
                $btnWhatsapp = '<p style="margin:16px 0 0; text-align:center;">
                    <a href="https://wa.me/' . $waNum . '?text=' . $txt . '" target="_blank"
                       style="display:inline-block;background:#25D366;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:bold">
                      💬 Escribir por WhatsApp
                    </a>
                  </p>';
            }

            // Firma (preferir CID; si no, URL) — AUMENTADA
            $firmaHtml = '';
            $firmaImgTag = '';
            $encargadoNombre = !empty($data['encargado_nombre']) ? $data['encargado_nombre'] : ($comNombre ?: '');

            if (!empty($data['firma_file']) && is_file($data['firma_file'])) {
                $cidFirma = 'firma_' . md5($data['firma_file']);
                $mime = function_exists('mime_content_type') ? mime_content_type($data['firma_file']) : 'image/png';
                $mail->addEmbeddedImage($data['firma_file'], $cidFirma, basename($data['firma_file']), 'base64', $mime);
                // ⬇️ AUMENTÉ el tamaño de la firma
                $firmaImgTag = "<img src='cid:{$cidFirma}' alt='Firma' width='520' style='width:100%; max-width:520px; height:auto; display:block; margin:12px auto 0;'>";
            } elseif (!empty($data['firma_url_public'])) {
                $firmaImgTag = '<img src="' . htmlspecialchars($data['firma_url_public'], ENT_QUOTES, 'UTF-8') . '" alt="Firma" width="520" style="width:100%; max-width:520px; height:auto; display:block; margin:12px auto 0;">';
            }
            if ($firmaImgTag || $encargadoNombre) {
                $firmaHtml = '<div style="margin-top:18px; text-align:center;">'
                    . ($encargadoNombre ? '<div style="font-weight:bold;margin-bottom:6px;">' . htmlspecialchars($encargadoNombre, ENT_QUOTES, 'UTF-8') . '</div>' : '')
                    . $firmaImgTag
                    . '</div>';
            }

            // ====== HTML final ======
            // Cambios solicitados:
            // - Título del evento: NEGRO, NEGRITA, MÁS GRANDE y CENTRADO
            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:700px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden'>
                {$bannerImg}

                <div style='padding:24px 24px 0 24px'>
                  <h1 style='margin:0 0 8px 0; color:#000000; font-size:24px; line-height:1.35; font-weight:700; text-align:center;'>" . $nombreEvento . "</h1>
                  <p style='margin:0 0 16px 0; text-align:center; color:#6b7280; font-size:14px;'>" . $modalidadTxt . ($resumenFechas ? " · " . $resumenFechas : "") . "</p>
                </div>

                <div style='padding:0 28px 28px 28px; font-size:15px; color:#222'>
                  {$encabezado}

                  <p style='margin:0 0 10px'>Gracias por sumarse a este espacio de aprendizaje. Su inscripción ha sido confirmada. A continuación, encontrará los detalles del evento:</p>
                <p style='margin:0 0 12px'><strong>Modalidad:</strong> " . $modalidadTxt . "</p>
                " . $asistHtml . "

                  <p style='margin:0 0 6px'><strong>📌 Fecha y Horario</strong></p>
                  " . ($resumenFechas ? "<p style='margin:0 0 8px'>📅 " . $resumenFechas . "</p>" : "") . "
                <div style='margin:8px 0 18px; padding:14px; background:#fafafa; border:1px solid #eee; border-radius:10px;'>
                  " . $detalleHorarioFinal . "
                </div>

                  " . $lugarHtml . "

                  <p style='margin:14px 0 8px'><strong>🔹 Tenga en cuenta:</strong></p>
                  <ul style='margin:8px 0 18px; padding-left:18px; color:#333'>
                    <li>Para garantizar su reserva, por favor envíe con anticipación el soporte de pago o autorización correspondiente.</li>" .
                (!empty($fechaLimiteTxt) ? "<li>Confirme su asistencia antes del <strong>{$fechaLimiteTxt}</strong>.</li>" : "") .
                "<li>Un día antes del evento recibirá el cronograma detallado.</li>
                  </ul>

                  " . $btnWhatsapp . "

                  <p style='margin-top:24px'>¡Nos vemos pronto!</p>
                  <p style='margin:0'>Cordialmente,</p>
                  " . $firmaHtml . "

                  " . ($pdfAviso ? $pdfAviso : "") . "

                  <p style='font-size:12px;color:#888;margin-top:24px'>Este es un mensaje automático, por favor no responder.</p>
                </div>
                <div style='background:#f1f1f1;text-align:center;padding:12px;font-size:12px;color:#888'>
                  F&C Consultores © " . date('Y') . "
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);

            // AltBody robusto
            $altNombreEvento = $data['nombre_evento'] ?? '';
            $altModalidad = $data['modalidad'] ?? '';
            $altResumen = strip_tags($resumenFechas);
            $mail->AltBody = "Inscripción confirmada a {$altNombreEvento} ({$altModalidad}). Fechas: {$altResumen}." . $altAsist;

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
            $mail->Port = 25;              // sin TLS explícito
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
            $smtpUser = 'it@fycconsultores.com';
            $smtpPass = 'ecym cwbl dfkg maea'; // APP PASSWORD
            $fromEmail = 'certificados@fycconsultores.com';
            $fromName = 'F&C Consultores';

            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;

            // Encabezados
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($correoDestino);

            // Reply-To al correo del inscrito (si viene)
            $replyTo = !empty($data['email_corporativo']) ? $data['email_corporativo']
                : (!empty($data['email_personal']) ? $data['email_personal'] : null);
            if (!empty($replyTo)) {
                $mail->addReplyTo($replyTo, isset($data['inscrito_nombre']) ? $data['inscrito_nombre'] : '');
            }

            // ====== Adjuntar soporte si existe (ruta relativa tipo uploads/soportes/xxx.pdf) ======
            $hayAdjunto = false;
            $rel = '';
            if (!empty($data['soporte_pago'])) {
                $rel = $data['soporte_pago'];
            } elseif (!empty($data['soporte_rel'])) { // por si usas otra clave
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

            // Campos seguros
            $ev = isset($data['nombre_evento']) ? htmlspecialchars($data['nombre_evento'], ENT_QUOTES, 'UTF-8') : '';
            $mod = isset($data['modalidad']) ? htmlspecialchars($data['modalidad'], ENT_QUOTES, 'UTF-8') : '';
            $sum = isset($data['resumen_fechas']) ? $data['resumen_fechas'] : '';
            $now = date('Y-m-d H:i:s');

            $mail->Subject = 'Nueva inscripción – ' . $ev;

            // Datos del inscrito
            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:700px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden'>
                <div style='padding:22px 26px; font-size:14px; color:#222'>
                  <h2 style='margin:0 0 12px;color:#d32f57'>Nueva inscripción recibida</h2>

                  <p style='margin:0 0 8px'><strong>Evento:</strong> {$ev}</p>
                  <p style='margin:0 0 8px'><strong>Modalidad:</strong> {$mod}</p>" .
                (!empty($sum) ? "<p style='margin:0 0 12px'><strong>Fechas:</strong> {$sum}</p>" : "") . "

                  <div style='margin:14px 0; padding:12px; background:#fafafa; border:1px solid #eee; border-radius:10px;'>
                    <p style='margin:0 0 8px'><strong>Tipo de inscripción:</strong> " . htmlspecialchars(isset($data['tipo_inscripcion']) ? $data['tipo_inscripcion'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Nombre:</strong> " . htmlspecialchars(isset($data['inscrito_nombre']) ? $data['inscrito_nombre'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Cédula:</strong> " . htmlspecialchars(isset($data['cedula']) ? $data['cedula'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Cargo:</strong> " . htmlspecialchars(isset($data['cargo']) ? $data['cargo'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Entidad:</strong> " . htmlspecialchars(isset($data['entidad']) ? $data['entidad'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Ciudad:</strong> " . htmlspecialchars(isset($data['ciudad']) ? $data['ciudad'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Celular:</strong> " . htmlspecialchars(isset($data['celular']) ? $data['celular'] : '', ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin:0 0 8px'><strong>Email corporativo:</strong> " . htmlspecialchars(isset($data['email_corporativo']) ? $data['email_corporativo'] : '', ENT_QUOTES, 'UTF-8') . "</p>" .
                (!empty($data['email_personal']) ? "<p style='margin:0 0 8px'><strong>Email personal:</strong> " . htmlspecialchars($data['email_personal'], ENT_QUOTES, 'UTF-8') . "</p>" : "") .
                (!empty($data['medio']) ? "<p style='margin:0 0 8px'><strong>Medio por el que se enteró:</strong> " . htmlspecialchars($data['medio'], ENT_QUOTES, 'UTF-8') . "</p>" : "") .
                "</div>

                  " . ($hayAdjunto ? "<p style='margin:10px 0 0; color:#0a7'>📎 Se adjuntó el Soporte de Asistencia.</p>" : "<p style='margin:10px 0 0; color:#a70'>⚠️ El inscrito no adjuntó soporte.</p>") . "

                  <p style='margin:10px 0 0; font-size:12px; color:#666'>Recibido: {$now}</p>
                </div>
                <div style='background:#f1f1f1;text-align:center;padding:12px;font-size:12px;color:#888'>
                  F&C Consultores © " . date('Y') . "
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);
            $mail->AltBody = "Nueva inscripción en {$ev}. Inscrito: " . (isset($data['inscrito_nombre']) ? $data['inscrito_nombre'] : '') . " - " . (isset($data['email_corporativo']) ? $data['email_corporativo'] : '') . ($hayAdjunto ? ' (se adjunta soporte)' : ' (sin soporte)');

            return $mail->send();

        } catch (Exception $e) {
            error_log('No se pudo enviar AVISO a comercial: ' . $mail->ErrorInfo);
            return false;
        }
    }

}
