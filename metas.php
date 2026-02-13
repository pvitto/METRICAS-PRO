<?php
// 1. CONFIGURACIÓN DE ERRORES
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. CONFIGURACIÓN DE ZONA HORARIA
date_default_timezone_set('America/Bogota');

// 3. SEGURIDAD Y CONEXIÓN
session_start();
if (!isset($_SESSION['meta_user_id'])) { header("Location: login.php"); exit(); }
require_once 'db_connection.php';

$userName = $_SESSION['meta_user_name'] ?? 'Usuario';
$meses_es = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// ---------------------------------------------------------
// CONEXIÓN A GOOGLE DRIVE API
// ---------------------------------------------------------
$archivos_drive = [];
$error_drive = "";

// USAMOS __DIR__ PARA RUTAS ABSOLUTAS E INFALIBLES
$archivo_credenciales = __DIR__ . '/credenciales.json';
$ruta_autoload = __DIR__ . '/google_api/vendor/autoload.php';

if (file_exists($ruta_autoload) && file_exists($archivo_credenciales)) {
    try {
        require_once $ruta_autoload;
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $archivo_credenciales);
        
        $client = new Google\Client();
        $client->useApplicationDefaultCredentials();
        $client->addScope(Google\Service\Drive::DRIVE_READONLY);
        $driveService = new Google\Service\Drive($client);

        // TU CARPETA DE DRIVE
        $folderId = '1iXon5Xmknvxi6vqEFV4u5zck0U4grZYq';
        
        $optParams = array(
          'q' => "'$folderId' in parents and trashed=false",
          'pageSize' => 50,
          'fields' => 'nextPageToken, files(id, name, webViewLink)',
          'orderBy' => 'createdTime desc'
        );
        $results = $driveService->files->listFiles($optParams);
        
        foreach ($results->getFiles() as $file) {
            $archivos_drive[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'link' => $file->getWebViewLink()
            ];
        }
    } catch (Exception $e) {
        $error_drive = "Error conectando a Drive: " . $e->getMessage();
    }
} else {
    // DIAGNÓSTICO EXACTO DE RUTAS
    $error_drive = "Error de rutas. PHP no encuentra:<br>";
    if (!file_exists($ruta_autoload)) $error_drive .= "❌ Autoload en: " . $ruta_autoload . "<br>";
    if (!file_exists($archivo_credenciales)) $error_drive .= "❌ JSON en: " . $archivo_credenciales;
}

// ---------------------------------------------------------
// LÓGICA (POST)
// ---------------------------------------------------------

// A) CREAR PROYECTO
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'create_project') {
    $name = $conn->real_escape_string($_POST['project_name']);
    $desc = $conn->real_escape_string($_POST['project_desc']);
    $target = (int)$_POST['project_target'];
    $conn->query("INSERT INTO project_goals (name, description, target, current) VALUES ('$name', '$desc', $target, 0)");
    header("Location: metas.php?project_id=" . $conn->insert_id);
    exit();
}

// B) EDITAR META
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_target') {
    $pid = (int)$_POST['project_id'];
    $newTarget = (int)$_POST['new_target'];
    $conn->query("UPDATE project_goals SET target = $newTarget WHERE id = $pid");
    header("Location: metas.php?project_id=$pid");
    exit();
}

// C) CARGAR ARCHIVO DESDE DRIVE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cargar_archivo') {
    $pid = (int)$_POST['project_id'];
    $cantidad = (int)$_POST['cantidad_enviada'];
    $fecha = date('Y-m-d H:i:s');
    
    // Separamos el nombre y el link que vienen del Select
    $drive_data = explode('||', $_POST['archivo_drive']);
    $nombre = $conn->real_escape_string($drive_data[0]);
    $link = isset($drive_data[1]) ? $conn->real_escape_string($drive_data[1]) : '';
    
    $stmt = $conn->prepare("INSERT INTO seguimiento_archivos (project_id, nombre_archivo, link_drive, fecha_subida, estado_actual, cantidad_enviada, registrado_por) VALUES (?, ?, ?, ?, 'cargado', ?, ?)");
    $stmt->bind_param("isssis", $pid, $nombre, $link, $fecha, $cantidad, $userName);
    $stmt->execute();
    header("Location: metas.php?project_id=$pid");
    exit();
}

// D) ACTUALIZAR ESTADO DEL ARCHIVO (Auditoría)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'actualizar_archivo') {
    $id_archivo = (int)$_POST['archivo_id'];
    $pid = (int)$_POST['project_id'];
    $nuevo_estado = $conn->real_escape_string($_POST['estado']);
    $aprobadas = (int)($_POST['aprobadas'] ?? 0);
    $subsanar = (int)($_POST['por_subsanar'] ?? 0);
    $no_reportables = (int)($_POST['no_reportables'] ?? 0);
    $reconfirmado = isset($_POST['reconfirmado']) ? 1 : 0;
    
    $fecha_ahora = date('Y-m-d H:i:s');
    $sql_fechas = "";
    
    if ($nuevo_estado == 'en_revision') { 
        $sql_fechas = ", fecha_envio_revision = IFNULL(fecha_envio_revision, '$fecha_ahora')"; 
    } elseif ($nuevo_estado == 'devuelto') { 
        $sql_fechas = ", fecha_devolucion = '$fecha_ahora'"; 
    }

    $sql = "UPDATE seguimiento_archivos SET 
            estado_actual = '$nuevo_estado', 
            aprobadas = $aprobadas, 
            por_subsanar = $subsanar, 
            no_reportables = $no_reportables, 
            reconfirmado = $reconfirmado 
            $sql_fechas 
            WHERE id = $id_archivo";
    
    $conn->query($sql);
    header("Location: metas.php?project_id=$pid");
    exit();
}

// ---------------------------------------------------------
// LECTURA DE DATOS
// ---------------------------------------------------------
$proyectos = [];
$q = $conn->query("SELECT * FROM project_goals ORDER BY id ASC");
if($q) { while($row = $q->fetch_assoc()) { $proyectos[] = $row; } }

$currentProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (count($proyectos) > 0 ? $proyectos[0]['id'] : null);
$currentProject = null;
$historial_archivos = null;
$reporte_generado = "";

$total_aprobadas = 0;
$total_en_revision = 0;
$total_no_reportables = 0;

if ($currentProjectId) {
    foreach ($proyectos as $p) { if ($p['id'] == $currentProjectId) { $currentProject = $p; break; } }
    
    $historial_archivos = $conn->query("SELECT * FROM seguimiento_archivos WHERE project_id = $currentProjectId ORDER BY fecha_subida DESC LIMIT 50");

    $qStats = $conn->query("SELECT 
        SUM(aprobadas) as aprobadas, 
        SUM(no_reportables) as no_rep,
        SUM(CASE WHEN estado_actual IN ('cargado', 'en_revision', 'devuelto') THEN (cantidad_enviada - aprobadas - no_reportables) ELSE 0 END) as en_revision
        FROM seguimiento_archivos WHERE project_id = $currentProjectId");
    
    $rStats = $qStats->fetch_assoc();
    $total_aprobadas = $rStats['aprobadas'] ?? 0;
    $total_en_revision = $rStats['en_revision'] ?? 0;
    $total_no_reportables = $rStats['no_rep'] ?? 0;

    // GENERADOR DE REPORTE MENSUAL
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'generar_reporte') {
        $mes_filtro = (int)$_POST['mes_reporte'];
        $anio_filtro = (int)$_POST['anio_reporte'];
        $meta = $currentProject['target'];
        
        $qMeses = $conn->query("SELECT MONTH(fecha_subida) as mes, SUM(aprobadas) as aprobadas FROM seguimiento_archivos WHERE project_id = $currentProjectId AND YEAR(fecha_subida) = $anio_filtro AND MONTH(fecha_subida) < $mes_filtro GROUP BY mes ORDER BY mes ASC");
        
        $qMesActual = $conn->query("SELECT SUM(aprobadas) as aprobadas, SUM(CASE WHEN estado_actual != 'aprobado' THEN (cantidad_enviada - aprobadas - no_reportables) ELSE 0 END) as revision FROM seguimiento_archivos WHERE project_id = $currentProjectId AND MONTH(fecha_subida) = $mes_filtro AND YEAR(fecha_subida) = $anio_filtro");
        $dMes = $qMesActual->fetch_assoc();
        $mes_aprobadas = $dMes['aprobadas'] ?? 0;
        $mes_revision = $dMes['revision'] ?? 0;
        $mes_total = $mes_aprobadas + $mes_revision;
        $para_completar = $meta - $total_aprobadas;
        
        $reporte_generado = "Metas formación\n\n";
        $reporte_generado .= "Meta global: ✨ ".number_format($meta)." mujeres ✨\n\n";
        $reporte_generado .= "Avance mensual:\n";
        
        while($rm = $qMeses->fetch_assoc()) {
            if($rm['aprobadas'] > 0) {
                $reporte_generado .= "* " . $meses_es[$rm['mes']] . ": " . number_format($rm['aprobadas']) . " mujeres certificadas\n";
            }
        }
        
        $reporte_generado .= "* " . $meses_es[$mes_filtro] . ":\n";
        $reporte_generado .= "  - aprobadas: " . number_format($mes_aprobadas) . "\n";
        $reporte_generado .= "  - por revisión: " . number_format($mes_revision) . "\n";
        $reporte_generado .= "  - total: " . number_format($mes_total) . "\n";
        $reporte_generado .= "  - para completar la meta: " . number_format($para_completar) . "\n\n";
        $reporte_generado .= "Total global: " . number_format($total_aprobadas + $total_en_revision) . "\n";
        $reporte_generado .= "Total mujeres fase 1 no reportables: " . number_format($total_no_reportables);
    }
}

$metaTotal = $currentProject['target'] ?? 0;
$logradoTotal = $total_aprobadas;
$falta = max(0, $metaTotal - $logradoTotal);
$pct_ok = ($metaTotal > 0) ? ($total_aprobadas / $metaTotal) * 100 : 0;
$pct_pend = ($metaTotal > 0) ? ($total_en_revision / $metaTotal) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metas - Métrica Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .nav-item.active { background-color: #eff6ff; border-left: 4px solid #2563eb; color: #1e40af; }
        .bar-stripe { background-image: linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent); background-size: 1rem 1rem; }
    </style>
</head>
<body class="text-gray-800">

    <header class="bg-white shadow-sm border-b sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-600 text-white p-2 rounded-lg"><i class="fas fa-folder-open"></i></div>
                    <div><h1 class="text-xl font-bold text-gray-900">Control de Archivos</h1></div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600">Hola, <span class="font-bold"><?php echo htmlspecialchars($userName); ?></span></span>
                    <a href="logout.php" class="text-sm text-red-600 border border-red-200 px-3 py-1 rounded hover:bg-red-50 font-bold">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden sticky top-24">
                    <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
                        <h2 class="font-bold text-gray-700 text-sm uppercase">Proyectos</h2>
                        <button onclick="document.getElementById('modal-new-project').classList.remove('hidden')" class="text-xs bg-blue-600 text-white px-2 py-1 rounded"><i class="fas fa-plus"></i></button>
                    </div>
                    <nav class="flex flex-col max-h-[70vh] overflow-y-auto">
                        <?php if (count($proyectos) > 0): ?>
                            <?php foreach($proyectos as $p): 
                                $p_pct = ($p['target'] > 0) ? ($p['current']/$p['target'])*100 : 0;
                            ?>
                                <a href="metas.php?project_id=<?php echo $p['id']; ?>" class="nav-item p-4 border-b border-gray-50 block <?php echo ($currentProjectId == $p['id']) ? 'active' : 'text-gray-600'; ?>">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="font-semibold text-sm truncate"><?php echo htmlspecialchars($p['name']); ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-1 mt-2">
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
                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
                        <div class="flex justify-between items-end mb-4">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                                    <?php echo htmlspecialchars($currentProject['name']); ?>
                                    <button onclick="editarMetaActual(<?php echo $currentProject['target']; ?>)" class="text-gray-300 hover:text-blue-500 text-sm transition"><i class="fas fa-pencil-alt"></i></button>
                                </h2>
                                <div class="flex items-center gap-2 mt-2">
                                    <span class="bg-red-100 text-red-700 text-xs font-bold px-2 py-1 rounded">FALTAN: <?php echo number_format($falta); ?></span>
                                    <span class="text-xs text-gray-400 uppercase font-bold">Meta: <?php echo number_format($metaTotal); ?></span>
                                </div>
                                <div class="flex items-center gap-3 mt-3 text-[11px] text-gray-600">
                                    <span class="flex items-center gap-1 bg-green-50 px-2 py-1 rounded border border-green-100"><span class="w-2 h-2 bg-green-500 rounded-full"></span> Aprobadas: <strong><?php echo $total_aprobadas; ?></strong></span>
                                    <span class="flex items-center gap-1 bg-yellow-50 px-2 py-1 rounded border border-yellow-100"><span class="w-2 h-2 bg-yellow-400 rounded-full"></span> En Revisión: <strong><?php echo $total_en_revision; ?></strong></span>
                                    <span class="flex items-center gap-1 bg-gray-50 px-2 py-1 rounded border border-gray-200"><span class="w-2 h-2 bg-gray-400 rounded-full"></span> No Reportables: <strong><?php echo $total_no_reportables; ?></strong></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-bold text-blue-600"><?php echo number_format($total_aprobadas); ?></p>
                                <p class="text-[10px] text-gray-400 uppercase font-bold">Aprobadas Totales</p>
                            </div>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-5 flex overflow-hidden shadow-inner">
                            <div class="bg-green-500 h-5 transition-all duration-1000 flex items-center justify-center text-[9px] text-white font-bold" style="width: <?php echo $pct_ok; ?>%">
                                <?php echo ($pct_ok > 5) ? round($pct_ok).'%' : ''; ?>
                            </div>
                            <div class="bg-yellow-400 h-5 transition-all duration-1000 bar-stripe flex items-center justify-center text-[9px] text-yellow-900 font-bold" style="width: <?php echo $pct_pend; ?>%">
                                <?php echo ($pct_pend > 5) ? round($pct_pend).'%' : ''; ?>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                        
                        <div class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6 h-fit">
                            <div class="mb-4 border-b pb-2 flex gap-4 text-xs font-bold uppercase tracking-wider">
                                <button onclick="showTab('tab_carga')" id="btn_carga" class="text-blue-600 border-b-2 border-blue-600 pb-1">Subir Archivo</button>
                                <button onclick="showTab('tab_reporte')" id="btn_reporte" class="text-gray-400 pb-1">Reporte</button>
                            </div>
                            
                            <div id="tab_carga">
                                <form method="POST">
                                    <input type="hidden" name="action" value="cargar_archivo">
                                    <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                    
                                    <label class="block text-[10px] text-gray-500 font-bold mb-1 uppercase">Seleccionar desde Drive <i class="fab fa-google-drive text-blue-500 ml-1"></i></label>
                                    
                                    <?php if($error_drive): ?>
                                        <div class="text-[10px] text-red-600 bg-red-50 p-2 rounded border border-red-200 mb-4"><?php echo $error_drive; ?></div>
                                    <?php else: ?>
                                        <select name="archivo_drive" class="w-full border p-3 rounded-lg mb-4 text-sm font-semibold" required>
                                            <option value="">-- Elige un archivo --</option>
                                            <?php foreach($archivos_drive as $f): ?>
                                                <option value="<?php echo htmlspecialchars($f['name'].'||'.$f['link']); ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    
                                    <label class="block text-[10px] text-gray-500 font-bold mb-1 uppercase">Cantidad de Certificados (Mujeres)</label>
                                    <input type="number" name="cantidad_enviada" class="w-full border p-3 rounded-lg mb-4 text-lg font-bold text-blue-600" required>
                                    
                                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg shadow hover:bg-blue-700 transition" <?php if($error_drive) echo 'disabled class="opacity-50 cursor-not-allowed"'; ?>>Registrar Carga</button>
                                </form>
                            </div>
                            
                            <div id="tab_reporte" class="hidden">
                                <form method="POST">
                                    <input type="hidden" name="action" value="generar_reporte">
                                    <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                                    <div class="flex gap-2 mb-4">
                                        <div class="w-1/2">
                                            <label class="block text-[10px] text-gray-500 font-bold mb-1 uppercase">Mes</label>
                                            <select name="mes_reporte" class="w-full border p-2 rounded-lg" required>
                                                <?php for($i=1; $i<=12; $i++): ?>
                                                    <option value="<?php echo $i; ?>" <?php if(date('n') == $i) echo 'selected'; ?>><?php echo $meses_es[$i]; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="w-1/2">
                                            <label class="block text-[10px] text-gray-500 font-bold mb-1 uppercase">Año</label>
                                            <input type="number" name="anio_reporte" value="<?php echo date('Y'); ?>" class="w-full border p-2 rounded-lg" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="w-full bg-gray-800 text-white font-bold py-3 rounded-lg shadow hover:bg-gray-900 transition">Generar Texto</button>
                                </form>
                                
                                <?php if($reporte_generado): ?>
                                    <div class="mt-4">
                                        <textarea class="w-full h-64 text-xs font-mono bg-gray-50 border border-gray-200 rounded p-2 focus:outline-none" readonly id="texto_reporte"><?php echo $reporte_generado; ?></textarea>
                                        <button onclick="copiarReporte()" class="w-full mt-2 bg-green-500 text-white text-xs font-bold py-2 rounded hover:bg-green-600"><i class="fas fa-copy"></i> Copiar al portapapeles</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="md:col-span-3 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden flex flex-col h-[600px]">
                            <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
                                <h3 class="font-bold text-gray-700 text-xs uppercase">Trazabilidad de Archivos</h3>
                            </div>
                            <div class="overflow-y-auto flex-1 p-2">
                                <?php if ($historial_archivos && $historial_archivos->num_rows > 0): ?>
                                    <div class="space-y-3">
                                        <?php while($h = $historial_archivos->fetch_assoc()): 
                                            $color = 'gray'; $icono = 'fa-upload';
                                            if($h['estado_actual'] == 'en_revision') { $color = 'yellow'; $icono = 'fa-search'; }
                                            if($h['estado_actual'] == 'devuelto') { $color = 'red'; $icono = 'fa-undo'; }
                                            if($h['estado_actual'] == 'aprobado') { $color = 'green'; $icono = 'fa-check-double'; }
                                        ?>
                                        <div class="border border-<?php echo $color; ?>-200 rounded-lg p-3 bg-<?php echo $color; ?>-50">
                                            <div class="flex justify-between items-start border-b border-<?php echo $color; ?>-200 pb-2 mb-2">
                                                <div>
                                                    <h4 class="font-bold text-gray-800 text-sm">
                                                        <i class="fas <?php echo $icono; ?> text-<?php echo $color; ?>-500 mr-1"></i> 
                                                        <?php if(!empty($h['link_drive'])): ?>
                                                            <a href="<?php echo htmlspecialchars($h['link_drive']); ?>" target="_blank" class="hover:text-blue-600 underline decoration-blue-300 decoration-2 underline-offset-2 transition"><?php echo htmlspecialchars($h['nombre_archivo']); ?></a>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($h['nombre_archivo']); ?>
                                                        <?php endif; ?>
                                                    </h4>
                                                    <p class="text-[10px] text-gray-500 uppercase mt-1">Cargado: <?php echo date("d/m/Y H:i", strtotime($h['fecha_subida'])); ?></p>
                                                </div>
                                                <button onclick='abrirAuditoria(<?php echo json_encode($h); ?>)' class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-100 px-3 py-1 rounded text-[10px] font-bold shadow-sm transition">
                                                    Auditar
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-4 gap-2 text-center">
                                                <div class="bg-white rounded p-1 border border-gray-100">
                                                    <p class="text-[9px] text-gray-400 font-bold uppercase">Enviadas</p>
                                                    <p class="font-black text-gray-700"><?php echo $h['cantidad_enviada']; ?></p>
                                                </div>
                                                <div class="bg-white rounded p-1 border border-green-100">
                                                    <p class="text-[9px] text-green-500 font-bold uppercase">Aprobadas</p>
                                                    <p class="font-black text-green-600"><?php echo $h['aprobadas']; ?></p>
                                                </div>
                                                <div class="bg-white rounded p-1 border border-yellow-100 relative">
                                                    <?php if($h['reconfirmado']) echo '<span class="absolute -top-1 -right-1 text-blue-500 text-[10px]" title="Reconfirmado"><i class="fas fa-check-circle"></i></span>'; ?>
                                                    <p class="text-[9px] text-yellow-600 font-bold uppercase">Subsanar</p>
                                                    <p class="font-black text-yellow-700"><?php echo $h['por_subsanar']; ?></p>
                                                </div>
                                                <div class="bg-white rounded p-1 border border-gray-200">
                                                    <p class="text-[8px] text-gray-400 font-bold uppercase leading-tight">No<br>Reportables</p>
                                                    <p class="font-black text-gray-600"><?php echo $h['no_reportables']; ?></p>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="modal-new-project" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-[100]">
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-96">
            <h3 class="font-bold text-lg mb-4">Nuevo Proyecto</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_project">
                <input type="text" name="project_name" class="w-full border p-3 rounded-lg mb-3" placeholder="Nombre del Proyecto" required>
                <input type="number" name="project_target" class="w-full border p-3 rounded-lg mb-3" placeholder="Meta Total (Ej: 3500)" required>
                <textarea name="project_desc" class="w-full border p-3 rounded-lg mb-4" placeholder="Descripción opcional"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modal-new-project').classList.add('hidden')" class="px-4 py-2 bg-gray-100 rounded-lg font-bold">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-bold">Crear</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-auditoria" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-[100]">
        <div class="bg-white rounded-2xl shadow-2xl p-6 w-96">
            <h3 class="font-bold text-lg mb-1 border-b pb-2">Auditar Archivo</h3>
            <p id="aud_nombre" class="text-xs text-blue-600 font-bold mb-4"></p>
            
            <form method="POST">
                <input type="hidden" name="action" value="actualizar_archivo">
                <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
                <input type="hidden" name="archivo_id" id="aud_id">
                
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cambiar Estado a:</label>
                <select name="estado" id="aud_estado" class="w-full border p-2 rounded-lg mb-4 bg-gray-50" onchange="toggleAuditoriaCampos()">
                    <option value="cargado">Recién Cargado (Plataforma)</option>
                    <option value="en_revision">En Revisión (Enviado a Auditoría)</option>
                    <option value="devuelto">Devuelto (Con Observaciones)</option>
                    <option value="aprobado">Aprobado Final</option>
                </select>

                <div id="aud_campos" class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-[10px] font-bold text-green-600 uppercase mb-1">Aprobadas</label>
                        <input type="number" name="aprobadas" id="aud_aprobadas" class="w-full border p-2 rounded bg-green-50 font-bold" value="0">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-yellow-600 uppercase mb-1">Subsanar</label>
                        <input type="number" name="por_subsanar" id="aud_subsanar" class="w-full border p-2 rounded bg-yellow-50 font-bold" value="0">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">No Reportables</label>
                        <input type="number" name="no_reportables" id="aud_no_rep" class="w-full border p-2 rounded bg-gray-50 font-bold" value="0">
                    </div>
                    <div class="flex items-center justify-center pt-4">
                        <label class="flex items-center gap-2 text-xs font-bold text-blue-600 cursor-pointer">
                            <input type="checkbox" name="reconfirmado" id="aud_reconf" class="w-4 h-4">
                            Reconfirmado
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modal-auditoria').classList.add('hidden')" class="px-4 py-2 bg-gray-100 rounded-lg font-bold text-sm">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-bold text-sm">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <form id="formUpdateTarget" method="POST" style="display:none;">
        <input type="hidden" name="action" value="update_target">
        <input type="hidden" name="project_id" value="<?php echo $currentProjectId; ?>">
        <input type="hidden" name="new_target" id="new_target_val">
    </form>

    <script>
        function showTab(id) {
            document.getElementById('tab_carga').classList.add('hidden');
            document.getElementById('tab_reporte').classList.add('hidden');
            document.getElementById(id).classList.remove('hidden');
            document.getElementById('btn_carga').className = id === 'tab_carga' ? 'text-blue-600 border-b-2 border-blue-600 pb-1' : 'text-gray-400 pb-1';
            document.getElementById('btn_reporte').className = id === 'tab_reporte' ? 'text-gray-800 border-b-2 border-gray-800 pb-1' : 'text-gray-400 pb-1';
        }

        function editarMetaActual(metaActual) {
            Swal.fire({
                title: 'Ajustar Meta',
                input: 'number',
                inputValue: metaActual,
                showCancelButton: true,
                confirmButtonText: 'Actualizar'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('new_target_val').value = result.value;
                    document.getElementById('formUpdateTarget').submit();
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
            Swal.fire('¡Copiado!', 'El reporte está en tu portapapeles.', 'success');
        }
        
        <?php if($reporte_generado): ?>
            showTab('tab_reporte');
        <?php endif; ?>
    </script>
</body>
</html>