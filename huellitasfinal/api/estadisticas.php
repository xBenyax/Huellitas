<?php
/* =========================================================
   estadisticas.php — tarjetas de la sección Inicio
   Contrato esperado por script.js:
     GET /estadisticas.php
     éxito: { ok:true, total_mascotas, porcentaje_perros,
              porcentaje_gatos, en_adopcion }
   ========================================================= */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

try {
    // Total de mascotas registradas en el sistema.
    $total = (int) $pdo->query("SELECT COUNT(*) FROM mascota")->fetchColumn();

    // Conteo por especie — el ENUM real es 'PERRO' / 'GATO' (huellitas.sql),
    // no había forma de que 50/50 hardcodeado reflejara la BD real.
    $perros = (int) $pdo->query("SELECT COUNT(*) FROM mascota WHERE especie = 'PERRO'")->fetchColumn();
    $gatos  = (int) $pdo->query("SELECT COUNT(*) FROM mascota WHERE especie = 'GATO'")->fetchColumn();

    $porcentaje_perros = $total > 0 ? round(($perros / $total) * 100) : 0;
    $porcentaje_gatos  = $total > 0 ? round(($gatos  / $total) * 100) : 0;

    // "En adopción" = publicaciones activas en la tabla adopcion,
    // no "total - adoptadas" (eso incluía animales en regulación, traspasados, etc.)
    $en_adopcion = (int) $pdo->query(
        "SELECT COUNT(*) FROM adopcion WHERE estado = 'DISPONIBLE'"
    )->fetchColumn();

    echo json_encode([
        "ok" => true,
        "total_mascotas"    => $total,
        "porcentaje_perros" => $porcentaje_perros,
        "porcentaje_gatos"  => $porcentaje_gatos,
        "en_adopcion"       => $en_adopcion
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "mensaje" => "No se pudieron cargar las estadísticas."
    ]);
}