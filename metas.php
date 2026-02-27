<?php
// 1. CONFIGURACIÓN DE ERRORES (Anti Error 500)
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// 2. CONFIGURACIÓN DE ZONA HORARIA Y DÍAS EN ESPAÑOL
date_default_timezone_set('America/Bogota');
$dias_es = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses_es = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// --- MANTENER SESIÓN ABIERTA POR 1 AÑO ---
$tiempo_vida = 31536000; 
$ruta_sesiones = __DIR__ . '/mis_sesiones_privadas';
if (!file_exists($ruta_sesiones)) { mkdir($ruta_sesiones, 0777, true); }
session_save_path($ruta_sesiones);
session_set_cookie_params($tiempo_vida);
ini_set('session.gc_maxlifetime', $tiempo_vida);
ini_set('session.cookie_lifetime', $tiempo_vida);

// 3. SEGURIDAD Y CONEXIÓN
session_start();
if (!isset($_SESSION['meta_user_id'])) { header("Location: login.php"); exit(); }
require_once 'db_connection.php';
// -------------------------------------------------------------

$userName = $_SESSION['meta_user_name'] ?? 'Usuario';

// --- VERIFICACIÓN DE ROLES ---
$userRole = $_SESSION['meta_user_rol'] ?? 'user';
$isAdmin = ($userRole === 'admin'); 

// ✅ EL CANDADO SUPER ADMIN (SÓLO EL USUARIO LLAMADO "ADMIN")
$nombreMinusculas = strtolower(trim($userName));
$isSuperAdmin = ($nombreMinusculas === 'administrador' || $nombreMinusculas === 'admin');

// ====================================================================
// ⚙️ FUNCIÓN SALVAVIDAS PARA FORMATEAR FECHAS DEL HTML (Evita Error 500)
// ====================================================================
function formatearFecha($dt) {
    if (empty($dt)) return NULL;
    $dt = str_replace('T', ' ', $dt); // Quita la 'T' del HTML
    if (strlen($dt) == 16) $dt .= ':00'; // Agrega segundos si faltan
    return $dt;
}

// ====================================================================
// ⚙️ AUTO-ACTUALIZACIÓN DE BASE DE DATOS (MIGRACIÓN INVISIBLE)
// ====================================================================
try {
    $conn->query("ALTER TABLE historial_metas MODIFY COLUMN estado varchar(50) DEFAULT 'pendiente'");
    $checkCol = $conn->query("SHOW COLUMNS FROM historial_metas LIKE 'fecha_cierre'");
    if ($checkCol && $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE historial_metas ADD COLUMN fecha_cierre datetime DEFAULT NULL AFTER fecha");
    }
} catch (Throwable $e) {}

// ====================================================================
// 🤖 AUTO-CIERRE DE MES INVISIBLE (PASA A VERDE AUTOMÁTICAMENTE)
// ====================================================================
try {
    $mes_actual = date('Y-m'); // Ej: 2026-03
    $sql_auto_cierre = "UPDATE historial_metas 
                        SET estado = 'confirmado', 
                            confirmado_por = 'Sistema (Auto)', 
                            fecha_confirmacion = NOW() 
                        WHERE estado = 'pendiente' 
                        AND jornada_abierta = 0 
                        AND DATE_FORMAT(COALESCE(fecha_cierre, fecha), '%Y-%m') < '$mes_actual' 
                        AND estado != 'eliminado'";
    $conn->query($sql_auto_cierre);
} catch (Throwable $e) {}
// ====================================================================

$tabla_usuarios = "usuarios_metas"; 
$col_user_id    = "id";          
$col_user_name  = "nombre";  
$col_user_pass  = "password";  

function registrarBitacora($conn, $usuario, $accion, $detalle) {
    try {
        $fecha = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO bitacora_actividad (usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $usuario, $accion, $detalle, $fecha);
            $stmt->execute();
        }
    } catch (Throwable $e) {} 
}

// ---------------------------------------------------------
// CONEXIÓN A GOOGLE DRIVE API
// ---------------------------------------------------------
$archivos_drive = [];
$error_drive = "";

$archivo_credenciales = __DIR__ . '/credenciales.json';
$rutas_posibles = [
    __DIR__ . '/google_api/vendor/autoload.php',
    __DIR__ . '/google_api/google-api-php-client--PHP8.1/vendor/autoload.php',
    __DIR__ . '/google-api-php-client--PHP8.1/vendor/autoload.php'
];

$ruta_autoload = false;
foreach($rutas_posibles as $ruta) {
    if (file_exists($ruta)) { $ruta_autoload = $ruta; break; }
}

if ($ruta_autoload && file_exists($archivo_credenciales)) {
    try {
        require_once $ruta_autoload;
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $archivo_credenciales);
        $client = new Google\Client();
        $client->useApplicationDefaultCredentials();
        $client->addScope(Google\Service\Drive::DRIVE_READONLY);
        $driveService = new Google\Service\Drive($client);

        $folderId = '1F130gs4xpYI0Z8BQdjsSZy4RxwzkOtpB';
        $optParams = array('q' => "'$folderId' in parents and trashed=false", 'pageSize' => 50, 'fields' => 'nextPageToken, files(id, name, webViewLink)', 'orderBy' => 'createdTime desc');
        $results = $driveService->files->listFiles($optParams);
        
        foreach ($results->getFiles() as $file) {
            $archivos_drive[] = ['id' => $file->getId(), 'name' => $file->getName(), 'link' => $file->getWebViewLink()];
        }
    } catch (Exception $e) { $error_drive = "Error conectando a Drive: " . $e->getMessage(); }
} else {
    $error_drive = "<strong>ERROR DE RUTAS DRIVE:</strong><br>";
    if (!$ruta_autoload) $error_drive .= "❌ No encuentro el Autoload.<br>";
    if (!file_exists($archivo_credenciales)) $error_drive .= "❌ No encuentro el JSON.<br>";
}

// ---------------------------------------------------------
// LÓGICA (POST) - ACCIONES GENERALES
// ---------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'create_project' && $isAdmin) {
    $name = $conn->real_escape_string($_POST['project_name']);
    $desc = $conn->real_escape_string($_POST['project_desc']);
    $target = (int)($_POST['project_target'] ?? 0);
    $conn->query("INSERT INTO project_goals (name, description, target, current) VALUES ('$name', '$desc', $target, 0)");
    registrarBitacora($conn, $userName, "Creó Proyecto", "Nombre: $name, Meta: $target");
    header("Location: metas.php?project_id=" . $conn->insert_id);
    exit();
}

// B) INICIAR DÍA (SOPORTA MÚLTIPLES JORNADAS)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'iniciar_dia') {
    $pid = (int)$_POST['project_id'];
    $apertura = (int)$_POST['apertura'];
    $tab = $_POST['current_tab'] ?? 'hoy';
    $fecha = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("INSERT INTO historial_metas (project_id, meta_registrada, certificados_registrados, apertura, cierre, fecha, jornada_abierta, estado, creado_por) VALUES (?, 0, 0, ?, 0, ?, 1, 'pendiente', ?)");
    if($stmt) {
        $stmt->bind_param("iiss", $pid, $apertura, $fecha, $userName);
        $stmt->execute();
    }
    
    registrarBitacora($conn, $userName, "Inició Jornada", "Proyecto ID: $pid, Apertura: $apertura");
    header("Location: metas.php?project_id=$pid&tab=$tab");
    exit();
}

// C) CERRAR DÍA (REGISTRA FECHA DE CIERRE)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cerrar_dia') {
    $historial_id = (int)$_POST['historial_id'];
    $pid = (int)$_POST['project_id'];
    $cierre = (int)$_POST['cierre'];
    $tab = $_POST['current_tab'] ?? 'hoy';
    $fecha_cierre = date('Y-m-d H:i:s');

    $conn->query("UPDATE historial_metas SET cierre=$cierre, certificados_registrados=$cierre, meta_registrada=$cierre, jornada_abierta=0, fecha_cierre='$fecha_cierre' WHERE id=$historial_id");
    recalcularTotalProyecto($conn, $pid);
    
    registrarBitacora($conn, $userName, "Cerró Jornada", "ID: $historial_id. Cierre reportado: $cierre");
    header("Location: metas.php?project_id=$pid&tab=$tab");
    exit();
}

// D) CARGA RETROACTIVA
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'carga_retro') {
    $pid = (int)$_POST['project_id'];
    $cantidad = (int)$_POST['cantidad'];
    $fecha_inicio = formatearFecha($_POST['retro_inicio']); 
    $fecha_fin = formatearFecha($_POST['retro_fin']);
    $tab = $_POST['current_tab'] ?? 'retroactivo';

    $stmt = $conn->prepare("INSERT INTO historial_metas (project_id, meta_registrada, certificados_registrados, apertura, cierre, fecha, fecha_cierre, jornada_abierta, estado, creado_por) VALUES (?, ?, ?, 0, ?, ?, ?, 0, 'pendiente', ?)");
    if ($stmt) {
        $stmt->bind_param("iiiisss", $pid, $cantidad, $cantidad, $cantidad, $fecha_inicio, $fecha_fin, $userName);
        $stmt->execute();
    }
    
    recalcularTotalProyecto($conn, $pid);
    registrarBitacora($conn, $userName, "Carga Retroactiva", "Cantidad: $cantidad. Desde: $fecha_inicio Hasta: $fecha_fin");
    header("Location: metas.php?project_id=$pid&tab=$tab");
    exit();
}

// === CONSOLIDAR MES (CIERRE MENSUAL) ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'consolidar_mes') {
    $pid = (int)$_POST['project_id'];
    $mes = (int)$_POST['mes_cierre'];
    $anio = (int)$_POST['anio_cierre'];
    $reportadas = (int)$_POST['reportadas'];
    $certificadas = (int)$_POST['certificadas'];
    
    // Archivar registros de ese mes que NO esten eliminados
    $stmt_arch = $conn->prepare("UPDATE historial_metas SET estado = 'archivado' WHERE project_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ? AND jornada_abierta = 0 AND estado != 'eliminado'");
    if($stmt_arch) {
        $stmt_arch->bind_param("iii", $pid, $mes, $anio);
        $stmt_arch->execute();
    }

    // Crear registro consolidado
    $fecha_consolidada = date("Y-m-t 23:59:59", strtotime("$anio-$mes-01")); 
    $stmt_ins = $conn->prepare("INSERT INTO historial_metas (project_id, meta_registrada, certificados_registrados, apertura, cierre, fecha, fecha_cierre, jornada_abierta, estado, creado_por, confirmado_por, fecha_confirmacion) VALUES (?, ?, ?, 0, ?, ?, ?, 0, 'confirmado', ?, ?, ?)");
    if ($stmt_ins) {
        $stmt_ins->bind_param("iiiissssss", $pid, $reportadas, $certificadas, $certificadas, $fecha_consolidada, $fecha_consolidada, $userName, $userName, $fecha_consolidada);
        $stmt_ins->execute();
    }

    recalcularTotalProyecto($conn, $pid);
    registrarBitacora($conn, $userName, "Cierre de Mes", "Consolidó $mes/$anio. Reportó: $reportadas, Certificó: $certificadas");
    
    $_SESSION['swal_success'] = "Mes consolidado correctamente. Los números han sido actualizados y confirmados.";
    header("Location: metas.php?project_id=$pid&tab=informes");
    exit();
}

// EDITAR HISTORIAL (ACTUALIZA TODAS LAS VARIABLES DE CÁLCULO)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'edit_history' && $isAdmin) {
    $hid = (int)$_POST['historial_id'];
    $pid = (int)$_POST['project_id'];
    $tab = $_POST['current_tab'] ?? 'hoy';
    
    $nueva_fecha = formatearFecha($_POST['edit_fecha']);
    $nueva_fecha_cierre = formatearFecha($_POST['edit_fecha_cierre']);
    
    $nueva_apertura = (int)$_POST['edit_apertura'];
    $nueva_cierre = (int)$_POST['edit_cierre'];
    
    $qOld = $conn->query("SELECT apertura, cierre, fecha, fecha_cierre FROM historial_metas WHERE id=$hid");
    $old = ($qOld && $qOld->num_rows > 0) ? $qOld->fetch_assoc() : ['apertura'=>0, 'cierre'=>0, 'fecha'=>'', 'fecha_cierre'=>''];
    
    $detalle_log = "Editó ID $hid. ANTES -> In:{$old['apertura']}, Out:{$old['cierre']}, Inicio:{$old['fecha']}. DESPUÉS -> In:$nueva_apertura, Out:$nuevo_cierre, Inicio:$nueva_fecha.";
    
    // Al editar, sincronizamos `meta_registrada` con el nuevo cierre para que la resta cuadre
    if(empty($nueva_fecha_cierre)){
        $stmt = $conn->prepare("UPDATE historial_metas SET apertura=?, cierre=?, certificados_registrados=?, meta_registrada=?, fecha=?, fecha_cierre=NULL, editado_por=? WHERE id=?");
        if($stmt){
            $stmt->bind_param("iiiissi", $nueva_apertura, $nuevo_cierre, $nuevo_cierre, $nuevo_cierre, $nueva_fecha, $userName, $hid);
            $stmt->execute();
        }
    } else {
        $stmt = $conn->prepare("UPDATE historial_metas SET apertura=?, cierre=?, certificados_registrados=?, meta_registrada=?, fecha=?, fecha_cierre=?, editado_por=? WHERE id=?");
        if($stmt){
            $stmt->bind_param("iiiisssi", $nueva_apertura, $nuevo_cierre, $nuevo_cierre, $nuevo_cierre, $nueva_fecha, $nueva_fecha_cierre, $userName, $hid);
            $stmt->execute();
        }
    }
    
    recalcularTotalProyecto($conn, $pid);
    registrarBitacora($conn, $userName, "Editó Registro", $detalle_log);
    header("Location: metas.php?project_id=$pid&tab=$tab");
    exit();
}

// ELIMINAR HISTORIAL (SOFT DELETE)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_history' && $isAdmin) {
    $hid = (int)$_POST['historial_id'];
    $pid = (int)$_POST['project_id'];
    $tab = $_POST['current_tab'] ?? 'hoy';
    
    $conn->query("UPDATE historial_metas SET estado = 'eliminado', editado_por = '$userName' WHERE id=$hid");
    
    recalcularTotalProyecto($conn, $pid);
    registrarBitacora($conn, $userName, "Eliminó Registro", "Marcó como 'Eliminado' el registro ID: $hid. Solo visible en Auditoría.");
    header("Location: metas.php?project_id=$pid&tab=$tab");
    exit();
}

// F) CARGAR ARCHIVO DRIVE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cargar_archivo') {
    $pid = (int)$_POST['project_id'];
    $cantidad = (int)$_POST['cantidad_enviada'];
    $fecha = date('Y-m-d H:i:s');
    $drive_data = explode('||', $_POST['archivo_drive']);
    $nombre = $conn->real_escape_string($drive_data[0]);
    $link = isset($drive_data[1]) ? $conn->real_escape_string($drive_data[1]) : '';
    
    $stmt = $conn->prepare("INSERT INTO seguimiento_archivos (project_id, nombre_archivo, link_drive, fecha_subida, estado_actual, cantidad_enviada, registrado_por) VALUES (?, ?, ?, ?, 'cargado', ?, ?)");
    if($stmt){
        $stmt->bind_param("isssis", $pid, $nombre, $link, $fecha, $cantidad, $userName);
        $stmt->execute();
    }
    
    registrarBitacora($conn, $userName, "Cargó Archivo Drive", "Archivo: $nombre, Cantidad: $cantidad");
    header("Location: metas.php?project_id=$pid&tab=drive");
    exit();
}

// G) ACTUALIZAR ESTADO DEL ARCHIVO
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'actualizar_archivo' && $isAdmin) {
    $id_archivo = (int)$_POST['archivo_id'];
    $pid = (int)$_POST['project_id'];
    $nuevo_estado = $conn->real_escape_string($_POST['estado']);
    $aprobadas = (int)($_POST['aprobadas'] ?? 0);
    $subsanar = (int)($_POST['por_subsanar'] ?? 0);
    $no_reportables = (int)($_POST['no_reportables'] ?? 0);
    $reconfirmado = isset($_POST['reconfirmado']) ? 1 : 0;
    
    $fecha_ahora = date('Y-m-d H:i:s');
    $sql_fechas = "";
    if ($nuevo_estado == 'en_revision') { $sql_fechas = ", fecha_envio_revision = IFNULL(fecha_envio_revision, '$fecha_ahora')"; } 
    elseif ($nuevo_estado == 'devuelto') { $sql_fechas = ", fecha_devolucion = '$fecha_ahora'"; }

    $sql = "UPDATE seguimiento_archivos SET estado_actual = '$nuevo_estado', aprobadas = $aprobadas, por_subsanar = $subsanar, no_reportables = $no_reportables, reconfirmado = $reconfirmado, auditado_por = '$userName' $sql_fechas WHERE id = $id_archivo";
    $conn->query($sql);
    
    registrarBitacora($conn, $userName, "Auditó Archivo", "Estado cambiado a: $nuevo_estado. Aprobadas: $aprobadas");
    header("Location: metas.php?project_id=$pid&tab=drive");
    exit();
}

// H) CAMBIAR CONTRASEÑA DE USUARIO (SOLO SUPER ADMIN)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cambiar_password' && $isSuperAdmin) {
    $pid = (int)($_POST['project_id'] ?? 0);
    $redir = "metas.php?tab=usuarios";
    if($pid > 0) $redir .= "&project_id=$pid";

    try {
        $id_target = (int)($_POST['id_usuario'] ?? 0);
        $nueva_pass = $_POST['nueva_password'] ?? '';
        
        if ($id_target > 0 && !empty($nueva_pass)) {
            $hash = password_hash($nueva_pass, PASSWORD_DEFAULT); 
            $sql = "UPDATE `$tabla_usuarios` SET `$col_user_pass` = ? WHERE `$col_user_id` = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) { 
                $stmt->bind_param("si", $hash, $id_target);
                if($stmt->execute()) {
                    registrarBitacora($conn, $userName, "Cambio de Clave", "Forzó clave del usuario ID: $id_target");
                    $_SESSION['swal_success'] = "La contraseña se actualizó correctamente.";
                } else {
                    $_SESSION['swal_error'] = "Error ejecutando la actualización en DB.";
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) { }
    
    header("Location: " . $redir);
    exit();
}

// FUNCIÓN AUXILIAR RECALCULAR 
function recalcularTotalProyecto($conn, $pid) {
    $sql = "SELECT SUM(cierre - apertura) as total_real FROM historial_metas WHERE project_id = $pid AND jornada_abierta = 0 AND estado NOT IN ('archivado', 'eliminado')";
    $res = $conn->query($sql);
    $row = $res->fetch_assoc();
    $nuevo_total = $row['total_real'] ?? 0;
    $conn->query("UPDATE project_goals SET current=$nuevo_total WHERE id=$pid");
}

// ---------------------------------------------------------
// LECTURA DE DATOS Y NUEVOS CÁLCULOS
// ---------------------------------------------------------
$proyectos = [];
$q = $conn->query("SELECT * FROM project_goals ORDER BY id ASC");
if($q) { while($row = $q->fetch_assoc()) { $proyectos[] = $row; } }

$currentProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (count($proyectos) > 0 ? $proyectos[0]['id'] : null);
$tabActiva = $_GET['tab'] ?? 'hoy';

$currentProject = null;

// Variables Formación
$sesionesAbiertas = [];
$historial = null;
$historial_auditoria = null;
$estadisticas_diarias = [];
$stats = ['confirmado' => 0, 'pendiente' => 0];

// Variables Drive
$historial_archivos = null;
$reporte_generado = "";
$total_aprobadas_drive = 0;
$total_en_revision_drive = 0;
$total_no_reportables_drive = 0;

// Variables Contador Diario
$diario_validadas = 0;
$diario_por_validar = 0;
$diario_general = 0;

// Consulta Usuarios (Solo SuperAdmin)
$usuarios_lista = [];
if ($isSuperAdmin) {
    $qU = $conn->query("SELECT `$col_user_id` as id, `$col_user_name` as nombre FROM `$tabla_usuarios` ORDER BY `$col_user_name` ASC");
    if($qU) { while($r = $qU->fetch_assoc()) { $usuarios_lista[] = $r; } }
}

// Consulta Bitácora de Actividad
$bitacora = $conn->query("SELECT * FROM bitacora_actividad ORDER BY fecha DESC LIMIT 150");

if ($currentProjectId) {
    foreach ($proyectos as $p) { if ($p['id'] == $currentProjectId) { $currentProject = $p; break; } }
    
    // --- JORNADAS ABIERTAS SIMULTÁNEAS ---
    $qOpen = $conn->query("SELECT * FROM historial_metas WHERE project_id = $currentProjectId AND jornada_abierta = 1 AND estado != 'eliminado' ORDER BY fecha ASC");
    if($qOpen) { while($r = $qOpen->fetch_assoc()) { $sesionesAbiertas[] = $r; } }
    
    // Historial Público
    $historial = $conn->query("SELECT * FROM historial_metas WHERE project_id = $currentProjectId AND estado NOT IN ('archivado', 'eliminado') ORDER BY fecha DESC LIMIT 50");
    
    // Historial Auditoría (SÓLO SUPER ADMIN)
    if($isSuperAdmin) {
        $historial_auditoria = $conn->query("SELECT * FROM historial_metas WHERE project_id = $currentProjectId ORDER BY fecha DESC LIMIT 200");
    }

    // Estadísticas Diarias
    $qStatsDiarias = $conn->query("SELECT DATE(COALESCE(fecha_cierre, fecha)) as dia, 
                                   SUM(CASE WHEN estado='confirmado' THEN (cierre - apertura) ELSE 0 END) as validado,
                                   SUM(CASE WHEN estado='pendiente' THEN (IF(meta_registrada>0, meta_registrada, cierre) - apertura) ELSE 0 END) as pendiente
                                   FROM historial_metas 
                                   WHERE project_id = $currentProjectId AND estado NOT IN ('eliminado', 'archivado') AND jornada_abierta = 0 
                                   GROUP BY dia ORDER BY dia DESC LIMIT 30");
    if($qStatsDiarias) { while($r = $qStatsDiarias->fetch_assoc()) { $estadisticas_diarias[] = $r; } }

    $sqlStats = "SELECT 
        SUM(CASE WHEN estado='confirmado' THEN (cierre - apertura) ELSE 0 END) as ok,
        SUM(CASE WHEN estado='pendiente' AND jornada_abierta=0 THEN (cierre - apertura) ELSE 0 END) as pend
        FROM historial_metas WHERE project_id = $currentProjectId AND estado NOT IN ('archivado', 'eliminado')";
    $rStats = $conn->query($sqlStats)->fetch_assoc();
    $stats['confirmado'] = $rStats['ok'] ?? 0;
    $stats['pendiente'] = $rStats['pend'] ?? 0;

    // --- CÁLCULO CONTADOR DIARIO ---
    $qDiario = $conn->query("SELECT estado, (cierre - apertura) as prod_aprobada, (meta_registrada - apertura) as prod_reportada, meta_registrada FROM historial_metas WHERE project_id = $currentProjectId AND DATE(COALESCE(fecha_cierre, fecha)) = CURDATE() AND jornada_abierta = 0 AND estado NOT IN ('archivado', 'eliminado')");
    if($qDiario) {
        while($r = $qDiario->fetch_assoc()) {
            if($r['estado'] == 'confirmado') {
                $diario_validadas += $r['prod_aprobada'];
            } else {
                $diario_por_validar += ($r['meta_registrada'] != 0) ? $r['prod_reportada'] : $r['prod_aprobada'];
            }
        }
    }
    $diario_general = $diario_validadas + $diario_por_validar;

    // --- DATOS DRIVE ---
    $historial_archivos = $conn->query("SELECT * FROM seguimiento_archivos WHERE project_id = $currentProjectId ORDER BY fecha_subida DESC LIMIT 50");
    $qStatsDrive = $conn->query("SELECT SUM(aprobadas) as aprobadas, SUM(no_reportables) as no_rep, SUM(CASE WHEN estado_actual IN ('cargado', 'en_revision', 'devuelto') THEN (cantidad_enviada - aprobadas - no_reportables) ELSE 0 END) as en_revision FROM seguimiento_archivos WHERE project_id = $currentProjectId");
    $rStatsDrive = $qStatsDrive->fetch_assoc();
    $total_aprobadas_drive = $rStatsDrive['aprobadas'] ?? 0;
    $total_en_revision_drive = $rStatsDrive['en_revision'] ?? 0;
    $total_no_reportables_drive = $rStatsDrive['no_rep'] ?? 0;

    // --- GENERAR INFORME POR FECHAS ---
    $informe_resultados = null;
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'generar_informe_fechas') {
        $f_inicio = $conn->real_escape_string($_POST['fecha_inicio']);
        $f_fin = $conn->real_escape_string($_POST['fecha_fin']);
        
        $qInfo = $conn->query("SELECT estado, (cierre - apertura) as prod_aprobada, (meta_registrada - apertura) as prod_reportada, meta_registrada FROM historial_metas WHERE project_id = $currentProjectId AND DATE(COALESCE(fecha_cierre, fecha)) BETWEEN '$f_inicio' AND '$f_fin' AND jornada_abierta = 0 AND estado NOT IN ('archivado', 'eliminado')");
        
        $inf_validadas = 0;
        $inf_por_validar = 0;
        if($qInfo) {
            while($r = $qInfo->fetch_assoc()) {
                if($r['estado'] == 'confirmado') {
                    $inf_validadas += $r['prod_aprobada'];
                } else {
                    $inf_por_validar += ($r['meta_registrada'] != 0) ? $r['prod_reportada'] : $r['prod_aprobada'];
                }
            }
        }
        $informe_resultados = [
            'inicio' => $f_inicio,
            'fin' => $f_fin,
            'validadas' => $inf_validadas,
            'por_validar' => $inf_por_validar,
            'general' => $inf_validadas + $inf_por_validar
        ];
        $tabActiva = 'informes';
    }

    // --- GENERADOR DE REPORTE GENERAL (TEXTO) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'generar_reporte') {
        $mes_filtro = (int)$_POST['mes_reporte'];
        $anio_filtro = (int)$_POST['anio_reporte'];
        $meta = $currentProject['target'];
        
        $meses_avance = array_fill(1, 12, 0);
        
        // Sumar Drive Meses Anteriores
        $qDriveAnt = $conn->query("SELECT MONTH(fecha_subida) as mes, SUM(aprobadas) as aprobadas FROM seguimiento_archivos WHERE project_id = $currentProjectId AND YEAR(fecha_subida) = $anio_filtro AND MONTH(fecha_subida) < $mes_filtro GROUP BY mes");
        if($qDriveAnt) { while($r = $qDriveAnt->fetch_assoc()) { $meses_avance[$r['mes']] += $r['aprobadas']; } }
        
        // Sumar Historial Meses Anteriores
        $qProdAnt = $conn->query("SELECT MONTH(fecha) as mes, SUM(cierre - apertura) as aprobadas FROM historial_metas WHERE project_id = $currentProjectId AND YEAR(fecha) = $anio_filtro AND MONTH(fecha) < $mes_filtro AND estado = 'confirmado' AND jornada_abierta = 0 GROUP BY mes");
        if($qProdAnt) { while($r = $qProdAnt->fetch_assoc()) { $meses_avance[$r['mes']] += $r['aprobadas']; } }
        
        // Drive del mes filtrado
        $qDriveAct = $conn->query("SELECT SUM(aprobadas) as aprobadas, SUM(CASE WHEN estado_actual != 'aprobado' THEN (cantidad_enviada - aprobadas - no_reportables) ELSE 0 END) as revision FROM seguimiento_archivos WHERE project_id = $currentProjectId AND MONTH(fecha_subida) = $mes_filtro AND YEAR(fecha_subida) = $anio_filtro");
        $dDrive = $qDriveAct->fetch_assoc();
        
        // Historial del mes filtrado
        $qProdAct = $conn->query("SELECT SUM(CASE WHEN estado='confirmado' THEN (cierre - apertura) ELSE 0 END) as aprobadas, SUM(CASE WHEN estado='pendiente' AND jornada_abierta=0 THEN (cierre - apertura) ELSE 0 END) as revision FROM historial_metas WHERE project_id = $currentProjectId AND MONTH(fecha) = $mes_filtro AND YEAR(fecha) = $anio_filtro AND estado NOT IN ('archivado', 'eliminado')");
        $dProd = $qProdAct->fetch_assoc();

        $mes_aprobadas = ($dDrive['aprobadas'] ?? 0) + ($dProd['aprobadas'] ?? 0);
        $mes_revision = ($dDrive['revision'] ?? 0) + ($dProd['revision'] ?? 0);
        $mes_total = $mes_aprobadas + $mes_revision;
        
        $total_global_aprobadas = $stats['confirmado'] + $total_aprobadas_drive;
        $total_global_revision = $stats['pendiente'] + $total_en_revision_drive;
        $total_general = $total_global_aprobadas + $total_global_revision;
        $para_completar = max(0, $meta - $total_global_aprobadas);
        
        $reporte_generado = "Metas formación\n\n";
        $reporte_generado .= "Meta global: ✨ ".number_format($meta)." mujeres ✨\n\n";
        $reporte_generado .= "Avance mensual:\n";
        for($i = 1; $i < $mes_filtro; $i++) {
            if($meses_avance[$i] > 0) {
                $reporte_generado .= "* " . $meses_es[$i] . ": " . number_format($meses_avance[$i]) . " mujeres certificadas\n";
            }
        }
        $reporte_generado .= "* " . $meses_es[$mes_filtro] . ":\n";
        $reporte_generado .= "  - aprobadas: " . number_format($mes_aprobadas) . "\n";
        $reporte_generado .= "  - por revisión: " . number_format($mes_revision) . "\n";
        $reporte_generado .= "  - total: " . number_format($mes_total) . "\n";
        $reporte_generado .= "  - para completar la meta: " . number_format($para_completar) . "\n\n";
        $reporte_generado .= "Total global: " . number_format($total_general) . "\n";
        $reporte_generado .= "Total mujeres fase 1 no reportables: " . number_format($total_no_reportables_drive);
        
        $tabActiva = 'reporte'; 
    }
}

// Cálculos Top Header 
$metaTotal = $currentProject['target'] ?? 0;
$total_ok = $stats['confirmado'] + $total_aprobadas_drive;
$total_pend = $stats['pendiente'] + $total_en_revision_drive;
$logradoTotal = $total_ok + $total_pend;

$falta = max(0, $metaTotal - $logradoTotal);
$pct_ok = ($metaTotal > 0) ? ($total_ok / $metaTotal) * 100 : 0;
$pct_pend = ($metaTotal > 0) ? ($total_pend / $metaTotal) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metas - Control de Formación</title>
    
    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark')
        }
    </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config = { darkMode: 'class', } </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .bar-stripe { background-image: linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent); background-size: 1rem 1rem; }
        /* Ocultar scrollbar pero permitir scroll */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900 transition-colors duration-200">

    <header class="bg-white dark:bg-gray-800 shadow-sm border-b dark:border-gray-700 sticky top-0 z-50 transition-colors duration-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-600 text-white p-2 rounded-lg"><i class="fas fa-chart-line"></i></div>
                    <div><h1 class="text-xl font-bold text-gray-900 dark:text-white">Control de Formación</h1></div>
                </div>
                <div class="flex items-center space-x-4">
                    <button id="theme-toggle" type="button" class="text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none rounded-lg text-sm p-2.5 transition">
                        <i id="theme-toggle-dark-icon" class="fas fa-moon hidden text-lg"></i>
                        <i id="theme-toggle-light-icon" class="fas fa-sun hidden text-lg"></i>
                    </button>
                    <span class="text-sm text-gray-600 dark:text-gray-300">Hola, <span class="font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($userName); ?></span>
                    <?php if($isSuperAdmin): ?> <span class="text-[10px] bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 px-2 py-0.5 rounded ml-1 font-bold">SUPER ADMIN</span> <?php endif; ?>
                    </span>
                    <a href="logout.php" class="text-sm text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 px-3 py-1 rounded hover:bg-red-50 dark:hover:bg-red-900/30 font-bold transition">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden sticky top-24 transition-colors duration-200">
                    <div class="p-4 border-b dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex justify-between items-center">
                        <h2 class="font-bold text-gray-700 dark:text-gray-300 text-sm">PROYECTOS</h2>
                        <?php if($isAdmin): ?>
                            <button onclick="document.getElementById('modal-new-project').classList.remove('hidden')" class="text-xs bg-blue-600 text-white px-2 py-1 rounded shadow-sm hover:bg-blue-700"><i class="fas fa-plus"></i></button>
                        <?php endif; ?>
                    </div>
                    <nav class="flex flex-col max-h-[70vh] overflow-y-auto">
                        <?php if (count($proyectos) > 0): ?>
                            <?php foreach($proyectos as $p): 
                                $p_pct = ($p['target'] > 0) ? ($p['current']/$p['target'])*100 : 0;
                                $isActive = ($currentProjectId == $p['id']);
                                $itemClasses = $isActive ? 'bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-600 dark:border-blue-400 text-blue-800 dark:text-blue-200' : 'border-l-4 border-transparent text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/50';
                            ?>
                                <a href="metas.php?project_id=<?php echo $p['id']; ?>&tab=hoy" class="p-4 border-b border-gray-50 dark:border-gray-700 block transition-all <?php echo $itemClasses; ?>">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="font-semibold text-sm truncate"><?php echo htmlspecialchars($p['name']); ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1 mt-2">
                                        <div class="bg-blue-500 h-1 rounded-full" style="width: <?php echo $p_pct; ?>%"></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>

            <div class="lg:col-span-3">
                <?php if ($currentProject): ?>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 mb-4 transition-colors duration-200">
                        <div class="flex justify-between items-end mb-4">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($currentProject['name']); ?></h2>
                                <div class="flex items-center gap-2 mt-2">
                                    <span class="bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 text-xs font-bold px-2 py-1 rounded">FALTAN: <?php echo number_format($falta); ?></span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">para la meta de <?php echo number_format($metaTotal); ?></span>
                                </div>
                                <div class="flex items-center gap-3 mt-3 text-[11px] text-gray-600 dark:text-gray-300">
                                    <span class="flex items-center gap-1 bg-green-50 dark:bg-green-900/30 px-2 py-1 rounded border border-green-100 dark:border-green-800">
                                        <span class="w-2 h-2 bg-green-500 rounded-full"></span> 
                                        Confirmadas: <strong><?php echo number_format($total_ok); ?></strong> 
                                        <span class="text-green-600 dark:text-green-400 font-bold ml-1">(<?php echo number_format($pct_ok, 1); ?>%)</span>
                                    </span>
                                    <span class="flex items-center gap-1 bg-yellow-50 dark:bg-yellow-900/30 px-2 py-1 rounded border border-yellow-100 dark:border-yellow-800">
                                        <span class="w-2 h-2 bg-yellow-400 rounded-full"></span> 
                                        Por Confirmar: <strong><?php echo number_format($total_pend); ?></strong>
                                        <span class="text-yellow-600 dark:text-yellow-500 font-bold ml-1">(<?php echo number_format($pct_pend, 1); ?>%)</span>
                                    </span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($logradoTotal); ?></p>
                                <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase font-bold">Formación Total</p>
                            </div>
                        </div>
                        
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-5 flex overflow-hidden shadow-inner">
                            <div class="bg-green-500 h-5 transition-all duration-1000 flex items-center justify-center text-[9px] text-white font-bold" style="width: <?php echo $pct_ok; ?>%">
                                <?php echo ($pct_ok > 5) ? round($pct_ok).'%' : ''; ?>
                            </div>
                            <div class="bg-yellow-400 h-5 transition-all duration-1000 bar-stripe flex items-center justify-center text-[9px] text-yellow-900 font-bold" style="width: <?php echo $pct_pend; ?>%">
                                <?php echo ($pct_pend > 5) ? round($pct_pend).'%' : ''; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 border-b border-gray-200 dark:border-gray-700 overflow-x-auto hide-scrollbar pb-1">
                        <nav class="flex space-x-6 min-w-max">
                            <button onclick="switchMasterTab('hoy')" id="mtab_hoy" class="tab-btn border-blue-500 text-blue-600 dark:text-blue-400 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fas fa-sun"></i> Hoy / Registro
                            </button>
                            <button onclick="switchMasterTab('estadisticas')" id="mtab_estadisticas" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fas fa-chart-bar"></i> Estadísticas Diarias
                            </button>
                            <button onclick="switchMasterTab('informes')" id="mtab_informes" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fas fa-filter"></i> Informes y Cierres
                            </button>
                            <button onclick="switchMasterTab('drive')" id="mtab_drive" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fab fa-google-drive"></i> Archivos Excel
                            </button>
                            <button onclick="switchMasterTab('reporte')" id="mtab_reporte" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fas fa-file-alt"></i> Rep. Texto
                            </button>
                            
                            <?php if($isSuperAdmin): ?>
                            <button onclick="switchMasterTab('auditoria')" id="mtab_auditoria" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fas fa-user-secret text-red-500"></i> Auditoría Admin
                            </button>
                            <button onclick="switchMasterTab('usuarios')" id="mtab_usuarios" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 whitespace-nowrap pb-2 px-1 border-b-2 font-bold text-sm flex items-center gap-2 transition">
                                <i class="fas fa-users-cog text-blue-500"></i> Usuarios
                            </button>
                            <?php endif; ?>
                        </nav>
                    </div>

                    <div id="vista_hoy" class="tab-content" style="display:none;">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                            <div class="md:col-span-2 space-y-6">
                                
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 transition-colors">
                                    <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm mb-4 border-b dark:border-gray-700 pb-2"><i class="fas fa-sun text-orange-500 mr-2"></i> Jornada Actual</h3>
                                    
                                    <div class="mb-6 grid grid-cols-3 gap-2 text-center">
                                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 rounded-lg p-2">
                                            <p class="text-[9px] text-blue-600 dark:text-blue-400 font-bold uppercase">Hoy</p>
                                            <p class="text-xl font-black text-blue-700 dark:text-blue-300"><?php echo $diario_general; ?></p>
                                        </div>
                                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800 rounded-lg p-2">
                                            <p class="text-[9px] text-green-600 dark:text-green-400 font-bold uppercase">Confirmadas</p>
                                            <p class="text-xl font-black text-green-700 dark:text-green-300"><?php echo $diario_validadas; ?></p>
                                        </div>
                                        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-100 dark:border-yellow-800 rounded-lg p-2">
                                            <p class="text-[9px] text-yellow-600 dark:text-yellow-400 font-bold uppercase" title="Por Confirmar">Por Conf.</p>
                                            <p class="text-xl font-black text-yellow-700 dark:text-yellow-300"><?php echo $diario_por_validar; ?></p>
                                        </div>
                                    </div>

                                    <?php 
                                    if (count($sesionesAbiertas) > 0): 
                                        foreach($sesionesAbiertas as $sa):
                                    ?>
                                        <div class="border-l-4 border-yellow-400 dark:border-yellow-500 pl-4 mb-4 bg-yellow-50 dark:bg-yellow-900/10 p-3 rounded-r-lg">
                                            <h3 class="font-bold text-yellow-700 dark:text-yellow-500 text-xs"><i class="fas fa-clock fa-spin mr-1"></i> Jornada del <?php echo date('d/m h:i a', strtotime($sa['fecha'])); ?></h3>
                                            <p class="text-[11px] text-gray-600 dark:text-gray-400 mb-2">Apertura: <strong><?php echo $sa['apertura']; ?></strong> | Por: <?php echo htmlspecialchars($sa['creado_por']); ?></p>
                                            
                                            <form method="POST" action="" class="flex gap-2">
                                                <input type="hidden" name="action" value="cerrar_dia">
                                                <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                                <input type="hidden" name="historial_id" value="<?php echo $sa['id']; ?>">
                                                <input type="hidden" name="apertura_original" value="<?php echo $sa['apertura']; ?>">
                                                <input type="hidden" name="current_tab" value="hoy">
                                                
                                                <input type="number" name="cierre" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 rounded p-2 text-sm font-bold text-blue-600 dark:text-white" placeholder="Cierre final" required>
                                                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold px-3 rounded text-xs transition whitespace-nowrap">Finalizar</button>
                                            </form>
                                        </div>
                                    <?php 
                                        endforeach;
                                    endif; 
                                    ?>

                                    <form method="POST" action="" class="mt-4 pt-4 border-t dark:border-gray-700">
                                        <input type="hidden" name="action" value="iniciar_dia">
                                        <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                        <input type="hidden" name="current_tab" value="hoy">
                                        <div class="mb-3">
                                            <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 mb-1 uppercase">Apertura (Iniciar una nueva)</label>
                                            <input type="number" name="apertura" class="w-full border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-sm font-bold text-blue-700 dark:text-blue-300" required>
                                        </div>
                                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-lg shadow transition text-sm">Crear Jornada Ahora</button>
                                    </form>
                                </div>

                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 transition-colors">
                                    <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm mb-2 border-b dark:border-gray-700 pb-2"><i class="fas fa-history text-green-600 dark:text-green-400 mr-2"></i> Carga Retroactiva / Temporadas</h3>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mb-4 leading-tight">Registra días pasados. Quedará en estado "Por Confirmar" sumando al mes.</p>
                                    
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="carga_retro">
                                        <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                        <input type="hidden" name="current_tab" value="hoy">
                                        <div class="flex flex-col xl:flex-row gap-3 mb-3">
                                            <div class="w-full xl:w-1/2">
                                                <label class="block text-[9px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha Inicio</label>
                                                <input type="datetime-local" name="retro_inicio" value="<?php echo date('Y-m-d\T08:00'); ?>" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white rounded p-2 text-xs font-bold" required>
                                            </div>
                                            <div class="w-full xl:w-1/2">
                                                <label class="block text-[9px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha Fin</label>
                                                <input type="datetime-local" name="retro_fin" value="<?php echo date('Y-m-d\T17:00'); ?>" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white rounded p-2 text-xs font-bold" required>
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Cantidad Total Realizada</label>
                                            <input type="number" name="cantidad" class="w-full border border-green-300 dark:border-green-600 bg-green-50 dark:bg-gray-700 p-2.5 rounded text-lg font-bold text-green-700 dark:text-green-400" required>
                                        </div>
                                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 rounded shadow transition text-sm">Guardar Retroactivo</button>
                                    </form>
                                </div>
                            </div>

                            <div class="md:col-span-3 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden flex flex-col h-[700px] transition-colors">
                                <div class="p-4 border-b dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex justify-between items-center">
                                    <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm"><i class="fas fa-list-ul mr-1 text-gray-400"></i> Historial Activo</h3>
                                    <span class="text-[9px] text-gray-400">Excluye borrados/archivados</span>
                                </div>
                                <div class="overflow-y-auto flex-1 p-2">
                                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                                        <thead class="text-[10px] text-gray-700 dark:text-gray-300 uppercase bg-gray-50 dark:bg-gray-700/50 sticky top-0 z-10">
                                            <tr>
                                                <th class="px-3 py-2 rounded-tl-lg">Periodo / Autor</th>
                                                <th class="px-3 py-2 text-center">Datos</th>
                                                <th class="px-3 py-2 text-center rounded-tr-lg">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <?php if ($historial && $historial->num_rows > 0): ?>
                                                <?php while($h = $historial->fetch_assoc()): 
                                                    // PERMITE NEGATIVOS SI APLICA
                                                    $prod_aprobada = ($h['cierre'] - $h['apertura']);
                                                    $prod_reportada = ($h['meta_registrada'] != 0) ? ($h['meta_registrada'] - $h['apertura']) : $prod_aprobada;
                                                    
                                                    $f_ini = date("d/m H:i", strtotime($h['fecha']));
                                                    $f_fin = $h['fecha_cierre'] ? date("d/m H:i", strtotime($h['fecha_cierre'])) : '...';
                                                    
                                                    $json_data = htmlspecialchars(json_encode([
                                                        'id' => $h['id'],
                                                        'apertura' => $h['apertura'],
                                                        'cierre' => $h['cierre'],
                                                        'fecha' => date('Y-m-d\TH:i:s', strtotime($h['fecha'])),
                                                        'fecha_cierre' => $h['fecha_cierre'] ? date('Y-m-d\TH:i:s', strtotime($h['fecha_cierre'])) : ''
                                                    ]), ENT_QUOTES, 'UTF-8');
                                                ?>
                                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                                    <td class="px-3 py-3 border-r border-gray-50 dark:border-gray-700">
                                                        <div class="text-[10px] font-mono text-gray-600 dark:text-gray-300">
                                                            <span class="text-green-600">In:</span> <?php echo $f_ini; ?><br>
                                                            <span class="text-red-500">Out:</span> <?php echo $f_fin; ?>
                                                        </div>
                                                        <div class="text-[9px] text-blue-500 mt-1" title="Creado por"><i class="fas fa-user-edit"></i> <?php echo htmlspecialchars($h['creado_por'] ?? 'Sistema'); ?></div>
                                                    </td>
                                                    <td class="px-3 py-3 text-center border-r border-gray-50 dark:border-gray-700">
                                                        <?php if ($h['jornada_abierta'] == 1) { ?>
                                                            <span class="text-yellow-600 dark:text-yellow-500 italic text-xs">Abierto</span>
                                                        <?php } else if ($h['estado'] == 'confirmado') { ?>
                                                            <div class="text-green-600 dark:text-green-400 font-bold text-lg" title="Confirmadas">Apr: <?php echo $prod_aprobada; ?></div>
                                                        <?php } else { ?>
                                                            <span class="text-yellow-600 dark:text-yellow-500 font-bold text-lg" title="Reportadas"><?php echo $prod_reportada; ?></span>
                                                        <?php } ?>
                                                        <div class="text-[9px] text-gray-400 font-mono mt-1">In:<?php echo $h['apertura']; ?>-Out:<?php echo $h['cierre']; ?></div>
                                                    </td>
                                                    <td class="px-3 py-3 text-center align-middle whitespace-nowrap">
                                                        <?php if ($h['jornada_abierta'] == 1) { ?>
                                                            <span class="text-[9px] text-yellow-600 bg-yellow-100 px-2 py-1 rounded">En curso</span>
                                                        <?php } else if ($h['estado'] == 'confirmado') { ?>
                                                            <span class="text-[9px] text-green-600 font-bold bg-green-50 px-2 py-1 rounded border border-green-200">✅ Confirmada</span>
                                                        <?php } else { ?>
                                                            <span class="text-[9px] text-yellow-700 bg-yellow-50 px-2 py-1 rounded border border-yellow-200 shadow-sm">Por Confirmar</span>
                                                        <?php } ?>
                                                        
                                                        <?php if($isAdmin): ?>
                                                            <div class="mt-2 block">
                                                                <button type="button" onclick="abrirEditHistorial(<?php echo $json_data; ?>)" class="text-blue-400 hover:text-blue-600 p-1 transition" title="Editar este registro">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button type="button" onclick="eliminarHistorial(<?php echo $h['id']; ?>)" class="text-red-400 hover:text-red-600 p-1 transition" title="Eliminar este registro">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center py-10 text-gray-400">No hay registros activos.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="vista_estadisticas" class="tab-content" style="display:none;">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden flex flex-col h-[700px]">
                            <div class="p-4 border-b dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex justify-between items-center">
                                <h3 class="font-bold text-gray-700 dark:text-gray-300"><i class="fas fa-chart-line text-blue-500 mr-2"></i> Rendimiento Agrupado por Día (Últimos 30 días)</h3>
                                <div class="flex items-center gap-3">
                                    <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-1 rounded font-bold">Solo vista</span>
                                    <button onclick="exportarExcel('tabla_estadisticas', 'Estadisticas_Diarias')" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded shadow-sm text-xs font-bold transition flex items-center gap-1">
                                        <i class="fas fa-file-excel"></i> Exportar Excel
                                    </button>
                                </div>
                            </div>
                            <div class="overflow-y-auto p-4 flex-1">
                                <table id="tabla_estadisticas" class="w-full text-sm text-left text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-700 rounded-lg">
                                    <thead class="text-xs text-gray-700 dark:text-gray-300 uppercase bg-gray-100 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-4 py-3">Fecha exacta</th>
                                            <th class="px-4 py-3 text-center">Por Confirmar</th>
                                            <th class="px-4 py-3 text-center">Confirmadas</th>
                                            <th class="px-4 py-3 text-right">TOTAL DEL DÍA</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php if(count($estadisticas_diarias) > 0): ?>
                                            <?php foreach($estadisticas_diarias as $ed): 
                                                $tot = $ed['validado'] + $ed['pendiente'];
                                                $classRow = ($tot > 100) ? 'bg-blue-50 dark:bg-blue-900/10' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50';
                                                
                                                // TRADUCCIÓN A ESPAÑOL Y DÍA DE LA SEMANA
                                                $w = date("w", strtotime($ed['dia']));
                                                $d = date("d", strtotime($ed['dia']));
                                                $m = (int)date("m", strtotime($ed['dia']));
                                                $fecha_str = $dias_es[$w] . ' ' . $d . ' de ' . $meses_es[$m];
                                            ?>
                                            <tr class="<?php echo $classRow; ?> transition">
                                                <td class="px-4 py-3 font-bold text-gray-800 dark:text-gray-200"><?php echo $fecha_str; ?></td>
                                                <td class="px-4 py-3 text-center font-bold text-yellow-600"><?php echo $ed['pendiente'] != 0 ? $ed['pendiente'] : '-'; ?></td>
                                                <td class="px-4 py-3 text-center font-bold text-green-600"><?php echo $ed['validado'] != 0 ? $ed['validado'] : '-'; ?></td>
                                                <td class="px-4 py-3 text-right font-black text-blue-600 text-lg"><?php echo $tot; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center py-10 text-gray-400">No hay datos agrupables aún.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div id="vista_informes" class="tab-content" style="display:none;">
                        <div class="w-full lg:w-1/2 mx-auto">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 h-fit">
                                <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm mb-4 border-b dark:border-gray-700 pb-2"><i class="fas fa-calendar-alt text-purple-500 mr-2"></i> Calculadora por Fechas</h3>
                                <form method="POST">
                                    <input type="hidden" name="action" value="generar_informe_fechas">
                                    <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                    <input type="hidden" name="current_tab" value="informes">
                                    <div class="flex flex-col sm:flex-row gap-4 mb-4">
                                        <div class="w-full sm:w-1/2">
                                            <label class="block text-[10px] text-gray-500 dark:text-gray-400 font-bold mb-1 uppercase">Fecha de Inicio</label>
                                            <input type="date" name="fecha_inicio" value="<?php echo $_POST['fecha_inicio'] ?? date('Y-m-d'); ?>" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-2.5 rounded-lg text-sm focus:ring-purple-500" required>
                                        </div>
                                        <div class="w-full sm:w-1/2">
                                            <label class="block text-[10px] text-gray-500 dark:text-gray-400 font-bold mb-1 uppercase">Fecha de Fin</label>
                                            <input type="date" name="fecha_fin" value="<?php echo $_POST['fecha_fin'] ?? date('Y-m-d'); ?>" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-2.5 rounded-lg text-sm focus:ring-purple-500" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="w-full bg-purple-600 text-white font-bold py-3 rounded-lg shadow hover:bg-purple-700 transition">Generar Cálculo</button>
                                </form>
                                
                                <?php if($informe_resultados): ?>
                                    <div class="mt-6 border-t dark:border-gray-700 pt-6">
                                        <h4 class="text-center text-sm font-bold text-gray-600 dark:text-gray-400 mb-4">Del <span class="text-purple-600 dark:text-purple-400"><?php echo date('d/m/Y', strtotime($informe_resultados['inicio'])); ?></span> al <span class="text-purple-600 dark:text-purple-400"><?php echo date('d/m/Y', strtotime($informe_resultados['fin'])); ?></span></h4>
                                        <div class="grid grid-cols-3 gap-2 text-center">
                                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                                                <p class="text-[9px] text-blue-600 font-bold uppercase mb-1">Total</p>
                                                <p class="text-2xl font-black text-blue-700"><?php echo $informe_resultados['general']; ?></p>
                                            </div>
                                            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                                <p class="text-[9px] text-green-600 font-bold uppercase mb-1">Confirmadas</p>
                                                <p class="text-2xl font-black text-green-700"><?php echo $informe_resultados['validadas']; ?></p>
                                            </div>
                                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                                <p class="text-[9px] text-yellow-600 font-bold uppercase mb-1">Por Confirmar</p>
                                                <p class="text-2xl font-black text-yellow-700"><?php echo $informe_resultados['por_validar']; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div id="vista_drive" class="tab-content" style="display:none;">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                            <div class="md:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 h-fit transition-colors">
                                <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm mb-4 border-b dark:border-gray-700 pb-2"><i class="fas fa-cloud-upload-alt text-blue-500 mr-2"></i> Subir Archivo</h3>
                                
                                <form method="POST">
                                    <input type="hidden" name="action" value="cargar_archivo">
                                    <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                    
                                    <label class="block text-[10px] text-gray-500 dark:text-gray-400 font-bold mb-1 uppercase">Seleccionar desde Drive <i class="fab fa-google-drive text-blue-500 ml-1"></i></label>
                                    
                                    <?php if($error_drive): ?>
                                        <div class="text-[10px] text-red-600 bg-red-50 p-2 rounded border border-red-200 mb-4"><?php echo $error_drive; ?></div>
                                    <?php else: ?>
                                        <select name="archivo_drive" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg mb-4 text-sm font-semibold focus:ring-blue-500 focus:border-blue-500" required>
                                            <option value="">-- Elige un archivo --</option>
                                            <?php foreach($archivos_drive as $f): ?>
                                                <option value="<?php echo htmlspecialchars($f['name'].'||'.$f['link']); ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    
                                    <label class="block text-[10px] text-gray-500 dark:text-gray-400 font-bold mb-1 uppercase">Cantidad de Certificados (Mujeres)</label>
                                    <input type="number" name="cantidad_enviada" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg mb-4 text-lg font-bold text-blue-600 focus:ring-blue-500 focus:border-blue-500" required>
                                    
                                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg shadow hover:bg-blue-700 transition" <?php if($error_drive) echo 'disabled class="opacity-50 cursor-not-allowed"'; ?>>Registrar Carga de Drive</button>
                                </form>
                            </div>

                            <div class="md:col-span-3 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden flex flex-col h-[600px] transition-colors">
                                <div class="p-4 border-b dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex justify-between items-center">
                                    <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm"><i class="fas fa-folder-open mr-1 text-gray-400"></i> Trazabilidad de Archivos Excel</h3>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-700 px-2 py-1 rounded border dark:border-gray-600 shadow-sm">
                                        <span title="Aprobadas" class="text-green-600 dark:text-green-400 font-bold"><i class="fas fa-check"></i> <?php echo $total_aprobadas_drive; ?></span> | 
                                        <span title="En Revisión" class="text-yellow-600 dark:text-yellow-500 font-bold"><i class="fas fa-search"></i> <?php echo $total_en_revision_drive; ?></span>
                                    </div>
                                </div>
                                <div class="overflow-y-auto flex-1 p-3">
                                    <?php if ($historial_archivos && $historial_archivos->num_rows > 0): ?>
                                        <div class="space-y-3">
                                            <?php while($h = $historial_archivos->fetch_assoc()): 
                                                $color = 'gray'; $icono = 'fa-upload';
                                                if($h['estado_actual'] == 'en_revision') { $color = 'yellow'; $icono = 'fa-search'; }
                                                if($h['estado_actual'] == 'devuelto') { $color = 'red'; $icono = 'fa-undo'; }
                                                if($h['estado_actual'] == 'aprobado') { $color = 'green'; $icono = 'fa-check-double'; }
                                            ?>
                                            <div class="border border-<?php echo $color; ?>-200 dark:border-<?php echo $color; ?>-800 rounded-lg p-3 bg-<?php echo $color; ?>-50 dark:bg-<?php echo $color; ?>-900/20 transition hover:shadow-sm relative">
                                                <div class="flex justify-between items-start border-b border-<?php echo $color; ?>-200 dark:border-<?php echo $color; ?>-800 pb-2 mb-2">
                                                    <div>
                                                        <h4 class="font-bold text-gray-800 dark:text-gray-200 text-sm truncate max-w-[200px] md:max-w-xs">
                                                            <i class="fas <?php echo $icono; ?> text-<?php echo $color; ?>-500 mr-1"></i> 
                                                            <?php if(!empty($h['link_drive'])): ?>
                                                                <a href="<?php echo htmlspecialchars($h['link_drive']); ?>" target="_blank" class="hover:text-blue-600 dark:hover:text-blue-400 underline decoration-blue-300 dark:decoration-blue-700 decoration-2 underline-offset-2 transition"><?php echo htmlspecialchars($h['nombre_archivo']); ?></a>
                                                            <?php else: ?>
                                                                <?php echo htmlspecialchars($h['nombre_archivo']); ?>
                                                            <?php endif; ?>
                                                        </h4>
                                                        <div class="text-[9px] text-gray-500 dark:text-gray-400 mt-1 flex flex-col gap-0.5">
                                                            <span><i class="fas fa-upload text-gray-400"></i> Subió: <?php echo htmlspecialchars($h['registrado_por'] ?? 'Sistema'); ?></span>
                                                            <?php if(!empty($h['auditado_por'])): ?>
                                                                <span><i class="fas fa-search text-blue-400"></i> Auditó: <?php echo htmlspecialchars($h['auditado_por']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if($isAdmin): ?>
                                                        <button onclick="abrirAuditoria(<?php echo htmlspecialchars(json_encode($h), ENT_QUOTES, 'UTF-8'); ?>)" class="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 px-3 py-1 rounded text-[10px] font-bold shadow-sm transition">
                                                            Auditar
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="grid grid-cols-4 gap-2 text-center">
                                                    <div class="bg-white dark:bg-gray-800 rounded p-1 border border-gray-100 dark:border-gray-700">
                                                        <p class="text-[9px] text-gray-400 dark:text-gray-500 font-bold uppercase">Enviadas</p>
                                                        <p class="font-black text-gray-700 dark:text-gray-300"><?php echo $h['cantidad_enviada']; ?></p>
                                                    </div>
                                                    <div class="bg-white dark:bg-gray-800 rounded p-1 border border-green-100 dark:border-green-900">
                                                        <p class="text-[9px] text-green-500 font-bold uppercase">Aprobadas</p>
                                                        <p class="font-black text-green-600 dark:text-green-400"><?php echo $h['aprobadas']; ?></p>
                                                    </div>
                                                    <div class="bg-white dark:bg-gray-800 rounded p-1 border border-yellow-100 dark:border-yellow-900 relative">
                                                        <?php if($h['reconfirmado']) echo '<span class="absolute -top-1 -right-1 text-blue-500 text-[10px]" title="Reconfirmado"><i class="fas fa-check-circle"></i></span>'; ?>
                                                        <p class="text-[9px] text-yellow-600 dark:text-yellow-500 font-bold uppercase">Subsanar</p>
                                                        <p class="font-black text-yellow-700 dark:text-yellow-400"><?php echo $h['por_subsanar']; ?></p>
                                                    </div>
                                                    <div class="bg-white dark:bg-gray-800 rounded p-1 border border-gray-200 dark:border-gray-700">
                                                        <p class="text-[8px] text-gray-400 font-bold uppercase leading-tight">No<br>Reportables</p>
                                                        <p class="font-black text-gray-600 dark:text-gray-400"><?php echo $h['no_reportables']; ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endwhile; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-10 text-gray-400 text-sm">No hay archivos cargados.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="vista_reporte" class="tab-content" style="display:none;">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 w-full lg:w-2/3 mx-auto transition-colors">
                            <h3 class="font-bold text-gray-700 dark:text-gray-300 text-sm mb-4 border-b dark:border-gray-700 pb-2"><i class="fas fa-file-signature text-blue-500 mr-2"></i> Generador de Reporte</h3>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="generar_reporte">
                                <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                <input type="hidden" name="current_tab" value="reporte">
                                <div class="flex flex-col sm:flex-row gap-4 mb-4">
                                    <div class="w-full sm:w-1/2">
                                        <label class="block text-[10px] text-gray-500 dark:text-gray-400 font-bold mb-1 uppercase">Mes del Reporte</label>
                                        <select name="mes_reporte" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-2.5 rounded-lg text-sm font-semibold focus:ring-blue-500" required>
                                            <?php for($i=1; $i<=12; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php if(date('n') == $i) echo 'selected'; ?>><?php echo $meses_es[$i]; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="w-full sm:w-1/2">
                                        <label class="block text-[10px] text-gray-500 dark:text-gray-400 font-bold mb-1 uppercase">Año</label>
                                        <input type="number" name="anio_reporte" value="<?php echo date('Y'); ?>" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-2.5 rounded-lg text-sm font-semibold focus:ring-blue-500" required>
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-gray-800 dark:bg-gray-600 text-white font-bold py-3 rounded-lg shadow hover:bg-gray-900 dark:hover:bg-gray-500 transition">Generar Texto del Reporte</button>
                            </form>
                            
                            <?php if($reporte_generado): ?>
                                <div class="mt-6 border-t dark:border-gray-700 pt-4">
                                    <textarea class="w-full h-72 text-sm font-mono bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-700 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-blue-500" readonly id="texto_reporte"><?php echo $reporte_generado; ?></textarea>
                                    <button onclick="copiarReporte()" class="w-full mt-3 bg-green-500 text-white font-bold py-3 rounded-lg shadow hover:bg-green-600 transition flex justify-center items-center gap-2">
                                        <i class="fas fa-copy"></i> Copiar al portapapeles
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if($isSuperAdmin): ?>
                    <div id="vista_auditoria" class="tab-content" style="display:none;">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border-2 border-red-100 dark:border-red-900 overflow-hidden flex flex-col h-[700px]">
                            <div class="p-4 border-b border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/10 flex justify-between items-center">
                                <h3 class="font-bold text-red-700 dark:text-red-400"><i class="fas fa-user-secret mr-2"></i> Auditoría Profunda e Inmutable</h3>
                                <span class="text-[10px] bg-red-200 text-red-800 px-2 py-1 rounded font-bold">Nivel 5: Todo visible</span>
                            </div>
                            
                            <div class="p-2 overflow-y-auto flex-1 bg-gray-50 dark:bg-gray-900">
                                <p class="text-xs text-gray-500 mb-2 font-mono px-2"><i class="fas fa-info-circle"></i> Aquí ves lo que los usuarios borraron o los meses que el sistema archivó.</p>
                                <table class="w-full text-xs text-left text-gray-600 dark:text-gray-400">
                                    <thead class="text-[10px] text-gray-700 uppercase bg-gray-200 dark:bg-gray-700 sticky top-0 z-10">
                                        <tr>
                                            <th class="px-2 py-2">ID</th>
                                            <th class="px-2 py-2">Fecha Inicio / Cierre</th>
                                            <th class="px-2 py-2">In/Out</th>
                                            <th class="px-2 py-2 text-center">Estado Real</th>
                                            <th class="px-2 py-2">Usuarios/Cambios</th>
                                            <th class="px-2 py-2 text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 font-mono">
                                        <?php if ($historial_auditoria && $historial_auditoria->num_rows > 0): ?>
                                            <?php while($ha = $historial_auditoria->fetch_assoc()): 
                                                $bg = 'bg-white dark:bg-gray-800';
                                                $estado = strtoupper($ha['estado']);
                                                if($estado == 'ELIMINADO') { $bg = 'bg-red-100 dark:bg-red-900/30 text-red-800'; }
                                                elseif($estado == 'ARCHIVADO') { $bg = 'bg-gray-200 dark:bg-gray-700 text-gray-500 line-through'; }
                                                elseif($estado == 'CONFIRMADO') { $bg = 'bg-green-50 dark:bg-green-900/20 text-green-700'; }
                                                
                                                $json_data = htmlspecialchars(json_encode([
                                                    'id' => $ha['id'],
                                                    'apertura' => $ha['apertura'],
                                                    'cierre' => $ha['cierre'],
                                                    'fecha' => date('Y-m-d\TH:i:s', strtotime($ha['fecha'])),
                                                    'fecha_cierre' => $ha['fecha_cierre'] ? date('Y-m-d\TH:i:s', strtotime($ha['fecha_cierre'])) : ''
                                                ]), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr class="<?php echo $bg; ?> hover:opacity-80 transition">
                                                <td class="px-2 py-2 font-bold border-r border-gray-300 dark:border-gray-700">#<?php echo $ha['id']; ?></td>
                                                <td class="px-2 py-2 border-r border-gray-300 dark:border-gray-700">
                                                    I: <?php echo $ha['fecha']; ?><br>
                                                    F: <?php echo $ha['fecha_cierre'] ?? 'Null'; ?>
                                                </td>
                                                <td class="px-2 py-2 border-r border-gray-300 dark:border-gray-700">
                                                    In: <b><?php echo $ha['apertura']; ?></b><br>
                                                    Out: <b><?php echo $ha['cierre']; ?></b><br>
                                                    MetaReg: <?php echo $ha['meta_registrada']; ?>
                                                </td>
                                                <td class="px-2 py-2 text-center font-bold border-r border-gray-300 dark:border-gray-700"><?php echo $estado; ?></td>
                                                <td class="px-2 py-2 text-[9px] leading-tight border-r border-gray-300 dark:border-gray-700">
                                                    C: <?php echo htmlspecialchars($ha['creado_por'] ?? '-'); ?><br>
                                                    E: <?php echo htmlspecialchars($ha['editado_por'] ?? '-'); ?><br>
                                                    V: <?php echo htmlspecialchars($ha['confirmado_por'] ?? '-'); ?>
                                                </td>
                                                <td class="px-2 py-2 text-center bg-white dark:bg-gray-800 no-underline">
                                                    <button type="button" onclick="abrirEditHistorial(<?php echo $json_data; ?>)" class="text-blue-500 hover:text-blue-700 p-1 bg-blue-50 rounded" title="Forzar Edición">
                                                        <i class="fas fa-tools"></i>
                                                    </button>
                                                    <?php if($estado != 'ELIMINADO'): ?>
                                                        <button type="button" onclick="eliminarHistorial(<?php echo $ha['id']; ?>)" class="text-red-500 hover:text-red-700 p-1 bg-red-50 rounded" title="Eliminar (Soft Delete)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-center py-5">Sin registros</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div id="vista_usuarios" class="tab-content" style="display:none;">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-8 w-full lg:w-1/2 mx-auto transition-colors">
                            <h3 class="font-bold text-gray-700 dark:text-gray-200 text-lg mb-2"><i class="fas fa-key text-yellow-500 mr-2"></i> Gestión de Contraseñas</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-6 pb-4 border-b dark:border-gray-700">Cambia la contraseña de cualquier miembro del equipo al instante. Las acciones quedarán registradas en la bitácora.</p>
                            
                            <?php if(count($usuarios_lista) > 0): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="cambiar_password">
                                <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                <input type="hidden" name="current_tab" value="usuarios">
                                
                                <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1 uppercase">Seleccionar Usuario</label>
                                <select name="id_usuario" class="w-full border border-gray-300 dark:border-gray-600 p-3 rounded-lg mb-5 text-sm font-semibold bg-gray-50 dark:bg-gray-700 dark:text-white focus:bg-white dark:focus:bg-gray-600 focus:ring-blue-500 focus:border-blue-500" required>
                                    <option value="">-- Elige a quién le cambiarás la clave --</option>
                                    <?php foreach($usuarios_lista as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <label class="block text-xs font-bold text-gray-600 dark:text-gray-400 mb-1 uppercase">Escribir Nueva Contraseña</label>
                                <input type="text" name="nueva_password" class="w-full border border-gray-300 dark:border-gray-600 p-3 rounded-lg mb-6 text-lg font-bold text-gray-800 dark:text-white bg-white dark:bg-gray-700 focus:ring-blue-500 focus:border-blue-500" placeholder="Ej: NuevaClave123" required minlength="4">
                                
                                <button type="submit" class="w-full bg-gray-800 dark:bg-blue-600 text-white font-bold py-3.5 rounded-lg shadow hover:bg-gray-900 dark:hover:bg-blue-700 transition flex justify-center items-center gap-2">
                                    <i class="fas fa-lock"></i> Forzar Cambio de Contraseña
                                </button>
                            </form>
                            <?php else: ?>
                                <div class="p-4 bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg text-sm border border-red-200 dark:border-red-800">
                                    <i class="fas fa-exclamation-triangle"></i> La tabla de usuarios no contiene datos o no se pudo leer.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="flex flex-col items-center justify-center h-96 bg-white dark:bg-gray-800 rounded-xl border-dashed border-2 border-gray-200 dark:border-gray-700 transition-colors">
                        <i class="fas fa-folder-open text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                        <p class="text-gray-400 font-semibold">Selecciona o crea un proyecto</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="modal-new-project" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-[100] backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6 w-96 border border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-lg mb-4 text-gray-800 dark:text-white"><i class="fas fa-plus-circle text-blue-500 mr-2"></i> Nuevo Proyecto</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_project">
                <input type="text" name="project_name" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg mb-3 focus:ring-blue-500" placeholder="Nombre del Proyecto" required>
                <input type="number" name="project_target" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg mb-3 focus:ring-blue-500" placeholder="Meta Total (Ej: 3500)" required>
                <textarea name="project_desc" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg mb-4 focus:ring-blue-500" placeholder="Descripción opcional"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modal-new-project').classList.add('hidden')" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg font-bold transition">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold shadow transition">Crear</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-edit-history" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-[100] backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6 w-96 border border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-lg mb-2 text-gray-800 dark:text-white"><i class="fas fa-edit text-blue-500 mr-2"></i> Editar Registro</h3>
            <p class="text-[9px] text-red-500 mb-4">*Cualquier cambio aquí quedará registrado en la Auditoría inmutable para siempre.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="edit_history">
                <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                <input type="hidden" name="historial_id" id="edit_hist_id">
                <input type="hidden" name="current_tab" id="edit_current_tab">

                <div class="flex flex-col sm:flex-row gap-2 mb-4">
                    <div class="w-full sm:w-1/2">
                        <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha Inicio</label>
                        <input type="datetime-local" step="1" name="edit_fecha" id="edit_fecha" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-2 rounded-lg text-xs font-bold focus:ring-blue-500" required>
                    </div>
                    <div class="w-full sm:w-1/2">
                        <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha Cierre</label>
                        <input type="datetime-local" step="1" name="edit_fecha_cierre" id="edit_fecha_cierre" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-2 rounded-lg text-xs font-bold focus:ring-blue-500">
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-4 mb-5">
                    <div class="w-full sm:w-1/2">
                        <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Apertura (In)</label>
                        <input type="number" name="edit_apertura" id="edit_apertura" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg text-lg font-bold focus:ring-blue-500" required>
                    </div>
                    <div class="w-full sm:w-1/2">
                        <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Cierre (Out)</label>
                        <input type="number" name="edit_cierre" id="edit_cierre" class="w-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white p-3 rounded-lg text-lg font-bold focus:ring-blue-500" required>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modal-edit-history').classList.add('hidden')" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg font-bold text-sm transition">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm shadow transition">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-auditoria" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-[100] backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6 w-96 border border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-lg mb-1 border-b dark:border-gray-700 pb-2 text-gray-800 dark:text-white">Auditar Archivo</h3>
            <p id="aud_nombre" class="text-xs text-blue-600 dark:text-blue-400 font-bold mb-4 truncate"></p>
            
            <form method="POST">
                <input type="hidden" name="action" value="actualizar_archivo">
                <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                <input type="hidden" name="archivo_id" id="aud_id">
                
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Cambiar Estado a:</label>
                <select name="estado" id="aud_estado" class="w-full border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 dark:text-white p-2 rounded-lg mb-4 focus:ring-blue-500" onchange="toggleAuditoriaCampos()">
                    <option value="cargado">Recién Cargado (Plataforma)</option>
                    <option value="en_revision">En Revisión (Enviado a Auditoría)</option>
                    <option value="devuelto">Devuelto (Con Observaciones)</option>
                    <option value="aprobado">Aprobado Final</option>
                </select>

                <div id="aud_campos" class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-[10px] font-bold text-green-600 dark:text-green-400 uppercase mb-1">Aprobadas</label>
                        <input type="number" name="aprobadas" id="aud_aprobadas" class="w-full border border-green-200 dark:border-green-800 p-2 rounded bg-green-50 dark:bg-green-900/30 dark:text-white font-bold focus:ring-green-500" value="0">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-yellow-600 dark:text-yellow-500 uppercase mb-1">Subsanar</label>
                        <input type="number" name="por_subsanar" id="aud_subsanar" class="w-full border border-yellow-200 dark:border-yellow-800 p-2 rounded bg-yellow-50 dark:bg-yellow-900/30 dark:text-white font-bold focus:ring-yellow-500" value="0">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">No Reportables</label>
                        <input type="number" name="no_reportables" id="aud_no_rep" class="w-full border border-gray-200 dark:border-gray-600 p-2 rounded bg-gray-50 dark:bg-gray-700 dark:text-white font-bold focus:ring-gray-500" value="0">
                    </div>
                    <div class="col-span-2 flex items-center justify-center pt-2">
                        <label class="flex items-center gap-2 text-xs font-bold text-blue-600 dark:text-blue-400 cursor-pointer">
                            <input type="checkbox" name="reconfirmado" id="aud_reconf" class="w-4 h-4 text-blue-600 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500">
                            Reconfirmado por Auditoría
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="document.getElementById('modal-auditoria').classList.add('hidden')" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg font-bold text-sm transition">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm shadow transition">Guardar</button>
                </div>
            </form>
        </div>
    </div>
    
    <form id="formDeleteHistory" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete_history">
        <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
        <input type="hidden" name="historial_id" id="del_hist_id">
        <input type="hidden" name="current_tab" id="del_current_tab">
    </form>

    <script>
        // --- CONTROL PESTAÑAS MAESTRAS (SÚPER BLINDADO) ---
        let currentActiveTab = '<?php echo $tabActiva; ?>';
        
        const tabMap = {
            'hoy': 'vista_hoy',
            'retroactivo': 'vista_hoy', 
            'estadisticas': 'vista_estadisticas',
            'informes': 'vista_informes',
            'drive': 'vista_drive',
            'reporte': 'vista_reporte',
            'auditoria': 'vista_auditoria',
            'usuarios': 'vista_usuarios'
        };

        function switchMasterTab(tab) {
            currentActiveTab = tab;
            
            // Actualizar URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);

            // 1. Ocultar todos los contenidos de forma forzada usando style para evitar conflictos
            document.querySelectorAll('.tab-content').forEach(el => {
                el.style.display = 'none';
            });
            
            // 2. Apagar diseño de todos los botones
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('border-blue-500', 'text-blue-600', 'dark:text-blue-400');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            
            // 3. Obtener el ID correcto
            let vistaId = tabMap[tab];
            if(!vistaId || !document.getElementById(vistaId)) {
                vistaId = 'vista_hoy';
                tab = 'hoy'; 
            }
            
            // 4. Mostrar el contenedor elegido
            document.getElementById(vistaId).style.display = 'block';
            
            // 5. Encender el boton elegido
            let btnTab = document.getElementById('mtab_' + tab) || document.getElementById('mtab_hoy');
            if(btnTab) {
                btnTab.classList.remove('border-transparent', 'text-gray-500');
                btnTab.classList.add('border-blue-500', 'text-blue-600', 'dark:text-blue-400');
            }
        }

        // --- MODO OSCURO NATIVO ---
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        const themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        if (document.documentElement.classList.contains('dark')) {
            themeToggleLightIcon.classList.remove('hidden');
        } else {
            themeToggleDarkIcon.classList.remove('hidden');
        }

        themeToggleBtn.addEventListener('click', function() {
            themeToggleDarkIcon.classList.toggle('hidden');
            themeToggleLightIcon.classList.toggle('hidden');

            if (localStorage.getItem('color-theme')) {
                if (localStorage.getItem('color-theme') === 'light') {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                }
            } else {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                }
            }
        });

        // ==========================================
        // ✅ EXPORTAR TABLA A EXCEL
        // ==========================================
        function exportarExcel(tableID, filename = ''){
            var tableSelect = document.getElementById(tableID);
            // Clonamos la tabla para generar un HTML limpio
            var cloneTable = tableSelect.cloneNode(true);
            
            // Creamos un HTML válido para Excel con codificación UTF-8 para evitar problemas con las tildes
            var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            html += '<head><meta charset="utf-8"></head><body>';
            html += cloneTable.outerHTML;
            html += '</body></html>';

            var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
            var downloadLink = document.createElement("a");
            document.body.appendChild(downloadLink);

            downloadLink.href = URL.createObjectURL(blob);
            downloadLink.download = filename ? filename + '.xls' : 'Estadisticas.xls';
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
        // ==========================================

        // ==========================================
        // ✅ ALERTA PARA CIERRE DE MES
        // ==========================================
        function confirmarCierre(form) {
            Swal.fire({
                title: '¿Cerrar y validar el mes?',
                text: "Esto archivará los registros por confirmar sueltos de ese mes y creará un solo registro oficial confirmado. ¡Tus números quedarán cuadrados!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, consolidar mes',
                cancelButtonText: 'Cancelar',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#ffffff' : '#545454'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
        // ==========================================

        function abrirEditHistorial(data) {
            document.getElementById('edit_hist_id').value = data.id;
            document.getElementById('edit_fecha').value = data.fecha;
            document.getElementById('edit_fecha_cierre').value = data.fecha_cierre;
            document.getElementById('edit_apertura').value = data.apertura;
            document.getElementById('edit_cierre').value = data.cierre;
            document.getElementById('edit_current_tab').value = currentActiveTab;
            document.getElementById('modal-edit-history').classList.remove('hidden');
        }

        // SOFT DELETE (Auditoría)
        function eliminarHistorial(id) {
            Swal.fire({
                title: '¿Eliminar de la vista pública?',
                text: "Este registro desaparecerá de las estadísticas, pero los administradores siempre podrán verlo en la Auditoría Inmutable.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#ffffff' : '#545454'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('del_hist_id').value = id;
                    document.getElementById('del_current_tab').value = currentActiveTab;
                    document.getElementById('formDeleteHistory').submit();
                }
            });
        }

        function abrirAuditoria(data) {
            document.getElementById('aud_id').value = data.id;
            document.getElementById('aud_nombre').innerText = data.nombre_archivo;
            document.getElementById('aud_estado').value = data.estado_actual;
            document.getElementById('aud_aprobadas').value = data.aprobadas;
            document.getElementById('aud_subsanar').value = data.por_subsanar;
            document.getElementById('aud_no_rep').value = data.no_reportables;
            document.getElementById('aud_reconf').checked = data.reconfirmado == 1;
            
            toggleAuditoriaCampos();
            document.getElementById('modal-auditoria').classList.remove('hidden');
        }

        function toggleAuditoriaCampos() {
            const estado = document.getElementById('aud_estado').value;
            const campos = document.getElementById('aud_campos');
            if(estado === 'cargado') {
                campos.style.opacity = '0.4';
                campos.style.pointerEvents = 'none';
            } else {
                campos.style.opacity = '1';
                campos.style.pointerEvents = 'auto';
            }
        }

        function copiarReporte() {
            var copyText = document.getElementById("texto_reporte");
            copyText.select();
            document.execCommand("copy");
            Swal.fire({
                icon: 'success',
                title: '¡Copiado!',
                text: 'El reporte está en tu portapapeles.',
                showConfirmButton: false,
                timer: 1500,
                background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                color: document.documentElement.classList.contains('dark') ? '#ffffff' : '#545454'
            });
        }

        // Iniciar en la pestaña correcta al cargar
        switchMasterTab(currentActiveTab);
    </script>

    <?php if(isset($_SESSION['swal_success'])): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: '¡Éxito!',
            text: '<?php echo $_SESSION['swal_success']; ?>',
            timer: 3500,
            showConfirmButton: false,
            background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#ffffff' : '#545454'
        });
    </script>
    <?php unset($_SESSION['swal_success']); endif; ?>

    <?php if(isset($_SESSION['swal_error'])): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Aviso del Sistema',
            text: '<?php echo $_SESSION['swal_error']; ?>',
            confirmButtonColor: '#2563eb',
            background: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#ffffff' : '#545454'
        });
    </script>
    <?php unset($_SESSION['swal_error']); endif; ?>
    
</body>
</html>