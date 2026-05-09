<?php
/**
 * auditoria_admin.php
 * Panel de auditoría — historial completo de cambios realizados por administradores.
 * Muestra: quién hizo qué, cuándo, desde qué IP y los valores antes vs después.
 *
 * Ubicación: C:\xampp\htdocs\control_produccion\public\auditoria_admin.php
 *
 * By: Nestor Rosales | Rosales_Dev91
 */

session_start();
date_default_timezone_set('America/Guatemala');

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] != 'administrador') {
    header("Location: login_monitor.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría de Cambios — Admin</title>
    <style>
        /* ── RESET ── */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: linear-gradient(135deg, #0a3d2a 0%, #155724 100%);
            color: white;
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
            padding-bottom: 60px;
        }

        /* ── TOPBAR ── */
        .topbar {
            background: rgba(0, 51, 0, 0.95);
            backdrop-filter: blur(10px);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 2px solid #5cdf85;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4);
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .topbar h1   { font-size: 18px; }
        .badge-live  {
            display: flex; align-items: center; gap: 6px;
            background: rgba(92,223,133,0.2);
            border: 1px solid #5cdf85;
            padding: 4px 14px; border-radius: 20px; font-size: 12px;
        }
        .dot { width:9px; height:9px; border-radius:50%; background:#5cdf85; animation:pulse 1.4s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.25} }
        .topbar-links { display:flex; gap:16px; flex-wrap:wrap; }
        .topbar-links a {
            color:#a8f0b8; font-size:13px; text-decoration:none;
            padding:5px 12px; border-radius:20px; border:1px solid transparent; transition:all .2s;
        }
        .topbar-links a:hover { border-color:#5cdf85; background:rgba(92,223,133,0.1); }

        /* ── ADMIN EN TOPBAR ── */
        .admin-badge {
            display:flex; align-items:center; gap:8px;
            background:rgba(92,223,133,0.1);
            border:1px solid rgba(92,223,133,0.3);
            padding:5px 14px; border-radius:20px; font-size:12px;
        }
        .admin-badge strong { color:#5cdf85; }

        /* ── LAYOUT ── */
        .content { max-width:1280px; margin:auto; padding:24px 20px; }

        /* ── STAT CARDS ── */
        .stats-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(150px,1fr));
            gap:14px; margin-bottom:22px;
        }
        .stat-card {
            background:rgba(0,0,0,0.35); border-radius:12px; padding:16px 18px;
            border:1px solid rgba(92,223,133,0.3); transition:transform .2s,box-shadow .2s;
        }
        .stat-card:hover { transform:translateY(-2px); border-color:#5cdf85; box-shadow:0 5px 15px rgba(0,0,0,.3); }
        .stat-label { font-size:11px; color:#a8f0b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
        .stat-val   { font-size:26px; font-weight:bold; }
        .val-green  { color:#5cdf85; }  .val-blue  { color:#79c8ff; }
        .val-amber  { color:#ffc107; }  .val-red   { color:#ff6b6b; }
        .val-purple { color:#c9b3ff; }

        /* ── PANEL ── */
        .panel {
            background:rgba(0,0,0,0.35); border-radius:14px; padding:22px;
            border:1px solid rgba(92,223,133,0.3); margin-bottom:22px;
        }
        .panel-title {
            font-size:12px; font-weight:bold; letter-spacing:1.5px;
            text-transform:uppercase; color:#5cdf85;
            border-bottom:1px solid rgba(92,223,133,0.3);
            padding-bottom:10px; margin-bottom:18px;
        }

        /* ── FILTROS ── */
        .filters-bar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:18px; }
        .filter-group { display:flex; flex-direction:column; gap:4px; }
        .filter-group label { font-size:11px; color:#a8f0b8; text-transform:uppercase; letter-spacing:.8px; }
        .filter-select, .filter-input {
            background:rgba(255,255,255,0.1);
            border:1px solid rgba(92,223,133,0.4);
            border-radius:8px; color:white;
            padding:7px 12px; font-size:13px; min-width:140px; transition:border-color .2s;
        }
        .filter-select:focus, .filter-input:focus { outline:none; border-color:#5cdf85; background:rgba(255,255,255,0.15); }
        .filter-select option { background:#0a3d2a; }
        .btn-filter {
            background:#006400; border:none; border-radius:8px; color:white;
            padding:8px 18px; font-size:13px; cursor:pointer; transition:background .2s;
        }
        .btn-filter:hover { background:#008000; }
        .btn-reset {
            background:transparent; border:1px solid rgba(92,223,133,0.4);
            border-radius:8px; color:#a8f0b8; padding:8px 14px; font-size:13px;
            cursor:pointer; transition:all .2s;
        }
        .btn-reset:hover { border-color:#5cdf85; color:white; }

        /* ── TABLA ── */
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; min-width:820px; }
        thead th {
            background:rgba(0,100,0,0.5); padding:12px 14px; text-align:left;
            font-size:11px; text-transform:uppercase; letter-spacing:.8px; color:#a8f0b8;
            border-bottom:1px solid rgba(92,223,133,0.3); white-space:nowrap;
        }
        tbody tr { border-bottom:1px solid rgba(255,255,255,0.06); transition:background .15s; }
        tbody tr:hover { background:rgba(92,223,133,0.07); }
        tbody td { padding:11px 14px; vertical-align:top; line-height:1.4; }

        /* ── BADGE TIPO ── */
        .badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:bold; white-space:nowrap; }
        .b-agregar   { background:#083d2e; color:#5cdf85; border:1px solid #5cdf85; }
        .b-modificar { background:#1b1758; color:#c9b3ff; border:1px solid #c9b3ff; }
        .b-eliminar  { background:#5a1515; color:#ff6b6b; border:1px solid #ff6b6b; }
        .b-login     { background:#0d3a50; color:#79c8ff; border:1px solid #79c8ff; }
        .b-otro      { background:#3a2e0a; color:#ffc107; border:1px solid #ffc107; }

        /* ── CHIP DE ADMIN ── */
        .admin-chip { display:inline-flex; align-items:center; gap:6px; }
        .av-sm {
            width:28px; height:28px; border-radius:50%;
            background:linear-gradient(135deg,#2d2589,#1a1555);
            color:#cecbf6; font-size:10px; font-weight:bold;
            display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        .admin-info { display:flex; flex-direction:column; }
        .admin-nombre { font-size:13px; font-weight:bold; }
        .admin-codigo { font-size:10px; color:#a8f0b8; }

        /* ── DIFF VIEWER ── */
        .diff-toggle {
            background:rgba(92,223,133,0.1); border:1px solid rgba(92,223,133,0.3);
            color:#a8f0b8; padding:3px 10px; border-radius:8px;
            font-size:11px; cursor:pointer; transition:all .2s; white-space:nowrap;
        }
        .diff-toggle:hover { background:rgba(92,223,133,0.25); color:white; border-color:#5cdf85; }
        .diff-panel {
            display:none; margin-top:8px;
            background:rgba(0,0,0,0.4); border-radius:8px; overflow:hidden;
            border:1px solid rgba(92,223,133,0.2);
        }
        .diff-panel.open { display:block; }
        .diff-cols  { display:grid; grid-template-columns:1fr 1fr; }
        .diff-col   { padding:10px 12px; }
        .diff-col.antes   { background:rgba(255,80,80,0.08); border-right:1px solid rgba(255,255,255,0.1); }
        .diff-col.despues { background:rgba(92,223,133,0.08); }
        .diff-header { font-size:10px; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; font-weight:bold; }
        .diff-header.red   { color:#ff6b6b; }
        .diff-header.green { color:#5cdf85; }
        .diff-item { display:flex; justify-content:space-between; gap:10px; padding:3px 0; border-bottom:1px solid rgba(255,255,255,0.05); }
        .diff-item:last-child { border-bottom:none; }
        .diff-key   { font-size:11px; color:#a8f0b8; min-width:90px; flex-shrink:0; }
        .diff-value { font-size:11px; color:white; word-break:break-word; text-align:right; }
        .diff-value.changed { color:#5cdf85; font-weight:bold; }
        .diff-value.removed { color:#ff6b6b; text-decoration:line-through; }

        .ip-text    { font-size:11px; color:#5cdf85; }
        .fecha-text { font-size:12px; white-space:nowrap; }
        .desc-text  { font-size:13px; margin-bottom:4px; }
        .tabla-text { font-size:10px; color:#a8f0b8; }

        .empty-state { text-align:center; padding:50px 20px; color:#a8f0b8; }
        .empty-icon  { font-size:48px; margin-bottom:12px; }

        .spinner     { text-align:center; padding:40px; color:#a8f0b8; }
        .spin-circle {
            width:36px; height:36px; border:3px solid rgba(92,223,133,0.2);
            border-top-color:#5cdf85; border-radius:50%;
            animation:spin .8s linear infinite; margin:0 auto 14px;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        .hidden { display:none !important; }

        .footer-info { text-align:center; color:rgba(92,223,133,0.5); font-size:11px; padding-top:10px; }

        @media (max-width:600px) {
            .diff-cols { grid-template-columns:1fr; }
            .diff-col.antes { border-right:none; border-bottom:1px solid rgba(255,255,255,0.1); }
        }
    </style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="topbar">
    <div class="topbar-left">
        <h1>🔍 Auditoría de Cambios</h1>
        <div class="badge-live"><div class="dot"></div> EN VIVO</div>
    </div>
    <div class="topbar-links">
        <div class="admin-badge">
            👤 Sesión: <strong><?php echo htmlspecialchars($_SESSION['empleado'] ?? ''); ?></strong>
        </div>
        <a href="dashboard_monitor.php">📡 Monitor en Vivo</a>
        <a href="#" id="btn-export">📥 Exportar CSV</a>
        <a href="login_monitor.php?logout=1" onclick="return confirm('¿Cerrar sesión?')">🚪 Salir</a>
    </div>
</div>

<!-- ── CONTENIDO ── -->
<div class="content">

    <!-- TARJETAS DE ESTADÍSTICAS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">🕐 Últimas 24h</div>
            <div class="stat-val val-green" id="s-hoy">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">➕ Registros Agregados</div>
            <div class="stat-val val-green" id="s-agregar">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">✏️ Modificaciones</div>
            <div class="stat-val val-purple" id="s-modificar">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">🗑️ Eliminaciones</div>
            <div class="stat-val val-red" id="s-eliminar">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">🔓 Inicios de Sesión</div>
            <div class="stat-val val-blue" id="s-login">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">📋 Total Historial</div>
            <div class="stat-val val-amber" id="s-total">—</div>
        </div>
    </div>

    <!-- PANEL PRINCIPAL -->
    <div class="panel">
        <div class="panel-title">📋 Historial Completo — Quién hizo qué y cuándo</div>

        <!-- FILTROS -->
        <div class="filters-bar">
            <div class="filter-group">
                <label>Tipo de Acción</label>
                <select class="filter-select" id="f-tipo">
                    <option value="todos">Todos</option>
                    <option value="agregar">➕ Agregar</option>
                    <option value="modificar">✏️ Modificar</option>
                    <option value="eliminar">🗑️ Eliminar</option>
                    <option value="login">🔓 Login</option>
                    <option value="otro">📌 Otro</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Administrador</label>
                <select class="filter-select" id="f-admin">
                    <option value="">Todos los admins</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Módulo Afectado</label>
                <select class="filter-select" id="f-tabla">
                    <option value="">Todos los módulos</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Fecha Específica</label>
                <input type="date" class="filter-input" id="f-fecha">
            </div>
            <button class="btn-filter" onclick="aplicarFiltros()">🔍 Filtrar</button>
            <button class="btn-reset"  onclick="resetFiltros()">✕ Limpiar</button>
        </div>

        <!-- SPINNER DE CARGA -->
        <div class="spinner" id="spinner">
            <div class="spin-circle"></div>
            Cargando historial de auditoría...
        </div>

        <!-- TABLA DE RESULTADOS -->
        <div id="tabla-container" class="hidden">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha y Hora</th>
                            <th>Administrador</th>
                            <th>Tipo</th>
                            <th>Módulo</th>
                            <th>Descripción y Cambios</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-auditoria"></tbody>
                </table>
            </div>
            <div class="footer-info" id="footer-info"></div>
        </div>

        <!-- ESTADO VACÍO -->
        <div class="empty-state hidden" id="empty-state">
            <div class="empty-icon">📭</div>
            <p>No se encontraron registros con los filtros aplicados.</p>
            <p style="font-size:12px;margin-top:8px;color:#5cdf85">
                Los cambios se registrarán aquí automáticamente cuando los administradores realicen acciones.
            </p>
        </div>
    </div>

</div>

<script>
let datos_cache = null;

// ── Iniciales para avatar ──
function iniciales(nombre) {
    if (!nombre) return '??';
    const p = nombre.trim().split(' ');
    return p.length === 1
        ? p[0].substring(0,2).toUpperCase()
        : (p[0][0] + (p[1][0] || '')).toUpperCase();
}

// ── Escape HTML ──
function esc(t) {
    if (t === null || t === undefined) return '—';
    const d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}

// ── Badge visual por tipo ──
function badgeTipo(tipo) {
    const map = {
        agregar:   ['b-agregar',  '➕ Agregar'],
        modificar: ['b-modificar','✏️ Modificar'],
        eliminar:  ['b-eliminar', '🗑️ Eliminar'],
        login:     ['b-login',    '🔓 Login'],
        otro:      ['b-otro',     '📌 Otro']
    };
    const [cls, label] = map[tipo] || ['b-otro','📌'];
    return `<span class="badge ${cls}">${label}</span>`;
}

// ── Render del comparador antes/después ──
function renderDiff(id, antes, despues) {
    if (!antes && !despues) return '';

    let html = `<button class="diff-toggle" onclick="toggleDiff('diff-${id}')">🔎 Ver cambios detallados</button>
    <div class="diff-panel" id="diff-${id}">
        <div class="diff-cols">`;

    // Columna ANTES (rojo)
    html += `<div class="diff-col antes">
        <div class="diff-header red">⬅ ANTES del cambio</div>`;
    if (antes && Object.keys(antes).length > 0) {
        for (const [k, v] of Object.entries(antes)) {
            const cambiado = despues && despues[k] !== undefined && String(despues[k]) !== String(v);
            html += `<div class="diff-item">
                <span class="diff-key">${esc(k)}</span>
                <span class="diff-value ${cambiado ? 'removed' : ''}">${esc(v)}</span>
            </div>`;
        }
    } else {
        html += `<span style="font-size:11px;color:#a8f0b8">Registro nuevo (sin estado previo)</span>`;
    }
    html += `</div>`;

    // Columna DESPUÉS (verde)
    html += `<div class="diff-col despues">
        <div class="diff-header green">➡ DESPUÉS del cambio</div>`;
    if (despues && Object.keys(despues).length > 0) {
        for (const [k, v] of Object.entries(despues)) {
            const cambiado = antes && antes[k] !== undefined && String(antes[k]) !== String(v);
            html += `<div class="diff-item">
                <span class="diff-key">${esc(k)}</span>
                <span class="diff-value ${cambiado ? 'changed' : ''}">${esc(v)}</span>
            </div>`;
        }
    } else {
        html += `<span style="font-size:11px;color:#a8f0b8">Registro eliminado</span>`;
    }
    html += `</div></div></div>`;

    return html;
}

function toggleDiff(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

// ── Poblar selects de filtro con los datos disponibles ──
function poblarFiltros(data) {
    const selAdmin = document.getElementById('f-admin');
    const valorAdmin = selAdmin.value;
    selAdmin.innerHTML = '<option value="">Todos los admins</option>';
    (data.admins || []).forEach(a => {
        selAdmin.innerHTML += `<option value="${esc(a.admin_codigo)}">${esc(a.admin_nombre)} (${esc(a.admin_codigo)})</option>`;
    });
    selAdmin.value = valorAdmin;

    const selTabla = document.getElementById('f-tabla');
    const valorTabla = selTabla.value;
    selTabla.innerHTML = '<option value="">Todos los módulos</option>';
    (data.tablas || []).forEach(t => {
        selTabla.innerHTML += `<option value="${esc(t)}">${esc(t)}</option>`;
    });
    selTabla.value = valorTabla;
}

// ── Render de la tabla de resultados ──
function renderTabla(registros) {
    const tbody = document.getElementById('tbody-auditoria');
    const empty = document.getElementById('empty-state');
    const cont  = document.getElementById('tabla-container');

    if (!registros || registros.length === 0) {
        cont.classList.add('hidden');
        empty.classList.remove('hidden');
        return;
    }

    cont.classList.remove('hidden');
    empty.classList.add('hidden');

    tbody.innerHTML = registros.map(r => `
        <tr>
            <td style="color:#a8f0b8;font-size:11px">${r.id}</td>
            <td><div class="fecha-text">🕐 ${esc(r.fecha_hora)}</div></td>
            <td>
                <div class="admin-chip">
                    <div class="av-sm">${iniciales(r.admin_nombre)}</div>
                    <div class="admin-info">
                        <span class="admin-nombre">${esc(r.admin_nombre)}</span>
                        <span class="admin-codigo">${esc(r.admin_codigo)}</span>
                    </div>
                </div>
            </td>
            <td>${badgeTipo(r.tipo_accion)}</td>
            <td>
                <div class="tabla-text">📦 ${esc(r.tabla_afectada)}</div>
                ${r.id_registro ? `<div style="font-size:10px;color:#5cdf85">ID #${r.id_registro}</div>` : ''}
            </td>
            <td>
                <div class="desc-text">${esc(r.descripcion)}</div>
                ${renderDiff(r.id, r.datos_antes, r.datos_despues)}
            </td>
            <td><div class="ip-text">${r.ip ? '🌐 ' + esc(r.ip) : '—'}</div></td>
        </tr>
    `).join('');

    document.getElementById('footer-info').textContent =
        `Mostrando ${registros.length} registro${registros.length !== 1 ? 's' : ''}`;
}

// ── Petición a la API ──
function cargarAuditoria(params = {}) {
    document.getElementById('spinner').classList.remove('hidden');
    document.getElementById('tabla-container').classList.add('hidden');
    document.getElementById('empty-state').classList.add('hidden');

    const qs = new URLSearchParams(params).toString();
    fetch('auditoria_admin_api.php?' + qs + '&_=' + Date.now())
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
            document.getElementById('spinner').classList.add('hidden');
            if (!data.ok) { alert('Error: ' + (data.error || 'Desconocido')); return; }

            datos_cache = data;

            // Actualizar contadores
            document.getElementById('s-hoy').textContent      = data.cambios_hoy        ?? 0;
            document.getElementById('s-agregar').textContent  = data.totales?.agregar   ?? 0;
            document.getElementById('s-modificar').textContent= data.totales?.modificar ?? 0;
            document.getElementById('s-eliminar').textContent = data.totales?.eliminar  ?? 0;
            document.getElementById('s-login').textContent    = data.totales?.login     ?? 0;
            document.getElementById('s-total').textContent    = data.total              ?? 0;

            poblarFiltros(data);
            renderTabla(data.registros || []);
        })
        .catch(err => {
            document.getElementById('spinner').classList.add('hidden');
            document.getElementById('empty-state').classList.remove('hidden');
            console.error('Error auditoría:', err);
        });
}

function aplicarFiltros() {
    const params = {};
    const tipo  = document.getElementById('f-tipo').value;
    const admin = document.getElementById('f-admin').value;
    const tabla = document.getElementById('f-tabla').value;
    const fecha = document.getElementById('f-fecha').value;
    if (tipo && tipo !== 'todos') params.tipo  = tipo;
    if (admin)  params.admin = admin;
    if (tabla)  params.tabla = tabla;
    if (fecha)  params.fecha = fecha;
    cargarAuditoria(params);
}

function resetFiltros() {
    document.getElementById('f-tipo').value  = 'todos';
    document.getElementById('f-admin').value = '';
    document.getElementById('f-tabla').value = '';
    document.getElementById('f-fecha').value = '';
    cargarAuditoria();
}

// ── Exportar CSV ──
document.getElementById('btn-export').addEventListener('click', function(e) {
    e.preventDefault();
    if (!datos_cache || !datos_cache.registros) { alert('Cargue los datos primero.'); return; }

    const filas = [['ID','Fecha','Administrador','Codigo','Tipo','Modulo','ID Registro','Descripcion','IP']];
    datos_cache.registros.forEach(r => {
        filas.push([
            r.id,
            r.fecha_hora,
            r.admin_nombre,
            r.admin_codigo,
            r.tipo_accion,
            r.tabla_afectada,
            r.id_registro || '',
            '"' + (r.descripcion || '').replace(/"/g,'""') + '"',
            r.ip || ''
        ]);
    });

    const csv  = '\uFEFF' + filas.map(f => f.join(',')).join('\n');
    const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'auditoria_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
});

// ── Arranque ──
cargarAuditoria();

// Auto-refresh cada 30 segundos
setInterval(aplicarFiltros, 30000);
</script>
</body>
</html>
