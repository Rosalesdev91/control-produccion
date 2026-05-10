<?php
/**
 * test_auditoria.php
 * Prueba completa del sistema de auditoría
 * 
 * Accede: http://localhost/control_produccion/public/test_auditoria.php
 */

// Incluir configuraciones
require_once dirname(__DIR__) . '/config/database.php';
require_once 'registrar_actividad.php';

session_start();

// Simular sesión de administrador para la prueba
$_SESSION['empleado'] = 'Admin Prueba';
$_SESSION['codigo_empleado'] = 'ADMIN001';
$_SESSION['rol'] = 'administrador';
$_SESSION['ip'] = '127.0.0.1';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Prueba de Auditoría</title>
    <style>
        body { font-family: Arial; background: #0a3d2a; color: white; padding: 20px; }
        .success { background: #006400; padding: 10px; margin: 10px 0; border-left: 4px solid #5cdf85; }
        .error { background: #8b0000; padding: 10px; margin: 10px 0; border-left: 4px solid #ff6b6b; }
        button { background: #5cdf85; color: #0a3d2a; padding: 10px 20px; margin: 5px; border: none; cursor: pointer; }
        button:hover { background: #4cbf6b; }
    </style>
</head>
<body>
    <h1>🧪 Prueba del Sistema de Auditoría</h1>
    <hr>
";

// Prueba 1: Registrar actividad simple (feed del dashboard)
echo "<h2>📝 Prueba 1: Actividad Simple (actividad_monitor)</h2>";
$result1 = registrar_actividad($conn, 'login', 'Admin Prueba', 'Prueba de inicio de sesión desde test');
if ($result1) {
    echo "<div class='success'>✅ Actividad simple registrada correctamente</div>";
} else {
    echo "<div class='error'>❌ Error al registrar actividad simple: " . $conn->error . "</div>";
}

// Prueba 2: Registrar cambio en auditoría
echo "<h2>📝 Prueba 2: Auditoría de Cambio (auditoria_cambios)</h2>";
$antes = [
    'nombre_empleado' => 'Juan Pérez',
    'rol' => 'empleado',
    'activo' => 1
];
$despues = [
    'nombre_empleado' => 'Juan Pérez R.',
    'rol' => 'administrador',
    'activo' => 1
];
$result2 = registrar_cambio_admin(
    $conn,
    'modificar',
    'empleados',
    'Modificó empleado Juan Pérez (ID #123)',
    $antes,
    $despues,
    123,
    'Admin Prueba',
    'ADMIN001'
);
if ($result2) {
    echo "<div class='success'>✅ Auditoría de cambio registrada correctamente</div>";
} else {
    echo "<div class='error'>❌ Error al registrar auditoría: " . $conn->error . "</div>";
}

// Prueba 3: Registrar acción completa (ambas tablas)
echo "<h2>📝 Prueba 3: Acción Completa (ambas tablas)</h2>";
$result3 = registrar_accion_completa(
    $conn,
    'agregar',
    'equipos',
    'Agregó nuevo equipo: Pulidora Industrial',
    [],
    ['codigo_equipo' => 'PUL-001', 'nombre_equipo' => 'Pulidora Industrial', 'activo' => 1],
    456
);
if ($result3 !== false) {
    echo "<div class='success'>✅ Acción completa registrada correctamente (ambas tablas)</div>";
} else {
    echo "<div class='error'>❌ Error en acción completa</div>";
}

// Verificar registros insertados
echo "<h2>📊 Verificación de registros</h2>";

// Contar registros en actividad_monitor
$count1 = $conn->query("SELECT COUNT(*) as total FROM actividad_monitor")->fetch_assoc()['total'];
echo "<div class='success'>📊 actividad_monitor: <strong>{$count1}</strong> registros</div>";

// Contar registros en auditoria_cambios
$count2 = $conn->query("SELECT COUNT(*) as total FROM auditoria_cambios")->fetch_assoc()['total'];
echo "<div class='success'>📊 auditoria_cambios: <strong>{$count2}</strong> registros</div>";

// Mostrar últimos registros
echo "<h2>📋 Últimos registros en auditoria_cambios</h2>";
$result = $conn->query("SELECT * FROM auditoria_cambios ORDER BY id DESC LIMIT 5");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; background: rgba(0,0,0,0.5);'>
            <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Tabla</th>
                <th>Descripción</th>
                <th>Admin</th>
                <th>Fecha</th>
            </tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>{$row['id']}</td>
                <td>{$row['tipo_accion']}</td>
                <td>{$row['tabla_afectada']}</td>
                <td>" . htmlspecialchars($row['descripcion']) . "</td>
                <td>{$row['admin_nombre']}</td>
                <td>{$row['fecha_hora']}</td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<div class='error'>No hay registros en auditoria_cambios</div>";
}

echo "<hr>
<div style='margin-top: 20px;'>
    <a href='auditoria_admin.php'><button>🔍 Ir al Panel de Auditoría</button></a>
    <a href='dashboard_monitor.php'><button>📡 Ir al Dashboard Monitor</button></a>
    <button onclick='location.reload()'>🔄 Ejecutar nuevamente</button>
</div>
</body>
</html>";
?>