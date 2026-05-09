<?php
session_start();

// Validación de sesión
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

require_once '../config/database.php';
require_once 'auto_audit.php';
require_once 'registrar_actividad.php';

$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');

// Exportar CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Filtros base (sin paginación)
    $fecha_inicio   = $_GET['fecha_inicio'] ?? '';
    $fecha_fin      = $_GET['fecha_fin'] ?? '';
    $estado_filtro  = $_GET['estado'] ?? '';

    $where = []; $params_csv = []; $types_csv = '';
    if ($fecha_inicio) { $where[] = "c.fecha_check >= ?"; $params_csv[] = "$fecha_inicio 00:00:00"; $types_csv .= 's'; }
    if ($fecha_fin)    { $where[] = "c.fecha_check <= ?"; $params_csv[] = "$fecha_fin 23:59:59";   $types_csv .= 's'; }
    if ($estado_filtro){ $where[] = "c.estado = ?";       $params_csv[] = $estado_filtro;           $types_csv .= 's'; }
    $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_checks_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM para UTF-8

    // Headers CSV
    fputcsv($output, ['Fecha', 'Orden', 'Equipo Origen', 'Material', 'Verificador', 'Estado', 'Motivo', 'Equipos', 'Plan de acción']);

    // Query para CSV (sin LIMIT/OFFSET)
    $sql_csv = "
        SELECT
            c.id, c.orden, c.equipo_origen, c.material, c.empleado_verificador, c.estado, c.motivo,
            c.observaciones, c.equipos_causantes, c.fecha_check
        FROM check_pruebas c
        $where_clause
        ORDER BY c.fecha_check DESC
    ";
    $stmt_csv = $conn->prepare($sql_csv);
    if ($params_csv) $stmt_csv->bind_param($types_csv, ...$params_csv);
    $stmt_csv->execute();
    $result_csv = $stmt_csv->get_result();

    while ($row = $result_csv->fetch_assoc()) {
        $equipos = $row['equipos_causantes'] ? json_decode($row['equipos_causantes'], true) : [];
        $equipos_str = is_array($equipos) ? implode(', ', $equipos) : '—';
        fputcsv($output, [
            date('d/m/Y H:i', strtotime($row['fecha_check'])),
            $row['orden'],
            $row['equipo_origen'] ?? '—',
            $row['material'] ?? '—',
            $row['empleado_verificador'],
            $row['estado'] === 'conforme' ? 'OK' : 'NC',
            $row['motivo'] ?? '—',
            $equipos_str,
            $row['observaciones'] ?? '—'
        ]);
    }
    $stmt_csv->close();
    exit();
}

/* ================================
   FILTROS
================================ */
$fecha_inicio   = $_GET['fecha_inicio'] ?? '';
$fecha_fin      = $_GET['fecha_fin'] ?? '';
$estado_filtro  = $_GET['estado'] ?? '';
$pagina         = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina     = 50;
$offset         = ($pagina - 1) * $por_pagina;

/* ================================
   FILTROS PARA MÉTRICAS Y GRÁFICOS (solo fechas)
================================ */
$where_metrics = []; $params_metrics = []; $types_metrics = '';
if ($fecha_inicio) { 
    $where_metrics[] = "fecha_check >= ?"; 
    $params_metrics[] = "$fecha_inicio 00:00:00"; 
    $types_metrics .= 's'; 
}
if ($fecha_fin) {    
    $where_metrics[] = "fecha_check <= ?"; 
    $params_metrics[] = "$fecha_fin 23:59:59";   
    $types_metrics .= 's'; 
}
$where_metrics_clause = $where_metrics ? 'WHERE ' . implode(' AND ', $where_metrics) : '';

/* ================================
   MÉTRICAS GENERALES (filtradas por fechas)
================================ */
$stmt = $conn->prepare("SELECT COUNT(*) FROM check_pruebas $where_metrics_clause");
if ($params_metrics) $stmt->bind_param($types_metrics, ...$params_metrics);
$stmt->execute();
$total_checks = $stmt->get_result()->fetch_row()[0];
$stmt->close();

/* Conforme */
$where_conforme = $where_metrics;
$params_conforme = $params_metrics;
$types_conforme = $types_metrics;
$where_conforme[] = "estado = ?";
$params_conforme[] = 'conforme';
$types_conforme .= 's';
$where_conforme_clause = $where_conforme ? 'WHERE ' . implode(' AND ', $where_conforme) : '';
$stmt = $conn->prepare("SELECT COUNT(*) FROM check_pruebas $where_conforme_clause");
if ($params_conforme) $stmt->bind_param($types_conforme, ...$params_conforme);
$stmt->execute();
$conforme_checks = $stmt->get_result()->fetch_row()[0];
$stmt->close();

/* No Conforme */
$where_noconforme = $where_metrics;
$params_noconforme = $params_metrics;
$types_noconforme = $types_metrics;
$where_noconforme[] = "estado = ?";
$params_noconforme[] = 'no_conforme';
$types_noconforme .= 's';
$where_noconforme_clause = $where_noconforme ? 'WHERE ' . implode(' AND ', $where_noconforme) : '';
$stmt = $conn->prepare("SELECT COUNT(*) FROM check_pruebas $where_noconforme_clause");
if ($params_noconforme) $stmt->bind_param($types_noconforme, ...$params_noconforme);
$stmt->execute();
$noconforme_checks = $stmt->get_result()->fetch_row()[0];
$stmt->close();

/* ================================
   NO CONFORME: POR MOTIVO (Top 10, filtrado por fechas)
================================ */
$noconforme_por_motivo = [];
$where_motivo = $where_metrics;
$params_motivo = $params_metrics;
$types_motivo = $types_metrics;
$where_motivo[] = "estado = ?";
$params_motivo[] = 'no_conforme';
$types_motivo .= 's';
$where_motivo[] = "motivo IS NOT NULL";
$where_motivo_clause = $where_motivo ? 'WHERE ' . implode(' AND ', $where_motivo) : '';
$stmt = $conn->prepare("
    SELECT motivo, COUNT(*) as count 
    FROM check_pruebas 
    $where_motivo_clause
    GROUP BY motivo 
    ORDER BY count DESC 
    LIMIT 10
");
if ($params_motivo) $stmt->bind_param($types_motivo, ...$params_motivo);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $noconforme_por_motivo[$row['motivo']] = (int)$row['count'];
}
$stmt->close();

/* ================================
   NO CONFORME: POR EQUIPO (filtrado por fechas)
================================ */
$noconforme_por_equipo = [];
$where_equipo = $where_metrics;
$params_equipo = $params_metrics;
$types_equipo = $types_metrics;
$where_equipo[] = "estado = ?";
$params_equipo[] = 'no_conforme';
$types_equipo .= 's';
$where_equipo[] = "equipos_causantes IS NOT NULL";
$where_equipo[] = "equipos_causantes != 'null'";
$where_equipo_clause = $where_equipo ? 'WHERE ' . implode(' AND ', $where_equipo) : '';
$stmt = $conn->prepare("
    SELECT equipos_causantes, COUNT(*) as count 
    FROM check_pruebas 
    $where_equipo_clause
    GROUP BY equipos_causantes
");
if ($params_equipo) $stmt->bind_param($types_equipo, ...$params_equipo);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $equipos = json_decode($row['equipos_causantes'], true);
    if (is_array($equipos)) {
        foreach ($equipos as $eq) {
            $eq = trim($eq);
            if ($eq) {
                $noconforme_por_equipo[$eq] = ($noconforme_por_equipo[$eq] ?? 0) + (int)$row['count'];
            }
        }
    }
}
$stmt->close();

/* ================================
   CONFORME: POR EQUIPO (filtrado por fechas)
================================ */
$conforme_por_equipo = [];
$where_equipo_con = $where_metrics;
$params_equipo_con = $params_metrics;
$types_equipo_con = $types_metrics;
$where_equipo_con[] = "estado = ?";
$params_equipo_con[] = 'conforme';
$types_equipo_con .= 's';
$where_equipo_con[] = "equipos_causantes IS NOT NULL";
$where_equipo_con[] = "equipos_causantes != 'null'";
$where_equipo_con_clause = $where_equipo_con ? 'WHERE ' . implode(' AND ', $where_equipo_con) : '';
$stmt = $conn->prepare("
    SELECT equipos_causantes, COUNT(*) as count 
    FROM check_pruebas 
    $where_equipo_con_clause
    GROUP BY equipos_causantes
");
if ($params_equipo_con) $stmt->bind_param($types_equipo_con, ...$params_equipo_con);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $equipos = json_decode($row['equipos_causantes'], true);
    if (is_array($equipos)) {
        foreach ($equipos as $eq) {
            $eq = trim($eq);
            if ($eq) {
                $conforme_por_equipo[$eq] = ($conforme_por_equipo[$eq] ?? 0) + (int)$row['count'];
            }
        }
    }
}
$stmt->close();

// Top 10 equipos por total
$equipos_total = [];
foreach (array_unique(array_merge(array_keys($noconforme_por_equipo), array_keys($conforme_por_equipo))) as $eq) {
    $total = ($conforme_por_equipo[$eq] ?? 0) + ($noconforme_por_equipo[$eq] ?? 0);
    if ($total > 0) {
        $equipos_total[$eq] = $total;
    }
}
arsort($equipos_total);
$top_equipos = array_slice(array_keys($equipos_total), 0, 10, true);
$top_conforme = []; $top_noconforme = [];
foreach ($top_equipos as $eq) {
    $top_conforme[] = $conforme_por_equipo[$eq] ?? 0;
    $top_noconforme[] = $noconforme_por_equipo[$eq] ?? 0;
}

/* ================================
   COMPARATIVA: POR EQUIPO ORIGEN (Top 10, filtrado por fechas)
================================ */
$top_equipo_origen = [];
$top_conforme_eo = [];
$top_noconforme_eo = [];
$where_eo = $where_metrics;
$params_eo = $params_metrics;
$types_eo = $types_metrics;
$where_eo[] = "equipo_origen IS NOT NULL";
$where_eo[] = "equipo_origen != ''";
$where_eo_clause = $where_eo ? 'WHERE ' . implode(' AND ', $where_eo) : '';
$stmt = $conn->prepare("
    SELECT equipo_origen, 
           SUM(CASE WHEN estado = 'conforme' THEN 1 ELSE 0 END) as conf,
           SUM(CASE WHEN estado = 'no_conforme' THEN 1 ELSE 0 END) as nconf
    FROM check_pruebas 
    $where_eo_clause
    GROUP BY equipo_origen 
    ORDER BY (SUM(CASE WHEN estado = 'conforme' THEN 1 ELSE 0 END) + SUM(CASE WHEN estado = 'no_conforme' THEN 1 ELSE 0 END)) DESC 
    LIMIT 10
");
if ($params_eo) $stmt->bind_param($types_eo, ...$params_eo);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $top_equipo_origen[] = $row['equipo_origen'];
    $top_conforme_eo[] = (int)$row['conf'];
    $top_noconforme_eo[] = (int)$row['nconf'];
}
$stmt->close();

/* ================================
   NO CONFORME: POR MATERIAL (Top 10, filtrado por fechas)
================================ */
$noconforme_por_material = [];
$where_material = $where_metrics;
$params_material = $params_metrics;
$types_material = $types_metrics;
$where_material[] = "estado = ?";
$params_material[] = 'no_conforme';
$types_material .= 's';
$where_material[] = "material IS NOT NULL";
$where_material[] = "material != ''";
$where_material_clause = $where_material ? 'WHERE ' . implode(' AND ', $where_material) : '';
$stmt = $conn->prepare("
    SELECT material, COUNT(*) as count 
    FROM check_pruebas 
    $where_material_clause
    GROUP BY material 
    ORDER BY count DESC 
    LIMIT 10
");
if ($params_material) $stmt->bind_param($types_material, ...$params_material);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $noconforme_por_material[$row['material']] = (int)$row['count'];
}
$stmt->close();

/* ================================
   FILTROS PARA TABLA (fechas + estado)
================================ */
$where = []; $params_base = []; $types_base = '';
if ($fecha_inicio) { $where[] = "c.fecha_check >= ?"; $params_base[] = "$fecha_inicio 00:00:00"; $types_base .= 's'; }
if ($fecha_fin)    { $where[] = "c.fecha_check <= ?"; $params_base[] = "$fecha_fin 23:59:59";   $types_base .= 's'; }
if ($estado_filtro){ $where[] = "c.estado = ?";       $params_base[] = $estado_filtro;           $types_base .= 's'; }
$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ================================
   CONTEO TOTAL (paginación)
================================ */
$stmt = $conn->prepare("SELECT COUNT(*) FROM check_pruebas c $where_clause");
if ($params_base) $stmt->bind_param($types_base, ...$params_base);
$stmt->execute();
$total_registros = $stmt->get_result()->fetch_row()[0];
$stmt->close();
$total_paginas = max(1, ceil($total_registros / $por_pagina));

/* ================================
   DATOS DE LA TABLA (solo columnas necesarias)
================================ */
$params = $params_base;
$types = $types_base;
$sql = "
    SELECT 
        c.id, c.orden, c.equipo_origen, c.material, c.empleado_verificador, c.estado, c.motivo, 
        c.observaciones, c.equipos_causantes, c.fecha_check
    FROM check_pruebas c
    $where_clause
    ORDER BY c.fecha_check DESC
    LIMIT ? OFFSET ?
";
$params[] = $por_pagina; $params[] = $offset; $types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$registros = [];
while ($row = $result->fetch_assoc()) {
    $equipos = $row['equipos_causantes'] ? json_decode($row['equipos_causantes'], true) : [];
    $row['equipos_str'] = is_array($equipos) ? implode(', ', array_map('htmlspecialchars', $equipos)) : '—';
    $registros[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Check de Calidad</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #e8f5e8; /* Fondo claro y armónico, verde muy suave */
            --card: #f0f8f0; /* Tarjetas con verde aún más suave */
            --success: #28a745; /* Verde éxito mantenido */
            --danger: #dc3545; /* Rojo peligro mantenido, pero equilibrado */
            --primary: #198754; /* Verde primario más vivo y armónico */
            --primary-hover: #146c43; /* Hover más oscuro pero suave */
            --text: #155724; /* Texto principal en verde oscuro para contraste */
            --text-strong: #0d4424; /* Texto fuerte más profundo */
            --border: #9fd09f; /* Bordes suaves en verde claro */
            --muted: #6c9d6c; /* Muted en verde medio */
            --input-bg: #ffffff; /* Fondo inputs blanco */
            --input-text: #155724; /* Texto inputs en verde oscuro */
            --counter: #6c9d6c; /* Counter en muted */
            --hover: #d4e8d4; /* Hover suave en verde claro */
        }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, Arial, sans-serif; font-size: 0.875rem; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); } /* Sombra más suave */
        .card-header { background: var(--primary); color: white; font-weight: 600; font-size: 0.85rem; padding: 0.4rem 0.8rem; border-bottom: 1px solid var(--border); }
        .table { font-size: 0.85rem; color: var(--text); }
        .table th { background: var(--primary); color: white; font-weight: 600; font-size: 0.8rem; padding: 0.4rem 0.6rem; border-bottom: 2px solid var(--border); }
        .table td { padding: 0.35rem 0.6rem; border-bottom: 1px solid var(--border); }
        .table-hover tbody tr:hover { background: var(--hover); }
        .badge { font-size: 0.7rem; font-weight: 600; padding: 0.25em 0.55em; border-radius: 6px; }
        .badge.bg-success { background: var(--success) !important; }
        .badge.bg-danger  { background: var(--danger) !important; }
        .pagination .page-link { background: var(--card); border-color: var(--border); color: var(--text); font-size: 0.8rem; padding: 0.3rem 0.6rem; }
        .pagination .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: white; }
        .chart-container { height: 180px !important; padding: 0.5rem; }
        .metric-card .card-body { padding: 0.8rem; }
        .metric-card h2 { font-size: 1.7rem; margin: 0; font-weight: 700; }
        .metric-card small { font-size: 0.7rem; color: var(--muted); }
        .filter-form .form-control, .filter-form .btn { font-size: 0.8rem; padding: 0.35rem 0.6rem; border-radius: 6px; background: var(--input-bg); color: var(--input-text); border-color: var(--border); }
        .flujo-equipos { max-width: 130px; word-break: break-word; font-size: 0.75rem; color: var(--muted); }
        .text-success { color: var(--success) !important; }
        .text-danger  { color: var(--danger) !important; }

        /* Firma pie de página - Ajustada para ser consistente, fija en el fondo */
        .firma {
            text-align: center;
            font-size: 13px;
            color: white;
            padding: 15px 0;
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            width: 100%;
            position: fixed;
            left: 0;
            bottom: 0;
            box-sizing: border-box;
            z-index: 1000;
        }

        .firma p {
            margin: 5px 0 0 0;
            font-size: 12px;
            opacity: 0.9;
        }
    </style>
</head>
<body class="pb-5">

<div class="container-fluid py-3">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center" style="background: var(--primary); color: white; padding: 1rem; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.1); margin-bottom: 1rem;">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-clipboard-check fs-4"></i>
            <h1 class="h5 mb-0">Dashboard Check de Calidad</h1>
        </div>

        <div class="text-end">
            <h2 class="h6 mb-1">Dashboard del Administrador</h2>
            <h2 class="h6 mb-1">Bienvenid@, <?= htmlspecialchars($_SESSION['empleado']) ?></h2>
            <a href="login_admin.php" class="text-decoration-none" style="color: white; font-weight: bold;">Cerrar sesión</a>
        </div>
    </div>

    <!-- MÉTRICAS -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-4">
            <div class="card text-center metric-card">
                <div class="card-header">Total</div>
                <div class="card-body">
                    <h2 style="color: var(--primary);"><?= number_format($total_checks) ?></h2>
                    <small>checks</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center metric-card">
                <div class="card-header text-success">OK</div>
                <div class="card-body">
                    <h2 class="text-success"><?= number_format($conforme_checks) ?></h2>
                    <small>(<?= $total_checks ? round($conforme_checks/$total_checks*100,1) : 0 ?>%)</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center metric-card">
                <div class="card-header text-danger">NC</div>
                <div class="card-body">
                    <h2 class="text-danger"><?= number_format($noconforme_checks) ?></h2>
                    <small>(<?= $total_checks ? round($noconforme_checks/$total_checks*100,1) : 0 ?>%)</small>
                </div>
            </div>
        </div>
    </div>

    <!-- GRÁFICOS PRINCIPALES -->
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Estado</div>
                <div class="card-body p-1"><canvas id="chartEstado"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Motivos NC</div>
                <div class="card-body p-1"><canvas id="chartMotivo"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Comparativa Equipos</div>
                <div class="card-body p-1"><canvas id="chartEquipo"></canvas></div>
            </div>
        </div>
    </div>

    <!-- GRÁFICOS ADICIONALES: EQUIPO ORIGEN Y MATERIAL -->
    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Comparativa Equipo Origen</div>
                <div class="card-body p-1"><canvas id="chartEquipoOrigen"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Materiales NC</div>
                <div class="card-body p-1"><canvas id="chartMaterial"></canvas></div>
            </div>
        </div>
    </div>

    <!-- FILTROS Y TABLA -->
    <div class="card">
        <div class="card-header"><i class="bi bi-funnel"></i> Registros</div>
        <div class="card-body p-2">

            <!-- Filtros -->
            <form method="GET" class="row g-2 mb-2 align-items-center filter-form">
                <div class="col-4 col-md-2"><input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" class="form-control"></div>
                <div class="col-4 col-md-2"><input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" class="form-control"></div>
                <div class="col-4 col-md-2">
                    <select name="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="conforme" <?= $estado_filtro === 'conforme' ? 'selected' : '' ?>>OK</option>
                        <option value="no_conforme" <?= $estado_filtro === 'no_conforme' ? 'selected' : '' ?>>NC</option>
                    </select>
                </div>
                <div class="col-6 col-md-1"><button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-search"></i></button></div>
                <div class="col-6 col-md-1"><a href="?" class="btn btn-secondary btn-sm w-100"><i class="bi bi-arrow-counterclockwise"></i></a></div>
                <div class="col-6 col-md-1"><a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-primary btn-sm w-100"><i class="bi bi-download"></i> CSV</a></div>
            </form>

            <!-- Tabla (solo columnas esenciales) -->
            <div class="table-responsive" style="max-height: calc(100vh - 520px);">
                <table class="table table-light table-hover table-sm mb-0"> <!-- Cambiado a table-light para armonía con fondo claro -->
                    <thead class="sticky-top">
                        <tr>
                            <th>Fecha</th><th>Orden</th><th>Equipo Origen</th><th>Material</th><th>Verificado por</th>
                            <th>Estado</th><th>Motivo</th><th>Eq. Caus.</th><th>Plan de acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r): ?>
                        <tr>
                            <td><?= date('d/m H:i', strtotime($r['fecha_check'])) ?></td>
                            <td><code><?= htmlspecialchars($r['orden']) ?></code></td>
                            <td><?= htmlspecialchars($r['equipo_origen'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($r['material'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($r['empleado_verificador']) ?></td>
                            <td><span class="badge <?= $r['estado']==='conforme'?'bg-success':'bg-danger' ?>">
                                <?= $r['estado']==='conforme'?'OK':'NC' ?>
                            </span></td>
                            <td><?= htmlspecialchars($r['motivo']??'—') ?></td>
                            <td class="flujo-equipos"><?= $r['equipos_str'] ?></td>
                            <td><?= htmlspecialchars($r['observaciones']??'—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
            <nav class="mt-2">
                <ul class="pagination pagination-sm justify-content-center mb-1">
                    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina-1])) ?>">«</a>
                    </li>
                    <?php for ($i = max(1, $pagina-1); $i <= min($total_paginas, $pagina+1); $i++): ?>
                    <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pagina >= $total_paginas ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina+1])) ?>">»</a>
                    </li>
                </ul>
                <p class="text-center text-muted small mb-0">
                    <?= count($registros) ?> de <?= number_format($total_registros) ?> (p. <?= $pagina ?>/<?= $total_paginas ?>)
                </p>
            </nav>
            <?php endif; ?>

            <?php if (empty($registros)): ?>
            <p class="text-center text-muted my-2">Sin registros.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script>
const chartCfg = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { labels: { color: '#155724', font: { size: 9 } }, position: 'bottom' } }
};

// Estado
new Chart(document.getElementById('chartEstado'), {
    type: 'doughnut',
    data: {
        labels: ['OK', 'NC'],
        datasets: [{ data: [<?= $conforme_checks ?>, <?= $noconforme_checks ?>],
            backgroundColor: ['#28a745', '#dc3545'], borderColor: '#e8f5e8', borderWidth: 2 }]
    },
    options: { ...chartCfg, cutout: '70%' }
});

// Motivos
<?php if (!empty($noconforme_por_motivo)): ?>
new Chart(document.getElementById('chartMotivo'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($noconforme_por_motivo)) ?>,
        datasets: [{ data: <?= json_encode(array_values($noconforme_por_motivo)) ?>,
            backgroundColor: '#dc3545', borderWidth: 1 }]
    },
    options: {
        ...chartCfg,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { color: '#6c9d6c', font: { size: 8 } }, grid: { color: '#9fd09f' } } }
    }
});
<?php endif; ?>

// Comparativa Equipos (Vertical Stacked)
<?php if (!empty($top_equipos)): ?>
new Chart(document.getElementById('chartEquipo'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($top_equipos) ?>,
        datasets: [
            { label: 'C', data: <?= json_encode($top_conforme) ?>, backgroundColor: '#28a745', borderWidth: 1 },
            { label: 'NC', data: <?= json_encode($top_noconforme) ?>, backgroundColor: '#dc3545', borderWidth: 1 }
        ]
    },
    options: {
        ...chartCfg,
        plugins: { legend: { display: true } },
        scales: {
            y: {
                stacked: true,
                beginAtZero: true,
                ticks: { color: '#6c9d6c', font: { size: 8 } },
                grid: { color: '#9fd09f' }
            },
            x: {
                stacked: true,
                ticks: { color: '#6c9d6c', font: { size: 8 } },
                grid: { color: '#9fd09f' }
            }
        }
    }
});
<?php endif; ?>

// Comparativa Equipo Origen
<?php if (!empty($top_equipo_origen)): ?>
new Chart(document.getElementById('chartEquipoOrigen'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($top_equipo_origen) ?>,
        datasets: [
            { label: 'OK', data: <?= json_encode($top_conforme_eo) ?>, backgroundColor: '#28a745', borderWidth: 1 },
            { label: 'NC', data: <?= json_encode($top_noconforme_eo) ?>, backgroundColor: '#dc3545', borderWidth: 1 }
        ]
    },
    options: {
        ...chartCfg,
        plugins: { legend: { display: true } },
        scales: {
            y: {
                stacked: true,
                beginAtZero: true,
                ticks: { color: '#6c9d6c', font: { size: 8 } },
                grid: { color: '#9fd09f' }
            },
            x: {
                stacked: true,
                ticks: { color: '#6c9d6c', font: { size: 8 } },
                grid: { color: '#9fd09f' }
            }
        }
    }
});
<?php endif; ?>

// Materiales NC
<?php if (!empty($noconforme_por_material)): ?>
new Chart(document.getElementById('chartMaterial'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($noconforme_por_material)) ?>,
        datasets: [{ data: <?= json_encode(array_values($noconforme_por_material)) ?>,
            backgroundColor: '#dc3545', borderWidth: 1 }]
    },
    options: {
        ...chartCfg,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { color: '#6c9d6c', font: { size: 8 } }, grid: { color: '#9fd09f' } } }
    }
});
<?php endif; ?>

</script>

<!-- Firma ajustada como footer fijo en el fondo -->
<div class="firma">
    Sistema de control de Calidad | © <?= date("Y"); ?><br>
    <p>Desarrollado por: Nestor Rosales | Rosales_Dev91</p>
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