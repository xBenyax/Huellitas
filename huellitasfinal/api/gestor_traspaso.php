<?php
/* =========================================================
   gestor_traspaso.php — Gestor solicita traspaso de un animal
   POST body: { id_mascota_origen, id_albergue, id_albergue_destino }
   éxito: { ok:true, traspaso:{...} }

   VERSIÓN SIMPLE (sin tocar la BD): huellitas.sql solo tiene UNA
   columna de albergue en traspaso (id_albergue). En vez de agregar
   id_albergue_destino, reutilizamos esa columna para guardar el
   albergue DESTINO. El albergue de ORIGEN no se guarda aparte: se
   deduce después consultando mascota_rescatada por id_mascota_origen
   (ahí ya sabemos en qué albergue estaba el animal).
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_mascota_origen   = isset($input['id_mascota_origen'])   ? (int) $input['id_mascota_origen']   : 0;
$id_albergue_origen  = isset($input['id_albergue'])          ? (int) $input['id_albergue']          : 0;
$id_albergue_destino = isset($input['id_albergue_destino'])  ? (int) $input['id_albergue_destino']  : 0;

if (!$id_mascota_origen || !$id_albergue_origen || !$id_albergue_destino) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}
if ($id_albergue_origen === $id_albergue_destino) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "El albergue destino debe ser distinto al de origen."]);
    exit;
}

try {
    // La mascota debe pertenecer realmente al albergue que solicita el traspaso.
    $stmtCheck = $pdo->prepare(
        "SELECT 1 FROM mascota_rescatada WHERE id_mascota = :id_mascota AND id_albergue = :id_albergue"
    );
    $stmtCheck->execute(['id_mascota' => $id_mascota_origen, 'id_albergue' => $id_albergue_origen]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "mensaje" => "Esa mascota no pertenece a tu albergue."]);
        exit;
    }

    // Guardamos el DESTINO en la única columna de albergue que existe.
    $stmt = $pdo->prepare(
        "INSERT INTO traspaso (fecha_solicitud, estado, id_mascota_origen, id_albergue)
         VALUES (CURDATE(), 'SOLICITADO', :id_mascota_origen, :id_albergue_destino)"
    );
    $stmt->execute([
        'id_mascota_origen'   => $id_mascota_origen,
        'id_albergue_destino' => $id_albergue_destino,
    ]);
    $id_traspaso = (int) $pdo->lastInsertId();

    echo json_encode([
        "ok" => true,
        "traspaso" => [
            "id_traspaso" => $id_traspaso,
            "estado"      => "SOLICITADO",
        ]
    ]);

} catch (\PDOException $e) {
    error_log("gestor_traspaso.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo solicitar el traspaso."]);
}
