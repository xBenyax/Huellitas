<?php
/* =========================================================
   gestor_crear_espacio.php — crear un canil/gatera en el albergue
   POST body: { id_albergue, tipo, numero, capacidad }
     - tipo: "PERROS" | "GATOS"
   éxito: { ok:true, espacio:{ id_espacio, tipo, numero, capacidad, ocupacion:0 } }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_albergue = isset($input['id_albergue']) ? (int) $input['id_albergue'] : 0;
$tipo        = strtoupper(trim($input['tipo'] ?? ''));
$numero      = isset($input['numero'])    ? (int) $input['numero']    : 0;
$capacidad   = isset($input['capacidad']) ? (int) $input['capacidad'] : 0;

if (!$id_albergue || !in_array($tipo, ['PERROS', 'GATOS']) || $numero <= 0 || $capacidad <= 0) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios o son inválidos."]);
    exit;
}

try {
    $stmtAlb = $pdo->prepare("SELECT 1 FROM albergue WHERE id_albergue = :id");
    $stmtAlb->execute(['id' => $id_albergue]);
    if (!$stmtAlb->fetch()) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Albergue no encontrado."]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO espacio (id_albergue, tipo, numero, capacidad)
         VALUES (:id_albergue, :tipo, :numero, :capacidad)"
    );
    $stmt->execute([
        'id_albergue' => $id_albergue,
        'tipo'        => $tipo,
        'numero'      => $numero,
        'capacidad'   => $capacidad,
    ]);
    $id_espacio = (int) $pdo->lastInsertId();

    echo json_encode([
        "ok" => true,
        "espacio" => [
            "id_espacio" => $id_espacio,
            "tipo"       => $tipo,
            "numero"     => $numero,
            "capacidad"  => $capacidad,
            "ocupacion"  => 0,
        ]
    ]);

} catch (\PDOException $e) {
    error_log("gestor_crear_espacio.php: " . $e->getMessage());
    http_response_code(500);
    $msg = "No se pudo crear el espacio.";
    if (stripos($e->getMessage(), "Duplicate") !== false) {
        $msg = "Ya existe un espacio con ese número en tu albergue.";
    }
    // DEBUG TEMPORAL: quitar "debug" antes de producción.
    echo json_encode(["ok" => false, "mensaje" => $msg, "debug" => $e->getMessage()]);
}
