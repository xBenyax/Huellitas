<?php
/* =========================================================
   albergues.php — lista de albergues (selector de traspaso)
   GET /albergues.php
   éxito: { ok:true, albergues:[{ id_albergue, nombre, zona }] }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

try {
    $stmt = $pdo->query("SELECT id_albergue, nombre, zona FROM albergue ORDER BY nombre");
    $albergues = $stmt->fetchAll();
    echo json_encode(["ok" => true, "albergues" => $albergues]);
} catch (\PDOException $e) {
    error_log("albergues.php: " . $e->getMessage());
    echo json_encode(["ok" => true, "albergues" => []]);
}
