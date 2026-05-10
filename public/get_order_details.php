<?php
/**
 * API Endpoint: Order Details
 * Obtiene detalles completos y analytics de una orden específica
 * Versión optimizada con métricas adicionales
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Validación de sesión optimizada
if (!isset($_SESSION['empleado'], $_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Acceso no autorizado',
        'code' => 'AUTH_ERROR'
    ]);
    exit();
}

require_once dirname(__DIR__) . '/config/database.php';

// Validación y sanitización del parámetro orden
if (!isset($_GET['orden']) || empty(trim($_GET['orden']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Parámetro orden requerido',
        'code' => 'MISSING_PARAM'
    ]);
    exit();
}

$orden = $conn->real_escape_string(trim($_GET['orden']));

// Obtener filtros de la sesión
$filtros = isset($_SESSION['filtros']) ? $_SESSION['filtros'] : [
    'fecha_inicio' => date('Y-m-01'),
    'fecha_fin' => date('Y-m-d'),
    'hora_inicio' => '00:00:00',
    'hora_fin' => '23:59:59'
];

try {
    // Consulta optimizada con datos adicionales y analytics
    $query = "SELECT 
        rq.*,
        DATE_FORMAT(rq.fecha, '%d/%m/%Y') as fecha_formateada,
        TIME_FORMAT(rq.hora, '%H:%i') as hora_formateada,
        TIMESTAMPDIFF(HOUR, 
            CONCAT(rq.fecha, ' ', rq.hora),
            NOW()
        ) as horas_desde_quiebra
    FROM registro_quiebras rq
    WHERE rq.orden = ?
    AND rq.fecha BETWEEN ? AND ? 
    AND rq.hora BETWEEN ? AND ?
    ORDER BY rq.fecha DESC, rq.hora DESC";
    
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Error al preparar consulta: " . $conn->error);
    }
    
    $stmt->bind_param('sssss', 
        $orden,
        $filtros['fecha_inicio'],
        $filtros['fecha_fin'],
        $filtros['hora_inicio'],
        $filtros['hora_fin']
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Error al ejecutar consulta: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $detalles = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Si no hay datos, devolver respuesta estructurada
    if (empty($detalles)) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'summary' => [
                'total' => 0,
                'orden' => $orden,
                'message' => 'No se encontraron quiebras para esta orden en el período seleccionado'
            ]
        ]);
        exit();
    }
    
    // Calcular métricas de resumen
    $empleados = array_unique(array_column($detalles, 'empleado'));
    $motivos = array_unique(array_column($detalles, 'motivo'));
    $areas = array_unique(array_column($detalles, 'area'));
    $turnos = array_unique(array_column($detalles, 'turno'));
    $equipos = array_filter(array_unique(array_column($detalles, 'equipo')));
    $responsables = array_filter(array_unique(array_column($detalles, 'responsable')));
    
    // Calcular distribución por motivo
    $distribucion_motivos = [];
    foreach ($detalles as $detalle) {
        $motivo = $detalle['motivo'];
        if (!isset($distribucion_motivos[$motivo])) {
            $distribucion_motivos[$motivo] = 0;
        }
        $distribucion_motivos[$motivo]++;
    }
    arsort($distribucion_motivos);
    
    // Calcular distribución por turno
    $distribucion_turnos = [];
    foreach ($detalles as $detalle) {
        $turno = $detalle['turno'];
        if (!isset($distribucion_turnos[$turno])) {
            $distribucion_turnos[$turno] = 0;
        }
        $distribucion_turnos[$turno]++;
    }
    
    // Obtener primera y última quiebra
    $primera_quiebra = end($detalles);
    $ultima_quiebra = reset($detalles);
    
    // Calcular tiempo promedio entre quiebras
    $tiempo_promedio = null;
    if (count($detalles) > 1) {
        $timestamps = array_map(function($d) {
            return strtotime($d['fecha'] . ' ' . $d['hora']);
        }, $detalles);
        
        sort($timestamps);
        $diferencias = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $diferencias[] = $timestamps[$i] - $timestamps[$i-1];
        }
        
        if (!empty($diferencias)) {
            $promedio_segundos = array_sum($diferencias) / count($diferencias);
            $tiempo_promedio = [
                'horas' => floor($promedio_segundos / 3600),
                'minutos' => floor(($promedio_segundos % 3600) / 60)
            ];
        }
    }
    
    // Calcular tasa de quiebras por día
    $dias_activos = [];
    foreach ($detalles as $detalle) {
        $dia = $detalle['fecha'];
        if (!isset($dias_activos[$dia])) {
            $dias_activos[$dia] = 0;
        }
        $dias_activos[$dia]++;
    }
    
    $fecha_inicio = new DateTime($primera_quiebra['fecha']);
    $fecha_fin = new DateTime($ultima_quiebra['fecha']);
    $dias_transcurridos = max(1, $fecha_inicio->diff($fecha_fin)->days + 1);
    
    // Identificar el motivo más frecuente
    $motivo_principal = array_key_first($distribucion_motivos);
    
    // Construir respuesta optimizada
    $response = [
        'success' => true,
        'data' => $detalles,
        'summary' => [
            'orden' => $orden,
            'total_quiebras' => count($detalles),
            'empleados_involucrados' => count($empleados),
            'empleados' => array_values($empleados),
            'motivos_distintos' => count($motivos),
            'motivos' => array_values($motivos),
            'areas_afectadas' => count($areas),
            'areas' => array_values($areas),
            'turnos_afectados' => count($turnos),
            'turnos' => array_values($turnos),
            'equipos_involucrados' => count($equipos),
            'equipos' => array_values($equipos),
            'responsables_asignados' => count($responsables),
            'responsables' => array_values($responsables),
            'motivo_principal' => $motivo_principal,
            'frecuencia_motivo_principal' => $distribucion_motivos[$motivo_principal] ?? 0
        ],
        'analytics' => [
            'primera_quiebra' => [
                'fecha' => $primera_quiebra['fecha_formateada'],
                'hora' => $primera_quiebra['hora_formateada'],
                'empleado' => $primera_quiebra['empleado'],
                'motivo' => $primera_quiebra['motivo']
            ],
            'ultima_quiebra' => [
                'fecha' => $ultima_quiebra['fecha_formateada'],
                'hora' => $ultima_quiebra['hora_formateada'],
                'empleado' => $ultima_quiebra['empleado'],
                'motivo' => $ultima_quiebra['motivo']
            ],
            'distribucion_motivos' => $distribucion_motivos,
            'distribucion_turnos' => $distribucion_turnos,
            'tiempo_promedio_entre_quiebras' => $tiempo_promedio,
            'dias_con_actividad' => count($dias_activos),
            'dias_transcurridos' => $dias_transcurridos,
            'tasa_quiebras_por_dia' => round(count($detalles) / max(1, $dias_transcurridos), 2),
            'patron_temporal' => $dias_activos
        ],
        'metadata' => [
            'fecha_consulta' => date('Y-m-d H:i:s'),
            'periodo_analizado' => [
                'desde' => $filtros['fecha_inicio'],
                'hasta' => $filtros['fecha_fin']
            ],
            'total_registros' => count($detalles),
            'tiempo_respuesta_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2)
        ]
    ];
    
    // Enviar respuesta
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Error en get_order_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'message' => $e->getMessage(),
        'code' => 'SERVER_ERROR',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
} finally {
    // Cerrar conexión
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
?>