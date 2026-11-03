<?php
/* =========================================================
   gestor_registro.php — crear cuenta GESTOR_ALBERGUE (footer)

   POST body (modo "unirse", por defecto):
     { modo:"unirse", nombre_real, nombre_albergue, codigo_acceso, contrasena }
     - Valida codigo_acceso contra la columna albergue.codigo_acceso.

   POST body (modo "crear"):
     { modo:"crear", nombre_real, nombre_albergue, zona, contrasena }
     - Crea un albergue nuevo con un codigo_acceso generado
       automáticamente (único) y crea la cuenta de gestor asociada.

   éxito: { ok:true, usuario:{...}, albergue:{ id_albergue, nombre, zona, codigo_acceso } }
   error: { ok:false, mensaje:"..." }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

function generarCodigoAcceso(PDO $pdo): string {
    do {
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM albergue WHERE codigo_acceso = :c");
        $stmt->execute(['c' => $codigo]);
        $existe = (int) $stmt->fetchColumn() > 0;
    } while ($existe);
    return $codigo;
}

$input = json_decode(file_get_contents('php://input'), true);

$modo            = ($input['modo'] ?? 'unirse') === 'crear' ? 'crear' : 'unirse';
$nombre_real     = trim($input['nombre_real'] ?? '');
$nombre_albergue = trim($input['nombre_albergue'] ?? '');
$contrasena      = $input['contrasena'] ?? '';

if ($nombre_real === '' || $nombre_albergue === '' || $contrasena === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
    exit;
}

try {
    $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM usuario WHERE TRIM(nombre_real) = :nombre_real");
    $stmtDup->execute(['nombre_real' => $nombre_real]);
    if ((int) $stmtDup->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(["ok" => false, "mensaje" => "Ya existe una cuenta con ese nombre real."]);
        exit;
    }

    $pdo->beginTransaction();

    if ($modo === 'crear') {
        /* ---------- Crear un albergue nuevo ---------- */
        $zona = trim($input['zona'] ?? '');
        if ($zona === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(["ok" => false, "mensaje" => "Indica la zona del albergue."]);
            exit;
        }

        $codigo_acceso = generarCodigoAcceso($pdo);

        $stmtAlb = $pdo->prepare(
            "INSERT INTO albergue (nombre, zona, codigo_acceso) VALUES (:nombre, :zona, :codigo)"
        );
        $stmtAlb->execute([
            'nombre' => $nombre_albergue,
            'zona'   => $zona,
            'codigo' => $codigo_acceso,
        ]);
        $id_albergue = (int) $pdo->lastInsertId();

        $albergue = [
            "id_albergue"    => $id_albergue,
            "nombre"         => $nombre_albergue,
            "zona"           => $zona,
            "codigo_acceso"  => $codigo_acceso,
        ];
    } else {
        /* ---------- Unirse a un albergue existente ---------- */
        $codigo_acceso = trim($input['codigo_acceso'] ?? '');
        if ($codigo_acceso === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(["ok" => false, "mensaje" => "Faltan datos obligatorios."]);
            exit;
        }

        $stmtAlb = $pdo->prepare("SELECT id_albergue, nombre, zona, codigo_acceso FROM albergue WHERE codigo_acceso = :c");
        $stmtAlb->execute(['c' => $codigo_acceso]);
        $albergue = $stmtAlb->fetch();

        if (!$albergue) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(["ok" => false, "mensaje" => "El código de acceso no corresponde a ningún albergue registrado."]);
            exit;
        }
    }

    $contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO usuario (nombre_real, zona, contrasena, rol, id_albergue)
         VALUES (:nombre_real, :zona, :contrasena, 'GESTOR_ALBERGUE', :id_albergue)"
    );
    $stmt->execute([
        'nombre_real' => $nombre_real,
        'zona'        => $albergue['zona'],
        'contrasena'  => $contrasena_hash,
        'id_albergue' => $albergue['id_albergue'],
    ]);

    $nuevoId = (int) $pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "usuario" => [
            "id_usuario"  => $nuevoId,
            "nombre_real" => $nombre_real,
            "zona"        => $albergue['zona'],
            "rol"         => "GESTOR_ALBERGUE",
            "id_albergue" => $albergue['id_albergue'],
        ],
        "albergue" => $albergue
    ]);

} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("gestor_registro.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo crear la cuenta de gestor.", "debug" => $e->getMessage()]);
}
