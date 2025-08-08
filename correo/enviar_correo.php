<?php
// /correo/enviar_correo.php
require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CorreoDenuncia {
    /**
     * Enviar confirmación de inscripción a un evento
     *
     * @param string $nombre Nombre del inscrito
     * @param string $correo Correo del inscrito (corporativo)
     * @param array  $data   Datos del evento:
     *   - nombre_evento
     *   - modalidad (Presencial/Virtual)
     *   - fecha_limite (YYYY-MM-DD)
     *   - resumen_fechas (p.ej. "4, 5 y 6 de septiembre de 2025")
     *   - detalle_horario (HTML con días/horas)
     *   - url_imagen (ruta absoluta del archivo en el servidor para incrustar)
     *   - adjunto_pdf (ruta absoluta o null)
     *   - lugar (opcional si es presencial)
     * @return bool
     */
    public function sendConfirmacionInscripcion($nombre, $correo, array $data) {
        $mail = new PHPMailer(true);
        try {
            // SMTP
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = "smtp-relay.gmail.com";
            $mail->Port = 25;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = false;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];

            // Credenciales / remitente (las mismas que usas en el otro proyecto)
            $mail->From     = "certificados@fycconsultores.com";
            $mail->FromName = "F&C Consultores";
            $mail->Username = "it@fycconsultores.com";
            $mail->Password = "ecym cwbl dfkg maea";

            $mail->CharSet = 'UTF-8';
            $mail->Subject = "Confirmación de inscripción – " . $data['nombre_evento'];
            $mail->addAddress($correo, $nombre);

            // Incrustar imagen del evento (si existe)
            $cidImg = null;
            if (!empty($data['url_imagen']) && file_exists($data['url_imagen'])) {
                $cidImg = 'eventimg' . md5($data['url_imagen']);
                $mail->addEmbeddedImage($data['url_imagen'], $cidImg);
            }

            // Adjuntar PDF (solo si nos pasaron ruta y existe)
            if (!empty($data['adjunto_pdf']) && file_exists($data['adjunto_pdf'])) {
                $mail->addAttachment($data['adjunto_pdf'], basename($data['adjunto_pdf']));
            }

            // Construir HTML
            $bannerImg = $cidImg ? "<img src='cid:$cidImg' alt='Evento' style='width:100%; max-height:260px; object-fit:cover; border-radius:12px 12px 0 0'>" : "";

            $lugarHtml = "";
            if (!empty($data['lugar'])) {
                $lugarHtml = "
                    <p><strong>🔹 Lugar:</strong><br>
                    {$data['lugar']}</p>
                ";
            }

            $pdfAviso = !empty($data['adjunto_pdf'])
                ? "<p style='margin:0'>📎 Se adjunta la guía hotelera en PDF.</p>"
                : "";

            $modalidadTxt = htmlspecialchars($data['modalidad']);

            $html = "
            <div style='font-family:Arial, Helvetica, sans-serif;background:#f6f7fb;padding:24px'>
              <div style='max-width:640px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden'>
                " . $bannerImg . "
                <div style='padding:24px'>
                  <h2 style='margin:0 0 8px;color:#942934'>¡Inscripción confirmada!</h2>
                  <p style='margin:0 0 16px;color:#333'>Hola <strong>$nombre</strong>, gracias por inscribirte al:</p>
                  <h3 style='margin:0 0 12px;color:#d32f57'>{$data['nombre_evento']}</h3>

                  <p style='margin:0 0 12px'><strong>Modalidad:</strong> $modalidadTxt</p>

                  <p style='margin:0 0 6px'><strong>📌 Fecha y Horario:</strong></p>
                  <p style='margin:0'><span>📅 {$data['resumen_fechas']}</span></p>
                  <div style='margin:8px 0 16px; padding:12px; background:#fafafa; border:1px solid #eee; border-radius:10px;'>
                    {$data['detalle_horario']}
                  </div>

                  $lugarHtml

                  <p><strong>🔹 Tenga en cuenta:</strong></p>
                  <ul style='margin:8px 0 16px; padding-left:18px; color:#333'>
                    <li>Para garantizar su reserva, por favor envíe con anticipación el soporte de pago o autorización correspondiente.</li>
                    <li>Confirme su asistencia antes del <strong>" . date('d/m/Y', strtotime($data['fecha_limite'])) . "</strong>.</li>
                    <li>Un día antes del evento recibirá el cronograma detallado.</li>
                  </ul>

                  $pdfAviso

                  <p style='font-size:12px;color:#888;margin-top:24px'>Este es un mensaje automático, por favor no responder.</p>
                </div>
                <div style='background:#f1f1f1;text-align:center;padding:10px;font-size:12px;color:#888'>
                  F&C Consultores © " . date('Y') . "
                </div>
              </div>
            </div>";

            $mail->MsgHTML($html);
            $mail->AltBody = "Inscripción confirmada a {$data['nombre_evento']} ({$data['modalidad']}). Fechas: {$data['resumen_fechas']}";

            return $mail->send();
        } catch (Exception $e) {
            error_log("No se pudo enviar correo: " . $mail->ErrorInfo);
            return false;
        }
    }
}
