<?php
// ============================================
// CONFIGURACIÓN INICIAL
// ============================================

session_start();
set_time_limit(0);
ini_set('memory_limit', '256M');

if (!isset($_SESSION['empleado'], $_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

require_once dirname(__DIR__) . '/config/database.php';

date_default_timezone_set('America/Costa_Rica');
ini_set('date.timezone', 'America/Costa_Rica');

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function convertir12a24(string $time, string $ampm): string
{
    if (empty($time)) return '00:00:00';
    
    $time = trim($time);
    $ampm = strtoupper(trim($ampm));
    
    if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time)) {
        if (strlen($time) == 5) return $time . ':00';
        return $time;
    }
    
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
        $hours = intval($matches[1]);
        $minutes = intval($matches[2]);
        
        $hours = max(1, min(12, $hours));
        $minutes = max(0, min(59, $minutes));
        
        if ($ampm === 'PM' && $hours < 12) $hours += 12;
        elseif ($ampm === 'AM' && $hours == 12) $hours = 0;
        
        $hours = $hours % 24;
        return sprintf('%02d:%02d:00', $hours, $minutes);
    }
    
    return '00:00:00';
}

function tiempo24a12(string $time24): string
{
    if (empty($time24) || $time24 === '00:00:00' || $time24 === '00:00') return '12:00 AM';
    
    try {
        $time = substr($time24, 0, 5);
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) return '12:00 AM';
        
        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) return '12:00 AM';
        
        $ampm = $hours >= 12 ? 'PM' : 'AM';
        $hours12 = $hours % 12;
        $hours12 = $hours12 === 0 ? 12 : $hours12;
        
        return sprintf('%d:%02d %s', $hours12, $minutes, $ampm);
    } catch (Exception $e) {
        return '12:00 AM';
    }
}

function convertirHoraAMinutos(string $hora): int
{
    $parts = explode(':', $hora);
    $horas = (int)($parts[0] ?? 0);
    $minutos = (int)($parts[1] ?? 0);
    $segundos = (int)($parts[2] ?? 0);
    return ($horas * 60) + $minutos + ($segundos > 30 ? 1 : 0);
}

function rangoCruzaMedianoche(string $hora_inicio, string $hora_fin): bool
{
    if (empty($hora_inicio) || empty($hora_fin)) return false;
    
    $minutosInicio = convertirHoraAMinutos($hora_inicio);
    $minutosFin = convertirHoraAMinutos($hora_fin);
    
    if ($minutosFin < $minutosInicio) {
        if ($minutosInicio == 0 && $minutosFin >= 1439) return false;
        return true;
    }
    return false;
}

function sanitizar_input($input): string
{
    if (!is_string($input)) return '';
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $input;
}

function format_number(int $number): string
{
    return number_format($number, 0, '.', ',');
}

// ============================================
// FUNCIONES DE CONSULTA PRINCIPALES
// ============================================

function obtenerTotalProduccion($conn, $filtros): int
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT COUNT(DISTINCT orden) as total FROM (
                SELECT orden FROM produccion 
                WHERE area = 'Bodega de Aros' AND equipo = 'Empaque' 
                  AND orden IS NOT NULL AND orden != ''
                  AND DATE(fecha) BETWEEN ? AND ?
                UNION
                SELECT orden FROM registros_antiguos 
                WHERE area = 'Bodega de Aros' AND equipo = 'Empaque' 
                  AND orden IS NOT NULL AND orden != ''
                  AND DATE(fecha) BETWEEN ? AND ?
            ) AS combined";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return (int)($result['total'] ?? 0);
}

function obtenerTotalQuiebras($conn, $filtros): int
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT COUNT(*) as total FROM registro_quiebras WHERE fecha BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return (int)($result['total'] ?? 0);
}

function obtenerOrdenesCCValidas($conn, $filtros): int
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT COUNT(DISTINCT p.orden) as total
            FROM produccion p
            WHERE p.area = 'Control Calidad'
              AND p.orden IS NOT NULL AND p.orden != ''
              AND DATE(p.fecha) BETWEEN ? AND ?
              AND p.orden NOT IN (
                  SELECT DISTINCT orden FROM (
                      SELECT orden FROM produccion 
                      WHERE area = 'Bodega de Aros' AND equipo = 'Empaque'
                        AND orden IS NOT NULL AND orden != ''
                        AND DATE(fecha) BETWEEN ? AND ?
                      UNION
                      SELECT orden FROM registros_antiguos 
                      WHERE area = 'Bodega de Aros' AND equipo = 'Empaque'
                        AND orden IS NOT NULL AND orden != ''
                        AND DATE(fecha) BETWEEN ? AND ?
                  ) AS empaque
              )
              AND p.orden NOT IN (
                  SELECT DISTINCT orden FROM registro_quiebras q
                  WHERE q.orden IS NOT NULL AND q.orden != ''
                    AND q.fecha BETWEEN ? AND ?
              )";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssss', 
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin,
        $fecha_inicio, $fecha_fin
    );
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return (int)($result['total'] ?? 0);
}

function obtenerTopOrdenes($conn, $filtros, int $limite = 50): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    // Primero, obtener las órdenes finalizadas (que ya están en Bodega de Aros con Empaque)
    // Usamos una sola consulta para obtener todas las órdenes finalizadas del período
    $sqlFinalizadas = "SELECT DISTINCT orden FROM (
                           SELECT orden FROM produccion 
                           WHERE area = 'Bodega de Aros' AND equipo = 'Empaque'
                             AND orden IS NOT NULL AND orden != ''
                             AND DATE(fecha) BETWEEN ? AND ?
                           UNION
                           SELECT orden FROM registros_antiguos 
                           WHERE area = 'Bodega de Aros' AND equipo = 'Empaque'
                             AND orden IS NOT NULL AND orden != ''
                             AND DATE(fecha) BETWEEN ? AND ?
                       ) AS finalizadas";
    
    $stmtFin = $conn->prepare($sqlFinalizadas);
    $stmtFin->bind_param('ssss', $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin);
    $stmtFin->execute();
    $resultFin = $stmtFin->get_result();
    
    $ordenesFinalizadas = [];
    while ($row = $resultFin->fetch_assoc()) {
        $ordenesFinalizadas[$row['orden']] = true;
    }
    $stmtFin->close();
    
    // Si hay muchas órdenes finalizadas, construimos una lista para excluir
    $excludeClause = '';
    $excludeParams = [];
    if (!empty($ordenesFinalizadas)) {
        $placeholders = implode(',', array_fill(0, count($ordenesFinalizadas), '?'));
        $excludeClause = "AND orden NOT IN ($placeholders)";
        $excludeParams = array_keys($ordenesFinalizadas);
    }
    
    // Consulta principal optimizada
    $sql = "SELECT 
                orden, 
                COUNT(*) as total_quiebras, 
                GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', ') as motivos,
                GROUP_CONCAT(DISTINCT empleado ORDER BY empleado SEPARATOR ', ') as empleados,
                GROUP_CONCAT(DISTINCT equipo ORDER BY equipo SEPARATOR ', ') as equipos,
                MIN(fecha) as primera_quiebra, 
                MAX(fecha) as ultima_quiebra
            FROM registro_quiebras 
            WHERE fecha BETWEEN ? AND ?
              AND orden IS NOT NULL AND orden != ''
              $excludeClause
            GROUP BY orden 
            ORDER BY total_quiebras DESC 
            LIMIT ?";
    
    // Preparar parámetros
    $params = array_merge([$fecha_inicio, $fecha_fin], $excludeParams, [$limite]);
    $types = str_repeat('s', 2 + count($excludeParams)) . 'i';
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Para cada orden, obtener el último movimiento de producción o quiebra
    foreach ($datos as &$o) {
        $orden = $o['orden'];
        
        // Optimizar: una sola consulta para obtener el último movimiento
        // Primero intentamos en produccion, luego en antiguos, luego en quiebras
        $sqlUltimo = "(SELECT fecha, TIME_FORMAT(fecha, '%H:%i') as hora, area, equipo, 'produccion' as fuente
                       FROM produccion WHERE orden = ?
                       ORDER BY fecha DESC LIMIT 1)
                       UNION ALL
                       (SELECT fecha, TIME_FORMAT(fecha, '%H:%i') as hora, area, equipo, 'antiguo' as fuente
                       FROM registros_antiguos WHERE orden = ?
                       ORDER BY fecha DESC LIMIT 1)
                       UNION ALL
                       (SELECT fecha, TIME_FORMAT(fecha, '%H:%i') as hora, area, equipo, 'quiebra' as fuente
                       FROM registro_quiebras WHERE orden = ?
                       ORDER BY fecha DESC LIMIT 1)
                       ORDER BY fecha DESC LIMIT 1";
        
        $stmtUlt = $conn->prepare($sqlUltimo);
        $stmtUlt->bind_param('sss', $orden, $orden, $orden);
        $stmtUlt->execute();
        $ultimo = $stmtUlt->get_result()->fetch_assoc();
        $stmtUlt->close();
        
        if ($ultimo) {
            $fechaObj = new DateTime($ultimo['fecha']);
            $o['ultimo_movimiento_completo'] = $fechaObj->format('d/m/Y') . ' ' . $ultimo['hora'];
            $o['ultimo_movimiento_fecha'] = $fechaObj->format('d/m/Y');
            $o['ultimo_movimiento_hora'] = $ultimo['hora'];
            $o['ultimo_movimiento_area'] = !empty($ultimo['area']) ? $ultimo['area'] : 'N/A';
            $o['ultimo_movimiento_equipo'] = !empty($ultimo['equipo']) ? $ultimo['equipo'] : 'N/A';
            $o['fuente_ultimo_mov'] = $ultimo['fuente'];
        } else {
            $o['ultimo_movimiento_completo'] = 'Sin registro';
            $o['ultimo_movimiento_fecha'] = 'Sin registro';
            $o['ultimo_movimiento_hora'] = 'Sin registro';
            $o['ultimo_movimiento_area'] = 'Sin registro';
            $o['ultimo_movimiento_equipo'] = 'Sin registro';
            $o['fuente_ultimo_mov'] = 'N/A';
        }
        
        $o['primera_quiebra'] = $o['primera_quiebra'] ? date('d/m', strtotime($o['primera_quiebra'])) : 'N/A';
        $o['ultima_quiebra'] = $o['ultima_quiebra'] ? date('d/m', strtotime($o['ultima_quiebra'])) : 'N/A';
    }
    
    return $datos;
}

function obtenerTopEmpleadosQuiebras($conn, $filtros, int $limite = 80): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT empleado, COUNT(*) as total_quiebras, COUNT(DISTINCT orden) as ordenes_afectadas
            FROM registro_quiebras 
            WHERE fecha BETWEEN ? AND ?
              AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
            GROUP BY empleado 
            ORDER BY total_quiebras DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $fecha_inicio, $fecha_fin, $limite);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $datos;
}

function obtenerTopEmpleadosProduccion($conn, $filtros): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT empleado, COUNT(*) as total_produccion
            FROM (SELECT empleado FROM produccion WHERE DATE(fecha) BETWEEN ? AND ?
                  UNION ALL SELECT empleado FROM registros_antiguos WHERE DATE(fecha) BETWEEN ? AND ?) p
            WHERE empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
            GROUP BY empleado 
            ORDER BY total_produccion DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $quiebrasMap = [];
    $sqlQuie = "SELECT empleado, COUNT(*) as total_quiebras 
                FROM registro_quiebras WHERE fecha BETWEEN ? AND ?
                AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                GROUP BY empleado";
    $stmtQuie = $conn->prepare($sqlQuie);
    $stmtQuie->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmtQuie->execute();
    $quiebrasData = $stmtQuie->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtQuie->close();
    
    foreach ($quiebrasData as $q) {
        $quiebrasMap[$q['empleado']] = $q['total_quiebras'];
    }
    
    $resultado = [];
    foreach ($datos as $emp) {
        $tp = (int)$emp['total_produccion'];
        $tq = (int)($quiebrasMap[$emp['empleado']] ?? 0);
        $total = $tp + $tq;
        $ratio = $total > 0 ? round(100 - ($tq / $total) * 100, 1) : 100;
        $resultado[] = [
            'empleado' => $emp['empleado'],
            'total_produccion' => $tp,
            'total_quiebras' => $tq,
            'horas_trabajadas' => 1,
            'productividad_hora' => $tp,
            'ratio' => $ratio
        ];
    }
    
    return $resultado;
}

function obtenerTopEquiposQuiebras($conn, $filtros, int $limite = 80): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT equipo, COUNT(*) as total_quiebras, COUNT(DISTINCT empleado) as empleados_afectados,
            COUNT(DISTINCT orden) as ordenes_afectadas, COUNT(DISTINCT motivo) as motivos_diferentes,
            COUNT(DISTINCT area) as areas_afectadas,
            GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', ') as motivos_frecuentes,
            GROUP_CONCAT(DISTINCT empleado ORDER BY empleado SEPARATOR ', ') as empleados_relacionados,
            MIN(fecha) as primera_quiebra, MAX(fecha) as ultima_quiebra
            FROM registro_quiebras 
            WHERE fecha BETWEEN ? AND ?
              AND equipo IS NOT NULL AND equipo != ''
            GROUP BY equipo 
            ORDER BY total_quiebras DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $fecha_inicio, $fecha_fin, $limite);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($datos as &$e) {
        $e['primera_quiebra'] = $e['primera_quiebra'] ? date('d/m/Y', strtotime($e['primera_quiebra'])) : 'N/A';
        $e['ultima_quiebra'] = $e['ultima_quiebra'] ? date('d/m/Y', strtotime($e['ultima_quiebra'])) : 'N/A';
    }
    
    return $datos;
}

function obtenerTopResponsables($conn, $filtros, int $limite = 50): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT responsable, COUNT(*) as total_quiebras, COUNT(DISTINCT empleado) as empleados_afectados,
            COUNT(DISTINCT orden) as ordenes_afectadas, COUNT(DISTINCT motivo) as motivos_diferentes,
            COUNT(DISTINCT area) as areas_afectadas, COUNT(DISTINCT equipo) as equipos_afectados,
            GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', ') as motivos_frecuentes,
            GROUP_CONCAT(DISTINCT empleado ORDER BY empleado SEPARATOR ', ') as empleados_relacionados,
            MIN(fecha) as primera_quiebra, MAX(fecha) as ultima_quiebra
            FROM registro_quiebras 
            WHERE fecha BETWEEN ? AND ?
              AND responsable IS NOT NULL AND responsable != '' AND responsable != 'N/A'
            GROUP BY responsable 
            ORDER BY total_quiebras DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $fecha_inicio, $fecha_fin, $limite);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($datos as &$r) {
        $r['primera_quiebra'] = $r['primera_quiebra'] ? date('d/m/Y', strtotime($r['primera_quiebra'])) : 'N/A';
        $r['ultima_quiebra'] = $r['ultima_quiebra'] ? date('d/m/Y', strtotime($r['ultima_quiebra'])) : 'N/A';
    }
    
    return $datos;
}

function obtenerTimelineQuiebras($conn, $filtros): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    // Limpiar resultados pendientes antes de la consulta
    limpiar_resultados_backend($conn);
    
    // Incluir día de la semana (1 = lunes, 7 = domingo)
    $sql = "SELECT fecha as fecha_local, 
                   DATE_FORMAT(fecha, '%d/%m') as fecha_display,
                   DAYOFWEEK(fecha) as dia_semana,
                   COUNT(*) as total 
            FROM registro_quiebras
            WHERE fecha BETWEEN ? AND ?
              AND DAYOFWEEK(fecha) != 1
            GROUP BY DATE(fecha) 
            ORDER BY fecha_local ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error prepare obtenerTimelineQuiebras: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    
    if (!$stmt->execute()) {
        error_log("Error execute obtenerTimelineQuiebras: " . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $result = $stmt->get_result();
    $datos = [];
    while ($row = $result->fetch_assoc()) {
        $datos[] = [
            'fecha' => $row['fecha_local'],
            'fecha_display' => $row['fecha_display'],
            'total' => (int)$row['total'],
            'dia_semana' => (int)$row['dia_semana']
        ];
    }
    $stmt->close();
    
    return $datos;
}
function obtenerPromedioQuiebras($conn, $filtros): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    // Obtener timeline SIN filtrar domingos (para estadísticas reales)
    $sql = "SELECT fecha as fecha_local, 
                   COUNT(*) as total 
            FROM registro_quiebras
            WHERE fecha BETWEEN ? AND ?
            GROUP BY DATE(fecha) 
            ORDER BY fecha_local ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $result = $stmt->get_result();
    $valores = [];
    while ($row = $result->fetch_assoc()) {
        $valores[] = (int)$row['total'];
    }
    $stmt->close();
    
    $totalDias = count($valores);
    $totalQuiebras = array_sum($valores);
    $promedio = $totalDias > 0 ? round($totalQuiebras / $totalDias, 1) : 0;
    
    sort($valores);
    $mediana = 0;
    if ($totalDias > 0) {
        $mid = floor($totalDias / 2);
        if ($totalDias % 2 == 0) {
            $mediana = ($valores[$mid - 1] + $valores[$mid]) / 2;
        } else {
            $mediana = $valores[$mid];
        }
        $mediana = round($mediana, 1);
    }
    
    return [
        'promedio' => $promedio,
        'mediana' => $mediana,
        'dias_con_quiebras' => $totalDias,
        'total_quiebras' => $totalQuiebras,
        'maximo' => $totalDias > 0 ? max($valores) : 0,
        'minimo' => $totalDias > 0 ? min($valores) : 0
    ];
}

function obtenerTopMotivos($conn, $filtros, int $limite = 30): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT motivo, COUNT(*) as total 
            FROM registro_quiebras 
            WHERE fecha BETWEEN ? AND ? AND motivo IS NOT NULL AND motivo != ''
            GROUP BY motivo 
            ORDER BY total DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $fecha_inicio, $fecha_fin, $limite);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $datos;
}

function obtenerQuiebrasPorTurno($conn, $filtros): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $sql = "SELECT COALESCE(turno, 'No especificado') as turno, COUNT(*) as total 
            FROM registro_quiebras 
            WHERE fecha BETWEEN ? AND ?
            GROUP BY turno 
            ORDER BY total DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $datos;
}

function obtenerAreasProduccion($conn, $filtros): array
{
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    // Paso 1: Obtener todos los empleados y el área donde produjeron (su área principal)
    $sqlEmpleadosArea = "SELECT DISTINCT empleado, area
                         FROM (
                             SELECT empleado, area FROM produccion WHERE DATE(fecha) BETWEEN ? AND ? AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                             UNION
                             SELECT empleado, area FROM registros_antiguos WHERE DATE(fecha) BETWEEN ? AND ? AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                         ) AS empleados_produccion
                         GROUP BY empleado";
    
    $stmt = $conn->prepare($sqlEmpleadosArea);
    $stmt->bind_param('ssss', $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $result = $stmt->get_result();
    $empleadosAreaMap = [];
    while ($row = $result->fetch_assoc()) {
        $empleadosAreaMap[$row['empleado']] = $row['area'];
    }
    $stmt->close();
    
    // Paso 2: Obtener todas las quiebras y asignarlas al área del empleado
    $sqlQuiebras = "SELECT empleado, COUNT(*) as total_quiebras
                    FROM registro_quiebras
                    WHERE fecha BETWEEN ? AND ?
                      AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                    GROUP BY empleado";
    
    $stmt = $conn->prepare($sqlQuiebras);
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $result = $stmt->get_result();
    $quiebrasPorEmpleado = [];
    while ($row = $result->fetch_assoc()) {
        $quiebrasPorEmpleado[$row['empleado']] = (int)$row['total_quiebras'];
    }
    $stmt->close();
    
    // Paso 3: Obtener producción por área
    $sqlProd = "SELECT area, COUNT(*) as total_produccion
                FROM (SELECT area FROM produccion WHERE DATE(fecha) BETWEEN ? AND ?
                      UNION ALL SELECT area FROM registros_antiguos WHERE DATE(fecha) BETWEEN ? AND ?) p
                WHERE area IS NOT NULL AND area != ''
                GROUP BY area";
    
    $stmt = $conn->prepare($sqlProd);
    $stmt->bind_param('ssss', $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $result = $stmt->get_result();
    $produccionPorArea = [];
    while ($row = $result->fetch_assoc()) {
        $produccionPorArea[$row['area']] = (int)$row['total_produccion'];
    }
    $stmt->close();
    
    // Paso 4: Contabilizar quiebras por área (basado en el área DONDE PRODUJO el empleado)
    $quiebrasPorArea = [];
    $empleadosConQuiebrasPorArea = [];
    
    foreach ($quiebrasPorEmpleado as $empleado => $totalQuiebras) {
        if (isset($empleadosAreaMap[$empleado])) {
            $areaEmpleado = $empleadosAreaMap[$empleado];
            $quiebrasPorArea[$areaEmpleado] = ($quiebrasPorArea[$areaEmpleado] ?? 0) + $totalQuiebras;
            $empleadosConQuiebrasPorArea[$areaEmpleado] = ($empleadosConQuiebrasPorArea[$areaEmpleado] ?? 0) + 1;
        } else {
            // Empleado sin producción en el período - asignar a "Sin área"
            $quiebrasPorArea['Sin área'] = ($quiebrasPorArea['Sin área'] ?? 0) + $totalQuiebras;
            $empleadosConQuiebrasPorArea['Sin área'] = ($empleadosConQuiebrasPorArea['Sin área'] ?? 0) + 1;
        }
    }
    
    // Paso 5: Construir resultado combinando todas las áreas
    $todasLasAreas = array_unique(array_merge(array_keys($produccionPorArea), array_keys($quiebrasPorArea)));
    $resultado = [];
    
    foreach ($todasLasAreas as $area) {
        $totalProd = $produccionPorArea[$area] ?? 0;
        $totalQuie = $quiebrasPorArea[$area] ?? 0;
        $empleadosConQuie = $empleadosConQuiebrasPorArea[$area] ?? 0;
        
        // Solo incluir áreas con producción o quiebras
        if ($totalProd > 0 || $totalQuie > 0) {
            $resultado[] = [
                'area' => $area,
                'total_produccion' => $totalProd,
                'total_quiebras' => $totalQuie,
                'empleados_con_quiebras' => $empleadosConQuie
            ];
        }
    }
    
    // Ordenar por área
    usort($resultado, function($a, $b) {
        return strcmp($a['area'], $b['area']);
    });
    
    return $resultado;
}

// ============================================
// ENDPOINT: EFICIENCIA ÚLTIMOS 6 MESES
// ============================================
if (isset($_GET['eficiencia_6meses'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $resultado = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("first day of -$i month");
            $anio = date('Y', $ts);
            $mes = date('m', $ts);
            $fechaInicio = "$anio-$mes-01";
            $fechaFin = date('Y-m-t', $ts);
            $labelMes = date('M Y', $ts);
            
            $sqlProd = "SELECT COUNT(DISTINCT orden) as total FROM (
                            SELECT orden FROM produccion
                            WHERE area='Bodega de Aros' AND equipo='Empaque'
                              AND orden IS NOT NULL AND orden != ''
                              AND DATE(fecha) BETWEEN ? AND ?
                            UNION
                            SELECT orden FROM registros_antiguos
                            WHERE area='Bodega de Aros' AND equipo='Empaque'
                              AND orden IS NOT NULL AND orden != ''
                              AND DATE(fecha) BETWEEN ? AND ?
                        ) AS e";
            $stmt = $conn->prepare($sqlProd);
            $stmt->bind_param('ssss', $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
            $stmt->execute();
            $prod = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            $sqlQ = "SELECT COUNT(*) as total FROM registro_quiebras WHERE fecha BETWEEN ? AND ?";
            $stmt = $conn->prepare($sqlQ);
            $stmt->bind_param('ss', $fechaInicio, $fechaFin);
            $stmt->execute();
            $quiebras = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            $totalOk = $prod;
            $totalBase = $totalOk + $quiebras;
            $eficiencia = $totalBase > 0 ? round(($totalOk / $totalBase) * 100, 2) : 100;
            
            $resultado[] = [
                'mes' => $labelMes,
                'mes_key' => "$anio-$mes",
                'produccion' => $prod,
                'quiebras' => $quiebras,
                'cc_validas' => 0,
                'eficiencia' => $eficiencia,
            ];
        }
        echo json_encode(['success' => true, 'data' => $resultado], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DATOS DE TABLAS VÍA AJAX
// ============================================
if (isset($_GET['ajax_tablas'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $filtrosAjax = [
            'fecha_inicio' => $_GET['fecha_inicio'] ?? date('Y-m-d'),
            'fecha_fin' => $_GET['fecha_fin'] ?? date('Y-m-d')
        ];
        
        $response = [
            'success' => true,
            'top_ordenes' => obtenerTopOrdenes($conn, $filtrosAjax, 30),
            'top_empleados_quiebras' => obtenerTopEmpleadosQuiebras($conn, $filtrosAjax, 60),
            'top_empleados_produccion' => obtenerTopEmpleadosProduccion($conn, $filtrosAjax),
            'top_equipos' => obtenerTopEquiposQuiebras($conn, $filtrosAjax, 50),
            'top_responsables' => obtenerTopResponsables($conn, $filtrosAjax, 50),
            'areas_lista' => obtenerAreasProduccion($conn, $filtrosAjax),
            'timeline_data' => obtenerTimelineQuiebras($conn, $filtrosAjax),
            'top_motivos' => obtenerTopMotivos($conn, $filtrosAjax, 15),
            'quiebras_turno' => obtenerQuiebrasPorTurno($conn, $filtrosAjax)
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: PRODUCCIÓN EN VIVO
// ============================================
if (isset($_GET['produccion_vivo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $fecha = date('Y-m-d');
    $area_filter = $_GET['area'] ?? '';
    
    $sql = "SELECT COUNT(*) as total_produccion, COUNT(DISTINCT empleado) as empleados_activos,
            COUNT(DISTINCT area) as areas_activas, COUNT(DISTINCT equipo) as equipos_activos,
            COUNT(DISTINCT orden) as ordenes_procesadas
            FROM produccion WHERE DATE(fecha) = ?";
    
    $params = [$fecha];
    $types = "s";
    
    if (!empty($area_filter)) {
        $sql .= " AND area = ?";
        $params[] = $area_filter;
        $types .= "s";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $sqlHora = "SELECT HOUR(fecha) as hora, COUNT(*) as produccion
                FROM produccion WHERE DATE(fecha) = ?
                GROUP BY HOUR(fecha) ORDER BY hora ASC";
    $stmt = $conn->prepare($sqlHora);
    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $produccion_por_hora = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $sqlArea = "SELECT area, COUNT(*) as total_produccion
                FROM produccion WHERE DATE(fecha) = ?
                GROUP BY area ORDER BY total_produccion DESC";
    $stmt = $conn->prepare($sqlArea);
    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $produccion_por_area = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $sqlEmpleado = "SELECT empleado, COUNT(*) as total_produccion
                    FROM produccion WHERE DATE(fecha) = ? AND empleado IS NOT NULL
                    GROUP BY empleado ORDER BY total_produccion DESC LIMIT 10000";
    $stmt = $conn->prepare($sqlEmpleado);
    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $produccion_por_empleado = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $sqlEquipo = "SELECT equipo, COUNT(*) as total
                  FROM produccion WHERE DATE(fecha) = ? AND equipo IS NOT NULL
                  GROUP BY equipo ORDER BY total DESC LIMIT 10";
    $stmt = $conn->prepare($sqlEquipo);
    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $produccion_por_equipo = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => [
                'total_produccion' => (int)($stats['total_produccion'] ?? 0),
                'empleados_activos' => (int)($stats['empleados_activos'] ?? 0),
                'areas_activas' => (int)($stats['areas_activas'] ?? 0),
                'equipos_activos' => (int)($stats['equipos_activos'] ?? 0),
                'ordenes_procesadas' => (int)($stats['ordenes_procesadas'] ?? 0)
            ],
            'produccion_por_hora' => $produccion_por_hora,
            'produccion_por_area' => $produccion_por_area,
            'produccion_por_empleado' => $produccion_por_empleado,
            'produccion_por_equipo' => $produccion_por_equipo,
            'ultima_actualizacion' => date('H:i:s')
        ]
    ]);
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE PRODUCCIÓN VIVO
// ============================================
if (isset($_GET['detalles_produccion_vivo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $fecha = date('Y-m-d');
    $area_filter = $_GET['area'] ?? '';
    
    $sql = "SELECT id, empleado, area, equipo, orden, 
            TIME_FORMAT(fecha, '%H:%i') as hora,
            CASE WHEN TIMESTAMPDIFF(MINUTE, fecha, NOW()) < 5 THEN 'justo_ahora'
                 WHEN TIMESTAMPDIFF(MINUTE, fecha, NOW()) < 30 THEN 'reciente'
                 ELSE 'antiguo' END as estado
            FROM produccion WHERE DATE(fecha) = ?";
    
    $params = [$fecha];
    $types = "s";
    
    if (!empty($area_filter)) {
        $sql .= " AND area = ?";
        $params[] = $area_filter;
        $types .= "s";
    }
    
    $sql .= " ORDER BY fecha DESC LIMIT 10000";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode(['success' => true, 'data' => $registros]);
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: WIP EMPAQUE - CORREGIDO
// ============================================
if (isset($_GET['wip_empaque'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // Fecha actual
        $fecha_actual = new DateTime();
        $anio_actual = $fecha_actual->format('Y');
        $mes_actual = $fecha_actual->format('m');
        
        // Fechas del mes actual (para finalizadas)
        $fecha_inicio_mes_actual = "$anio_actual-$mes_actual-01";
        $fecha_fin_mes_actual = date('Y-m-t', strtotime($fecha_inicio_mes_actual));
        
        // LÍMITE para WIP: Últimos 3 meses (para no perder órdenes pendientes)
        $fecha_limite_wip = date('Y-m-d', strtotime('-3 months'));
        
        // =============================================
        // 1. Obtener TODAS las órdenes activas (WIP)
        //    Buscamos en los últimos 3 meses (incluye mes anterior y actual)
        //    NO importa el mes, mientras NO estén finalizadas
        // =============================================
        $sqlWip = "SELECT orden, 
                       MIN(fecha) as primera_produccion, 
                       MAX(fecha) as ultima_produccion,
                       COUNT(*) as total_registros,
                       GROUP_CONCAT(DISTINCT area ORDER BY area SEPARATOR ', ') as areas,
                       GROUP_CONCAT(DISTINCT equipo ORDER BY equipo SEPARATOR ', ') as equipos
                FROM (
                    SELECT orden, fecha, area, equipo FROM produccion 
                    WHERE fecha >= ? AND orden IS NOT NULL AND TRIM(orden) != '' AND orden != 'N/A'
                    UNION ALL 
                    SELECT orden, fecha, area, equipo FROM registros_antiguos 
                    WHERE fecha >= ? AND orden IS NOT NULL AND TRIM(orden) != '' AND orden != 'N/A'
                ) t
                GROUP BY orden";
        
        $stmtWip = $conn->prepare($sqlWip);
        if (!$stmtWip) {
            throw new Exception("Error preparando consulta WIP: " . $conn->error);
        }
        
        $stmtWip->bind_param('ss', $fecha_limite_wip, $fecha_limite_wip);
        $stmtWip->execute();
        $resultWip = $stmtWip->get_result();
        
        $todasLasOrdenes = [];
        while ($row = $resultWip->fetch_assoc()) {
            $todasLasOrdenes[$row['orden']] = $row;
        }
        $stmtWip->close();
        
        // =============================================
        // 2. Obtener órdenes FINALIZADAS (solo del MES ACTUAL)
        //    Las finalizadas de meses anteriores NO se muestran
        // =============================================
        $sqlFin = "SELECT DISTINCT orden 
                   FROM (
                       SELECT orden FROM produccion 
                       WHERE area = 'Bodega de Aros' AND equipo = 'Empaque' 
                         AND orden IS NOT NULL AND orden != '' AND orden != 'N/A'
                         AND fecha BETWEEN ? AND ?
                       UNION 
                       SELECT orden FROM registros_antiguos 
                       WHERE area = 'Bodega de Aros' AND equipo = 'Empaque' 
                         AND orden IS NOT NULL AND orden != '' AND orden != 'N/A'
                         AND fecha BETWEEN ? AND ?
                   ) f";
        
        $stmtFin = $conn->prepare($sqlFin);
        if (!$stmtFin) {
            throw new Exception("Error preparando consulta finalizadas: " . $conn->error);
        }
        
        $stmtFin->bind_param('ssss', $fecha_inicio_mes_actual, $fecha_fin_mes_actual, $fecha_inicio_mes_actual, $fecha_fin_mes_actual);
        $stmtFin->execute();
        $resultFin = $stmtFin->get_result();
        
        $ordenesFinalizadasMesActual = [];
        while ($row = $resultFin->fetch_assoc()) {
            $ordenesFinalizadasMesActual[$row['orden']] = true;
        }
        $stmtFin->close();
        
        // =============================================
        // 3. Obtener órdenes que NUNCA han sido finalizadas (WIP histórico)
        //    Para saber si una orden ya fue finalizada en algún momento
        // =============================================
        $sqlFinHistorico = "SELECT DISTINCT orden 
                            FROM (
                                SELECT orden FROM produccion 
                                WHERE area = 'Bodega de Aros' AND equipo = 'Empaque' 
                                  AND orden IS NOT NULL AND orden != '' AND orden != 'N/A'
                                UNION 
                                SELECT orden FROM registros_antiguos 
                                WHERE area = 'Bodega de Aros' AND equipo = 'Empaque' 
                                  AND orden IS NOT NULL AND orden != '' AND orden != 'N/A'
                            ) f";
        
        $stmtFinHist = $conn->prepare($sqlFinHistorico);
        if (!$stmtFinHist) {
            throw new Exception("Error preparando consulta finalizadas histórico: " . $conn->error);
        }
        
        $stmtFinHist->execute();
        $resultFinHist = $stmtFinHist->get_result();
        
        $ordenesFinalizadasHistoricas = [];
        while ($row = $resultFinHist->fetch_assoc()) {
            $ordenesFinalizadasHistoricas[$row['orden']] = true;
        }
        $stmtFinHist->close();
        
        // =============================================
        // 4. Procesar cada orden
        // =============================================
        $wipActivas = [];      // Órdenes que siguen activas (NUNCA finalizadas)
        $finalizadasList = []; // Órdenes finalizadas en el MES ACTUAL
        
        foreach ($todasLasOrdenes as $ordenNombre => $orden) {
            // Verificar si la orden NUNCA ha sido finalizada (históricamente)
            $nuncaFinalizada = !isset($ordenesFinalizadasHistoricas[$ordenNombre]);
            
            // Verificar si fue finalizada en el MES ACTUAL
            $finalizadaEsteMes = isset($ordenesFinalizadasMesActual[$ordenNombre]);
            
            if ($finalizadaEsteMes) {
                // Orden finalizada en el mes actual → mostrar en Finalizadas
                $sqlHoraBodega = "(SELECT fecha 
                                  FROM produccion 
                                  WHERE orden = ? AND area = 'Bodega de Aros' AND equipo = 'Empaque'
                                  ORDER BY fecha DESC 
                                  LIMIT 1)
                                  UNION ALL
                                  (SELECT fecha 
                                  FROM registros_antiguos 
                                  WHERE orden = ? AND area = 'Bodega de Aros' AND equipo = 'Empaque'
                                  ORDER BY fecha DESC 
                                  LIMIT 1)
                                  ORDER BY fecha DESC LIMIT 1";
                $stmtBodega = $conn->prepare($sqlHoraBodega);
                if ($stmtBodega) {
                    $stmtBodega->bind_param('ss', $ordenNombre, $ordenNombre);
                    $stmtBodega->execute();
                    $horaBodega = $stmtBodega->get_result()->fetch_assoc();
                    $stmtBodega->close();
                    
                    if ($horaBodega && isset($horaBodega['fecha'])) {
                        $fechaObj = new DateTime($horaBodega['fecha']);
                        $orden['hora_bodega'] = $fechaObj->format('d/m/Y H:i:s');
                    } else {
                        $orden['hora_bodega'] = 'Fecha no registrada';
                    }
                } else {
                    $orden['hora_bodega'] = 'No registrada';
                }
                
                // Formatear primera producción
                if ($orden['primera_produccion']) {
                    $fechaObj = new DateTime($orden['primera_produccion']);
                    $orden['primera_produccion_display'] = $fechaObj->format('d/m/Y H:i');
                }
                
                $finalizadasList[] = $orden;
            } 
            elseif ($nuncaFinalizada) {
                // Orden NUNCA ha sido finalizada → WIP activo
                // Formatear primera producción
                if ($orden['primera_produccion']) {
                    $fechaObj = new DateTime($orden['primera_produccion']);
                    $orden['primera_produccion_display'] = $fechaObj->format('d/m/Y H:i');
                } else {
                    $orden['primera_produccion_display'] = 'N/A';
                }
                
                // Obtener el ÚLTIMO movimiento de producción
                $sqlUltimoMov = "(SELECT fecha, area, equipo, 'produccion' as fuente
                                 FROM produccion 
                                 WHERE orden = ? 
                                 ORDER BY fecha DESC 
                                 LIMIT 1)
                                 UNION ALL
                                 (SELECT fecha, area, equipo, 'antiguo' as fuente
                                 FROM registros_antiguos 
                                 WHERE orden = ? 
                                 ORDER BY fecha DESC 
                                 LIMIT 1)
                                 ORDER BY fecha DESC LIMIT 1";
                
                $stmtMov = $conn->prepare($sqlUltimoMov);
                if ($stmtMov) {
                    $stmtMov->bind_param('ss', $ordenNombre, $ordenNombre);
                    $stmtMov->execute();
                    $ultimoProd = $stmtMov->get_result()->fetch_assoc();
                    $stmtMov->close();
                } else {
                    $ultimoProd = null;
                }
                
                if ($ultimoProd && isset($ultimoProd['fecha'])) {
                    $fechaObj = new DateTime($ultimoProd['fecha']);
                    $orden['ultimo_movimiento_completo'] = $fechaObj->format('d/m/Y H:i:s');
                    $orden['ultimo_movimiento_area'] = !empty($ultimoProd['area']) ? $ultimoProd['area'] : 'No registrada';
                    $orden['ultimo_movimiento_equipo'] = !empty($ultimoProd['equipo']) ? $ultimoProd['equipo'] : 'No registrado';
                    $orden['fuente_ultimo_mov'] = $ultimoProd['fuente'] ?? 'produccion';
                } else {
                    $orden['ultimo_movimiento_completo'] = 'Sin movimiento';
                    $orden['ultimo_movimiento_area'] = 'Sin movimiento';
                    $orden['ultimo_movimiento_equipo'] = 'Sin movimiento';
                    $orden['fuente_ultimo_mov'] = 'N/A';
                }
                
                $wipActivas[] = $orden;
            }
            // Si la orden fue finalizada en meses anteriores pero NO en el actual → NO se muestra en ninguna tabla
        }
        
        // Ordenar WIP por fecha de última producción (más reciente primero)
        usort($wipActivas, function($a, $b) {
            return strtotime($b['ultima_produccion']) - strtotime($a['ultima_produccion']);
        });
        
        // Ordenar finalizadas por fecha de empaque (más reciente primero)
        usort($finalizadasList, function($a, $b) {
            return strtotime($b['hora_bodega'] ?? '1900-01-01') - strtotime($a['hora_bodega'] ?? '1900-01-01');
        });
        
        // =============================================
        // 5. Enviar respuesta exitosa
        // =============================================
        $response = [
            'success' => true,
            'wip' => $wipActivas,
            'finalizadas' => $finalizadasList,
            'mes_actual' => "$anio_actual-$mes_actual",
            'rango_mes' => "WIP: Órdenes nunca finalizadas | Finalizadas: SOLO del mes actual",
            'total_wip' => count($wipActivas),
            'total_finalizadas' => count($finalizadasList),
            'fecha_consulta' => date('Y-m-d H:i:s'),
            'info' => [
                'wip_descripcion' => 'Órdenes que NUNCA han sido finalizadas (nunca llegaron a Bodega de Aros con Empaque)',
                'finalizadas_descripcion' => 'Órdenes finalizadas SOLO en el mes actual. Al cambiar de mes, este contador se reinicia a 0.',
                'persistencia_wip' => 'Las órdenes pendientes del mes anterior continúan en WIP hasta que se finalicen.'
            ]
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("❌ Error WIP Empaque: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage(),
            'error_details' => $e->getTraceAsString()
        ], JSON_UNESCAPED_UNICODE);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE ORDEN
// ============================================
if (isset($_GET['detalles_orden'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $orden = $_GET['detalles_orden'];
    $fecha_inicio = $filtros['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $filtros['fecha_fin'] ?? date('Y-m-d');
    
    try {
        // Obtener quiebras de la orden
        $sql = "SELECT q.id, q.fecha, TIME_FORMAT(q.fecha, '%H:%i') as hora, 
                       q.empleado, q.motivo, q.area, q.equipo, q.responsable,
                       q.empleado_registro, q.turno
                FROM registro_quiebras q
                WHERE q.orden = ? AND q.fecha BETWEEN ? AND ?
                ORDER BY q.fecha DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $orden, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // También obtener producción relacionada
        $sqlProd = "SELECT p.id, p.fecha, TIME_FORMAT(p.fecha, '%H:%i') as hora,
                           p.empleado, p.area, p.equipo
                    FROM produccion p
                    WHERE p.orden = ? AND DATE(p.fecha) BETWEEN ? AND ?
                    ORDER BY p.fecha DESC LIMIT 10000";
        
        $stmtProd = $conn->prepare($sqlProd);
        $stmtProd->bind_param('sss', $orden, $fecha_inicio, $fecha_fin);
        $stmtProd->execute();
        $produccion = $stmtProd->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtProd->close();
        
        echo json_encode([
            'success' => true,
            'data' => $registros,
            'produccion' => $produccion,
            'total_quiebras' => count($registros)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE EMPLEADO
// ============================================
if (isset($_GET['detalles_empleado'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $empleado = $_GET['detalles_empleado'];
    $fecha_inicio = $filtros['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $filtros['fecha_fin'] ?? date('Y-m-d');
    
    try {
        // Obtener quiebras del empleado
        $sql = "SELECT q.id, q.fecha, TIME_FORMAT(q.fecha, '%H:%i') as hora,
                       q.orden, q.motivo, q.area, q.equipo, q.responsable,
                       q.empleado_registro, q.turno, 'quiebra' as tipo
                FROM registro_quiebras q
                WHERE q.empleado = ? AND q.fecha BETWEEN ? AND ?
                ORDER BY q.fecha DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $quiebras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Obtener producción del empleado
        $sqlProd = "SELECT p.id, p.fecha, TIME_FORMAT(p.fecha, '%H:%i') as hora,
                           p.orden, p.area, p.equipo, 'produccion' as tipo
                    FROM produccion p
                    WHERE p.empleado = ? AND DATE(p.fecha) BETWEEN ? AND ?
                    ORDER BY p.fecha DESC LIMIT 10000";
        
        $stmtProd = $conn->prepare($sqlProd);
        $stmtProd->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmtProd->execute();
        $produccion = $stmtProd->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtProd->close();
        
        // Combinar registros
        $registros = array_merge($quiebras, $produccion);
        usort($registros, function($a, $b) {
            return strtotime($b['fecha']) - strtotime($a['fecha']);
        });
        
        // Datos diarios para gráfico
        $sqlDiario = "SELECT DATE(fecha) as fecha_dia, 
                             SUM(CASE WHEN tipo = 'quiebra' THEN 1 ELSE 0 END) as quiebras_dia,
                             SUM(CASE WHEN tipo = 'produccion' THEN 1 ELSE 0 END) as produccion_dia
                      FROM (
                          SELECT fecha, 'quiebra' as tipo FROM registro_quiebras WHERE empleado = ? AND fecha BETWEEN ? AND ?
                          UNION ALL
                          SELECT fecha, 'produccion' as tipo FROM produccion WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                      ) combined
                      GROUP BY DATE(fecha)
                      ORDER BY fecha_dia ASC";
        
        $stmtDia = $conn->prepare($sqlDiario);
        $stmtDia->bind_param('ssssss', $empleado, $fecha_inicio, $fecha_fin, $empleado, $fecha_inicio, $fecha_fin);
        $stmtDia->execute();
        $datos_diarios = $stmtDia->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtDia->close();
        
        // Producción por hora
        $sqlHora = "SELECT HOUR(fecha) as hora, COUNT(*) as total_produccion
                    FROM produccion
                    WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                    GROUP BY HOUR(fecha)
                    ORDER BY hora ASC";
        
        $stmtHora = $conn->prepare($sqlHora);
        $stmtHora->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmtHora->execute();
        $produccion_por_hora = $stmtHora->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtHora->close();
        
        echo json_encode([
            'success' => true,
            'data' => $registros,
            'datos_diarios' => $datos_diarios,
            'produccion_por_hora' => $produccion_por_hora,
            'total_quiebras' => count($quiebras),
            'total_produccion' => count($produccion)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE ÁREA
// ============================================
if (isset($_GET['detalles_area'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $area = $_GET['detalles_area'];
    $fecha_inicio = $filtros['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $filtros['fecha_fin'] ?? date('Y-m-d');
    
    try {
        // Obtener empleados del área con sus estadísticas
        $sqlEmpleados = "SELECT e.empleado,
                                COALESCE(p.total_produccion, 0) as total_produccion,
                                COALESCE(q.total_quiebras, 0) as quiebras,
                                MIN(e.fecha) as primera_produccion,
                                MAX(e.fecha) as ultima_produccion
                         FROM (
                             SELECT DISTINCT empleado, MIN(fecha) as fecha, MAX(fecha) as ultima
                             FROM produccion 
                             WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                             GROUP BY empleado
                         ) e
                         LEFT JOIN (
                             SELECT empleado, COUNT(*) as total_produccion
                             FROM produccion 
                             WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                             GROUP BY empleado
                         ) p ON e.empleado = p.empleado
                         LEFT JOIN (
                             SELECT empleado, COUNT(*) as total_quiebras
                             FROM registro_quiebras 
                             WHERE area = ? AND fecha BETWEEN ? AND ?
                             GROUP BY empleado
                         ) q ON e.empleado = q.empleado
                         ORDER BY p.total_produccion DESC";
        
        $stmt = $conn->prepare($sqlEmpleados);
        $stmt->bind_param('ssssssss', $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $empleados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Calcular ratios de eficiencia
        foreach ($empleados as &$emp) {
            $total = $emp['total_produccion'] + $emp['quiebras'];
            $emp['ratio'] = $total > 0 ? round(($emp['total_produccion'] / $total) * 100, 1) : 100;
        }
        
        // Datos diarios del área
        $sqlDiario = "SELECT DATE(fecha) as fecha_dia, 
                             COUNT(*) as total_produccion
                      FROM produccion
                      WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                      GROUP BY DATE(fecha)
                      ORDER BY fecha_dia ASC";
        
        $stmtDia = $conn->prepare($sqlDiario);
        $stmtDia->bind_param('sss', $area, $fecha_inicio, $fecha_fin);
        $stmtDia->execute();
        $datos_diarios = $stmtDia->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtDia->close();
        
        // Producción por hora
        $sqlHora = "SELECT HOUR(fecha) as hora, COUNT(*) as total_produccion
                    FROM produccion
                    WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                    GROUP BY HOUR(fecha)
                    ORDER BY hora ASC";
        
        $stmtHora = $conn->prepare($sqlHora);
        $stmtHora->bind_param('sss', $area, $fecha_inicio, $fecha_fin);
        $stmtHora->execute();
        $produccion_por_hora = $stmtHora->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtHora->close();
        
        // Estadísticas del área
        $sqlStats = "SELECT COUNT(DISTINCT empleado) as empleados_activos,
                            SUM(CASE WHEN tipo = 'produccion' THEN 1 ELSE 0 END) as total_produccion,
                            SUM(CASE WHEN tipo = 'quiebra' THEN 1 ELSE 0 END) as total_quiebras
                     FROM (
                         SELECT empleado, 'produccion' as tipo FROM produccion WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                         UNION ALL
                         SELECT empleado, 'quiebra' as tipo FROM registro_quiebras WHERE area = ? AND fecha BETWEEN ? AND ?
                     ) combined";
        
        $stmtStats = $conn->prepare($sqlStats);
        $stmtStats->bind_param('ssssss', $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin);
        $stmtStats->execute();
        $area_detalle = $stmtStats->get_result()->fetch_assoc();
        $stmtStats->close();
        
        $total_prod = (int)($area_detalle['total_produccion'] ?? 0);
        $total_q = (int)($area_detalle['total_quiebras'] ?? 0);
        $total_base = $total_prod + $total_q;
        $area_detalle['eficiencia'] = $total_base > 0 ? round(($total_prod / $total_base) * 100, 1) : 100;
        $area_detalle['productividad_por_hora'] = $total_prod > 0 ? round($total_prod / 8, 1) : 0;
        $area_detalle['horas_totales'] = 8;
        
        echo json_encode([
            'success' => true,
            'empleados' => $empleados,
            'area_detalle' => $area_detalle,
            'datos_diarios' => $datos_diarios,
            'produccion_por_hora' => $produccion_por_hora
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE EQUIPO
// ============================================
if (isset($_GET['detalles_equipo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $equipo = $_GET['detalles_equipo'];
    $fecha_inicio = $filtros['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $filtros['fecha_fin'] ?? date('Y-m-d');
    
    try {
        $sql = "SELECT q.id, q.fecha, TIME_FORMAT(q.fecha, '%H:%i') as hora,
                       q.empleado, q.orden, q.motivo, q.area, q.responsable
                FROM registro_quiebras q
                WHERE q.equipo = ? AND q.fecha BETWEEN ? AND ?
                ORDER BY q.fecha DESC LIMIT 200";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $equipo, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Estadísticas
        $sqlStats = "SELECT COUNT(*) as total_quiebras,
                            COUNT(DISTINCT empleado) as empleados_afectados,
                            COUNT(DISTINCT orden) as ordenes_afectadas,
                            COUNT(DISTINCT motivo) as motivos_diferentes,
                            COUNT(DISTINCT area) as areas_afectadas,
                            MIN(fecha) as primera_quiebra,
                            MAX(fecha) as ultima_quiebra
                     FROM registro_quiebras
                     WHERE equipo = ? AND fecha BETWEEN ? AND ?";
        
        $stmtStats = $conn->prepare($sqlStats);
        $stmtStats->bind_param('sss', $equipo, $fecha_inicio, $fecha_fin);
        $stmtStats->execute();
        $estadisticas = $stmtStats->get_result()->fetch_assoc();
        $stmtStats->close();
        
        echo json_encode([
            'success' => true,
            'registros' => $registros,
            'estadisticas' => $estadisticas
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE RESPONSABLE
// ============================================
if (isset($_GET['detalles_responsable'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $responsable = $_GET['detalles_responsable'];
    
    // Obtener fechas directamente de $_GET
    $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
    
    // Validar fechas
    $fecha_inicio = preg_replace('/[^0-9-]/', '', $fecha_inicio);
    $fecha_fin = preg_replace('/[^0-9-]/', '', $fecha_fin);
    
    if (!strtotime($fecha_inicio)) $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
    if (!strtotime($fecha_fin)) $fecha_fin = date('Y-m-d');
    
    try {
        // CORREGIDO: usar TIME_FORMAT(hora, '%H:%i') en lugar de TIME_FORMAT(fecha, '%H:%i')
        $sql = "SELECT q.id, q.fecha, 
                       TIME_FORMAT(q.hora, '%H:%i') as hora,
                       q.empleado, 
                       q.orden, 
                       q.motivo, 
                       q.area, 
                       q.equipo, 
                       q.empleado_registro
                FROM registro_quiebras q
                WHERE q.responsable = ? AND q.fecha BETWEEN ? AND ?
                ORDER BY q.fecha DESC, q.hora DESC
                LIMIT 10000";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $responsable, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Limpiar horas vacías
        foreach ($registros as &$r) {
            if (empty($r['hora']) || $r['hora'] === '00:00' || $r['hora'] === '00:00:00') {
                $r['hora'] = '--:--';
            }
        }
        
        // Estadísticas
        $sqlStats = "SELECT COUNT(*) as total_quiebras,
                            COUNT(DISTINCT orden) as ordenes_afectadas,
                            COUNT(DISTINCT empleado) as empleados_afectados,
                            COUNT(DISTINCT area) as areas_afectadas,
                            COUNT(DISTINCT equipo) as equipos_afectados,
                            COUNT(DISTINCT motivo) as motivos_diferentes,
                            MIN(fecha) as primera_quiebra,
                            MAX(fecha) as ultima_quiebra
                     FROM registro_quiebras
                     WHERE responsable = ? AND fecha BETWEEN ? AND ?";
        
        $stmtStats = $conn->prepare($sqlStats);
        $stmtStats->bind_param('sss', $responsable, $fecha_inicio, $fecha_fin);
        $stmtStats->execute();
        $estadisticas = $stmtStats->get_result()->fetch_assoc();
        $stmtStats->close();
        
        // Formatear fechas para mostrar
        $fecha_inicio_display = date('d/m/Y', strtotime($fecha_inicio));
        $fecha_fin_display = date('d/m/Y', strtotime($fecha_fin));
        
        echo json_encode([
            'success' => true,
            'registros' => $registros,
            'estadisticas' => $estadisticas,
            'fecha_inicio' => $fecha_inicio_display,
            'fecha_fin' => $fecha_fin_display
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: EXPORTAR REPORTE COMPLETO (CON DIVISIÓN AUTOMÁTICA Y SOPORTE TOTAL DE FILTROS)
// ============================================
if (isset($_GET['exportar_reporte_completo']) && $_GET['exportar_reporte_completo'] == 1) {
    $fecha_desde = $_GET['reporte_fecha_desde'] ?? date('Y-m-d', strtotime('-7 days'));
    $fecha_hasta = $_GET['reporte_fecha_hasta'] ?? date('Y-m-d');
    $hora_desde = convertir12a24($_GET['reporte_hora_desde_time'] ?? '00:00', $_GET['reporte_hora_desde_ampm'] ?? 'AM');
    $hora_hasta = convertir12a24($_GET['reporte_hora_hasta_time'] ?? '23:59', $_GET['reporte_hora_hasta_ampm'] ?? 'PM');
    $tipo_reporte = $_GET['tipo_reporte'] ?? 'completo';

    $conn->query("SET net_write_timeout = 3600, net_read_timeout = 3600");
    $rango_cruzado = rangoCruzaMedianoche($hora_desde, $hora_hasta);

    // Helper unbuffered con división automática (>1M filas)
    $streamToFileWithSplit = function(string $sql, array $params, $tmpDir, string $baseName, array $header, int $maxRows = 1000000) use ($conn) {
        $escaped = array_map(fn($v) => $conn->real_escape_string((string)$v), $params);
        $i = 0;
        $sqlFinal = preg_replace_callback('/\?/', function() use (&$i, &$escaped) {
            return "'" . $escaped[$i++] . "'";
        }, $sql);
        
        $conn->real_query($sqlFinal);
        $result = $conn->use_result();
        if (!$result) return ['totalRows' => 0, 'files' => []];
        
        $part = 1;
        $rowCount = 0;
        $currentFile = $tmpDir . '/' . $baseName . '_parte_' . str_pad($part, 3, '0', STR_PAD_LEFT) . '.csv';
        $fh = fopen($currentFile, 'w');
        fwrite($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, $header);
        
        $totalRows = 0;
        $filesCreated = [];
        
        while ($row = $result->fetch_assoc()) {
            fputcsv($fh, array_values($row));
            $rowCount++;
            $totalRows++;
            
            if ($rowCount >= $maxRows) {
                fclose($fh);
                $filesCreated[] = $currentFile;
                $part++;
                $rowCount = 0;
                $currentFile = $tmpDir . '/' . $baseName . '_parte_' . str_pad($part, 3, '0', STR_PAD_LEFT) . '.csv';
                $fh = fopen($currentFile, 'w');
                fwrite($fh, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($fh, $header);
            }
        }
        
        fclose($fh);
        if ($rowCount > 0) {
            $filesCreated[] = $currentFile;
        }
        
        $result->free();
        return ['totalRows' => $totalRows, 'files' => $filesCreated];
    };

    // Helper simple sin división (para tablas pequeñas)
    $streamToFile = function(string $sql, array $params, $fileHandle, array $header, string $separator = ',') use ($conn) {
        $escaped = array_map(fn($v) => $conn->real_escape_string((string)$v), $params);
        $i = 0;
        $sqlFinal = preg_replace_callback('/\?/', function() use (&$i, &$escaped) {
            return "'" . $escaped[$i++] . "'";
        }, $sql);
        $conn->real_query($sqlFinal);
        $result = $conn->use_result();
        if (!$result) return 0;
        fwrite($fileHandle, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fileHandle, $header, $separator);
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            fputcsv($fileHandle, array_values($row), $separator);
            $count++;
        }
        $result->free();
        return $count;
    };

    // ---- Reportes resumidos (pocas filas) — descarga directa CSV ----
    if ($tipo_reporte !== 'completo') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reporte_' . $tipo_reporte . '_' . date('Y-m-d_H-i-s') . '.csv"');
        header('X-Accel-Buffering: no');
        if (ob_get_level()) ob_end_clean();
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        switch ($tipo_reporte) {
            case 'area':
                $data = obtenerReportePorArea($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
                fputcsv($output, ['Área','Producción Total','Quiebras Totales','Empleados Activos','Eficiencia (%)','Primera Producción','Última Producción']);
                foreach ($data as $row) fputcsv($output, [$row['area'],$row['total_produccion'],$row['total_quiebras'],$row['empleados_activos'],$row['eficiencia'],$row['primera_produccion']??'N/A',$row['ultima_produccion']??'N/A']);
                break;
            case 'equipo':
                $data = obtenerReportePorEquipo($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
                fputcsv($output, ['Equipo','Quiebras','Empleados Afectados','Órdenes Afectadas','Motivos Diferentes','Áreas Afectadas','Primera Quiebra','Última Quiebra']);
                foreach ($data as $row) fputcsv($output, [$row['equipo'],$row['total_quiebras'],$row['empleados_afectados'],$row['ordenes_afectadas'],$row['motivos_diferentes'],$row['areas_afectadas'],$row['primera_quiebra'],$row['ultima_quiebra']]);
                break;
            case 'empleado':
                $data = obtenerReportePorEmpleado($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
                fputcsv($output, ['Empleado','Producción','Quiebras','Eficiencia (%)','Horas Trabajadas','Productividad/h','Primera Actividad','Última Actividad']);
                foreach ($data as $row) fputcsv($output, [$row['empleado'],$row['total_produccion'],$row['total_quiebras'],$row['eficiencia'],$row['horas_trabajadas'],$row['productividad_hora'],$row['primera_actividad'],$row['ultima_actividad']]);
                break;
            case 'quiebras':
                $data = obtenerReporteQuiebrasPorEmpleado($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
                fputcsv($output, ['Empleado','Total Quiebras','Órdenes Afectadas','Motivos Frecuentes','Equipos Relacionados','Áreas','Primera Quiebra','Última Quiebra']);
                foreach ($data as $row) fputcsv($output, [$row['empleado'],$row['total_quiebras'],$row['ordenes_afectadas'],$row['motivos_frecuentes'],$row['equipos_relacionados'],$row['areas_involucradas'],$row['primera_quiebra'],$row['ultima_quiebra']]);
                break;
        }
        fclose($output);
        $conn->close();
        exit;
    }

    // ---- Reporte COMPLETO: genera ZIP con división automática ----
    $tmpDir = sys_get_temp_dir() . '/reporte_' . uniqid();
    mkdir($tmpDir, 0700, true);

    $header = ['Fecha', 'Hora', 'Tipo', 'Empleado', 'Área', 'Equipo', 'Orden', 'Motivo/Detalle', 'Responsable', 'Turno'];

    // ============================================
    // 1. PRODUCCIÓN (con filtro de fecha y hora)
    // ============================================
    if ($rango_cruzado) {
        $resultProd = $streamToFileWithSplit(
            "SELECT DATE_FORMAT(fecha,'%Y-%m-%d') as fecha,
                    TIME_FORMAT(TIME(fecha),'%H:%i:%s') as hora,
                    'Producción' as tipo,
                    COALESCE(empleado, '') as empleado,
                    COALESCE(area, '') as area,
                    COALESCE(equipo, '') as equipo,
                    COALESCE(orden, '') as orden,
                    '' as motivo,
                    '' as responsable,
                    '' as turno
             FROM produccion
             WHERE (DATE(fecha) = ? AND TIME(fecha) >= ?) 
                OR (DATE(fecha) = ? AND TIME(fecha) <= ?) 
                OR (DATE(fecha) > ? AND DATE(fecha) < ?)
             ORDER BY fecha ASC, hora ASC",
            [$fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta],
            $tmpDir, 'produccion', $header
        );
    } else {
        $resultProd = $streamToFileWithSplit(
            "SELECT DATE_FORMAT(fecha,'%Y-%m-%d') as fecha,
                    TIME_FORMAT(TIME(fecha),'%H:%i:%s') as hora,
                    'Producción' as tipo,
                    COALESCE(empleado, '') as empleado,
                    COALESCE(area, '') as area,
                    COALESCE(equipo, '') as equipo,
                    COALESCE(orden, '') as orden,
                    '' as motivo,
                    '' as responsable,
                    '' as turno
             FROM produccion
             WHERE DATE(fecha) BETWEEN ? AND ? 
               AND TIME(fecha) BETWEEN ? AND ?
             ORDER BY fecha ASC, hora ASC",
            [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta],
            $tmpDir, 'produccion', $header
        );
    }

    // ============================================
    // 2. REGISTROS ANTIGUOS (con filtro de fecha Y HORA)
    // NOTA: Esta tabla tiene hora fija 00:00:00, por lo que:
    // - Solo se incluirán registros si la hora_desde <= 00:00:00 <= hora_hasta
    // - En rangos que cruzan medianoche, se aplica la misma lógica que producción
    // ============================================
    if ($rango_cruzado) {
        // Rango que cruza medianoche
        // Una fila con hora 00:00:00 cumple si:
        // (fecha = fecha_desde AND '00:00:00' >= hora_desde) 
        // OR (fecha = fecha_hasta AND '00:00:00' <= hora_hasta)
        // OR (fecha > fecha_desde AND fecha < fecha_hasta)
        $resultAntiguos = $streamToFileWithSplit(
            "SELECT DATE_FORMAT(fecha,'%Y-%m-%d') as fecha,
                    '00:00:00' as hora,
                    'Antiguo' as tipo,
                    COALESCE(empleado, '') as empleado,
                    COALESCE(area, '') as area,
                    COALESCE(equipo, '') as equipo,
                    COALESCE(orden, '') as orden,
                    '' as motivo,
                    '' as responsable,
                    '' as turno
             FROM registros_antiguos
             WHERE (
                (DATE(fecha) = ? AND '00:00:00' >= ?)
                OR (DATE(fecha) = ? AND '00:00:00' <= ?)
                OR (DATE(fecha) > ? AND DATE(fecha) < ?)
             )
             ORDER BY fecha ASC",
            [$fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta],
            $tmpDir, 'registros_antiguos', $header
        );
    } else {
        // Rango normal (hora_inicio <= hora_fin)
        // hora 00:00:00 solo cumple si hora_desde <= 00:00:00 <= hora_hasta
        $resultAntiguos = $streamToFileWithSplit(
            "SELECT DATE_FORMAT(fecha,'%Y-%m-%d') as fecha,
                    '00:00:00' as hora,
                    'Antiguo' as tipo,
                    COALESCE(empleado, '') as empleado,
                    COALESCE(area, '') as area,
                    COALESCE(equipo, '') as equipo,
                    COALESCE(orden, '') as orden,
                    '' as motivo,
                    '' as responsable,
                    '' as turno
             FROM registros_antiguos
             WHERE DATE(fecha) BETWEEN ? AND ? 
               AND '00:00:00' BETWEEN ? AND ?
             ORDER BY fecha ASC",
            [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta],
            $tmpDir, 'registros_antiguos', $header
        );
    }

    // ============================================
    // 3. QUIEBRAS (con filtro de fecha y hora)
    // ============================================
    if ($rango_cruzado) {
        $resultQuiebras = $streamToFileWithSplit(
            "SELECT fecha,
                    TIME_FORMAT(TIME(hora),'%H:%i:%s') as hora,
                    'Quiebra' as tipo,
                    COALESCE(empleado, '') as empleado,
                    COALESCE(area, '') as area,
                    COALESCE(equipo, '') as equipo,
                    COALESCE(orden, '') as orden,
                    COALESCE(motivo, '') as motivo,
                    COALESCE(responsable, '') as responsable,
                    COALESCE(turno, '') as turno
             FROM registro_quiebras
             WHERE (fecha = ? AND TIME(hora) >= ?) 
                OR (fecha = ? AND TIME(hora) <= ?) 
                OR (fecha > ? AND fecha < ?)
             ORDER BY fecha ASC, hora ASC",
            [$fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta],
            $tmpDir, 'quiebras', $header
        );
    } else {
        $resultQuiebras = $streamToFileWithSplit(
            "SELECT fecha,
                    TIME_FORMAT(TIME(hora),'%H:%i:%s') as hora,
                    'Quiebra' as tipo,
                    COALESCE(empleado, '') as empleado,
                    COALESCE(area, '') as area,
                    COALESCE(equipo, '') as equipo,
                    COALESCE(orden, '') as orden,
                    COALESCE(motivo, '') as motivo,
                    COALESCE(responsable, '') as responsable,
                    COALESCE(turno, '') as turno
             FROM registro_quiebras
             WHERE fecha BETWEEN ? AND ? 
               AND TIME(hora) BETWEEN ? AND ?
             ORDER BY fecha ASC, hora ASC",
            [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta],
            $tmpDir, 'quiebras', $header
        );
    }

    // ============================================
    // CREAR ARCHIVO README
    // ============================================
    $readmeFile = $tmpDir . '/LEEME.txt';
    $readmeContent = "=== REPORTE COMPLETO ===\n\n";
    $readmeContent .= "Fecha del reporte: " . date('Y-m-d H:i:s') . "\n";
    $readmeContent .= "Período seleccionado: $fecha_desde a $fecha_hasta\n";
    $readmeContent .= "Rango horario: " . tiempo24a12($hora_desde) . " a " . tiempo24a12($hora_hasta) . "\n";
    $readmeContent .= "Rango cruza medianoche: " . ($rango_cruzado ? 'Sí' : 'No') . "\n\n";
    $readmeContent .= "=== ARCHIVOS GENERADOS ===\n\n";
    $readmeContent .= "PRODUCCIÓN: " . number_format($resultProd['totalRows'] ?? 0, 0, '.', ',') . " registros en " . count($resultProd['files'] ?? []) . " archivo(s)\n";
    $readmeContent .= "REGISTROS ANTIGUOS: " . number_format($resultAntiguos['totalRows'] ?? 0, 0, '.', ',') . " registros en " . count($resultAntiguos['files'] ?? []) . " archivo(s)\n";
    $readmeContent .= "QUIEBRAS: " . number_format($resultQuiebras['totalRows'] ?? 0, 0, '.', ',') . " registros en " . count($resultQuiebras['files'] ?? []) . " archivo(s)\n\n";
    $readmeContent .= "=== NOTAS IMPORTANTES ===\n";
    $readmeContent .= "1. Los registros de 'registros_antiguos' tienen hora fija 00:00:00 por naturaleza de la tabla.\n";
    $readmeContent .= "2. Para que aparezcan en el reporte, el rango horario debe incluir las 00:00:00.\n";
    $readmeContent .= "3. Excel tiene un límite de 1,048,576 filas por hoja.\n";
    $readmeContent .= "4. Los archivos con más de 1,000,000 filas fueron automáticamente divididos en partes.\n";
    $readmeContent .= "5. Para abrir archivos CSV grandes, use: Google Sheets, Python/Pandas, Power BI o R.\n";
    $readmeContent .= "6. Para descargar TODOS los registros de 'registros_antiguos' (2,561,059), seleccione:\n";
    $readmeContent .= "   - Fecha Desde: 2025-01-01\n";
    $readmeContent .= "   - Fecha Hasta: 2026-03-03\n";
    $readmeContent .= "   - Hora Desde: 00:00 AM\n";
    $readmeContent .= "   - Hora Hasta: 11:59 PM\n";
    
    file_put_contents($readmeFile, $readmeContent);

    // ============================================
    // CREAR ZIP
    // ============================================
    $zipFile = $tmpDir . '/reporte_completo_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    
    $zip->addFile($readmeFile, 'LEEME.txt');
    
    foreach (($resultProd['files'] ?? []) as $file) {
        $zip->addFile($file, basename($file));
    }
    foreach (($resultAntiguos['files'] ?? []) as $file) {
        $zip->addFile($file, basename($file));
    }
    foreach (($resultQuiebras['files'] ?? []) as $file) {
        $zip->addFile($file, basename($file));
    }
    
    $zip->close();

    // ============================================
    // ENVIAR ZIP AL NAVEGADOR
    // ============================================
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
    header('Content-Length: ' . filesize($zipFile));
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    readfile($zipFile);

    // ============================================
    // LIMPIAR ARCHIVOS TEMPORALES
    // ============================================
    $files = glob($tmpDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($tmpDir);

    $conn->close();
    exit;
}
 
// Endpoint para previsualizar reporte
if (isset($_GET['previsualizar_reporte'])) {
    header('Content-Type: application/json; charset=utf-8');
    $fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-7 days'));
    $fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    $hora_desde = convertir12a24($_GET['hora_desde_time'] ?? '00:00', $_GET['hora_desde_ampm'] ?? 'AM');
    $hora_hasta = convertir12a24($_GET['hora_hasta_time'] ?? '23:59', $_GET['hora_hasta_ampm'] ?? 'PM');
    $tipo = $_GET['tipo'] ?? 'completo';
    
    switch ($tipo) {
        case 'area':
            $data = obtenerReportePorArea($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
            break;
        case 'equipo':
            $data = obtenerReportePorEquipo($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
            break;
        case 'empleado':
            $data = obtenerReportePorEmpleado($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
            break;
        case 'quiebras':
            $data = obtenerReporteQuiebrasPorEmpleado($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
            break;
        default:
            $data = obtenerReporteCompleto($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
    }
    
    echo json_encode(['success' => true, 'data' => $data, 'total' => count($data)]);
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: PREVISUALIZAR REPORTE (CON AMBAS TABLAS)
// ============================================
if (isset($_GET['previsualizar_reporte'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-7 days'));
        $fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
        $hora_desde_time = $_GET['hora_desde_time'] ?? '00:00';
        $hora_desde_ampm = $_GET['hora_desde_ampm'] ?? 'AM';
        $hora_hasta_time = $_GET['hora_hasta_time'] ?? '23:59';
        $hora_hasta_ampm = $_GET['hora_hasta_ampm'] ?? 'PM';
        $tipo = $_GET['tipo'] ?? 'completo';
        
        $hora_desde = convertir12a24($hora_desde_time, $hora_desde_ampm);
        $hora_hasta = convertir12a24($hora_hasta_time, $hora_hasta_ampm);
        
        if ($hora_hasta === '23:59:00') {
            $hora_hasta = '23:59:59';
        }
        
        $sql = "";
        $params = [];
        $types = "";
        
        switch ($tipo) {
            case 'completo':
                $sql = "(SELECT 'produccion' as fuente, id, fecha, TIME_FORMAT(fecha, '%H:%i') as hora, empleado, area, equipo, orden, '' as motivo, '' as responsable 
                         FROM produccion 
                         WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                         LIMIT 5000)
                         UNION ALL
                         (SELECT 'registros_antiguos' as fuente, id, fecha, TIME_FORMAT(fecha, '%H:%i') as hora, empleado, area, equipo, orden, '' as motivo, '' as responsable 
                         FROM registros_antiguos 
                         WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                         LIMIT 5000)
                         UNION ALL
                         (SELECT 'quiebra' as fuente, id, fecha, TIME_FORMAT(fecha, '%H:%i') as hora, empleado, area, equipo, orden, motivo, responsable 
                         FROM registro_quiebras 
                         WHERE fecha BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                         LIMIT 5000)
                         ORDER BY fecha DESC
                         LIMIT 1000";
                $params = [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta, 
                           $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta,
                           $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta];
                $types = "ssssssssssss";
                break;
                
            case 'area':
                $sql = "SELECT area, 
                               SUM(total_produccion) as total_produccion,
                               SUM(total_quiebras) as total_quiebras,
                               SUM(total_produccion) + SUM(total_quiebras) as total_registros
                        FROM (
                            SELECT area, COUNT(*) as total_produccion, 0 as total_quiebras
                            FROM (SELECT area FROM produccion WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                                  UNION ALL 
                                  SELECT area FROM registros_antiguos WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?) p
                            GROUP BY area
                            UNION ALL
                            SELECT area, 0 as total_produccion, COUNT(*) as total_quiebras
                            FROM registro_quiebras 
                            WHERE fecha BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                              AND area IS NOT NULL AND area != ''
                            GROUP BY area
                        ) combined
                        GROUP BY area
                        ORDER BY total_registros DESC";
                $params = [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta,
                           $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta,
                           $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta];
                $types = "ssssssssssss";
                break;
                
            case 'equipo':
                $sql = "SELECT equipo, COUNT(*) as total_quiebras,
                               COUNT(DISTINCT empleado) as empleados_afectados,
                               COUNT(DISTINCT orden) as ordenes_afectadas,
                               GROUP_CONCAT(DISTINCT motivo SEPARATOR ', ') as motivos
                        FROM registro_quiebras 
                        WHERE fecha BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                          AND equipo IS NOT NULL AND equipo != ''
                        GROUP BY equipo
                        ORDER BY total_quiebras DESC
                        LIMIT 1000";
                $params = [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta];
                $types = "ssss";
                break;
                
            case 'empleado':
                $sql = "SELECT empleado, 
                               SUM(total_produccion) as total_produccion,
                               SUM(total_quiebras) as total_quiebras
                        FROM (
                            SELECT empleado, COUNT(*) as total_produccion, 0 as total_quiebras
                            FROM (SELECT empleado FROM produccion WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ? AND empleado IS NOT NULL AND empleado != ''
                                  UNION ALL 
                                  SELECT empleado FROM registros_antiguos WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ? AND empleado IS NOT NULL AND empleado != '') p
                            GROUP BY empleado
                            UNION ALL
                            SELECT empleado, 0 as total_produccion, COUNT(*) as total_quiebras
                            FROM registro_quiebras 
                            WHERE fecha BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                              AND empleado IS NOT NULL AND empleado != ''
                            GROUP BY empleado
                        ) combined
                        GROUP BY empleado
                        ORDER BY total_produccion DESC
                        LIMIT 1000";
                $params = [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta,
                           $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta,
                           $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta];
                $types = "ssssssssssss";
                break;
                
            case 'quiebras':
                $sql = "SELECT empleado, COUNT(*) as total_quiebras,
                               COUNT(DISTINCT orden) as ordenes_afectadas,
                               GROUP_CONCAT(DISTINCT motivo SEPARATOR ', ') as motivos
                        FROM registro_quiebras 
                        WHERE fecha BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                          AND empleado IS NOT NULL AND empleado != ''
                        GROUP BY empleado
                        ORDER BY total_quiebras DESC
                        LIMIT 1000";
                $params = [$fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta];
                $types = "ssss";
                break;
                
            default:
                throw new Exception('Tipo de reporte no válido');
        }
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'total' => count($data)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE EMPLEADO COMPLETO (CON GRÁFICOS)
// ============================================
if (isset($_GET['detalles_empleado_completo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $empleado = $_GET['detalles_empleado_completo'];
    
    $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
    
    $fecha_inicio = preg_replace('/[^0-9-]/', '', $fecha_inicio);
    $fecha_fin = preg_replace('/[^0-9-]/', '', $fecha_fin);
    
    if (!strtotime($fecha_inicio)) $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
    if (!strtotime($fecha_fin)) $fecha_fin = date('Y-m-d');
    
    try {
        // ==================== PRODUCCIÓN TOTAL ====================
        $totalProd = 0;
        
        // Tabla produccion
        $sqlTotalProd = "SELECT COUNT(*) as total FROM produccion 
                         WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?";
        $stmt = $conn->prepare($sqlTotalProd);
        $stmt->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $totalProd = (int)($row['total'] ?? 0);
        $result->free();
        $stmt->close();
        
        // Tabla registros_antiguos
        $sqlTotalAnt = "SELECT COUNT(*) as total FROM registros_antiguos 
                        WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?";
        $stmt = $conn->prepare($sqlTotalAnt);
        $stmt->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $totalAnt = (int)($row['total'] ?? 0);
        $result->free();
        $stmt->close();
        
        $totalProd = $totalProd + $totalAnt;
        
        // ==================== QUIEBRAS TOTAL ====================
        $sqlQuiebras = "SELECT COUNT(*) as total FROM registro_quiebras 
                        WHERE empleado = ? AND fecha BETWEEN ? AND ?";
        $stmt = $conn->prepare($sqlQuiebras);
        $stmt->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $totalQuiebras = (int)($result->fetch_assoc()['total'] ?? 0);
        $result->free();
        $stmt->close();
        
        // ==================== DÍAS TRABAJADOS ====================
        $sqlDias = "SELECT COUNT(DISTINCT DATE(fecha)) as dias FROM (
                        SELECT fecha FROM produccion WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                        UNION
                        SELECT fecha FROM registros_antiguos WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                    ) AS todas_fechas";
        $stmt = $conn->prepare($sqlDias);
        $stmt->bind_param('ssssss', $empleado, $fecha_inicio, $fecha_fin, $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $diasTrabajados = (int)($result->fetch_assoc()['dias'] ?? 1);
        $result->free();
        $stmt->close();
        
        // ==================== PRODUCCIÓN POR HORA ====================
        $sqlHora = "SELECT hora, SUM(total) as total FROM (
                        SELECT HOUR(fecha) as hora, COUNT(*) as total FROM produccion
                        WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                        GROUP BY HOUR(fecha)
                        UNION ALL
                        SELECT HOUR(fecha) as hora, COUNT(*) as total FROM registros_antiguos
                        WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                        GROUP BY HOUR(fecha)
                    ) AS combined
                    GROUP BY hora
                    ORDER BY hora ASC";
        $stmt = $conn->prepare($sqlHora);
        $stmt->bind_param('ssssss', $empleado, $fecha_inicio, $fecha_fin, $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $produccion_por_hora = [];
        while ($row = $result->fetch_assoc()) {
            $produccion_por_hora[] = $row;
        }
        $result->free();
        $stmt->close();
        
        // Normalizar horas (0-23)
        $horasCompletas = [];
        for ($i = 0; $i < 24; $i++) {
            $horasCompletas[$i] = ['hora' => $i, 'total' => 0];
        }
        foreach ($produccion_por_hora as $h) {
            $horasCompletas[$h['hora']] = $h;
        }
        $produccion_por_hora = array_values($horasCompletas);
        
        // ==================== PRODUCCIÓN POR DÍA DE SEMANA ====================
        $sqlDia = "SELECT dia_semana, SUM(produccion) as produccion FROM (
                        SELECT DAYOFWEEK(fecha) as dia_semana, COUNT(*) as produccion FROM produccion
                        WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                        GROUP BY DAYOFWEEK(fecha)
                        UNION ALL
                        SELECT DAYOFWEEK(fecha) as dia_semana, COUNT(*) as produccion FROM registros_antiguos
                        WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                        GROUP BY DAYOFWEEK(fecha)
                    ) AS combined
                    GROUP BY dia_semana
                    ORDER BY FIELD(dia_semana, 2, 3, 4, 5, 6, 7, 1)";
        $stmt = $conn->prepare($sqlDia);
        $stmt->bind_param('ssssss', $empleado, $fecha_inicio, $fecha_fin, $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Mapear días al orden correcto: Lunes(2), Martes(3), Miércoles(4), Jueves(5), Viernes(6), Sábado(7), Domingo(1)
        $diasMap = [];
        while ($row = $result->fetch_assoc()) {
            $diasMap[(int)$row['dia_semana']] = (int)$row['produccion'];
        }
        $result->free();
        $stmt->close();
        
        // Crear array en el orden correcto con nombres de días
        $ordenDias = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo'
        ];
        
        $produccion_por_dia = [];
        foreach ($ordenDias as $numDia => $nombreDia) {
            $produccion_por_dia[] = [
                'dia_semana' => $numDia,
                'nombre_dia' => $nombreDia,
                'produccion' => $diasMap[$numDia] ?? 0
            ];
        }
        
        // ==================== TENDENCIA DIARIA ====================
        $sqlTendencia = "SELECT fecha, fecha_display, SUM(produccion) as produccion FROM (
                            SELECT DATE(fecha) as fecha, DATE_FORMAT(fecha, '%d/%m') as fecha_display, COUNT(*) as produccion
                            FROM produccion
                            WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                            GROUP BY DATE(fecha)
                            UNION ALL
                            SELECT DATE(fecha) as fecha, DATE_FORMAT(fecha, '%d/%m') as fecha_display, COUNT(*) as produccion
                            FROM registros_antiguos
                            WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                            GROUP BY DATE(fecha)
                        ) AS combined
                        GROUP BY fecha
                        ORDER BY fecha ASC";
        $stmt = $conn->prepare($sqlTendencia);
        $stmt->bind_param('ssssss', $empleado, $fecha_inicio, $fecha_fin, $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $tendencia = [];
        while ($row = $result->fetch_assoc()) {
            $tendencia[] = $row;
        }
        $result->free();
        $stmt->close();
        
        // Agregar quiebras a la tendencia
        $sqlQuiebrasTendencia = "SELECT DATE(fecha) as fecha, COUNT(*) as quiebras
                                 FROM registro_quiebras
                                 WHERE empleado = ? AND fecha BETWEEN ? AND ?
                                 GROUP BY DATE(fecha)";
        $stmt = $conn->prepare($sqlQuiebrasTendencia);
        $stmt->bind_param('sss', $empleado, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $quiebrasMap = [];
        while ($row = $result->fetch_assoc()) {
            $quiebrasMap[$row['fecha']] = $row['quiebras'];
        }
        $result->free();
        $stmt->close();
        
        foreach ($tendencia as &$t) {
            $t['quiebras'] = $quiebrasMap[$t['fecha']] ?? 0;
        }
        
        // ==================== REGISTROS RECIENTES ====================
        $sqlRegistros = "(SELECT fecha, TIME_FORMAT(fecha, '%H:%i') as hora, orden, area, 'produccion' as tipo, equipo as detalle
                         FROM produccion
                         WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                         ORDER BY fecha DESC LIMIT 1000)
                         UNION ALL
                         (SELECT fecha, TIME_FORMAT(fecha, '%H:%i') as hora, orden, area, 'produccion_antigua' as tipo, equipo as detalle
                         FROM registros_antiguos
                         WHERE empleado = ? AND DATE(fecha) BETWEEN ? AND ?
                         ORDER BY fecha DESC LIMIT 1000)
                         UNION ALL
                         (SELECT fecha, TIME_FORMAT(fecha, '%H:%i') as hora, orden, area, 'quiebra' as tipo, motivo as detalle
                         FROM registro_quiebras
                         WHERE empleado = ? AND fecha BETWEEN ? AND ?
                         ORDER BY fecha DESC LIMIT 1000)
                         ORDER BY fecha DESC
                         LIMIT 2000";
        $stmt = $conn->prepare($sqlRegistros);
        $stmt->bind_param('sssssssss', 
            $empleado, $fecha_inicio, $fecha_fin,
            $empleado, $fecha_inicio, $fecha_fin,
            $empleado, $fecha_inicio, $fecha_fin
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $registros = [];
        while ($row = $result->fetch_assoc()) {
            $registros[] = $row;
        }
        $result->free();
        $stmt->close();
        
        // ==================== CÁLCULOS FINALES ====================
        $totalBase = $totalProd + $totalQuiebras;
        $eficiencia = $totalBase > 0 ? round(($totalProd / $totalBase) * 100, 1) : 100;
        
        $horasLaborales = 8;
        $horasTotales = $diasTrabajados * $horasLaborales;
        $productividadHora = $horasTotales > 0 ? round($totalProd / $horasTotales, 1) : $totalProd;
        $ratioProdQuiebra = $totalQuiebras > 0 ? round($totalProd / $totalQuiebras, 1) : $totalProd;
        
        echo json_encode([
            'success' => true,
            'total_produccion' => $totalProd,
            'total_quiebras' => $totalQuiebras,
            'eficiencia' => $eficiencia,
            'productividad_hora' => $productividadHora,
            'ratio_prod_quiebra' => $ratioProdQuiebra,
            'produccion_por_hora' => $produccion_por_hora,
            'produccion_por_dia' => $produccion_por_dia,
            'tendencia' => $tendencia,
            'registros' => $registros,
            'fecha_inicio' => date('d/m/Y', strtotime($fecha_inicio)),
            'fecha_fin' => date('d/m/Y', strtotime($fecha_fin))
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("❌ Error en detalles_empleado_completo: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE ÁREA COMPLETO (INCLUYENDO registros_antiguos)
// ============================================
if (isset($_GET['detalles_area_completo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $area = $_GET['detalles_area_completo'];
    
    // Obtener fechas de $_GET
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
    
    // Validar fechas
    $fecha_inicio = preg_replace('/[^0-9-]/', '', $fecha_inicio);
    $fecha_fin = preg_replace('/[^0-9-]/', '', $fecha_fin);
    
    if (!strtotime($fecha_inicio)) $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
    if (!strtotime($fecha_fin)) $fecha_fin = date('Y-m-d');
    
    try {
        // ==================== PRODUCCIÓN POR HORA (UNIFICANDO ambas tablas) ====================
        $sqlHora = "SELECT hora, SUM(total_produccion) as total_produccion
                    FROM (
                        SELECT HOUR(fecha) as hora, COUNT(*) as total_produccion
                        FROM produccion
                        WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                        GROUP BY HOUR(fecha)
                        UNION ALL
                        SELECT HOUR(fecha) as hora, COUNT(*) as total_produccion
                        FROM registros_antiguos
                        WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                        GROUP BY HOUR(fecha)
                    ) AS combined
                    GROUP BY hora
                    ORDER BY hora ASC";
        
        $stmt = $conn->prepare($sqlHora);
        $stmt->bind_param('ssssss', $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $produccion_por_hora_raw = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Normalizar horas (0-23)
        $horasMap = [];
        foreach ($produccion_por_hora_raw as $h) {
            $horasMap[(int)$h['hora']] = (int)$h['total_produccion'];
        }
        
        $produccion_por_hora = [];
        for ($i = 0; $i < 24; $i++) {
            $produccion_por_hora[] = [
                'hora' => $i,
                'total_produccion' => $horasMap[$i] ?? 0
            ];
        }
        
        // ==================== TENDENCIA DIARIA (UNIFICANDO ambas tablas) ====================
        $sqlTendencia = "SELECT fecha, SUM(produccion) as produccion
                         FROM (
                             SELECT DATE(fecha) as fecha, COUNT(*) as produccion
                             FROM produccion
                             WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                             GROUP BY DATE(fecha)
                             UNION ALL
                             SELECT DATE(fecha) as fecha, COUNT(*) as produccion
                             FROM registros_antiguos
                             WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                             GROUP BY DATE(fecha)
                         ) AS combined
                         GROUP BY fecha
                         ORDER BY fecha ASC";
        
        $stmt = $conn->prepare($sqlTendencia);
        $stmt->bind_param('ssssss', $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $tendencia_raw = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Formatear tendencia
        $tendencia = [];
        foreach ($tendencia_raw as $t) {
            $fechaObj = new DateTime($t['fecha']);
            $tendencia[] = [
                'fecha' => $t['fecha'],
                'fecha_display' => $fechaObj->format('d/m'),
                'produccion' => (int)$t['produccion']
            ];
        }
        
        // ==================== EMPLEADOS DEL ÁREA (UNIFICANDO ambas tablas) ====================
        $sqlEmpleados = "SELECT DISTINCT empleado
                         FROM (
                             SELECT empleado FROM produccion
                             WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                               AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                             UNION
                             SELECT empleado FROM registros_antiguos
                             WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                               AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                         ) AS empleados_area";
        
        $stmt = $conn->prepare($sqlEmpleados);
        $stmt->bind_param('ssssss', $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $empleadosArea = [];
        while ($row = $result->fetch_assoc()) {
            $empleadosArea[] = $row['empleado'];
        }
        $stmt->close();
        
        // ==================== PRODUCCIÓN POR EMPLEADO (UNIFICANDO ambas tablas) ====================
        $sqlProdEmp = "SELECT empleado, SUM(produccion) as produccion
                       FROM (
                           SELECT empleado, COUNT(*) as produccion
                           FROM produccion
                           WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                             AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                           GROUP BY empleado
                           UNION ALL
                           SELECT empleado, COUNT(*) as produccion
                           FROM registros_antiguos
                           WHERE area = ? AND DATE(fecha) BETWEEN ? AND ?
                             AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                           GROUP BY empleado
                       ) AS combined
                       GROUP BY empleado";
        
        $stmt = $conn->prepare($sqlProdEmp);
        $stmt->bind_param('ssssss', $area, $fecha_inicio, $fecha_fin, $area, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        $produccionMap = [];
        while ($row = $result->fetch_assoc()) {
            $produccionMap[$row['empleado']] = (int)$row['produccion'];
        }
        $stmt->close();
        
        // ==================== QUIEBRAS POR EMPLEADO ====================
        $quiebrasMap = [];
        if (!empty($empleadosArea)) {
            $placeholders = implode(',', array_fill(0, count($empleadosArea), '?'));
            $sqlQuieEmp = "SELECT empleado, COUNT(*) as quiebras
                           FROM registro_quiebras
                           WHERE fecha BETWEEN ? AND ?
                             AND empleado IN ($placeholders)
                           GROUP BY empleado";
            
            $stmt = $conn->prepare($sqlQuieEmp);
            $types = 'ss' . str_repeat('s', count($empleadosArea));
            $params = array_merge([$fecha_inicio, $fecha_fin], $empleadosArea);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $quiebrasMap[$row['empleado']] = (int)$row['quiebras'];
            }
            $stmt->close();
        }
        
        // ==================== REGISTROS DE QUIEBRAS ====================
        $registrosQuiebras = [];
        if (!empty($empleadosArea)) {
            $placeholders = implode(',', array_fill(0, count($empleadosArea), '?'));
            $sqlRegistros = "SELECT q.id, q.fecha, 
                                    TIME_FORMAT(q.hora, '%H:%i') as hora,
                                    q.empleado, q.orden, q.motivo, q.equipo, q.responsable
                             FROM registro_quiebras q
                             WHERE q.fecha BETWEEN ? AND ?
                               AND q.empleado IN ($placeholders)
                             ORDER BY q.fecha DESC, q.hora DESC
                             LIMIT 500";
            
            $stmt = $conn->prepare($sqlRegistros);
            $types = 'ss' . str_repeat('s', count($empleadosArea));
            $params = array_merge([$fecha_inicio, $fecha_fin], $empleadosArea);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $registrosQuiebras = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        
        // Limpiar horas vacías
        foreach ($registrosQuiebras as &$r) {
            if (empty($r['hora']) || $r['hora'] === '00:00' || $r['hora'] === '00:00:00') {
                $r['hora'] = '--:--';
            }
        }
        
        // ==================== COMBINAR DATOS DE EMPLEADOS ====================
        $topEmpleados = [];
        foreach ($empleadosArea as $empleado) {
            $prod = $produccionMap[$empleado] ?? 0;
            $quie = $quiebrasMap[$empleado] ?? 0;
            $total = $prod + $quie;
            $eficiencia = $total > 0 ? round(($prod / $total) * 100, 1) : 100;
            
            $topEmpleados[] = [
                'empleado' => $empleado,
                'produccion' => $prod,
                'quiebras' => $quie,
                'eficiencia' => $eficiencia
            ];
        }
        
        // Ordenar por producción DESC
        usort($topEmpleados, function($a, $b) {
            return $b['produccion'] - $a['produccion'];
        });
        
        // ==================== TOTALES DEL ÁREA ====================
        $totalProd = array_sum($produccionMap);
        $totalQuiebras = array_sum($quiebrasMap);
        $empleadosActivos = count($empleadosArea);
        
        // ==================== EFICIENCIA GLOBAL ====================
        $totalBase = $totalProd + $totalQuiebras;
        $eficiencia = $totalBase > 0 ? round(($totalProd / $totalBase) * 100, 1) : 100;
        
        // ==================== RESPUESTA ====================
        echo json_encode([
            'success' => true,
            'total_produccion' => $totalProd,
            'total_quiebras' => $totalQuiebras,
            'eficiencia' => $eficiencia,
            'empleados_activos' => $empleadosActivos,
            'produccion_por_hora' => $produccion_por_hora,
            'tendencia' => $tendencia,
            'top_empleados' => $topEmpleados,
            'registros_quiebras' => $registrosQuiebras,
            'fecha_inicio' => date('d/m/Y', strtotime($fecha_inicio)),
            'fecha_fin' => date('d/m/Y', strtotime($fecha_fin))
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("❌ Error en detalles_area_completo: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage()
        ]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE EQUIPO COMPLETO (CON GRÁFICOS)
// ============================================
if (isset($_GET['detalles_equipo_completo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $equipo = $_GET['detalles_equipo_completo'];
    $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
    
    try {
        // Quiebras por hora - USAR CAMPO 'hora'
        $sqlHora = "SELECT HOUR(hora) as hora, COUNT(*) as total
                    FROM registro_quiebras
                    WHERE equipo = ? AND fecha BETWEEN ? AND ?
                    GROUP BY HOUR(hora)
                    ORDER BY hora ASC";
        $stmt = $conn->prepare($sqlHora);
        $stmt->bind_param('sss', $equipo, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $quiebras_por_hora = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Quiebras por día
        $sqlDia = "SELECT DAYOFWEEK(fecha) as dia_semana, COUNT(*) as total
                   FROM registro_quiebras
                   WHERE equipo = ? AND fecha BETWEEN ? AND ?
                   GROUP BY DAYOFWEEK(fecha)
                   ORDER BY FIELD(DAYOFWEEK(fecha), 2, 3, 4, 5, 6, 7, 1)";
        $stmt = $conn->prepare($sqlDia);
        $stmt->bind_param('sss', $equipo, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Mapear días al orden correcto
        $diasMap = [];
        while ($row = $result->fetch_assoc()) {
            $diasMap[(int)$row['dia_semana']] = (int)$row['total'];
        }
        $result->free();
        $stmt->close();
        
        $ordenDias = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo'
        ];
        
        $quiebras_por_dia = [];
        foreach ($ordenDias as $numDia => $nombreDia) {
            $quiebras_por_dia[] = [
                'dia_semana' => $numDia,
                'nombre_dia' => $nombreDia,
                'total' => $diasMap[$numDia] ?? 0
            ];
        }
        
        // Registros - CORREGIDO: usar TIME_FORMAT(hora, '%H:%i')
        $sqlRegistros = "SELECT fecha, 
                                TIME_FORMAT(hora, '%H:%i') as hora,
                                empleado, 
                                orden, 
                                area, 
                                motivo
                         FROM registro_quiebras
                         WHERE equipo = ? AND fecha BETWEEN ? AND ?
                         ORDER BY fecha DESC, hora DESC
                         LIMIT 10000";
        $stmt = $conn->prepare($sqlRegistros);
        $stmt->bind_param('sss', $equipo, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Limpiar horas vacías
        foreach ($registros as &$r) {
            if (empty($r['hora']) || $r['hora'] === '00:00' || $r['hora'] === '00:00:00') {
                $r['hora'] = '--:--';
            }
        }
        
        // Estadísticas
        $sqlStats = "SELECT COUNT(*) as total_quiebras,
                            COUNT(DISTINCT orden) as ordenes_afectadas,
                            COUNT(DISTINCT empleado) as empleados_afectados,
                            COUNT(DISTINCT motivo) as motivos_diferentes
                     FROM registro_quiebras
                     WHERE equipo = ? AND fecha BETWEEN ? AND ?";
        $stmt = $conn->prepare($sqlStats);
        $stmt->bind_param('sss', $equipo, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'total_quiebras' => $stats['total_quiebras'] ?? 0,
            'ordenes_afectadas' => $stats['ordenes_afectadas'] ?? 0,
            'empleados_afectados' => $stats['empleados_afectados'] ?? 0,
            'motivos_diferentes' => $stats['motivos_diferentes'] ?? 0,
            'quiebras_por_hora' => $quiebras_por_hora,
            'quiebras_por_dia' => $quiebras_por_dia,
            'registros' => $registros,
            'fecha_inicio' => $fecha_inicio,
            'fecha_fin' => $fecha_fin
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// ENDPOINT: DETALLES DE ORDEN COMPLETO (SIN PRODUCCIÓN POR EMPLEADO)
// ============================================
if (isset($_GET['detalles_orden_completo'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $orden = $_GET['detalles_orden_completo'];
    
    // Obtener fechas directamente de $_GET
    $fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
    
    // Validar fechas
    $fecha_inicio = preg_replace('/[^0-9-]/', '', $fecha_inicio);
    $fecha_fin = preg_replace('/[^0-9-]/', '', $fecha_fin);
    
    if (!strtotime($fecha_inicio)) $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
    if (!strtotime($fecha_fin)) $fecha_fin = date('Y-m-d');
    
    try {
        // ==================== TOTAL PRODUCCIÓN ====================
        $sqlTotalProd = "SELECT COUNT(*) as total FROM (
                             SELECT id FROM produccion WHERE orden = ? AND DATE(fecha) BETWEEN ? AND ?
                             UNION ALL
                             SELECT id FROM registros_antiguos WHERE orden = ? AND DATE(fecha) BETWEEN ? AND ?
                         ) AS total_prod";
        $stmt = $conn->prepare($sqlTotalProd);
        $stmt->bind_param('ssssss', $orden, $fecha_inicio, $fecha_fin, $orden, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $totalProduccion = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        
        // ==================== TOTAL QUIEBRAS ====================
        $sqlQuiebras = "SELECT COUNT(*) as total FROM registro_quiebras 
                        WHERE orden = ? AND fecha BETWEEN ? AND ?";
        $stmt = $conn->prepare($sqlQuiebras);
        $stmt->bind_param('sss', $orden, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $totalQuiebras = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        
        // ==================== QUIEBRAS POR HORA (para gráfico) ====================
        $sqlHora = "SELECT HOUR(hora) as hora, COUNT(*) as total
                    FROM registro_quiebras
                    WHERE orden = ? AND fecha BETWEEN ? AND ?
                    GROUP BY HOUR(hora)
                    ORDER BY hora ASC";
        $stmt = $conn->prepare($sqlHora);
        $stmt->bind_param('sss', $orden, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $quiebras_por_hora = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // ==================== EMPLEADOS CON QUIEBRAS SOLAMENTE ====================
        $sqlQuieEmpleados = "SELECT 
                                CASE 
                                    WHEN empleado IS NULL OR empleado = '' OR empleado = 'N/A' 
                                    THEN 'No registrado' 
                                    ELSE empleado 
                                END as empleado,
                                COUNT(*) as quiebras
                             FROM registro_quiebras
                             WHERE orden = ? AND fecha BETWEEN ? AND ?
                             GROUP BY empleado
                             ORDER BY quiebras DESC";
        
        $stmt = $conn->prepare($sqlQuieEmpleados);
        $stmt->bind_param('sss', $orden, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $empleados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // ==================== REGISTROS DE QUIEBRAS ====================
        $sqlRegistros = "SELECT fecha, 
                                TIME_FORMAT(hora, '%H:%i') as hora,
                                empleado, 
                                area, 
                                equipo, 
                                motivo,
                                responsable,
                                turno
                         FROM registro_quiebras
                         WHERE orden = ? AND fecha BETWEEN ? AND ?
                         ORDER BY fecha DESC, hora DESC
                         LIMIT 10000";
        $stmt = $conn->prepare($sqlRegistros);
        $stmt->bind_param('sss', $orden, $fecha_inicio, $fecha_fin);
        $stmt->execute();
        $quiebras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Limpiar datos
        foreach ($quiebras as &$q) {
            if (empty($q['hora']) || $q['hora'] === '00:00' || $q['hora'] === '00:00:00') {
                $q['hora'] = '--:--';
            }
            if (empty($q['equipo']) || $q['equipo'] === '') {
                $q['equipo'] = 'No registrado';
            }
            if (empty($q['empleado']) || $q['empleado'] === '' || $q['empleado'] === 'N/A') {
                $q['empleado'] = 'No registrado';
            }
            if (empty($q['area']) || $q['area'] === '') {
                $q['area'] = 'No registrada';
            }
        }
        
        // ==================== MOTIVOS DIFERENTES ====================
        $motivos = array_unique(array_filter(array_column($quiebras, 'motivo'), function($m) {
            return !empty($m) && $m !== 'N/A';
        }));
        
        // ==================== RESPUESTA ====================
        echo json_encode([
            'success' => true,
            'total_quiebras' => $totalQuiebras,
            'total_produccion' => $totalProduccion,
            'empleados_involucrados' => count($empleados),
            'motivos_diferentes' => count($motivos),
            'quiebras_por_hora' => $quiebras_por_hora,
            'quiebras' => $quiebras,
            'empleados' => $empleados,
            'fecha_inicio' => date('d/m/Y', strtotime($fecha_inicio)),
            'fecha_fin' => date('d/m/Y', strtotime($fecha_fin))
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en detalles_orden_completo: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ============================================
// FUNCIONES PARA REPORTES AVANZADOS
// ============================================
 
function obtenerReporteCompleto($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta): array
{
    $rango_cruzado = rangoCruzaMedianoche($hora_desde, $hora_hasta);
    $resultados = [];
    
    // Producción
    if ($rango_cruzado) {
        $sql = "SELECT fecha, TIME(fecha) as hora, 'Producción' as tipo, empleado, area, equipo, orden, '' as detalle, '' as responsable, '' as turno
                FROM produccion WHERE ((DATE(fecha) = ? AND TIME(fecha) >= ?) OR (DATE(fecha) = ? AND TIME(fecha) <= ?) OR (DATE(fecha) > ? AND DATE(fecha) < ?))
                UNION ALL
                SELECT fecha, TIME(fecha) as hora, 'Antiguo' as tipo, empleado, area, equipo, orden, '' as detalle, '' as responsable, '' as turno
                FROM registros_antiguos WHERE ((DATE(fecha) = ? AND TIME(fecha) >= ?) OR (DATE(fecha) = ? AND TIME(fecha) <= ?) OR (DATE(fecha) > ? AND DATE(fecha) < ?))";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssssssssss', 
            $fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta,
            $fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta);
    } else {
        $sql = "SELECT fecha, TIME(fecha) as hora, 'Producción' as tipo, empleado, area, equipo, orden, '' as detalle, '' as responsable, '' as turno
                FROM produccion WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?
                UNION ALL
                SELECT fecha, TIME(fecha) as hora, 'Antiguo' as tipo, empleado, area, equipo, orden, '' as detalle, '' as responsable, '' as turno
                FROM registros_antiguos WHERE DATE(fecha) BETWEEN ? AND ? AND TIME(fecha) BETWEEN ? AND ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssssss', $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
    }
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($prod as $p) {
        $resultados[] = $p;
    }
    
    // Quiebras
    if ($rango_cruzado) {
        $sql = "SELECT fecha, TIME(hora) as hora, 'Quiebra' as tipo, empleado, area, equipo, orden, motivo as detalle, responsable, turno
                FROM registro_quiebras WHERE ((fecha = ? AND TIME(hora) >= ?) OR (fecha = ? AND TIME(hora) <= ?) OR (fecha > ? AND fecha < ?))";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssss', $fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta);
    } else {
        $sql = "SELECT fecha, TIME(hora) as hora, 'Quiebra' as tipo, empleado, area, equipo, orden, motivo as detalle, responsable, turno
                FROM registro_quiebras WHERE fecha BETWEEN ? AND ? AND TIME(hora) BETWEEN ? AND ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
    }
    $stmt->execute();
    $quiebras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($quiebras as $q) {
        $resultados[] = $q;
    }
    
    usort($resultados, fn($a, $b) => strtotime($a['fecha'] . ' ' . $a['hora']) - strtotime($b['fecha'] . ' ' . $b['hora']));
    
    return $resultados;
}
 
function obtenerReportePorArea($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta): array
{
    $filtros = [
        'fecha_inicio' => $fecha_desde,
        'fecha_fin' => $fecha_hasta,
        'hora_inicio' => $hora_desde,
        'hora_fin' => $hora_hasta
    ];
    
    $areas = obtenerAreasProduccion($conn, $filtros);
    
    foreach ($areas as &$area) {
        $total = $area['total_produccion'] + $area['total_quiebras'];
        $area['eficiencia'] = $total > 0 ? round(100 - ($area['total_quiebras'] / $total) * 100, 1) : 100;
        
        // Obtener primera y última producción del área
        $sql = "SELECT MIN(fecha) as primera, MAX(fecha) as ultima 
                FROM (SELECT fecha FROM produccion WHERE area = ? 
                      UNION ALL SELECT fecha FROM registros_antiguos WHERE area = ?) t";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $area['area'], $area['area']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $area['primera_produccion'] = $row['primera'] ? date('d/m/Y H:i', strtotime($row['primera'])) : 'N/A';
        $area['ultima_produccion'] = $row['ultima'] ? date('d/m/Y H:i', strtotime($row['ultima'])) : 'N/A';
        $stmt->close();
    }
    
    return $areas;
}
 
function obtenerReportePorEquipo($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta): array
{
    $filtros = [
        'fecha_inicio' => $fecha_desde,
        'fecha_fin' => $fecha_hasta,
        'hora_inicio' => $hora_desde,
        'hora_fin' => $hora_hasta
    ];
    
    return obtenerTopEquiposQuiebras($conn, $filtros, 500);
}
 
function obtenerReportePorEmpleado($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta): array
{
    $filtros = [
        'fecha_inicio' => $fecha_desde,
        'fecha_fin' => $fecha_hasta,
        'hora_inicio' => $hora_desde,
        'hora_fin' => $hora_hasta
    ];
    
    $empleados = obtenerTopEmpleadosProduccion($conn, $filtros);
    
    foreach ($empleados as &$emp) {
        $emp['primera_actividad'] = $emp['primera_produccion'] ? date('d/m/Y H:i', strtotime($emp['primera_produccion'])) : 'N/A';
        $emp['ultima_actividad'] = $emp['ultima_produccion'] ? date('d/m/Y H:i', strtotime($emp['ultima_produccion'])) : 'N/A';
        unset($emp['primera_produccion'], $emp['ultima_produccion']);
    }
    
    return $empleados;
}
 
function obtenerReporteQuiebrasPorEmpleado($conn, $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta): array
{
    $filtros = [
        'fecha_inicio' => $fecha_desde,
        'fecha_fin' => $fecha_hasta,
        'hora_inicio' => $hora_desde,
        'hora_fin' => $hora_hasta
    ];
    
    $rango_cruzado = rangoCruzaMedianoche($hora_desde, $hora_hasta);
    
    if ($rango_cruzado) {
        $sql = "SELECT empleado, COUNT(*) as total_quiebras, COUNT(DISTINCT orden) as ordenes_afectadas,
                       GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', ') as motivos_frecuentes,
                       GROUP_CONCAT(DISTINCT equipo ORDER BY equipo SEPARATOR ', ') as equipos_relacionados,
                       GROUP_CONCAT(DISTINCT area ORDER BY area SEPARATOR ', ') as areas_involucradas,
                       MIN(fecha) as primera_quiebra, MAX(fecha) as ultima_quiebra
                FROM registro_quiebras WHERE ((fecha = ? AND TIME(hora) >= ?) OR (fecha = ? AND TIME(hora) <= ?) OR (fecha > ? AND fecha < ?))
                AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                GROUP BY empleado ORDER BY total_quiebras DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssss', $fecha_desde, $hora_desde, $fecha_hasta, $hora_hasta, $fecha_desde, $fecha_hasta);
    } else {
        $sql = "SELECT empleado, COUNT(*) as total_quiebras, COUNT(DISTINCT orden) as ordenes_afectadas,
                       GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', ') as motivos_frecuentes,
                       GROUP_CONCAT(DISTINCT equipo ORDER BY equipo SEPARATOR ', ') as equipos_relacionados,
                       GROUP_CONCAT(DISTINCT area ORDER BY area SEPARATOR ', ') as areas_involucradas,
                       MIN(fecha) as primera_quiebra, MAX(fecha) as ultima_quiebra
                FROM registro_quiebras WHERE fecha BETWEEN ? AND ? AND TIME(hora) BETWEEN ? AND ?
                AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
                GROUP BY empleado ORDER BY total_quiebras DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $fecha_desde, $fecha_hasta, $hora_desde, $hora_hasta);
    }
    $stmt->execute();
    $resultados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($resultados as &$r) {
        $r['primera_quiebra'] = $r['primera_quiebra'] ? date('d/m/Y', strtotime($r['primera_quiebra'])) : 'N/A';
        $r['ultima_quiebra'] = $r['ultima_quiebra'] ? date('d/m/Y', strtotime($r['ultima_quiebra'])) : 'N/A';
    }
    
    return $resultados;
}

// ============================================
// PROCESAMIENTO DE FILTROS
// ============================================

$defaultFilters = [
    'fecha_inicio' => date('Y-m-d'),
    'fecha_fin' => date('Y-m-d'),
    'hora_inicio_time' => '00:00',
    'hora_inicio_ampm' => 'AM',
    'hora_fin_time' => '23:59',
    'hora_fin_ampm' => 'PM',
];

$filtros = [];
$mostrar_filtros = [];

foreach (array_keys($defaultFilters) as $campo) {
    $valor = $_GET[$campo] ?? $_SESSION['filtros'][$campo] ?? $defaultFilters[$campo] ?? '';
    $valor = sanitizar_input($valor);
    $mostrar_filtros[$campo] = $valor;
    $filtros[$campo] = $valor;
    $_SESSION['filtros'][$campo] = $valor;
}

if ($filtros['fecha_inicio'] > $filtros['fecha_fin']) {
    [$filtros['fecha_inicio'], $filtros['fecha_fin']] = [$filtros['fecha_fin'], $filtros['fecha_inicio']];
    [$mostrar_filtros['fecha_inicio'], $mostrar_filtros['fecha_fin']] = [$mostrar_filtros['fecha_fin'], $mostrar_filtros['fecha_inicio']];
}

$filtros['hora_inicio'] = convertir12a24($filtros['hora_inicio_time'], $filtros['hora_inicio_ampm']);
$filtros['hora_fin'] = convertir12a24($filtros['hora_fin_time'], $filtros['hora_fin_ampm']);

if ($filtros['hora_fin'] === '23:59:00') {
    $filtros['hora_fin'] = '23:59:59';
}

if (!rangoCruzaMedianoche($filtros['hora_inicio'], $filtros['hora_fin'])) {
    if (convertirHoraAMinutos($filtros['hora_inicio']) >= convertirHoraAMinutos($filtros['hora_fin'])) {
        $filtros['hora_fin'] = '23:59:59';
    }
}

$_SESSION['filtros']['hora_inicio'] = $filtros['hora_inicio'];
$_SESSION['filtros']['hora_fin'] = $filtros['hora_fin'];

// ============================================
// EJECUCIÓN PRINCIPAL
// ============================================

$total_produccion = 0;
$total_quiebras = 0;
$ordenes_cc_sin_empaque = 0;
$eficiencia_global = 0;
$top_ordenes = [];
$top_empleados_quiebras = [];
$top_empleados_produccion = [];
$top_equipos = [];
$top_responsables = [];
$timeline_data = [];
$top_motivos = [];
$quiebras_turno = [];
$areas_lista = [];
$promedio_quiebras = [];
$mostrar_mes_info = false;
$mostrar_ordenes_periodo = true;
$nombre_mes = '';
$rango_mes = '';
$total_ordenes_mes_completo = 0;

try {
    if (empty($filtros['fecha_inicio']) || empty($filtros['fecha_fin'])) {
        $filtros['fecha_inicio'] = date('Y-m-d', strtotime('-30 days'));
        $filtros['fecha_fin'] = date('Y-m-d');
    }
    
    $fecha_inicio = $filtros['fecha_inicio'];
    $fecha_fin = $filtros['fecha_fin'];
    
    $total_produccion = obtenerTotalProduccion($conn, $filtros);
    $total_quiebras = obtenerTotalQuiebras($conn, $filtros);
    $ordenes_cc_sin_empaque = obtenerOrdenesCCValidas($conn, $filtros);
    $timeline_data = obtenerTimelineQuiebras($conn, $filtros);
    $top_motivos = obtenerTopMotivos($conn, $filtros, 15);
    $quiebras_turno = obtenerQuiebrasPorTurno($conn, $filtros);
    $areas_lista = obtenerAreasProduccion($conn, $filtros);
    
    // EFICIENCIA GLOBAL
    $total_ok = $total_produccion + $ordenes_cc_sin_empaque;
    $total_base = $total_ok + $total_quiebras;
    $eficiencia_global = $total_base > 0 ? round(($total_ok / $total_base) * 100, 2) : 100;
    
    $promedio_quiebras = obtenerPromedioQuiebras($conn, $filtros);
    
    $top_ordenes = obtenerTopOrdenes($conn, $filtros, 30);
    $top_empleados_quiebras = obtenerTopEmpleadosQuiebras($conn, $filtros, 60);
    $top_empleados_produccion = obtenerTopEmpleadosProduccion($conn, $filtros);
    $top_equipos = obtenerTopEquiposQuiebras($conn, $filtros, 50);
    $top_responsables = obtenerTopResponsables($conn, $filtros, 50);
    
    // Información del mes
    $fecha_inicio_obj = new DateTime($filtros['fecha_inicio']);
    $fecha_fin_obj = new DateTime($filtros['fecha_fin']);
    $diff_meses = ($fecha_fin_obj->format('Y') - $fecha_inicio_obj->format('Y')) * 12 
                + ($fecha_fin_obj->format('m') - $fecha_inicio_obj->format('m'));
    
    if ($diff_meses == 0) {
        $mostrar_mes_info = true;
        $mostrar_ordenes_periodo = true;
        $total_ordenes_mes_completo = $total_produccion;
        
        $meses = ['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
                  '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
                  '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'];
        $mes_num = $fecha_inicio_obj->format('m');
        $nombre_mes = $meses[$mes_num] ?? $mes_num;
        $anio = $fecha_inicio_obj->format('Y');
        $fecha_inicio_mes = "$anio-$mes_num-01";
        $fecha_fin_mes = date('Y-m-t', strtotime($fecha_inicio_mes));
        $rango_mes = date('d/m/Y', strtotime($fecha_inicio_mes)) . ' – ' . date('d/m/Y', strtotime($fecha_fin_mes));
    } else {
        $mostrar_mes_info = false;
        $mostrar_ordenes_periodo = true;
        $total_ordenes_mes_completo = $total_produccion;
    }
    
} catch (Exception $e) {
    error_log("❌ ERROR en dashboard: " . $e->getMessage());
}

$JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$promedio_quiebras_json = json_encode($promedio_quiebras, JSON_UNESCAPED_UNICODE);
$top_empleados_produccion_json = json_encode($top_empleados_produccion ?: [], $JSON_FLAGS);
$timeline_json = json_encode($timeline_data ?: [], $JSON_FLAGS);
$top_motivos_json = json_encode($top_motivos ?: [], $JSON_FLAGS);
$quiebras_turno_json = json_encode($quiebras_turno ?: [], $JSON_FLAGS);
$areas_lista_json = json_encode($areas_lista ?: [], $JSON_FLAGS);
$top_equipos_json = json_encode($top_equipos ?: [], $JSON_FLAGS);
$top_responsables_json = json_encode($top_responsables ?: [], $JSON_FLAGS);

$conn->close();
?>
