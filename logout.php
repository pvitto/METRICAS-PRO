<?php
// --- CONFIGURACIÓN PARA ENCONTRAR LA SESIÓN PRIVADA Y MATARLA ---
$tiempo_vida = 31536000;
$ruta_sesiones = __DIR__ . '/mis_sesiones_privadas';
if (file_exists($ruta_sesiones)) {
    session_save_path($ruta_sesiones);
}
session_start();
// ----------------------------------------------------------------

// 2. Vaciamos todas las variables de sesión
$_SESSION = array();

// 3. Destruimos la cookie "inmortal" del navegador del usuario
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destruimos la sesión en la carpeta privada de forma definitiva
session_destroy();

// 5. Redirigimos de vuelta a la pantalla de Login
header("Location: login.php");
exit();
?>