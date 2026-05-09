<?php
session_start();
require_once '../config/database.php';
require_once 'registrar_actividad.php';

// ==================== SEGURIDAD BÁSICA ====================
if (!isset($_SESSION['codigo_empleado']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'empleado') {
    header("Location: login_2.php");
    exit();
}
// =========================================================

// Función para obtener quiebras con filtros
function obtenerQuiebrasFiltradas($conn, $filtros) {
    $sql = "SELECT * FROM registro_quiebras WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($filtros['id'])) {
        $sql .= " AND id LIKE ?";
        $params[] = "%" . $filtros['id'] . "%";
        $types .= "s";
    }
    if (!empty($filtros['orden'])) {
        $sql .= " AND orden LIKE ?";
        $params[] = "%" . $filtros['orden'] . "%";
        $types .= "s";
    }
    if (!empty($filtros['fecha_inicio'])) {
        $sql .= " AND DATE(fecha) >= ?";
        $params[] = $filtros['fecha_inicio'];
        $types .= "s";
    }
    if (!empty($filtros['fecha_fin'])) {
        $sql .= " AND DATE(fecha) <= ?";
        $params[] = $filtros['fecha_fin'];
        $types .= "s";
    }
    if (empty($filtros['fecha_inicio']) && empty($filtros['fecha_fin'])) {
        $sql .= " AND DATE(fecha) = CURDATE()";
    }

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $quiebras = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $quiebras;
}

// Filtros desde GET
$filtros = [
    'id' => $_GET['id'] ?? '',
    'orden' => $_GET['orden'] ?? '',
    'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
    'fecha_fin' => $_GET['fecha_fin'] ?? '',
    'editar_id' => $_GET['editar_id'] ?? ''
];

// Procesar edición
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_edicion'])) {
    $id = $_POST['editar_id'];
    $lado = $_POST['nuevo_lado_lente'] ?? '';

    if ($id && in_array($lado, ['OD', 'OI', 'AMBOS'])) {
        $stmt = $conn->prepare("UPDATE registro_quiebras SET lado_lente = ? WHERE id = ?");
        $stmt->bind_param("ss", $lado, $id);
        $stmt->execute();
        $stmt->close();

        // Redirigir manteniendo filtros (sin editar_id)
        $query = $_GET;
        unset($query['editar_id']);
        $redirect = http_build_query($query);
        header("Location: ?$redirect");
        exit();
    }
}

// Obtener datos
$filas = obtenerQuiebrasFiltradas($conn, $filtros);
$registroEditar = null;

if (!empty($filtros['editar_id'])) {
    $stmt = $conn->prepare("SELECT * FROM registro_quiebras WHERE id = ?");
    $stmt->bind_param("s", $filtros['editar_id']);
    $stmt->execute();
    $registroEditar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIA-LAB | Registro de Quiebras</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root{--g:#28a745;--dg:#218838;--lg:#d4fcd4;--bg:#0f3a18;--card:rgba(0,0,0,0.35);}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--lg);font-family:'Rajdhani',sans-serif;min-height:100vh;display:flex;flex-direction:column}
        .container{max-width:1300px;margin:auto;width:95%;padding:20px 0;flex:1}
        .header{text-align:center;margin-bottom:30px}
        .logo-img{width:180px;margin:0 auto 15px;display:block;filter:drop-shadow(0 0 10px var(--g))}
        h1{font:900 2.8rem 'Orbitron',sans-serif;background:linear-gradient(90deg,var(--g),var(--dg));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .bienvenida{font-size:1.3rem;margin:10px 0;color:#a8e6b3}
        .logout{position:absolute;top:20px;right:20px;background:var(--dg);padding:10px 18px;border-radius:50px;font-size:0.9rem}
        .logout a{color:white;text-decoration:none}
        .logout:hover{background:#1e7e34}
        
        form{background:var(--card);padding:25px;border-radius:15px;margin-bottom:25px;box-shadow:0 8px 25px rgba(0,0,0,0.4);backdrop-filter:blur(10px)}
        .filtros-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:20px}
        label{display:block;margin-bottom:8px;font-weight:700;font-size:1.1rem}
        input,select{width:100%;padding:14px;background:#fff;border:2px solid var(--g);border-radius:10px;font-size:1rem;color:#155724}
        input:focus,select:focus{outline:none;border-color:var(--dg);box-shadow:0 0 15px rgba(40,167,69,.6)}
        .btn-group{text-align:center;margin-top:15px}
        button{padding:14px 30px;background:var(--dg);color:white;border:none;border-radius:10px;font-size:1.1rem;cursor:pointer;margin:0 8px;transition:.3s}
        button:hover{background:#1e7e34;transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,0.4)}
        
        .table-container{background:var(--card);border-radius:15px;overflow:hidden;box-shadow:0 8px 25px rgba(0,0,0,0.4);margin-top:20px}
        table{width:100%;border-collapse:collapse;background:#f8fff9;color:#155724}
        th{background:var(--dg);color:white;padding:16px;font-size:1.1rem;position:sticky;top:0;z-index:10}
        td{padding:14px;text-align:center;font-weight:500}
        tr:nth-child(even){background:#e8f5e9}
        tr:hover{background:#c8e6c9}
        .edit-btn{background:#006400;color:white;border:none;padding:8px 14px;border-radius:8px;cursor:pointer}
        .edit-btn:hover{background:#004d00}
        
        .edit-mode{background:var(--card);padding:30px;border-radius:15px;margin-top:20px}
        .edit-mode h3{color:var(--lg);margin-bottom:20px}
        .volver{margin-top:20px;display:inline-block;color:#a8e6b3;text-decoration:none;font-size:1.1rem}
        .volver:hover{text-decoration:underline}
        
        .footer{background:#001a00;color:#a8e6b3;text-align:center;padding:20px;font-size:0.95rem;position:sticky;bottom:0;width:100%;border-top:1px solid var(--g)}
        .total{font-size:1.4rem;font-weight:700;margin:20px 0;color:var(--lg);text-align:center}
    </style>
</head>
<body>

<div class="logout">
    <a href="login_2.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
</div>

<div class="container">

    <div class="header">
        <img src="/control_produccion/public/logo.png" class="logo-img" alt="SIA-LAB">
        <h1>REGISTRO DE QUIEBRAS</h1>
        <p class="bienvenida">Bienvenid@, <?= htmlspecialchars($_SESSION['nombre_empleado'] ?? 'Empleado') ?></p>
    </div>

    <!-- Filtros -->
    <form method="GET">
        <div class="filtros-grid">
            <div>
                <label>📅 Fecha Inicio</label>
                <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($filtros['fecha_inicio']) ?>">
            </div>
            <div>
                <label>📅 Fecha Fin</label>
                <input type="date" name="fecha_fin" value="<?= htmlspecialchars($filtros['fecha_fin']) ?>">
            </div>
            <div>
                <label>🔢 ID Registro</label>
                <input type="text" name="id" placeholder="Buscar por ID" value="<?= htmlspecialchars($filtros['id']) ?>">
            </div>
            <div>
                <label>📋 N° Orden</label>
                <input type="text" name="orden" placeholder="Buscar por orden" value="<?= htmlspecialchars($filtros['orden']) ?>">
            </div>
        </div>
        <div class="btn-group">
            <button type="submit">🔍 Aplicar Filtros</button>
            <button type="button" onclick="window.location.href='?'">🔄 Limpiar Todo</button>
        </div>
    </form>

    <?php if ($registroEditar): ?>
        <!-- Modo Edición -->
        <div class="edit-mode">
            <h3>✏️ Editando Registro ID: <strong><?= htmlspecialchars($registroEditar['id']) ?></strong></h3>
            <table>
                <tr><th>ID</th><td><?= htmlspecialchars($registroEditar['id']) ?></td></tr>
                <tr><th>Orden</th><td><?= htmlspecialchars($registroEditar['orden']) ?></td></tr>
                <tr><th>Motivo</th><td><?= htmlspecialchars($registroEditar['motivo']) ?></td></tr>
                <tr><th>Defecto</th><td><?= htmlspecialchars($registroEditar['porque_defecto']) ?></td></tr>
                <tr><th>Lado Actual</th><td><strong><?= htmlspecialchars($registroEditar['lado_lente']) ?></strong></td></tr>
                <tr><th>Fecha</th><td><?= date('d/m/Y h:i A', strtotime($registroEditar['fecha'] . ' ' . $registroEditar['hora'])) ?></td></tr>
            </table>

            <form method="POST" style="margin-top:25px;">
                <input type="hidden" name="editar_id" value="<?= htmlspecialchars($registroEditar['id']) ?>">
                <?php foreach ($filtros as $k => $v): if ($k !== 'editar_id' && $v !== ''): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
                <?php endif; endforeach; ?>
                <label><strong>Nuevo Lado del Lente:</strong></label>
                <select name="nuevo_lado_lente" style="width:100%;max-width:300px;margin:15px 0;" required>
                    <option value="OD" <?= $registroEditar['lado_lente']==='OD'?'selected':'' ?>>Derecho (OD)</option>
                    <option value="OI" <?= $registroEditar['lado_lente']==='OI'?'selected':'' ?>>Izquierdo (OI)</option>
                    <option value="AMBOS" <?= $registroEditar['lado_lente']==='AMBOS'?'selected':'' ?>>Ambos</option>
                </select>
                <div class="btn-group">
                    <button type="submit" name="guardar_edicion">💾 Guardar Cambio</button>
                    <a href="?" class="volver">← Volver a la lista</a>
                </div>
            </form>
        </div>

<?php else: ?>
    <!-- Lista normal -->
    <div class="total">
        Total de Quiebras Hoy: <strong><?= count($filas) ?></strong> registro<?= count($filas) !== 1 ? 's' : '' ?>
    </div>

    <?php if (!empty($filas)): ?>
        <div class="table-container">
            <!-- NUEVO CONTENEDOR CON SCROLL VERTICAL -->
            <div style="max-height: 65vh; overflow-y: auto; border-radius: 15px 15px 0 0;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Registro</th>
                            <th>Orden</th>
                            <th>Equipo</th>
                            <th>Motivo</th>
                            <th>Defecto</th>
                            <th>Lado</th>
                            <th>Fecha y Hora</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filas as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['id']) ?></td>
                            <td><?= htmlspecialchars($r['empleado_registro']) ?></td>
                            <td><?= htmlspecialchars($r['orden']) ?></td>
                            <td><?= htmlspecialchars($r['equipo']) ?></td>
                            <td><?= htmlspecialchars($r['motivo']) ?></td>
                            <td><?= htmlspecialchars($r['porque_defecto']) ?></td>
                            <td><strong><?= $r['lado_lente'] ?></strong></td>
                            <td><?= date('d/m/Y h:i A', strtotime($r['fecha'] . ' ' . $r['hora'])) ?></td>
                            <td>
                                <form method="GET" style="display:inline;">
                                    <input type="hidden" name="editar_id" value="<?= $r['id'] ?>">
                                    <?php foreach ($filtros as $k=>$v): if($k!=='editar_id' && $v!==''): ?>
                                        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
                                    <?php endif; endforeach; ?>
                                    <button type="submit" class="edit-btn" title="Editar lado del lente">✏️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- FIN DEL CONTENEDOR CON SCROLL -->
        </div>
    <?php else: ?>
        <p style="text-align:center;font-size:1.3rem;margin:50px;color:#a8e6b3;">
            No se encontraron registros con los filtros aplicados.
        </p>
    <?php endif; ?>
<?php endif; ?>

</div>

<div class="footer">
    Sistema de Control de Quiebras © <?= date("Y") ?> • Desarrollado por Nestor Rosales
</div>

<!-- Tracking de navegación para monitor en vivo -->
<script>
(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            modulo: pagina, 
            pagina: window.location.pathname 
        })
    }).catch(err => console.log('Tracking error:', err));
})();
</script>
</body>
</html>

<?php
$conn->close();
?>