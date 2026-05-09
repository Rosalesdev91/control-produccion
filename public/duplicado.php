<?php
$conexion = new mysqli("localhost", "root", "", "produccion_quiebras");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

// Filtros
$empleado = $_GET['empleado'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

$query = "
SELECT 
    rq.id,
    rq.empleado_registro,
    rq.orden,
    rq.turno,
    rq.responsable,
    rq.empleado,
    rq.equipo,
    rq.motivo,
    rq.porque_defecto,
    rq.lado_lente,
    CONCAT(rq.fecha, ' ', rq.hora) AS fecha_hora
FROM registro_quiebras rq
INNER JOIN (
    SELECT fecha, orden
    FROM registro_quiebras
    WHERE 1=1
";

// Filtros en subconsulta
if ($empleado != '') {
    $query .= " AND empleado_registro = '$empleado'";
}
if ($fecha_inicio != '' && $fecha_fin != '') {
    $query .= " AND fecha BETWEEN '$fecha_inicio' AND '$fecha_fin'";
}

$query .= "
    GROUP BY fecha, orden
    HAVING COUNT(*) > 1
) dup 
ON rq.fecha = dup.fecha AND rq.orden = dup.orden
WHERE 1=1
";

// Filtros en consulta principal
if ($empleado != '') {
    $query .= " AND rq.empleado_registro = '$empleado'";
}
if ($fecha_inicio != '' && $fecha_fin != '') {
    $query .= " AND rq.fecha BETWEEN '$fecha_inicio' AND '$fecha_fin'";
}

$query .= " ORDER BY rq.fecha DESC, rq.orden, rq.hora";

$resultado = $conexion->query($query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Duplicados por Día</title>
    <style>
        body { font-family: Arial; background: #f4f4f4; }
        h2 { text-align: center; }
        form { text-align: center; margin-bottom: 20px; }
        input, button { padding: 8px; margin: 5px; }
        table {
            border-collapse: collapse;
            width: 95%;
            margin: auto;
            background: white;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background: #007BFF;
            color: white;
        }
    </style>
</head>
<body>

<h2>🔍 Duplicados por Día</h2>

<form method="GET">
    <input type="text" name="empleado" placeholder="Empleado" value="<?= $empleado ?>">
    <input type="date" name="fecha_inicio" value="<?= $fecha_inicio ?>">
    <input type="date" name="fecha_fin" value="<?= $fecha_fin ?>">
    <button type="submit">Buscar</button>
</form>

<table>
    <tr>
        <th>ID</th>
        <th>Registro</th>
        <th>N° Orden</th>
        <th>Turno</th>
        <th>Responsable</th>
        <th>Empleado</th>
        <th>Equipo</th>
        <th>Motivo</th>
        <th>Defecto</th>
        <th>Lado Lente</th>
        <th>Fecha y Hora</th>
    </tr>

<?php
if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        echo "<tr>
            <td>{$row['id']}</td>
            <td>{$row['empleado_registro']}</td>
            <td>{$row['orden']}</td>
            <td>{$row['turno']}</td>
            <td>{$row['responsable']}</td>
            <td>{$row['empleado']}</td>
            <td>{$row['equipo']}</td>
            <td>{$row['motivo']}</td>
            <td>{$row['porque_defecto']}</td>
            <td>{$row['lado_lente']}</td>
            <td>{$row['fecha_hora']}</td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='11'>Sin resultados</td></tr>";
}
?>

</table>

</body>
</html>