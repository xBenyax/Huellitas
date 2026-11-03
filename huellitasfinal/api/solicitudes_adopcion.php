<?php
/* =========================================================
   solicitudes_adopcion.php — enviar solicitud de adopción
   Contrato esperado por script.js:
     POST /solicitudes_adopcion.php
     body: { id_usuario, id_adopcion }
     éxito: { ok:true, solicitud:{...} }
     error: { ok:false, mensaje:"Ya enviaste una solicitud para esta mascota." }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_usuario  = isset($input['id_usuario'])  ? (int) $input['id_usuario']  : 0;
$id_adopcion = isset($input['id_adopcion']) ? (int) $input['id_adopcion'] : 0;

if (!$id_usuario || !$id_adopcion) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

try {
    // La publicación debe seguir DISPONIBLE (evita solicitar algo ya en proceso/adoptado).
    $stmtAdop = $pdo->prepare("SELECT estado FROM adopcion WHERE id_adopcion = :id LIMIT 1");
    $stmtAdop->execute(['id' => $id_adopcion]);
    $adopcion = $stmtAdop->fetch();

    if (!$adopcion) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Esta publicación de adopción ya no existe."]);
        exit;
    }
    if ($adopcion['estado'] !== 'DISPONIBLE') {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Esta mascota ya no está disponible para adopción."]);
        exit;
    }

    // Respeta UNIQUE(id_usuario, id_adopcion) de la tabla.
    $stmtDup = $pdo->prepare(
        "SELECT COUNT(*) FROM solicitud_adopcion WHERE id_usuario = :id_usuario AND id_adopcion = :id_adopcion"
    );
    $stmtDup->execute(['id_usuario' => $id_usuario, 'id_adopcion' => $id_adopcion]);
    if ((int) $stmtDup->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Ya enviaste una solicitud para esta mascota."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO solicitud_adopcion (fecha_solicitud, estado, id_usuario, id_adopcion)
         VALUES (CURDATE(), 'PENDIENTE', :id_usuario, :id_adopcion)"
    );
    $stmt->execute(['id_usuario' => $id_usuario, 'id_adopcion' => $id_adopcion]);
    $id_solicitud = (int) $pdo->lastInsertId();

    echo json_encode([
        "ok" => true,
        "solicitud" => [
            "id_solicitud"    => $id_solicitud,
            "id_usuario"      => $id_usuario,
            "id_adopcion"     => $id_adopcion,
            "estado"          => "PENDIENTE",
            "fecha_solicitud" => date('Y-m-d'),
        ]
    ]);

} catch (\PDOException $e) {
    // Por si la carrera entre el SELECT y el INSERT choca con el UNIQUE de la BD.
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Ya enviaste una solicitud para esta mascota."]);
        exit;
    }
    error_log("solicitudes_adopcion.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo enviar la solicitud."]);
}
