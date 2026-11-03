<?php
/* GET ?id_usuario=N -> solicitudes de adopción del usuario con su estado. Solo lectura. */
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

$id = isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : 0;
if (!$id) { http_response_code(400); echo json_encode(['ok' => false, 'mensaje' => 'Falta el usuario.']); exit; }

try {
    $st = $pdo->prepare(
        "SELECT s.id_solicitud, s.estado, s.fecha_solicitud,
                m.id_mascota, m.nombre_mascota, m.foto, LOWER(m.especie) AS especie,
                al.nombre AS albergue
         FROM solicitud_adopcion s
         JOIN adopcion a  ON a.id_adopcion  = s.id_adopcion
         JOIN mascota  m  ON m.id_mascota   = a.id_mascota
         JOIN albergue al ON al.id_albergue = a.id_albergue
         WHERE s.id_usuario = :u
         ORDER BY s.id_solicitud DESC"
    );
    $st->execute(['u' => $id]);
    echo json_encode(['ok' => true, 'solicitudes' => $st->fetchAll()]);
} catch (\PDOException $e) {
    error_log('mis_solicitudes.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudieron cargar tus solicitudes.']);
}
