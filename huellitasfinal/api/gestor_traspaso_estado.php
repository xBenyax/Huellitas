<?php
/* =========================================================
   gestor_traspaso_estado.php
   POST body: { id_traspaso, estado }  ("ACEPTADO" | "RECHAZADO" | "FINALIZADO")
   éxito: { ok:true, traspaso:{...} }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_traspaso = isset($input['id_traspaso']) ? (int) $input['id_traspaso'] : 0;
$estado      = strtoupper(trim($input['estado'] ?? ''));

if (!$id_traspaso || !in_array($estado, ['ACEPTADO', 'RECHAZADO', 'FINALIZADO'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Datos inválidos."]);
    exit;
}

try {
    $stmtTr = $pdo->prepare("SELECT * FROM traspaso WHERE id_traspaso = :id");
    $stmtTr->execute(['id' => $id_traspaso]);
    $traspaso = $stmtTr->fetch();
    if (!$traspaso) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Traspaso no encontrado."]);
        exit;
    }

    $pdo->beginTransaction();

    if ($estado === 'ACEPTADO') {
        $pdo->prepare(
            "UPDATE traspaso SET estado = 'ACEPTADO', fecha_aceptacion = CURDATE() WHERE id_traspaso = :id"
        )->execute(['id' => $id_traspaso]);
    } elseif ($estado === 'RECHAZADO') {
        $pdo->prepare("UPDATE traspaso SET estado = 'RECHAZADO' WHERE id_traspaso = :id")
            ->execute(['id' => $id_traspaso]);
    } else { // FINALIZADO
        $pdo->prepare("UPDATE traspaso SET estado = 'FINALIZADO' WHERE id_traspaso = :id")
            ->execute(['id' => $id_traspaso]);
        $pdo->prepare("UPDATE mascota SET estado = 'TRASPASADA' WHERE id_mascota = :id")
            ->execute(['id' => $traspaso['id_mascota_origen']]);
    }

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "traspaso" => [
            "id_traspaso" => $id_traspaso,
            "estado"      => $estado,
        ]
    ]);

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("gestor_traspaso_estado.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo actualizar el traspaso."]);
}
