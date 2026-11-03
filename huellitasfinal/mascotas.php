<?php
/* =========================================================
   mascotas.php
   -----------------------------------------------------------
   GET  /mascotas.php?especie=&estado=&nombre=&limit=&id_usuario=
     - especie: "perro" | "gato" (opcional)
     - estado:  "Disponible" -> solo mascotas con una publicación
                de adopción activa (adopcion.estado = 'DISPONIBLE')
     - nombre:  búsqueda LIKE por nombre_mascota (barra de búsqueda global)
     - limit:   límite de resultados (ej. destacadas en Inicio)
     - id_usuario: opcional, para marcar solicitud_enviada por mascota
     éxito: { ok:true, mascotas:[{
       id_mascota, nombre_mascota, especie(minúscula), edad("X años"),
       genero(minúscula), estado(texto legible),
       adopcion:{ id_adopcion, descripcion } | null,
       solicitud_enviada: bool
     }] }

   POST /mascotas.php   (usuario registra su propia mascota, PERFIL)
     body:  { nombre_mascota, foto, especie, edad, genero, id_usuario }
     éxito: { ok:true, id_mascota, mascota:{...} }
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

function formatMascota(array $row): array {
    $mascota = [
        "id_mascota"     => (int) $row['id_mascota'],
        "nombre_mascota" => $row['nombre_mascota'],
        "foto"           => $row['foto'] ?? null,   // URL guardada en la BD
        "especie"        => strtolower($row['especie']),
        "edad"           => edadTexto($row['edad']),
        "genero"         => strtolower($row['genero']),
        "estado"         => ESTADO_TEXTO[$row['estado']] ?? $row['estado'],
        "adopcion"       => null,
        "solicitud_enviada" => (bool) ($row['solicitud_enviada'] ?? false),
    ];
    if (!empty($row['id_adopcion'])) {
        $mascota['adopcion'] = [
            "id_adopcion" => (int) $row['id_adopcion'],
            "descripcion" => $row['adopcion_descripcion'],
        ];
    }
    return $mascota;
}

$method = $_SERVER['REQUEST_METHOD'];

/* ===================== GET ===================== */
if ($method === 'GET') {
    $especie    = strtoupper(trim($_GET['especie'] ?? ''));
    $estado     = trim($_GET['estado'] ?? '');
    $nombre     = trim($_GET['nombre'] ?? '');
    $limit      = isset($_GET['limit']) ? (int) $_GET['limit'] : null;
    $id_usuario = isset($_GET['id_usuario']) ? (int) $_GET['id_usuario'] : null;

    try {
        $sql = "SELECT m.id_mascota, m.nombre_mascota, m.foto, m.especie, m.edad, m.genero, m.estado,
                       a.id_adopcion, a.descripcion AS adopcion_descripcion
                       " . ($id_usuario ? ", sa.id_solicitud AS solicitud_id" : "") . "
                FROM mascota m
                LEFT JOIN adopcion a
                       ON a.id_mascota = m.id_mascota AND a.estado = 'DISPONIBLE'";

        if ($id_usuario) {
            $sql .= " LEFT JOIN solicitud_adopcion sa
                             ON sa.id_adopcion = a.id_adopcion AND sa.id_usuario = :id_usuario";
        }

        $sql .= " WHERE 1=1";
        $params = [];

        if ($especie === 'PERRO' || $especie === 'GATO') {
            $sql .= " AND m.especie = :especie";
            $params['especie'] = $especie;
        }
        if (strcasecmp($estado, 'Disponible') === 0) {
            $sql .= " AND a.id_adopcion IS NOT NULL";
        }
        if ($nombre !== '') {
            $sql .= " AND m.nombre_mascota LIKE :nombre";
            $params['nombre'] = "%$nombre%";
        }
        if ($id_usuario) {
            $params['id_usuario'] = $id_usuario;
        }

        $sql .= " ORDER BY m.id_mascota DESC";
        if ($limit) {
            $sql .= " LIMIT " . max(1, $limit);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $mascotas = array_map(function ($row) {
            $row['solicitud_enviada'] = !empty($row['solicitud_id']);
            return formatMascota($row);
        }, $rows);

        echo json_encode(["ok" => true, "mascotas" => $mascotas]);
    } catch (\PDOException $e) {
        // ok:true con lista vacía para que el front no muestre error de red
        // ante un problema de datos, pero sin ocultar el motivo en logs.
        error_log("mascotas.php GET: " . $e->getMessage());
        echo json_encode(["ok" => true, "mascotas" => []]);
    }
    exit;
}

/* ===================== POST ===================== */
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $nombre_mascota = trim($input['nombre_mascota'] ?? '');
    $foto           = $input['foto'] ?? null;
    $especie        = strtoupper(trim($input['especie'] ?? ''));
    $edad           = $input['edad'] ?? null;
    $genero         = strtoupper(trim($input['genero'] ?? ''));
    $id_usuario     = $input['id_usuario'] ?? null;

    if ($nombre_mascota === '' || !in_array($especie, ['PERRO','GATO']) ||
        !in_array($genero, ['MACHO','HEMBRA']) || $edad === null || !$id_usuario) {
        http_response_code(400);
        echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
        exit;
    }

    try {
        // Límite de 5 mascotas propias por usuario (regla del negocio).
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM mascota WHERE id_usuario = :id_usuario");
        $stmtCount->execute(['id_usuario' => $id_usuario]);
        if ((int) $stmtCount->fetchColumn() >= 5) {
            http_response_code(400);
            echo json_encode(["ok" => false, "mensaje" => "Ya registraste el máximo de 5 mascotas por usuario."]);
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO mascota (nombre_mascota, foto, especie, edad, genero, estado, id_usuario)
             VALUES (:nombre_mascota, :foto, :especie, :edad, :genero, 'ACTIVA', :id_usuario)"
        );
        $stmt->execute([
            'nombre_mascota' => $nombre_mascota,
            'foto'           => $foto,
            'especie'        => $especie,
            'edad'           => $edad,
            'genero'         => $genero,
            'id_usuario'     => $id_usuario,
        ]);
        $id_mascota = (int) $pdo->lastInsertId();

        // Ficha de salud vacía asociada, para que el usuario pueda
        // empezar a registrar vacunas/tratamientos de inmediato.
        $stmtSalud = $pdo->prepare(
            "INSERT INTO salud (tipo_documento, foto_respaldo, tratamientos_vigentes, id_mascota)
             VALUES ('', NULL, NULL, :id_mascota)"
        );
        $stmtSalud->execute(['id_mascota' => $id_mascota]);

        $pdo->commit();

        echo json_encode([
            "ok" => true,
            "id_mascota" => $id_mascota,
            "mascota" => [
                "id_mascota"     => $id_mascota,
                "nombre_mascota" => $nombre_mascota,
                "foto"           => $foto,
                "especie"        => strtolower($especie),
                "edad"           => edadTexto($edad),
                "genero"         => strtolower($genero),
            ]
        ]);
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("mascotas.php POST: " . $e->getMessage());
        http_response_code(500);
        // DEBUG TEMPORAL: quitar la línea del "mensaje" con getMessage() antes de producción.
        echo json_encode(["ok" => false, "mensaje" => "No se pudo registrar la mascota.", "debug" => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["ok" => false, "mensaje" => "Método no permitido."]);