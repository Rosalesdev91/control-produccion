<?php
/**
 * dashboard_monitor_produccion.php
 * Panel de monitoreo de PRODUCCIÓN y QUIEBRAS - VERSIÓN SMART TV
 * Optimizado para pantallas grandes y control remoto
 * 
 * By: Nestor Rosales | Rosales_Dev91
 */

session_start();
require_once 'registrar_actividad.php';

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] != 'administrador') {
    header("Location: login_monitor.php");
    exit();
}

date_default_timezone_set('America/Guatemala');

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
    <title>Monitor — Producción y Quiebras</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: linear-gradient(135deg, #0a3d2a 0%, #155724 100%);
            color: white;
            font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
            min-height: 100vh;
            padding-bottom: 100px;
            font-size: 18px;
        }

        /* ESTILOS PARA MODO MATRIZ COMPLETA */
        body.matriz-fullscreen {
            padding-bottom: 0;
            background: #0a2a1a;
        }
        body.matriz-fullscreen .topbar,
        body.matriz-fullscreen .stats-grid,
        body.matriz-fullscreen .two-col,
        body.matriz-fullscreen .firma,
        body.matriz-fullscreen > .content-wrapper > .panel:not(#matriz-panel),
        body.matriz-fullscreen .rango-fechas,
        body.matriz-fullscreen > .content-wrapper > div:first-of-type {
            display: none !important;
        }
        body.matriz-fullscreen #matriz-panel {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            margin: 0;
            border-radius: 0;
            background: #0a2a1a;
            z-index: 99999;
            overflow: auto;
            padding: 15px;
        }
        body.matriz-fullscreen #matriz-panel .panel-title {
            font-size: 24px;
            margin-bottom: 20px;
        }
        body.matriz-fullscreen .table-scroll-matriz {
            max-height: calc(100vh - 100px);
        }
        body.matriz-fullscreen .matriz-table th,
        body.matriz-fullscreen .matriz-table td {
            font-size: 14px;
            padding: 12px 6px;
        }
        body.matriz-fullscreen .btn-salir-fullscreen {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #ff6b6b;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 100000;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        body.matriz-fullscreen .btn-salir-fullscreen:focus {
            outline: 3px solid #ffc107;
        }

        .focusable:focus, button:focus, input:focus, a:focus, .filter-btn:focus {
            outline: 3px solid #ffc107 !important;
            outline-offset: 4px;
            transform: scale(1.02);
        }

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
        .topbar h1 { font-size: 28px; font-weight: bold; }
        .topbar a {  
            color: #a8f0b8; 
            font-size: 18px; 
            text-decoration: none; 
            padding: 10px 16px;
            border-radius: 30px;
            transition: all 0.3s;
            display: inline-block;
        }
        .topbar a:focus { 
            background: rgba(92, 223, 133, 0.2);
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
        .dot-historic { background: #ffc107; animation: none; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.25} }

        .fecha-filter {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(0,0,0,0.4);
            padding: 12px 20px;
            border-radius: 50px;
            flex-wrap: wrap;
        }
        .fecha-filter label { font-size: 16px; color: #a8f0b8; font-weight: bold; }
        .fecha-filter input {
            background: rgba(255,255,255,0.15);
            border: 2px solid #5cdf85;
            color: white;
            padding: 10px 16px;
            border-radius: 30px;
            font-size: 16px;
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
        .fecha-filter button:focus {
            background: #ffc107;
            transform: scale(1.05);
        }
        .btn-csv { background: #ff6b6b !important; color: white !important; }
        .btn-fullscreen {
            background: #3a6ea5 !important;
            color: white !important;
        }

        .content-wrapper { max-width: 1600px; margin: auto; padding: 30px 25px; }

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
            transition: transform 0.2s;
        }
        .stat-card:focus { transform: translateY(-4px); outline: 3px solid #ffc107; }
        .stat-label { font-size: 16px; color: #a8f0b8; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 2px; }
        .stat-val { font-size: 42px; font-weight: bold; }
        .val-green { color: #5cdf85; }
        .val-amber { color: #ffc107; }
        .val-red { color: #ff6b6b; }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        @media (max-width: 1024px) { .two-col { grid-template-columns: 1fr; } }

        .panel {
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            padding: 25px;
            border: 2px solid rgba(92, 223, 133, 0.4);
        }
        .panel:focus-within { border-color: #ffc107; }
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
        }

        /* TABLA MATRICIAL - Producción por hora por área/equipo */
        .matriz-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .matriz-table th, .matriz-table td {
            border: 1px solid rgba(92, 223, 133, 0.3);
            padding: 8px 4px;
            text-align: center;
            vertical-align: middle;
        }
        .matriz-table th {
            background: rgba(0, 51, 0, 0.8);
            color: #a8f0b8;
            font-weight: bold;
            position: sticky;
            top: 0;
            font-size: 11px;
        }
        .matriz-table td {
            background: rgba(0, 0, 0, 0.3);
        }
        .matriz-table tr:hover td {
            background: rgba(92, 223, 133, 0.15);
        }
        .matriz-table .area-cell {
            text-align: left;
            font-weight: bold;
            background: rgba(0, 51, 0, 0.5);
            min-width: 120px;
        }
        .matriz-table .equipo-cell {
            text-align: left;
            background: rgba(0, 0, 0, 0.4);
            min-width: 140px;
        }
        .matriz-table .cantidad-cero {
            color: #555;
        }
        .matriz-table .cantidad-positiva {
            color: #5cdf85;
            font-weight: bold;
        }
        .matriz-table .ultimo-cell {
            text-align: center;
            font-family: monospace;
            font-size: 11px;
            color: #ffc107;
            min-width: 90px;
        }
        .table-scroll-matriz {
            max-height: 550px;
            overflow-x: auto;
            overflow-y: auto;
            border-radius: 12px;
        }

        .quiebra-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 18px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .quiebra-key { color: #a8f0b8; }
        .quiebra-val { font-weight: bold; color: #ff6b6b; font-size: 32px; }

        .tipo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        .tipo-card {
            background: rgba(0,0,0,0.3);
            border-radius: 12px;
            padding: 15px;
            text-align: center;
        }
        .tipo-card span:first-child { font-size: 14px; color: #a8f0b8; }
        .tipo-card span:last-child { font-size: 28px; font-weight: bold; color: #ff6b6b; display: block; margin-top: 8px; }

        .mini-stat {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin: 15px 0;
        }
        .mini-card {
            background: rgba(0,0,0,0.35);
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 16px;
            flex: 1;
            text-align: center;
        }

        .quiebra-count {
            display: inline-block;
            background: #ff6b6b;
            color: white;
            font-weight: bold;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 16px;
        }

        .ranking-number {
            display: inline-block;
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            text-align: center;
            line-height: 36px;
            font-weight: bold;
            font-size: 16px;
        }
        .ranking-number.top1 { background: #ffd700; color: #333; }
        .ranking-number.top2 { background: #c0c0c0; color: #333; }
        .ranking-number.top3 { background: #cd7f32; color: #333; }

        .motivo-tag, .responsable-tag {
            background: rgba(255, 107, 107, 0.25);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            display: inline-block;
            margin: 3px 6px 3px 0;
        }
        .responsable-tag { background: rgba(92, 223, 133, 0.25); }

        .filters { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-btn {
            font-size: 16px;
            padding: 12px 24px;
            border-radius: 40px;
            cursor: pointer;
            border: 2px solid rgba(92, 223, 133, 0.6);
            background: transparent;
            color: #d4fcd4;
        }
        .filter-btn.active { background: #006400; border-color: #5cdf85; color: white; }
        .filter-btn:focus { outline: 3px solid #ffc107; transform: scale(1.05); }

        .act-feed { max-height: 450px; overflow-y: auto; }
        .act-row {
            display: flex;
            gap: 18px;
            align-items: flex-start;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .act-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        .ico-prod { background: #0c5240; color: #5cdf85; }
        .ico-quiebra { background: #6b1c1c; color: #ff6b6b; }
        .act-text { font-size: 16px; line-height: 1.5; }
        .act-time { font-size: 14px; color: #a8f0b8; margin-top: 6px; }

        .firma {
            text-align: center;
            font-size: 16px;
            color: #a8f0b8;
            padding: 20px 25px;
            background: rgba(0, 51, 0, 0.95);
            border-top: 2px solid #5cdf85;
            position: fixed;
            bottom: 0;
            width: 100%;
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

        ::-webkit-scrollbar { width: 12px; height: 12px; }
        ::-webkit-scrollbar-track { background: rgba(0,0,0,0.3); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #5cdf85; border-radius: 10px; }

        .rango-fechas {
            font-size: 16px;
            background: rgba(0,0,0,0.5);
            padding: 10px 20px;
            border-radius: 40px;
            color: #ffc107;
            display: inline-block;
        }

        button, .filter-btn {
            min-height: 48px;
            min-width: 100px;
        }

        .motivos-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .motivo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .motivo-nombre {
            font-size: 16px;
            color: #ffc107;
        }
        .motivo-cantidad {
            font-size: 22px;
            font-weight: bold;
            color: #ff6b6b;
        }
        .motivo-barra {
            background: rgba(255,107,107,0.3);
            border-radius: 10px;
            height: 8px;
            margin-top: 5px;
            overflow: hidden;
        }
        .motivo-barra-fill {
            background: #ff6b6b;
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        .top-motivos-container {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .motivo-emoji {
            font-size: 20px;
            margin-right: 10px;
        }
        .ordenes-scroll {
            max-height: 300px;
            overflow-y: auto;
        }
        .ordenes-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .ordenes-table th, .ordenes-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .ordenes-table th {
            color: #a8f0b8;
            border-bottom: 2px solid rgba(92, 223, 133, 0.4);
        }
    </style>
</head>
<body>

<img src="/control_produccion/public/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">

<div class="topbar">
    <h1>📡 Monitor — Producción y Quiebras</h1>
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
            <button id="btn-fullscreen" class="btn-fullscreen focusable">🖥️ Ver Matriz Completa</button>
        </div>
        <span id="badge-estado" class="badge-live">
            <span class="dot"></span>En vivo
        </span>
        <span id="reloj" style="font-size:18px;color:#a8f0b8;font-family:monospace">--:--:--</span>
        <span id="last-update" style="font-size:14px;color:#5cdf85"></span>
        <a href="dashboard_monitor.php" class="focusable">← Volver al Monitor General</a>
        <a href="login_monitor.php" class="focusable">🚪 Cerrar sesión</a>
    </div>
</div>

<div class="content-wrapper">

    <div id="spinner">
        <div>🔄 Cargando datos de producción y quiebras...</div>
        <div style="font-size:18px;margin-top:15px">Optimizado</div>
    </div>

    <div id="main-content" class="hidden">

        <div class="stats-grid">
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📦 Producción</div>
                <div class="stat-val val-green" id="p-hoy">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">💔 Quiebras</div>
                <div class="stat-val val-red" id="q-hoy">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">👷 Empleados Activos</div>
                <div class="stat-val val-green" id="e-activos">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">🏭 Equipos Activos</div>
                <div class="stat-val val-amber" id="eq-activos">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📊 Órdenes Procesadas</div>
                <div class="stat-val" id="o-procesadas">—</div>
            </div>
            <div class="stat-card" tabindex="0">
                <div class="stat-label">📍 Áreas Activas</div>
                <div class="stat-val val-green" id="a-activas">—</div>
            </div>
        </div>

        <div style="text-align: center; margin-bottom: 25px;">
            <span class="rango-fechas" id="rango-info">Cargando...</span>
        </div>

        <!-- PANEL: TABLA MATRICIAL DE PRODUCCIÓN POR HORA -->
        <div class="panel" id="matriz-panel" style="margin-bottom: 20px;">
            <div class="panel-title">
                <span>📊 PRODUCCIÓN — Matriz por Hora (Área / Equipo vs Hora del día)</span>
                <span id="prod-count" style="font-size:16px;">0</span>
            </div>
            <div class="table-scroll-matriz">
                <table class="matriz-table" id="matriz-table">
                    <thead id="matriz-header">
                        <tr>
                            <th>ÁREA</th>
                            <th>EQUIPO</th>
                            <th>12</th><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th><th>7</th><th>8</th><th>9</th><th>10</th><th>11</th>
                            <th>12</th><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th><th>7</th><th>8</th><th>9</th><th>10</th><th>11</th>
                            <th>ÚLTIMO<br>REGISTRO</th>
                        </tr>
                        <tr style="background: rgba(255,193,7,0.2);">
                            <th colspan="2"></th>
                            <th colspan="12">AM</th>
                            <th colspan="12">PM</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="matriz-body">
                        <tr><td colspan="27">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="two-col">

            <div class="panel">
                <div class="panel-title">💔 Resumen de Quiebras</div>
                
                <div class="quiebra-row">
                    <span class="quiebra-key">💔 TOTAL GENERAL</span>
                    <span class="quiebra-val" id="q-total">—</span>
                </div>
                
                <div class="tipo-grid">
                    <div class="tipo-card"><span>👤 Persona</span><span id="tipo-persona">—</span></div>
                    <div class="tipo-card"><span>🛠️ Equipo</span><span id="tipo-equipo">—</span></div>
                    <div class="tipo-card"><span>🧪 Material</span><span id="tipo-material">—</span></div>
                    <div class="tipo-card"><span>🏢 Sucursal</span><span id="tipo-sucursal">—</span></div>
                </div>
                
                <div>
                    <div style="font-size: 14px; color: #a8f0b8; margin-bottom: 10px;">⏰ POR TURNO</div>
                    <div class="mini-stat" id="quiebras-por-turno"></div>
                </div>
                
                <div style="margin-top: 15px;">
                    <div style="font-size: 14px; color: #a8f0b8; margin-bottom: 10px;">🛠️ TOP EQUIPOS QUIEBRAS</div>
                    <div id="quiebras-equipo" style="max-height: 180px; overflow-y: auto;"></div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title">🔥 TOP MOTIVOS DE QUIEBRAS</div>
                <div id="top-motivos-container" class="top-motivos-container">
                    <div style="padding: 20px; text-align: center;">📊 Cargando motivos...</div>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom: 20px; border: 2px solid rgba(255,107,107,0.6);">
            <div class="panel-title">
                <span>⚠️ ÓRDENES CON MÁS QUIEBRAS</span>
                <span style="background:rgba(255,107,107,0.3);padding:6px 15px;border-radius:30px;">¡Atención!</span>
            </div>
            <div class="ordenes-scroll">
                <table class="ordenes-table">
                    <thead><tr><th>#</th><th>📋 Número de Orden</th><th>💔 Quiebras</th><th>📝 Motivos</th><th>👥 Responsables</th></tr></thead>
                    <tbody id="ordenes-table-body"><tr><td colspan="5">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">📜 Actividad Reciente</div>
            <div class="filters">
                <button class="filter-btn focusable active" data-filtro="todos">📋 Todos</button>
                <button class="filter-btn focusable" data-filtro="produccion">📦 Producción</button>
                <button class="filter-btn focusable" data-filtro="quiebra">💔 Quiebras</button>
            </div>
            <div class="act-feed" id="act-feed">
                <p>Cargando actividad...</p>
            </div>
        </div>

    </div>
</div>

<div class="firma">
    Monitor — Producción y Quiebras &nbsp;|&nbsp; © <?php echo date("Y"); ?>
    <p style="font-size:14px;margin-top:8px">Desarrollado Por: Nestor Rosales | Rosales_Dev91</p>
</div>

<script>
let filtroActual = 'todos';
let datosActuales = null;
let autoScrollInterval = null;
let autoScrollEnabled = true;

function actualizarReloj() {
    const now = new Date();
    document.getElementById('reloj').textContent = now.toLocaleTimeString('es-CR');
}
actualizarReloj();
setInterval(actualizarReloj, 1000);

function actualizarBadgeFecha(fechaInicio, fechaFin) {
    const badge = document.getElementById('badge-estado');
    const hoy = new Date().toISOString().slice(0,10);
    if (fechaInicio === hoy && fechaFin === hoy) {
        badge.innerHTML = '<span class="dot"></span>En vivo';
        badge.classList.remove('badge-historic');
    } else {
        badge.innerHTML = '<span class="dot dot-historic"></span>Histórico';
        badge.classList.add('badge-historic');
    }
    document.getElementById('rango-info').innerHTML = `📅 Mostrando datos del ${fechaInicio} al ${fechaFin}`;
}

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

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getMotivoEmoji(motivo) {
    const motivos = {
        'electrico': '⚡', 'mecanico': '🔧', 'operador': '👨‍🔧',
        'material': '📦', 'calidad': '🔍', 'seguridad': '🛡️',
        'software': '💻', 'hardware': '🖥️', 'humano': '👤', 'proceso': '📋'
    };
    for (let key in motivos) {
        if (motivo.toLowerCase().includes(key)) return motivos[key];
    }
    return '⚠️';
}

// FUNCIÓN: Renderizar tabla matricial
function renderTablaMatricial(matrizData) {
    const tbody = document.getElementById('matriz-body');
    
    if (!matrizData || matrizData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="27">✨ Sin producción registrada</td></tr>';
        document.getElementById('prod-count').textContent = '0';
        return;
    }
    
    let html = '';
    let totalFilas = 0;
    
    matrizData.forEach(row => {
        totalFilas++;
        html += '<tr>';
        html += `<td class="area-cell">${escapeHtml(row.area)}</td>`;
        html += `<td class="equipo-cell">${escapeHtml(row.equipo)}</td>`;
        
        // Mostrar las 24 horas (0-23)
        for (let hora = 0; hora <= 23; hora++) {
            const cantidad = row.horas[hora] || 0;
            const clase = cantidad > 0 ? 'cantidad-positiva' : 'cantidad-cero';
            const valor = cantidad > 0 ? cantidad : '';
            html += `<td class="${clase}">${valor}</td>`;
        }
        
        // Último registro
        const ultimoHtml = row.ultimo ? `🕐 ${row.ultimo}` : '—';
        html += `<td class="ultimo-cell">${ultimoHtml}</td>`;
        html += '</tr>';
    });
    
    tbody.innerHTML = html;
    document.getElementById('prod-count').textContent = totalFilas;
}

function renderQuiebrasPorTurno(porTurno) {
    const container = document.getElementById('quiebras-por-turno');
    if (!porTurno || Object.keys(porTurno).length === 0) {
        container.innerHTML = '<div class="mini-card">Sin datos</div>';
        return;
    }
    container.innerHTML = Object.keys(porTurno).map(turno => `
        <div class="mini-card">
            <div style="color:#a8f0b8;">TURNO ${turno}</div>
            <div style="font-size:28px;font-weight:bold;color:#ff6b6b;">${porTurno[turno]}</div>
        </div>
    `).join('');
}

function renderQuiebrasEquipo(lista) {
    const container = document.getElementById('quiebras-equipo');
    if (!lista || lista.length === 0) {
        container.innerHTML = '<div style="padding:15px;text-align:center;">Sin datos</div>';
        return;
    }
    const top15 = lista.slice(0, 15);
    container.innerHTML = top15.map((item, index) => `
        <div style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
            <div><span style="color:#ffc107;width:30px;display:inline-block;">#${index+1}</span> ${escapeHtml(item.equipo)}</div>
            <span style="color:#ff6b6b;font-size:20px;font-weight:bold;">${item.total}</span>
        </div>
    `).join('');
}

function renderQuiebrasPorTipo(porTipo) {
    if (!porTipo) return;
    document.getElementById('tipo-persona').textContent = porTipo.persona || 0;
    document.getElementById('tipo-equipo').textContent = porTipo.equipo || 0;
    document.getElementById('tipo-material').textContent = porTipo.material || 0;
    document.getElementById('tipo-sucursal').textContent = porTipo.sucursal || 0;
}

function renderTopMotivos(motivos) {
    const container = document.getElementById('top-motivos-container');
    
    if (!motivos || motivos.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center;">✅ No hay motivos registrados en este período</div>';
        return;
    }
    
    const motivosFiltrados = motivos.filter(m => m.motivo && m.motivo.trim() !== '' && m.motivo !== 'No especificado');
    
    if (motivosFiltrados.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center;">✅ No hay motivos específicos registrados</div>';
        return;
    }
    
    const maxCount = motivosFiltrados[0].total;
    
    container.innerHTML = `
        <ul class="motivos-list">
            ${motivosFiltrados.map((motivo, index) => {
                let rankIcon = '';
                if (index === 0) rankIcon = '🥇';
                else if (index === 1) rankIcon = '🥈';
                else if (index === 2) rankIcon = '🥉';
                else rankIcon = `${index + 1}°`;
                
                const porcentaje = maxCount > 0 ? (motivo.total / maxCount) * 100 : 0;
                const emoji = getMotivoEmoji(motivo.motivo);
                
                return `
                    <li class="motivo-item">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; align-items: center;">
                                    <span class="motivo-emoji">${rankIcon}</span>
                                    <span class="motivo-emoji">${emoji}</span>
                                    <span class="motivo-nombre">${escapeHtml(motivo.motivo)}</span>
                                </div>
                                <span class="motivo-cantidad">${motivo.total}</span>
                            </div>
                            <div class="motivo-barra">
                                <div class="motivo-barra-fill" style="width: ${porcentaje}%;"></div>
                            </div>
                        </div>
                    </li>
                `;
            }).join('')}
        </ul>
    `;
}

function renderOrdenesConMasQuiebras(ordenes) {
    const tbody = document.getElementById('ordenes-table-body');
    if (!ordenes || ordenes.length === 0 || (ordenes.length === 1 && ordenes[0].orden === 'Sin órdenes con quiebras')) {
        tbody.innerHTML = '<tr><td colspan="5">✅ Sin órdenes con quiebras</td><\/tr>';
        return;
    }
    tbody.innerHTML = ordenes.slice(0, 20).map((orden, index) => {
        let rankClass = '';
        if (index === 0) rankClass = 'top1';
        else if (index === 1) rankClass = 'top2';
        else if (index === 2) rankClass = 'top3';
        
        let motivosHtml = '—';
        if (orden.motivos && orden.motivos !== '—') {
            const motivosList = orden.motivos.split(',').slice(0, 3);
            motivosHtml = motivosList.map(m => `<span class="motivo-tag">${escapeHtml(m.trim())}</span>`).join('');
        }
        
        let responsablesHtml = '—';
        if (orden.responsables && orden.responsables !== '—') {
            const respList = orden.responsables.split(',').slice(0, 2);
            responsablesHtml = respList.map(r => `<span class="responsable-tag">${escapeHtml(r.trim())}</span>`).join('');
        }
        
        return `
            <tr>
                <td><span class="ranking-number ${rankClass}">${index + 1}</span></td>
                <td style="font-weight:bold;">${escapeHtml(orden.orden)}</td>
                <td><span class="quiebra-count">${orden.total_quiebras}</span></td>
                <td>${motivosHtml}</td>
                <td>${responsablesHtml}</td>
            </tr>
        `;
    }).join('');
}

function renderActividad(actividad) {
    const el = document.getElementById('act-feed');
    if (!actividad || actividad.length === 0) {
        el.innerHTML = '<p>📭 Sin actividad reciente</p>';
        return;
    }
    el.innerHTML = actividad.map(a => {
        const tipo = a.tipo;
        const icono = tipo === 'produccion' ? '📦' : '💔';
        const clase = tipo === 'produccion' ? 'ico-prod' : 'ico-quiebra';
        return `
            <div class="act-row" data-tipo="${tipo}">
                <div class="act-icon ${clase}">${icono}</div>
                <div style="flex:1">
                    <div class="act-text">${escapeHtml(a.detalle || 'Sin detalles')}</div>
                    <div class="act-time">🕐 ${escapeHtml(a.fecha_hora || '—')}</div>
                </div>
            </div>
        `;
    }).join('');
    aplicarFiltro();
}

function exportarCSV() {
    if (!datosActuales) { alert('No hay datos para exportar'); return; }
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    let csvContent = "\uFEFFTipo,Detalle,Fecha/Hora\n";
    if (datosActuales.actividad) {
        datosActuales.actividad.forEach(act => {
            const tipo = act.tipo === 'produccion' ? 'Producción' : 'Quiebra';
            const detalle = act.detalle.replace(/,/g, ';');
            csvContent += `"${tipo}","${detalle}","${act.fecha_hora}"\n`;
        });
    }
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.download = `produccion_quiebras_${fechaInicio}_a_${fechaFin}.csv`;
    link.href = URL.createObjectURL(blob);
    link.click();
    URL.revokeObjectURL(link.href);
}

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
    }, 6000);
}

document.addEventListener('keydown', () => { autoScrollEnabled = false; setTimeout(() => { autoScrollEnabled = true; }, 10000); });
document.addEventListener('click', () => { autoScrollEnabled = false; setTimeout(() => { autoScrollEnabled = true; }, 10000); });

let cargando = false;

function cargarDatos() {
    if (cargando) return;
    cargando = true;
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    if (fechaInicio > fechaFin) { alert('Fecha DESDE no puede ser mayor que HASTA'); cargando = false; return; }
    actualizarBadgeFecha(fechaInicio, fechaFin);
    fetch(`api_produccion_vivo.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&${Date.now()}`)
        .then(r => r.json())
        .then(data => {
            datosActuales = data;
            document.getElementById('spinner').classList.add('hidden');
            document.getElementById('main-content').classList.remove('hidden');
            document.getElementById('p-hoy').textContent = (data.produccion_hoy || 0).toLocaleString();
            document.getElementById('q-hoy').textContent = (data.quiebras_hoy || 0).toLocaleString();
            document.getElementById('e-activos').textContent = (data.empleados_activos_hoy || 0).toLocaleString();
            document.getElementById('eq-activos').textContent = (data.equipos_activos || 0).toLocaleString();
            document.getElementById('o-procesadas').textContent = (data.ordenes_procesadas || 0).toLocaleString();
            document.getElementById('a-activas').textContent = (data.areas_activas || 0).toLocaleString();
            
            // Renderizar tabla matricial
            renderTablaMatricial(data.produccion_matriz || []);
            
            document.getElementById('q-total').textContent = (data.quiebras_hoy || 0).toLocaleString();
            renderQuiebrasPorTipo(data.quiebras_por_tipo || {});
            renderQuiebrasPorTurno(data.quiebras_por_turno || {});
            renderQuiebrasEquipo(data.quiebras_por_equipo || []);
            renderTopMotivos(data.quiebras_por_motivo || []);
            renderOrdenesConMasQuiebras(data.ordenes_con_mas_quiebras || []);
            renderActividad(data.actividad || []);
            document.getElementById('last-update').textContent = `🔄 ${new Date().toLocaleTimeString('es-CR')}`;
        })
        .catch(err => console.error('Error:', err))
        .finally(() => { cargando = false; });
}

function irAHoy() {
    const hoy = new Date().toISOString().slice(0,10);
    document.getElementById('fecha-inicio').value = hoy;
    document.getElementById('fecha-fin').value = hoy;
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
    document.getElementById('fecha-inicio').value = inicio.toISOString().slice(0,10);
    document.getElementById('fecha-fin').value = fin.toISOString().slice(0,10);
    cargarDatos();
}
function irAMes() {
    const hoy = new Date();
    const inicio = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    const fin = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
    document.getElementById('fecha-inicio').value = inicio.toISOString().slice(0,10);
    document.getElementById('fecha-fin').value = fin.toISOString().slice(0,10);
    cargarDatos();
}

// Función para ver SOLO la matriz en pantalla completa
function toggleMatrizFullscreen() {
    const body = document.body;
    
    if (!body.classList.contains('matriz-fullscreen')) {
        // Entrar a modo matriz completa
        body.classList.add('matriz-fullscreen');
        
        // Crear botón de salir
        const exitBtn = document.createElement('button');
        exitBtn.id = 'exit-fullscreen-btn';
        exitBtn.className = 'btn-salir-fullscreen focusable';
        exitBtn.innerHTML = '✖ Salir de vista completa';
        exitBtn.addEventListener('click', exitMatrizFullscreen);
        document.body.appendChild(exitBtn);
        
        // Enfocar el botón para accesibilidad
        exitBtn.focus();
    } else {
        exitMatrizFullscreen();
    }
}

function exitMatrizFullscreen() {
    const body = document.body;
    body.classList.remove('matriz-fullscreen');
    const exitBtn = document.getElementById('exit-fullscreen-btn');
    if (exitBtn) {
        exitBtn.remove();
    }
    // Devolver foco al botón original
    const fullscreenBtn = document.getElementById('btn-fullscreen');
    if (fullscreenBtn) fullscreenBtn.focus();
}

document.getElementById('btn-cargar-fecha').addEventListener('click', cargarDatos);
document.getElementById('btn-hoy').addEventListener('click', irAHoy);
document.getElementById('btn-semana').addEventListener('click', irASemana);
document.getElementById('btn-mes').addEventListener('click', irAMes);
document.getElementById('btn-exportar-csv').addEventListener('click', exportarCSV);
document.getElementById('btn-fullscreen').addEventListener('click', toggleMatrizFullscreen);

// Soporte para tecla ESC para salir del modo
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('matriz-fullscreen')) {
        exitMatrizFullscreen();
    }
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Space') {
        const focused = document.querySelector('.focusable:focus');
        if (focused) { focused.click(); e.preventDefault(); }
    }
});

cargarDatos();
setInterval(cargarDatos, 60000);
startAutoScroll();
document.addEventListener('visibilitychange', () => { if (!document.hidden) cargarDatos(); });

(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ modulo: pagina, timestamp: Date.now() }) }).catch(() => {});
})();
</script>

<script>
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