<?php
/* Ayuda compartida para enviar correos de Huellitas con PHPMailer (SMTP de Gmail).
   Instalación de PHPMailer (elige una):
     a) Composer, dentro de la carpeta api:  composer require phpmailer/phpmailer
     b) Manual: descarga PHPMailer y copia la carpeta src a  api/PHPMailer/src/
        (necesitas Exception.php, PHPMailer.php y SMTP.php)               */

function huellitas_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config_huellitas.php';
    return $cfg;
}

function html_correo(string $titulo, string $contenido): string {
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    return '<div style="background:#FAF3E6;padding:24px;font-family:Arial,sans-serif;color:#3F362B;">'
         . '<div style="max-width:520px;margin:auto;background:#FFFCF6;border-radius:18px;padding:28px;">'
         . '<div style="font-size:28px;">🐾 <b style="color:#33472F;">Huellitas</b></div>'
         . '<h2 style="color:#33472F;margin:14px 0 8px;">' . $t . '</h2>'
         . $contenido
         . '<p style="font-size:12px;color:#7A6F5E;margin-top:24px;">Un hogar para cada huellita. '
         . 'Si no esperabas este mensaje, puedes ignorarlo.</p>'
         . '</div></div>';
}

function enviar_correo(string $para, string $asunto, string $html): bool {
    $cfg = huellitas_config();
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    } elseif (is_file(__DIR__ . '/PHPMailer/src/PHPMailer.php')) {
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';
    } else {
        error_log('enviar_correo: PHPMailer no está instalado en api/.');
        return false;
    }
    try {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host       = $cfg['smtp_host'];
        $m->Port       = $cfg['smtp_port'];
        $m->SMTPAuth   = true;
        $m->Username   = $cfg['smtp_user'];
        $m->Password   = $cfg['smtp_pass'];
        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->CharSet    = 'UTF-8';
        $m->setFrom($cfg['smtp_user'], $cfg['from_name']);
        $m->addAddress($para);
        $m->isHTML(true);
        $m->Subject = $asunto;
        $m->Body    = $html;
        $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], "\n", $html))));
        $m->send();
        return true;
    } catch (\Throwable $e) {
        error_log('enviar_correo: ' . $e->getMessage());
        return false;
    }
}
