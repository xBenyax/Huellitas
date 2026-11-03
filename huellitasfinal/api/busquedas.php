<?php
/* =========================================================
   busquedas.php
   -----------------------------------------------------------
   GET  /busquedas.php   (público)
     éxito: { ok:true, busquedas:[{ id_busqueda, estado("Perdido"/"Reunificado"),
       zona_perdida, fecha_perdida, descripcion, contacto,
       mascota:{ id_mascota, nombre_mascota, especie(minúscula) } }] }

   POST /busquedas.php   (reportar mascota perdida — requiere login)
     body: { nombre_mascota, especie, zona_perdida, fecha_perdida,
             descripcion, contacto, id_usuario }
     éxito: { ok:true, busqueda:{...} }

   VERSIÓN SIMPLE (sin tocar la BD): el formulario de "mascota
   perdida" no pide edad ni género, pero mascota.edad y
   mascota.genero son NOT NULL. En vez de alterar la tabla,
   usamos valores por defecto (edad=0, genero='MACHO') al
   crear el registro — no son datos reales, solo para que el
   INSERT no truene contra el esquema tal como está.
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

const BUSQUEDA_ESTADO_TEXTO = [
    'DESAPARECIDA' => 'Perdido',
    'RECUPERADA'   => 'Reunificado',
];

$method = $_SERVER['REQUEST_METHOD'];

/* ===================== GET ===================== */
if ($method === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT b.id_busqueda, b.estado, b.zona_perdida, b.fecha_perdida, b.descripcion, b.contacto,
                    m.id_mascota, m.nombre_mascota, m.foto, m.especie
             FROM busqueda b
             JOIN mascota m ON m.id_mascota = b.id_mascota
             ORDER BY b.fecha_perdida DESC, b.id_busqueda DESC"
        );
        $rows = $stmt->fetchAll();

        $busquedas = array_map(fn($r) => [
            "id_busqueda"   => (int) $r['id_busqueda'],
            "estado"        => BUSQUEDA_ESTADO_TEXTO[$r['estado']] ?? $r['estado'],
            "zona_perdida"  => $r['zona_perdida'],
            "fecha_perdida" => $r['fecha_perdida'],
            "descripcion"   => $r['descripcion'],
            "contacto"      => $r['contacto'],
            "mascota" => [
                "id_mascota"     => (int) $r['id_mascota'],
                "nombre_mascota" => $r['nombre_mascota'],
                "foto"           => $r['foto'] ?? null,
                "especie"        => strtolower($r['especie']),
            ],
        ], $rows);

        echo json_encode(["ok" => true, "busquedas" => $busquedas]);
    } catch (\PDOException $e) {
        error_log("busquedas.php GET: " . $e->getMessage());
        echo json_encode(["ok" => true, "busquedas" => []]);
    }
    exit;
}

/* ===================== POST ===================== */
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $nombre_mascota = trim($input['nombre_mascota'] ?? '');
    $especie        = strtoupper(trim($input['especie'] ?? ''));
    $zona_perdida   = trim($input['zona_perdida'] ?? '');
    $fecha_perdida  = $input['fecha_perdida'] ?? '';
    $descripcion    = trim($input['descripcion'] ?? '');
    $contacto       = trim($input['contacto'] ?? '');
    $id_usuario     = $input['id_usuario'] ?? null;
    $foto           = trim($input['foto'] ?? '') ?: null;   // URL de la imagen (opcional)

    if ($nombre_mascota === '' || !in_array($especie, ['PERRO','GATO']) || $zona_perdida === '' ||
        $fecha_perdida === '' || $descripcion === '' || $contacto === '' || !$id_usuario) {
        http_response_code(400);
        echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // edad=0 / genero='MACHO': valores de relleno, el formulario no los pide.
        $stmtM = $pdo->prepare(
            "INSERT INTO mascota (nombre_mascota, foto, especie, edad, genero, estado)
             VALUES (:nombre_mascota, :foto, :especie, 0, 'MACHO', 'ACTIVA')"
        );
        $stmtM->execute(['nombre_mascota' => $nombre_mascota, 'foto' => $foto, 'especie' => $especie]);
        $id_mascota = (int) $pdo->lastInsertId();

        $stmtB = $pdo->prepare(
            "INSERT INTO busqueda (zona_perdida, fecha_perdida, descripcion, contacto, estado, id_usuario, id_mascota)
             VALUES (:zona_perdida, :fecha_perdida, :descripcion, :contacto, 'DESAPARECIDA', :id_usuario, :id_mascota)"
        );
        $stmtB->execute([
            'zona_perdida'  => $zona_perdida,
            'fecha_perdida' => $fecha_perdida,
            'descripcion'   => $descripcion,
            'contacto'      => $contacto,
            'id_usuario'    => $id_usuario,
            'id_mascota'    => $id_mascota,
        ]);
        $id_busqueda = (int) $pdo->lastInsertId();

        $pdo->commit();

        echo json_encode([
            "ok" => true,
            "busqueda" => [
                "id_busqueda"   => $id_busqueda,
                "estado"        => "Perdido",
                "zona_perdida"  => $zona_perdida,
                "fecha_perdida" => $fecha_perdida,
                "descripcion"   => $descripcion,
                "contacto"      => $contacto,
                "mascota" => [
                    "id_mascota"     => $id_mascota,
                    "nombre_mascota" => $nombre_mascota,
                    "foto"           => $foto,
                    "especie"        => strtolower($especie),
                ],
            ]
        ]);
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("busquedas.php POST: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["ok" => false, "mensaje" => "No se pudo publicar el reporte."]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["ok" => false, "mensaje" => "Método no permitido."]);
