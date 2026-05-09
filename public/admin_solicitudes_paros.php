<?php
// admin_solicitudes_paros.php
session_start();
require_once '../config/database.php';
require_once 'registrar_actividad.php';

// Verificar sesión de administrador
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header('Location: login_admin.php');
    exit;
}

$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Costa_Rica');

// ==================== FILTROS ====================
$fecha_desde        = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta        = $_GET['fecha_hasta'] ?? date('Y-m-d');
$area_filtro        = $_GET['area'] ?? '';
$estado_filtro      = $_GET['estado'] ?? '';
$empleado_filtro    = $_GET['empleado'] ?? '';
$tipo_paro_filtro   = $_GET['tipo_paro'] ?? '';

// ==================== LISTAS PARA FILTROS ====================
$ESTADOS = ['pendiente'=>'Pendiente', 'iniciada'=>'Iniciada', 'finalizada'=>'Finalizada', 'rechazada'=>'Rechazada'];
$AREAS = $TIPOS_PARO = $TIPOS_NO_APLICA = $EMPLEADOS = [];

$res = $conn->query("SELECT DISTINCT area FROM areas ORDER BY area");
while ($r = $res->fetch_assoc()) $AREAS[] = $r['area'];

$res = $conn->query("SELECT nombre FROM tipos_paro WHERE nombre != 'Sin WIP' ORDER BY nombre");
while ($r = $res->fetch_assoc()) $TIPOS_PARO[] = $r['nombre'];

$res = $conn->query("SELECT nombre FROM tipos_no_aplica ORDER BY nombre");
while ($r = $res->fetch_assoc()) $TIPOS_NO_APLICA[] = $r['nombre'];

$res = $conn->query("SELECT DISTINCT empleado FROM solicitudes_paro WHERE empleado IS NOT NULL ORDER BY empleado");
while ($r = $res->fetch_assoc()) $EMPLEADOS[] = $r['empleado'];

// ==================== CONSULTA PRINCIPAL ====================
$query = "
    SELECT 
        sp.id, sp.empleado, sp.area, sp.equipo, sp.motivo, sp.fecha_solicitud, sp.estado, sp.motivo_rechazo,
        sp.tipo_paro AS nombre_tipo_paro,
        pp.fecha_inicio, pp.fecha_fin, pp.comentario_final, t.nombre_tecnico, pp.id_tecnico,

        -- Tiempo de espera (desde solicitud hasta inicio o rechazo)
        CASE 
            WHEN sp.estado = 'pendiente' THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, NOW())
            WHEN sp.estado IN ('iniciada', 'finalizada') AND pp.fecha_inicio IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_inicio)
            WHEN sp.estado = 'rechazada' AND sp.fecha_rechazo IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, sp.fecha_rechazo)
            ELSE NULL 
        END AS tiempo_espera_min,

        -- Duración del paro (solo cuando ya inició)
        CASE 
            WHEN pp.fecha_inicio IS NOT NULL AND pp.fecha_fin IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin)
            WHEN pp.fecha_inicio IS NOT NULL AND sp.estado = 'iniciada' THEN TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, NOW())
            ELSE NULL 
        END AS duracion_min

    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id_paro = pp.id
    LEFT JOIN tecnicos t ON pp.id_tecnico = t.id
    WHERE DATE(sp.fecha_solicitud) BETWEEN ? AND ?
    AND sp.tipo_paro != 'Sin WIP' -- <--- EXCLUIR SOLICITUDES DE TIPO 'Sin WIP'
";

$params = [$fecha_desde, $fecha_hasta];
$types  = "ss";

if ($area_filtro)      { $query .= " AND sp.area = ?";        $params[] = $area_filtro;      $types .= "s"; }
if ($estado_filtro)    { $query .= " AND sp.estado = ?";      $params[] = $estado_filtro;    $types .= "s"; }
if ($empleado_filtro)  { $query .= " AND sp.empleado = ?";    $params[] = $empleado_filtro;  $types .= "s"; }
if ($tipo_paro_filtro) { 
    $query .= " AND sp.tipo_paro = ?";
    $params[] = $tipo_paro_filtro;
    $types .= "s";
}

$query .= " ORDER BY 
    CASE WHEN sp.estado = 'pendiente' THEN 1 
         WHEN sp.estado = 'iniciada' THEN 2 
         WHEN sp.estado = 'finalizada' THEN 3 
         ELSE 4 END,
    sp.fecha_solicitud DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$solicitudes = [];
while ($row = $result->fetch_assoc()) {
    $row['tiempo_espera_formateado'] = $row['tiempo_espera_min'] !== null ? formatearTiempo($row['tiempo_espera_min']) : '-';
    $row['duracion_formateada'] = $row['duracion_min'] !== null ? formatearTiempo($row['duracion_min']) : '-';
    $row['fecha_solicitud_fmt'] = date('d/m/Y H:i', strtotime($row['fecha_solicitud']));
    $row['fecha_inicio_fmt'] = $row['fecha_inicio'] && $row['fecha_inicio'] !== '0000-00-00 00:00:00' ? date('d/m/Y H:i', strtotime($row['fecha_inicio'])) : '-';
    $row['fecha_fin_fmt'] = $row['fecha_fin'] && $row['fecha_fin'] !== '0000-00-00 00:00:00' ? date('d/m/Y H:i', strtotime($row['fecha_fin'])) : '-';
    $solicitudes[] = $row;
}
$stmt->close();

function formatearTiempo($minutos) {
    if (is_null($minutos)) return '-';
    $horas = floor($minutos / 60);
    $mins = $minutos % 60;
    return $horas > 0 ? "$horas h $mins min" : "$mins min";
}

// Contadores para estadísticas (excluyendo 'Sin WIP')
$pending_count = $conn->query("SELECT COUNT(*) FROM solicitudes_paro WHERE estado='pendiente' AND tipo_paro != 'Sin WIP'")->fetch_row()[0];
$stats = $conn->query("
    SELECT 
        COUNT(CASE WHEN estado = 'pendiente' AND tipo_paro != 'Sin WIP' THEN 1 END) AS pendientes,
        COUNT(CASE WHEN estado = 'iniciada' AND tipo_paro != 'Sin WIP' THEN 1 END) AS en_progreso,
        COUNT(CASE WHEN estado = 'finalizada' AND tipo_paro != 'Sin WIP' AND DATE(fecha_solicitud) = CURDATE() THEN 1 END) AS finalizadas_hoy
    FROM solicitudes_paro
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Visualización de Solicitudes de Paros</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root{
            --p:#007bff;--s:#28a745;--d:#dc3545;--w:#ffc107;--g:#6c757d;
            --l:#f8f9fa;--b:#dee2e6;--r:12px;--sh:0 4px 15px rgba(0,0,0,.1);
        }
        body{
            font-family:Segoe UI,sans-serif;
            background:linear-gradient(135deg,#f5f7fa,#c3cfe2);
            margin:0;
            display:flex;
            flex-direction:column;
            min-height:100vh;
            color:#333;
        }
        .header{
            background:var(--p);
            color:#fff;
            padding:15px 0;
            box-shadow:0 4px 10px rgba(0,0,0,.2);
        }
        .header-content{
            max-width:1300px;
            margin:auto;
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:0 20px;
        }
        .main{
            max-width:1300px;
            margin:20px auto;
            padding:0 15px;
            flex:1;
        }
        .alert{
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
        }
        .alert-pending{
            background:#fff3cd;
            color:#856404;
            border-left:5px solid var(--w);
            animation:pulse 2s infinite;
        }
        @keyframes pulse{
            0%,100%{transform:scale(1);}
            50%{transform:scale(1.02);}
        }
        .card{
            background:#fff;
            border-radius:var(--r);
            box-shadow:var(--sh);
            margin-bottom:25px;
            overflow:hidden;
        }
        .card-header{
            background:#f8f9fa;
            padding:15px 20px;
            font-weight:600;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }
        .stats{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
            gap:15px;
            margin-bottom:25px;
        }
        .stat{
            background:#fff;
            padding:20px;
            text-align:center;
            border-radius:var(--r);
            box-shadow:var(--sh);
        }
        .stat-number{
            font-size:2.2em;
            font-weight:700;
            color:var(--p);
        }
        .filters{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
            gap:15px;
            padding:20px;
        }
        .form-control{
            width:100%;
            padding:10px;
            border:1px solid var(--b);
            border-radius:8px;
        }
        .btn{
            padding:10px 20px;
            border:none;
            border-radius:8px;
            cursor:pointer;
            margin:2px;
            font-size:13px;
            text-decoration:none;
            display:inline-block;
            text-align:center;
        }
        .btn-primary{
            background:var(--p);
            color:#fff;
        }
        .btn-secondary{
            background:var(--g);
            color:#fff;
        }
        .table{
            width:100%;
            border-collapse:collapse;
            font-size:14px;
        }
        .table th,.table td{
            padding:10px;
            border-bottom:1px solid var(--b);
            text-align:left;
        }
        .table th{
            background:#f8f9fa;
            position:sticky;
            top:0;
        }
        .badge{
            padding:4px 8px;
            border-radius:8px;
            font-size:12px;
            font-weight:600;
        }
        .badge-warning{background:var(--w);color:#856404;}
        .badge-success{background:var(--s);color:#fff;}
        .badge-danger{background:var(--d);color:#fff;}
        .badge-secondary{background:var(--g);color:#fff;}
        .footer{
            background:var(--p);
            color:#fff;
            text-align:center;
            padding:15px;
            margin-top:auto;
        }
        .view-only-banner {
            background:linear-gradient(135deg,#667eea,#764ba2);
            color:white;
            padding:10px;
            text-align:center;
            font-weight:600;
            margin-bottom:15px;
            border-radius:var(--r);
        }
        .info-badge {
            background:linear-gradient(135deg,#48bb78,#38a169);
            color:white;
            padding:8px 15px;
            border-radius:20px;
            font-size:12px;
            font-weight:600;
            display:inline-block;
            margin-bottom:15px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div>
                <img src="/control_produccion/public/logo.png" height="50" alt="Logo">
                <span style="margin-left:15px;font-size:18px;">Panel Administrativo</span>
            </div>
            <div style="display:flex;gap:20px;align-items:center;">
                <div style="background:rgba(255,255,255,.15);padding:8px 15px;border-radius:8px;">
                    Admin: <?=htmlspecialchars($_SESSION['empleado'] ?? 'Administrador')?>
                </div>
                <div class="clock" id="reloj"></div>
                <a href="dashboard_admin_paros.php" class="btn btn-secondary">Volver al Dashboard</a>
                <a href="login_admin.php?logout=1" class="btn btn-danger">Cerrar Sesión</a>
            </div>
        </div>
    </header>

    <div class="main">
        <div class="view-only-banner">
            <i class="fas fa-eye"></i> MODO SOLO LECTURA - Visualización de solicitudes de paros
        </div>

        <div class="info-badge">
            <i class="fas fa-info-circle"></i> Se excluyen automáticamente las solicitudes de tipo "Sin WIP"
        </div>

        <?php if($pending_count > 0): ?>
            <div class="alert alert-pending">
                <i class="fas fa-exclamation-triangle"></i> 
                ¡ATENCIÓN! Hay <?=$pending_count?> solicitud(es) pendiente(s) de atención por los técnicos.
            </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat">
                <div class="stat-number"><?=$stats['pendientes']??0?></div>
                <div>Pendientes</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?=$stats['en_progreso']??0?></div>
                <div>En Progreso</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?=$stats['finalizadas_hoy']??0?></div>
                <div>Finalizadas Hoy</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Filtros de Búsqueda</div>
            <form method="GET">
                <div class="filters">
                    <div>
                        <label>Desde</label>
                        <input type="date" name="fecha_desde" class="form-control" value="<?=$fecha_desde?>" required>
                    </div>
                    <div>
                        <label>Hasta</label>
                        <input type="date" name="fecha_hasta" class="form-control" value="<?=$fecha_hasta?>" required>
                    </div>
                    <div>
                        <label>Área</label>
                        <select name="area" class="form-control">
                            <option value="">Todas las áreas</option>
                            <?php foreach($AREAS as $area): ?>
                                <option value="<?=$area?>" <?=($area_filtro==$area?'selected':'')?>><?=htmlspecialchars($area)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Estado</label>
                        <select name="estado" class="form-control">
                            <option value="">Todos los estados</option>
                            <?php foreach($ESTADOS as $k=>$v): ?>
                                <option value="<?=$k?>" <?=($estado_filtro==$k?'selected':'')?>><?=$v?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tipo de Paro</label>
                        <select name="tipo_paro" class="form-control">
                            <option value="">Todos</option>
                            <?php foreach($TIPOS_PARO as $t): ?>
                                <option <?=($tipo_paro_filtro==$t?'selected':'')?>><?=htmlspecialchars($t)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Empleado</label>
                        <select name="empleado" class="form-control">
                            <option value="">Todos</option>
                            <?php foreach($EMPLEADOS as $emp): ?>
                                <option <?=($empleado_filtro==$emp?'selected':'')?>><?=htmlspecialchars($emp)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="padding:20px;text-align:right;background:#f8f9fa;">
                    <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                    <a href="admin_solicitudes_paros.php" class="btn btn-secondary">Limpiar Todo</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                Solicitudes de Paros - Vista Administrativa
                <span><?=count($solicitudes)?> registro(s) encontrado(s)</span>
            </div>
            <div style="overflow-x:auto;padding:10px;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha Solicitud</th>
                            <th>Empleado</th>
                            <th>Área</th>
                            <th>Equipo</th>
                            <th>Tipo Paro</th>
                            <th>Motivo</th>
                            <th>Estado</th>
                            <th>Tiempo Espera</th>
                            <th>Duración Paro</th>
                            <th>Técnico</th>
                            <th>Inicio</th>
                            <th>Fin</th>
                            <th>Comentario Final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($solicitudes)): ?>
                            <tr>
                                <td colspan="14" style="text-align:center;padding:50px;color:#999;font-size:16px;">
                                    <i class="fas fa-inbox" style="font-size:48px;margin-bottom:15px;display:block;"></i>
                                    No se encontraron solicitudes con los filtros aplicados.
                                </td>
                            </tr>
                        <?php else: foreach($solicitudes as $s): ?>
                            <tr style="background:#<?= $s['estado']=='pendiente'?'fff8e1':($s['estado']=='iniciada'?'e8f5e9':'fff') ?>;">
                                <td><strong>#<?=$s['id']?></strong></td>
                                <td><?=$s['fecha_solicitud_fmt']?></td>
                                <td><?=htmlspecialchars($s['empleado'])?></td>
                                <td><?=htmlspecialchars($s['area'])?></td>
                                <td><?=htmlspecialchars($s['equipo'])?></td>
                                <td><small><?=htmlspecialchars($s['nombre_tipo_paro'] ?? 'Sin tipo definido')?></small></td>
                                <td title="<?=htmlspecialchars($s['motivo'])?>">
                                    <?=strlen($s['motivo'])>40 ? htmlspecialchars(substr($s['motivo'],0,40)).'...' : htmlspecialchars($s['motivo'])?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $s['estado']=='pendiente'?'warning':($s['estado']=='iniciada'?'success':($s['estado']=='finalizada'?'secondary':'danger')) ?>;">
                                        <?=ucfirst(str_replace('_', ' ', $s['estado']))?>
                                    </span>
                                    <?php if($s['estado']=='rechazada' && $s['motivo_rechazo']): ?>
                                        <br><small style="color:#dc3545;" title="<?=htmlspecialchars($s['motivo_rechazo'])?>">
                                            Rechazado: <?=htmlspecialchars(substr($s['motivo_rechazo'],0,30))?>...
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($s['tiempo_espera_min'] !== null): ?>
                                        <span class="badge <?= $s['tiempo_espera_min'] > 30 ? 'badge-danger' : 'badge-warning' ?>;">
                                            <?=$s['tiempo_espera_formateado']?>
                                        </span>
                                    <?php else: ?> - <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($s['duracion_min'] !== null): ?>
                                        <span class="badge badge-success"><?=$s['duracion_formateada']?></span>
                                    <?php else: ?> - <?php endif; ?>
                                </td>
                                <td><?=htmlspecialchars($s['nombre_tecnico'] ?? '-')?></td>
                                <td><?=$s['fecha_inicio_fmt']?></td>
                                <td><?=$s['fecha_fin_fmt']?></td>
                                <td><small><?=htmlspecialchars($s['comentario_final'] ?? '')?></small></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer class="footer">
        Sistema de Control de Paros - Vista Administrativa © <?= date("Y"); ?> | Desarrollado por Nestor Rosales - Rosales_Dev91
    </footer>

    <script>
        function actualizarReloj() {
            const ahora = new Date();
            const opciones = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            };
            document.getElementById('reloj').textContent = ahora.toLocaleDateString('es-CR', opciones).replace(/,/g, '');
        }
        setInterval(actualizarReloj, 1000);
        actualizarReloj();

        // Auto-refresh cada 2 minutos para mantener datos actualizados
        setInterval(() => {
            location.reload();
        }, 120000);
    </script>

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
<?php $conn->close(); ?>