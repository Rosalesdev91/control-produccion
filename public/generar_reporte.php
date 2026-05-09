<?php
session_start();
require_once '../config/database.php';

// Configuración inicial
$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');

// Verificación de acceso
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    header("Location: login_recti.php");
    exit();
}

// Obtener filtros de la sesión O de los parámetros GET (prioridad a GET)
$filtros = [];
$whereConditions = [];

// Primero verificar si hay filtros en GET (para cuando se genera desde la vista)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['search']) || isset($_GET['fecha_inicio']) || isset($_GET['filtro_sucursal']))) {
    // Obtener filtros de GET
    if (!empty($_GET['search'])) {
        $filtros['search'] = $_GET['search'];
    }
    if (!empty($_GET['fecha_inicio'])) {
        $filtros['fecha_inicio'] = $_GET['fecha_inicio'];
    }
    if (!empty($_GET['fecha_fin'])) {
        $filtros['fecha_fin'] = $_GET['fecha_fin'];
    }
    if (!empty($_GET['filtro_sucursal'])) {
        $filtros['filtro_sucursal'] = $_GET['filtro_sucursal'];
    }
    if (!empty($_GET['filtro_motivo'])) {
        $filtros['filtro_motivo'] = $_GET['filtro_motivo'];
    }
    if (!empty($_GET['filtro_responsable'])) {
        $filtros['filtro_responsable'] = $_GET['filtro_responsable'];
    }
    if (!empty($_GET['filtro_responsable_final'])) {
        $filtros['filtro_responsable_final'] = $_GET['filtro_responsable_final'];
    }
} 
// Si no hay filtros en GET, usar los de la sesión
elseif (isset($_SESSION['filtros_reporte'])) {
    $filtros = $_SESSION['filtros_reporte'];
}

// Construir condiciones WHERE basadas en los filtros
if (!empty($filtros['search'])) {
    $search = $conn->real_escape_string($filtros['search']);
    $whereConditions[] = "(orden LIKE '%$search%' OR paciente LIKE '%$search%' OR material LIKE '%$search%')";
}

if (!empty($filtros['fecha_inicio'])) {
    $fecha_inicio = $conn->real_escape_string($filtros['fecha_inicio']);
    $whereConditions[] = "fecha >= '$fecha_inicio'";
}

if (!empty($filtros['fecha_fin'])) {
    $fecha_fin = $conn->real_escape_string($filtros['fecha_fin']);
    $whereConditions[] = "fecha <= '$fecha_fin'";
}

if (!empty($filtros['filtro_sucursal'])) {
    $filtro_sucursal = $conn->real_escape_string($filtros['filtro_sucursal']);
    $whereConditions[] = "sucursal = '$filtro_sucursal'";
}

if (!empty($filtros['filtro_motivo'])) {
    $filtro_motivo = $conn->real_escape_string($filtros['filtro_motivo']);
    $whereConditions[] = "motivo = '$filtro_motivo'";
}

if (!empty($filtros['filtro_responsable'])) {
    $filtro_responsable = $conn->real_escape_string($filtros['filtro_responsable']);
    $whereConditions[] = "responsable = '$filtro_responsable'";
}

if (!empty($filtros['filtro_responsable_final'])) {
    $filtro_responsable_final = $conn->real_escape_string($filtros['filtro_responsable_final']);
    $whereConditions[] = "responsable_final = '$filtro_responsable_final'";
}

// Si no hay filtros aplicados, mostrar solo registros del día actual
if (count($whereConditions) === 0) {
    $hoy = date('Y-m-d');
    $whereConditions[] = "DATE(fecha) = '$hoy'";
}

// Construir la consulta
$query = "SELECT * FROM rectificaciones";
if (count($whereConditions) > 0) {
    $query .= " WHERE " . implode(" AND ", $whereConditions);
}
$query .= " ORDER BY fecha_registro DESC";

// Resto del código permanece igual...
// Ejecutar la consulta
$result = $conn->query($query);

// Configurar headers para descarga CSV
$filename = 'reporte_rectificaciones_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Crear output stream
$output = fopen('php://output', 'w');

// BOM para UTF-8 (para que Excel abra correctamente el archivo)
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

// Escribir encabezados
$headers = array(
    'Fecha Registro',
    'Hora Registro',
    'Fecha Verificación',
    'Hora Verificación',
    'Sucursal',
    'Orden',
    'Paciente',
    'Tipo Visión',
    'Material',
    'Responsable',
    'Responsable Final',
    'Verificado por',
    'Motivo',
    'Lado',
    'Solución',
    'Registrado por',
    'Fecha Registro BD'
);

fputcsv($output, $headers, ';');

// Escribir datos
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $fila = array(
            $row['fecha'],
            $row['hora'],
            $row['fecha_verificacion'],
            $row['hora_verificacion'],
            $row['sucursal'],
            $row['orden'],
            $row['paciente'],
            $row['tipo_vision'],
            $row['material'],
            $row['responsable'],
            $row['responsable_final'],
            $row['verificada_por'],
            $row['motivo'],
            $row['lado'],
            $row['solucion'],
            $row['empleado_registro'],
            $row['fecha_registro']
        );
        fputcsv($output, $fila, ';');
    }
}

// Cerrar conexión y output
fclose($output);
$conn->close();
exit();