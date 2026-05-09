<?php
// ajax_detalle_equipo.php - VERSIÓN CORREGIDA (EXCLUYE SIN WIP)
declare(strict_types=1);
session_start();

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    http_response_code(403);
    exit(json_encode(['error' => 'No autorizado']));
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

// Parámetros
$equipo      = trim($_GET['equipo'] ?? '');
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$tipo_paro   = trim($_GET['tipo_paro'] ?? '');
$estado      = trim($_GET['estado'] ?? '');

// Validación básica
if ($equipo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
    exit(json_encode(['success' => false, 'error' => 'Parámetros inválidos']));
}

// CONSULTA CORREGIDA - Excluir registros "Sin WIP" y mantener LEFT JOIN para incluir solicitudes sin paro_produccion
$sql = "
    SELECT 
        sp.id,
        sp.fecha_solicitud,
        sp.tipo_paro AS tipo_paro_nombre,
        sp.estado,
        pp.fecha_inicio,
        pp.fecha_fin,
        COALESCE(sp.motivo, pp.motivo, 'Sin descripción') AS descripcion,
        CASE 
            WHEN pp.fecha_inicio IS NOT NULL AND sp.fecha_solicitud IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_inicio)
            ELSE NULL 
        END AS tiempo_respuesta,
        CASE 
            WHEN pp.fecha_fin IS NOT NULL AND pp.fecha_inicio IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin)
            ELSE NULL 
        END AS tiempo_resolucion,
        t.nombre_tecnico AS tecnico
    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud  -- MANTENER LEFT JOIN
    LEFT JOIN tecnicos t ON pp.id_tecnico = t.id
    WHERE sp.equipo = ?
      AND DATE(sp.fecha_solicitud) BETWEEN ? AND ?
      AND sp.tipo_paro != 'Sin WIP'  -- EXCLUIR SIN WIP
";

$params = [$equipo, $fecha_desde, $fecha_hasta];
$types = "sss";

// Filtro por tipo de paro
if ($tipo_paro !== '' && $tipo_paro !== '0' && $tipo_paro !== 'todos') {
    $sql .= " AND sp.tipo_paro = ?";
    $params[] = $tipo_paro;
    $types .= "s";
}

// Filtro por estado - CORREGIDO
if ($estado !== '' && $estado !== 'todos') {
    if ($estado === 'finalizada') {
        $sql .= " AND pp.fecha_fin IS NOT NULL";
    } else {
        $sql .= " AND sp.estado = ?";
        $params[] = $estado;
        $types .= "s";
    }
}

$sql .= " ORDER BY sp.fecha_solicitud DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error preparing statement: " . $conn->error);
    exit(json_encode(['success' => false, 'error' => 'Error en la consulta: ' . $conn->error]));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    error_log("Error executing statement: " . $stmt->error);
    exit(json_encode(['success' => false, 'error' => 'Error al ejecutar: ' . $stmt->error]));
}

$result = $stmt->get_result();
$datos = [];

while ($row = $result->fetch_assoc()) {
    // Formatear tiempos para mejor presentación
    $row['tiempo_respuesta_fmt'] = $row['tiempo_respuesta'] !== null 
        ? formatearMinutos((int)$row['tiempo_respuesta']) 
        : '—';
    
    $row['tiempo_resolucion_fmt'] = $row['tiempo_resolucion'] !== null 
        ? formatearMinutos((int)$row['tiempo_resolucion']) 
        : '—';
    
    // Asegurar que los valores nulos se manejen correctamente
    $row['descripcion'] = $row['descripcion'] ?: 'Sin descripción';
    $row['tipo_paro_nombre'] = $row['tipo_paro_nombre'] ?: 'No especificado';
    
    $datos[] = $row;
}

// Función auxiliar para formato legible
function formatearMinutos(int $minutos): string {
    if ($minutos < 0) return 'Error';
    $horas = floor($minutos / 60);
    $mins = $minutos % 60;
    
    if ($horas > 0) {
        return "{$horas}h {$mins}m";
    } else {
        return "{$mins}m";
    }
}

echo json_encode([
    'success' => true,
    'data' => $datos,
    'total' => count($datos),
    'filtros' => [
        'equipo' => $equipo,
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta,
        'tipo_paro' => $tipo_paro,
        'estado' => $estado,
        'excluye_sin_wip' => true
    ]
], JSON_UNESCAPED_UNICODE);

$stmt->close();
$conn->close();
exit;