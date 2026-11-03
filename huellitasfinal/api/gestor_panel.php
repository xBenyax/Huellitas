<?php
/* =========================================================
   gestor_panel.php — panel del gestor (vista Espacios)
   GET /gestor_panel.php?id_albergue=ID
   éxito: { ok:true, albergue, perros, gatos, solicitudes, traspasos, espacios }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

const ESTADO_TEXTO = [
    'ACTIVA'         => 'Activa',
    'TRASPASADA'     => 'Traspasada',
    'EN_REGULACION'  => 'En regulación',
    'EN_ADOPCION'    => 'En adopción',
    'ADOPTADA'       => 'Adoptada',
];

function edadTexto($edad) {
    $edad = (int) $edad;
    return $edad === 1 ? "1 año" : "$edad años";
}

$id_albergue = isset($_GET['id_albergue']) ? (int) $_GET['id_albergue'] : 0;
if (!$id_albergue) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Falta id_albergue."]);
    exit;
}

try {
    $stmtAlb = $pdo->prepare("SELECT id_albergue, nombre, zona FROM albergue WHERE id_albergue = :id");
    $stmtAlb->execute(['id' => $id_albergue]);
    $albergue = $stmtAlb->fetch();
    if (!$albergue) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Albergue no encontrado."]);
        exit;
    }

    // Animales del albergue (via mascota_rescatada), con su adopción actual si tiene.
    $stmtAnimales = $pdo->prepare(
        "SELECT m.id_mascota, m.nombre_mascota, m.foto, m.especie, m.edad, m.genero, m.estado,
                a.id_adopcion, a.estado AS adopcion_estado, a.descripcion AS adopcion_descripcion,
                s.id_salud, s.tratamientos_vigentes
         FROM mascota_rescatada r
         JOIN mascota m ON m.id_mascota = r.id_mascota
         LEFT JOIN adopcion a ON a.id_mascota = m.id_mascota
         LEFT JOIN salud s ON s.id_mascota = m.id_mascota
         WHERE r.id_albergue = :id_albergue
         ORDER BY m.id_mascota DESC"
    );
    $stmtAnimales->execute(['id_albergue' => $id_albergue]);
    $animales = $stmtAnimales->fetchAll();

    $formatAnimal = function ($row) {
        $a = [
            "id_mascota"     => (int) $row['id_mascota'],
            "nombre_mascota" => $row['nombre_mascota'],
            "foto"           => $row['foto'],
            "especie"        => strtolower($row['especie']),
            "edad"           => edadTexto($row['edad']),
            "genero"         => strtolower($row['genero']),
            "estado"         => $row['estado'],
            "estado_texto"   => ESTADO_TEXTO[$row['estado']] ?? $row['estado'],
            "adopcion"       => null,
            "salud"          => $row['id_salud'] ? [
                "id_salud"              => (int) $row['id_salud'],
                "tratamientos_vigentes" => $row['tratamientos_vigentes'],
            ] : null,
        ];
        if (!empty($row['id_adopcion'])) {
            $a['adopcion'] = [
                "id_adopcion" => (int) $row['id_adopcion'],
                "estado"      => $row['adopcion_estado'],
                "descripcion" => $row['adopcion_descripcion'],
            ];
        }
        return $a;
    };

    $perros = array_values(array_map($formatAnimal, array_filter($animales, fn($r) => $r['especie'] === 'PERRO')));
    $gatos  = array_values(array_map($formatAnimal, array_filter($animales, fn($r) => $r['especie'] === 'GATO')));

    // Solicitudes de adopción sobre mascotas de este albergue.
    $stmtSol = $pdo->prepare(
        "SELECT s.id_solicitud, s.estado, s.fecha_solicitud, s.id_adopcion,
                m.nombre_mascota, u.nombre_real
         FROM solicitud_adopcion s
         JOIN adopcion a ON a.id_adopcion = s.id_adopcion
         JOIN mascota m ON m.id_mascota = a.id_mascota
         JOIN usuario u ON u.id_usuario = s.id_usuario
         WHERE a.id_albergue = :id_albergue
         ORDER BY s.fecha_solicitud DESC"
    );
    $stmtSol->execute(['id_albergue' => $id_albergue]);
    $solicitudes = array_map(fn($r) => [
        "id_solicitud"    => (int) $r['id_solicitud'],
        "estado"          => $r['estado'],
        "fecha_solicitud" => $r['fecha_solicitud'],
        "id_adopcion"     => (int) $r['id_adopcion'],
        "mascota"         => $r['nombre_mascota'],
        "usuario"         => $r['nombre_real'],
    ], $stmtSol->fetchAll());

    /* -----------------------------------------------------
       Traspasos donde este albergue participa.
       VERSIÓN SIMPLE (sin columna nueva): traspaso.id_albergue
       guarda el DESTINO (ver gestor_traspaso.php). El ORIGEN se
       deduce con mascota_rescatada por id_mascota_origen.
       "Salientes"  -> el origen (mascota_rescatada) es este albergue.
       "Entrantes"  -> traspaso.id_albergue (destino) es este albergue.
       ----------------------------------------------------- */
    $stmtTr = $pdo->prepare(
        "SELECT t.id_traspaso, t.estado, t.fecha_solicitud, m.nombre_mascota,
                albDestino.nombre AS destino_nombre, r.id_albergue AS id_albergue_origen
         FROM traspaso t
         JOIN mascota m ON m.id_mascota = t.id_mascota_origen
         LEFT JOIN mascota_rescatada r ON r.id_mascota = t.id_mascota_origen
         JOIN albergue albDestino ON albDestino.id_albergue = t.id_albergue
         WHERE t.id_albergue = :id_destino OR r.id_albergue = :id_origen
         ORDER BY t.fecha_solicitud DESC"
    );
    $stmtTr->execute(['id_destino' => $id_albergue, 'id_origen' => $id_albergue]);
    $traspasos = array_map(fn($r) => [
        "id_traspaso"     => (int) $r['id_traspaso'],
        "estado"          => $r['estado'],
        "fecha_solicitud" => $r['fecha_solicitud'],
        "mascota"         => $r['nombre_mascota'],
        "destino_nombre"  => $r['destino_nombre'],
    ], $stmtTr->fetchAll());

    // Espacios (caniles/gateras) y su ocupación actual.
    $stmtEsp = $pdo->prepare(
        "SELECT e.id_espacio, e.tipo, e.numero, e.capacidad,
                (SELECT COUNT(*) FROM mascota_rescatada r WHERE r.id_espacio = e.id_espacio) AS ocupacion
         FROM espacio e
         WHERE e.id_albergue = :id_albergue
         ORDER BY e.tipo, e.numero"
    );
    $stmtEsp->execute(['id_albergue' => $id_albergue]);
    $espacios = array_map(fn($r) => [
        "id_espacio" => (int) $r['id_espacio'],
        "tipo"       => $r['tipo'],
        "numero"     => (int) $r['numero'],
        "capacidad"  => (int) $r['capacidad'],
        "ocupacion"  => (int) $r['ocupacion'],
    ], $stmtEsp->fetchAll());

    echo json_encode([
        "ok" => true,
        "albergue"    => $albergue,
        "perros"      => $perros,
        "gatos"       => $gatos,
        "solicitudes" => $solicitudes,
        "traspasos"   => $traspasos,
        "espacios"    => $espacios,
    ]);

} catch (\PDOException $e) {
    error_log("gestor_panel.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo cargar el panel del albergue."]);
}
