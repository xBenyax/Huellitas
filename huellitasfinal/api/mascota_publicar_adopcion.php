<?php
/* =========================================================
   mascota_publicar_adopcion.php
   Publica en adopción una mascota propia (mascota.id_usuario),
   SIN pasar por un albergue (id_albergue queda NULL en 'adopcion').

   POST body: { id_mascota, id_usuario, descripcion }
   éxito: { ok:true, adopcion:{...} }
   error: { ok:false, mensaje:"..." }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_mascota  = isset($input['id_mascota'])  ? (int) $input['id_mascota']  : 0;
$id_usuario  = isset($input['id_usuario'])  ? (int) $input['id_usuario']  : 0;
$descripcion = trim($input['descripcion'] ?? '');

if (!$id_mascota || !$id_usuario || $descripcion === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

try {
    // La mascota debe pertenecer a este usuario.
    $stmtCheck = $pdo->prepare(
        "SELECT 1 FROM mascota WHERE id_mascota = :id_mascota AND id_usuario = :id_usuario"
    );
    $stmtCheck->execute(['id_mascota' => $id_mascota, 'id_usuario' => $id_usuario]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "mensaje" => "Esa mascota no te pertenece."]);
        exit;
    }

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE mascota SET estado = 'EN_ADOPCION' WHERE id_mascota = :id")
        ->execute(['id' => $id_mascota]);

    // adopcion: UNIQUE(id_mascota). Si ya existe una fila (p.ej. CANCELADA
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
                    id_albergue = NULL
             WHERE id_adopcion = :id"
        )->execute(['descripcion' => $descripcion, 'id' => $id_adopcion]);
    } else {
        // id_albergue no se manda -> NULL. Si la columna es NOT NULL sin
        // default, MySQL lanza PDOException y lo capturamos más abajo
        // con un mensaje claro de qué corregir en la base de datos.
        $pdo->prepare(
            "INSERT INTO adopcion (descripcion, fecha_publicacion, estado, id_mascota)
             VALUES (:descripcion, CURDATE(), 'DISPONIBLE', :id_mascota)"
        )->execute(['descripcion' => $descripcion, 'id_mascota' => $id_mascota]);
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
    error_log("mascota_publicar_adopcion.php: " . $e->getMessage());
    http_response_code(500);

    $msg = "No se pudo publicar la adopción.";
    if (stripos($e->getMessage(), "id_albergue") !== false) {
        $msg = "La columna 'id_albergue' de la tabla 'adopcion' no admite valores nulos. "
             . "Corrige con: ALTER TABLE adopcion MODIFY id_albergue INT NULL;";
    }
    // DEBUG TEMPORAL: quitar "debug" antes de producción.
    echo json_encode(["ok" => false, "mensaje" => $msg, "debug" => $e->getMessage()]);
}
