<?php
// NO subas este archivo a GitHub. Contiene credenciales.
return [
    // Cuenta Gmail que ENVÍA los correos de Huellitas.
    // Necesita verificación en 2 pasos y una "Contraseña de aplicación":
    // https://myaccount.google.com/apppasswords
    'smtp_host'   => 'smtp.gmail.com',
    'smtp_port'   => 587,
    'smtp_user'   => 'TU_CORREO_HUELLITAS@gmail.com',
    'smtp_pass'   => 'CLAVE_DE_APLICACION_DE_16_LETRAS',
    'from_name'   => 'Huellitas',

    // El MISMO ID de cliente que pones en script.js (GOOGLE_CLIENT_ID).
    'google_client_id' => 'TU_CLIENT_ID.apps.googleusercontent.com',
];
