<?php
/* =========================================================
   gestor_login.php — login del footer (rol GESTOR_ALBERGUE)
   Contrato esperado por script.js:
     POST /gestor_login.php  body: { nombre_real, contrasena }
     éxito: { ok:true, usuario:{...}, albergue:{ id_albergue, nombre, zona } }
     error: { ok:false, mensaje:"..." }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$nombre_real = trim($input['nombre_real'] ?? '');
$contrasena  = $input['contrasena'] ?? '';

if ($nombre_real === '' || $contrasena === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM usuario
         WHERE TRIM(nombre_real) = :nombre_real
           AND rol = 'GESTOR_ALBERGUE'
         LIMIT 1"
    );
    $stmt->execute(['nombre_real' => $nombre_real]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($contrasena, $usuario['contrasena'])) {
        http_response_code(401);
        echo json_encode(["ok" => false, "mensaje" => "Nombre real o contraseña incorrectos."]);
        exit;
    }

    unset($usuario['contrasena']);

    $albergue = null;
    if ($usuario['id_albergue']) {
        $stmtAlb = $pdo->prepare("SELECT id_albergue, nombre, zona FROM albergue WHERE id_albergue = :id");
        $stmtAlb->execute(['id' => $usuario['id_albergue']]);
        $albergue = $stmtAlb->fetch() ?: null;
    }

    if (!$albergue) {
        // Un gestor sin albergue asociado no debería poder operar el panel.
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Tu cuenta de gestor no tiene un albergue asociado. Contacta al administrador."]);
        exit;
    }

    echo json_encode([
        "ok" => true,
        "usuario" => $usuario,
        "albergue" => $albergue
    ]);

} catch (\PDOException $e) {
    error_log("gestor_login.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error en el servidor al iniciar sesión."]);
}
