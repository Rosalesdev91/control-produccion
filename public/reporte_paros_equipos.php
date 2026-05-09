<?php
// Enhanced PHP Script for General Equipment Downtime Report with CSV Download
// Shows aggregates per equipment (or all) and detailed list of all downtimes
// Backend: Handles DB connection, queries, calculations, CSV generation
// Frontend: HTML form for inputs, tables/charts for output, CSV download
// Requirements: Assumes MySQL DB with 'paro_produccion' table
// Config: Adjust DB credentials in $conn

// Database Configuration
$servername = "localhost"; // Change to your DB server
$username = "root"; // DB username
$password = ""; // DB password
$dbname = "produccion_quiebras"; // DB name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Default values
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$hora_inicio = isset($_GET['hora_inicio']) ? $_GET['hora_inicio'] : '00:00';
$hora_fin = isset($_GET['hora_fin']) ? $_GET['hora_fin'] : '23:59';
$turno = isset($_GET['turno']) ? $_GET['turno'] : 'general';
$equipo = isset($_GET['equipo']) ? $_GET['equipo'] : '';
$download_csv = isset($_GET['download_csv']) && $_GET['download_csv'] == 1;

// Validate inputs
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin) ||
    !preg_match('/^\d{2}:\d{2}$/', $hora_inicio) || !preg_match('/^\d{2}:\d{2}$/', $hora_fin)) {
    die("Invalid date or time format.");
}

// Turno horarios (from original)
$turnos_horarios = [
    'A' => ['inicio' => '06:01', 'fin' => '14:00'],
    'B' => ['inicio' => '14:01', 'fin' => '21:30'],
    'C' => ['inicio' => '21:31', 'fin' => '06:00'],
    'general' => null
];

// Function to calculate available minutes
function calcularMinutosJornada($turno, $num_days = 1) {
    switch ($turno) {
        case 'A':
            $horas = 7.25; // 8h - 45min descanso
            break;
        case 'B':
            $horas = 6.75; // 7.5h - 45min
            break;
        case 'C':
            $horas = 7.75; // 8.5h - 45min
            break;
        default:
            $horas = 21.5; // General
    }
    return ($horas * 60) * $num_days;
}

// Function to format minutes with hours in parentheses
function formatTiempo($minutos) {
    $horas = number_format($minutos / 60, 1);
    return number_format($minutos, 0) . " min ($horas h)";
}

// Calculate number of days
$start_date = new DateTime($fecha_inicio);
$end_date = new DateTime($fecha_fin);
$num_days = $start_date->diff($end_date)->days + 1;

// Available minutes (same for all equipos in the period)
$minutos_disponibles = calcularMinutosJornada($turno, $num_days);

// Function to get downtime minutes for a specific equipo
function getTiempoVaradoMinutos($conn, $equipo, $datetime_inicio, $datetime_fin, $turno, $hora_inicio, $hora_fin) {
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    $time_condition = "";
    $params = [];
    $types = "";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            $time_condition = " AND (TIME(fecha_inicio) >= ? OR TIME(fecha_inicio) <= ?)";
        } else {
            $time_condition = " AND TIME(fecha_inicio) BETWEEN ? AND ?";
        }
        $params = [$time_start, $time_end];
        $types = "ss";
    }
    
    $sql = "
        SELECT SUM(
            TIME_TO_SEC(
                TIMEDIFF(
                    IFNULL(fecha_fin, LEAST(NOW(), ?)),
                    fecha_inicio
                )
            ) / 60
        ) AS tiempo_varado_minutos
        FROM paro_produccion
        WHERE equipo = ?
            AND fecha_inicio BETWEEN ? AND ?
            AND (fecha_fin IS NULL OR fecha_fin >= ?)
            $time_condition
    ";
    
    $params = array_merge([$datetime_fin, $equipo, $datetime_inicio, $datetime_fin, $datetime_inicio], $params);
    $types = "sssss" . $types;
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error in getTiempoVaradoMinutos: " . $conn->error);
        die("SQL Prepare Error: " . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log("SQL Execute Error in getTiempoVaradoMinutos: " . $stmt->error);
        die("SQL Execute Error: " . $stmt->error);
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['tiempo_varado_minutos'] ?? 0;
}

// Function to get all downtimes (paros) details
function getAllParos($conn, $datetime_inicio, $datetime_fin, $turno, $hora_inicio, $hora_fin, $equipo = '') {
    $time_start = $hora_inicio . ':00';
    $time_end = $hora_fin . ':00';
    
    $time_condition = "";
    $params = [$datetime_inicio, $datetime_fin, $datetime_inicio];
    $types = "sss";
    
    if ($turno !== 'general') {
        if ($turno === 'C') {
            $time_condition = " AND (TIME(fecha_inicio) >= ? OR TIME(fecha_inicio) <= ?)";
        } else {
            $time_condition = " AND TIME(fecha_inicio) BETWEEN ? AND ?";
        }
        $params = array_merge($params, [$time_start, $time_end]);
        $types .= "ss";
    }
    
    $equipo_condition = "";
    if ($equipo) {
        $equipo_condition = " AND equipo = ?";
        $params[] = $equipo;
        $types .= "s";
    }
    
    $sql = "
        SELECT id, empleado, area, equipo, motivo, fecha_inicio, fecha_fin,
               TIME_TO_SEC(
                   TIMEDIFF(
                       IFNULL(fecha_fin, LEAST(NOW(), ?)),
                       fecha_inicio
                   )
               ) / 60 AS duracion_min,
               activo
        FROM paro_produccion
        WHERE fecha_inicio BETWEEN ? AND ?
              AND (fecha_fin IS NULL OR fecha_fin >= ?)
              $time_condition
              $equipo_condition
        ORDER BY fecha_inicio DESC
    ";
    
    $params = array_merge([$datetime_fin], $params);
    $types = "s" . $types;
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error in getAllParos: " . $conn->error);
        die("SQL Prepare Error: " . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log("SQL Execute Error in getAllParos: " . $stmt->error);
        die("SQL Execute Error: " . $stmt->error);
    }
    $result = $stmt->get_result();
    
    $paros = [];
    while ($row = $result->fetch_assoc()) {
        $row['duracion_display'] = $row['activo'] ? $row['duracion_min'] . ' min (En curso)' : $row['duracion_min'] . ' min';
        $paros[] = $row;
    }
    $stmt->close();
    
    return $paros;
}

// Datetime ranges
$datetime_inicio = $fecha_inicio . ' ' . $hora_inicio . ':00';
$datetime_fin = $fecha_fin . ' ' . $hora_fin . ':59';

// Validate date range
if (strtotime($datetime_inicio) > strtotime($datetime_fin)) {
    die("Error: Fecha de inicio debe ser anterior a fecha de fin.");
}

// Get list of equipos for dropdown
$equipos = [];
$result = $conn->query("SELECT DISTINCT equipo FROM paro_produccion ORDER BY equipo");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $equipos[] = $row['equipo'];
    }
} else {
    error_log("Error fetching equipos: " . $conn->error);
}

// Aggregates data
$aggregates = [];
if ($equipo) {
    // Single equipo
    $tiempo_varado = getTiempoVaradoMinutos($conn, $equipo, $datetime_inicio, $datetime_fin, $turno, $hora_inicio, $hora_fin);
    $tiempo_efectivo = $minutos_disponibles - $tiempo_varado;
    $porcentaje_efectivo = ($minutos_disponibles > 0) ? round(($tiempo_efectivo / $minutos_disponibles) * 100, 2) : 0;
    $aggregates[] = [
        'equipo' => $equipo,
        'tiempo_disponible' => $minutos_disponibles,
        'tiempo_varado' => $tiempo_varado,
        'tiempo_efectivo' => $tiempo_efectivo,
        'porcentaje_efectivo' => $porcentaje_efectivo
    ];
} else {
    // All equipos
    foreach ($equipos as $eq) {
        $tiempo_varado = getTiempoVaradoMinutos($conn, $eq, $datetime_inicio, $datetime_fin, $turno, $hora_inicio, $hora_fin);
        $tiempo_efectivo = $minutos_disponibles - $tiempo_varado;
        $porcentaje_efectivo = ($minutos_disponibles > 0) ? round(($tiempo_efectivo / $minutos_disponibles) * 100, 2) : 0;
        $aggregates[] = [
            'equipo' => $eq,
            'tiempo_disponible' => $minutos_disponibles,
            'tiempo_varado' => $tiempo_varado,
            'tiempo_efectivo' => $tiempo_efectivo,
            'porcentaje_efectivo' => $porcentaje_efectivo
        ];
    }
}

// Get detailed paros
$paros_details = getAllParos($conn, $datetime_inicio, $datetime_fin, $turno, $hora_inicio, $hora_fin, $equipo);

// Handle CSV download
if ($download_csv) {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_paros_' . date('Ymd_His') . '.csv"');
    
    // Create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write report header
    fputcsv($output, ['Reporte General de Paros y Porcentaje de Tiempo Efectivo']);
    fputcsv($output, ['Período', "$fecha_inicio al $fecha_fin"]);
    fputcsv($output, ['Turno', $turno]);
    fputcsv($output, ['Equipo', $equipo ?: 'Todos los Equipos']);
    fputcsv($output, []); // Empty line for separation
    
    // Write aggregates table
    fputcsv($output, ['Resumen de Tiempo Efectivo']);
    fputcsv($output, ['Equipo', 'Tiempo Disponible', 'Tiempo Varado', 'Tiempo Efectivo', '% Efectivo']);
    foreach ($aggregates as $agg) {
        fputcsv($output, [
            $agg['equipo'],
            formatTiempo($agg['tiempo_disponible']),
            formatTiempo($agg['tiempo_varado']),
            formatTiempo($agg['tiempo_efectivo']),
            number_format($agg['porcentaje_efectivo'], 2) . '%'
        ]);
    }
    
    // Empty line for separation
    fputcsv($output, []);
    
    // Write detailed paros table
    fputcsv($output, ['Detalles de Todos los Paros']);
    fputcsv($output, ['ID', 'Empleado', 'Área', 'Equipo', 'Motivo', 'Fecha Inicio', 'Fecha Fin', 'Duración', 'Activo']);
    foreach ($paros_details as $paro) {
        fputcsv($output, [
            $paro['id'],
            $paro['empleado'],
            $paro['area'],
            $paro['equipo'],
            $paro['motivo'],
            $paro['fecha_inicio'],
            $paro['fecha_fin'] ?? 'N/A',
            $paro['duracion_display'],
            $paro['activo'] ? 'Sí' : 'No'
        ]);
    }
    
    fclose($output);
    $conn->close();
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte General de Paros y Tiempo Efectivo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f8f9fa; }
        .container { max-width: 1200px; margin-top: 50px; }
        .table { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mb-4">Reporte General de Paros y Porcentaje de Tiempo Efectivo</h2>
        
        <form method="GET" class="mb-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label>Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($fecha_inicio); ?>" required>
                </div>
                <div class="col-md-3">
                    <label>Fecha Fin</label>
                    <input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fecha_fin); ?>" required>
                </div>
                <div class="col-md-2">
                    <label>Hora Inicio</label>
                    <input type="time" name="hora_inicio" class="form-control" value="<?php echo htmlspecialchars($hora_inicio); ?>" required>
                </div>
                <div class="col-md-2">
                    <label>Hora Fin</label>
                    <input type="time" name="hora_fin" class="form-control" value="<?php echo htmlspecialchars($hora_fin); ?>" required>
                </div>
                <div class="col-md-2">
                    <label>Turno</label>
                    <select name="turno" class="form-control" onchange="adjustHours(this.value)">
                        <option value="general" <?php echo $turno == 'general' ? 'selected' : ''; ?>>General</option>
                        <option value="A" <?php echo $turno == 'A' ? 'selected' : ''; ?>>A</option>
                        <option value="B" <?php echo $turno == 'B' ? 'selected' : ''; ?>>B</option>
                        <option value="C" <?php echo $turno == 'C' ? 'selected' : ''; ?>>C</option>
                    </select>
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <label>Equipo (opcional)</label>
                    <select name="equipo" class="form-control">
                        <option value="">-- Todos --</option>
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?php echo htmlspecialchars($eq); ?>" <?php echo $equipo == $eq ? 'selected' : ''; ?>><?php echo htmlspecialchars($eq); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 align-self-end">
                    <button type="submit" class="btn btn-primary w-100">Generar Reporte</button>
                </div>
                <div class="col-md-4 align-self-end">
                    <button type="submit" name="download_csv" value="1" class="btn btn-success w-100">Descargar CSV</button>
                </div>
            </div>
        </form>
        
        <?php if (!empty($aggregates)): ?>
            <h4 class="mt-5">Resumen de Tiempo Efectivo <?php echo $equipo ? 'para ' . htmlspecialchars($equipo) : '(Todos los Equipos)'; ?></h4>
            <p>Período: <?php echo htmlspecialchars($fecha_inicio) . ' al ' . htmlspecialchars($fecha_fin); ?> (Turno: <?php echo htmlspecialchars($turno); ?>)</p>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Equipo</th>
                            <th>Tiempo Disponible</th>
                            <th>Tiempo Varado</th>
                            <th>Tiempo Efectivo</th>
                            <th>% Efectivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aggregates as $agg): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($agg['equipo']); ?></td>
                                <td><?php echo formatTiempo($agg['tiempo_disponible']); ?></td>
                                <td><?php echo formatTiempo($agg['tiempo_varado']); ?></td>
                                <td><?php echo formatTiempo($agg['tiempo_efectivo']); ?></td>
                                <td class="<?php echo ($agg['porcentaje_efectivo'] < 70 ? 'text-danger' : ($agg['porcentaje_efectivo'] < 90 ? 'text-warning' : 'text-success')); ?>">
                                    <?php echo number_format($agg['porcentaje_efectivo'], 2); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($equipo): // Show chart only for single equipo ?>
                <canvas id="efectivoChart" height="100"></canvas>
                <script>
                    const ctx = document.getElementById('efectivoChart');
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Efectivo', 'Varado'],
                            datasets: [{
                                data: [<?php echo $aggregates[0]['tiempo_efectivo']; ?>, <?php echo $aggregates[0]['tiempo_varado']; ?>],
                                backgroundColor: ['#28a745', '#dc3545']
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { position: 'top' },
                                title: { display: true, text: 'Distribución de Tiempo' }
                            }
                        }
                    });
                </script>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!empty($paros_details)): ?>
            <h4 class="mt-5">Detalles de Todos los Paros</h4>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empleado</th>
                            <th>Área</th>
                            <th>Equipo</th>
                            <th>Motivo</th>
                            <th>Fecha Inicio</th>
                            <th>Fecha Fin</th>
                            <th>Duración</th>
                            <th>Activo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paros_details as $paro): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($paro['id']); ?></td>
                                <td><?php echo htmlspecialchars($paro['empleado']); ?></td>
                                <td><?php echo htmlspecialchars($paro['area']); ?></td>
                                <td><?php echo htmlspecialchars($paro['equipo']); ?></td>
                                <td><?php echo htmlspecialchars($paro['motivo']); ?></td>
                                <td><?php echo htmlspecialchars($paro['fecha_inicio']); ?></td>
                                <td><?php echo htmlspecialchars($paro['fecha_fin'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($paro['duracion_display']); ?></td>
                                <td><?php echo $paro['activo'] ? 'Sí' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mt-4">No se encontraron paros en el período seleccionado.</div>
        <?php endif; ?>
    </div>

    <script>
        function adjustHours(turno) {
            const horaInicio = document.querySelector('[name="hora_inicio"]');
            const horaFin = document.querySelector('[name="hora_fin"]');
            
            if (turno === 'general') {
                horaInicio.removeAttribute('readonly');
                horaFin.removeAttribute('readonly');
                horaInicio.value = '00:00';
                horaFin.value = '23:59';
            } else {
                horaInicio.setAttribute('readonly', true);
                horaFin.setAttribute('readonly', true);
                switch(turno) {
                    case 'A': horaInicio.value = '06:01'; horaFin.value = '14:00'; break;
                    case 'B': horaInicio.value = '14:01'; horaFin.value = '21:30'; break;
                    case 'C': horaInicio.value = '21:31'; horaFin.value = '06:00'; break;
                }
            }
        }
    </script>
</body>
</html>