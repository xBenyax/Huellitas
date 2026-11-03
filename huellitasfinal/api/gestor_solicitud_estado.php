<?php
/* =========================================================
   gestor_solicitud_estado.php
   POST body: { id_solicitud, estado }  ("APROBADA" | "RECHAZADA")
   éxito: { ok:true, solicitud:{...} }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';
require_once 'enviar_correo.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_solicitud = isset($input['id_solicitud']) ? (int) $input['id_solicitud'] : 0;
$estado       = strtoupper(trim($input['estado'] ?? ''));

if (!$id_solicitud || !in_array($estado, ['APROBADA', 'RECHAZADA'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Datos inválidos."]);
    exit;
}

try {
    $stmtSol = $pdo->prepare("SELECT * FROM solicitud_adopcion WHERE id_solicitud = :id");
    $stmtSol->execute(['id' => $id_solicitud]);
    $solicitud = $stmtSol->fetch();
    if (!$solicitud) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Solicitud no encontrada."]);
        exit;
    }

    // Solo se puede resolver una solicitud pendiente (evita correos duplicados).
    if ($solicitud['estado'] !== 'PENDIENTE') {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Esta solicitud ya fue resuelta."]);
        exit;
    }

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE solicitud_adopcion SET estado = :estado WHERE id_solicitud = :id")
        ->execute(['estado' => $estado, 'id' => $id_solicitud]);

    if ($estado === 'APROBADA') {
        $pdo->prepare(
            "UPDATE adopcion SET estado = 'EN_PROCESO',
                    fecha_aprobacion = CURDATE(), fecha_inicio_seguimiento = CURDATE()
             WHERE id_adopcion = :id_adopcion"
        )->execute(['id_adopcion' => $solicitud['id_adopcion']]);
    }

    $pdo->commit();

    // Correo al adoptante (si falla, la decisión del albergue ya quedó guardada).
    $correo_enviado = false;
    try {
        $stInfo = $pdo->prepare(
            "SELECT u.correo, u.nombre_real, m.nombre_mascota, al.nombre AS albergue
             FROM solicitud_adopcion s
             JOIN usuario  u  ON u.id_usuario   = s.id_usuario
             JOIN adopcion a  ON a.id_adopcion  = s.id_adopcion
             JOIN mascota  m  ON m.id_mascota   = a.id_mascota
             JOIN albergue al ON al.id_albergue = a.id_albergue
             WHERE s.id_solicitud = :id"
        );
        $stInfo->execute(['id' => $id_solicitud]);
        $info = $stInfo->fetch();
        if ($info && !empty($info['correo'])) {
            $e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
            if ($estado === 'APROBADA') {
                $asunto = '¡Tu solicitud de adopción fue aceptada! 🐾';
                $html = html_correo('¡Buenas noticias, ' . $e($info['nombre_real']) . '!',
                    '<p>El albergue <b>' . $e($info['albergue']) . '</b> <b style="color:#4E6B44;">aceptó</b> tu solicitud de adopción de <b>'
                  . $e($info['nombre_mascota']) . '</b>.</p><p>El albergue se pondrá en contacto contigo para los siguientes pasos.</p>');
            } else {
                $asunto = 'Respuesta a tu solicitud de adopción';
                $html = html_correo('Hola, ' . $e($info['nombre_real']),
                    '<p>El albergue <b>' . $e($info['albergue']) . '</b> <b style="color:#9B3B27;">no pudo aceptar</b> tu solicitud de adopción de <b>'
                  . $e($info['nombre_mascota']) . '</b> en esta ocasión.</p><p>No te desanimes: hay muchas huellitas esperando un hogar en Huellitas. 💚</p>');
            }
            $correo_enviado = enviar_correo($info['correo'], $asunto, $html);
        }
    } catch (\Throwable $mailErr) {
        error_log("gestor_solicitud_estado.php (correo): " . $mailErr->getMessage());
    }

    echo json_encode([
        "ok" => true,
        "correo_enviado" => $correo_enviado,
        "solicitud" => [
            "id_solicitud" => $id_solicitud,
            "estado"       => $estado,
        ]
    ]);

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("gestor_solicitud_estado.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo actualizar la solicitud."]);
}
