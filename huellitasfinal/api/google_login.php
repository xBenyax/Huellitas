<?php
/* POST { credential }  (ID token de Google Identity Services)
   Valida el token con Google, busca el usuario por correo o lo crea.
   Google ya confirma que el Gmail es del usuario (email_verified), por eso la cuenta queda verificada. */
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once 'conexion.php';
require_once 'enviar_correo.php';

$in  = json_decode(file_get_contents('php://input'), true);
$jwt = trim($in['credential'] ?? '');
if ($jwt === '') { http_response_code(400); echo json_encode(['ok' => false, 'mensaje' => 'Falta la credencial de Google.']); exit; }

// Validación oficial del token en Google.
$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($jwt));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$p = $resp ? json_decode($resp, true) : null;

$cfg = huellitas_config();
$issOk = isset($p['iss']) && in_array($p['iss'], ['accounts.google.com', 'https://accounts.google.com'], true);
if ($http !== 200 || !$p || ($p['aud'] ?? '') !== $cfg['google_client_id'] || !$issOk
    || ($p['email_verified'] ?? '') !== 'true' || (int)($p['exp'] ?? 0) < time()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo validar tu cuenta de Google.']);
    exit;
}

$correo = strtolower($p['email']);
$nombre = trim($p['name'] ?? '') ?: strstr($correo, '@', true);

try {
    $st = $pdo->prepare("SELECT * FROM usuario WHERE correo = ? AND rol <> 'GESTOR_ALBERGUE' LIMIT 1");
    $st->execute([$correo]);
    $u = $st->fetch();
    $nuevo = false;

    if ($u) {
        if (!$u['correo_verificado']) {
            $pdo->prepare("UPDATE usuario SET correo_verificado = 1, codigo_verificacion = NULL, codigo_expira = NULL WHERE id_usuario = ?")->execute([$u['id_usuario']]);
            $u['correo_verificado'] = 1;
        }
    } else {
        // zona y contraseña son NOT NULL en tu tabla: se pone un valor por defecto y una contraseña aleatoria
        // (esta cuenta entra siempre con Google).
        $pdo->prepare("INSERT INTO usuario (nombre_real, zona, contrasena, rol, id_albergue, correo, correo_verificado)
                       VALUES (?, 'Sin especificar', ?, 'PARTICULAR', NULL, ?, 1)")
            ->execute([$nombre, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $correo]);
        $st = $pdo->prepare("SELECT * FROM usuario WHERE id_usuario = ?");
        $st->execute([$pdo->lastInsertId()]);
        $u = $st->fetch();
        $nuevo = true;

        enviar_correo($correo, '¡Bienvenido/a a Huellitas!', html_correo('¡Bienvenido/a, ' . htmlspecialchars($nombre) . '!',
            '<p>Tu cuenta quedó vinculada con tu Gmail. Ya puedes solicitar adopciones y llevar el control de salud de tus mascotas.</p>'));
    }

    unset($u['contrasena'], $u['codigo_verificacion'], $u['codigo_expira'], $u['codigo_intentos']);
    echo json_encode(['ok' => true, 'usuario' => $u, 'nuevo' => $nuevo, 'verificacion_enviada' => false]);

} catch (\PDOException $e) {
    error_log('google_login.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Error en el servidor al iniciar sesión con Google.']);
}
