<?php
session_start();
session_unset(); // Limpia todas las variables de sesión
session_destroy(); // Destruye la sesión por completo

// Redirige al inicio (asegúrate de que apunte a tu HTML o PHP principal)
header("Location: index.html"); 
exit;
?>