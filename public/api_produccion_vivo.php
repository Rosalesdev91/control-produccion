<?php
/**
 * api_produccion_vivo.php
 * API para dashboard_monitor_produccion.php
 * 
 * REGLAS DE NEGOCIO:
 * - empleado NO NULL → QUIEBRA POR PERSONA (muestra el nombre)
 * - equipo NO NULL → QUIEBRA POR EQUIPO
 * - material NO NULL → QUIEBRA POR MATERIAL
 * 
 * MODIFICADO: Incluye registros_antiguos para producción
 * OPTIMIZADO: Consultas optimizadas para mejor rendimiento
 * NUEVO: Matriz de producción por hora (produccion_matriz)
 * 
 * By: Nestor Rosales | Rosales_Dev91
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once dirname(__DIR__) . '/config/database.php';
date_default_timezone_set('America/Guatemala');

// ============================================================================
// 0. SOPORTE PARA FILTRO DE FECHA (RANGO O FECHA ÚNICA)
// ============================================================================
$fecha_inicio = date('Y-m-d');
$fecha_fin = date('Y-m-d');
$es_rango = false;

if (isset($_GET['fecha_inicio']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_inicio'])) {
    $fecha_inicio = $_GET['fecha_inicio'];
    $es_rango = true;
}

if (isset($_GET['fecha_fin']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha_fin'])) {
    $fecha_fin = $_GET['fecha_fin'];
    $es_rango = true;
}

if (isset($_GET['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha']) && !$es_rango) {
    $fecha_inicio = $_GET['fecha'];
    $fecha_fin = $_GET['fecha'];
}

// Condición WHERE para fechas
if ($fecha_inicio === $fecha_fin) {
    $where_fecha = "DATE(fecha) = '$fecha_inicio'";
} else {
    $where_fecha = "DATE(fecha) BETWEEN '$fecha_inicio' AND '$fecha_fin'";
}

// ============================================================================
// 1. MÉTRICAS PRINCIPALES (UNIENDO ambas tablas en una sola consulta)
// ============================================================================

// Consulta única para obtener todas las métricas de producción
$sql_metrics = "
    SELECT 
        (SELECT COUNT(*) FROM (SELECT 1 FROM produccion WHERE $where_fecha UNION ALL SELECT 1 FROM registros_antiguos WHERE $where_fecha) AS t) AS total_produccion,
        (SELECT COUNT(DISTINCT empleado) FROM (SELECT empleado FROM produccion WHERE $where_fecha AND empleado IS NOT NULL AND empleado != '' UNION ALL SELECT empleado FROM registros_antiguos WHERE $where_fecha AND empleado IS NOT NULL AND empleado != '') AS t) AS total_empleados,
        (SELECT COUNT(DISTINCT equipo) FROM (SELECT equipo FROM produccion WHERE $where_fecha AND equipo IS NOT NULL AND equipo != '' UNION ALL SELECT equipo FROM registros_antiguos WHERE $where_fecha AND equipo IS NOT NULL AND equipo != '') AS t) AS total_equipos,
        (SELECT COUNT(DISTINCT orden) FROM (SELECT orden FROM produccion WHERE $where_fecha AND orden IS NOT NULL AND orden != '' UNION ALL SELECT orden FROM registros_antiguos WHERE $where_fecha AND orden IS NOT NULL AND orden != '') AS t) AS total_ordenes,
        (SELECT COUNT(DISTINCT area) FROM (SELECT area FROM produccion WHERE $where_fecha AND area IS NOT NULL AND area != '' UNION ALL SELECT area FROM registros_antiguos WHERE $where_fecha AND area IS NOT NULL AND area != '') AS t) AS total_areas
";

$produccion_total = 0;
$empleados_activos = 0;
$equipos_activos = 0;
$ordenes_procesadas = 0;
$areas_activas = 0;

$res = $conn->query($sql_metrics);
if ($res) {
    $row = $res->fetch_assoc();
    $produccion_total = (int)$row['total_produccion'];
    $empleados_activos = (int)$row['total_empleados'];
    $equipos_activos = (int)$row['total_equipos'];
    $ordenes_procesadas = (int)$row['total_ordenes'];
    $areas_activas = (int)$row['total_areas'];
}

// Quiebras en el rango
$quiebras_total = 0;
$sql_quiebras = "SELECT COUNT(*) as total FROM registro_quiebras WHERE $where_fecha";
$res = $conn->query($sql_quiebras);
if ($res) { 
    $quiebras_total = (int)$res->fetch_assoc()['total']; 
}

// ============================================================================
// 2. PRODUCCIÓN MATRICIAL POR HORA (Área + Equipo vs Hora del día)
// ============================================================================
function getProduccionMatriz($conn, $where_fecha) {
    $sql = "
        SELECT 
            area,
            COALESCE(equipo, 'Sin equipo') as equipo,
            HOUR(fecha) as hora,
            COUNT(*) as cantidad,
            MAX(fecha) as ultimo
        FROM (
            SELECT area, equipo, fecha FROM produccion WHERE $where_fecha
            UNION ALL
            SELECT area, equipo, fecha FROM registros_antiguos WHERE $where_fecha
        ) as unified
        GROUP BY area, equipo, HOUR(fecha)
        ORDER BY area, equipo, hora ASC
    ";
    
    $result = $conn->query($sql);
    
    // Estructura para almacenar los datos
    $matriz = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['area'] . '|' . $row['equipo'];
            
            if (!isset($matriz[$key])) {
                $matriz[$key] = [
                    'area' => $row['area'],
                    'equipo' => $row['equipo'],
                    'horas' => array_fill(0, 24, 0),
                    'ultimo' => null
                ];
            }
            
            $hora = (int)$row['hora'];
            $matriz[$key]['horas'][$hora] = (int)$row['cantidad'];
            
            // Actualizar último registro (tomar el más reciente)
            $fechaActual = $row['ultimo'];
            if ($matriz[$key]['ultimo'] === null || $fechaActual > $matriz[$key]['ultimo']) {
                $matriz[$key]['ultimo'] = $fechaActual;
            }
        }
    }
    
    // Ordenar por área y luego por equipo
    uasort($matriz, function($a, $b) {
        if ($a['area'] == $b['area']) {
            return strcmp($a['equipo'], $b['equipo']);
        }
        return strcmp($a['area'], $b['area']);
    });
    
    // Formatear la hora del último registro
    $resultado = [];
    foreach ($matriz as $item) {
        $resultado[] = [
            'area' => $item['area'],
            'equipo' => $item['equipo'],
            'horas' => $item['horas'],
            'ultimo' => $item['ultimo'] ? date('H:i:s', strtotime($item['ultimo'])) : null
        ];
    }
    
    return $resultado;
}

$produccion_matriz = getProduccionMatriz($conn, $where_fecha);

// ============================================================================
// 3. PRODUCCIÓN POR ÁREA Y EQUIPO (Resumen - UNION de ambas tablas)
// ============================================================================
$produccion_resumen = [];
$sql_resumen = "
    SELECT 
        area, 
        COALESCE(equipo, 'Sin equipo') as equipo, 
        COUNT(*) as cantidad,
        MAX(fecha) as ultimo,
        GROUP_CONCAT(DISTINCT turno ORDER BY turno SEPARATOR ', ') as turnos
    FROM (
        SELECT area, equipo, fecha, turno FROM produccion WHERE $where_fecha
        UNION ALL
        SELECT area, equipo, fecha, turno FROM registros_antiguos WHERE $where_fecha
    ) as unified
    GROUP BY area, equipo 
    ORDER BY cantidad DESC
    LIMIT 50
";
$res = $conn->query($sql_resumen);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $produccion_resumen[] = [
            'area' => $row['area'],
            'equipo' => $row['equipo'] ?: '—',
            'cantidad' => (int)$row['cantidad'],
            'ultimo' => date('H:i:s', strtotime($row['ultimo'])),
            'turno' => $row['turnos']
        ];
    }
}

// ============================================================================
// 4. QUIEBRAS POR TURNO
// ============================================================================
$quiebras_por_turno = [];
$sql_turno = "SELECT turno, COUNT(*) as total FROM registro_quiebras WHERE $where_fecha AND turno IS NOT NULL AND turno != '' GROUP BY turno";
$res = $conn->query($sql_turno);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $quiebras_por_turno[$row['turno']] = (int)$row['total'];
    }
}

// ============================================================================
// 5. QUIEBRAS POR RESPONSABLE
// ============================================================================
$quiebras_por_responsable = [];
$sql_responsable = "SELECT responsable as responsable, COUNT(*) as total FROM registro_quiebras WHERE $where_fecha AND responsable IS NOT NULL AND responsable != '' GROUP BY responsable ORDER BY total DESC LIMIT 6";
$res = $conn->query($sql_responsable);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $quiebras_por_responsable[] = [
            'responsable' => $row['responsable'],
            'total' => (int)$row['total']
        ];
    }
}

if (empty($quiebras_por_responsable)) {
    $quiebras_por_responsable = [['responsable' => 'Sin datos', 'total' => 0]];
}

// ============================================================================
// 6. QUIEBRAS POR EQUIPO
// ============================================================================
$quiebras_por_equipo = [];
$sql_equipo_q = "SELECT equipo, COUNT(*) as total FROM registro_quiebras WHERE $where_fecha AND equipo IS NOT NULL AND equipo != '' GROUP BY equipo ORDER BY total DESC LIMIT 20";
$res = $conn->query($sql_equipo_q);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $quiebras_por_equipo[] = [
            'equipo' => $row['equipo'],
            'total' => (int)$row['total']
        ];
    }
}

if (empty($quiebras_por_equipo)) {
    $quiebras_por_equipo = [['equipo' => 'Sin datos', 'total' => 0]];
}

// ============================================================================
// 7. QUIEBRAS POR MATERIAL
// ============================================================================
$quiebras_por_material = [];
$sql_material = "SELECT material, COUNT(*) as total FROM registro_quiebras WHERE $where_fecha AND material IS NOT NULL AND material != '' GROUP BY material ORDER BY total DESC LIMIT 15";
$res = $conn->query($sql_material);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $quiebras_por_material[] = [
            'material' => $row['material'],
            'total' => (int)$row['total']
        ];
    }
}

if (empty($quiebras_por_material)) {
    $quiebras_por_material = [['material' => 'Sin datos', 'total' => 0]];
}

// ============================================================================
// 8. QUIEBRAS POR MOTIVO
// ============================================================================
$quiebras_por_motivo = [];
$sql_motivo = "SELECT COALESCE(motivo, 'No especificado') as motivo, COUNT(*) as total FROM registro_quiebras WHERE $where_fecha GROUP BY motivo ORDER BY total DESC LIMIT 20";
$res = $conn->query($sql_motivo);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $quiebras_por_motivo[] = [
            'motivo' => $row['motivo'],
            'total' => (int)$row['total']
        ];
    }
}

// ============================================================================
// 9. ESTADÍSTICAS POR TIPO (basado en columna RESPONSABLE)
// ============================================================================
$quiebras_por_tipo = [
    'persona' => 0,
    'equipo' => 0,
    'material' => 0,
    'sucursal' => 0
];

// Contar por RESPONSABLE (la columna que contiene 'persona', 'equipo', 'material', 'sucursal')
$sql_tipo_responsable = "SELECT responsable, COUNT(*) as total FROM registro_quiebras WHERE $where_fecha AND responsable IS NOT NULL AND responsable != '' GROUP BY responsable";
$res = $conn->query($sql_tipo_responsable);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $responsable = strtolower(trim($row['responsable']));
        switch ($responsable) {
            case 'persona':
                $quiebras_por_tipo['persona'] = (int)$row['total'];
                break;
            case 'equipo':
                $quiebras_por_tipo['equipo'] = (int)$row['total'];
                break;
            case 'material':
                $quiebras_por_tipo['material'] = (int)$row['total'];
                break;
            case 'sucursal':
                $quiebras_por_tipo['sucursal'] = (int)$row['total'];
                break;
        }
    }
}

// ============================================================================
// 10. ÓRDENES CON MÁS QUIEBRAS
// ============================================================================
$ordenes_con_mas_quiebras = [];
$sql_ordenes_q = "
    SELECT 
        orden,
        COUNT(*) as total_quiebras,
        GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', ') as motivos,
        GROUP_CONCAT(DISTINCT responsable ORDER BY responsable SEPARATOR ', ') as responsables
    FROM registro_quiebras 
    WHERE $where_fecha 
        AND orden IS NOT NULL 
        AND orden != ''
    GROUP BY orden 
    ORDER BY total_quiebras DESC 
    LIMIT 20
";
$res = $conn->query($sql_ordenes_q);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ordenes_con_mas_quiebras[] = [
            'orden' => $row['orden'],
            'total_quiebras' => (int)$row['total_quiebras'],
            'motivos' => $row['motivos'],
            'responsables' => $row['responsables']
        ];
    }
}

if (empty($ordenes_con_mas_quiebras)) {
    $ordenes_con_mas_quiebras = [['orden' => 'Sin órdenes con quiebras', 'total_quiebras' => 0, 'motivos' => '—', 'responsables' => '—']];
}

// ============================================================================
// 11. ACTIVIDAD RECIENTE (UNION de ambas tablas)
// ============================================================================
$actividad = [];

// Producción reciente (1000 registros)
$sql_act_prod = "
    SELECT 
        'produccion' as tipo, 
        CONCAT('📦 ', empleado, ' - ', area, ' (Turno ', turno, '): Orden ', orden) as detalle,
        fecha as fecha_hora
    FROM (
        SELECT empleado, area, turno, orden, fecha FROM produccion WHERE $where_fecha
        UNION ALL
        SELECT empleado, area, turno, orden, fecha FROM registros_antiguos WHERE $where_fecha
    ) as unified
    ORDER BY fecha DESC 
    LIMIT 1000
";
$res = $conn->query($sql_act_prod);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $actividad[] = [
            'tipo' => 'produccion',
            'detalle' => $row['detalle'],
            'fecha_hora' => date('d/m/Y H:i:s', strtotime($row['fecha_hora']))
        ];
    }
}

// Quiebras recientes (1000 registros)
$sql_act_q = "
    SELECT 
        'quiebra' as tipo,
        fecha,
        hora,
        empleado,
        equipo,
        material,
        motivo,
        turno,
        orden,
        CASE 
            WHEN empleado IS NOT NULL AND empleado != '' THEN CONCAT('👤 ', empleado)
            WHEN equipo IS NOT NULL AND equipo != '' THEN CONCAT('🛠️ ', equipo)
            WHEN material IS NOT NULL AND material != '' THEN CONCAT('🧪 ', material)
            ELSE NULL
        END as origen,
        CASE 
            WHEN empleado IS NOT NULL AND empleado != '' THEN 'empleado'
            WHEN equipo IS NOT NULL AND equipo != '' THEN 'equipo'
            WHEN material IS NOT NULL AND material != '' THEN 'material'
            ELSE 'sucursal'
        END as subtipo
    FROM registro_quiebras 
    WHERE $where_fecha
    ORDER BY fecha DESC, hora DESC 
    LIMIT 1000
";
$res = $conn->query($sql_act_q);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if ($row['origen']) {
            $detalle = '💔 ' . $row['origen'];
            if ($row['motivo'] && $row['motivo'] != '') {
                $detalle .= ' - ' . $row['motivo'];
            }
        } else {
            $detalle = '💔 Sucursal';
            if ($row['motivo'] && $row['motivo'] != '') {
                $detalle .= ' - ' . $row['motivo'];
            } else {
                $detalle .= ' - Sin motivo';
            }
        }
        
        if ($row['turno'] && $row['turno'] != '') {
            $detalle .= ' (Turno ' . $row['turno'] . ')';
        }
        
        if ($row['orden'] && $row['orden'] != '') {
            $detalle .= ' | Orden: ' . $row['orden'];
        }
        
        $actividad[] = [
            'tipo' => 'quiebra',
            'detalle' => $detalle,
            'fecha_hora' => date('d/m/Y H:i:s', strtotime($row['fecha'] . ' ' . $row['hora'])),
            'subtipo' => $row['subtipo']
        ];
    }
}

// Ordenar actividad por fecha (más reciente primero)
usort($actividad, function($a, $b) {
    return strtotime($b['fecha_hora']) - strtotime($a['fecha_hora']);
});
$actividad = array_slice($actividad, 0, 2000);  // 1000 + 1000 = hasta 2000 en total

// ============================================================================
// 12. EFICIENCIA
// ============================================================================
$total_eventos = $produccion_total + $quiebras_total;
$eficiencia = $total_eventos > 0 ? round(($produccion_total / $total_eventos) * 100, 1) : 100;

// ============================================================================
// 13. RESPUESTA JSON (CON MATRIZ DE PRODUCCIÓN INCLUIDA)
// ============================================================================
echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'fecha_inicio' => $fecha_inicio,
    'fecha_fin' => $fecha_fin,
    
    'produccion_hoy' => $produccion_total,
    'quiebras_hoy' => $quiebras_total,
    'empleados_activos_hoy' => $empleados_activos,
    'equipos_activos' => $equipos_activos,
    'ordenes_procesadas' => $ordenes_procesadas,
    'areas_activas' => $areas_activas,
    
    'eficiencia' => $eficiencia,
    'quiebras_por_tipo' => $quiebras_por_tipo,
    
    'produccion_matriz' => $produccion_matriz,  // <-- NUEVO: Matriz Área/Equipo vs Hora
    'produccion_resumen' => $produccion_resumen,
    'quiebras_por_turno' => $quiebras_por_turno,
    'quiebras_por_responsable' => $quiebras_por_responsable,
    'quiebras_por_equipo' => $quiebras_por_equipo,
    'quiebras_por_material' => $quiebras_por_material,
    'quiebras_por_motivo' => $quiebras_por_motivo,
    
    'ordenes_con_mas_quiebras' => $ordenes_con_mas_quiebras,
    
    'actividad' => $actividad,
    
    'alertas' => [],
    'warnings' => []
], JSON_UNESCAPED_UNICODE);

$conn->close();
?>