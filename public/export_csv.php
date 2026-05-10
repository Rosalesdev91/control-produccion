<?php
/**
 * Exportar todos los datos del sistema a un archivo Excel para Incentivos
 * Versión corregida y optimizada para coincidir con la estructura de metas_diarias.php
 * Incluye empleados sin equipo asignado
 */

// Iniciar output buffering para evitar problemas con headers
ob_start();

// Iniciar sesión para manejar mensajes
session_start();

// Incluir configuración de la base de datos
require_once dirname(__DIR__) . '/config/database.php';

// Verificar e incluir la librería PhpSpreadsheet
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    ob_end_clean();
    error_log("Archivo autoload.php no encontrado en: $autoloadPath");
    header('Content-Type: text/html; charset=utf-8');
    echo '<div class="alert alert-danger">Error: No se encontró el archivo autoload.php. Asegúrese de instalar PhpSpreadsheet con Composer.</div>';
    exit;
}
require_once $autoloadPath;

// Importar clases de PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// Verificar si la clase Spreadsheet está disponible
if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    ob_end_clean();
    error_log("Clase PhpOffice\PhpSpreadsheet\Spreadsheet no encontrada. Verifique la instalación de PhpSpreadsheet.");
    header('Content-Type: text/html; charset=utf-8');
    echo '<div class="alert alert-danger">Error: La librería PhpSpreadsheet no está instalada correctamente.</div>';
    exit;
}

// Configurar zona horaria para Guatemala
date_default_timezone_set('America/Guatemala');

// Agregar constante para turnos y sus horarios
define('TURNOS_HORARIOS', [
    'A' => ['inicio' => '06:01', 'fin' => '14:00'],
    'B' => ['inicio' => '14:01', 'fin' => '21:30'],
    'C' => ['inicio' => '21:31', 'fin' => '06:00'],
    'general' => null
]);

class MetasController {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Validar y sanitizar parámetros de entrada
     */
    public function validateAndSanitizeInputs() {
        $filters = [
            'fecha_inicio' => [
                'filter' => FILTER_VALIDATE_REGEXP,
                'options' => ['regexp' => '/^\d{4}-\d{2}-\d{2}$/']
            ],
            'fecha_fin' => [
                'filter' => FILTER_VALIDATE_REGEXP,
                'options' => ['regexp' => '/^\d{4}-\d{2}-\d{2}$/']
            ],
            'hora_inicio' => [
                'filter' => FILTER_VALIDATE_REGEXP,
                'options' => ['regexp' => '/^\d{2}:\d{2}$/']
            ],
            'hora_fin' => [
                'filter' => FILTER_VALIDATE_REGEXP,
                'options' => ['regexp' => '/^\d{2}:\d{2}$/']
            ],
            'turno' => [
                'filter' => FILTER_SANITIZE_STRING,
                'flags' => FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH
            ]
        ];
        
        $input = filter_input_array(INPUT_GET, $filters);
        
        $turno = $input['turno'] ?? 'general';
        if (!array_key_exists($turno, TURNOS_HORARIOS)) {
            $turno = 'general';
        }
        
        return [
            'fecha_inicio' => $input['fecha_inicio'] ?? date('Y-m-d'),
            'fecha_fin' => $input['fecha_fin'] ?? date('Y-m-d'),
            'hora_inicio' => $input['hora_inicio'] ?? '00:00',
            'hora_fin' => $input['hora_fin'] ?? '23:59',
            'turno' => $turno
        ];
    }
    
    /**
     * Validar rango de fechas
     */
    public function validateDateRange($datetime_inicio, $datetime_fin) {
        if (strtotime($datetime_inicio) > strtotime($datetime_fin)) {
            throw new InvalidArgumentException('La fecha de inicio no puede ser posterior a la fecha de fin.');
        }
    }
    
    /**
     * Calcular horas de jornada según el turno
     */
    public function calcularHorasJornada($datetime_inicio) {
        // Obtener la hora de inicio
        $hora_inicio = date('H:i', strtotime($datetime_inicio));
        
        // Determinar el turno basado en la hora de inicio
        if ($hora_inicio >= '06:00' && $hora_inicio < '14:00') {
            // Turno A: 7.25 horas
            return 7.25;
        } elseif ($hora_inicio >= '14:00' && $hora_inicio < '21:30') {
            // Turno B: 6.75 horas
            return 6.75;
        } else {
            // Turno C: 7.75 horas
            return 7.75;
        }
    }
    
    /**
     * Obtener datos de metas vs producción por equipo (resumen, ajustado a periodo sin 'jornada')
     */
    public function getMetasData($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno) {
        // Calcular número de días
        $fecha_inicio = date('Y-m-d', strtotime($datetime_inicio));
        $fecha_fin = date('Y-m-d', strtotime($datetime_fin));
        
        $num_days = 1;
        if ($fecha_inicio != $fecha_fin) {
            $start = new DateTime($fecha_inicio);
            $end = new DateTime($fecha_fin);
            $num_days = $start->diff($end)->days + 1;
        }

        // Calcular horas totales del periodo
        $horas_periodo = $this->calcularHorasJornada($datetime_inicio) * $num_days;
        
        $time_start = $hora_inicio . ':00';
        $time_end = $hora_fin . ':00';
        
        // Construir condiciones de tiempo
        $time_condition_prod = "";
        $time_condition_quiebra = "";
        $time_condition_empleados = "";
        
        if ($turno !== 'general') {
            if ($turno === 'C') {
                $time_condition_prod = " AND (TIME(fecha) >= ? OR TIME(fecha) <= ?)";
                $time_condition_quiebra = " AND (TIME(CONCAT(q.fecha, ' ', q.hora)) >= ? OR TIME(CONCAT(q.fecha, ' ', q.hora)) <= ?)";
                $time_condition_empleados = " AND (TIME(fecha) >= ? OR TIME(fecha) <= ?)";
            } else {
                $time_condition_prod = " AND TIME(fecha) BETWEEN ? AND ?";
                $time_condition_quiebra = " AND TIME(CONCAT(q.fecha, ' ', q.hora)) BETWEEN ? AND ?";
                $time_condition_empleados = " AND TIME(fecha) BETWEEN ? AND ?";
            }
        }

        $sql = "
            SELECT 
                e.nombre_equipo AS equipo, 
                e.meta_hora_persona AS meta_hora, 
                $horas_periodo AS horas_periodo,
                (e.meta_hora_persona * $horas_periodo) AS meta_periodo,
                COALESCE(p.producido, 0) AS producido,
                COALESCE(q.total_quiebras, 0) AS total_quiebras,
                (COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0)) AS producido_neto,
                (COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0) - (e.meta_hora_persona * $horas_periodo)) AS diferencia_periodo,
                CASE 
                    WHEN COALESCE(p.producido, 0) > 0 
                    THEN ROUND((COALESCE(q.total_quiebras, 0) / COALESCE(p.producido, 0)) * 100, 2)
                    ELSE 0
                END AS porcentaje_quiebras,
                CASE 
                    WHEN (e.meta_hora_persona * $horas_periodo) > 0 
                    THEN ROUND(((COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0)) / (e.meta_hora_persona * $horas_periodo)) * 100, 2)
                    ELSE 0
                END AS porcentaje_avance_periodo,
                CASE 
                    WHEN (COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0)) >= (e.meta_hora_persona * $horas_periodo) 
                    THEN '✅ Meta alcanzada'
                    ELSE '❌ No alcanzada'
                END AS estado_periodo
            FROM equipos e
            LEFT JOIN (
                SELECT equipo, COUNT(*) AS producido
                FROM (
                    SELECT equipo FROM produccion WHERE fecha BETWEEN ? AND ? $time_condition_prod
                    UNION ALL
                    SELECT equipo FROM registros_antiguos WHERE fecha BETWEEN ? AND ? $time_condition_prod
                ) AS produccion_combinada
                GROUP BY equipo
            ) p ON e.nombre_equipo = p.equipo
            LEFT JOIN (
                SELECT 
                    COALESCE(p.equipo, '') AS equipo,
                    COUNT(DISTINCT q.id) AS total_quiebras
                FROM registro_quiebras q
                LEFT JOIN (
                    SELECT DISTINCT equipo, empleado 
                    FROM (
                        SELECT equipo, empleado FROM produccion WHERE fecha BETWEEN ? AND ? $time_condition_empleados
                        UNION ALL
                        SELECT equipo, empleado FROM registros_antiguos WHERE fecha BETWEEN ? AND ? $time_condition_empleados
                    ) AS empleados_union
                ) p ON q.empleado = p.empleado
                WHERE CONCAT(q.fecha, ' ', q.hora) BETWEEN ? AND ? $time_condition_quiebra
                GROUP BY p.equipo
            ) q ON e.nombre_equipo = q.equipo
            WHERE e.meta_hora_persona IS NOT NULL
            ORDER BY e.nombre_equipo ASC
        ";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta en getMetasData: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }

        // Parámetros base
        $params = [$datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin];
        $types = str_repeat('s', 10);

        // Agregar parámetros de tiempo si aplica
        if ($turno !== 'general') {
            $params = array_merge($params, [$time_start, $time_end, $time_start, $time_end, $time_start, $time_end, $time_start, $time_end, $time_start, $time_end]);
            $types .= str_repeat('s', 10);
        }

        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getMetasData: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $stmt->close();

        return $result;
    }
    
    /**
     * Obtener detalles de quiebras por equipo (corregido con validación)
     */
    public function getQuiebrasDetails($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno) {
        $time_start = $hora_inicio . ':00';
        $time_end = $hora_fin . ':00';
        
        $time_condition = "";
        if ($turno !== 'general') {
            if ($turno === 'C') {
                $time_condition = " AND (TIME(CONCAT(q.fecha, ' ', q.hora)) >= ? OR TIME(CONCAT(q.fecha, ' ', q.hora)) <= ?)";
            } else {
                $time_condition = " AND TIME(CONCAT(q.fecha, ' ', q.hora)) BETWEEN ? AND ?";
            }
        }
        
        $sql = "
            SELECT 
                q.id, 
                COALESCE(p.equipo, 'Sin equipo') AS equipo, 
                q.empleado, 
                q.area, 
                q.motivo, 
                CONCAT(q.fecha, ' ', q.hora) as fecha_completa
            FROM registro_quiebras q
            LEFT JOIN (
                SELECT DISTINCT equipo, empleado 
                FROM (
                    SELECT equipo, empleado FROM produccion WHERE fecha BETWEEN ? AND ?
                    UNION ALL
                    SELECT equipo, empleado FROM registros_antiguos WHERE fecha BETWEEN ? AND ?
                ) AS empleados_union
            ) p ON q.empleado = p.empleado
            WHERE CONCAT(q.fecha, ' ', q.hora) BETWEEN ? AND ? $time_condition
            ORDER BY p.equipo, q.empleado, q.fecha DESC, q.hora DESC
        ";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta en getQuiebrasDetails: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        
        // Parámetros base
        $params = [$datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin];
        $types = str_repeat('s', 6);
        
        // Agregar parámetros de tiempo si aplica
        if ($turno !== 'general') {
            $params = array_merge($params, [$time_start, $time_end]);
            $types .= 'ss';
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getQuiebrasDetails: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Obtener reporte global de producción por empleado para incentivos (incluyendo empleados sin equipo)
     */
    public function getGlobalProduccionPorEmpleado($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno) {
        $time_start = $hora_inicio . ':00';
        $time_end = $hora_fin . ':00';
        
        $time_condition_prod = "";
        $time_condition_quiebra = "";
        
        if ($turno !== 'general') {
            if ($turno === 'C') {
                $time_condition_prod = " AND (TIME(fecha) >= ? OR TIME(fecha) <= ?)";
                $time_condition_quiebra = " AND (TIME(CONCAT(fecha, ' ', hora)) >= ? OR TIME(CONCAT(fecha, ' ', hora)) <= ?)";
            } else {
                $time_condition_prod = " AND TIME(fecha) BETWEEN ? AND ?";
                $time_condition_quiebra = " AND TIME(CONCAT(fecha, ' ', hora)) BETWEEN ? AND ?";
            }
        }
        
        $sql = "
            SELECT 
                p.empleado,
                p.area,
                COALESCE(p.equipo, 'Sin equipo asignado') AS equipo,
                COUNT(DISTINCT p.hora) AS total_horas_producidas,
                SUM(p.producido) AS total_producido,
                COALESCE(SUM(q.total_quiebras), 0) AS total_quiebras,
                SUM(p.producido) - COALESCE(SUM(q.total_quiebras), 0) AS producido_neto,
                COALESCE(e.meta_hora_persona, 0) * COUNT(DISTINCT p.hora) AS meta_global,
                (SUM(p.producido) - COALESCE(SUM(q.total_quiebras), 0)) - (COALESCE(e.meta_hora_persona, 0) * COUNT(DISTINCT p.hora)) AS diferencia_global,
                CASE 
                    WHEN COALESCE(e.meta_hora_persona, 0) = 0 
                    THEN '⚠️ Sin meta definida'
                    WHEN (SUM(p.producido) - COALESCE(SUM(q.total_quiebras), 0)) - (COALESCE(e.meta_hora_persona, 0) * COUNT(DISTINCT p.hora)) > 0 
                    THEN '✅ Positiva (Incentivo sugerido)'
                    ELSE '❌ Negativa (Sin incentivo)'
                END AS estado_incentivo
            FROM (
                SELECT 
                    empleado, 
                    area,
                    equipo, 
                    COUNT(*) AS producido,
                    DATE_FORMAT(fecha, '%Y-%m-%d %H:00:00') AS hora
                FROM (
                    SELECT empleado, area, equipo, fecha FROM produccion WHERE fecha BETWEEN ? AND ? $time_condition_prod
                    UNION ALL
                    SELECT empleado, area, equipo, fecha FROM registros_antiguos WHERE fecha BETWEEN ? AND ? $time_condition_prod
                ) ap
                GROUP BY empleado, area, equipo, DATE_FORMAT(fecha, '%Y-%m-%d %H:00:00')
            ) p
            LEFT JOIN equipos e ON p.equipo = e.nombre_equipo
            LEFT JOIN (
                SELECT 
                    empleado, 
                    COUNT(DISTINCT id) AS total_quiebras,
                    DATE_FORMAT(CONCAT(fecha, ' ', hora), '%Y-%m-%d %H:00:00') AS hora_quiebra
                FROM registro_quiebras
                WHERE CONCAT(fecha, ' ', hora) BETWEEN ? AND ? $time_condition_quiebra
                GROUP BY empleado, DATE_FORMAT(CONCAT(fecha, ' ', hora), '%Y-%m-%d %H:00:00')
            ) q ON p.empleado = q.empleado AND p.hora = q.hora_quiebra
            GROUP BY p.empleado, p.area, p.equipo, e.meta_hora_persona
            ORDER BY 
                CASE WHEN p.equipo IS NULL OR p.equipo = '' THEN 1 ELSE 0 END,
                p.equipo, 
                p.empleado ASC
        ";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta en getGlobalProduccionPorEmpleado: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        
        // Parámetros base
        $params = [$datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin, $datetime_inicio, $datetime_fin];
        $types = str_repeat('s', 6);
        
        // Agregar parámetros de tiempo si aplica
        if ($turno !== 'general') {
            $params = array_merge($params, [$time_start, $time_end, $time_start, $time_end, $time_start, $time_end]);
            $types .= str_repeat('s', 6);
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getGlobalProduccionPorEmpleado: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Obtener reporte detallado por hora para incentivos, agrupado por empleado (incluye empleados sin equipo)
     */
    public function getDetalladoPorHoras($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno) {
        $time_start = $hora_inicio . ':00';
        $time_end = $hora_fin . ':00';
        
        $time_condition_prod = "";
        $time_condition_quiebra = "";
        
        if ($turno !== 'general') {
            if ($turno === 'C') {
                $time_condition_prod = " AND (TIME(fecha) >= ? OR TIME(fecha) <= ?)";
                $time_condition_quiebra = " AND (TIME(CONCAT(fecha, ' ', hora)) >= ? OR TIME(CONCAT(fecha, ' ', hora)) <= ?)";
            } else {
                $time_condition_prod = " AND TIME(fecha) BETWEEN ? AND ?";
                $time_condition_quiebra = " AND TIME(CONCAT(fecha, ' ', hora)) BETWEEN ? AND ?";
            }
        }
        
        $sql = "
            SELECT 
                p.empleado,
                p.area,
                COALESCE(p.equipo, 'Sin equipo asignado') AS equipo,
                p.dia,
                p.hora,
                p.cantidad_producida,
                COALESCE(e.meta_hora_persona, 0) AS meta_hora,
                COALESCE(q.total_quiebras, 0) AS quiebras,
                (p.cantidad_producida - COALESCE(q.total_quiebras, 0)) AS produccion_neta,
                (p.cantidad_producida - COALESCE(q.total_quiebras, 0) - COALESCE(e.meta_hora_persona, 0)) AS diferencia,
                CASE 
                    WHEN COALESCE(e.meta_hora_persona, 0) = 0 
                    THEN '⚠️ Sin meta definida'
                    WHEN (p.cantidad_producida - COALESCE(q.total_quiebras, 0) - COALESCE(e.meta_hora_persona, 0)) >= 0 
                    THEN '✅ Positiva'
                    ELSE '❌ Negativa'
                END AS estado_diferencia
            FROM (
                SELECT 
                    empleado,
                    area,
                    equipo,
                    DATE(fecha) AS dia,
                    DATE_FORMAT(fecha, '%H:00:00') AS hora,
                    COUNT(x.id) AS cantidad_producida
                FROM (
                    SELECT id, empleado, area, equipo, fecha FROM produccion WHERE fecha BETWEEN ? AND ? $time_condition_prod
                    UNION ALL
                    SELECT id, empleado, area, equipo, fecha FROM registros_antiguos WHERE fecha BETWEEN ? AND ? $time_condition_prod
                ) x
                LEFT JOIN equipos e ON x.equipo = e.nombre_equipo
                GROUP BY empleado, area, equipo, DATE(fecha), DATE_FORMAT(fecha, '%H:00:00')
            ) p
            LEFT JOIN equipos e ON p.equipo = e.nombre_equipo
            LEFT JOIN (
                SELECT 
                    empleado,
                    DATE(fecha) AS dia,
                    DATE_FORMAT(CONCAT(fecha, ' ', hora), '%H:00:00') AS hora,
                    COUNT(DISTINCT id) AS total_quiebras
                FROM registro_quiebras
                WHERE CONCAT(fecha, ' ', hora) BETWEEN ? AND ? $time_condition_quiebra
                GROUP BY empleado, DATE(fecha), DATE_FORMAT(CONCAT(fecha, ' ', hora), '%H:00:00')
            ) q ON p.empleado = q.empleado AND p.dia = q.dia AND p.hora = q.hora
            ORDER BY 
                CASE WHEN p.equipo IS NULL OR p.equipo = '' THEN 1 ELSE 0 END,
                p.equipo, 
                p.empleado, 
                p.dia, 
                p.hora ASC
        ";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta en getDetalladoPorHoras: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        
        // Parámetros base
        $params = [
            $datetime_inicio, $datetime_fin, 
            $datetime_inicio, $datetime_fin,
            $datetime_inicio, $datetime_fin
        ];
        $types = str_repeat('s', 6);
        
        // Agregar parámetros de tiempo si aplica
        if ($turno !== 'general') {
            $params = array_merge(
                $params, 
                [$time_start, $time_end, $time_start, $time_end, $time_start, $time_end]
            );
            $types .= str_repeat('s', 6);
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getDetalladoPorHoras: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[$row['empleado']][] = $row;
        }
        $stmt->close();
        
        return $data;
    }
}

// Inicializar el controlador
$controller = new MetasController($conn);

// Validar y sanitizar parámetros de entrada
$inputs = $controller->validateAndSanitizeInputs();
extract($inputs);

// Manejo especial para turno C que cruza medianoche
$original_fecha_fin = $fecha_fin;
if ($turno !== 'general' && isset(TURNOS_HORARIOS[$turno])) {
    $horario = TURNOS_HORARIOS[$turno];
    $hora_inicio = $horario['inicio'];
    $hora_fin = $horario['fin'];
    
    // Manejo especial para turno C
    if ($turno === 'C') {
        $fecha_fin = date('Y-m-d', strtotime($original_fecha_fin . ' +1 day'));
    }
}

$datetime_inicio = $fecha_inicio . ' ' . $hora_inicio . ':00';
$datetime_fin = $fecha_fin . ' ' . $hora_fin . ':59';

try {
    // Validar rango de fechas
    $controller->validateDateRange($datetime_inicio, $datetime_fin);

    // Obtener datos
    $resultado_metas = $controller->getMetasData($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno);
    $quiebras_data = $controller->getQuiebrasDetails($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno);
    $global_por_empleado = $controller->getGlobalProduccionPorEmpleado($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno);
    $detallado_por_horas = $controller->getDetalladoPorHoras($datetime_inicio, $datetime_fin, $hora_inicio, $hora_fin, $turno);

    // Crear el objeto Spreadsheet
    $spreadsheet = new Spreadsheet();

    // Hoja 1: Resumen de Metas vs Producción (ajustado a periodo)
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Resumen Metas');

    // Escribir encabezados
    $sheet->setCellValue('A1', '=== Resumen de Metas vs Producción ===');
    $sheet->setCellValue('A2', 'Filtros aplicados:');
    $sheet->setCellValue('A3', 'Fecha desde: ' . $inputs['fecha_inicio'] . ' hasta: ' . $original_fecha_fin);
    $sheet->setCellValue('A4', 'Hora desde: ' . $hora_inicio . ' hasta: ' . $hora_fin);
    $sheet->setCellValue('A5', 'Turno: ' . ucfirst($turno) . ($turno === 'general' ? '(todo el día)' : ''));
    
    $sheet->fromArray([
        'Equipo',
        'Meta/Hora',
        'Horas Periodo',
        'Meta Periodo',
        'Producción Total',
        'Quiebras',
        'Prod. Neto',
        'Diferencia',
        '% Quiebras',
        '% Avance',
        'Estado'
    ], NULL, 'A7');

    // Aplicar estilo a headers
    $headerStyle = [
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D3D3D3']]
    ];
    $sheet->getStyle('A7:K7')->applyFromArray($headerStyle);
    for ($col = 'A'; $col <= 'K'; $col++) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Escribir datos
    $rowNumber = 8;
    if ($resultado_metas && $resultado_metas->num_rows > 0) {
        while ($row = $resultado_metas->fetch_assoc()) {
            $sheet->fromArray([
                $row['equipo'],
                number_format($row['meta_hora'], 0, ',', '.'),
                number_format($row['horas_periodo'], 2, ',', '.'),
                number_format($row['meta_periodo'], 0, ',', '.'),
                number_format($row['producido'], 0, ',', '.'),
                number_format($row['total_quiebras'], 0, ',', '.'),
                number_format($row['producido_neto'], 0, ',', '.'),
                ($row['diferencia_periodo'] >= 0 ? '+' : '') . number_format($row['diferencia_periodo'], 0, ',', '.'),
                number_format($row['porcentaje_quiebras'], 2, ',', '.') . '%',
                number_format($row['porcentaje_avance_periodo'], 2, ',', '.') . '%',
                $row['estado_periodo']
            ], NULL, 'A' . $rowNumber);
            $rowNumber++;
        }
    } else {
        $sheet->setCellValue('A' . $rowNumber, 'No hay datos de resumen disponibles para el rango seleccionado');
    }

    // Hoja 2: Reporte Global por Empleado para Incentivos (INCLUYE SIN EQUIPO)
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Global Empleados');
    
    $sheet->setCellValue('A1', '=== Reporte Global por Empleado para Incentivos ===');
    $sheet->setCellValue('A2', 'Nota: Empleados sin equipo asignado aparecen con "Sin equipo asignado" y meta = 0');
    $sheet->fromArray([
        'Empleado',
        'Área',
        'Equipo',
        'Total Horas Producidas',
        'Total Producido',
        'Total Quiebras',
        'Producido Neto',
        'Meta Global (Meta/Hora * Horas)',
        'Diferencia Global',
        'Estado Incentivo'
    ], NULL, 'A3');

    // Estilo headers
    $sheet->getStyle('A3:J3')->applyFromArray($headerStyle);
    for ($col = 'A'; $col <= 'J'; $col++) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $rowNumber = 4;
    $has_data = false;
    if (!empty($global_por_empleado)) {
        foreach ($global_por_empleado as $row) {
            // Validar claves
            if (isset($row['empleado'], $row['area'], $row['equipo'], $row['total_horas_producidas'], $row['total_producido'], $row['total_quiebras'], $row['producido_neto'], $row['meta_global'], $row['diferencia_global'], $row['estado_incentivo'])) {
                $has_data = true;
                $sheet->fromArray([
                    $row['empleado'],
                    $row['area'],
                    $row['equipo'],
                    number_format($row['total_horas_producidas'], 0, ',', '.'),
                    number_format($row['total_producido'], 0, ',', '.'),
                    number_format($row['total_quiebras'], 0, ',', '.'),
                    number_format($row['producido_neto'], 0, ',', '.'),
                    number_format($row['meta_global'], 0, ',', '.'),
                    ($row['diferencia_global'] >= 0 ? '+' : '') . number_format($row['diferencia_global'], 0, ',', '.'),
                    $row['estado_incentivo']
                ], NULL, 'A' . $rowNumber);
                
                // Resaltar empleados sin equipo
                if ($row['equipo'] === 'Sin equipo asignado') {
                    $sheet->getStyle('A' . $rowNumber . ':J' . $rowNumber)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFF2DEDE');
                }
                
                $rowNumber++;
            } else {
                error_log("Fila inválida en Global Empleados: " . json_encode($row));
            }
        }
    }
    if (!$has_data) {
        $sheet->setCellValue('A' . $rowNumber, 'No hay datos globales por empleado disponibles para el rango seleccionado');
    }

    // Hojas individuales por empleado para el Reporte Detallado por Hora (INCLUYE SIN EQUIPO)
    if (!empty($detallado_por_horas)) {
        foreach ($detallado_por_horas as $empleado => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(substr('Detallado ' . $empleado, 0, 31));

            $sheet->setCellValue('A1', "=== Reporte Detallado por Hora para $empleado ===");
            $sheet->fromArray([
                'Empleado',
                'Área',
                'Equipo',
                'Día',
                'Hora',
                'Cantidad Producida',
                'Meta por Hora',
                'Quiebras',
                'Producción Neta',
                'Diferencia',
                'Estado Diferencia'
            ], NULL, 'A2');

            // Estilo headers
            $sheet->getStyle('A2:K2')->applyFromArray($headerStyle);
            for ($col = 'A'; $col <= 'K'; $col++) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $rowNumber = 3;
            $sin_equipo = false;
            
            foreach ($rows as $row) {
                if (isset($row['empleado'], $row['area'], $row['equipo'], $row['dia'], $row['hora'], $row['cantidad_producida'], $row['meta_hora'], $row['quiebras'], $row['produccion_neta'], $row['diferencia'], $row['estado_diferencia'])) {
                    $sheet->fromArray([
                        $row['empleado'],
                        $row['area'],
                        $row['equipo'],
                        $row['dia'],
                        $row['hora'],
                        number_format($row['cantidad_producida'], 0, ',', '.'),
                        number_format($row['meta_hora'], 0, ',', '.'),
                        number_format($row['quiebras'], 0, ',', '.'),
                        number_format($row['produccion_neta'], 0, ',', '.'),
                        ($row['diferencia'] >= 0 ? '+' : '') . number_format($row['diferencia'], 0, ',', '.'),
                        $row['estado_diferencia']
                    ], NULL, 'A' . $rowNumber);
                    
                    // Marcar si es empleado sin equipo
                    if ($row['equipo'] === 'Sin equipo asignado') {
                        $sin_equipo = true;
                        $sheet->getStyle('A' . $rowNumber . ':K' . $rowNumber)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFF2DEDE');
                    }
                    
                    $rowNumber++;
                } else {
                    error_log("Fila inválida en Detallado " . $empleado . ": " . json_encode($row));
                }
            }
            
            // Agregar nota si es empleado sin equipo
            if ($sin_equipo) {
                $sheet->setCellValue('A' . $rowNumber, 'NOTA: Este empleado no tiene equipo asignado - Meta = 0');
                $sheet->getStyle('A' . $rowNumber)->getFont()->setItalic(true);
            }
        }
    } else {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detallado Vacío');
        $sheet->setCellValue('A1', 'No hay datos detallados por hora disponibles para el rango seleccionado');
    }

    // Nueva Hoja: Cumplimientos Positivos (INCLUYE SIN EQUIPO)
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Cumplimientos Positivos');
    
    $sheet->setCellValue('A1', '=== Detalle de Cumplimientos Positivos por Hora ===');
    $sheet->setCellValue('A2', 'Nota: Empleados sin equipo no tienen cumplimientos positivos (meta = 0)');
    $sheet->fromArray([
        'Empleado',
        'Área',
        'Equipo',
        'Día',
        'Hora',
        'Cantidad Producida',
        'Meta por Hora',
        'Quiebras',
        'Producción Neta',
        'Diferencia',
        'Estado Diferencia'
    ], NULL, 'A3');

    // Estilo headers
    $sheet->getStyle('A3:K3')->applyFromArray($headerStyle);
    for ($col = 'A'; $col <= 'K'; $col++) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $rowNumber = 4;
    $has_data = false;
    if (!empty($detallado_por_horas)) {
        foreach ($detallado_por_horas as $empleado => $rows) {
            foreach ($rows as $row) {
                if ($row['estado_diferencia'] === '✅ Positiva') {
                    $has_data = true;
                    $sheet->fromArray([
                        $row['empleado'],
                        $row['area'],
                        $row['equipo'],
                        $row['dia'],
                        $row['hora'],
                        number_format($row['cantidad_producida'], 0, ',', '.'),
                        number_format($row['meta_hora'], 0, ',', '.'),
                        number_format($row['quiebras'], 0, ',', '.'),
                        number_format($row['produccion_neta'], 0, ',', '.'),
                        ($row['diferencia'] >= 0 ? '+' : '') . number_format($row['diferencia'], 0, ',', '.'),
                        $row['estado_diferencia']
                    ], NULL, 'A' . $rowNumber);
                    $rowNumber++;
                }
            }
        }
    }
    if (!$has_data) {
        $sheet->setCellValue('A' . $rowNumber, 'No hay instancias de cumplimientos positivos para el rango seleccionado');
    }

    // Nueva Hoja: Empleados Sin Equipo (INFORME ESPECIAL)
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Empleados Sin Equipo');
    
    $sheet->setCellValue('A1', '=== Reporte Especial: Empleados sin Equipo Asignado ===');
    $sheet->setCellValue('A2', 'Estos empleados producen pero no tienen equipo asignado ni metas definidas');
    $sheet->fromArray([
        'Empleado',
        'Área',
        'Total Producción',
        'Total Quiebras',
        'Producción Neta',
        'Total Horas',
        'Promedio Producción/Hora',
        'Recomendación'
    ], NULL, 'A3');

    // Estilo headers
    $sheet->getStyle('A3:H3')->applyFromArray($headerStyle);
    for ($col = 'A'; $col <= 'H'; $col++) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $rowNumber = 4;
    $has_sin_equipo = false;
    if (!empty($global_por_empleado)) {
        foreach ($global_por_empleado as $row) {
            if ($row['equipo'] === 'Sin equipo asignado' && $row['total_producido'] > 0) {
                $has_sin_equipo = true;
                
                // Calcular promedio por hora
                $promedio_hora = $row['total_horas_producidas'] > 0 
                    ? round($row['producido_neto'] / $row['total_horas_producidas'], 2)
                    : 0;
                
                // Generar recomendación
                $recomendacion = 'Revisar asignación de equipo';
                if ($promedio_hora > 50) {
                    $recomendacion = 'Alto rendimiento - Considerar asignar a equipo de alta productividad';
                } elseif ($promedio_hora > 30) {
                    $recomendacion = 'Rendimiento medio - Asignar a equipo estándar';
                } elseif ($promedio_hora > 0) {
                    $recomendacion = 'Rendimiento bajo - Necesita capacitación';
                }
                
                $sheet->fromArray([
                    $row['empleado'],
                    $row['area'],
                    number_format($row['total_producido'], 0, ',', '.'),
                    number_format($row['total_quiebras'], 0, ',', '.'),
                    number_format($row['producido_neto'], 0, ',', '.'),
                    number_format($row['total_horas_producidas'], 0, ',', '.'),
                    number_format($promedio_hora, 2, ',', '.'),
                    $recomendacion
                ], NULL, 'A' . $rowNumber);
                
                // Resaltar en rojo claro
                $sheet->getStyle('A' . $rowNumber . ':H' . $rowNumber)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF2DEDE');
                
                $rowNumber++;
            }
        }
    }
    if (!$has_sin_equipo) {
        $sheet->setCellValue('A' . $rowNumber, 'No hay empleados sin equipo asignado con producción en el periodo seleccionado');
    }

    // Hoja: Detalles de Quiebras
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Detalles Quiebras');
    
    $sheet->setCellValue('A1', '=== Detalles de Quiebras ===');
    $sheet->fromArray(['ID', 'Equipo', 'Empleado', 'Área', 'Fecha Completa', 'Motivo'], NULL, 'A2');

    // Estilo headers
    $sheet->getStyle('A2:F2')->applyFromArray($headerStyle);
    for ($col = 'A'; $col <= 'F'; $col++) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $rowNumber = 3;
    $has_data = false;
    if (!empty($quiebras_data)) {
        foreach ($quiebras_data as $row) {
            // Validar claves
            $required_keys = ['id', 'equipo', 'empleado', 'area', 'fecha_completa', 'motivo'];
            if (array_key_exists($required_keys[0], $row) && array_key_exists($required_keys[1], $row) &&
                array_key_exists($required_keys[2], $row) && array_key_exists($required_keys[3], $row) &&
                array_key_exists($required_keys[4], $row) && array_key_exists($required_keys[5], $row)) {
                $has_data = true;
                $sheet->fromArray([
                    $row['id'],
                    $row['equipo'] ?? 'N/A',
                    $row['empleado'] ?? 'N/A',
                    $row['area'] ?? 'N/A',
                    $row['fecha_completa'] ?? 'N/A',
                    $row['motivo'] ?? 'N/A'
                ], NULL, 'A' . $rowNumber);
                $rowNumber++;
            } else {
                error_log("Fila inválida en Detalles Quiebras: " . json_encode($row));
            }
        }
    }
    if (!$has_data) {
        $sheet->setCellValue('A' . $rowNumber, 'No hay datos de quiebras disponibles para el rango de fechas y turno seleccionados');
    }

    // Configurar headers para la descarga del archivo Excel
    if (ob_get_length()) {
        ob_clean(); // Limpiar cualquier salida previa
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="reporte_incentivos_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Guardar el archivo Excel
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    // Limpiar el buffer de salida y enviar el contenido
    ob_end_flush();
    exit;

} catch (Exception $e) {
    // Limpiar el buffer de salida
    ob_end_clean();
    
    // Registrar el error
    error_log("Error al generar archivo Excel: " . $e->getMessage());
    
    // Mostrar mensaje de error al usuario
    header('Content-Type: text/html; charset=utf-8');
    echo '<div class="alert alert-danger">Error al generar el archivo Excel: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>