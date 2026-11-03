<?php
/* POST { id_usuario, correo } -> guarda el correo y envía un CÓDIGO de 6 dígitos (vence en 15 min). */
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';
require_once 'enviar_correo.php';

$in     = json_decode(file_get_contents('php://input'), true);
$id     = (int)($in['id_usuario'] ?? 0);
$correo = strtolower(trim($in['correo'] ?? ''));

if (!$id || !preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/', $correo)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Correo de Gmail inválido.']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT correo, correo_verificado, codigo_expira FROM usuario WHERE id_usuario = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) { http_response_code(404); echo json_encode(['ok' => false, 'mensaje' => 'Usuario no encontrado.']); exit; }

    // Una cuenta con correo ya verificado no puede cambiarlo por esta vía.
    if ($u['correo_verificado'] && $u['correo'] && $u['correo'] !== $correo) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'mensaje' => 'Esta cuenta ya tiene otro correo verificado.']);
        exit;
    }
    if ($u['correo_verificado'] && $u['correo'] === $correo) {
        echo json_encode(['ok' => true, 'ya_verificado' => true]);
        exit;
    }
    // Máximo 1 envío por minuto (el código vive 15 min).
    if ($u['codigo_expira'] && strtotime($u['codigo_expira']) > time() + 14 * 60) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'mensaje' => 'Espera un minuto antes de pedir otro código.']);
        exit;
    }

    $codigo = (string)random_int(100000, 999999);
    $pdo->prepare(
        "UPDATE usuario SET correo = ?, correo_verificado = 0, codigo_verificacion = ?,
                codigo_expira = DATE_ADD(NOW(), INTERVAL 15 MINUTE), codigo_intentos = 0
         WHERE id_usuario = ?"
    )->execute([$correo, hash('sha256', $codigo), $id]);

    $html = html_correo('Confirma tu correo',
        '<p>¡Gracias por unirte! Tu código de verificación es:</p>'
      . '<p style="font-size:34px;letter-spacing:8px;font-weight:bold;color:#4E6B44;margin:12px 0;">' . $codigo . '</p>'
      . '<p>Vence en 15 minutos.</p>');

    if (!enviar_correo($correo, 'Tu código de verificación de Huellitas', $html)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'mensaje' => 'El servidor no pudo enviar el correo.']);
        exit;
    }
    echo json_encode(['ok' => true]);

} catch (\PDOException $e) {
    http_response_code($e->getCode() === '23000' ? 409 : 500);
    echo json_encode(['ok' => false, 'mensaje' => $e->getCode() === '23000'
        ? 'Ese correo ya está registrado en otra cuenta.' : 'No se pudo enviar el código.']);
}
