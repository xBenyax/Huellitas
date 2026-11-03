<?php
/* =========================================================
   salud_albergue.php  (archivo NUEVO)
   GET /salud_albergue.php?id_albergue=ID
   éxito: { ok:true, perros:[...], gatos:[...] }
   Cada mascota trae: id_mascota, nombre_mascota, foto, especie,
   edad, genero, salud:{ id_salud, estado, observacion_activa,
   tratamientos_vigentes (string JSON) }
   - Crea la ficha de salud (EN_OBSERVACION) si la mascota no tiene.
   - Recalcula el estado en cada consulta (los tratamientos vencen solos).
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);
if (isset($_GET['debug'])) {            // SOLO para diagnosticar; quitar después
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';
require_once 'salud_estado.php';

$id_albergue = isset($_GET['id_albergue']) ? (int) $_GET['id_albergue'] : 0;
if (!$id_albergue) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Falta el id del albergue."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT m.id_mascota, m.nombre_mascota, m.foto, m.especie, m.edad, m.genero,
                s.id_salud, s.estado AS estado_salud, s.tratamientos_vigentes
         FROM mascota_rescatada r
         JOIN mascota m ON m.id_mascota = r.id_mascota
         LEFT JOIN salud s ON s.id_mascota = m.id_mascota
         WHERE r.id_albergue = :alb
           AND m.estado NOT IN ('ADOPTADA', 'TRASPASADA')
         ORDER BY m.nombre_mascota ASC"
    );
    $stmt->execute(['alb' => $id_albergue]);
    $filas = $stmt->fetchAll();

    $insertar = $pdo->prepare(
        "INSERT INTO salud (id_mascota, estado, tratamientos_vigentes)
         VALUES (:m, 'EN_OBSERVACION', '[]')"
    );
    $actualizar = $pdo->prepare("UPDATE salud SET estado = :e WHERE id_salud = :id");

    $perros = [];
    $gatos  = [];

    foreach ($filas as $f) {
        $id_salud = $f['id_salud'] ? (int) $f['id_salud'] : 0;
        $json     = $f['tratamientos_vigentes'] ?: '[]';
        $estado   = $f['estado_salud'] ?: 'EN_OBSERVACION';

        // Mascota sin ficha de salud (existente o recién creada): se crea ahora.
        if (!$id_salud) {
            $insertar->execute(['m' => (int) $f['id_mascota']]);
            $id_salud = (int) $pdo->lastInsertId();
            $json     = '[]';
            $estado   = 'EN_OBSERVACION';
        }

        $registros = saludParseRegistros($json);
        $nuevo     = calcularEstadoSalud($registros);
        if ($nuevo !== $estado) {
            $actualizar->execute(['e' => $nuevo, 'id' => $id_salud]);
            $estado = $nuevo;
        }

        $edad = (int) $f['edad'];
        $item = [
            "id_mascota"     => (int) $f['id_mascota'],
            "nombre_mascota" => $f['nombre_mascota'],
            "foto"           => $f['foto'] ?? null,
            "especie"        => strtolower($f['especie']),
            "edad"           => $edad === 1 ? "1 año" : "$edad años",
            "genero"         => strtolower($f['genero'] ?? ''),
            "salud"          => [
                "id_salud"              => $id_salud,
                "estado"                => $estado,
                "observacion_activa"    => saludObservacionActiva($registros),
                "tratamientos_vigentes" => $json,
            ],
        ];

        if ($item['especie'] === 'perro') $perros[] = $item;
        else                              $gatos[]  = $item;
    }

    echo json_encode(["ok" => true, "perros" => $perros, "gatos" => $gatos]);

} catch (\Throwable $e) {
    error_log("salud_albergue.php: " . $e->getMessage());
    http_response_code(500);
    $detalle = isset($_GET['debug']) ? " [DEBUG: " . $e->getMessage() . "]" : "";
    echo json_encode(["ok" => false, "mensaje" => "No se pudo cargar la salud del albergue." . $detalle]);
}
