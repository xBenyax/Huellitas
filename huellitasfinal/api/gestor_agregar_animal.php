<?php
/* =========================================================
   gestor_agregar_animal.php — Gestor rescata/agrega un animal
   POST body: { id_albergue, nombre_mascota, foto, especie, edad, genero, id_espacio }
   éxito: { ok:true, mascota:{...} }
   error: { ok:false, mensaje:"Ese espacio ya está a su capacidad máxima." }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_albergue    = isset($input['id_albergue']) ? (int) $input['id_albergue'] : 0;
$nombre_mascota = trim($input['nombre_mascota'] ?? '');
$foto           = $input['foto'] ?? null;
$especie        = strtoupper(trim($input['especie'] ?? ''));
$edad           = $input['edad'] ?? null;
$genero         = strtoupper(trim($input['genero'] ?? ''));
$id_espacio     = isset($input['id_espacio']) ? (int) $input['id_espacio'] : 0;

if (!$id_albergue || $nombre_mascota === '' || !in_array($especie, ['PERRO','GATO']) ||
    !in_array($genero, ['MACHO','HEMBRA']) || $edad === null || !$id_espacio) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

try {
    // El espacio debe pertenecer a este albergue.
    $stmtEsp = $pdo->prepare("SELECT capacidad FROM espacio WHERE id_espacio = :id AND id_albergue = :id_albergue");
    $stmtEsp->execute(['id' => $id_espacio, 'id_albergue' => $id_albergue]);
    $espacio = $stmtEsp->fetch();
    if (!$espacio) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Espacio no encontrado en este albergue."]);
        exit;
    }

    $stmtOcup = $pdo->prepare("SELECT COUNT(*) FROM mascota_rescatada WHERE id_espacio = :id_espacio");
    $stmtOcup->execute(['id_espacio' => $id_espacio]);
    $ocupacion = (int) $stmtOcup->fetchColumn();

    if ($ocupacion >= (int) $espacio['capacidad']) {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Ese espacio ya está a su capacidad máxima."]);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO mascota (nombre_mascota, foto, especie, edad, genero, estado)
         VALUES (:nombre_mascota, :foto, :especie, :edad, :genero, 'ACTIVA')"
    );
    $stmt->execute([
        'nombre_mascota' => $nombre_mascota,
        'foto'           => $foto,
        'especie'        => $especie,
        'edad'           => $edad,
        'genero'         => $genero,
    ]);
    $id_mascota = (int) $pdo->lastInsertId();

    $stmtResc = $pdo->prepare(
        "INSERT INTO mascota_rescatada (id_mascota, id_albergue, id_espacio)
         VALUES (:id_mascota, :id_albergue, :id_espacio)"
    );
    $stmtResc->execute([
        'id_mascota'  => $id_mascota,
        'id_albergue' => $id_albergue,
        'id_espacio'  => $id_espacio,
    ]);

    // Ficha de salud inicial: todo animal nuevo entra "En observación"
    // hasta que el gestor lo dé de alta desde la pestaña Salud.
    $stmtSalud = $pdo->prepare(
        "INSERT INTO salud (id_mascota, estado, tratamientos_vigentes)
         VALUES (:id_mascota, 'EN_OBSERVACION', '[]')"
    );
    $stmtSalud->execute(['id_mascota' => $id_mascota]);

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "mascota" => [
            "id_mascota"     => $id_mascota,
            "nombre_mascota" => $nombre_mascota,
            "especie"        => strtolower($especie),
            "genero"         => strtolower($genero),
            "edad"           => (int) $edad,
        ]
    ]);

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("gestor_agregar_animal.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo agregar el animal."]);
}
