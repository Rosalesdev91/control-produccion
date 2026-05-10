<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['rol']) !== 'administrador') {
    die('Acceso no autorizado');
}

$empleado = $_GET['empleado'] ?? '';
if (empty($empleado)) {
    die('Empleado no especificado');
}

$conn = new mysqli($host, $username, $password, $dbname);

// Producción del empleado
$stmt = $conn->prepare("
    SELECT DATE(fecha) as fecha_dia, TIME(fecha) as hora, proceso, referencia 
    FROM produccion_picking 
    WHERE empleado = ? 
    ORDER BY fecha DESC 
    LIMIT 100
");
$stmt->bind_param("s", $empleado);
$stmt->execute();
$result = $stmt->get_result();

// Estadísticas
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(DISTINCT DATE(fecha)) as dias,
        COUNT(DISTINCT proceso) as procesos,
        MIN(DATE(fecha)) as primer_dia,
        MAX(DATE(fecha)) as ultimo_dia
    FROM produccion_picking 
    WHERE empleado = ?
");
$stats->bind_param("s", $empleado);
$stats->execute();
$estadisticas = $stats->get_result()->fetch_assoc();
?>

<div style="margin-bottom: 20px; background: #e9ecef; padding: 15px; border-radius: 5px;">
    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        <div><strong>Total:</strong> <?= number_format($estadisticas['total'] ?? 0) ?></div>
        <div><strong>Días:</strong> <?= $estadisticas['dias'] ?? 0 ?></div>
        <div><strong>Procesos:</strong> <?= $estadisticas['procesos'] ?? 0 ?></div>
        <div><strong>Último día:</strong> <?= $estadisticas['ultimo_dia'] ? date('d/m/Y', strtotime($estadisticas['ultimo_dia'])) : 'N/A' ?></div>
    </div>
</div>

<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr style="background: #28a745; color: white;">
            <th style="padding: 10px;">Fecha</th>
            <th style="padding: 10px;">Hora</th>
            <th style="padding: 10px;">Proceso</th>
            <th style="padding: 10px;">Referencia</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td style="padding: 8px;"><?= date('d/m/Y', strtotime($row['fecha_dia'])) ?></td>
                <td style="padding: 8px;"><?= $row['hora'] ?></td>
                <td style="padding: 8px;"><?= htmlspecialchars($row['proceso']) ?></td>
                <td style="padding: 8px;"><?= htmlspecialchars($row['referencia']) ?></td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="4" style="padding: 20px; text-align: center;">Sin registros</td></tr>
        <?php endif; ?>
    </tbody>
</table>