<?php
session_start();
require_once '../config/database.php';
date_default_timezone_set('America/Costa_Rica');

/* ==============================================
   FUNCIONES AUXILIARES
============================================== */
function limpiarInput($dato) {
    return trim(htmlspecialchars($dato ?? ''));
}

function obtenerMensaje() {
    $mensaje = $_SESSION['mensaje'] ?? '';
    $mensaje_check = $_SESSION['mensaje_check'] ?? '';
    unset($_SESSION['mensaje'], $_SESSION['mensaje_check']);
    return ['general' => $mensaje, 'check' => $mensaje_check];
}

function obtenerDatosEmpleado($conn, $codigo) {
    $stmt = $conn->prepare("SELECT nombre_empleado FROM empleados WHERE codigo_empleado = ?");
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    $stmt->bind_result($nombre);
    return $stmt->fetch() ? ['codigo' => $codigo, 'nombre' => $nombre] : false;
}

function obtenerMotivos($conn) {
    $motivos = [];
    $stmt = $conn->prepare("SELECT motivo FROM motivos ORDER BY motivo");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $motivos[] = $row['motivo'];
    $stmt->close();
    return $motivos;
}

function obtenerEquipos($conn) {
    $equipos = [];
    $stmt = $conn->prepare("SELECT nombre_equipo FROM equipos_prueba ORDER BY nombre_equipo");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $equipos[] = $row['nombre_equipo'];
    $stmt->close();
    return $equipos;
}

function obtenerMateriales($conn) {
    $materiales = [];
    $stmt = $conn->prepare("SELECT material_prueba FROM materiales_prueba ORDER BY material_prueba");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $materiales[] = $row['material_prueba'];
    $stmt->close();
    return $materiales;
}

function obtenerRegistrosOrden($conn, $orden) {
    $registros = [];
    $equipos_unicos = [];
    $stmt = $conn->prepare("
        (SELECT id, empleado, area, COALESCE(equipo,'N/A') AS equipo, turno, fecha FROM produccion WHERE orden = ?)
        UNION ALL
        (SELECT id, empleado, area, COALESCE(equipo,'N/A'), turno, fecha FROM registros_antiguos WHERE orden = ?)
        ORDER BY fecha DESC LIMIT 100
    ");
    $stmt->bind_param("ss", $orden, $orden);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $registros[] = $row;
        if ($row['equipo'] !== 'N/A') $equipos_unicos[$row['equipo']] = true;
    }
    $stmt->close();
    return ['registros' => $registros, 'equipos' => array_keys($equipos_unicos)];
}

function obtenerCheckEmpleado($conn, $orden, $codigo_empleado) {
    $stmt = $conn->prepare("SELECT * FROM check_pruebas WHERE orden = ? AND codigo_empleado = ? ORDER BY fecha_check DESC LIMIT 1");
    $stmt->bind_param("ss", $orden, $codigo_empleado);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: [];
}

function procesarEquipos($equipos) {
    $eq = array_filter(array_unique($equipos));
    return !empty($eq) ? json_encode($eq, JSON_UNESCAPED_UNICODE) : null;
}

/* ==============================================
   PROCESAMIENTO
============================================== */
if (!isset($_SESSION['ordenes_a_check'])) {
    $_SESSION['ordenes_a_check'] = [];
}

$mensajes = obtenerMensaje();
$nombreEmpleado = $_SESSION['nombreEmpleado'] ?? null;
$codigoEmpleado = $_SESSION['codigoEmpleado'] ?? '';
$ordenes = $_SESSION['ordenes_a_check'];
$motivos = obtenerMotivos($conn);
$all_equipos = obtenerEquipos($conn);
$materiales = obtenerMateriales($conn);

// Cambiar empleado
if (isset($_POST['cambiar_empleado'])) {
    unset($_SESSION['codigoEmpleado'], $_SESSION['nombreEmpleado'], $_SESSION['ordenes_a_check']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Validar empleado
if (isset($_POST['consultar_empleado']) && ($codigo = limpiarInput($_POST['codigo_empleado']))) {
    $empleado = obtenerDatosEmpleado($conn, $codigo);
    if ($empleado) {
        $_SESSION['codigoEmpleado'] = $empleado['codigo'];
        $_SESSION['nombreEmpleado'] = $empleado['nombre'];
    } else {
        $_SESSION['mensaje'] = "Empleado no encontrado: " . htmlspecialchars($codigo);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Agregar orden
if (isset($_POST['agregar_orden']) && ($orden = limpiarInput($_POST['orden_agregar']))) {
    $datos = obtenerRegistrosOrden($conn, $orden);
    if (!empty($datos['registros'])) {
        if (!in_array($orden, $ordenes)) {
            $_SESSION['ordenes_a_check'][] = $orden;
            $ordenes[] = $orden;
        }
    } else {
        $_SESSION['mensaje'] = "Orden no encontrada o sin registros: " . htmlspecialchars($orden);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Remover orden
if (isset($_POST['remover_orden']) && ($remover = limpiarInput($_POST['remover_orden']))) {
    $_SESSION['ordenes_a_check'] = array_diff($_SESSION['ordenes_a_check'], [$remover]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Limpiar lista
if (isset($_POST['limpiar_lista'])) {
    unset($_SESSION['ordenes_a_check']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Guardar checks múltiples
if (isset($_POST['guardar_checks']) && $nombreEmpleado) {
    $guardados = 0;
    $errores = 0;
    
    $orders_to_process = isset($_POST['edit_order']) && ($orden_edit = limpiarInput($_POST['edit_order']))
        ? [$orden_edit]
        : array_keys($_POST['estado'] ?? []);
    
    foreach ($orders_to_process as $orden) {
        $estado = $_POST['estado'][$orden] ?? '';
        if (empty($estado)) continue;
        
        $material = limpiarInput($_POST['material'][$orden] ?? '');
        $equipo_origen = limpiarInput($_POST['equipo_origen'][$orden] ?? '');
        
        if (empty($equipo_origen)) {
            $errores++;
            continue;
        }
        
        $is_nc = $estado === 'no_conforme';
        $motivo = $is_nc ? limpiarInput($_POST['motivo'][$orden] ?? '') : null;
        $obs = limpiarInput($_POST['plan'][$orden] ?? '');
        $equipos_res = $is_nc ? ($_POST['equipo_res'][$orden] ?? []) : [];

        $datos_o = obtenerRegistrosOrden($conn, $orden);
        $orden_eq = $datos_o['equipos'];

        if ($is_nc) {
            if (empty($motivo) || empty($equipos_res) || array_filter($equipos_res, function($eq) use ($all_equipos) { return !in_array($eq, $all_equipos); })) {
                $errores++;
                continue;
            }
            $equipos_json = json_encode(array_unique($equipos_res), JSON_UNESCAPED_UNICODE);
        } else {
            $equipos_json = json_encode([$equipo_origen], JSON_UNESCAPED_UNICODE);
            if (!$equipos_json) {
                $errores++;
                continue;
            }
        }

        $stmt = $conn->prepare("SELECT id FROM check_pruebas WHERE codigo_empleado = ? AND orden = ?");
        $stmt->bind_param("ss", $codigoEmpleado, $orden);
        $stmt->execute();
        $stmt->bind_result($id);
        $existe = $stmt->fetch();
        $stmt->close();

        if ($existe) {
            if ($is_nc) {
                $stmt = $conn->prepare("UPDATE check_pruebas SET estado=?, motivo=?, observaciones=?, equipos_causantes=?, material=?, equipo_origen=?, fecha_check=NOW() WHERE id=?");
                $stmt->bind_param("ssssssi", $estado, $motivo, $obs, $equipos_json, $material, $equipo_origen, $id);
            } else {
                $stmt = $conn->prepare("UPDATE check_pruebas SET estado=?, motivo=NULL, observaciones=?, equipos_causantes=?, material=?, equipo_origen=?, fecha_check=NOW() WHERE id=?");
                $stmt->bind_param("sssssi", $estado, $obs, $equipos_json, $material, $equipo_origen, $id);
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO check_pruebas (orden, codigo_empleado, empleado_verificador, estado, motivo, observaciones, equipos_causantes, material, equipo_origen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssss", $orden, $codigoEmpleado, $nombreEmpleado, $estado, $motivo, $obs, $equipos_json, $material, $equipo_origen);
        }

        if ($stmt->execute()) {
            $guardados++;
        } else {
            $errores++;
        }
        $stmt->close();
    }
    $_SESSION['mensaje_check'] = "Guardados: $guardados, Errores: $errores";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Cargar checks para prefill si hay órdenes
$checks_all = [];
if (!empty($ordenes) && $nombreEmpleado) {
    foreach ($ordenes as $o) {
        $checks_all[$o] = obtenerCheckEmpleado($conn, $o, $codigoEmpleado);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check de Calidad</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --bg: #155724;
            --card: #1e7e34;
            --success: #28a745;
            --danger: #dc3545;
            --primary: #004d00;
            --primary-hover: #003300;
            --text: #d4fcd4;
            --text-strong: #ffffff;
            --border: #cccccc;
            --muted: #b2e0b2;
            --input-bg: #ffffff;
            --input-text: #000000;
            --counter: #666;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', Arial, sans-serif;
            line-height: 1.5;
            font-size: 14px;
            padding-bottom: 80px;
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 10px; }

        /* Cabecera */
        .header {
            background: var(--bg);
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .reloj {
            text-align: center;
            font-weight: bold;
            font-size: 24px;
            color: var(--text-strong);
            padding: 8px 0;
        }
        .bienvenida {
            background: var(--card);
            padding: 10px 16px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: var(--text-strong);
            margin: 8px 0;
        }
        .bienvenida button {
            background: var(--danger);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 13px;
            cursor: pointer;
        }

        /* Cards */
        .card {
            background: var(--card);
            padding: 16px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            margin-bottom: 16px;
        }
        .card h3 {
            margin: 0 0 12px;
            font-size: 18px;
            color: var(--text-strong);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge {
            background: var(--success);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        /* Alertas */
        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 12px;
            font-weight: 600;
            font-size: 14px;
        }
        .alert.success { background: var(--success); color: white; }
        .alert.error { background: #5a0000; color: #ffd1d1; }

        /* Formularios */
        input, select, textarea {
            width: 100%;
            padding: 8px 10px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--input-text);
            font-size: 13px;
            margin: 4px 0;
            box-sizing: border-box;
        }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid var(--success);
            border-color: var(--success);
        }
        textarea { resize: vertical; min-height: 60px; }

        button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.3s;
            margin: 4px 4px 4px 0;
        }
        button:hover { background: var(--primary-hover); }
        button.secondary { background: #6c757d; }
        button.secondary:hover { background: #5a6268; }
        button.small { padding: 4px 8px; font-size: 12px; }
        button.edit-btn { 
            background: #ffc107; 
            color: #000;
            font-weight: bold;
        }
        button.edit-btn:hover { background: #ffb300; }
        button.save-btn {
            background: var(--success);
        }
        button.save-btn:hover { background: #218838; }

        /* Tablas */
        .table-container {
            max-height: 600px;
            overflow: auto;
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: white;
            color: black;
        }
        th, td {
            padding: 8px 6px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }
        th {
            background: var(--success);
            color: white;
            position: sticky;
            top: 0;
            z-index: 1;
            white-space: nowrap;
        }
        tbody tr:hover { background: #f2f2f2; }
        tbody tr.locked { background: #f0f0f0; opacity: 0.85; }
        
        .nc-cell { transition: opacity 0.2s; }
        .nc-cell.hidden { display: none; }

        .estado-conforme { color: var(--success); font-weight: bold; }
        .estado-no-conforme { color: var(--danger); font-weight: bold; }

        /* Checkboxes para equipos */
        .checkbox-group {
            max-height: 120px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 5px;
            border-radius: 4px;
            background: #f9f9f9;
        }
        .checkbox-group label {
            display: block;
            margin: 2px 0;
            font-size: 12px;
            cursor: pointer;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 5px;
        }

        /* Búsqueda / Agregar */
        .add-form {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        .add-form input { flex: 1; margin: 0; }
        .add-form button { margin: 0; }

        .ordenes-lista {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 8px 0;
            align-items: center;
        }

        /* Checkbox estilo */
        input[type="checkbox"].estado-check {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        /* Scrollbars */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #666; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #888; }

        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 8px; }
            table { font-size: 12px; }
            th, td { padding: 6px 4px; }
            .add-form { flex-direction: column; }
            .ordenes-lista { justify-content: flex-start; }
            .checkbox-group { max-height: 100px; }
        }

        /* Firma */
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
<body>

<!-- Cabecera -->
<div class="header">
    <div class="container">
        <div class="reloj" id="reloj"></div>
        <?php if ($nombreEmpleado): ?>
            <div class="bienvenida">
                <div>Verificador: <strong><?=htmlspecialchars($nombreEmpleado)?></strong> (<?=htmlspecialchars($codigoEmpleado)?>)</div>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="cambiar_empleado">Cambiar</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <?php if (!$nombreEmpleado): ?>
        <div class="card">
            <h3>Identificación del Verificador</h3>
            <?php if ($mensajes['general']): ?>
                <div class="alert error"><?=htmlspecialchars($mensajes['general'])?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="text" name="codigo_empleado" placeholder="Código de empleado" 
                       value="<?=htmlspecialchars($codigoEmpleado)?>" required autofocus>
                <button type="submit" name="consultar_empleado">Ingresar</button>
            </form>
        </div>

    <?php else: ?>
        <?php if ($mensajes['check']): ?>
            <div class="alert <?=strpos($mensajes['check'], 'Errores: 0') !== false ? 'success' : 'error'?>">
                <?=htmlspecialchars($mensajes['check'])?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Agregar Órdenes para Check</h3>
            <?php if ($mensajes['general']): ?>
                <div class="alert error"><?=htmlspecialchars($mensajes['general'])?></div>
            <?php endif; ?>
            <form method="POST" class="add-form">
                <input type="text" name="orden_agregar" placeholder="Escanear/ingresar orden..." autofocus required>
                <button type="submit" name="agregar_orden">Agregar</button>
            </form>
            <?php if (!empty($ordenes)): ?>
                <div class="ordenes-lista">
                    <span>Órdenes agregadas:</span>
                    <?php foreach ($ordenes as $o): ?>
                        <span class="badge"><?=htmlspecialchars($o)?></span>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="remover_orden" value="<?=htmlspecialchars($o)?>">
                            <button type="submit" class="secondary small" title="Remover">X</button>
                        </form>
                    <?php endforeach; ?>
                    <form method="POST" style="display:inline; margin-left: auto;">
                        <button type="submit" name="limpiar_lista" class="secondary" title="Limpiar todo">Limpiar Lista</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($ordenes)): ?>
            <div class="card">
                <h3>Realizar Checks <span class="badge"><?=count($ordenes)?></span></h3>
                <form method="POST" id="checksForm" novalidate>
                    <input type="hidden" name="edit_order" id="edit_order" value="">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Orden</th>
                                    <th>Equipo Prueba</th>
                                    <th>Material</th>
                                    <th>Conforme</th>
                                    <th>No Conforme</th>
                                    <th class="nc-col">Equipo(s) Responsable(s)</th>
                                    <th class="nc-col">Motivo</th>
                                    <th class="nc-col">Plan de Acción</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ordenes as $o): 
                                    $datos = obtenerRegistrosOrden($conn, $o);
                                    $check = $checks_all[$o];
                                    $estado = $check['estado'] ?? '';
                                    $is_locked = !empty($estado);
                                    $is_nc = $estado === 'no_conforme';
                                    $material = $check['material'] ?? '';
                                    $equipo_origen = $check['equipo_origen'] ?? '';
                                    $motivo_sel = $check['motivo'] ?? '';
                                    $plan = $check['observaciones'] ?? '';
                                    $equipo_caus_array = [];
                                    if ($is_nc && $check['equipos_causantes']) {
                                        $equipo_caus_array = json_decode($check['equipos_causantes'], true) ?: [];
                                    }
                                    $disabled = $is_locked ? 'disabled' : '';
                                ?>
                                    <tr class="check-row <?= $is_locked ? 'locked' : '' ?>" data-order="<?=htmlspecialchars($o)?>">
                                        <td><strong><?=htmlspecialchars($o)?></strong></td>
                                        <td>
                                            <select name="equipo_origen[<?=htmlspecialchars($o)?>]" <?=$disabled?>>
                                                <option value="">Seleccione equipo origen</option>
                                                <?php foreach ($all_equipos as $eq): ?>
                                                    <option value="<?=htmlspecialchars($eq)?>" <?= $equipo_origen === $eq ? 'selected' : '' ?>><?=htmlspecialchars($eq)?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="material[<?=htmlspecialchars($o)?>]" <?=$disabled?>>
                                                <option value="">Seleccione material</option>
                                                <?php foreach ($materiales as $mat): ?>
                                                    <option value="<?=htmlspecialchars($mat)?>" <?= $material === $mat ? 'selected' : '' ?>><?=htmlspecialchars($mat)?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="checkbox" class="estado-check conforme-check" name="estado[<?=htmlspecialchars($o)?>]" value="conforme" <?= $estado === 'conforme' ? 'checked' : '' ?> <?=$disabled?>>
                                        </td>
                                        <td>
                                            <input type="checkbox" class="estado-check nc-check" name="estado[<?=htmlspecialchars($o)?>]" value="no_conforme" <?= $is_nc ? 'checked' : '' ?> <?=$disabled?>>
                                        </td>
                                        <td class="nc-cell <?= !$is_nc ? 'hidden' : '' ?>">
                                            <div class="checkbox-group">
                                                <?php foreach ($all_equipos as $eq): ?>
                                                    <label>
                                                        <input type="checkbox" name="equipo_res[<?=htmlspecialchars($o)?>][]" value="<?=htmlspecialchars($eq)?>" <?= in_array($eq, $equipo_caus_array) ? 'checked' : '' ?> <?=$disabled?>>
                                                        <?=htmlspecialchars($eq)?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="nc-cell <?= !$is_nc ? 'hidden' : '' ?>">
                                            <select name="motivo[<?=htmlspecialchars($o)?>]" <?=$disabled?>>
                                                <option value="">Seleccione motivo</option>
                                                <?php foreach ($motivos as $m): ?>
                                                    <option value="<?=htmlspecialchars($m)?>" <?= $motivo_sel === $m ? 'selected' : '' ?>><?=htmlspecialchars($m)?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="nc-cell <?= !$is_nc ? 'hidden' : '' ?>">
                                            <textarea name="plan[<?=htmlspecialchars($o)?>]" placeholder="Plan de acción..." <?=$disabled?>><?=htmlspecialchars($plan)?></textarea>
                                        </td>
                                        <td>
                                            <?php if ($is_locked): ?>
                                                <button type="button" class="edit-btn small toggle-edit" data-order="<?=htmlspecialchars($o)?>">Editar</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="guardar_checks">Guardar Todos los Checks</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    // Reloj
    setInterval(() => {
        const d = new Date();
        const opts = {weekday:'short', day:'numeric', month:'short', hour:'2-digit', minute:'2-digit'};
        document.getElementById('reloj').textContent = d.toLocaleDateString('es-CR', opts).replace(',', ' -');
    }, 1000);

    // Toggle campos NC cuando se marca checkbox
    document.querySelectorAll('.check-row').forEach(row => {
        const conformeCheck = row.querySelector('.conforme-check');
        const ncCheck = row.querySelector('.nc-check');
        const ncCells = row.querySelectorAll('.nc-cell');
        
        function updateNCFields() {
            const isNc = ncCheck && ncCheck.checked;
            ncCells.forEach(cell => {
                if (isNc) {
                    cell.classList.remove('hidden');
                } else {
                    cell.classList.add('hidden');
                }
            });
        }
        
        // Solo un checkbox puede estar marcado a la vez
        if (conformeCheck) {
            conformeCheck.addEventListener('change', function() {
                if (this.checked && ncCheck) {
                    ncCheck.checked = false;
                }
                updateNCFields();
            });
        }
        
        if (ncCheck) {
            ncCheck.addEventListener('change', function() {
                if (this.checked && conformeCheck) {
                    conformeCheck.checked = false;
                }
                updateNCFields();
            });
        }
        
        updateNCFields();
    });

    // Función para validar una fila
    function validateRow(tr) {
        let valid = true;
        let errorMsg = '';
        
        const conformeCheck = tr.querySelector('.conforme-check');
        const ncCheck = tr.querySelector('.nc-check');
        const orden = tr.querySelector('td:first-child').textContent.trim();
        
        // Verificar que al menos un estado esté marcado
        if (!conformeCheck.checked && !ncCheck.checked) {
            valid = false;
            errorMsg += 'Seleccione estado (Conforme o No Conforme) para la orden ' + orden + '.\n';
        }
        
        const eqOrigenSel = tr.querySelector('select[name*="equipo_origen"]').value;
        if (!eqOrigenSel) {
            valid = false;
            errorMsg += 'Seleccione equipo origen para la orden ' + orden + '.\n';
        }
        
        if (ncCheck && ncCheck.checked) {
            const checkedBoxes = tr.querySelectorAll('input[name*="equipo_res"]:checked');
            const motSel = tr.querySelector('select[name*="motivo"]').value;
            if (checkedBoxes.length === 0 || !motSel) {
                valid = false;
                errorMsg += 'Complete al menos un equipo responsable y motivo para No Conforme en orden ' + orden + '.\n';
            }
        }
        
        if (!valid) {
            alert(errorMsg);
        }
        return valid;
    }

    // Validación submit global
    const form = document.getElementById('checksForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const rowsToValidate = Array.from(document.querySelectorAll('.check-row')).filter(tr => {
                const conformeCheck = tr.querySelector('.conforme-check');
                const ncCheck = tr.querySelector('.nc-check');
                return (conformeCheck && !conformeCheck.disabled) || (ncCheck && !ncCheck.disabled);
            });
            
            let allValid = true;
            rowsToValidate.forEach(tr => {
                if (!validateRow(tr)) {
                    allValid = false;
                }
            });
            
            if (!allValid) {
                e.preventDefault();
            }
        });
    }

    // Toggle edit por fila
    document.querySelectorAll('.toggle-edit').forEach(btn => {
        btn.addEventListener('click', function() {
            const tr = this.closest('tr');
            const order = this.dataset.order;
            
            if (this.textContent === 'Editar') {
                // Habilitar edición
                tr.querySelectorAll('input, select, textarea').forEach(el => el.disabled = false);
                tr.classList.remove('locked');
                this.classList.remove('edit-btn');
                this.classList.add('save-btn');
            } else {
                // Validar y guardar
                if (!validateRow(tr)) {
                    return;
                }
                document.getElementById('edit_order').value = order;
                document.getElementById('checksForm').submit();
            }
        });
    });

    // Atajos
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            document.querySelector('input[name="orden_agregar"]')?.focus();
        }
    });

    // Enfocar input agregar si existe
    const addInput = document.querySelector('input[name="orden_agregar"]');
    if (addInput && !addInput.value.trim()) addInput.focus();
</script>

<!-- Firma -->
<div class="firma">
    Sistema de control de Calidad | © <?= date("Y"); ?><br>
    <p>Desarrollado por: Nestor Rosales | Rosales_Dev91</p>
</div>

</body>
</html>