<?php
// correo/enviar_correo.php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CorreoDenuncia {

    /**
     * Envía confirmación de inscripción a un evento (PHPMailer + Composer).
     *
     * @param string $nombre  Nombre del inscrito
     * @param string $correo  Correo del inscrito (corporativo o personal)
     * @param array  $data    Datos del evento:
     *  - (recomendado) evento_id                ← para buscar comercial (whatsapp/firma) si faltan
     *  - nombre_evento
     *  - modalidad (Presencial|Virtual)
     *  - fecha_limite (YYYY-MM-DD)
     *  - resumen_fechas (ej. "4, 5 y 6 de septiembre de 2025")
     *  - detalle_horario (HTML)
     *  - url_imagen (ruta ABSOLUTA en el servidor para incrustar)  [opcional]
     *  - url_imagen_public (URL pública opcional)
     *  - adjunto_pdf (ruta ABSOLUTA del PDF a adjuntar)            [opcional]
     *  - lugar (HTML opcional si es presencial)
     *  - entidad_empresa (para encabezado “Señores”)
     *  - nombre_inscrito  (para encabezado “Señores”)
     *  - whatsapp_numero  (solo dígitos; si no viene, se busca por evento→comercial)
     *  - firma_file       (ruta ABSOLUTA de imagen para CID)       [opcional]
     *  - firma_url_public (URL pública de imagen de firma)         [opcional]
     *  - encargado_nombre (texto debajo de firma; si no viene, se usa nombre del comercial)
     * @return bool true si envía, false si falla
     */
    public function sendConfirmacionInscripcion($nombre, $correo, array $data) {
        $mail = new PHPMailer(true);

        // ====== OPCIONAL: completar desde BD (evento → comercial) si faltan datos ======
        // Si NO te pasan whatsapp/firma/encargado, intentamos obtenerlos con evento_id
        $comWaDb = null;       // whatsapp del comercial (solo dígitos)
        $comFirmaPath = null;  // ruta relativa tipo "uploads/firmas/xxx.png"
        $comNombre = null;     // nombre comercial
        if (
            (!isset($data['whatsapp_numero']) || empty($data['whatsapp_numero']))
            || (empty($data['firma_file']) && empty($data['firma_url_public']))
            || (empty($data['encargado_nombre']))
        ) {
            if (!empty($data['evento_id'])) {
                // Conexión y helper de URL para armar firma pública si hace falta
                $conn = null;
                $baseUrlFn = null;
                // Conexión
                $tryConn = @require_once dirname(__DIR__) . '/db/conexion.php';
                if (isset($conn) && $conn instanceof mysqli) {
                    // Cargar comercial del evento
                    $sql = "SELECT u.nombre, u.whatsapp, u.firma_path
                            FROM eventos e
                            LEFT JOIN usuarios u ON u.id = e.comercial_user_id
                            WHERE e.id = ? LIMIT 1";
                    if ($st = $conn->prepare($sql)) {
                        $st->bind_param('i', $data['evento_id']);
                        if ($st->execute()) {
                            $st->bind_result($rNom, $rWa, $rFirma);
                            if ($st->fetch()) {
                                $comNombre   = $rNom ?: null;
                                $comWaDb     = $rWa ? preg_replace('/\D+/', '', $rWa) : null;
                                $comFirmaPath= $rFirma ?: null; // p.ej. "uploads/firmas/firma_123.png"
                            }
                        }
                        $st->close();
                    }
                }
                // Intentar función base_url si existe
                if (is_file(dirname(__DIR__) . '/config/url.php')) {
                    @require_once dirname(__DIR__) . '/config/url.php';
                    if (function_exists('base_url')) {
                        $baseUrlFn = 'base_url';
                    }
                }

                // Completar whatsapp si faltaba
                if (empty($data['whatsapp_numero']) && !empty($comWaDb)) {
                    $data['whatsapp_numero'] = $comWaDb;
                }

                // Completar nombre encargado si faltaba
                if (empty($data['encargado_nombre']) && !empty($comNombre)) {
                    $data['encargado_nombre'] = $comNombre;
                }

                // Completar firma si faltaba
                if (empty($data['firma_file']) && empty($data['firma_url_public']) && !empty($comFirmaPath)) {
                    // Ruta absoluta del archivo (si existe en el servidor)
                    $abs = dirname(__DIR__) . '/' . ltrim($comFirmaPath, '/');
                    if (is_file($abs)) {
                        $data['firma_file'] = $abs; // preferimos embebida por CID
                    } else {
                        // Como fallback, intentamos construir URL pública si tenemos base_url()
                        if ($baseUrlFn) {
                            $data['firma_url_public'] = call_user_func($baseUrlFn, $comFirmaPath);
                        }
                    }
                }
            }
        }
        // ====== FIN de completar desde BD ======

        try {
            // ====== SMTP (ajústalo a tu servidor) ======
            $mail->SMTPDebug   = 0;
            $mail->Debugoutput = 'html';
            $mail->isSMTP();
            $mail->Host       = 'smtp-relay.gmail.com';
            $mail->Port       = 25;              // sin TLS explícito
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = false;           // importante para smtp-relay.gmail.com:25
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                )
            );

            // Credenciales / remitente
            $smtpUser   = 'it@fycconsultores.com';
            $smtpPass   = 'ecym cwbl dfkg maea'; // APP PASSWORD
            $fromEmail  = 'certificados@fycconsultores.com';
            $fromName   = 'F&C Consultores';

            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;

            // Encabezados
            $mail->CharSet  = 'UTF-8';
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

            // ====== Contenido ======
            $mail->isHTML(true);
            $asunto = 'Confirmación de inscripción – ' . (isset($data['nombre_evento']) ? $data['nombre_evento'] : '');
            $mail->Subject = $asunto;

            $bannerImg = $cidImg
                ? "<img src='cid:{$cidImg}' alt='Evento' style='width:100%; max-height:260px; object-fit:cover; border-radius:12px 12px 0 0'>"
                : '';

            $lugarHtml = !empty($data['lugar'])
                ? "<p><strong>•  Lugar:</strong><br>{$data['lugar']}</p>"
                : '';

            $pdfAviso  = (!empty($data['adjunto_pdf']) && is_file($data['adjunto_pdf']))
                ? "<p style='margin:0'>📎 Se adjunta la guía hotelera en PDF.</p>"
                : '';

            // Sanitiza mínimos
            $modalidadTxt    = isset($data['modalidad']) ? htmlspecialchars($data['modalidad'], ENT_QUOTES, 'UTF-8') : '';
            $nombreEvento    = isset($data['nombre_evento']) ? htmlspecialchars($data['nombre_evento'], ENT_QUOTES, 'UTF-8') : '';
            $resumenFechas   = isset($data['resumen_fechas']) ? $data['resumen_fechas'] : '';
            $detalleHorario  = isset($data['detalle_horario']) ? $data['detalle_horario'] : '';
            $fechaLimite     = isset($data['fecha_limite']) ? $data['fecha_limite'] : '';

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
                $encabezado  = "<p style='margin:0 0 10px'><strong>Señores:</strong><br>";
                if (!empty($data['entidad_empresa'])) {
                    $encabezado .= htmlspecialchars($data['entidad_empresa'], ENT_QUOTES, 'UTF-8') . "<br>";
                }
                if (!empty($data['nombre_inscrito'])) {
                    $encabezado .= htmlspecialchars($data['nombre_inscrito'], ENT_QUOTES, 'UTF-8');
                }
                $encabezado .= "</p>";
            }

            // ====== Botón de WhatsApp (usa comercial del evento por defecto) ======
            // Prioridad: $data['whatsapp_numero'] → si no, lo que miramos en BD arriba
            $btnWhatsapp = '';
            $waNum = !empty($data['whatsapp_numero']) ? preg_replace('/\D/', '', $data['whatsapp_numero']) : '';
            if (empty($waNum) && !empty($comWaDb)) {
                $waNum = $comWaDb;
            }
            if (!empty($waNum)) {
                $txt = rawurlencode('Hola, tengo una consulta sobre el evento ' . (isset($data['nombre_evento']) ? $data['nombre_evento'] : ''));
                $btnWhatsapp = '<p style="margin:16px 0 0">
                    <a href="https://wa.me/'.$waNum.'?text='.$txt.'" target="_blank"
                       style="display:inline-block;background:#25D366;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold">
                      💬 Escribir por WhatsApp
                    </a>
                  </p>';
            }

            // ====== Firma (preferir embebida por CID; si no, URL pública) ======
            $firmaHtml   = '';
            $firmaImgTag = '';
            $encargadoNombre = !empty($data['encargado_nombre']) ? $data['encargado_nombre'] : ($comNombre ?: '');

            if (!empty($data['firma_file']) && is_file($data['firma_file'])) {
                $cidFirma = 'firma_' . md5($data['firma_file']);
                $mime = function_exists('mime_content_type') ? mime_content_type($data['firma_file']) : 'image/png';
                $mail->addEmbeddedImage($data['firma_file'], $cidFirma, basename($data['firma_file']), 'base64', $mime);
                $firmaImgTag = "<img src='cid:{$cidFirma}' alt='Firma' style='width:300px; height:auto; display:block; margin:10px auto 0;'>";
            } elseif (!empty($data['firma_url_public'])) {
                $firmaImgTag = '<img src="'.htmlspecialchars($data['firma_url_public'], ENT_QUOTES, 'UTF-8').'" alt="Firma" style="width:300px; height:auto; display:block; margin:10px auto 0;">';
            }

            if ($firmaImgTag || $encargadoNombre) {
                $firmaHtml = '<div style="margin-top:18px; text-align:center;">'
                           . ($encargadoNombre ? '<div style="font-weight:bold;margin-bottom:6px;">'.htmlspecialchars($encargadoNombre, ENT_QUOTES, 'UTF-8').'</div>' : '')
                           . $firmaImgTag
                           . '</div>';
            }

            // ====== HTML final ======
            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:700px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden'>
                {$bannerImg}
                <div style='padding:28px; font-size:15px; color:#222'>

                  {$encabezado}

                  <p style='margin:0 0 10px'>Gracias por sumarse a este espacio de aprendizaje. Su inscripción ha sido confirmada. A continuación, encontrará los detalles del evento:</p>

                  <h3 style='margin:0 0 14px;color:#d32f57;font-size:20px'>{$nombreEvento}</h3>

                  <p style='margin:0 0 12px'><strong>Modalidad:</strong> {$modalidadTxt}</p>

                  <p style='margin:0 0 6px'><strong>📌 Fecha y Horario</strong></p>
                  <p style='margin:0 0 8px'>📅 {$resumenFechas}</p>
                  <div style='margin:8px 0 18px; padding:14px; background:#fafafa; border:1px solid #eee; border-radius:10px;'>
                    {$detalleHorario}
                  </div>

                  {$lugarHtml}

                  <p style='margin:14px 0 8px'><strong>🔹 Tenga en cuenta:</strong></p>
                  <ul style='margin:8px 0 18px; padding-left:18px; color:#333'>
                    <li>Para garantizar su reserva, por favor envíe con anticipación el soporte de pago o autorización correspondiente.</li>".
                    (!empty($fechaLimiteTxt) ? "<li>Confirme su asistencia antes del <strong>{$fechaLimiteTxt}</strong>.</li>" : "").
                   "<li>Un día antes del evento recibirá el cronograma detallado.</li>
                  </ul>

                  {$btnWhatsapp}

                  <p style='margin-top:24px'>¡Nos vemos pronto!</p>
                  <p style='margin:0'>Cordialmente,</p>
                  {$firmaHtml}

                  ".($pdfAviso ? $pdfAviso : "")."

                  <p style='font-size:12px;color:#888;margin-top:24px'>Este es un mensaje automático, por favor no responder.</p>
                </div>
                <div style='background:#f1f1f1;text-align:center;padding:12px;font-size:12px;color:#888'>
                  F&C Consultores © ".date('Y')."
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);
            $mail->AltBody = "Inscripción confirmada a {$nombreEvento} ({$modalidadTxt}). Fechas: {$resumenFechas}.";

            return $mail->send();

        } catch (Exception $e) {
            error_log('No se pudo enviar correo: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
