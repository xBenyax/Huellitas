<?php
/* =========================================================
   salud.php — mascotas propias + su fila de salud
   GET /salud.php?id_usuario=ID&rol=
   éxito: { ok:true, mascotas:[{ id_mascota, nombre_mascota,
            especie(minúscula), edad("X años"),
            salud:{ id_salud, tipo_documento, foto_respaldo,
                    tratamientos_vigentes(JSON string) } }] }

   NOTA: "rol" llega en la query pero no cambia la lógica —
   tanto PARTICULAR como GESTOR_ALBERGUE pueden tener mascotas
   propias (mascota.id_usuario), el botón "Registrar mi mascota"
   está disponible para ambos en la vista Salud.
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
        "SELECT m.id_mascota, m.nombre_mascota, m.foto, m.especie, m.edad, m.estado,
                s.id_salud, s.tratamientos_vigentes,
                a.id_adopcion, a.estado AS adopcion_estado, a.descripcion AS adopcion_descripcion
         FROM mascota m
         LEFT JOIN salud s ON s.id_mascota = m.id_mascota
         LEFT JOIN adopcion a ON a.id_mascota = m.id_mascota
         WHERE m.id_usuario = :id_usuario
         ORDER BY m.id_mascota ASC"
    );
    $stmt->execute(['id_usuario' => $id_usuario]);
    $rows = $stmt->fetchAll();

    $mascotas = array_map(function ($r) {
        return [
            "id_mascota"     => (int) $r['id_mascota'],
            "nombre_mascota" => $r['nombre_mascota'],
            "foto"           => $r['foto'] ?? null,
            "especie"        => strtolower($r['especie']),
            "edad"           => edadTexto($r['edad']),
            "estado"         => $r['estado'],
            "salud"          => $r['id_salud'] ? [
                "id_salud"              => (int) $r['id_salud'],
                "tratamientos_vigentes" => $r['tratamientos_vigentes'],
            ] : null,
            "adopcion"       => $r['id_adopcion'] ? [
                "id_adopcion" => (int) $r['id_adopcion'],
                "estado"      => $r['adopcion_estado'],
                "descripcion" => $r['adopcion_descripcion'],
            ] : null,
        ];
    }, $rows);

    echo json_encode(["ok" => true, "mascotas" => $mascotas]);

} catch (\PDOException $e) {
    error_log("salud.php: " . $e->getMessage());
    echo json_encode(["ok" => true, "mascotas" => []]);
}
