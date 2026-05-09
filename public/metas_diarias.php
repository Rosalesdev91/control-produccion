<?php
/**
 * Gestión de Metas por Equipo - Versión Mejorada y Corregida
 * Correcciones implementadas:
 * - Cálculos consistentes de metas y producción
 * - Consultas SQL optimizadas y corregidas
 * - Eliminada dependencia de tabla areas inexistente
 * - Cálculos de porcentajes y diferencias corregidos
 * - Mejor manejo de errores
 * - Filtro por turno ahora respeta las horas específicas por día, incluyendo manejo correcto de múltiples días y turno C (nocturno)
 * - CORRECCIÓN PRINCIPAL: Validación de inputs antes de manejo de AJAX para que globals ($turno, $hora_inicio, etc.) estén disponibles en handleHourDetailsAjax.
 * - CORRECCIÓN ADICIONAL: Agregada condición de tiempo al subquery de empleados en getQuiebrasDetails para consistencia con filtros de turno.
 */

session_start();

// Incluir configuración de la base de datos
require_once '../config/database.php';

// Configurar zona horaria para Guatemala
date_default_timezone_set('America/Guatemala');

// Agregar constante para turnos y sus horarios
define('TURNOS_HORARIOS', [
    'A' => ['inicio' => '06:01', 'fin' => '14:00'],
    'B' => ['inicio' => '14:01', 'fin' => '21:30'],
    'C' => ['inicio' => '21:31', 'fin' => '06:00'], // Nota: fin es del día siguiente si cruza medianoche
    'general' => null
]);

// Función para generar parámetros de filtro para URLs
function getFilterParams() {
    global $original_fecha_inicio, $original_fecha_fin, $hora_inicio, $hora_fin, $turno;
    return http_build_query([
        'fecha_inicio' => $original_fecha_inicio,
        'fecha_fin' => $original_fecha_fin,
        'hora_inicio' => $hora_inicio,
        'hora_fin' => $hora_fin,
        'turno' => $turno
    ]);
}

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
     * Manejar solicitud AJAX para detalles por hora
     */
    public function handleHourDetailsAjax() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'get_hora_detalle') {
            return false;
        }
        
        try {
            $equipo = filter_input(INPUT_GET, 'equipo', FILTER_SANITIZE_STRING);
            $hora = filter_input(INPUT_GET, 'hora', FILTER_SANITIZE_STRING);
            $empleado = filter_input(INPUT_GET, 'empleado', FILTER_SANITIZE_STRING);
            
            if (!$equipo || !$hora) {
                throw new InvalidArgumentException('Parámetros inválidos');
            }
            
            $produccion_data = $this->getProduccionDetails($equipo, $hora, $empleado);
            $quiebras_data = $this->getQuiebrasDetails($equipo, $hora, $empleado);
            
            $this->renderHourDetailsModal($produccion_data, $quiebras_data, $equipo, $hora, $empleado);
            exit;
            
        } catch (Exception $e) {
            error_log("Error en get_hora_detalle: " . $e->getMessage());
            echo '<div class="alert alert-danger">Error al cargar los detalles. Contacte al administrador.</div>';
            exit;
        }
    }
    
/**
 * Obtener detalles de producción por equipo, hora y empleado (incluye registros_antiguos)
 */
private function getProduccionDetails($equipo, $hora, $empleado = null) {
    global $turno, $hora_inicio, $hora_fin;
    
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    // Extraer la fecha base de la hora proporcionada
    $fecha_base = date('Y-m-d', strtotime($hora));
    $hora_base = date('H:i:s', strtotime($hora));
    
    $sql = "
        SELECT p.id, p.empleado, p.area, p.fecha
        FROM (
            SELECT * FROM produccion
            UNION ALL
            SELECT * FROM registros_antiguos
        ) AS p
        WHERE p.equipo = ? 
            AND p.fecha >= ? 
            AND p.fecha < DATE_ADD(?, INTERVAL 1 HOUR)
    ";
    
    $params = [$equipo, $hora, $hora];
    $types = "sss";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            // Turno C: manejar cruce de medianoche
            $sql .= " AND (";
            if ($hora_base >= '21:31:00' || $hora_base < '06:00:00') {
                // Cubrir el rango del turno C
                $sql .= " (p.fecha >= ? AND TIME(p.fecha) >= ?) OR (p.fecha >= DATE_SUB(?, INTERVAL 1 DAY) AND TIME(p.fecha) <= ?)";
                $params = array_merge($params, [$fecha_base, $time_start, $fecha_base, $time_end]);
                $types .= "ssss";
            } else {
                $sql .= " TIME(p.fecha) BETWEEN ? AND ?";
                $params = array_merge($params, [$time_start, $time_end]);
                $types .= "ss";
            }
            $sql .= ")";
        } else {
            // Otros turnos
            $sql .= " AND TIME(p.fecha) BETWEEN ? AND ?";
            $params = array_merge($params, [$time_start, $time_end]);
            $types .= "ss";
        }
    }
    
    if ($empleado) {
        $sql .= " AND p.empleado = ?";
        $params[] = $empleado;
        $types .= "s";
    }
    
    $sql .= " ORDER BY p.empleado, p.fecha DESC";
    
    $stmt = $this->conn->prepare($sql);
    if (!$stmt) {
        error_log("Error al preparar la consulta en getProduccionDetails: " . $this->conn->error);
        throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
    }
    
    error_log("getProduccionDetails params: equipo=$equipo, hora=$hora, empleado=$empleado, types=$types, params=" . json_encode($params));
    
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        error_log("Error al ejecutar la consulta en getProduccionDetails: " . $stmt->error);
        throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
    }
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    error_log("getProduccionDetails registros encontrados: " . count($data));
    return $data;
}
    
/**
 * Obtener detalles de quiebras por equipo, hora y empleado - VERSIÓN CORREGIDA CON FILTRO EN SUBQUERY
 */
private function getQuiebrasDetails($equipo, $hora, $empleado = null) {
    global $turno, $hora_inicio, $hora_fin;
    
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    // Extraer la fecha base de la hora proporcionada
    $fecha_base = date('Y-m-d', strtotime($hora));
    $hora_base = date('H:i:s', strtotime($hora));
    
    // Construir subquery para empleados con filtro de tiempo (igual que en getProduccionDetails)
    $subquery_sql = "
        SELECT DISTINCT empleado 
        FROM (
            SELECT * FROM produccion
            UNION ALL
            SELECT * FROM registros_antiguos
        ) AS p
        WHERE p.equipo = ? 
            AND p.fecha >= ? 
            AND p.fecha < DATE_ADD(?, INTERVAL 1 HOUR)
    ";
    
    $sub_params = [$equipo, $hora, $hora];
    $sub_types = "sss";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            $subquery_sql .= " AND (";
            if ($hora_base >= '21:31:00' || $hora_base < '06:00:00') {
                $subquery_sql .= " (p.fecha >= ? AND TIME(p.fecha) >= ?) OR (p.fecha >= DATE_SUB(?, INTERVAL 1 DAY) AND TIME(p.fecha) <= ?)";
                $sub_params = array_merge($sub_params, [$fecha_base, $time_start, $fecha_base, $time_end]);
                $sub_types .= "ssss";
            } else {
                $subquery_sql .= " TIME(p.fecha) BETWEEN ? AND ?";
                $sub_params = array_merge($sub_params, [$time_start, $time_end]);
                $sub_types .= "ss";
            }
            $subquery_sql .= ")";
        } else {
            $subquery_sql .= " AND TIME(p.fecha) BETWEEN ? AND ?";
            $sub_params = array_merge($sub_params, [$time_start, $time_end]);
            $sub_types .= "ss";
        }
    }
    
    // Consulta principal
    $sql = "
        SELECT q.id, q.empleado, q.area, q.motivo, 
               CONCAT(q.fecha, ' ', q.hora) as fecha_completa
        FROM registro_quiebras q
        WHERE q.empleado IN (
            $subquery_sql
        ) 
        AND CONCAT(q.fecha, ' ', q.hora) >= ? 
        AND CONCAT(q.fecha, ' ', q.hora) < DATE_ADD(?, INTERVAL 1 HOUR)
    ";
    
    // Combinar params: sub_params primero (aparecen primero en SQL), luego params principales
    $params = array_merge($sub_params, [$hora, $hora]);
    $types = $sub_types . "ss";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            // Turno C: manejar cruce de medianoche
            $sql .= " AND (";
            if ($hora_base >= '21:31:00' || $hora_base < '06:00:00') {
                $sql .= " (CONCAT(q.fecha, ' ', q.hora) >= ? AND TIME(CONCAT(q.fecha, ' ', q.hora)) >= ?) OR (CONCAT(q.fecha, ' ', q.hora) >= DATE_SUB(?, INTERVAL 1 DAY) AND TIME(CONCAT(q.fecha, ' ', q.hora)) <= ?)";
                $params = array_merge($params, [$fecha_base, $time_start, $fecha_base, $time_end]);
                $types .= "ssss";
            } else {
                $sql .= " TIME(CONCAT(q.fecha, ' ', q.hora)) BETWEEN ? AND ?";
                $params = array_merge($params, [$time_start, $time_end]);
                $types .= "ss";
            }
            $sql .= ")";
        } else {
            $sql .= " AND TIME(CONCAT(q.fecha, ' ', q.hora)) BETWEEN ? AND ?";
            $params = array_merge($params, [$time_start, $time_end]);
            $types .= "ss";
        }
    }
    
    if ($empleado) {
        $sql .= " AND q.empleado = ?";
        $params[] = $empleado;
        $types .= "s";
    }
    
    $sql .= " ORDER BY q.empleado, q.fecha DESC, q.hora DESC";
    
    $stmt = $this->conn->prepare($sql);
    if (!$stmt) {
        error_log("Error al preparar la consulta en getQuiebrasDetails: " . $this->conn->error);
        throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
    }
    
    error_log("getQuiebrasDetails params: equipo=$equipo, hora=$hora, empleado=$empleado, types=$types, params=" . json_encode($params));
    
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
    
    error_log("getQuiebrasDetails registros encontrados: " . count($data));
    return $data;
}
    
    /**
     * Renderizar modal de detalles por hora
     */
    private function renderHourDetailsModal($produccion_data, $quiebras_data, $equipo, $hora, $empleado = null) {
        ob_start();
        ?>
        <h6>Producción por Empleado</h6>
        <?php if (!empty($produccion_data)): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID Producción</th>
                            <th>Empleado</th>
                            <th>Área</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produccion_data as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['id']) ?></td>
                            <td><?= htmlspecialchars($item['empleado']) ?></td>
                            <td><?= htmlspecialchars($item['area']) ?></td>
                            <td><?= htmlspecialchars($item['fecha']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                No hay registros de producción para <?= htmlspecialchars($equipo) ?> <?php echo $empleado ? ' - ' . htmlspecialchars($empleado) : ''; ?> en la hora <?= htmlspecialchars($hora) ?>.
            </div>
        <?php endif; ?>

        <h6>Quiebras por Empleado</h6>
        <?php if (!empty($quiebras_data)): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Motivo</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quiebras_data as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['empleado']) ?></td>
                            <td><?= htmlspecialchars($item['motivo']) ?></td>
                            <td><?= htmlspecialchars($item['fecha_completa']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                No hay registros de quiebras para <?= htmlspecialchars($equipo) ?> <?php echo $empleado ? ' - ' . htmlspecialchars($empleado) : ''; ?> en la hora <?= htmlspecialchars($hora) ?>.
            </div>
        <?php endif; ?>
        <?php
        echo ob_get_clean();
    }
    
/**
 * Calcular horas de jornada según el turno
 */
public function calcularHorasJornada($datetime_inicio) {
    // Obtener la hora de inicio
    $hora_inicio = date('H:i', strtotime($datetime_inicio));
    
    // Determinar el turno basado en la hora de inicio
    if ($hora_inicio >= '06:00' && $hora_inicio < '14:00') {
        // Turno A: 6am - 2pm (8 horas) menos 45 minutos de descanso = 7.25 horas
        return 7.25;
    } elseif ($hora_inicio >= '14:00' && $hora_inicio < '21:30') {
        // Turno B: 2pm - 9:30pm (7.5 horas) menos 45 minutos de descanso = 6.75 horas
        return 6.75;
    } else {
        // Turno C: 9:30pm - 6am (8.5 horas) menos 45 minutos de descanso = 7.75 horas
        return 7.75;
    }
}

/**
 * Obtener datos de metas vs producción por equipo (VERSIÓN MEJORADA CON TURNOS)
 */
public function getMetasData($datetime_inicio, $datetime_fin) {
    global $turno, $original_fecha_inicio, $original_fecha_fin, $hora_inicio, $hora_fin;

    // Calcular número de días usando original_fecha_fin
    $num_days = 1;
    if ($original_fecha_inicio != $original_fecha_fin) {
        $start = new DateTime($original_fecha_inicio);
        $end = new DateTime($original_fecha_fin);
        $num_days = $start->diff($end)->days + 1;
    }

    // Calcular horas de jornada según el turno
    $horas_jornada = $this->calcularHorasJornada($datetime_inicio) * $num_days;
    
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    // Construir condiciones de tiempo para producción y quiebras
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
            $horas_jornada AS horas_jornada,
            (e.meta_hora_persona * $horas_jornada) AS meta_jornada,

            -- Producción real
            COALESCE(p.producido, 0) AS producido,

            -- Quiebras optimizadas
            COALESCE(q.total_quiebras, 0) AS total_quiebras,
            (COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0)) AS producido_neto,

            -- Diferencia y porcentajes
            (COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0) - (e.meta_hora_persona * $horas_jornada)) AS diferencia_jornada,
            
            CASE 
                WHEN COALESCE(p.producido, 0) > 0 
                THEN ROUND((COALESCE(q.total_quiebras, 0) / COALESCE(p.producido, 0)) * 100, 2)
                ELSE 0
            END AS porcentaje_quiebras,
            
            CASE 
                WHEN (e.meta_hora_persona * $horas_jornada) > 0 
                THEN ROUND(((COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0)) / (e.meta_hora_persona * $horas_jornada)) * 100, 2)
                ELSE 0
            END AS porcentaje_avance_jornada,
            
            CASE 
                WHEN (COALESCE(p.producido, 0) - COALESCE(q.total_quiebras, 0)) >= (e.meta_hora_persona * $horas_jornada) 
                THEN '✅ Meta alcanzada'
                ELSE '❌ No alcanzada'
            END AS estado_jornada

        FROM equipos e

        -- Producción optimizada
        LEFT JOIN (
            SELECT equipo, COUNT(*) AS producido
            FROM (
                SELECT equipo FROM produccion WHERE fecha BETWEEN ? AND ? $time_condition_prod
                UNION ALL
                SELECT equipo FROM registros_antiguos WHERE fecha BETWEEN ? AND ? $time_condition_prod
            ) AS produccion_combinada
            GROUP BY equipo
        ) p ON e.nombre_equipo = p.equipo

        -- Quiebras optimizadas
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
        // Para producción (2 UNIONs, 2 params cada uno) + empleados (2 UNIONs, 2 params cada uno) + quiebras (2 params)
        // Total extra: 4 (prod) + 4 (empleados) + 2 (quiebras) = 10 extras
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
     * Obtener datos por hora para todos los empleados (incluye registros_antiguos) - VERSIÓN CORREGIDA
     */
public function getHourlyData($datetime_inicio, $datetime_fin) {
    global $turno, $hora_inicio, $hora_fin;
    
    error_log("Ejecutando getHourlyData con datetime_inicio: $datetime_inicio, datetime_fin: $datetime_fin, turno: $turno, hora_inicio: $hora_inicio, hora_fin: $hora_fin");
    
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    // Construir condiciones de tiempo
    $time_condition_prod = "";
    $time_condition_quiebra = "";
    $params = [];
    $types = "";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            // Turno C cruza medianoche - necesita manejo especial
            $time_condition_prod = " AND (TIME(p.fecha) >= ? OR TIME(p.fecha) <= ?)";
            $time_condition_quiebra = " AND (TIME(CONCAT(q.fecha, ' ', q.hora)) >= ? OR TIME(CONCAT(q.fecha, ' ', q.hora)) <= ?)";
        } else {
            // Turnos A y B - rango normal
            $time_condition_prod = " AND TIME(p.fecha) BETWEEN ? AND ?";
            $time_condition_quiebra = " AND TIME(CONCAT(q.fecha, ' ', q.hora)) BETWEEN ? AND ?";
        }
    }
    
    $sql = "
        SELECT 
            p.empleado,
            p.equipo,
            e.meta_hora_persona,
            DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00:00') AS hora,
            COUNT(p.id) AS producido,
            COALESCE(q.total_quiebras, 0) AS total_quiebras,
            (COUNT(p.id) - COALESCE(q.total_quiebras, 0)) AS producido_neto,
            CASE 
                WHEN e.meta_hora_persona > 0 
                THEN ROUND(((COUNT(p.id) - COALESCE(q.total_quiebras, 0)) / e.meta_hora_persona) * 100, 2)
                ELSE 0
            END AS porcentaje_avance
        FROM (
            SELECT * FROM produccion
            UNION ALL
            SELECT * FROM registros_antiguos
        ) AS p
        LEFT JOIN equipos e ON p.equipo = e.nombre_equipo
        LEFT JOIN (
            SELECT 
                q.empleado,
                DATE_FORMAT(CONCAT(q.fecha, ' ', q.hora), '%Y-%m-%d %H:00:00') AS hora_quiebra, 
                COUNT(DISTINCT q.id) AS total_quiebras
            FROM registro_quiebras q
            WHERE CONCAT(q.fecha, ' ', q.hora) BETWEEN ? AND ?
            " . ($turno !== 'general' ? $time_condition_quiebra : "") . "
            GROUP BY q.empleado, DATE_FORMAT(CONCAT(q.fecha, ' ', q.hora), '%Y-%m-%d %H:00:00')
        ) q ON p.empleado = q.empleado
            AND DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00:00') = q.hora_quiebra
        WHERE p.fecha BETWEEN ? AND ?
        " . ($turno !== 'general' ? $time_condition_prod : "") . "
            AND e.meta_hora_persona IS NOT NULL
        GROUP BY p.empleado, p.equipo, DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00:00'), e.meta_hora_persona
        ORDER BY p.equipo, p.empleado, hora ASC
    ";
    
    // Preparar parámetros
    $params = [$datetime_inicio, $datetime_fin]; // Para quiebras
    $types = "ss";
    
    if ($turno !== 'general') {
        // Agregar parámetros de tiempo para quiebras
        $params = array_merge($params, [$time_start, $time_end]);
        $types .= "ss";
    }
    
    // Agregar parámetros para producción
    $params = array_merge($params, [$datetime_inicio, $datetime_fin]);
    $types .= "ss";
    
    if ($turno !== 'general') {
        // Agregar parámetros de tiempo para producción
        $params = array_merge($params, [$time_start, $time_end]);
        $types .= "ss";
    }
    
    $stmt = $this->conn->prepare($sql);
    if (!$stmt) {
        error_log("Error al preparar la consulta en getHourlyData: " . $this->conn->error);
        throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
    }
    
    // Vincular parámetros
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        error_log("Error al ejecutar la consulta en getHourlyData: " . $stmt->error);
        throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    error_log("Registros obtenidos en getHourlyData: " . count($data));
    $stmt->close();
    
    return $data;
}
    
    /**
     * Obtener detalles del equipo seleccionado, opcionalmente filtrado por empleado (producción incluye registros_antiguos)
     */
    public function getTeamDetails($equipo_seleccionado, $datetime_inicio, $datetime_fin, $empleado_seleccionado = null) {
        $details = [
            'areas_ordenes' => [],
            'empleados_produccion' => [],
            'quiebras_empleado' => [],
            'horas_equipo' => []
        ];
        
        try {
            global $turno, $hora_inicio, $hora_fin;
            
            $time_start = $hora_inicio . ':00';
            $time_end = $hora_fin . ':00';
            
            $time_condition_prod = "";
            if ($turno !== 'general') {
                if ($turno === 'C') {
                    $time_condition_prod = " AND (TIME(p.fecha) >= ? OR TIME(p.fecha) <= ?)";
                } else {
                    $time_condition_prod = " AND TIME(p.fecha) BETWEEN ? AND ?";
                }
            }
            
            // Producción por área
            $sql = "
                SELECT p.area, DATE(p.fecha) as fecha, COUNT(*) as cantidad
                FROM (
                    SELECT * FROM produccion
                    UNION ALL
                    SELECT * FROM registros_antiguos
                ) AS p
                WHERE p.equipo = ? AND p.fecha BETWEEN ? AND ? $time_condition_prod
            ";
            if ($empleado_seleccionado) {
                $sql .= " AND p.empleado = ?";
            }
            $sql .= " GROUP BY p.area, DATE(p.fecha) ORDER BY p.area, p.fecha DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Error al preparar la consulta en getTeamDetails (areas): " . $this->conn->error);
                throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
            }
            
            $params = [$equipo_seleccionado, $datetime_inicio, $datetime_fin];
            $types = "sss";
            
            if ($turno !== 'general') {
                $params = array_merge($params, [$time_start, $time_end]);
                $types .= "ss";
            }
            
            if ($empleado_seleccionado) {
                $params[] = $empleado_seleccionado;
                $types .= "s";
            }
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $details['areas_ordenes'][] = $row;
            }
            $stmt->close();

            // Producción individual por empleado
            $sql = "
                SELECT p.empleado, p.area, COUNT(*) AS cantidad
                FROM (
                    SELECT * FROM produccion
                    UNION ALL
                    SELECT * FROM registros_antiguos
                ) AS p
                WHERE p.equipo = ? AND p.fecha BETWEEN ? AND ? $time_condition_prod
            ";
            if ($empleado_seleccionado) {
                $sql .= " AND p.empleado = ?";
            }
            $sql .= " GROUP BY p.empleado, p.area ORDER BY p.empleado";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log("Error al preparar la consulta en getTeamDetails (empleados): " . $this->conn->error);
                throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
            }
            
            $params = [$equipo_seleccionado, $datetime_inicio, $datetime_fin];
            $types = "sss";
            
            if ($turno !== 'general') {
                $params = array_merge($params, [$time_start, $time_end]);
                $types .= "ss";
            }
            
            if ($empleado_seleccionado) {
                $params[] = $empleado_seleccionado;
                $types .= "s";
            }
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $details['empleados_produccion'][] = $row;
            }
            $stmt->close();
            
            // Obtener empleados únicos del equipo
            $empleados = $empleado_seleccionado ? [$empleado_seleccionado] : $this->getTeamEmployees($equipo_seleccionado, $datetime_inicio, $datetime_fin);
            
            // Quiebras por empleado
            if (!empty($empleados)) {
                $details['quiebras_empleado'] = $this->getEmployeeBreakages($empleados, $datetime_inicio, $datetime_fin);
            }

            // Producción por hora
            $details['horas_equipo'] = $this->getTeamHourlyProduction($equipo_seleccionado, $datetime_inicio, $datetime_fin, $empleado_seleccionado);
            
        } catch (Exception $e) {
            error_log("Error en getTeamDetails: " . $e->getMessage());
            throw $e;
        }
        
        return $details;
    }

/**
 * Obtener producción por hora de un equipo específico con detalles de empleados (incluye registros_antiguos) - VERSIÓN CORREGIDA
 */
private function getTeamHourlyProduction($equipo_seleccionado, $datetime_inicio, $datetime_fin, $empleado_seleccionado = null) {
    global $turno, $hora_inicio, $hora_fin;
    
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    $time_condition_prod = "";
    $time_condition_quiebra = "";
    $params = [];
    $types = "";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            $time_condition_prod = " AND (TIME(p.fecha) >= ? OR TIME(p.fecha) <= ?)";
            $time_condition_quiebra = " AND (TIME(CONCAT(q.fecha, ' ', q.hora)) >= ? OR TIME(CONCAT(q.fecha, ' ', q.hora)) <= ?)";
        } else {
            $time_condition_prod = " AND TIME(p.fecha) BETWEEN ? AND ?";
            $time_condition_quiebra = " AND TIME(CONCAT(q.fecha, ' ', q.hora)) BETWEEN ? AND ?";
        }
    }
    
    $sql = "
        SELECT 
            p.empleado,
            DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00:00') AS hora,
            p.equipo,
            COUNT(p.id) AS producido,
            COALESCE(q.total_quiebras, 0) AS total_quiebras,
            (COUNT(p.id) - COALESCE(q.total_quiebras, 0)) AS producido_neto,
            e.meta_hora_persona,
            CASE 
                WHEN e.meta_hora_persona > 0 
                THEN ROUND(((COUNT(p.id) - COALESCE(q.total_quiebras, 0)) / e.meta_hora_persona) * 100, 2)
                ELSE 0
            END AS porcentaje_avance
        FROM (
            SELECT * FROM produccion
            UNION ALL
            SELECT * FROM registros_antiguos
        ) AS p
        LEFT JOIN equipos e ON p.equipo = e.nombre_equipo
        LEFT JOIN (
            SELECT 
                q.empleado,
                DATE_FORMAT(CONCAT(q.fecha, ' ', q.hora), '%Y-%m-%d %H:00:00') AS hora_quiebra, 
                COUNT(DISTINCT q.id) AS total_quiebras
            FROM registro_quiebras q 
            WHERE CONCAT(q.fecha, ' ', q.hora) BETWEEN ? AND ? " . ($turno !== 'general' ? $time_condition_quiebra : "") . "
            GROUP BY q.empleado, DATE_FORMAT(CONCAT(q.fecha, ' ', q.hora), '%Y-%m-%d %H:00:00')
        ) q ON p.empleado = q.empleado 
            AND DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00:00') = q.hora_quiebra
        WHERE p.equipo = ? AND p.fecha BETWEEN ? AND ? " . ($turno !== 'general' ? $time_condition_prod : "") . "
    ";
    
    if ($empleado_seleccionado) {
        $sql .= " AND p.empleado = ?";
    }
    
    $sql .= " GROUP BY p.empleado, DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00:00'), p.equipo, e.meta_hora_persona
              ORDER BY p.empleado, hora ASC";
    
    // Preparar parámetros
    $params = [$datetime_inicio, $datetime_fin]; // Para quiebras
    $types = "ss";
    
    if ($turno !== 'general') {
        // Agregar parámetros de tiempo para quiebras
        $params = array_merge($params, [$time_start, $time_end]);
        $types .= "ss";
    }
    
    // Agregar parámetros para equipo y producción
    $params = array_merge($params, [$equipo_seleccionado, $datetime_inicio, $datetime_fin]);
    $types .= "sss";
    
    if ($turno !== 'general') {
        // Agregar parámetros de tiempo para producción
        $params = array_merge($params, [$time_start, $time_end]);
        $types .= "ss";
    }
    
    if ($empleado_seleccionado) {
        $params[] = $empleado_seleccionado;
        $types .= "s";
    }
    
    $stmt = $this->conn->prepare($sql);
    if (!$stmt) {
        error_log("Error al preparar la consulta en getTeamHourlyProduction: " . $this->conn->error);
        throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log("Error al ejecutar la consulta en getTeamHourlyProduction: " . $stmt->error);
        throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $horas = [];
    while ($row = $result->fetch_assoc()) {
        $horas[] = $row;
    }
    $stmt->close();
    
    return $horas;
}
    
    /**
     * Obtener lista de empleados únicos para un equipo en un rango de fechas (incluye registros_antiguos)
     */
    private function getTeamEmployees($equipo_seleccionado, $datetime_inicio, $datetime_fin) {
        global $turno, $hora_inicio, $hora_fin;
        
        $time_start = $hora_inicio . ':00';
        $time_end = $hora_fin . ':00';
        
        $time_condition = "";
        if ($turno !== 'general') {
            if ($turno === 'C') {
                $time_condition = " AND (TIME(p.fecha) >= ? OR TIME(p.fecha) <= ?)";
            } else {
                $time_condition = " AND TIME(p.fecha) BETWEEN ? AND ?";
            }
        }
        
        $sql = "
            SELECT DISTINCT p.empleado
            FROM (
                SELECT * FROM produccion
                UNION ALL
                SELECT * FROM registros_antiguos
            ) AS p
            WHERE p.equipo = ? 
                AND p.fecha BETWEEN ? AND ? $time_condition
            ORDER BY p.empleado ASC
        ";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta en getTeamEmployees: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        
        $params = [$equipo_seleccionado, $datetime_inicio, $datetime_fin];
        $types = "sss";
        
        if ($turno !== 'general') {
            $params = array_merge($params, [$time_start, $time_end]);
            $types .= "ss";
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getTeamEmployees: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        $result = $stmt->get_result();
        
        $empleados = [];
        while ($row = $result->fetch_assoc()) {
            $empleados[] = $row['empleado'];
        }
        
        $stmt->close();
        return $empleados;
    }
    
    /**
     * Obtener quiebras por empleados en un rango de fechas
     */
    private function getEmployeeBreakages($empleados, $datetime_inicio, $datetime_fin) {
        if (empty($empleados)) {
            return [];
        }
        
        global $turno, $hora_inicio, $hora_fin;
        
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
        
        // Crear placeholders para la lista de empleados
        $placeholders = implode(',', array_fill(0, count($empleados), '?'));
        
        $sql = "
            SELECT 
                q.empleado,
                q.motivo,
                CONCAT(q.fecha, ' ', q.hora) AS fecha_completa
            FROM registro_quiebras q
            WHERE q.empleado IN ($placeholders)
                AND CONCAT(q.fecha, ' ', q.hora) BETWEEN ? AND ? $time_condition
            ORDER BY q.empleado, q.fecha DESC, q.hora DESC
        ";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta en getEmployeeBreakages: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        
        // Preparar los parámetros para bind_param
        $types = str_repeat('s', count($empleados)) . 'ss';
        $params = array_merge($empleados, [$datetime_inicio, $datetime_fin]);
        
        if ($turno !== 'general') {
            $params = array_merge($params, [$time_start, $time_end]);
            $types .= 'ss';
        }
        
        // Usar call_user_func_array para bind_param dinámico
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getEmployeeBreakages: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $quiebras = [];
        while ($row = $result->fetch_assoc()) {
            $quiebras[] = $row;
        }
        
        $stmt->close();
        return $quiebras;
    }
    
    /**
     * Obtener lista de equipos disponibles
     */
    public function getAvailableTeams() {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT equipo 
            FROM (
                SELECT equipo FROM produccion 
                UNION 
                SELECT equipo FROM registros_antiguos
                UNION
                SELECT nombre_equipo AS equipo FROM equipos
            ) AS equipos_total 
            ORDER BY equipo
        ");
        if (!$stmt) {
            error_log("Error al preparar la consulta en getAvailableTeams: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getAvailableTeams: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        $result = $stmt->get_result();
        return $result;
    }
    
    /**
     * Obtener metas actuales de los equipos
     */
    public function getCurrentMetas() {
        $stmt = $this->conn->prepare("SELECT nombre_equipo, meta_hora_persona FROM equipos WHERE meta_hora_persona IS NOT NULL");
        if (!$stmt) {
            error_log("Error al preparar la consulta en getCurrentMetas: " . $this->conn->error);
            throw new Exception("Error al preparar la consulta SQL: " . $this->conn->error);
        }
        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta en getCurrentMetas: " . $stmt->error);
            throw new Exception("Error al ejecutar la consulta SQL: " . $stmt->error);
        }
        $result = $stmt->get_result();
        
        $metas = [];
        while ($row = $result->fetch_assoc()) {
            $metas[$row['nombre_equipo']] = $row['meta_hora_persona'];
        }
        $stmt->close();
        
        return $metas;
    }
}

// Inicializar el controlador
$controller = new MetasController($conn);

// PROCESAR ENTRADA Y VALIDACIONES PRIMERO (CORRECCIÓN: Antes de handle AJAX para setear globals)
$inputs = $controller->validateAndSanitizeInputs();
extract($inputs);

$original_fecha_inicio = $fecha_inicio;
$original_fecha_fin = $fecha_fin;

// Calcular número de días usando fechas originales
$num_days = 1;
if ($original_fecha_inicio != $original_fecha_fin) {
    $start = new DateTime($original_fecha_inicio);
    $end = new DateTime($original_fecha_fin);
    $num_days = $start->diff($end)->days + 1;
}

if ($turno !== 'general' && isset(TURNOS_HORARIOS[$turno])) {
    $horario = TURNOS_HORARIOS[$turno];
    $hora_inicio = $horario['inicio'];
    $hora_fin = $horario['fin'];
    
    // Manejo especial para turno C que cruza medianoche (ajuste hacia atrás)
    if ($turno === 'C') {
        $fecha_inicio = date('Y-m-d', strtotime($fecha_inicio . ' -1 day'));
    }
}

$datetime_inicio = $fecha_inicio . ' ' . $hora_inicio . ':00';
$datetime_fin = $fecha_fin . ' ' . $hora_fin . ':00';

// AHORA MANEJAR AJAX (globals ya están seteados)
$controller->handleHourDetailsAjax();

// Mostrar mensaje de éxito desde la sesión
if (isset($_SESSION['mensaje_exito'])) {
    $mensaje = '<div class="alert alert-success">' . htmlspecialchars($_SESSION['mensaje_exito']) . '</div>';
    unset($_SESSION['mensaje_exito']);
}

// Obtener datos principales
$equipo_detalle = null;
$team_details = [];
$empleado_seleccionado = null;

if (isset($_GET['ver_equipo']) && empty($mensaje)) {
    $equipo_seleccionado = filter_input(INPUT_GET, 'ver_equipo', FILTER_SANITIZE_STRING);
    $empleado_seleccionado = filter_input(INPUT_GET, 'ver_empleado', FILTER_SANITIZE_STRING) ?: null;
    try {
        $team_details = $controller->getTeamDetails($equipo_seleccionado, $datetime_inicio, $datetime_fin, $empleado_seleccionado);
        $equipo_detalle = $equipo_seleccionado;
    } catch (Exception $e) {
        error_log("Error al consultar detalles del equipo: " . $e->getMessage());
        $mensaje = '<div class="alert alert-danger">Error al consultar los detalles del equipo: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Obtener datos de metas y producción
try {
    $resultado_metas = $controller->getMetasData($datetime_inicio, $datetime_fin);
    $horas_data = $controller->getHourlyData($datetime_inicio, $datetime_fin);
    $equipos_meta = $controller->getAvailableTeams();
    $metas_actuales = $controller->getCurrentMetas();
} catch (Exception $e) {
    error_log("Error al obtener datos principales: " . $e->getMessage());
    $mensaje = '<div class="alert alert-danger">Error al consultar datos: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Preparar datos para gráficos
$labels = [];
$metas = [];
$producciones = [];
$quiebras = [];
$porcentajes_avance = [];

if (isset($resultado_metas) && $resultado_metas->num_rows > 0) {
    $resultado_metas->data_seek(0);
    while ($row = $resultado_metas->fetch_assoc()) {
        $labels[] = htmlspecialchars($row['equipo']);
        $metas[] = (int)$row['meta_jornada'];
        $producciones[] = (int)$row['producido_neto'];
        $quiebras[] = (int)$row['total_quiebras'];
        $porcentajes_avance[] = (float)$row['porcentaje_avance_jornada'];
    }
    $resultado_metas->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎯 Control de Metas/Produccion por Equipo/Persona</title>
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-green: #155724;
            --secondary-green: #0c3318;
            --accent-green: #28a745;
            --light-green: #90EE90;
            --dark-bg: #212529;
        }

        body {
            background-color: var(--dark-bg);
            color: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
        }

        .metas-container {
            background-color: var(--primary-green);
            padding: 25px;
            border-radius: 15px;
            margin: 30px auto;
            max-width: 1400px;
            width: 95%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }

        .header {
            background: linear-gradient(135deg, rgba(0, 51, 0, 0.95), rgba(21, 87, 36, 0.95));
            backdrop-filter: blur(10px);
            border-bottom: 3px solid var(--accent-green);
            padding: 20px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 25px;
        }

        .logo-container img {
            height: 65px;
            filter: brightness(1.2) drop-shadow(0 3px 6px rgba(0,0,0,0.4));
            transition: transform 0.3s ease;
        }

        .logo-container img:hover {
            transform: scale(1.05);
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .clock {
            font-size: 20px;
            font-weight: 700;
            color: var(--light-green);
            text-shadow: 0 2px 4px rgba(0,0,0,0.6);
            padding: 8px 15px;
            background: rgba(0,0,0,0.2);
            border-radius: 8px;
        }

        .logout-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.5);
            color: white;
        }

        .filtros-container {
            background: linear-gradient(135deg, var(--secondary-green), rgba(12, 51, 24, 0.8));
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 1px solid var(--accent-green);
        }

        .filtros-container h4 {
            margin-bottom: 20px;
            color: var(--light-green);
            font-weight: 600;
        }

        .filtro-grupo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .filtro-grupo label {
            min-width: 120px;
            margin-bottom: 0;
            font-weight: 500;
            color: #e9ecef;
        }

        .filtro-grupo input, .filtro-grupo select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            font-size: 14px;
            background-color: white;
            transition: border-color 0.3s ease;
        }

        .filtro-grupo input:focus, .filtro-grupo select:focus {
            border-color: var(--accent-green);
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
            outline: 0;
        }

        .btn-primary, .btn-success {
            background: linear-gradient(135deg, var(--accent-green), #1e7e34);
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }

        .btn-primary:hover, .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
            background: linear-gradient(135deg, #1e7e34, #155724);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            border: none;
            transition: all 0.3s ease;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #138496, #117a8b);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #212529;
            border: none;
            transition: all 0.3s ease;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #e0a800, #d39e00);
            color: #212529;
        }

        .table-wrapper {
            max-height: 500px;
            overflow-y: auto;
            overflow-x: auto;
            margin-bottom: 25px;
            border-radius: 10px;
            border: 2px solid var(--accent-green);
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }

        .table-wrapper::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .table-wrapper::-webkit-scrollbar-track {
            background: var(--secondary-green);
            border-radius: 5px;
        }

        .table-wrapper::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--accent-green), #1e7e34);
            border-radius: 5px;
        }

        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #1e7e34, #155724);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .table th {
            background: linear-gradient(135deg, var(--secondary-green), rgba(12, 51, 24, 0.9));
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 15px 10px;
            text-align: center;
            font-weight: 700;
            border-bottom: 2px solid var(--accent-green);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 12px 10px;
            text-align: center;
            border-bottom: 1px solid rgba(40, 167, 69, 0.3);
            background-color: rgba(255, 255, 255, 0.08);
            transition: background-color 0.3s ease;
        }

        .table tr:hover td {
            background-color: rgba(40, 167, 69, 0.15);
        }

        .meta-alcanzada {
            background-color: rgba(40, 167, 69, 0.25) !important;
            border-left: 4px solid var(--accent-green);
        }

        .meta-no-alcanzada {
            background-color: rgba(220, 53, 69, 0.25) !important;
            border-left: 4px solid #dc3545;
        }

        .porcentaje-bajo { color: #ff6b6b; font-weight: 600; }
        .porcentaje-medio { color: #ffd93d; font-weight: 600; }
        .porcentaje-alto { color: #6bcf7f; font-weight: 600; }

        .graficos-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
            min-height: 400px;
        }

        .grafico {
            background: linear-gradient(135deg, var(--secondary-green), rgba(12, 51, 24, 0.8));
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
            border: 1px solid var(--accent-green);
            height: 400px;
            position: relative;
        }

        .grafico h4 {
            margin-bottom: 20px;
            text-align: center;
            color: var(--light-green);
            font-weight: 600;
        }

        .grafico canvas {
            width: 100% !important;
            height: 300px !important;
        }

        .equipo-detalles {
            background: linear-gradient(135deg, var(--secondary-green), rgba(12, 51, 24, 0.8));
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 2px solid var(--accent-green);
        }

        .equipo-detalles h3 {
            margin-bottom: 20px;
            color: var(--light-green);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
        }

        .btn-volver {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-volver:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #5a6268, #495057);
            color: white;
        }

        .tabla-detalles {
            margin-bottom: 25px;
        }

        .tabla-detalles h4 {
            margin-bottom: 15px;
            color: var(--light-green);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-content {
            background-color: var(--dark-bg);
            color: white;
            border: 1px solid var(--accent-green);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--secondary-green), rgba(12, 51, 24, 0.9));
            border-bottom: 2px solid var(--accent-green);
        }

        .modal-footer {
            border-top: 2px solid var(--accent-green);
        }

        .alert {
            border-radius: 8px;
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.2);
            border-color: var(--accent-green);
            color: #d4edda;
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.2);
            border-color: #dc3545;
            color: #f8d7da;
        }

        .alert-info {
            background-color: rgba(23, 162, 184, 0.2);
            border-color: #17a2b8;
            color: #d1ecf1;
        }

        /* Responsividad mejorada */
        @media (max-width: 1200px) {
            .graficos-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .header-content {
                flex-direction: column;
                gap: 20px;
            }
            
            .filtro-grupo {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filtro-grupo label {
                min-width: auto;
            }
        }

        @media (max-width: 768px) {
            .metas-container {
                width: 98%;
                padding: 15px;
            }
            
            .table-wrapper {
                max-height: 350px;
            }
            
            .table th, .table td {
                padding: 8px 5px;
                font-size: 0.85rem;
            }
        }

        /* Animaciones */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .metas-container {
            animation: fadeIn 0.5s ease-out;
        }

        /* Loading spinner personalizado */
        .custom-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(40, 167, 69, 0.3);
            border-radius: 50%;
            border-top-color: var(--accent-green);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Footer fijo ajustado */
        .footer {
            background: linear-gradient(135deg, var(--secondary-green), var(--primary-green));
            color: var(--light-green);
            text-align: center;
            padding: 15px 20px;
            font-size: 0.9rem;
            border-top: 1px solid rgba(212, 252, 212, 0.2);
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 60;
            height: 70px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 -3px 10px rgba(0,0,0,0.3);
        }

        .footer p {
            margin: 0;
            opacity: 0.9;
        }

        .footer .developer {
            font-size: 0.8rem;
            margin-top: 5px;
            opacity: 0.7;
            font-style: italic;
        }
    </style>
</head>
<body>
    <!-- Encabezado mejorado -->
    <header class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="../img/logo.png" alt="Logo Empresa">
            </div>
            <div class="header-info">
                <div class="clock" id="reloj"></div>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <!-- Contenedor principal -->
    <div class="metas-container">
        <h2 class="text-center mb-4">
            <i class="fas fa-bullseye"></i> Gestión de Metas por Equipo
        </h2>
        
        <!-- Mostrar mensajes -->
        <?php if (!empty($mensaje)): ?>
            <?php echo $mensaje; ?>
        <?php endif; ?>

<!-- Formulario de Filtros -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0"><i class="fas fa-filter"></i> Filtros</h4>
    </div>
    <div class="card-body">
        <form id="filtrosForm" method="GET" action="">
            <!-- Campos del formulario: fecha_inicio, fecha_fin, hora_inicio, hora_fin, turno -->
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                    <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?php echo htmlspecialchars($original_fecha_inicio); ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="fecha_fin" class="form-label">Fecha Fin</label>
                    <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?php echo htmlspecialchars($original_fecha_fin); ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="hora_inicio" class="form-label">Hora Inicio</label>
                    <input type="time" class="form-control" id="hora_inicio" name="hora_inicio" value="<?php echo htmlspecialchars($hora_inicio); ?>" required <?php echo $turno !== 'general' ? 'readonly' : ''; ?>>
                </div>
                <div class="col-md-2">
                    <label for="hora_fin" class="form-label">Hora Fin</label>
                    <input type="time" class="form-control" id="hora_fin" name="hora_fin" value="<?php echo htmlspecialchars($hora_fin); ?>" required <?php echo $turno !== 'general' ? 'readonly' : ''; ?>>
                </div>
                <div class="col-md-2">
                    <label for="turno" class="form-label">Turno</label>
                    <select class="form-control" id="turno" name="turno" onchange="adjustHours(this.value)">
                        <option value="general" <?php echo $turno === 'general' ? 'selected' : ''; ?>>General</option>
                        <option value="A" <?php echo $turno === 'A' ? 'selected' : ''; ?>>Turno A (06:01-14:00)</option>
                        <option value="B" <?php echo $turno === 'B' ? 'selected' : ''; ?>>Turno B (14:01-21:30)</option>
                        <option value="C" <?php echo $turno === 'C' ? 'selected' : ''; ?>>Turno C (21:31-06:00)</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </div>
        </form>
        <!-- NUEVO: Botón para descargar el reporte de incentivos -->
        <a href="export_csv.php?<?php echo getFilterParams(); ?>" class="btn btn-success mt-2">
            <i class="fas fa-download"></i> Descargar Reporte de Incentivos (CSV)
        </a>
    </div>
</div>

<!-- Mensaje de filtros aplicados -->
<div class="alert alert-info mt-3">
    <i class="fas fa-filter"></i> Filtros aplicados: 
    Fecha desde <?= htmlspecialchars($original_fecha_inicio) ?> hasta <?= htmlspecialchars($original_fecha_fin) ?>, 
    Hora desde <?= htmlspecialchars($hora_inicio) ?> hasta <?= htmlspecialchars($hora_fin) ?>, 
    Turno: <?= htmlspecialchars(ucfirst($turno)) ?> <?= ($turno === 'general' ? '(todo el día)' : '') ?>
</div>

        <!-- Gráficos mejorados -->
        <div class="graficos-container">
            <div class="grafico">
                <h4><i class="fas fa-chart-bar"></i> Producción vs Meta por Equipo</h4>
                <canvas id="graficoMetas"></canvas>
            </div>
            <div class="grafico">
                <h4><i class="fas fa-chart-line"></i> Porcentaje de Avance por Equipo</h4>
                <canvas id="graficoAvance"></canvas>
            </div>
        </div>

        <!-- Tabla de resumen de metas vs producción -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0"><i class="fas fa-table"></i> Resumen de Metas vs Producción por Equipo</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-wrapper">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th><i class="fas fa-users"></i> Equipo</th>
                                <th><i class="fas fa-bullseye"></i> Meta/Hora</th>
                                <th><i class="fas fa-calendar-day"></i> Meta Jornada</th>
                                <th><i class="fas fa-industry"></i> Producción Total</th>
                                <th><i class="fas fa-exclamation-triangle"></i> Quiebras</th>
                                <th><i class="fas fa-calculator"></i> Prod. Neto</th>
                                <th><i class="fas fa-balance-scale"></i> Diferencia</th>
                                <th><i class="fas fa-percentage"></i> % Quiebras</th>
                                <th><i class="fas fa-chart-line"></i> % Avance</th>
                                <th><i class="fas fa-flag"></i> Estado</th>
                                <th><i class="fas fa-cogs"></i> Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (isset($resultado_metas) && $resultado_metas->num_rows > 0): ?>
                            <?php while ($row = $resultado_metas->fetch_assoc()): ?>
                                <?php
                                    $clase_fila = ($row['porcentaje_avance_jornada'] >= 100) ? 'meta-alcanzada' : 'meta-no-alcanzada';
                                    $clase_porcentaje = $row['porcentaje_avance_jornada'] < 70 ? 'porcentaje-bajo' : ($row['porcentaje_avance_jornada'] < 90 ? 'porcentaje-medio' : 'porcentaje-alto');
                                    $clase_diferencia = ($row['diferencia_jornada'] >= 0) ? 'text-success' : 'text-danger';
                                ?>
                                <tr class="<?php echo $clase_fila; ?>">
                                    <td><strong><?php echo htmlspecialchars($row['equipo']); ?></strong></td>
                                    <td><?php echo number_format($row['meta_hora'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['meta_jornada'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['producido'], 0, ',', '.'); ?></td>
                                    <td class="text-danger"><?php echo number_format($row['total_quiebras'], 0, ',', '.'); ?></td>
                                    <td><strong><?php echo number_format($row['producido_neto'], 0, ',', '.'); ?></strong></td>
                                    <td class="<?php echo $clase_diferencia; ?>">
                                        <?php echo ($row['diferencia_jornada'] >= 0 ? '+' : '') . number_format($row['diferencia_jornada'], 0, ',', '.'); ?>
                                    </td>
                                    <td><?php echo number_format($row['porcentaje_quiebras'], 2, ',', '.'); ?>%</td>
                                    <td class="<?php echo $clase_porcentaje; ?>">
                                        <strong><?php echo number_format($row['porcentaje_avance_jornada'], 2, ',', '.'); ?>%</strong>
                                    </td>
                                    <td><?php echo $row['estado_jornada']; ?></td>
                                    <td>
                                        <a href="?<?php echo getFilterParams(); ?>&ver_equipo=<?php echo urlencode($row['equipo']); ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> Ver Detalles
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center py-4">
                                    <i class="fas fa-info-circle"></i> No hay datos disponibles.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Detalles del equipo (si está seleccionado) -->
        <?php if ($equipo_detalle && !empty($team_details)): ?>
            <div class="equipo-detalles">
                <h3>
                    <span><i class="fas fa-users"></i> Detalles del Equipo: <?php echo htmlspecialchars($equipo_detalle); ?>
                        <?php if ($empleado_seleccionado): ?>
                            - Empleado: <?php echo htmlspecialchars($empleado_seleccionado); ?>
                        <?php endif; ?>
                    </span>
                    <a href="metas_diarias.php?<?php echo getFilterParams(); ?>" class="btn-volver">
                        <i class="fas fa-arrow-left"></i> Volver al Resumen
                    </a>
                </h3>
                
                <!-- Producción por hora del equipo -->
                <div class="tabla-detalles">
                    <h4><i class="fas fa-clock"></i> Producción por Hora y Empleado</h4>
                    <div class="table-wrapper">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Empleado</th> 
                                    <th>Hora</th>
                                    <th>Meta</th>
                                    <th>Producido</th>
                                    <th>Quiebras</th>
                                    <th>Prod. Neto</th>
                                    <th>% Avance</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($team_details['horas_equipo'])): ?>
                                    <?php foreach ($team_details['horas_equipo'] as $hora): ?>
                                        <?php
                                        $clase_porcentaje = '';
                                        if ($hora['porcentaje_avance'] < 70) {
                                            $clase_porcentaje = 'porcentaje-bajo';
                                        } elseif ($hora['porcentaje_avance'] < 90) {
                                            $clase_porcentaje = 'porcentaje-medio';
                                        } else {
                                            $clase_porcentaje = 'porcentaje-alto';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($hora['empleado']); ?></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($hora['hora'])); ?></td>
                                            <td><?php echo number_format($hora['meta_hora_persona'], 0, ',', '.'); ?></td>
                                            <td><?php echo number_format($hora['producido'], 0, ',', '.'); ?></td>
                                            <td class="text-danger"><?php echo number_format($hora['total_quiebras'], 0, ',', '.'); ?></td>
                                            <td><strong><?php echo number_format($hora['producido_neto'], 0, ',', '.'); ?></strong></td>
                                            <td class="<?php echo $clase_porcentaje; ?>">
                                                <strong><?php echo number_format($hora['porcentaje_avance'], 2, ',', '.'); ?>%</strong>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info btn-detalle-hora" 
                                                        data-equipo="<?php echo htmlspecialchars($hora['equipo']); ?>"
                                                        data-hora="<?php echo htmlspecialchars($hora['hora']); ?>"
                                                        data-empleado="<?php echo htmlspecialchars($hora['empleado']); ?>">
                                                    <i class="fas fa-search"></i> Detalles
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-3">
                                            <i class="fas fa-info-circle"></i> No hay datos de producción por hora para este equipo<?php echo $empleado_seleccionado ? ' y empleado' : ''; ?>.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Otras tablas de detalles -->
                <?php 
                $detail_sections = [
                    ['key' => 'areas_ordenes', 'title' => 'Producción por Área', 'icon' => 'fas fa-layer-group', 'columns' => ['Área', 'Fecha', 'Cantidad'], 'fields' => ['area', 'fecha', 'cantidad']],
                    ['key' => 'empleados_produccion', 'title' => 'Producción Individual', 'icon' => 'fas fa-user', 'columns' => ['Empleado', 'Área', 'Total producción'], 'fields' => ['empleado', 'area', 'cantidad']],
                    ['key' => 'quiebras_empleado', 'title' => 'Quiebras por Empleado', 'icon' => 'fas fa-exclamation-triangle', 'columns' => ['Empleado', 'Motivo', 'Fecha'], 'fields' => ['empleado', 'motivo', 'fecha_completa']]
                ];

                foreach ($detail_sections as $section): ?>
                    <div class="tabla-detalles">
                        <h4><i class="<?php echo $section['icon']; ?>"></i> <?php echo $section['title']; ?></h4>
                        <div class="table-wrapper">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <?php foreach ($section['columns'] as $column): ?>
                                            <th><?php echo $column; ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($team_details[$section['key']])): ?>
                                        <?php foreach ($team_details[$section['key']] as $item): ?>
                                            <tr>
                                                <?php foreach ($section['fields'] as $field): ?>
                                                    <td><?php echo htmlspecialchars($item[$field] ?? ''); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo count($section['columns']); ?>" class="text-center py-3">
                                                <i class="fas fa-info-circle"></i> No hay datos disponibles.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Tabla por hora (todos los equipos) -->
        <?php if (empty($equipo_detalle)): ?>
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0"><i class="fas fa-clock"></i> Producción por Hora - Por Empleado</h4>
                </div>
                <div class="card-body p-0">
                    <!-- Tabs para empleados -->
                    <?php
                    // Organizar datos por empleado
                    $empleados_data = [];
                    if (!empty($horas_data)) {
                        foreach ($horas_data as $hora) {
                            $empleado = $hora['empleado'];
                            if (!isset($empleados_data[$empleado])) {
                                $empleados_data[$empleado] = [];
                            }
                            $empleados_data[$empleado][] = $hora;
                        }
                    } else {
                        // Debugging: Log if if $horas_data is empty
                        error_log("No data in horas_data for datetime_inicio: $datetime_inicio, datetime_fin: $datetime_fin");
                    }
                    ?>

                    <?php if (!empty($empleados_data)): ?>
                        <!-- Nav Tabs -->
                        <ul class="nav nav-tabs" id="empleadosTabs" role="tablist">
                            <?php $first = true; ?>
                            <?php foreach (array_keys($empleados_data) as $index => $empleado): ?>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link <?php echo $first ? 'active' : ''; ?>" 
                                            id="tab-<?php echo md5($empleado); ?>" 
                                            data-bs-toggle="tab" 
                                            data-bs-target="#content-<?php echo md5($empleado); ?>" 
                                            type="button" 
                                            role="tab" 
                                            aria-controls="content-<?php echo md5($empleado); ?>" 
                                            aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                                        <?php echo htmlspecialchars($empleado); ?>
                                    </button>
                                </li>
                                <?php $first = false; ?>
                            <?php endforeach; ?>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="empleadosTabsContent">
                            <?php $first = true; ?>
                            <?php foreach ($empleados_data as $empleado => $data): ?>
                                <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" 
                                     id="content-<?php echo md5($empleado); ?>" 
                                     role="tabpanel" 
                                     aria-labelledby="tab-<?php echo md5($empleado); ?>">
                                    <div class="table-wrapper">
                                        <table class="table table-striped table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th><i class="fas fa-users"></i> Equipo</th>
                                                    <th><i class="fas fa-clock"></i> Hora</th>
                                                    <th><i class="fas fa-bullseye"></i> Meta</th>
                                                    <th><i class="fas fa-industry"></i> Producido</th>
                                                    <th><i class="fas fa-exclamation-triangle"></i> Quiebras</th>
                                                    <th><i class="fas fa-calculator"></i> Prod. Neto</th>
                                                    <th><i class="fas fa-chart-line"></i> % Avance</th>
                                                    <th><i class="fas fa-cogs"></i> Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($data)): ?>
                                                    <?php foreach ($data as $hora): ?>
                                                        <?php
                                                        $clase_porcentaje = '';
                                                        if ($hora['porcentaje_avance'] < 70) {
                                                            $clase_porcentaje = 'porcentaje-bajo';
                                                        } elseif ($hora['porcentaje_avance'] < 90) {
                                                            $clase_porcentaje = 'porcentaje-medio';
                                                        } else {
                                                            $clase_porcentaje = 'porcentaje-alto';
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td><strong><?php echo htmlspecialchars($hora['equipo']); ?></strong></td>
                                                            <td><?php echo date('Y-m-d H:i', strtotime($hora['hora'])); ?></td>
                                                            <td><?php echo number_format($hora['meta_hora_persona'], 0, ',', '.'); ?></td>
                                                            <td><?php echo number_format($hora['producido'], 0, ',', '.'); ?></td>
                                                            <td class="text-danger"><?php echo number_format($hora['total_quiebras'], 0, ',', '.'); ?></td>
                                                            <td><strong><?php echo number_format($hora['producido_neto'], 0, ',', '.'); ?></strong></td>
                                                            <td class="<?php echo $clase_porcentaje; ?>">
                                                                <strong><?php echo number_format($hora['porcentaje_avance'], 2, ',', '.'); ?>%</strong>
                                                            </td>
                                                            <td>
                                                                <button class="btn btn-sm btn-info btn-detalle-hora" 
                                                                        data-equipo="<?php echo htmlspecialchars($hora['equipo']); ?>"
                                                                        data-hora="<?php echo htmlspecialchars($hora['hora']); ?>"
                                                                        data-empleado="<?php echo htmlspecialchars($hora['empleado']); ?>">
                                                                    <i class="fas fa-search"></i> Ver Detalles
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="8" class="text-center py-4">
                                                            <i class="fas fa-info-circle"></i> No hay datos de producción por hora para <?php echo htmlspecialchars($empleado); ?>.
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php $first = false; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-users"></i> Equipo</th>
                                        <th><i class="fas fa-clock"></i> Hora</th>
                                        <th><i class="fas fa-bullseye"></i> Meta</th>
                                        <th><i class="fas fa-industry"></i> Producido</th>
                                        <th><i class="fas fa-exclamation-triangle"></i> Quiebras</th>
                                        <th><i class="fas fa-calculator"></i> Prod. Neto</th>
                                        <th><i class="fas fa-chart-line"></i> % Avance</th>
                                        <th><i class="fas fa-cogs"></i> Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <i class="fas fa-info-circle"></i> No hay datos de producción por hora para el rango seleccionado.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal mejorado para detalles por hora -->
    <div class="modal fade" id="modalDetalleHora" tabindex="-1" aria-labelledby="modalDetalleHoraLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDetalleHoraLabel">
                        <i class="fas fa-search"></i> Detalles de Producción y Quiebras por Hora
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalDetalleHoraBody">
                    <div class="text-center py-4">
                        <div class="custom-spinner"></div>
                        <p class="mt-3">Cargando detalles...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
            </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <i class="fas fa-cogs"></i>
            Sistema de Control de Producción © <?= date("Y") ?>
        </div>
        <div class="developer">
            <i class="fas fa-code"></i>
            Desarrollado por: Nestor Rosales | Rosales_Dev91
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    <script>
        // Clase para manejar la aplicación
        class MetasApp {
            constructor() {
                this.initClock();
                this.initFormHandlers();
                this.initCharts();
                this.initModalHandlers();
            }

            // Reloj en tiempo real mejorado
            initClock() {
                const updateClock = () => {
                    const now = new Date();
                    const options = { 
                        day: '2-digit', 
                        month: '2-digit', 
                        year: 'numeric',
                        hour: '2-digit', 
                        minute: '2-digit', 
                        second: '2-digit',
                        hour12: false,
                        timeZone: 'America/Guatemala'
                    };
                    document.getElementById('reloj').textContent = now.toLocaleString('es-GT', options);
                };
                
                updateClock();
                setInterval(updateClock, 1000);
            }

            // Manejadores de formularios
            initFormHandlers() {
                // Autocompletar meta al seleccionar equipo
                $('#equipo_meta').on('change', function() {
                    const selectedOption = $(this).find('option:selected');
                    const metaActual = selectedOption.data('meta-actual');
                    
                    if (metaActual) {
                        $('#meta_valor').val(metaActual);
                        $('#meta_valor').focus().select();
                    } else {
                        $('#meta_valor').val('').focus();
                    }
                });

                // Validación de fechas en tiempo real
                $('#fecha_inicio, #fecha_fin').on('change', this.validateDateRange);
                
                // Envío de formulario con loading
                $('#filtrosForm, #metasForm').on('submit', function() {
                    const submitBtn = $(this).find('button[type="submit"]');
                    const originalText = submitBtn.html();
                    
                    submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Procesando...');
                    
                    // Restaurar botón después de 3 segundos como fallback
                    setTimeout(() => {
                        submitBtn.prop('disabled', false).html(originalText);
                    }, 3000);
                });
            }

            // Validar rango de fechas
            validateDateRange() {
                const fechaInicio = $('#fecha_inicio').val();
                const fechaFin = $('#fecha_fin').val();
                const horaInicio = $('#hora_inicio').val();
                const horaFin = $('#hora_fin').val();

                if (fechaInicio && fechaFin && horaInicio && horaFin) {
                    const inicio = new Date(fechaInicio + 'T' + horaInicio);
                    const fin = new Date(fechaFin + 'T' + horaFin);

                    if (inicio > fin) {
                        $('#fecha_fin').addClass('is-invalid');
                        if (!$('#fecha_fin').next('.invalid-feedback').length) {
                            $('#fecha_fin').after('<div class="invalid-feedback">La fecha de fin debe ser posterior a la fecha de inicio</div>');
                        }
                        return false;
                    } else {
                        $('#fecha_fin').removeClass('is-invalid').next('.invalid-feedback').remove();
                        return true;
                    }
                }
            }

            // Inicializar gráficos con Chart.js
            initCharts() {
                // Referencias a los lienzos
                const ctxMetas = document.getElementById('graficoMetas');
                const ctxAvance = document.getElementById('graficoAvance');

                // Objeto para almacenar instancias de gráficos
                this.charts = {
                    graficoMetas: null,
                    graficoAvance: null
                };

                const chartOptions = {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { color: '#e9ecef' }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#28a745',
                            borderWidth: 1
                        }
                    },
                    scales: {
                        x: {
                            ticks: { color: '#e9ecef' },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' }
                        },
                        y: {
                            ticks: { color: '#e9ecef' },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                            beginAtZero: true
                        }
                    }
                };

                // Función para destruir gráfico si existe
                const destroyChart = (chartInstance) => {
                    if (chartInstance) {
                        chartInstance.destroy();
                    }
                };

                // Gráfico de barras - Producción vs Meta
                if (ctxMetas) {
                    // Destruir gráfico existente
                    destroyChart(this.charts.graficoMetas);

                    // Crear nuevo gráfico
                    this.charts.graficoMetas = new Chart(ctxMetas, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($labels); ?>,
                            datasets: [
                                {
                                    label: 'Meta por Jornada',
                                    data: <?php echo json_encode($metas); ?>,
                                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 2,
                                    borderRadius: 4
                                },
                                {
                                    label: 'Producido Neto',
                                    data: <?php echo json_encode($producciones); ?>,
                                    backgroundColor: 'rgba(75, 192, 192, 0.7)',
                                    borderColor: 'rgba(75, 192, 192, 1)',
                                    borderWidth: 2,
                                    borderRadius: 4
                                },
                                {
                                    label: 'Quiebras',
                                    data: <?php echo json_encode($quiebras); ?>,
                                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                                    borderColor: 'rgba(255, 99, 132, 1)',
                                    borderWidth: 2,
                                    borderRadius: 4
                                }
                            ]
                        },
                        options: {
                            ...chartOptions,
                            plugins: {
                                ...chartOptions.plugins,
                                title: {
                                    display: true,
                                    text: 'Comparativo de Producción vs Metas',
                                    color: '#e9ecef',
                                    font: { size: 16, weight: 'bold' }
                                }
                            },
                            scales: {
                                ...chartOptions.scales,
                                y: {
                                    ...chartOptions.scales.y,
                                    title: {
                                        display: true,
                                        text: 'Cantidad de Unidades',
                                        color: '#e9ecef'
                                    }
                                }
                            }
                        }
                    });
                }

                // Gráfico de líneas - Porcentaje de Avance
                if (ctxAvance) {
                    // Destruir gráfico existente
                    destroyChart(this.charts.graficoAvance);

                    // Crear nuevo gráfico
                    this.charts.graficoAvance = new Chart(ctxAvance, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($labels); ?>,
                            datasets: [{
                                label: '% de Avance',
                                data: <?php echo json_encode($porcentajes_avance); ?>,
                                backgroundColor: 'rgba(40, 167, 69, 0.2)',
                                borderColor: 'rgba(40, 167, 69, 1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: 'rgba(40, 167, 69, 1)',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 6,
                                pointHoverRadius: 8
                            }]
                        },
                        options: {
                            ...chartOptions,
                            plugins: {
                                ...chartOptions.plugins,
                                title: {
                                    display: true,
                                    text: 'Evolución del Porcentaje de Avance',
                                    color: '#e9ecef',
                                    font: { size: 16, weight: 'bold' }
                                }
                            },
                            scales: {
                                ...chartOptions.scales,
                                y: {
                                    ...chartOptions.scales.y,
                                    max: 150,
                                    title: {
                                        display: true,
                                        text: 'Porcentaje (%)',
                                        color: '#e9ecef'
                                    },
                                    ticks: {
                                        ...chartOptions.scales.y.ticks,
                                        callback: function(value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            // Manejadores del modal
            initModalHandlers() {
                // En el código JavaScript, actualiza el manejador del modal:
                $(document).on('click', '.btn-detalle-hora', function() {
                    const equipo = $(this).data('equipo');
                    const hora = $(this).data('hora');
                    const empleado = $(this).data('empleado');
                    
                    // Mostrar modal con loading
                    $('#modalDetalleHora').modal('show');
                    $('#modalDetalleHoraLabel').html(`
                        <i class="fas fa-search"></i> Detalles: ${equipo} - ${empleado} - ${new Date(hora).toLocaleString('es-GT')}
                    `);
                    
                    // Realizar petición AJAX con empleado
                    $.ajax({
                        url: window.location.pathname,
                        method: 'GET',
                        data: {
                            action: 'get_hora_detalle',
                            equipo: equipo,
                            hora: hora,
                            empleado: empleado // Agregar empleado a la petición
                        },
                        success: function(response) {
                            $('#modalDetalleHoraBody').html(response);
                        },
                        error: function(xhr, status, error) {
                            console.error('Error AJAX:', error);
                            $('#modalDetalleHoraBody').html(`
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Error al cargar los detalles</strong><br>
                                    Por favor, intente nuevamente o contacte al administrador.
                                    <br><small>Error técnico: ${error}</small>
                                </div>
                            `);
                        },
                        timeout: 10000
                    });
                });

                // Limpiar modal al cerrarse
                $('#modalDetalleHora').on('hidden.bs.modal', function() {
                    $('#modalDetalleHoraBody').html(`
                        <div class="text-center py-4">
                            <div class="custom-spinner"></div>
                            <p class="mt-3">Cargando detalles...</p>
                        </div>
                    `);
                });
            }

            // Utilidades
            showNotification(message, type = 'success') {
                const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
                const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
                
                const notification = $(`
                    <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                         style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                        <i class="fas ${icon}"></i> ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `);
                
                $('body').append(notification);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    notification.alert('close');
                }, 5000);
            }
        }

        // Inicializar aplicación cuando el DOM esté listo
        $(document).ready(function() {
            window.metasApp = new MetasApp();
            
            // Ocultar alertas automáticamente después de 8 segundos
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 8000);
        });

        // Manejo de errores globales de JavaScript
        window.addEventListener('error', function(e) {
            console.error('Error JavaScript:', e.error);
        });

        // Prevenir envío de formularios duplicados
        $('form').on('submit', function() {
            $(this).find('button[type="submit"]').prop('disabled', true);
        });
    </script>

    <script>
    // Función para ajustar horas según turno
    function adjustHours(turno) {
        const horaInicio = document.getElementById('hora_inicio');
        const horaFin = document.getElementById('hora_fin');
        
        if (turno === 'general') {
            horaInicio.removeAttribute('readonly');
            horaFin.removeAttribute('readonly');
            horaInicio.value = '00:00';
            horaFin.value = '23:59';
        } else {
            horaInicio.setAttribute('readonly', true);
            horaFin.setAttribute('readonly', true);
            switch(turno) {
                case 'A':
                    horaInicio.value = '06:01';
                    horaFin.value = '14:00';
                    break;
                case 'B':
                    horaInicio.value = '14:01';
                    horaFin.value = '21:30';
                    break;
                case 'C':
                    horaInicio.value = '21:31';
                    horaFin.value = '06:00';
                    break;
            }
        }
    }

    // Inicializar al cargar
    document.addEventListener('DOMContentLoaded', function() {
        adjustHours(document.getElementById('turno').value);
    });
</script>
</body>
</html>