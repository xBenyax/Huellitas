<?php
/* =========================================================
   gestor_marcar_finalizada.php
   POST body: { id_adopcion }
   éxito: { ok:true, adopcion:{...} }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);
$id_adopcion = isset($input['id_adopcion']) ? (int) $input['id_adopcion'] : 0;

if (!$id_adopcion) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Falta id_adopcion."]);
    exit;
}

try {
    $stmtAdop = $pdo->prepare("SELECT id_mascota FROM adopcion WHERE id_adopcion = :id");
    $stmtAdop->execute(['id' => $id_adopcion]);
    $adopcion = $stmtAdop->fetch();
    if (!$adopcion) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Adopción no encontrada."]);
        exit;
    }

    $pdo->beginTransaction();

    $pdo->prepare(
        "UPDATE adopcion SET estado = 'ADOPTADA', fecha_fin_seguimiento = CURDATE() WHERE id_adopcion = :id"
    )->execute(['id' => $id_adopcion]);

    $pdo->prepare("UPDATE mascota SET estado = 'ADOPTADA' WHERE id_mascota = :id")
        ->execute(['id' => $adopcion['id_mascota']]);

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "adopcion" => [
            "id_adopcion" => $id_adopcion,
            "estado"      => "ADOPTADA",
        ]
    ]);

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("gestor_marcar_finalizada.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo marcar la adopción como finalizada."]);
}
