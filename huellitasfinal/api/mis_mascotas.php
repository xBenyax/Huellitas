<?php
/* =========================================================
   mis_mascotas.php — perfiles del usuario (máx. 5)
   GET /mis_mascotas.php?id_usuario=ID
   éxito: { ok:true, mascotas:[{ id_mascota, nombre_mascota,
            especie(minúscula), edad("X años"), genero(minúscula) }] }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

function edadTexto($edad) {
    $edad = (int) $edad;
    return $edad === 1 ? "1 año" : "$edad años";
}

$id_usuario = isset($_GET['id_usuario']) ? (int) $_GET['id_usuario'] : 0;
if (!$id_usuario) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Falta id_usuario."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id_mascota, nombre_mascota, foto, especie, edad, genero
         FROM mascota WHERE id_usuario = :id_usuario
         ORDER BY id_mascota ASC"
    );
    $stmt->execute(['id_usuario' => $id_usuario]);
    $rows = $stmt->fetchAll();

    $mascotas = array_map(fn($r) => [
        "id_mascota"     => (int) $r['id_mascota'],
        "nombre_mascota" => $r['nombre_mascota'],
        "foto"           => $r['foto'] ?? null,
        "especie"        => strtolower($r['especie']),
        "edad"           => edadTexto($r['edad']),
        "genero"         => strtolower($r['genero']),
    ], $rows);

    echo json_encode(["ok" => true, "mascotas" => $mascotas]);

} catch (\PDOException $e) {
    error_log("mis_mascotas.php: " . $e->getMessage());
    echo json_encode(["ok" => true, "mascotas" => []]);
}
