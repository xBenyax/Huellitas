<?php
/* POST { id_usuario, codigo } -> confirma el correo si el código es correcto y no venció. */
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$in     = json_decode(file_get_contents('php://input'), true);
$id     = (int)($in['id_usuario'] ?? 0);
$codigo = trim($in['codigo'] ?? '');

if (!$id || !preg_match('/^\d{6}$/', $codigo)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Ingresa el código de 6 dígitos.']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT correo, correo_verificado, codigo_verificacion, codigo_expira, codigo_intentos FROM usuario WHERE id_usuario = ?");
    $st->execute([$id]);
    $u = $st->fetch();

    if (!$u || !$u['codigo_verificacion']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'No hay un código pendiente. Pide uno nuevo.']);
        exit;
    }
    if (strtotime($u['codigo_expira']) < time() || (int)$u['codigo_intentos'] >= 5) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'El código venció o se agotaron los intentos. Pide uno nuevo.']);
        exit;
    }
    if (!hash_equals($u['codigo_verificacion'], hash('sha256', $codigo))) {
        $pdo->prepare("UPDATE usuario SET codigo_intentos = codigo_intentos + 1 WHERE id_usuario = ?")->execute([$id]);
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'Código incorrecto.']);
        exit;
    }
    $pdo->prepare("UPDATE usuario SET correo_verificado = 1, codigo_verificacion = NULL, codigo_expira = NULL, codigo_intentos = 0 WHERE id_usuario = ?")->execute([$id]);
    echo json_encode(['ok' => true, 'correo' => $u['correo']]);

} catch (\PDOException $e) {
    error_log('verificar_codigo.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo verificar el código.']);
}
