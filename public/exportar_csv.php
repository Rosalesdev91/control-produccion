<?php
require_once dirname(__DIR__) . '/config/database.php';
session_start();

// Validar sesión y rol
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    die("Acceso denegado");
}

// Conexión a la base de datos
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Obtener filtros del POST
$filtros = array_map('trim', [
    'turno'           => $_POST['turno'] ?? '',
    'responsable'     => $_POST['responsable'] ?? '',
    'empleado'        => $_POST['empleado'] ?? '',
    'equipo'          => $_POST['equipo'] ?? '',
    'motivo'          => $_POST['motivo'] ?? '',
    'porque_defecto'  => $_POST['porque_defecto'] ?? '',
    'lado_lente'      => $_POST['lado_lente'] ?? '',
    'fecha_inicio'    => $_POST['fecha_inicio'] ?? '',
    'hora_inicio'     => $_POST['hora_inicio'] ?? '',
    'ampm_inicio'     => $_POST['ampm_inicio'] ?? '',
    'fecha_fin'       => $_POST['fecha_fin'] ?? '',
    'hora_fin'        => $_POST['hora_fin'] ?? '',
    'ampm_fin'        => $_POST['ampm_fin'] ?? '',
]);

// Función para combinar fecha, hora y am/pm en formato "Y-m-d H:i:s"
function combinar_fecha_hora($fecha, $hora, $ampm) {
    if (!$fecha) return null;
    if (!$hora) return $fecha . " 00:00:00"; // si falta hora, asumimos inicio del día
    if (!$ampm) {
        // Si no hay ampm, intentamos interpretar hora directamente
        return date("Y-m-d H:i:s", strtotime("$fecha $hora"));
    }
    // Combina fecha y hora con am/pm, luego formatea a datetime estándar
    return date("Y-m-d H:i:s", strtotime("$fecha $hora $ampm"));
}

$fechaHoraInicio = combinar_fecha_hora($filtros['fecha_inicio'], $filtros['hora_inicio'], $filtros['ampm_inicio']);
$fechaHoraFin    = combinar_fecha_hora($filtros['fecha_fin'], $filtros['hora_fin'], $filtros['ampm_fin']);

// Construcción dinámica de la consulta SQL
$sql = "SELECT orden, lado_lente, motivo, porque_defecto, responsable, equipo, empleado, fecha, hora, turno FROM registro_quiebras WHERE 1=1";
$params = [];
$types = "";

foreach (['turno', 'responsable', 'empleado', 'equipo', 'motivo', 'porque_defecto', 'lado_lente'] as $campo) {
    if (!empty($filtros[$campo]) && strtolower($filtros[$campo]) !== 'todos') {
        $sql .= " AND $campo = ?";
        $params[] = $filtros[$campo];
        $types .= "s";
    }
}

// Comparar fecha y hora por separado para mayor precisión
if (!empty($fechaHoraInicio)) {
    $fechaInicio = substr($fechaHoraInicio, 0, 10);  // yyyy-mm-dd
    $horaInicio  = substr($fechaHoraInicio, 11);     // hh:mm:ss
    $sql .= " AND (fecha > ? OR (fecha = ? AND hora >= ?))";
    $params[] = $fechaInicio;
    $params[] = $fechaInicio;
    $params[] = $horaInicio;
    $types .= "sss";
}

if (!empty($fechaHoraFin)) {
    $fechaFin = substr($fechaHoraFin, 0, 10);
    $horaFin  = substr($fechaHoraFin, 11);
    $sql .= " AND (fecha < ? OR (fecha = ? AND hora <= ?))";
    $params[] = $fechaFin;
    $params[] = $fechaFin;
    $params[] = $horaFin;
    $types .= "sss";
}

// Preparar y ejecutar la consulta
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error en preparación: " . $conn->error);
}

if (!empty($params)) {
    $bind_names = [];
    $bind_names[] = $types;
    foreach ($params as $key => $value) {
        $bind_names[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

$stmt->execute();
$result = $stmt->get_result();

// Zona horaria para formateo de fechas
date_default_timezone_set('America/Lima');

// Nombre del archivo
$filename = "reporte_quiebras_" . date("Ymd_His") . ".csv";

// Headers para descarga
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$filename\"");

// Abrir salida estándar
$fp = fopen('php://output', 'w');
if (!$fp) {
    die("No se pudo abrir la salida para el archivo CSV");
}

// BOM para Excel
fwrite($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Encabezados CSV
fputcsv($fp, [
    'Número de Orden',
    'Lado lente',
    'Motivo',
    'Porque defecto',
    'Responsable',
    'Equipo',
    'Empleado',
    'Fecha y Hora',
    'Turno'
], ';');

// Escribir filas formateando fecha y hora correctamente
while ($row = $result->fetch_assoc()) {
    $fechaHoraRaw = $row['fecha'] . ' ' . $row['hora'];
    $fechaHora = date('d/m/Y h:i A', strtotime($fechaHoraRaw));

    fputcsv($fp, [
        $row['orden'],
        $row['lado_lente'],
        $row['motivo'],
        $row['porque_defecto'],
        $row['responsable'],
        $row['equipo'],
        $row['empleado'],
        $fechaHora,
        $row['turno']
    ], ';');
}

fclose($fp);
exit;
?>