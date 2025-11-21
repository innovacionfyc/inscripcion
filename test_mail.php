<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp-relay.gmail.com';
    $mail->Port = 25; // como usas en el proyecto
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = false;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->Username = 'innovacionti@fycconsultores.com';
    $mail->Password = 'zicy idns chmv fmqr'; // tu app password

    $mail->setFrom('alerts@fycconsultores.com', 'F&C Consultores');
    $mail->addAddress('emgladino@gmail.com', 'Prueba');

    $mail->Subject = '✅ Prueba de envío PHPMailer';
    $mail->Body = 'Hola! Este es un correo de prueba enviado desde test_mail.php usando PHPMailer.';

    if ($mail->send()) {
        echo "✅ Correo enviado correctamente";
    } else {
        echo "❌ No se pudo enviar el correo";
    }

} catch (Exception $e) {
    echo "Error al enviar: {$mail->ErrorInfo}";
}
