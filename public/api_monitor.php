<?php
/**
 * api_monitor.php - VERSIÓN CORREGIDA Y FUNCIONAL
 * MODIFICADO: Añadido filtro de rango de fechas para actividad
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once dirname(__DIR__) . '/config/database.php';

// Verificar conexión a BD
if (!isset($conn) || $conn->connect_error) {
    echo json_encode([
        'success' => false,
        'error' => 'Error de conexión a la base de datos',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

date_default_timezone_set('America/Guatemala');

// ============================================================================
// FILTRO DE FECHAS
// ============================================================================
$fecha_inicio = date('Y-m-d');
$fecha_fin = date('Y-m-d');

if (isset($_GET['fecha_inicio']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_inicio'])) {
    $fecha_inicio = $_GET['fecha_inicio'];
}

if (isset($_GET['fecha_fin']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_fin'])) {
    $fecha_fin = $_GET['fecha_fin'];
}

if ($fecha_inicio === $fecha_fin) {
    $where_fecha = "DATE(fecha_hora) = '$fecha_inicio'";
} else {
    $where_fecha = "DATE(fecha_hora) BETWEEN '$fecha_inicio' AND '$fecha_fin'";
}

// ============================================================================
// MAPEO DE MÓDULOS
// ============================================================================
$modulos_nombres = [
    'dashboard_monitor' => '📡 Monitor en Vivo',
    'dashboard_admin_empleados' => '👥 Gestión de Empleados',
    'dashboard_admin_quiebras' => '📊 Registro de Quiebras',
    'dashboard_admin_produccion' => '🏭 Producción',
    'dashboard_admin_check' => '✅ Check de Calidad',
    'dashboard_admin_asistencia' => '⏰ Control de Pausas',
    'dashboard_admin_paros' => '⚠️ Paros de Producción',
    'auditoria_admin' => '🔍 Auditoría de Cambios',
    'registro' => '📦 Registro Producción',
    'registro_picking' => '📦 Registro Picking',
    'registro_asistencia' => '⏰ Marcas Asistencia',
    'registro_paro' => '⚠️ Solicitar Paro',
    'registro_quiebras' => '💔 Registro Quiebras',
    'solicitudes_paro' => '🔧 Atención Paros',
    'login_admin' => '🔐 Login Admin',
    'login_monitor' => '🔐 Login Monitor',
    'login_paros' => '🔐 Login Paros',
    'login_picking' => '🔐 Login Picking',
    'login' => '🔐 Login',
    'default' => '🏠 Activo'
];

// ============================================================================
// LEER TODAS LAS SESIONES
// ============================================================================
$sesiones_activas = [];

$session_save_path = session_save_path();
if (empty($session_save_path)) {
    $session_save_path = ini_get('session.save_path');
}
if (empty($session_save_path)) {
    $session_save_path = sys_get_temp_dir();
}

$timeout = ini_get('session.gc_maxlifetime') ?: 1440;

if (!empty($session_save_path) && is_dir($session_save_path)) {
    try {
        $session_files = @glob($session_save_path . '/sess_*');
        
        if ($session_files !== false && is_array($session_files)) {
            foreach ($session_files as $file) {
                if (!is_file($file) || !is_readable($file)) {
                    continue;
                }
                
                $session_data = @file_get_contents($file);
                if (empty($session_data)) continue;
                
                $empleado = '';
                $codigo = '';
                $rol = '';
                $ultimo_modulo = 'default';
                $ip = '';
                $ultima_actividad = @filemtime($file);
                
                if ($ultima_actividad === false) {
                    continue;
                }
                
                // Extraer empleado
                if (preg_match('/empleado\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $empleado = $m[2];
                } elseif (preg_match('/nombre_empleado\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $empleado = $m[2];
                } elseif (preg_match('/nombre_tecnico\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $empleado = $m[2];
                    $rol = 'tecnico';
                }
                
                // Extraer código
                if (preg_match('/codigo_empleado\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $codigo = $m[2];
                } elseif (preg_match('/codigoEmpleado\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $codigo = $m[2];
                }
                
                // Extraer rol
                if (empty($rol)) {
                    if (preg_match('/rol\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                        $rol = $m[2];
                    } elseif (preg_match('/es_tecnico\|b:([01])/', $session_data, $m)) {
                        $rol = $m[1] == '1' ? 'tecnico' : 'empleado';
                    } else {
                        $rol = 'empleado';
                    }
                }
                
                // Extraer módulo
                if (preg_match('/ultimo_modulo\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $ultimo_modulo = $m[2];
                }
                
                // Extraer IP
                if (preg_match('/ip\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
                    $ip = $m[2];
                }
                
                if (empty($ip)) {
                    $ip = 'No registrada';
                }
                
                $activa = (time() - $ultima_actividad) < $timeout;
                
                if ($activa && !empty($empleado)) {
                    $minutos_inactivo = round((time() - $ultima_actividad) / 60, 1);
                    $modulo_nombre = isset($modulos_nombres[$ultimo_modulo]) ? $modulos_nombres[$ultimo_modulo] : $modulos_nombres['default'];
                    
                    $sesiones_activas[] = [
                        'nombre' => $empleado,
                        'codigo' => $codigo ?: '—',
                        'rol' => $rol,
                        'modulo_actual' => $ultimo_modulo,
                        'modulo_nombre' => $modulo_nombre,
                        'tiempo_inactivo' => $minutos_inactivo,
                        'ip' => $ip
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error al leer sesiones: " . $e->getMessage());
    }
}

// ============================================================================
// AGREGAR ADMIN ACTUAL SI ESTÁ VACÍO
// ============================================================================
if (empty($sesiones_activas) && isset($_SESSION['empleado'])) {
    $admin_name = $_SESSION['empleado'];
    $admin_ip = isset($_SESSION['ip']) ? $_SESSION['ip'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1');
    $admin_rol = isset($_SESSION['rol']) ? $_SESSION['rol'] : 'administrador';
    $admin_modulo = isset($_SESSION['ultimo_modulo']) ? $_SESSION['ultimo_modulo'] : 'dashboard_monitor';
    $admin_modulo_nombre = isset($modulos_nombres[$admin_modulo]) ? $modulos_nombres[$admin_modulo] : $modulos_nombres['default'];
    
    $sesiones_activas[] = [
        'nombre' => $admin_name,
        'codigo' => isset($_SESSION['codigo_empleado']) ? $_SESSION['codigo_empleado'] : 'ADMIN',
        'rol' => $admin_rol,
        'modulo_actual' => $admin_modulo,
        'modulo_nombre' => $admin_modulo_nombre,
        'tiempo_inactivo' => 0,
        'ip' => $admin_ip
    ];
}

// Ordenar sesiones
if (!empty($sesiones_activas)) {
    usort($sesiones_activas, function($a, $b) {
        if ($a['rol'] === 'administrador' && $b['rol'] !== 'administrador') return -1;
        if ($a['rol'] !== 'administrador' && $b['rol'] === 'administrador') return 1;
        return strcmp($a['nombre'], $b['nombre']);
    });
}

// ============================================================================
// OBTENER ACTIVIDAD CON FILTRO DE FECHA
// ============================================================================
$actividad = [];

try {
    // Consulta con filtro de fecha
    $query = "SELECT tipo, usuario, detalle, ip, fecha_hora 
              FROM actividad_monitor 
              WHERE $where_fecha
              ORDER BY fecha_hora DESC 
              LIMIT 10000";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // El detalle ya está completo desde la tabla
            $detalle = $row['detalle'];
            
            // Si el detalle no tiene el ícono 👤, lo agregamos
            if (strpos($detalle, '👤') === false) {
                $detalle = '👤 ' . $row['usuario'] . ' — ' . $detalle;
            }
            
            $actividad[] = [
                'tipo' => $row['tipo'],
                'usuario' => $row['usuario'],
                'detalle' => $detalle,
                'ip' => !empty($row['ip']) ? $row['ip'] : 'No registrada',
                'fecha_hora' => date('d/m/Y H:i:s', strtotime($row['fecha_hora']))
            ];
        }
    }
    
    // Si no hay resultados, registrar para depuración
    if (empty($actividad)) {
        error_log("No se encontraron registros en actividad_monitor para el rango: $where_fecha");
    }
    
} catch (Exception $e) {
    error_log("Error al obtener actividad: " . $e->getMessage());
    $actividad = [];
}

// ============================================================================
// MÉTRICAS (sin filtro de fecha para métricas generales)
// ============================================================================
$total_empleados = 0;
try {
    $res = $conn->query("SELECT COUNT(*) as total FROM empleados");
    if ($res && $row = $res->fetch_assoc()) { 
        $total_empleados = (int)$row['total']; 
    }
} catch (Exception $e) {
    error_log("Error al contar empleados: " . $e->getMessage());
}

$db_size = 0;
try {
    $res = $conn->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = DATABASE()");
    if ($res && $row = $res->fetch_assoc()) { 
        $db_size = round((float)$row['size'] / 1024 / 1024, 2); 
    }
} catch (Exception $e) {
    error_log("Error al obtener tamaño BD: " . $e->getMessage());
}

$ultimo_insert = '—';
try {
    $res = $conn->query("SELECT MAX(fecha_hora) as ultimo FROM actividad_monitor");
    if ($res && $row = $res->fetch_assoc()) {
        $ultimo_insert = !empty($row['ultimo']) ? date('d/m/Y H:i:s', strtotime($row['ultimo'])) : '—';
    }
} catch (Exception $e) {
    error_log("Error al obtener último insert: " . $e->getMessage());
}

$activos_hoy = 0;
try {
    $res = $conn->query("
        SELECT COUNT(DISTINCT usuario) as activos 
        FROM actividad_monitor 
        WHERE fecha_hora >= NOW() - INTERVAL 24 HOUR
    ");
    if ($res && $row = $res->fetch_assoc()) { 
        $activos_hoy = (int)$row['activos']; 
    }
} catch (Exception $e) {
    error_log("Error al contar activos hoy: " . $e->getMessage());
}

// Contar admins y empleados activos
$admins_activos = 0;
$empleados_activos = 0;
foreach ($sesiones_activas as $s) {
    if ($s['rol'] === 'administrador') {
        $admins_activos++;
    } else {
        $empleados_activos++;
    }
}

// ============================================================================
// ALERTAS
// ============================================================================
$alertas = [];
$warnings = [];

if ($db_size > 100) {
    $alertas[] = "Base de datos supera los 100 MB ({$db_size} MB)";
} elseif ($db_size > 50) {
    $warnings[] = "Base de datos: {$db_size} MB";
}

$paros_pendientes = 0;
try {
    $res = $conn->query("SELECT COUNT(*) as total FROM solicitudes_paro WHERE estado = 'pendiente'");
    if ($res && $row = $res->fetch_assoc()) {
        $paros_pendientes = (int)$row['total'];
        if ($paros_pendientes > 0) {
            $warnings[] = "⚠️ {$paros_pendientes} solicitud(es) de paro pendiente(s)";
        }
    }
} catch (Exception $e) {
    error_log("Error al contar paros: " . $e->getMessage());
}

$server_load = 35;
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    if ($load !== false && isset($load[0])) {
        $server_load = round($load[0] * 10, 1);
    }
}

// ============================================================================
// RESPONDER
// ============================================================================
$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'fecha_inicio' => $fecha_inicio,
    'fecha_fin' => $fecha_fin,
    'total_conectados' => count($sesiones_activas),
    'admins_activos' => $admins_activos,
    'empleados_activos' => $empleados_activos,
    'total_empleados' => $total_empleados,
    'activos_hoy' => $activos_hoy,
    'db_size_mb' => $db_size,
    'ultimo_insert_db' => $ultimo_insert,
    'sesiones' => $sesiones_activas,
    'actividad' => $actividad,
    'alertas' => $alertas,
    'warnings' => $warnings,
    'paros_pendientes' => $paros_pendientes,
    'server_info' => [
        'load' => $server_load,
        'php_version' => PHP_VERSION,
        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);

if (isset($conn) && !$conn->connect_error) {
    $conn->close();
}
?>