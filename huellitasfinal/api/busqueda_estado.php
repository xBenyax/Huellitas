<?php
/* =========================================================
   busqueda_estado.php — reportar avistamiento (requiere login)
   POST body: { id_busqueda, estado }
   éxito: { ok:true }

   VERSIÓN SIMPLE (sin tocar la BD): huellitas.sql no tiene tabla
   para "avistamiento", así que por ahora este endpoint SOLO
   actualiza busqueda.estado — no guarda ubicación/descripción/
   contacto del testigo (esos datos se pierden). Si más adelante
   quieren conservarlos, ahí sí hace falta una tabla nueva.
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$id_busqueda = isset($input['id_busqueda']) ? (int) $input['id_busqueda'] : 0;
$estado      = strtoupper(trim($input['estado'] ?? ''));

if (!$id_busqueda || !in_array($estado, ['DESAPARECIDA', 'RECUPERADA'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "mensaje" => "Datos inválidos."]);
    exit;
}

try {
    $stmtCheck = $pdo->prepare("SELECT 1 FROM busqueda WHERE id_busqueda = :id");
    $stmtCheck->execute(['id' => $id_busqueda]);
    if (!$stmtCheck->fetch()) {
        http_response_code(404);
        echo json_encode(["ok" => false, "mensaje" => "Reporte no encontrado."]);
        exit;
    }

    $pdo->prepare("UPDATE busqueda SET estado = :estado WHERE id_busqueda = :id")
        ->execute(['estado' => $estado, 'id' => $id_busqueda]);

    echo json_encode(["ok" => true]);

} catch (\PDOException $e) {
    error_log("busqueda_estado.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "No se pudo enviar el aviso."]);
}
