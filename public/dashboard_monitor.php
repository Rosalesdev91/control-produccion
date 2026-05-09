<?php
/**
 * dashboard_monitor.php
 * Panel de monitoreo en vivo MEJORADO - VERSIÓN SMART TV
 * Optimizado para pantallas grandes y control remoto
 * 
 * MODIFICADO: Adaptado para Smart TVs y Android TV Box
 * - Textos más grandes
 * - Navegación por control remoto
 * - Scroll automático
 * - Controles optimizados para TV
 *
 * Requiere: api_monitor.php + registrar_actividad.php
 * By: Nestor Rosales | Rosales_Dev91
 */

session_start();
require_once 'registrar_actividad.php';

// Verificar autenticación
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] != 'administrador') {
    header("Location: login_monitor.php");
    exit();
}

// Zona horaria
date_default_timezone_set('America/Guatemala');

// Obtener fechas seleccionadas (por defecto hoy)
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#0a3d2a">
    <title>Monitor en Vivo</title>
    <style>
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }

        /* Modo TV: tamaños más grandes y mejor contraste */
        body {
            background: linear-gradient(135deg, #0a3d2a 0%, #155724 100%);
            color: white;
            font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
            min-height: 100vh;
            padding-bottom: 100px;
            font-size: 18px;
        }

        /* Enfoque para navegación por control remoto */
        .focusable:focus, button:focus, input:focus, a:focus, .filter-btn:focus {
            outline: 3px solid #ffc107 !important;
            outline-offset: 4px;
            transform: scale(1.02);
            transition: all 0.1s ease;
        }

        /* ── TOPBAR ── */
        .topbar {
            background: rgba(0, 51, 0, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            border-bottom: 2px solid #5cdf85;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        }
        .topbar h1 { 
            font-size: 28px; 
            font-weight: bold; 
        }
        .topbar a {  
            color: #a8f0b8; 
            font-size: 18px; 
            text-decoration: none; 
            padding: 10px 16px;
            border-radius: 30px;
            transition: all 0.3s;
            display: inline-block;
        }
        .topbar a:hover, .topbar a:focus { 
            background: rgba(92, 223, 133, 0.2);
            color: #5cdf85; 
            text-decoration: none;
            outline: 2px solid #5cdf85;
        }

        .badge-live {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(92, 223, 133, 0.2);
            border: 2px solid #5cdf85;
            padding: 8px 20px;
            border-radius: 40px;
            font-size: 18px;
        }
        .badge-historic {
            background: rgba(255, 193, 7, 0.2);
            border-color: #ffc107;
        }
        .dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #5cdf85;
            animation: pulse 1.4s infinite;
        }
        .dot-historic {
            background: #ffc107;
            animation: none;
        }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.25} }

        /* ── FILTRO FECHA ── */
        .fecha-filter {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(0,0,0,0.4);
            padding: 12px 20px;
            border-radius: 50px;
            flex-wrap: wrap;
        }
        .fecha-filter label {
            font-size: 16px;
            color: #a8f0b8;
            font-weight: bold;
        }
        .fecha-filter input {
            background: rgba(255,255,255,0.15);
            border: 2px solid #5cdf85;
            color: white;
            padding: 10px 16px;
            border-radius: 30px;
            font-size: 16px;
            cursor: pointer;
        }
        .fecha-filter input:focus {
            outline: 3px solid #ffc107;
            border-color: #ffc107;
        }
        .fecha-filter button {
            background: #5cdf85;
            border: none;
            color: #0a3d2a;
            padding: 10px 24px;
            border-radius: 30px;
            font-weight: bold;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
        }
        .fecha-filter button:hover, .fecha-filter button:focus {
            background: #ffc107;
            transform: scale(1.05);
            outline: 2px solid white;
        }
        .btn-csv {
            background: #ff6b6b !important;
            color: white !important;
        }

        /* ── WRAPPER ── */
        .content-wrapper { 
            max-width: 1600px; 
            margin: auto; 
            padding: 30px 25px; 
        }

        /* ── MÉTRICAS ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            padding: 22px 20px;
            border: 2px solid rgba(92, 223, 133, 0.4);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:focus {
            transform: translateY(-4px);
            outline: 3px solid #ffc107;
            border-color: #ffc107;
        }
        .stat-label { 
            font-size: 16px; 
            color: #a8f0b8; 
            margin-bottom: 12px; 
            text-transform: uppercase; 
            letter-spacing: 2px; 
        }
        .stat-val   { 
            font-size: 42px; 
            font-weight: bold; 
        }
        .val-green  { color: #5cdf85; }
        .val-amber  { color: #ffc107; }
        .val-red    { color: #ff6b6b; }
        .stat-unit  { font-size: 16px; color: #a8f0b8; margin-left: 6px; }

        /* ── GRID DOS COLUMNAS ── */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        @media (max-width: 1024px) { 
            .two-col { 
                grid-template-columns: 1fr; 
            } 
        }

        /* ── PANELES ── */
        .panel {
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            padding: 25px;
            border: 2px solid rgba(92, 223, 133, 0.4);
            transition: all 0.3s;
        }
        .panel:focus-within {
            border-color: #ffc107;
            outline: 2px solid #ffc107;
        }
        .panel-title {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #5cdf85;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(92, 223, 133, 0.4);
            padding-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* ── TABLA DE USUARIOS ── */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 16px;
        }
        .user-table th {
            text-align: left;
            padding: 15px 12px;
            color: #a8f0b8;
            border-bottom: 2px solid rgba(92, 223, 133, 0.4);
            font-weight: 600;
            font-size: 16px;
        }
        .user-table td {
            padding: 15px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            vertical-align: middle;
            font-size: 15px;
        }
        .user-table tr:hover { background: rgba(92, 223, 133, 0.15); }
        
        .avatar-small {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
        }
        .av-admin { background: linear-gradient(135deg, #2d2589, #1a1555); color: #cecbf6; }
        .av-emp   { background: linear-gradient(135deg, #0c5240, #063829); color: #9fe1cb; }
        .av-tecnico { background: linear-gradient(135deg, #1a5f2a, #0f3d1a); color: #b8f0c0; }
        
        .modulo-badge {
            background: rgba(92, 223, 133, 0.2);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            display: inline-block;
            font-weight: 500;
        }
        .tag {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }
        .tag-admin { background: #2d2589; color: #cecbf6; }
        .tag-emp { background: #085041; color: #9fe1cb; }
        .tag-tecnico { background: #1a5f2a; color: #b8f0c0; }

        .ip-text {
            font-family: monospace;
            font-size: 14px;
            background: rgba(0,0,0,0.4);
            padding: 6px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        /* ── BD STATUS ── */
        .db-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 16px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .db-key { color: #a8f0b8; font-size: 16px; }
        .db-val { font-weight: bold; color: #5cdf85; font-size: 20px; }

        .prog-wrap { margin-top: 20px; }
        .prog-label {
            display: flex;
            justify-content: space-between;
            font-size: 15px;
            color: #a8f0b8;
            margin-bottom: 10px;
        }
        .prog-bar {
            height: 12px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            overflow: hidden;
        }
        .prog-fill { height: 100%; border-radius: 10px; transition: width 0.5s ease; }

        /* ── ACTIVIDAD ── */
        .filters { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-btn {
            font-size: 16px;
            padding: 12px 24px;
            border-radius: 40px;
            cursor: pointer;
            border: 2px solid rgba(92, 223, 133, 0.6);
            background: transparent;
            color: #d4fcd4;
            transition: all 0.2s;
        }
        .filter-btn.active { 
            background: #006400; 
            border-color: #5cdf85; 
            color: white; 
        }
        .filter-btn:focus {
            outline: 3px solid #ffc107;
            transform: scale(1.05);
        }

        .act-feed { 
            max-height: 450px; 
            overflow-y: auto; 
        }
        .act-row {
            display: flex;
            gap: 18px;
            align-items: flex-start;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: background 0.2s;
        }
        .act-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: bold;
            flex-shrink: 0;
        }
        .act-text { 
            font-size: 16px; 
            line-height: 1.5; 
        }
        .act-time { 
            font-size: 14px; 
            color: #a8f0b8; 
            margin-top: 6px; 
        }
        .act-empleado {
            font-weight: bold;
            color: #5cdf85;
            background: rgba(92, 223, 133, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
            font-size: 14px;
            margin-right: 8px;
        }

        /* Scrollbar más grande para TV */
        ::-webkit-scrollbar { width: 12px; height: 12px; }
        ::-webkit-scrollbar-track { background: rgba(0,0,0,0.3); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #5cdf85; border-radius: 10px; }
        
        .table-scroll {
            max-height: 500px;
            overflow-y: auto;
        }

        .rango-fechas {
            font-size: 16px;
            background: rgba(0,0,0,0.5);
            padding: 10px 20px;
            border-radius: 40px;
            color: #ffc107;
            display: inline-block;
        }

        /* ── FOOTER ── */
        .firma {
            text-align: center;
            font-size: 16px;
            color: #a8f0b8;
            padding: 20px 25px;
            background: rgba(0, 51, 0, 0.95);
            border-top: 2px solid #5cdf85;
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            z-index: 100;
        }

        .logo {
            position: absolute;
            top: 20px;
            right: 30px;
            width: 180px;
            height: auto;
            opacity: 0.8;
        }

        #spinner {
            text-align: center;
            padding: 80px;
            font-size: 24px;
            color: #a8f0b8;
        }
        .hidden { display: none !important; }

        /* Modo kiosko - ocultar scrollbar si es necesario */
        @media (display-mode: fullscreen) {
            body { overflow-y: auto; }
        }
        
        /* Botones grandes para control remoto */
        button, .filter-btn, .fecha-filter button {
            min-height: 48px;
            min-width: 100px;
        }
        
        /* Alertas */
        .alertas-container {
            margin-bottom: 25px;
            display: none;
        }
        .alertas-container.show { display: block; }
        .alerta {
            background: rgba(255, 107, 107, 0.25);
            border-left: 6px solid #ff6b6b;
            padding: 16px 20px;
            margin-bottom: 12px;
            border-radius: 12px;
            font-size: 16px;
        }
        
        /* Estados de inactividad */
        .inactivo-bajo { color: #5cdf85; }
        .inactivo-medio { color: #ffc107; }
        .inactivo-alto { color: #ff6b6b; }
        
        /* Iconos de actividad */
        .ico-login { background: #1a5276; color: #85c1e9; }
        .ico-agregar { background: #1e7e34; color: #a8f0b8; }
        .ico-modificar { background: #b7950b; color: #fff3cd; }
        .ico-eliminar { background: #922b21; color: #f5b7b1; }
        .ico-otro { background: #6c3483; color: #d7bde2; }
    </style>
</head>
<body>

<img src="/control_produccion/public/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">

<div class="topbar">
    <h1>📡 Monitor en Vivo</h1>
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
        <div class="fecha-filter">
            <label>📅 DESDE:</label>
            <input type="date" id="fecha-inicio" class="focusable" value="<?php echo $fecha_inicio; ?>">
            <label>📅 HASTA:</label>
            <input type="date" id="fecha-fin" class="focusable" value="<?php echo $fecha_fin; ?>">
            <button id="btn-cargar-fecha" class="focusable">Ver</button>
            <button id="btn-hoy" class="focusable" style="background:#ffc107;">📆 Hoy</button>
            <button id="btn-semana" class="focusable" style="background:#4a90e2;">📅 Semana</button>
            <button id="btn-mes" class="focusable" style="background:#9b59b6;">📆 Mes</button>
            <button id="btn-exportar-csv" class="btn-csv focusable">📎 CSV</button>
        </div>
        <span id="badge-estado" class="badge-live">
            <span class="dot"></span>En vivo
        </span>
        <span id="reloj" style="font-size:18px;color:#a8f0b8;font-family:monospace">--:--:--</span>
        <span id="last-update" style="font-size:14px;color:#5cdf85"></span>
        <a href="dashboard_monitor_produccion.php" class="focusable">← Ir a Producción</a>
        <a href="login_monitor.php" class="focusable">🚪 Cerrar sesión</a>
    </div>
</div>

<div class="content-wrapper">

    <div id="spinner">
        <div>🔄 Cargando datos del sistema...</div>
        <div style="font-size:18px;margin-top:15px">Optimizado para Smart TV</div>
    </div>

    <div id="main-content" class="hidden">

        <div style="text-align: center; margin-bottom: 25px;">
            <span class="rango-fechas" id="rango-info">Cargando...</span>
        </div>

        <div id="alertas-container" class="alertas-container"></div>

        <div class="stats-grid" id="stats-grid">
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👥 Conectados ahora</div>
                <div class="stat-val val-green" id="s-online">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👑 Administradores activos</div>
                <div class="stat-val val-amber" id="s-admins">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👷 Empleados activos</div>
                <div class="stat-val val-green" id="s-emps">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📊 Total empleados BD</div>
                <div class="stat-val" id="s-total">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📝 Acciones registradas</div>
                <div class="stat-val" id="s-acciones">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">✅ Activos hoy</div>
                <div class="stat-val val-green" id="s-activos-hoy">—</div>
            </div>
        </div>

        <div class="two-col">

            <div class="panel">
                <div class="panel-title">
                    <span>👥 Usuarios en sesión activa</span>
                    <span id="user-count" style="font-size:16px;">0</span>
                </div>
                <div class="table-scroll">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Rol</th>
                                <th>📍 Módulo Actual</th>
                                <th>🌐 IP</th>
                                <th>⏱️ Inactivo</th>
                            </tr>
                        </thead>
                        <tbody id="user-table-body">
                            <tr><td colspan="5" style="text-align:center;">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title">🗄️ Base de datos — produccion_quiebras</div>
                <div class="db-row">
                    <span class="db-key">📋 Total empleados</span>
                    <span class="db-val" id="db-total">—</span>
                </div>
                <div class="db-row">
                    <span class="db-key">🔗 Conexiones activas</span>
                    <span class="db-val" id="db-conex">—</span>
                </div>
                <div class="db-row">
                    <span class="db-key">🕐 Último cambio tabla</span>
                    <span class="db-val" id="db-last">—</span>
                </div>
                <div class="db-row">
                    <span class="db-key">💾 Tamaño BD</span>
                    <span class="db-val" id="db-size">—</span>
                </div>

                <div class="prog-wrap">
                    <div class="prog-label">
                        <span>📊 Carga del servidor</span>
                        <span id="lbl-load">—</span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill" id="bar-load" style="width:0%;background:#5cdf85"></div>
                    </div>
                </div>
                <div class="prog-wrap">
                    <div class="prog-label">
                        <span>🔄 Sesiones PHP activas</span>
                        <span id="lbl-sess">—</span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill" id="bar-sess" style="width:0%;background:#ffc107"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">📜 Actividad reciente del sistema</div>
            <div class="filters" id="filters">
                <button class="filter-btn focusable active" data-filtro="todos">📋 Todos</button>
                <button class="filter-btn focusable" data-filtro="login">🔓 Acceso</button>
                <button class="filter-btn focusable" data-filtro="agregar">➕ Agregar</button>
                <button class="filter-btn focusable" data-filtro="modificar">✏️ Modificar</button>
                <button class="filter-btn focusable" data-filtro="eliminar">🗑️ Eliminar</button>
                <button class="filter-btn focusable" data-filtro="otro">📌 Otros</button>
            </div>
            <div class="act-feed" id="act-feed">
                <p style="color:#a8f0b8;">Cargando actividad...</p>
            </div>
        </div>

    </div>
</div>

<div class="firma">
    Monitor en vivo — Sistema de Monitoreo &nbsp;|&nbsp; © <?php echo date("Y"); ?>
    <p style="font-size:14px;margin-top:8px">Desarrollado Por: Nestor Rosales | Rosales_Dev91</p>
</div>

<script>
// ============================================
// VARIABLES GLOBALES
// ============================================
let filtroActual = 'todos';
let datosActuales = null;
let autoScrollInterval = null;
let autoScrollEnabled = true;

// ============================================
// RELOJ EN VIVO
// ============================================
function actualizarReloj() {
    const now = new Date();
    const fecha = now.toLocaleDateString('es-CR');
    const hora = now.toLocaleTimeString('es-CR');
    const reloj = document.getElementById('reloj');
    if (reloj) reloj.textContent = `${fecha} ${hora}`;
}
actualizarReloj();
setInterval(actualizarReloj, 1000);

// ============================================
// ACTUALIZAR BADGE SEGÚN RANGO DE FECHAS
// ============================================
function actualizarBadgeFecha(fechaInicio, fechaFin) {
    const badge = document.getElementById('badge-estado');
    const hoy = new Date().toISOString().slice(0,10);
    if (fechaInicio === hoy && fechaFin === hoy) {
        if (badge) {
            badge.innerHTML = '<span class="dot"></span>En vivo';
            badge.classList.remove('badge-historic');
        }
    } else {
        if (badge) {
            badge.innerHTML = '<span class="dot dot-historic"></span>Histórico';
            badge.classList.add('badge-historic');
        }
    }
    const rangoInfo = document.getElementById('rango-info');
    if (rangoInfo) rangoInfo.innerHTML = `📅 Mostrando datos del ${fechaInicio} al ${fechaFin}`;
}

// ============================================
// FILTROS DE ACTIVIDAD
// ============================================
function aplicarFiltro() {
    document.querySelectorAll('#act-feed .act-row').forEach(row => {
        const tipo = row.dataset.tipo;
        row.style.display = (filtroActual === 'todos' || tipo === filtroActual) ? 'flex' : 'none';
    });
}

document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        filtroActual = this.dataset.filtro;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        aplicarFiltro();
    });
});

// ============================================
// FUNCIONES UTILITARIAS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function iniciales(nombre) {
    if (!nombre) return '??';
    const partes = nombre.trim().split(' ');
    if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
    return (partes[0][0] + (partes[1][0] || '')).toUpperCase();
}

const moduloNombres = {
    'dashboard_monitor': '📡 Monitor en Vivo',
    'dashboard_monitor_produccion': '🏭 Monitor Producción',
    'dashboard_admin_empleados': '👥 Gestión de Empleados',
    'dashboard_admin_quiebras': '📊 Registro de Quiebras',
    'dashboard_admin_produccion': '🏭 Producción',
    'dashboard_admin_check': '✅ Check de Calidad',
    'dashboard_admin_asistencia': '⏰ Control de Pausas',
    'dashboard_admin_paros': '⚠️ Paros de Producción',
    'auditoria_admin': '🔍 Auditoría de Cambios',
    'registro': '📦 Registro Producción',
    'registro_picking': '📦 Registro Picking',
    'registro_asistencia': '⏰ Marcas Asistencia',
    'registro_paro': '⚠️ Solicitar Paro',
    'registro_quiebras': '💔 Registro Quiebras',
    'solicitudes_paro': '🔧 Atención Paros',
    'tareas_tecnico': '📋 Mis Tareas',
    'default': '🏠 Activo'
};

function getModuloNombre(modulo) {
    return moduloNombres[modulo] || moduloNombres['default'] + ' (' + modulo + ')';
}

function getAvatarClass(rol) {
    if (rol === 'administrador') return 'av-admin';
    if (rol === 'tecnico') return 'av-tecnico';
    return 'av-emp';
}

function getTagClass(rol) {
    if (rol === 'administrador') return 'tag-admin';
    if (rol === 'tecnico') return 'tag-tecnico';
    return 'tag-emp';
}

function getRolIcono(rol) {
    if (rol === 'administrador') return '👑';
    if (rol === 'tecnico') return '🔧';
    return '👤';
}

function getRolTexto(rol) {
    if (rol === 'administrador') return 'Admin';
    if (rol === 'tecnico') return 'Técnico';
    return 'Empleado';
}

// ============================================
// RENDERIZAR USUARIOS
// ============================================
function renderUsuarios(sesiones) {
    const tbody = document.getElementById('user-table-body');
    const userCount = document.getElementById('user-count');
    
    if (!sesiones || sesiones.length === 0) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">✨ No hay usuarios activos</td></tr>';
        if (userCount) userCount.textContent = '0';
        return;
    }
    
    if (userCount) userCount.textContent = sesiones.length;
    
    if (tbody) {
        tbody.innerHTML = sesiones.map(s => {
            const nombre = escapeHtml(s.nombre || 'Desconocido');
            const codigo = escapeHtml(s.codigo || '—');
            const modulo = s.modulo_nombre || getModuloNombre(s.modulo_actual || 'default');
            const inactivo = s.tiempo_inactivo !== undefined ? s.tiempo_inactivo : 0;
            const inactivoDisplay = (typeof inactivo === 'number' ? inactivo : parseFloat(inactivo)) + ' min';
            const rol = s.rol || 'empleado';
            const ip = escapeHtml(s.ip || 'No registrada');
            const avatarClass = getAvatarClass(rol);
            const tagClass = getTagClass(rol);
            const rolIcono = getRolIcono(rol);
            const rolTexto = getRolTexto(rol);
            
            let inactivoClass = '';
            if (inactivo > 10) inactivoClass = 'inactivo-alto';
            else if (inactivo > 5) inactivoClass = 'inactivo-medio';
            else inactivoClass = 'inactivo-bajo';
            
            return `
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div class="avatar-small ${avatarClass}">
                                ${iniciales(nombre)}
                            </div>
                            <div>
                                <div style="font-weight:bold; font-size:17px;">${nombre}</div>
                                <div style="font-size:13px; color:#a8f0b8;">📛 ${codigo}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="${tagClass}">${rolIcono} ${rolTexto}</span></td>
                    <td><span class="modulo-badge">${modulo}</span></td>
                    <td><span class="ip-text">🌐 ${ip}</span></td>
                    <td class="${inactivoClass}" style="font-size:14px;">⏱️ ${inactivoDisplay}</td>
                </tr>
            `;
        }).join('');
    }
}

// ============================================
// RENDERIZAR ALERTAS
// ============================================
function renderAlertas(data) {
    const container = document.getElementById('alertas-container');
    const alertas = data.alertas || [];
    const warnings = data.warnings || [];
    
    if (!container) return;
    
    if (alertas.length === 0 && warnings.length === 0) {
        container.classList.remove('show');
        return;
    }
    
    container.classList.add('show');
    let html = '';
    alertas.forEach(alerta => {
        html += `<div class="alerta">⚠️ ${escapeHtml(alerta)}</div>`;
    });
    warnings.forEach(warning => {
        html += `<div class="alerta">📌 ${escapeHtml(warning)}</div>`;
    });
    container.innerHTML = html;
}

// ============================================
// ACTUALIZAR BARRAS DE PROGRESO
// ============================================
function actualizarBarras(serverLoad, sesionesCount) {
    const load = Math.min(100, Math.max(0, serverLoad || 35));
    const sess = Math.min(95, Math.max(5, sesionesCount * 5 + 10));
    
    const lblLoad = document.getElementById('lbl-load');
    const barLoad = document.getElementById('bar-load');
    const lblSess = document.getElementById('lbl-sess');
    const barSess = document.getElementById('bar-sess');
    
    if (lblLoad) lblLoad.textContent = load + '%';
    if (barLoad) {
        barLoad.style.width = load + '%';
        barLoad.style.background = load > 70 ? '#ff6b6b' : load > 50 ? '#ffc107' : '#5cdf85';
    }
    
    if (lblSess) lblSess.textContent = Math.round(sess) + '%';
    if (barSess) barSess.style.width = sess + '%';
}

// ============================================
// RENDERIZAR ACTIVIDAD
// ============================================
function renderActividad(actividad) {
    const el = document.getElementById('act-feed');
    if (!el) return;
    
    if (!actividad || actividad.length === 0) {
        el.innerHTML = '<p style="text-align:center;">📭 No hay actividad registrada</p>';
        return;
    }
    
    const iconos = { login:'🔓', agregar:'➕', modificar:'✏️', eliminar:'🗑️', otro:'📌' };
    const clases = { login:'ico-login', agregar:'ico-agregar', modificar:'ico-modificar', eliminar:'ico-eliminar', otro:'ico-otro' };
    
    let html = '';
    for (let i = 0; i < actividad.length; i++) {
        const a = actividad[i];
        const tipo = a.tipo;
        let detalle = escapeHtml(a.detalle || 'Sin detalles');
        detalle = detalle.replace(/👤 ([^—]+) —/, '<span class="act-empleado">👤 $1</span> —');
        
        const fecha = escapeHtml(a.fecha_hora || '—');
        const ip = a.ip ? escapeHtml(a.ip) : '';
        
        html += `
            <div class="act-row" data-tipo="${tipo}">
                <div class="act-icon ${clases[tipo] || 'ico-otro'}">${iconos[tipo] || '📌'}</div>
                <div style="flex:1">
                    <div class="act-text">${detalle}</div>
                    <div class="act-time">
                        🕐 ${fecha}
                        ${ip ? `<span style="margin-left:12px;">🌐 ${ip}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
    el.innerHTML = html;
    aplicarFiltro();
}

// ============================================
// EXPORTAR CSV
// ============================================
function exportarCSV() {
    if (!datosActuales) {
        alert('No hay datos para exportar');
        return;
    }
    
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    const filename = `actividad_monitor_${fechaInicio}_a_${fechaFin}.csv`;
    
    let csvContent = "\uFEFF";
    csvContent += "Tipo,Usuario,Detalle,IP,Fecha/Hora\n";
    
    if (datosActuales.actividad && datosActuales.actividad.length > 0) {
        datosActuales.actividad.forEach(act => {
            const tipo = act.tipo || 'otro';
            const usuario = act.usuario || '—';
            const detalle = (act.detalle || '—').replace(/,/g, ';').replace(/\n/g, ' ');
            const ip = act.ip || '—';
            const fecha = act.fecha_hora || '—';
            csvContent += `"${tipo}","${usuario}","${detalle}","${ip}","${fecha}"\n`;
        });
    }
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// ============================================
// AUTO-SCROLL PARA TV
// ============================================
function startAutoScroll() {
    if (autoScrollInterval) clearInterval(autoScrollInterval);
    autoScrollInterval = setInterval(() => {
        if (autoScrollEnabled && document.visibilityState === 'visible') {
            const actFeed = document.querySelector('.act-feed');
            if (actFeed) {
                actFeed.scrollTop += 50;
                if (actFeed.scrollTop + actFeed.clientHeight >= actFeed.scrollHeight) {
                    actFeed.scrollTop = 0;
                }
            }
        }
    }, 5000);
}

// Detectar interacción del usuario para pausar auto-scroll
document.addEventListener('keydown', () => {
    autoScrollEnabled = false;
    setTimeout(() => { autoScrollEnabled = true; }, 10000);
});
document.addEventListener('click', () => {
    autoScrollEnabled = false;
    setTimeout(() => { autoScrollEnabled = true; }, 10000);
});

// ============================================
// CARGAR DATOS DESDE API
// ============================================
let cargando = false;

function cargarDatos() {
    if (cargando) return;
    cargando = true;
    
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    
    actualizarBadgeFecha(fechaInicio, fechaFin);
    
    fetch(`api_monitor.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&t=${Date.now()}`)
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => {
            if (data.success === false) {
                console.error('API error:', data.error);
                const spinner = document.getElementById('spinner');
                if (spinner) spinner.innerHTML = '<div>❌ Error al cargar datos: ' + (data.error || 'Desconocido') + '</div>';
                return;
            }
            
            datosActuales = data;
            
            const spinner = document.getElementById('spinner');
            const mainContent = document.getElementById('main-content');
            if (spinner) spinner.classList.add('hidden');
            if (mainContent) mainContent.classList.remove('hidden');
            
            // Actualizar estadísticas
            const elementos = {
                's-online': data.total_conectados || 0,
                's-admins': data.admins_activos || 0,
                's-emps': data.empleados_activos || 0,
                's-total': data.total_empleados || 0,
                's-acciones': data.actividad?.length || 0,
                's-activos-hoy': data.activos_hoy || 0,
                'db-total': data.total_empleados || 0,
                'db-conex': data.total_conectados || 0
            };
            
            for (const [id, valor] of Object.entries(elementos)) {
                const el = document.getElementById(id);
                if (el) el.textContent = typeof valor === 'number' ? valor.toLocaleString() : valor;
            }
            
            const dbLast = document.getElementById('db-last');
            if (dbLast) dbLast.textContent = data.ultimo_insert_db || '—';
            
            const dbSize = document.getElementById('db-size');
            if (dbSize) dbSize.textContent = data.db_size_mb ? data.db_size_mb + ' MB' : '—';
            
            const serverLoad = data.server_info?.load || 35;
            actualizarBarras(serverLoad, data.total_conectados || 0);
            
            renderUsuarios(data.sesiones || []);
            renderActividad(data.actividad || []);
            renderAlertas(data);
            
            const lastUpdate = document.getElementById('last-update');
            if (lastUpdate) lastUpdate.textContent = `🔄 Actualizado: ${new Date().toLocaleTimeString('es-CR')}`;
        })
        .catch(err => {
            console.error('Error:', err);
            const spinner = document.getElementById('spinner');
            if (spinner) spinner.innerHTML = '<div>❌ Error de conexión con el servidor</div>';
        })
        .finally(() => {
            cargando = false;
        });
}

// ============================================
// FUNCIONES DE FECHAS RÁPIDAS
// ============================================
function irAHoy() {
    const hoy = new Date().toISOString().slice(0,10);
    const fechaInicio = document.getElementById('fecha-inicio');
    const fechaFin = document.getElementById('fecha-fin');
    if (fechaInicio) fechaInicio.value = hoy;
    if (fechaFin) fechaFin.value = hoy;
    cargarDatos();
}

function irASemana() {
    const hoy = new Date();
    const diaSemana = hoy.getDay();
    const diasALunes = (diaSemana === 0 ? 6 : diaSemana - 1);
    const inicio = new Date(hoy);
    inicio.setDate(hoy.getDate() - diasALunes);
    const fin = new Date(inicio);
    fin.setDate(inicio.getDate() + 6);
    
    const fechaInicio = document.getElementById('fecha-inicio');
    const fechaFin = document.getElementById('fecha-fin');
    if (fechaInicio) fechaInicio.value = inicio.toISOString().slice(0,10);
    if (fechaFin) fechaFin.value = fin.toISOString().slice(0,10);
    cargarDatos();
}

function irAMes() {
    const hoy = new Date();
    const inicio = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    const fin = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
    
    const fechaInicio = document.getElementById('fecha-inicio');
    const fechaFin = document.getElementById('fecha-fin');
    if (fechaInicio) fechaInicio.value = inicio.toISOString().slice(0,10);
    if (fechaFin) fechaFin.value = fin.toISOString().slice(0,10);
    cargarDatos();
}

// ============================================
// EVENTOS
// ============================================
document.getElementById('btn-cargar-fecha')?.addEventListener('click', cargarDatos);
document.getElementById('btn-hoy')?.addEventListener('click', irAHoy);
document.getElementById('btn-semana')?.addEventListener('click', irASemana);
document.getElementById('btn-mes')?.addEventListener('click', irAMes);
document.getElementById('btn-exportar-csv')?.addEventListener('click', exportarCSV);

// Navegación por teclado para control remoto
document.addEventListener('keydown', (e) => {
    const focusable = document.querySelector('.focusable:focus');
    if (!focusable) return;
    
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Space') {
        focusable.click();
        e.preventDefault();
    }
});

// ============================================
// INICIALIZACIÓN
// ============================================
cargarDatos();
setInterval(cargarDatos, 10000); // Actualizar cada 10 segundos
startAutoScroll();

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) cargarDatos();
});

// ============================================
// TRACKING
// ============================================
(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ modulo: pagina, pagina: window.location.pathname, timestamp: Date.now() })
    }).catch(() => {});
})();
</script>
</body>
</html>