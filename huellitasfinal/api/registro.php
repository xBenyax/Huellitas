<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detectar si la petición viene como JSON o como formulario tradicional
    $rawInput = json_decode(file_get_contents('php://input'), true);
    $data = is_array($rawInput) ? $rawInput : $_POST;

    $nombre_real = trim($data['nombre_real'] ?? $data['nombre'] ?? $data['usuario'] ?? '');
    $zona        = trim($data['zona'] ?? '');
    $contrasena  = $data['contrasena'] ?? $data['password'] ?? '';
    
    // Capturar rol e id_albergue recibidos o asignar valores por defecto
    $rol         = !empty($data['rol']) ? $data['rol'] : 'PARTICULAR';
    $id_albergue = !empty($data['id_albergue']) ? (int)$data['id_albergue'] : null;

    if (empty($nombre_real) || empty($zona) || empty($contrasena)) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Por favor, complete todos los campos obligatorios.'
        ]);
        exit;
    }

    $contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);

    try {
        $sql = "INSERT INTO usuario (nombre_real, zona, contrasena, rol, id_albergue) 
                VALUES (:nombre_real, :zona, :contrasena, :rol, :id_albergue)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'nombre_real' => $nombre_real,
            'zona'        => $zona,
            'contrasena'  => $contrasena_hash,
            'rol'         => $rol,
            'id_albergue' => $id_albergue
        ]);

        $nuevoId = $pdo->lastInsertId();

        echo json_encode([
            'ok' => true,
            'mensaje' => '¡Cuenta creada con éxito!',
            'usuario' => [
                'id_usuario'  => $nuevoId,
                'nombre_real' => $nombre_real,
                'zona'        => $zona,
                'rol'         => $rol,
                'id_albergue' => $id_albergue
            ]
        ]);
        exit;

    } catch (\PDOException $e) {
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Error en la base de datos: ' . $e->getMessage()
        ]);
        exit;
    }
} else {
    echo json_encode([
        'ok' => false,
        'mensaje' => 'Acceso no válido.'
    ]);
    exit;
}
?>