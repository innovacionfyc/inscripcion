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
     * @param string $correo  Correo del inscrito (corporativo)
     * @param array  $data    Datos del evento:
     *  - nombre_evento
     *  - modalidad (Presencial|Virtual)
     *  - fecha_limite (YYYY-MM-DD)
     *  - resumen_fechas (ej. "4, 5 y 6 de septiembre de 2025")
     *  - detalle_horario (HTML)
     *  - url_imagen (ruta ABSOLUTA en el servidor para incrustar)  [opcional]
     *  - adjunto_pdf (ruta ABSOLUTA del PDF a adjuntar)            [opcional]
     *  - lugar (HTML opcional si es presencial)
     * @return bool true si envía, false si falla
     */
    public function sendConfirmacionInscripcion($nombre, $correo, array $data) {
        $mail = new PHPMailer(true);

        try {
            // ====== SMTP según tu proyecto anterior ======
            $mail->SMTPDebug  = 0;
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
            $mail->addReplyTo($fromEmail, $fromName); // por si responden

            // ====== Adjuntos / Embebidos ======
            // Imagen del evento embebida (si existe)
            $cidImg = null;
            if (!empty($data['url_imagen']) && is_file($data['url_imagen'])) {
                $cidImg = 'evento_' . md5($data['url_imagen']);
                // El segundo parámetro es el CID para usar en <img src="cid:...">
                $mail->addEmbeddedImage($data['url_imagen'], $cidImg);
            }

            // PDF adjunto (si existe)
            if (!empty($data['adjunto_pdf']) && is_file($data['adjunto_pdf'])) {
                $mail->addAttachment($data['adjunto_pdf'], basename($data['adjunto_pdf']));
            }

            // ====== Contenido ======
            $mail->isHTML(true);
            $asunto = 'Confirmación de inscripción – ' . (isset($data['nombre_evento']) ? $data['nombre_evento'] : '');
            $mail->Subject = $asunto;

            $bannerImg = $cidImg
                ? "<img src='cid:{$cidImg}' alt='Evento' style='width:100%; max-height:260px; object-fit:cover; border-radius:12px 12px 0 0'>"
                : '';

            $lugarHtml = !empty($data['lugar'])
                ? "<p><strong>🔹 Lugar:</strong><br>{$data['lugar']}</p>"
                : '';

            $pdfAviso  = (!empty($data['adjunto_pdf']) && is_file($data['adjunto_pdf']))
                ? "<p style='margin:0'>📎 Se adjunta la guía hotelera en PDF.</p>"
                : '';

            // Sanitiza mínimos
            $modalidadTxt = isset($data['modalidad']) ? htmlspecialchars($data['modalidad'], ENT_QUOTES, 'UTF-8') : '';
            $nombreEvento = isset($data['nombre_evento']) ? htmlspecialchars($data['nombre_evento'], ENT_QUOTES, 'UTF-8') : '';
            $resumenFechas= isset($data['resumen_fechas']) ? $data['resumen_fechas'] : '';
            $detalleHorario = isset($data['detalle_horario']) ? $data['detalle_horario'] : '';
            $fechaLimite = isset($data['fecha_limite']) ? $data['fecha_limite'] : '';

            $fechaLimiteTxt = '';
            if (!empty($fechaLimite)) {
                $ts = strtotime($fechaLimite);
                if ($ts !== false) {
                    $fechaLimiteTxt = date('d/m/Y', $ts);
                }
            }

            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:640px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden'>
                {$bannerImg}
                <div style='padding:24px'>
                  <h2 style='margin:0 0 8px;color:#942934'>¡Inscripción confirmada!</h2>
                  <p style='margin:0 0 16px;color:#333'>Hola <strong>".htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8')."</strong>, gracias por inscribirte al:</p>
                  <h3 style='margin:0 0 12px;color:#d32f57'>{$nombreEvento}</h3>
                  <p style='margin:0 0 12px'><strong>Modalidad:</strong> {$modalidadTxt}</p>

                  <p style='margin:0 0 6px'><strong>📌 Fecha y Horario:</strong></p>
                  <p style='margin:0'><span>📅 {$resumenFechas}</span></p>
                  <div style='margin:8px 0 16px; padding:12px; background:#fafafa; border:1px solid #eee; border-radius:10px;'>
                    {$detalleHorario}
                  </div>

                  {$lugarHtml}

                  <p><strong>🔹 Tenga en cuenta:</strong></p>
                  <ul style='margin:8px 0 16px; padding-left:18px; color:#333'>
                    <li>Para garantizar su reserva, por favor envíe con anticipación el soporte de pago o autorización correspondiente.</li>
                    ".(!empty($fechaLimiteTxt) ? "<li>Confirme su asistencia antes del <strong>{$fechaLimiteTxt}</strong>.</li>" : "")."
                    <li>Un día antes del evento recibirá el cronograma detallado.</li>
                  </ul>

                  {$pdfAviso}

                  <p style='font-size:12px;color:#888;margin-top:24px'>Este es un mensaje automático, por favor no responder.</p>
                </div>
                <div style='background:#f1f1f1;text-align:center;padding:10px;font-size:12px;color:#888'>
                  F&C Consultores © ".date('Y')."
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);
            $mail->AltBody = "Inscripción confirmada a {$nombreEvento} ({$modalidadTxt}). Fechas: {$resumenFechas}.";

            return $mail->send();

        } catch (Exception $e) {
            // Loguea el error exacto del servidor SMTP/PHPMailer
            error_log('No se pudo enviar correo: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
