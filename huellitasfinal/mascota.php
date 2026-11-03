<?php
/* =========================================================
   mascota.php — ficha pública de UNA mascota
   Contrato esperado por script.js:
     GET /mascota.php?id_mascota=ID
     éxito: { ok:true, mascota:{...}, adopcion:{...}|null,
              espacio:{...}|null, albergue:{...}|null }
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

$id_mascota = isset($_GET['id_mascota']) ? (int) $_GET['id_mascota'] : 0;

if (!$id_mascota) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Falta el id de la mascota."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM mascota WHERE id_mascota = :id LIMIT 1");
    $stmt->execute(['id' => $id_mascota]);
    $m = $stmt->fetch();

    if (!$m) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Mascota no encontrada."]);
        exit;
    }

    $mascota = [
        "id_mascota"     => (int) $m['id_mascota'],
        "nombre_mascota" => $m['nombre_mascota'],
        "especie"        => strtolower($m['especie']),
        "edad"           => edadTexto($m['edad']),
        "genero"         => strtolower($m['genero']),
        "estado"         => ESTADO_TEXTO[$m['estado']] ?? $m['estado'],
    ];

    // Publicación de adopción activa (si existe).
    $stmtAdop = $pdo->prepare(
        "SELECT id_adopcion, descripcion FROM adopcion
         WHERE id_mascota = :id AND estado = 'DISPONIBLE' LIMIT 1"
    );
    $stmtAdop->execute(['id' => $id_mascota]);
    $adopcionRow = $stmtAdop->fetch();
    $adopcion = $adopcionRow ? [
        "id_adopcion" => (int) $adopcionRow['id_adopcion'],
        "descripcion" => $adopcionRow['descripcion'],
    ] : null;

    // Espacio y albergue donde está alojada (solo si es una mascota rescatada,
    // no todas las mascotas tienen fila en mascota_rescatada).
    $espacio = null;
    $albergue = null;
    $stmtResc = $pdo->prepare(
        "SELECT r.id_albergue, e.id_espacio, e.tipo, e.numero, al.nombre AS albergue_nombre, al.zona AS albergue_zona
         FROM mascota_rescatada r
         JOIN espacio e ON e.id_espacio = r.id_espacio
         JOIN albergue al ON al.id_albergue = r.id_albergue
         WHERE r.id_mascota = :id LIMIT 1"
    );
    $stmtResc->execute(['id' => $id_mascota]);
    $resc = $stmtResc->fetch();
    if ($resc) {
        $espacio = [
            "id_espacio" => (int) $resc['id_espacio'],
            "tipo"       => $resc['tipo'],   // 'PERROS' | 'GATOS' — el front compara tal cual
            "numero"     => (int) $resc['numero'],
        ];
        $albergue = [
            "id_albergue" => (int) $resc['id_albergue'],
            "nombre"      => $resc['albergue_nombre'],
            "zona"        => $resc['albergue_zona'],
        ];
    }

    echo json_encode([
        "ok" => true,
        "mascota" => $mascota,
        "adopcion" => $adopcion,
        "espacio" => $espacio,
        "albergue" => $albergue,
    ]);

} catch (\PDOException $e) {
    error_log("mascota.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo cargar la ficha de la mascota."]);
}
