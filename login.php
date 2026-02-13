<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'db_connection.php';

// Si ya tiene sesión iniciada, mandarlo a metas.php
if (isset($_SESSION['meta_user_id'])) {
    header("Location: metas.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $conn->real_escape_string($_POST['usuario']);
    $pass = $_POST['password'];

    $sql = "SELECT * FROM usuarios_metas WHERE usuario = '$user' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $u = $result->fetch_assoc();
        
        if (password_verify($pass, $u['password'])) {
            // Seteamos variables exclusivas para la carpeta /metricas/
            $_SESSION['meta_user_id'] = $u['id'];
            $_SESSION['meta_user_name'] = $u['nombre'];

            header("Location: metas.php");
            exit();
        } else {
            $error = "Contraseña incorrecta.";
        }
    } else {
        $error = "El usuario no existe.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso - Sistema de Métricas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md p-6">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 text-white rounded-2xl shadow-lg mb-4">
                <i class="fas fa-chart-pie text-3xl"></i>
            </div>
            <h2 class="text-3xl font-extrabold text-gray-900">Métricas Pro</h2>
        </div>
        <div class="bg-white p-8 rounded-2xl shadow-xl border border-gray-100">
            <?php if($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 text-sm flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Usuario</label>
                    <input type="text" name="usuario" class="w-full border border-gray-300 p-3 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" required autofocus>
                </div>
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase">Contraseña</label>
                        <a href="restablecer.php" class="text-xs font-semibold text-blue-600">¿Olvidaste tu clave?</a>
                    </div>
                    <input type="password" name="password" class="w-full border border-gray-300 p-3 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" required>
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow-lg transition-all">Iniciar Sesión</button>
            </form>
        </div>
    </div>
</body>
</html>