<?php
/**
 * EXPORTADOR COMPLETO - OPTIMIZADO PARA GRANDES VOLÚMENES DE DATOS
 * Ejecutar desde navegador: http://localhost/control_produccion/public/exportar_todo.php
 * 
 * Exporta TODAS las tablas: produccion, registros_antiguos, registro_quiebras
 * SIN filtros de fecha ni hora
 * UTILIZA ESCRITURA DIRECTA EN CSV (sin acumular en memoria)
 */

// ============================================
// CONFIGURACIÓN
// ============================================

// Configurar zona horaria
date_default_timezone_set('America/Costa_Rica');
ini_set('date.timezone', 'America/Costa_Rica');

// Configurar tiempo de ejecución ilimitado
set_time_limit(0);
ini_set('memory_limit', '2048M');

// Deshabilitar buffer de salida para evitar problemas de memoria
while (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

// Carpeta de destino (OneDrive)
$carpeta_destino = 'C:\\Users\\Auxiliar Matriz\\OneDrive - Grupo Noma S.A\\produccion y quiebras';

// Modo: false = guardar en carpeta, true = descargar directamente
$forzar_descarga = false;

// ============================================
// CONEXIÓN A BASE DE DATOS
// ============================================

// Ruta a la configuración de base de datos
$config_path = __DIR__ . '/../config/database.php';

if (!file_exists($config_path)) {
    $rutas_posibles = [
        __DIR__ . '/config/database.php',
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../../config/database.php',
        'C:/xampp/htdocs/control_produccion/config/database.php'
    ];
    
    foreach ($rutas_posibles as $ruta) {
        if (file_exists($ruta)) {
            $config_path = $ruta;
            break;
        }
    }
}

if (!file_exists($config_path)) {
    die("❌ Error: No se encuentra el archivo de configuración database.php<br>
         Buscado en: " . htmlspecialchars($config_path));
}

require_once $config_path;

if (!$conn || $conn->connect_error) {
    die("❌ Error de conexión a la base de datos: " . ($conn->connect_error ?? "Conexión fallida"));
}

// Configurar charset para evitar problemas con caracteres especiales
$conn->set_charset("utf8");

echo "<pre style='font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 8px; overflow-x: auto;'>";
echo "<span style='color: #4ec9b0;'>✅ Conexión exitosa a la base de datos</span>\n";
echo "<span style='color: #ce9178;'>📅 Exportando TODOS los registros (sin filtros)</span>\n";
echo "<span style='color: #6a9955;'>⏰ Inicio: " . date('Y-m-d H:i:s') . "</span>\n\n";

// ============================================
// PREPARAR ARCHIVO CSV
// ============================================

$nombre_archivo = 'exportacion_completa_' . date('Y-m-d_H-i-s') . '.csv';

// Abrir archivo para escritura
if ($forzar_descarga) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
    $output = fopen('php://output', 'w');
    echo "<span style='color: #6a9955;'>📥 Descargando directamente al navegador...</span>\n";
} else {
    if (!is_dir($carpeta_destino)) {
        mkdir($carpeta_destino, 0777, true);
    }
    $ruta_completa = $carpeta_destino . '\\' . $nombre_archivo;
    $output = fopen($ruta_completa, 'w');
    if (!$output) {
        die("❌ Error: No se puede crear el archivo en $ruta_completa\n");
    }
    echo "<span style='color: #9cdcfe;'>📁 Carpeta destino: $carpeta_destino</span>\n";
    echo "<span style='color: #9cdcfe;'>📄 Archivo: $nombre_archivo</span>\n";
}

// Escribir BOM para UTF-8 (compatibilidad Excel)
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Cabeceras
$cabeceras = ['Fecha', 'Hora', 'Tipo', 'Empleado', 'Área', 'Equipo', 'Orden', 'Motivo/Detalle', 'Responsable', 'Turno'];
fputcsv($output, $cabeceras);

$total_registros = 0;

// ============================================
// FUNCIÓN PARA ESCRIBIR UNA FILA
// ============================================

function escribirFila($output, $fila) {
    global $total_registros;
    fputcsv($output, $fila);
    $total_registros++;
    
    // Mostrar progreso cada 10,000 registros
    if ($total_registros % 10000 === 0) {
        echo "<span style='color: #dcdcaa;'>   📊 Progreso: " . number_format($total_registros) . " registros escritos...</span>\n";
        flush();
    }
}

// ============================================
// 1. EXPORTAR TABLA PRODUCCION
// ============================================

echo "\n<span style='color: #4ec9b0;'>📦 Exportando tabla 'produccion'...</span>\n";
$sql = "SELECT 
            DATE(fecha) as fecha, 
            TIME(fecha) as hora, 
            'Producción' as tipo, 
            empleado, 
            area, 
            equipo, 
            orden
        FROM produccion";

$result = $conn->query($sql);
if ($result) {
    $cont = 0;
    while ($row = $result->fetch_assoc()) {
        escribirFila($output, [
            !empty($row['fecha']) ? date('d/m/Y', strtotime($row['fecha'])) : '',
            substr($row['hora'] ?? '', 0, 5),
            $row['tipo'],
            $row['empleado'] ?? '',
            $row['area'] ?? '',
            $row['equipo'] ?? '',
            $row['orden'] ?? '',
            '',
            '',
            ''
        ]);
        $cont++;
    }
    echo "<span style='color: #6a9955;'>   ✅ Exportadas " . number_format($cont) . " filas de producción</span>\n";
    $result->free();
} else {
    echo "<span style='color: #f48771;'>   ⚠️ Error en consulta: " . $conn->error . "</span>\n";
}

// ============================================
// 2. EXPORTAR TABLA REGISTROS_ANTIGUOS
// ============================================

echo "\n<span style='color: #4ec9b0;'>📦 Exportando tabla 'registros_antiguos'...</span>\n";
$sql = "SELECT 
            DATE(fecha) as fecha, 
            TIME(fecha) as hora, 
            'Antiguo' as tipo, 
            empleado, 
            area, 
            equipo, 
            orden
        FROM registros_antiguos";

$result = $conn->query($sql);
if ($result) {
    $cont = 0;
    while ($row = $result->fetch_assoc()) {
        escribirFila($output, [
            !empty($row['fecha']) ? date('d/m/Y', strtotime($row['fecha'])) : '',
            substr($row['hora'] ?? '', 0, 5),
            $row['tipo'],
            $row['empleado'] ?? '',
            $row['area'] ?? '',
            $row['equipo'] ?? '',
            $row['orden'] ?? '',
            '',
            '',
            ''
        ]);
        $cont++;
    }
    echo "<span style='color: #6a9955;'>   ✅ Exportadas " . number_format($cont) . " filas de registros antiguos</span>\n";
    $result->free();
} else {
    echo "<span style='color: #f48771;'>   ⚠️ Error en consulta: " . $conn->error . "</span>\n";
}

// ============================================
// 3. EXPORTAR TABLA REGISTRO_QUIEBRAS
// ============================================

echo "\n<span style='color: #4ec9b0;'>📦 Exportando tabla 'registro_quiebras'...</span>\n";
$sql = "SELECT 
            fecha, 
            TIME(hora) as hora, 
            'Quiebra' as tipo, 
            empleado, 
            area, 
            equipo, 
            orden, 
            motivo as detalle, 
            responsable, 
            turno
        FROM registro_quiebras";

$result = $conn->query($sql);
if ($result) {
    $cont = 0;
    while ($row = $result->fetch_assoc()) {
        escribirFila($output, [
            !empty($row['fecha']) ? date('d/m/Y', strtotime($row['fecha'])) : '',
            substr($row['hora'] ?? '', 0, 5),
            $row['tipo'],
            $row['empleado'] ?? '',
            $row['area'] ?? '',
            $row['equipo'] ?? '',
            $row['orden'] ?? '',
            $row['detalle'] ?? '',
            $row['responsable'] ?? '',
            $row['turno'] ?? ''
        ]);
        $cont++;
    }
    echo "<span style='color: #6a9955;'>   ✅ Exportadas " . number_format($cont) . " filas de quiebras</span>\n";
    $result->free();
} else {
    echo "<span style='color: #f48771;'>   ⚠️ Error en consulta: " . $conn->error . "</span>\n";
}

// ============================================
// CERRAR ARCHIVO Y MOSTAR RESULTADO
// ============================================

fclose($output);

$tamano = !$forzar_descarga ? filesize($ruta_completa) : 0;
$tamano_mb = round($tamano / 1024 / 1024, 2);

echo "\n<span style='color: #4ec9b0;'>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</span>\n";
echo "<span style='color: #6a9955;'>⏰ Finalizado: " . date('Y-m-d H:i:s') . "</span>\n";
echo "<span style='color: #4ec9b0;'>✅ TOTAL REGISTROS EXPORTADOS: " . number_format($total_registros) . "</span>\n";

if (!$forzar_descarga) {
    echo "<span style='color: #9cdcfe;'>📄 Archivo: $nombre_archivo</span>\n";
    echo "<span style='color: #9cdcfe;'>📁 Ubicación: $ruta_completa</span>\n";
    echo "<span style='color: #9cdcfe;'>💾 Tamaño: $tamano_mb MB</span>\n";
    echo "<span style='color: #4ec9b0;'>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</span>\n";
    
    echo "\n</pre>";
    
    // Botones de acción
    echo "<div style='margin-top: 20px;'>";
    echo "<button onclick=\"window.open('file:///" . str_replace('\\', '/', $carpeta_destino) . "')\" 
            style='background: #007acc; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-right: 10px;'>
            📂 Abrir carpeta de destino
           </button>";
    echo "<button onclick=\"window.location.href='exportar_todo.php'\" 
            style='background: #0e639c; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;'>
            🔄 Nueva exportación
           </button>";
    echo "</div>";
    
    // Mostrar enlace directo al archivo
    echo "<div style='margin-top: 15px; font-family: monospace;'>";
    echo "<small>🔗 Archivo generado: <code>" . htmlspecialchars($ruta_completa) . "</code></small>";
    echo "</div>";
}

$conn->close();
?>