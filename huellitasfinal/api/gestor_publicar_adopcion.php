<?php
/* =========================================================
   gestor_publicar_adopcion.php
   POST body: { id_mascota, id_albergue, descripcion }
   éxito: { ok:true, adopcion:{...} }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_mascota  = isset($input['id_mascota'])  ? (int) $input['id_mascota']  : 0;
$id_albergue = isset($input['id_albergue']) ? (int) $input['id_albergue'] : 0;
$descripcion = trim($input['descripcion'] ?? '');

if (!$id_mascota || !$id_albergue || $descripcion === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

try {
    // La mascota debe pertenecer a este albergue.
    $stmtCheck = $pdo->prepare(
        "SELECT 1 FROM mascota_rescatada WHERE id_mascota = :id_mascota AND id_albergue = :id_albergue"
    );
    $stmtCheck->execute(['id_mascota' => $id_mascota, 'id_albergue' => $id_albergue]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "mensaje" => "Esa mascota no pertenece a tu albergue."]);
        exit;
    }

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE mascota SET estado = 'EN_ADOPCION' WHERE id_mascota = :id")
        ->execute(['id' => $id_mascota]);

    // mascota_adopcion: fila única por mascota (PK id_mascota). La creamos si no existe.
    $pdo->prepare(
        "INSERT INTO mascota_adopcion (id_mascota, id_albergue, fecha_traspaso)
         VALUES (:id_mascota, :id_albergue, CURDATE())
         ON DUPLICATE KEY UPDATE id_albergue = VALUES(id_albergue)"
    )->execute(['id_mascota' => $id_mascota, 'id_albergue' => $id_albergue]);

    // adopcion: UNIQUE(id_mascota). Si ya existe una fila (ej. estado CANCELADA
    // de una publicación anterior), la reactivamos en vez de duplicarla.
    $stmtExist = $pdo->prepare("SELECT id_adopcion FROM adopcion WHERE id_mascota = :id_mascota");
    $stmtExist->execute(['id_mascota' => $id_mascota]);
    $existente = $stmtExist->fetch();

    if ($existente) {
        $id_adopcion = (int) $existente['id_adopcion'];
        $pdo->prepare(
            "UPDATE adopcion SET estado = 'DISPONIBLE', descripcion = :descripcion,
                    fecha_publicacion = CURDATE(), fecha_aprobacion = NULL,
                    fecha_inicio_seguimiento = NULL, fecha_fin_seguimiento = NULL,
                    id_albergue = :id_albergue
             WHERE id_adopcion = :id"
        )->execute(['descripcion' => $descripcion, 'id_albergue' => $id_albergue, 'id' => $id_adopcion]);
    } else {
        $pdo->prepare(
            "INSERT INTO adopcion (descripcion, fecha_publicacion, estado, id_mascota, id_albergue)
             VALUES (:descripcion, CURDATE(), 'DISPONIBLE', :id_mascota, :id_albergue)"
        )->execute(['descripcion' => $descripcion, 'id_mascota' => $id_mascota, 'id_albergue' => $id_albergue]);
        $id_adopcion = (int) $pdo->lastInsertId();
    }

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "adopcion" => [
            "id_adopcion" => $id_adopcion,
            "id_mascota"  => $id_mascota,
            "descripcion" => $descripcion,
            "estado"      => "DISPONIBLE",
        ]
    ]);

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("gestor_publicar_adopcion.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo publicar la adopción."]);
}
