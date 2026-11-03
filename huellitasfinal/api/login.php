<?php
/* =========================================================
   login.php — login PÚBLICO (usuario PARTICULAR)
   Contrato esperado por script.js:
     POST /login.php  body: { nombre_real, contrasena }
     éxito: { ok:true, usuario:{ id_usuario, nombre_real, zona, rol, id_albergue } }
     error: { ok:false, mensaje:"..." }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

// IMPORTANTE: usar la MISMA base de datos que el resto del proyecto (Aiven),
// no una conexión propia a localhost. Antes login.php se conectaba a una BD
// distinta a la del resto de endpoints — por eso nunca encontraba usuarios.
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$nombre_real = trim($input['nombre_real'] ?? '');
$contrasena  = $input['contrasena'] ?? '';

if ($nombre_real === '' || $contrasena === '') {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Faltan datos obligatorios."
    ]);
    exit;
}

try {
    // rol <> 'GESTOR_ALBERGUE': un gestor NO puede entrar por este login público,
    // debe usar gestor_login.php (footer).
    $sql = "SELECT * FROM usuario
            WHERE TRIM(nombre_real) = :nombre_real
              AND rol <> 'GESTOR_ALBERGUE'
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['nombre_real' => $nombre_real]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        http_response_code(401);
        echo json_encode([
            "ok" => false,
            "mensaje" => "Nombre real o contraseña incorrectos."
        ]);
        exit;
    }

    if (!password_verify($contrasena, $usuario['contrasena'])) {
        http_response_code(401);
        echo json_encode([
            "ok" => false,
            "mensaje" => "Nombre real o contraseña incorrectos."
        ]);
        exit;
    }

    // Nunca devolver el hash de la contraseña al front.
    unset($usuario['contrasena'], $usuario['codigo_verificacion'], $usuario['codigo_expira'], $usuario['codigo_intentos']);

    echo json_encode([
        "ok" => true,
        "usuario" => $usuario
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "Error en el servidor al iniciar sesión."
    ]);
}