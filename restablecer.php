<?php
// 1. GESTIÓN DE ERRORES Y SESIÓN
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Rutas según tu servidor
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once 'db_connection.php';

$mensaje = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- ACCIÓN 1: SOLICITAR CÓDIGO ---
    if (isset($_POST['action']) && $_POST['action'] == 'solicitar_codigo') {
        $email_destino = $conn->real_escape_string($_POST['correo']);
        
        // Verificar si el correo existe en tu tabla
        $check = $conn->query("SELECT usuario FROM usuarios_metas WHERE correo = '$email_destino'");

        if ($check && $check->num_rows > 0) {
            // Generar código aleatorio de 6 dígitos
            $codigo_temporal = rand(100000, 999999);
            $_SESSION['reset_code'] = $codigo_temporal;
            $_SESSION['reset_email'] = $email_destino;

            $mail = new PHPMailer(true);
            try {
                // Configuración Zoho (EL REMITENTE)
                $mail->isSMTP();
                $mail->Host       = 'smtp.zoho.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'metricaspro@zohomail.com'; 
                $mail->Password   = 'bY42LrZQKrEh'; // Tu clave de aplicación
                $mail->SMTPSecure = 'ssl';
                $mail->Port       = 465;

                $mail->setFrom('metricaspro@zohomail.com', 'Métricas Pro');
                $mail->addAddress($email_destino); // CUALQUIER CORREO (Gmail, Hotmail, etc)

                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = 'Código de recuperación - Métricas Pro';
                $mail->Body    = "
                    <div style='font-family: sans-serif; padding: 20px; border: 1px solid #ddd;'>
                        <h2 style='color: #2563eb;'>Recuperación de contraseña</h2>
                        <p>Has solicitado restablecer tu clave. Tu código de verificación es:</p>
                        <h1 style='background: #f3f4f6; display: inline-block; padding: 10px 20px; letter-spacing: 5px;'>$codigo_temporal</h1>
                        <p style='color: #666; font-size: 12px; margin-top: 20px;'>Si no solicitaste esto, ignora este mensaje.</p>
                    </div>";

                $mail->send();
                $mensaje = "✅ Código enviado. Revisa tu correo (incluso SPAM).";
            } catch (Exception $e) {
                $error = "❌ No se pudo enviar el correo. Error: {$mail->ErrorInfo}";
            }
        } else {
            $error = "❌ Ese correo electrónico no está registrado en el sistema.";
        }
    }

    // --- ACCIÓN 2: VALIDAR CÓDIGO Y CAMBIAR CLAVE ---
    if (isset($_POST['action']) && $_POST['action'] == 'cambiar_clave') {
        $codigo_usuario = $_POST['codigo'];
        $nueva_pass = $_POST['nueva_password'];
        $email_valido = $_SESSION['reset_email'] ?? '';

        if (isset($_SESSION['reset_code']) && $codigo_usuario == $_SESSION['reset_code']) {
            $hash = password_hash($nueva_pass, PASSWORD_DEFAULT);
            $conn->query("UPDATE usuarios_metas SET password = '$hash' WHERE correo = '$email_valido'");
            
            unset($_SESSION['reset_code']);
            unset($_SESSION['reset_email']);
            $mensaje = "✅ Contraseña actualizada. <a href='login.php' class='font-bold underline'>Inicia sesión aquí</a>";
        } else {
            $error = "❌ El código ingresado es incorrecto o ha expirado.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Clave - Métricas Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md border-t-8 border-blue-600">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Restablecer Clave</h2>

        <?php if($mensaje) echo "<div class='bg-green-100 text-green-700 p-4 mb-4 text-sm rounded border border-green-200'>$mensaje</div>"; ?>
        <?php if($error) echo "<div class='bg-red-100 text-red-700 p-4 mb-4 text-sm rounded border border-red-200'>$error</div>"; ?>

        <form method="POST" class="mb-8 pb-8 border-b">
            <input type="hidden" name="action" value="solicitar_codigo">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">1. Ingresa tu correo registrado</label>
            <div class="flex gap-2">
                <input type="email" name="correo" class="flex-1 border p-2 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500" placeholder="usuario@gmail.com, etc." required>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700 transition">Enviar</button>
            </div>
        </form>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="cambiar_clave">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">2. Completa los datos recibidos</label>
            
            <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400"><i class="fas fa-key"></i></span>
                <input type="number" name="codigo" placeholder="Código de 6 dígitos" class="w-full border pl-10 p-2 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>

            <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400"><i class="fas fa-lock"></i></span>
                <input type="password" name="nueva_password" placeholder="Nueva Contraseña" class="w-full border pl-10 p-2 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>

            <button type="submit" class="w-full bg-gray-800 text-white py-3 rounded-xl font-bold hover:bg-black transition shadow-lg">Actualizar Contraseña</button>
        </form>

        <div class="mt-6 text-center">
            <a href="login.php" class="text-sm text-gray-400 hover:text-blue-600"><i class="fas fa-arrow-left mr-1"></i> Volver al Login</a>
        </div>
    </div>
</body>
</html>