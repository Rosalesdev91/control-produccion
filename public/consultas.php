<?php
// consultas.php - App para generar consultas dinámicas de producción
// Basado en el esquema de la base de datos 'produccion_quiebras'

session_start();

require_once dirname(__DIR__) . '/config/database.php'; // Asume que tienes esta conexión

// Zona horaria
date_default_timezone_set('America/Guatemala');

// Parámetros por defecto y manejo de POST
$area = $_POST['area'] ?? '';
$equipo = $_POST['equipo'] ?? '';
$fecha_inicio = $_POST['fecha_inicio'] ?? '';
$fecha_fin = $_POST['fecha_fin'] ?? '';
$hora_inicio = $_POST['hora_inicio'] ?? '';
$hora_fin = $_POST['hora_fin'] ?? '';
$tipo_consulta = $_POST['tipo_consulta'] ?? 'total_diario'; // 'total_diario', 'por_hora', 'por_empleado'
$agrupar_por = $_POST['agrupar_por'] ?? 'dia'; // 'dia', 'hora', 'empleado'
$exportar_csv = isset($_POST['exportar_csv']);

$errores = [];
if (!empty($fecha_inicio) && !empty($fecha_fin) && $fecha_inicio > $fecha_fin) {
    $errores[] = "La fecha de inicio no puede ser mayor a la fecha de fin.";
}

// Función para validar fechas/horas
function validarFecha($fecha) {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return $dt && $dt->format('Y-m-d') === $fecha ? $fecha : false;
}

function validarHora($hora) {
    $dt = DateTime::createFromFormat('H:i', $hora);
    return $dt && $dt->format('H:i') === $hora ? $hora . ':00' : false;
}

$fecha_inicio_valida = validarFecha($fecha_inicio);
$fecha_fin_valida = validarFecha($fecha_fin);
$hora_inicio_valida = validarHora($hora_inicio);
$hora_fin_valida = validarHora($hora_fin);

if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    if (!$fecha_inicio_valida || !$fecha_fin_valida) {
        $errores[] = "Formato de fecha inválido (YYYY-MM-DD).";
    }
}
if (!empty($hora_inicio) && !empty($hora_fin)) {
    if (!$hora_inicio_valida || !$hora_fin_valida) {
        $errores[] = "Formato de hora inválido (HH:MM).";
    }
}

// Verificar que todos los campos requeridos estén presentes
$hay_filtros = !empty($area) && !empty($equipo) && !empty($fecha_inicio) && !empty($fecha_fin);
if (isset($_POST['generar']) && !$hay_filtros) {
    $errores[] = "Debes seleccionar todos los filtros requeridos (Área, Equipo, Fechas).";
}

// Establecer valores por defecto para horas si no se proporcionan
if ($hay_filtros) {
    if (empty($hora_inicio_valida)) {
        $hora_inicio_valida = '00:00:00';
    }
    if (empty($hora_fin_valida)) {
        $hora_fin_valida = '23:59:59';
    }
}

// Construir query dinámicamente
function generarQuery($area, $equipo, $fecha_inicio, $fecha_fin, $hora_inicio, $hora_fin, $tipo_consulta, $agrupar_por) {
    $where_comun = "area = ? AND equipo = ? AND TIME(fecha) BETWEEN ? AND ? AND DATE(fecha) BETWEEN ? AND ?";
    $params = [$area, $equipo, $hora_inicio, $hora_fin, $fecha_inicio, $fecha_fin];

    // Determinar select_inner, group_by_inner, count_col, group_col basado en tipo_consulta
    if ($tipo_consulta === 'total_diario') {
        $select_inner = "DATE(fecha) AS dia, COUNT(*) AS total_ingresos";
        $group_by_inner = "GROUP BY DATE(fecha)";
        $count_col = 'total_ingresos';
        $group_col = 'dia';
        $as_count = 'total_ingresos';
    } elseif ($tipo_consulta === 'por_hora') {
        $select_inner = "DATE_FORMAT(fecha, '%Y-%m-%d %H:00') AS hora, COUNT(*) AS total";
        $group_by_inner = "GROUP BY DATE_FORMAT(fecha, '%Y-%m-%d %H:00')";
        $count_col = 'total';
        $group_col = 'hora';
        $as_count = 'total';
    } elseif ($tipo_consulta === 'por_empleado') {
        $select_inner = "empleado AS empleado, COUNT(*) AS total";
        $group_by_inner = "GROUP BY empleado";
        $count_col = 'total';
        $group_col = 'empleado';
        $as_count = 'total';
    } else {
        $select_inner = "DATE(fecha) AS dia, COUNT(*) AS total_ingresos";
        $group_by_inner = "GROUP BY DATE(fecha)";
        $count_col = 'total_ingresos';
        $group_col = 'dia';
        $as_count = 'total_ingresos';
    }

    $extra_cols = '';

    // Override para agrupar por hora (formato de hora sin fecha, 0-23)
    if ($agrupar_por === 'hora') {
        $select_inner = "HOUR(fecha) AS hora_num, DATE_FORMAT(fecha, '%H:00') AS hora_label, COUNT(*) AS total";
        $group_by_inner = "GROUP BY HOUR(fecha)";
        $count_col = 'total';
        $group_col = 'hora_num';
        $as_count = 'total';
        $extra_cols = ', MAX(hora_label) AS hora_label';
    }

    $sql_produccion = "
        SELECT $select_inner
        FROM produccion
        WHERE $where_comun
        $group_by_inner
    ";

    $sql_antiguos = str_replace('produccion', 'registros_antiguos', $sql_produccion);

    $union_sql = $sql_produccion . " UNION ALL " . $sql_antiguos;

    $order_by = ($group_col === 'empleado') ? "ORDER BY $as_count DESC" : "ORDER BY $group_col ASC";

    $sql_union = "
        SELECT $group_col $extra_cols, SUM($count_col) AS $as_count
        FROM ($union_sql) AS union_total
        GROUP BY $group_col
        $order_by
    ";

    return [$sql_union, $params];
}

$datos = [];
$sql_generada = '';
if (empty($errores) && $hay_filtros && !$exportar_csv && isset($_POST['generar'])) {
    list($sql, $params) = generarQuery($area, $equipo, $fecha_inicio_valida, $fecha_fin_valida, $hora_inicio_valida, $hora_fin_valida, $tipo_consulta, $agrupar_por);
    $sql_generada = $sql;
    
    // Duplicar params y tipos para las dos subconsultas (6 ? cada una = 12)
    $params_dupe = array_merge($params, $params);
    $tipos = str_repeat('s', count($params)); // 'ssssss'
    $tipos_dupe = $tipos . $tipos; // 'ssssssssssss'
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error en prepare: " . $conn->error);
    }
    $stmt->bind_param($tipos_dupe, ...$params_dupe);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    while ($row = $resultado->fetch_assoc()) {
        $datos[] = $row;
    }
    $stmt->close();
}

// Exportar CSV
if ($exportar_csv && empty($errores) && $hay_filtros) {
    list($sql, $params) = generarQuery($area, $equipo, $fecha_inicio_valida, $fecha_fin_valida, $hora_inicio_valida, $hora_fin_valida, $tipo_consulta, $agrupar_por);
    
    // Duplicar params y tipos
    $params_dupe = array_merge($params, $params);
    $tipos = str_repeat('s', count($params));
    $tipos_dupe = $tipos . $tipos;
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error en prepare para CSV: " . $conn->error);
    }
    $stmt->bind_param($tipos_dupe, ...$params_dupe);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="consulta_produccion_' . date('Y-m-d_H-i-s') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // BOM para UTF-8
    
    // Headers dinámicos
    $meta = $resultado->fetch_fields();
    $headers = [];
    foreach ($meta as $field) {
        $headers[] = $field->name;
    }
    fputcsv($output, $headers);
    
    // Rewind y datos
    $resultado->data_seek(0);
    while ($row = $resultado->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    $stmt->close();
    exit();
}

// Obtener opciones para selects
$areas_query = $conn->query("SELECT DISTINCT area FROM areas UNION SELECT DISTINCT area FROM produccion WHERE area <> '' AND area IS NOT NULL ORDER BY area");
$equipos_query = $conn->query("SELECT nombre_equipo FROM equipos WHERE activo = 1 ORDER BY nombre_equipo");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generador de Consultas de Producción</title>
    <style>
        body { background: #155724; color: white; font-family: Arial, sans-serif; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: rgba(0,0,0,0.2); padding: 20px; border-radius: 10px; }
        form { display: flex; flex-direction: column; gap: 10px; }
        input, select, button { padding: 8px; border-radius: 5px; border: 1px solid #ccc; }
        button { background: #28a745; color: white; cursor: pointer; }
        button:hover { background: #218838; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; color: #212529; }
        th, td { padding: 8px; border: 1px solid #ccc; text-align: center; }
        th { background: #28a745; color: white; }
        .error { color: #ff6b6b; }
        .resultados { background: #f8f9fa; color: #212529; padding: 10px; border-radius: 5px; }
        pre { background: #000; color: #0f0; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Generador de Consultas Dinámicas</h1>
        <p>Genera reportes como totales diarios, por hora o por empleado. Basado en tu query de ejemplo.</p>
        
        <?php if (!empty($errores)): ?>
            <div class="error"><?php foreach ($errores as $err): echo htmlspecialchars($err) . '<br>'; endforeach; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <label>Área:</label>
            <select name="area" required>
                <option value="">-- Seleccione --</option>
                <?php while ($row = $areas_query->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($row['area']) ?>" <?= $area === $row['area'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($row['area']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            
            <label>Equipo:</label>
            <select name="equipo" required>
                <option value="">-- Seleccione --</option>
                <?php $equipos_query->data_seek(0); while ($row = $equipos_query->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($row['nombre_equipo']) ?>" <?= $equipo === $row['nombre_equipo'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($row['nombre_equipo']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            
            <label>Fecha Inicio:</label>
            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" required>
            
            <label>Fecha Fin:</label>
            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" required>
            
            <label>Hora Inicio:</label>
            <input type="time" name="hora_inicio" value="<?= htmlspecialchars($hora_inicio) ?>">
            
            <label>Hora Fin:</label>
            <input type="time" name="hora_fin" value="<?= htmlspecialchars($hora_fin) ?>">
            
            <label>Tipo de Consulta:</label>
            <select name="tipo_consulta">
                <option value="total_diario" <?= $tipo_consulta === 'total_diario' ? 'selected' : '' ?>>Total Diario (como tu query)</option>
                <option value="por_hora" <?= $tipo_consulta === 'por_hora' ? 'selected' : '' ?>>Por Hora</option>
                <option value="por_empleado" <?= $tipo_consulta === 'por_empleado' ? 'selected' : '' ?>>Por Empleado</option>
            </select>
            
            <label>Agrupar Por:</label>
            <select name="agrupar_por">
                <option value="dia" <?= $agrupar_por === 'dia' ? 'selected' : '' ?>>Día</option>
                <option value="hora" <?= $agrupar_por === 'hora' ? 'selected' : '' ?>>Hora</option>
                <option value="empleado" <?= $agrupar_por === 'empleado' ? 'selected' : '' ?>>Empleado</option>
            </select>
            
            <button type="submit" name="generar">Generar Consulta</button>
            <?php if (!empty($datos)): ?>
                <button type="submit" name="exportar_csv" value="1">📁 Exportar CSV</button>
            <?php endif; ?>
        </form>
        
        <?php if (!empty($datos)): ?>
            <div class="resultados">
                <h3>Resultados:</h3>
                <?php if (empty($datos)): ?>
                    <p>No se encontraron datos con los filtros seleccionados.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <?php $headers = array_keys($datos[0]); foreach ($headers as $col): ?>
                                    <th><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $col))) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($datos as $row): ?>
                                <tr>
                                    <?php foreach ($row as $val): ?>
                                        <td><?= htmlspecialchars($val ?? '') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <p><strong>Query Generada:</strong></p>
                <pre><?= htmlspecialchars($sql_generada) ?></pre>
            </div>
        <?php endif; ?>
        
        <p><a href="dashboard_admin_produccion.php" style="color: #d4fcd4;">← Volver al Dashboard</a></p>
    </div>
</body>
</html>