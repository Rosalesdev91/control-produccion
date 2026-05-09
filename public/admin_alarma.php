<?php
// ============================================================
//  admin_alarma.php  —  Panel de Administración
//  Requiere sesión con rol 'administrador'
// ============================================================
session_start();
require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('America/Costa_Rica');

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_alarma.php");
    exit();
}

$codigoAdmin  = $_SESSION['codigo_empleado'] ?? '';
$nombreAdmin  = $_SESSION['nombre_empleado'] ?? '';

// ── Exportar CSV ─────────────────────────────────────────────
if (isset($_GET['exportar'])) {
    $fecha    = $_GET['fecha']    ?? '';
    $empleado = $_GET['empleado'] ?? '';
    $turno    = $_GET['turno']    ?? '';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="alarma_registros_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Fecha Alarma','Código Empleado','Nombre','Estación','IP','Turno','Hora Confirmación','Segundos Respuesta']);

    $sql    = "SELECT r.fecha_alarma, r.codigo_empleado, r.nombre_empleado,
                      r.nombre_estacion, r.ip_estacion, r.turno,
                      r.fecha_confirmacion, r.segundos_respuesta
               FROM alarma_registros r WHERE 1=1";
    $params = [];
    if ($fecha)    { $sql .= " AND DATE(r.fecha_alarma)=?";                    $params[] = $fecha; }
    if ($empleado) { $sql .= " AND (r.codigo_empleado LIKE ? OR r.nombre_empleado LIKE ?)"; $like="%$empleado%"; $params[]=$like; $params[]=$like; }
    if ($turno)    { $sql .= " AND r.turno=?";                                 $params[] = $turno; }
    $sql .= " ORDER BY r.fecha_alarma DESC";

    $stmt = $conn->prepare($sql);
    if ($params) { $types=str_repeat('s',count($params)); $stmt->bind_param($types,...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row=$res->fetch_assoc()) {
        fputcsv($out,[$row['fecha_alarma'],$row['codigo_empleado'],$row['nombre_empleado'],
                      $row['nombre_estacion'],$row['ip_estacion'],$row['turno'],
                      $row['fecha_confirmacion'],$row['segundos_respuesta']]);
    }
    fclose($out); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Alarma de Calidad</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #f0f2f5;
            --card:     #ffffff;
            --border:   #dde1e7;
            --text:     #111827;
            --muted:    #6b7280;
            --blue:     #185FA5;
            --blue-dk:  #0e4275;
            --blue-lt:  #E6F1FB;
            --green:    #2E6B12;
            --green-lt: #EAF4DC;
            --red:      #B91C1C;
            --red-lt:   #FEE2E2;
            --amber:    #92400E;
            --amber-lt: #FEF3C7;
            --radius:   12px;
            --shadow:   0 2px 8px rgba(0,0,0,.07);
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .topbar {
            background: var(--blue-dk);
            color: #fff;
            padding: 11px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-left   { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .topbar-logo   { font-size: 18px; font-weight: 700; }
        .topbar-sub    { font-size: 12px; opacity: .75; }
        .admin-badge   { background: rgba(255,255,255,.15); padding: 4px 12px; border-radius: 99px; font-size: 12px; font-weight: 600; }
        .btn-logout    { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 6px 14px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: 600; transition: background .2s; cursor: pointer; }
        .btn-logout:hover { background: rgba(255,0,0,.4); }

        .tabs {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 0 24px;
            display: flex;
            gap: 0;
        }
        .tab {
            padding: 13px 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all .2s;
            white-space: nowrap;
        }
        .tab:hover { color: var(--blue); }
        .tab.active { color: var(--blue); border-bottom-color: var(--blue); }

        .main { max-width: 1300px; margin: 0 auto; padding: 24px 16px; display: flex; flex-direction: column; gap: 20px; }
        .tab-content { display: none; flex-direction: column; gap: 20px; }
        .tab-content.active { display: flex; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 26px;
            box-shadow: var(--shadow);
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            border-bottom: 2px solid var(--blue);
            padding-bottom: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
        .stat-card {
            background: var(--blue-lt);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .stat-num   { font-size: 34px; font-weight: 800; color: var(--blue); line-height: 1; }
        .stat-label { font-size: 12px; color: var(--muted); margin-top: 6px; font-weight: 500; }
        .stat-pend  { background: var(--amber-lt); }
        .stat-pend .stat-num { color: var(--amber); }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text);
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(24,95,165,.1);
        }
        .form-group textarea { resize: vertical; min-height: 60px; }

        .btn {
            padding: 9px 18px;
            border-radius: 8px;
            border: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s, transform .1s;
        }
        .btn:hover   { opacity: .88; }
        .btn:active  { transform: scale(.97); }
        .btn-primary { background: var(--blue);  color: #fff; }
        .btn-success { background: var(--green); color: #fff; }
        .btn-danger  { background: var(--red);   color: #fff; }
        .btn-outline { background: #fff; color: var(--blue); border: 1.5px solid var(--blue); }
        .btn-sm      { padding: 5px 12px; font-size: 12px; border-radius: 6px; }

        .table-wrap { overflow-x: auto; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead { background: #f8f9fb; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
        th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }
        tr:hover td { background: #f9fafb; }
        .empty-state { text-align: center; color: var(--muted); padding: 32px; font-size: 13px; }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
        }
        .tag-pend  { background: var(--amber-lt); color: var(--amber); }
        .tag-conf  { background: var(--green-lt); color: var(--green); }
        .tag-cancel{ background: #f3f4f6; color: var(--muted); }
        .tag-D { background: var(--blue-lt); color: var(--blue-dk); }
        .tag-V { background: var(--amber-lt); color: var(--amber); }
        .tag-N { background: #ede9fe; color: #5b21b6; }

        .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
        .dot-green  { background: #22c55e; }
        .dot-amber  { background: #f59e0b; }
        .dot-red    { background: #ef4444; }

        .filters {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 5px;
        }
        .filter-group input,
        .filter-group select {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 500;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.visible { display: flex; }
        .modal {
            background: #fff;
            border-radius: var(--radius);
            padding: 28px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }
        .modal-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--blue);
        }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--text);
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            z-index: 9999;
            display: none;
            max-width: 340px;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .toast.error { background: var(--red); }
        .toast.ok    { background: var(--green); }

        .ip-mono { font-family: 'Courier New', monospace; background: #f3f4f6; padding: 2px 8px; border-radius: 5px; font-size: 12px; }
        .mono { font-family: 'Courier New', monospace; font-size: 12px; }

        .intervalo-badge {
            display: inline-block;
            padding: 2px 8px;
            background: var(--blue-lt);
            color: var(--blue);
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <div>
            <div class="topbar-logo">⚙ Alarma de Calidad</div>
            <div class="topbar-sub">Panel de Administración</div>
        </div>
        <span class="admin-badge">👤 <?= htmlspecialchars($nombreAdmin) ?> (<?= htmlspecialchars($codigoAdmin) ?>)</span>
    </div>
    <a href="login_alarma.php?logout=1" class="btn-logout" onclick="limpiarSesion(event)">🚪 Cerrar sesión</a>
</div>

<div class="tabs">
    <div class="tab active" onclick="cambiarTab('programar')">📅 Programar Alarmas</div>
    <div class="tab" onclick="cambiarTab('estaciones')">🖥 Estaciones</div>
    <div class="tab" onclick="cambiarTab('historial')">📋 Historial</div>
    <div class="tab" onclick="cambiarTab('estadisticas')">📊 Estadísticas</div>
</div>

<div class="main">
    <!-- TAB 1: PROGRAMAR ALARMA -->
    <div class="tab-content active" id="tab-programar">
        <div class="card">
            <div class="card-title">📅 Nueva Alarma</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Estación (seleccione o escriba IP)</label>
                    <select id="sel-estacion" onchange="seleccionarEstacion(this)">
                        <option value="">— IP Manual —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>IP Destino *</label>
                    <input type="text" id="inp-ip" placeholder="192.168.1.10" required>
                </div>
                <div class="form-group">
                    <label>Nombre / Estación</label>
                    <input type="text" id="inp-nom-estacion" placeholder="Mesa 3, Línea B...">
                </div>
                <div class="form-group">
                    <label>Fecha y Hora de Disparo *</label>
                    <input type="datetime-local" id="inp-fecha">
                </div>
                <div class="form-group">
                    <label>Repetir cada (minutos, 0 = una sola vez)</label>
                    <input type="number" id="inp-intervalo" value="0" min="0" max="480" placeholder="0">
                </div>
                <div class="form-group">
                    <label>Mensaje para el operario</label>
                    <input type="text" id="inp-mensaje" value="Realizar prueba de calidad" maxlength="255">
                </div>
            </div>
            <div style="margin-top:16px; display:flex; gap:10px;">
                <button class="btn btn-success" onclick="programarAlarma()">📅 Programar Alarma</button>
                <button class="btn btn-outline" onclick="limpiarFormulario()">🗑 Limpiar</button>
            </div>
        </div>

        <div class="card">
            <div class="card-title">
                ⏰ Alarmas Programadas (últimos 7 días)
                <button class="btn btn-outline btn-sm" onclick="cargarAlarmasAdmin()">🔄 Actualizar</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>IP / Estación</th><th>Mensaje</th><th>Hora Disparo</th><th>Repetir</th><th>Estado</th><th>Confirmado por</th><th>Demora</th><th>Acciones</th></tr>
                    </thead>
                    <tbody id="tbody-alarmas"><tr><td colspan="8" class="empty-state">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: ESTACIONES -->
    <div class="tab-content" id="tab-estaciones">
        <div class="card">
            <div class="card-title">
                🖥 Estaciones Registradas
                <button class="btn btn-primary btn-sm" onclick="abrirModalEstacion()">➕ Nueva Estación</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Nombre</th><th>IP</th><th>Descripción</th><th>Acciones</th></tr></thead>
                    <tbody id="tbody-estaciones"><tr><td colspan="4" class="empty-state">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="card" style="font-size:13px; color:var(--muted); line-height:1.6;">
            <strong>💡 Tip:</strong> Las estaciones son las PCs donde se muestra la alarma.
            Registre cada PC con su nombre amigable y su dirección IP dentro de la red local.
            La pantalla <code>alarma_calidad.php</code> se detecta automáticamente por su IP.
        </div>
    </div>

    <!-- TAB 3: HISTORIAL -->
    <div class="tab-content" id="tab-historial">
        <div class="card">
            <div class="card-title">📋 Historial de Confirmaciones</div>
            <div class="filters">
                <div class="filter-group"><label>Fecha</label><input type="date" id="f-fecha"></div>
                <div class="filter-group"><label>Empleado</label><input type="text" id="f-empleado" placeholder="Código o nombre" style="width:160px;"></div>
                <div class="filter-group">
                    <label>Turno</label>
                    <select id="f-turno" style="width:130px;">
                        <option value="">Todos</option>
                        <option value="Diurno">Diurno</option>
                        <option value="Vespertino">Vespertino</option>
                        <option value="Nocturno">Nocturno</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:8px;">
                        <button class="btn btn-outline" onclick="cargarHistorial()">🔍 Buscar</button>
                        <button class="btn btn-outline" onclick="limpiarFiltros()">🗑 Limpiar</button>
                        <button class="btn btn-success" onclick="exportar()">📎 CSV</button>
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Fecha/Hora Alarma</th><th>Código</th><th>Nombre</th><th>Estación</th><th>IP</th><th>Turno</th><th>Confirmación</th><th>Demora</th></tr></thead>
                    <tbody id="tbody-historial"><tr><td colspan="8" class="empty-state">Use los filtros y presione Buscar</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 4: ESTADÍSTICAS -->
    <div class="tab-content" id="tab-estadisticas">
        <div class="card">
            <div class="card-title">📊 Estadísticas Generales</div>
            <div class="stats-grid">
                <div class="stat-card stat-pend"><div class="stat-num" id="s-pend">--</div><div class="stat-label">⏰ Pendientes</div></div>
                <div class="stat-card"><div class="stat-num" id="s-hoy">--</div><div class="stat-label">Confirmadas hoy</div></div>
                <div class="stat-card"><div class="stat-num" id="s-sem">--</div><div class="stat-label">Esta semana</div></div>
                <div class="stat-card"><div class="stat-num" id="s-mes">--</div><div class="stat-label">Este mes</div></div>
                <div class="stat-card"><div class="stat-num" id="s-total">--</div><div class="stat-label">Total histórico</div></div>
                <div class="stat-card"><div class="stat-num" id="s-avg">--</div><div class="stat-label">⏱ Promedio (seg)</div></div>
                <div class="stat-card"><div class="stat-num" id="s-min">--</div><div class="stat-label">🏆 Mejor tiempo (seg)</div></div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ESTACIÓN -->
<div class="modal-overlay" id="modal-estacion">
    <div class="modal">
        <div class="modal-title" id="modal-estacion-title">➕ Nueva Estación</div>
        <input type="hidden" id="m-id">
        <div class="form-grid" style="grid-template-columns:1fr;">
            <div class="form-group"><label>Nombre *</label><input type="text" id="m-nombre" placeholder="Mesa 3, Línea B, Pulido..."></div>
            <div class="form-group"><label>IP de la PC *</label><input type="text" id="m-ip" placeholder="192.168.1.10"></div>
            <div class="form-group"><label>Descripción</label><textarea id="m-desc" rows="2" placeholder="Opcional..."></textarea></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="cerrarModal('modal-estacion')">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarEstacion()">💾 Guardar</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
function toast(msg, tipo='ok') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + tipo;
    t.style.display = 'block';
    setTimeout(() => t.style.display='none', 3500);
}

function escape(s) {
    if (s == null || s === '') return '—';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDemora(seg) {
    if (!seg && seg !== 0) return '—';
    if (seg < 60) return seg + 's';
    return Math.floor(seg/60) + 'm ' + (seg%60) + 's';
}

async function post(accion, extra={}) {
    const fd = new FormData();
    fd.append('accion', accion);
    for (const [k,v] of Object.entries(extra)) fd.append(k, v);
    
    try {
        const response = await fetch('alarma_api.php', { method: 'POST', body: fd });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        
        try {
            const data = JSON.parse(text);
            if (!data.ok && data.msg) {
                console.warn(`API warning (${accion}):`, data.msg);
            }
            return data;
        } catch(e) {
            console.error('Error parsing JSON. Response:', text.substring(0, 300));
            toast('Error del servidor. Revise la consola.', 'error');
            return { ok: false, msg: 'Invalid JSON response' };
        }
    } catch(error) {
        console.error(`Error in post("${accion}"):`, error);
        toast('Error de conexión con el servidor', 'error');
        return { ok: false, msg: error.message };
    }
}

let tabActual = 'programar';
function cambiarTab(tab) {
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    const tabs = ['programar','estaciones','historial','estadisticas'];
    const idx = tabs.indexOf(tab);
    document.querySelectorAll('.tab')[idx].classList.add('active');
    document.getElementById('tab-'+tab).classList.add('active');
    tabActual = tab;
    if (tab==='estaciones') cargarEstaciones();
    if (tab==='historial') cargarHistorial();
    if (tab==='estadisticas') cargarStats();
}

let estaciones = [];

function cargarEstaciones() {
    post('get_estaciones').then(res => {
        if (!res.ok) {
            console.error('Error loading stations:', res.msg);
            document.getElementById('tbody-estaciones').innerHTML = '<tr><td colspan="4" class="empty-state">❌ Error cargando estaciones</td></tr>';
            return;
        }
        
        estaciones = res.estaciones || [];
        const sel = document.getElementById('sel-estacion');
        sel.innerHTML = '<option value="">— IP Manual —</option>';
        estaciones.forEach(e => {
            const opt = document.createElement('option');
            opt.value = e.id;
            opt.dataset.ip = e.ip;
            opt.dataset.nom = e.nombre;
            opt.textContent = `${e.nombre} (${e.ip})`;
            sel.appendChild(opt);
        });

        const tbody = document.getElementById('tbody-estaciones');
        if (!estaciones.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="empty-state">No hay estaciones registradas. Agregue la primera.</td></tr>';
            return;
        }
        tbody.innerHTML = estaciones.map(e => `
            <tr>
                <td><strong>${escape(e.nombre)}</strong></td>
                <td><span class="ip-mono">${escape(e.ip)}</span></td>
                <td>${escape(e.descripcion)}</td>
                <td>
                    <button class="btn btn-outline btn-sm" onclick='editarEstacion(${JSON.stringify(e)})'>✏ Editar</button>
                    <button class="btn btn-danger btn-sm" onclick="eliminarEstacion(${e.id})">🗑</button>
                </td>
            </tr>
        `).join('');
    });
}

function abrirModalEstacion(e=null) {
    document.getElementById('m-id').value = e ? e.id : '';
    document.getElementById('m-nombre').value = e ? e.nombre : '';
    document.getElementById('m-ip').value = e ? e.ip : '';
    document.getElementById('m-desc').value = e ? (e.descripcion||'') : '';
    document.getElementById('modal-estacion-title').textContent = e ? '✏ Editar Estación' : '➕ Nueva Estación';
    document.getElementById('modal-estacion').classList.add('visible');
}

function editarEstacion(e) { abrirModalEstacion(e); }
function cerrarModal(id) { document.getElementById(id).classList.remove('visible'); }

function guardarEstacion() {
    const id = document.getElementById('m-id').value;
    const nom = document.getElementById('m-nombre').value.trim();
    const ip = document.getElementById('m-ip').value.trim();
    const desc = document.getElementById('m-desc').value.trim();
    if (!nom || !ip) { toast('Nombre e IP son requeridos.','error'); return; }
    post('guardar_estacion', {id, nombre:nom, ip, descripcion:desc}).then(res => {
        if (res.ok) { toast('Estación guardada.','ok'); cerrarModal('modal-estacion'); cargarEstaciones(); }
        else toast(res.msg || 'Error al guardar.','error');
    });
}

function eliminarEstacion(id) {
    if (!confirm('¿Desactivar esta estación?')) return;
    post('eliminar_estacion', {id}).then(res => {
        if (res.ok) { toast('Estación eliminada.','ok'); cargarEstaciones(); }
        else toast('Error al eliminar.','error');
    });
}

function seleccionarEstacion(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt.value) {
        document.getElementById('inp-ip').value = opt.dataset.ip || '';
        document.getElementById('inp-nom-estacion').value = opt.dataset.nom || '';
    } else {
        document.getElementById('inp-ip').value = '';
        document.getElementById('inp-nom-estacion').value = '';
    }
}

function setFechaMinima() {
    const now = new Date();
    now.setSeconds(0,0);
    const local = new Date(now - now.getTimezoneOffset()*60000).toISOString().slice(0,16);
    document.getElementById('inp-fecha').min = local;
    document.getElementById('inp-fecha').value = local;
}

function programarAlarma() {
    const ip_destino = document.getElementById('inp-ip').value.trim();
    const nombre_estacion = document.getElementById('inp-nom-estacion').value.trim();
    const fecha_disparo = document.getElementById('inp-fecha').value;
    const intervalo = document.getElementById('inp-intervalo').value;
    const mensaje = document.getElementById('inp-mensaje').value.trim();
    
    if (!ip_destino) { toast('Ingrese la IP destino.','error'); return; }
    if (!fecha_disparo) { toast('Ingrese la fecha y hora de disparo.','error'); return; }
    
    // Validar formato IP
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (!ipRegex.test(ip_destino)) {
        toast('IP no válida. Use formato 192.168.1.100','error');
        return;
    }
    
    post('programar_alarma', {
        ip_destino: ip_destino,
        nombre_estacion: nombre_estacion,
        fecha_disparo: fecha_disparo.replace('T',' ') + ':00',
        intervalo_minutos: intervalo,
        mensaje: mensaje
    }).then(res => {
        if (res.ok) { 
            toast('✅ Alarma programada correctamente.','ok'); 
            cargarAlarmasAdmin(); 
            setFechaMinima(); 
            limpiarFormulario();
        } else {
            toast(res.msg || 'Error al programar.','error');
        }
    });
}

function limpiarFormulario() {
    document.getElementById('sel-estacion').value = '';
    document.getElementById('inp-ip').value = '';
    document.getElementById('inp-nom-estacion').value = '';
    document.getElementById('inp-intervalo').value = '0';
    document.getElementById('inp-mensaje').value = 'Realizar prueba de calidad';
    setFechaMinima();
}

function cancelarAlarma(id) {
    if (!confirm('¿Cancelar esta alarma?')) return;
    post('cancelar_alarma', {id}).then(res => {
        if (res.ok) { toast('Alarma cancelada.','ok'); cargarAlarmasAdmin(); }
        else toast('Error al cancelar.','error');
    });
}

function cargarAlarmasAdmin() {
    const tbody = document.getElementById('tbody-alarmas');
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state">Cargando...</td></tr>';
    post('get_alarmas_admin').then(res => {
        if (!res.ok || !res.alarmas || !res.alarmas.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No hay alarmas en los últimos 7 días.</td></tr>';
            return;
        }
        tbody.innerHTML = res.alarmas.map(a => {
            let estadoTag;
            if (!a.activa) estadoTag = '<span class="tag tag-cancel">⊘ Cancelada</span>';
            else if (a.confirmada) estadoTag = '<span class="tag tag-conf">✅ Confirmada</span>';
            else estadoTag = '<span class="tag tag-pend"><span class="dot dot-amber"></span> Pendiente</span>';
            const intervBadge = parseInt(a.intervalo_minutos) > 0 ? `<span class="intervalo-badge">🔁 c/${a.intervalo_minutos}min</span>` : '<span style="color:var(--muted);font-size:11px;">Una vez</span>';
            const accion = (!a.activa || a.confirmada) ? '—' : `<button class="btn btn-danger btn-sm" onclick="cancelarAlarma(${a.id})">✕ Cancelar</button>`;
            return `<tr>
                <td><span class="ip-mono">${escape(a.ip_destino)}</span><br><span style="font-size:11px;color:var(--muted);">${escape(a.nombre_estacion)}</span></td>
                <td>${escape(a.mensaje)}</td>
                <td class="mono">${escape(a.disparo)}</td>
                <td>${intervBadge}</td>
                <td>${estadoTag}</td>
                <td>${a.confirmada ? escape(a.confirmada_por_nombre) : '—'}</td>
                <td>${a.confirmada ? formatDemora(a.segundos_respuesta) : '—'}</td>
                <td>${accion}</td>
            </tr>`;
        }).join('');
    });
}

function cargarHistorial() {
    const fecha = document.getElementById('f-fecha').value;
    const empleado = document.getElementById('f-empleado').value;
    const turno = document.getElementById('f-turno').value;
    const tbody = document.getElementById('tbody-historial');
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state">Cargando......</td></tr>';
    post('get_registros_admin', {fecha, empleado, turno}).then(res => {
        if (!res.ok || !res.registros || !res.registros.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No hay registros con esos filtros.</td></tr>';
            return;
        }
        tbody.innerHTML = res.registros.map(r => {
            const turnoTag = `<span class="tag tag-${(r.turno||'D')[0]}">${escape(r.turno)}</span>`;
            return `医学院
                <td class="mono">${escape(r.fecha_alarma)}</td>
                <td class="mono">${escape(r.codigo_empleado)}</td>
                <td>${escape(r.nombre_empleado)}</td>
                <td>${escape(r.nombre_estacion)}</td>
                <td><span class="ip-mono">${escape(r.ip_estacion)}</span></td>
                <td>${turnoTag}</td>
                <td class="mono">${escape(r.hora_confirmacion)}</td>
                <td>${formatDemora(r.segundos_respuesta)}</td>
            </tr>`;
        }).join('');
    });
}

function limpiarFiltros() {
    document.getElementById('f-fecha').value = '';
    document.getElementById('f-empleado').value = '';
    document.getElementById('f-turno').value = '';
    cargarHistorial();
}

function exportar() {
    const fecha = document.getElementById('f-fecha').value;
    const empleado = document.getElementById('f-empleado').value;
    const turno = document.getElementById('f-turno').value;
    let url = window.location.href.split('?')[0] + '?exportar=1';
    if (fecha) url += '&fecha=' + encodeURIComponent(fecha);
    if (empleado) url += '&empleado=' + encodeURIComponent(empleado);
    if (turno) url += '&turno=' + encodeURIComponent(turno);
    window.open(url, '_blank');
}

function cargarStats() {
    post('get_stats').then(res => {
        if (!res.ok) return;
        document.getElementById('s-pend').textContent = res.pendientes ?? '--';
        document.getElementById('s-hoy').textContent = res.hoy ?? '--';
        document.getElementById('s-sem').textContent = res.semana ?? '--';
        document.getElementById('s-mes').textContent = res.mes ?? '--';
        document.getElementById('s-total').textContent = res.total ?? '--';
        document.getElementById('s-avg').textContent = res.promedio_segundos ?? '--';
        document.getElementById('s-min').textContent = res.respuesta_rapida ?? '--';
    });
}

function limpiarSesion(e) {
    e.preventDefault();
    fetch('login_alarma.php?logout=1').finally(() => { window.location.href = 'login_alarma.php'; });
}

setInterval(() => {
    if (tabActual === 'programar') cargarAlarmasAdmin();
    if (tabActual === 'estadisticas') cargarStats();
}, 30000);

setFechaMinima();
cargarEstaciones();
cargarAlarmasAdmin();
</script>
</body>
</html>