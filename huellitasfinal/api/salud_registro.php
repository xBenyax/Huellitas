<?php
/* =========================================================
   salud_registro.php
   POST body: { id_salud, tratamientos_vigentes }  (string JSON)
   éxito: { ok:true, salud:{...} }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_salud              = isset($input['id_salud']) ? (int) $input['id_salud'] : 0;
$tratamientos_vigentes = $input['tratamientos_vigentes'] ?? null;

if (!$id_salud || $tratamientos_vigentes === null) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

// Validamos que sea JSON válido antes de guardarlo — script.js siempre
// manda JSON.stringify(lista), pero mejor no confiar ciegamente en el body.
json_decode($tratamientos_vigentes);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "El formato de los tratamientos no es válido."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE salud SET tratamientos_vigentes = :tratamientos WHERE id_salud = :id_salud"
    );
    $stmt->execute([
        'tratamientos' => $tratamientos_vigentes,
        'id_salud'     => $id_salud,
    ]);

    if ($stmt->rowCount() === 0) {
        // rowCount 0 puede ser "no existe" o "mismo valor ya guardado";
        // confirmamos existencia para no devolver un falso error.
        $check = $pdo->prepare("SELECT 1 FROM salud WHERE id_salud = :id");
        $check->execute(['id' => $id_salud]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(["ok" => false, "mensaje" => "Ficha de salud no encontrada."]);
            exit;
        }
    }

    echo json_encode([
        "ok" => true,
        "salud" => [
            "id_salud"              => $id_salud,
            "tratamientos_vigentes" => $tratamientos_vigentes,
        ]
    ]);

} catch (\PDOException $e) {
    error_log("salud_registro.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo guardar el registro."]);
}
