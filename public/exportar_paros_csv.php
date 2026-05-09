<?php
// Conexión a la base de datos
$conn = new mysqli('localhost', 'root', '', 'produccion_quiebras');
if ($conn->connect_error) die("Error de conexión: " . $conn->connect_error);
$conn->set_charset("utf8");

if (!isset($_POST['exportar_csv'])) {
    exit('Acceso inválido');
}

// Función para convertir hora AM/PM a 24h
function convertirHora24($hora, $ampm) {
    $parts = explode(':', $hora);
    $hora_num = intval($parts[0]);
    $minutos = $parts[1] ?? '00';
    if (strtoupper($ampm) === 'PM' && $hora_num < 12) $hora_num += 12;
    elseif (strtoupper($ampm) === 'AM' && $hora_num == 12) $hora_num = 0;
    return str_pad($hora_num, 2, '0', STR_PAD_LEFT) . ':' . $minutos . ':00';
}

// Función para calcular y formatear duración en horas, minutos y segundos
function formatearDuracion($inicio, $fin) {
    if (!$fin) return 'En curso';
    
    $inicio_dt = new DateTime($inicio);
    $fin_dt = new DateTime($fin);
    $interval = $inicio_dt->diff($fin_dt);
    
    // Calcular el total de horas incluyendo los días
    $total_horas = ($interval->days * 24) + $interval->h;
    $minutos = $interval->i;
    $segundos = $interval->s;
    
    return sprintf('%02d:%02d:%02d', $total_horas, $minutos, $segundos);
}

// Función para formatear fecha en formato AM/PM
function formatoHoraAMPM($fecha) {
    if (!$fecha) return 'N/A';
    return date('d/m/Y h:i A', strtotime($fecha));
}

// Obtener filtros
$equipo = $_POST['equipo'] ?? '';
$fecha_inicio = $_POST['fecha_inicio'] ?? '';
$hora_inicio = $_POST['hora_inicio'] ?? '00:00';
$ampm_inicio = $_POST['ampm_inicio'] ?? 'AM';
$fecha_fin = $_POST['fecha_fin'] ?? '';
$hora_fin = $_POST['hora_fin'] ?? '11:59';
$ampm_fin = $_POST['ampm_fin'] ?? 'PM';

// Convertir horas a formato 24h
$hora_inicio_24 = convertirHora24($hora_inicio, $ampm_inicio);
$hora_fin_24 = convertirHora24($hora_fin, $ampm_fin);

$inicio_completo = (!empty($fecha_inicio)) ? "$fecha_inicio $hora_inicio_24" : null;
$fin_completo = (!empty($fecha_fin)) ? "$fecha_fin $hora_fin_24" : null;

// Crear condiciones
$condiciones = [];
$params = [];
$types = "";

// Filtro por equipo
if (!empty($equipo)) {
    $condiciones[] = "pp.equipo = ?";
    $params[] = $equipo;
    $types .= "s";
}

// Filtro por fecha y hora
if (!empty($inicio_completo) && !empty($fin_completo)) {
    $condiciones[] = "(
        (pp.fecha_inicio BETWEEN ? AND ?) OR
        (pp.fecha_fin BETWEEN ? AND ?) OR
        (pp.fecha_inicio <= ? AND pp.fecha_fin >= ?)
    )";
    $params[] = $inicio_completo;
    $params[] = $fin_completo;
    $params[] = $inicio_completo;
    $params[] = $fin_completo;
    $params[] = $inicio_completo;
    $params[] = $fin_completo;
    $types .= "ssssss";
} elseif (!empty($inicio_completo)) {
    $condiciones[] = "pp.fecha_fin >= ?";
    $params[] = $inicio_completo;
    $types .= "s";
} elseif (!empty($fin_completo)) {
    $condiciones[] = "pp.fecha_inicio <= ?";
    $params[] = $fin_completo;
    $types .= "s";
}

$where = count($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

// Consulta actualizada con JOIN para obtener el nombre del técnico y datos de solicitud
$sql = "SELECT 
            pp.id,
            pp.id_solicitud,
            pp.empleado, 
            pp.area, 
            pp.equipo, 
            pp.motivo, 
            pp.fecha_inicio, 
            pp.fecha_fin, 
            pp.activo,
            pp.fecha_solicitud,
            pp.tipo_paro,
            t.nombre_tecnico
        FROM paro_produccion pp
        LEFT JOIN tecnicos t ON pp.id_tecnico = t.id
        $where
        ORDER BY pp.fecha_inicio DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error en la consulta: " . $conn->error);
}

// Ligar parámetros dinámicos
if (!empty($params)) {
    $bind_params[] = &$types;
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_params);
}

$stmt->execute();
$resultado = $stmt->get_result();

// Cabeceras para descargar CSV
header('Content-Type: text/csv; charset=utf-8');
date_default_timezone_set('America/Guatemala');
header('Content-Disposition: attachment; filename=paros_filtrados_' . date('Y-m-d_H-i') . '.csv');

// Salida
$output = fopen('php://output', 'w');

// BOM para UTF-8 (para Excel)
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

// Encabezado del CSV actualizado (CON IDs y nuevas columnas)
fputcsv($output, [
    'ID',
    'ID Solicitud',
    'Empleado', 
    'Área', 
    'Equipo', 
    'Motivo', 
    'Fecha Inicio', 
    'Fecha Fin', 
    'Duración Paro (HH:MM:SS)',
    'Duración Respuesta (HH:MM:SS)',
    'Estado',
    'Técnico',
    'Fecha Solicitud',
    'Tipo Paro'
]);

// Datos
while ($row = $resultado->fetch_assoc()) {
    // Calcular duración del paro
    $duracion_paro = formatearDuracion($row['fecha_inicio'], $row['fecha_fin']);
    
    // Calcular duración de respuesta
    $duracion_respuesta = '';
    if (!empty($row['fecha_solicitud']) && !empty($row['fecha_inicio'])) {
        $duracion_respuesta = formatearDuracion($row['fecha_solicitud'], $row['fecha_inicio']);
    }
    
    fputcsv($output, [
        $row['id'],
        $row['id_solicitud'] ?? 'N/A',
        $row['empleado'],
        $row['area'],
        $row['equipo'],
        $row['motivo'],
        formatoHoraAMPM($row['fecha_inicio']),
        $row['fecha_fin'] ? formatoHoraAMPM($row['fecha_fin']) : 'En curso',
        $duracion_paro,
        $duracion_respuesta ?: 'N/A',
        $row['activo'] ? 'Activo' : 'Finalizado',
        $row['nombre_tecnico'] ?? 'N/A',
        !empty($row['fecha_solicitud']) ? formatoHoraAMPM($row['fecha_solicitud']) : 'N/A',
        $row['tipo_paro'] ?? 'N/A'
    ]);
}

fclose($output);
exit;
?>