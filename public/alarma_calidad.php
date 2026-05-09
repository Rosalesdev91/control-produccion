<?php
// ============================================================
//  alarma_calidad.php  —  Vista OPERARIO
//  VERSIÓN CON SONIDO MEJORADO Y MÁS FUERTE
// ============================================================
session_start();
require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('America/Costa_Rica');

function getClientIP() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$miIP = getClientIP();

$nombreEstacion = '';
$stmt = $conn->prepare("SELECT nombre FROM alarma_estaciones WHERE ip = ? AND activa = 1 LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $miIP);
    $stmt->execute();
    $stmt->bind_result($nombreEstacion);
    $stmt->fetch();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alarma de Calidad<?= $nombreEstacion ? ' — ' . htmlspecialchars($nombreEstacion) : '' ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #f0f2f5;
            --card: #ffffff;
            --border: #dde1e7;
            --text: #111827;
            --muted: #6b7280;
            --blue: #185FA5;
            --blue-dk: #0e4275;
            --blue-lt: #E6F1FB;
            --green: #2E6B12;
            --green-lt: #EAF4DC;
            --red: #B91C1C;
            --red-mid: #EF4444;
            --amber: #92400E;
            --amber-lt: #FEF3C7;
            --radius: 14px;
            --shadow: 0 2px 12px rgba(0,0,0,.08);
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            background: var(--blue-dk);
            color: #fff;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .topbar-logo { font-size: 18px; font-weight: 700; }
        .topbar-sub { font-size: 12px; opacity: .75; }
        .topbar-right { font-size: 22px; font-family: monospace; }

        .main {
            flex: 1;
            max-width: 640px;
            width: 100%;
            margin: 0 auto;
            padding: 28px 16px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            box-shadow: var(--shadow);
        }

        .clock-big {
            font-size: 64px;
            font-weight: 200;
            letter-spacing: 6px;
            font-family: monospace;
            text-align: center;
        }
        .clock-date {
            font-size: 14px;
            color: var(--muted);
            text-align: center;
            margin-top: 6px;
        }

        .station-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .station-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 16px;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 600;
        }
        .badge-station { background: var(--blue-lt); color: var(--blue-dk); }
        .badge-ip { background: #f3f4f6; color: var(--muted); font-family: monospace; }

        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .dot-green { background: #22c55e; }
        .dot-red { background: var(--red-mid); animation: blink 0.7s infinite; }
        .dot-amber { background: #f59e0b; }
        .dot-blue { background: var(--blue); }
        .dot-grey { background: #9ca3af; }

        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.15} }

        .status-info { text-align: center; margin-top: 10px; }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 14px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
        }
        .pill-active { background: var(--green-lt); color: var(--green); }
        .pill-alarm { background: #FEE2E2; color: var(--red); }
        .pill-noconf { background: var(--amber-lt); color: var(--amber); }
        .pill-ok { background: var(--blue-lt); color: var(--blue-dk); }
        .pill-error { background: #f3f4f6; color: #6b7280; }

        .proxima-wrap {
            text-align: center;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }
        .proxima-label { font-size: 12px; color: var(--muted); text-transform: uppercase; }
        .proxima-val { font-size: 26px; font-weight: 600; color: var(--blue); font-family: monospace; }
        .proxima-fecha { font-size: 12px; color: var(--muted); margin-top: 2px; }

        #alarm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(185,28,28,0.97);
            z-index: 1000;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
        }
        #alarm-overlay.visible { display: flex; }

        .alarm-icon { font-size: 72px; animation: shake 0.4s infinite; }
        @keyframes shake {
            0%,100% { transform: rotate(-14deg) scale(1.05); }
            50% { transform: rotate(14deg) scale(1.05); }
        }
        .alarm-title { font-size: 32px; font-weight: 800; color: #fff; }
        .alarm-msg { font-size: 18px; color: rgba(255,255,255,0.9); margin: 16px 0 32px; }

        .confirm-box {
            background: rgba(255,255,255,0.12);
            border: 2px solid rgba(255,255,255,0.35);
            border-radius: var(--radius);
            padding: 28px 32px;
            max-width: 420px;
            width: 100%;
        }
        .confirm-label { font-size: 14px; color: rgba(255,255,255,0.85); margin-bottom: 12px; }
        .confirm-input {
            width: 100%;
            padding: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-radius: 10px;
            font-size: 22px;
            text-align: center;
            letter-spacing: 5px;
            font-family: monospace;
            background: rgba(255,255,255,0.15);
            color: #fff;
            text-transform: uppercase;
        }
        .btn-confirm {
            width: 100%;
            margin-top: 12px;
            padding: 14px;
            border-radius: 10px;
            border: none;
            background: #fff;
            color: var(--red);
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
        }
        .error-txt { font-size: 13px; color: #fecaca; margin-top: 8px; }

        #success-panel {
            display: none;
            background: var(--green-lt);
            border-radius: var(--radius);
            padding: 24px;
            text-align: center;
        }
        #success-panel.visible { display: block; }

        .btn-debug {
            background: transparent;
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 10px;
        }
        .btn-debug:hover { background: var(--border); }

        .debug-info {
            font-size: 11px;
            color: var(--muted);
            text-align: center;
            margin-top: 10px;
            padding: 8px;
            background: #f9fafb;
            border-radius: 8px;
            font-family: monospace;
        }
        .firma { text-align: center; font-size: 11px; color: var(--muted); padding: 12px; }

        .conexion-badge {
            display: inline-block;
            margin-left: 10px;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .conexion-online { background: #22c55e20; color: #22c55e; }
        .conexion-offline { background: #ef444420; color: #ef4444; }
        .noconf-note { text-align: center; color: var(--muted); font-size: 13px; }
        
        /* Volumen visual */
        .volume-visualizer {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: rgba(0,0,0,0.7);
            color: #fff;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-family: monospace;
            z-index: 999;
            display: none;
        }
        .volume-visualizer.visible { display: block; }
    </style>
</head>
<body>

<div class="topbar">
    <div>
        <div class="topbar-logo">⚙ Alarma de Calidad</div>
        <div class="topbar-sub">Revisión de máquinas</div>
    </div>
    <div class="topbar-right" id="reloj-top">--:--:--</div>
</div>

<div id="alarm-overlay">
    <span class="alarm-icon">🚨</span>
    <div class="alarm-title">¡PRUEBA DE CALIDAD REQUERIDA!</div>
    <div class="alarm-msg" id="alarm-msg-text">Realizar prueba de calidad</div>
    <div class="confirm-box">
        <div class="confirm-label">🔐 Ingrese su código de empleado:</div>
        <input type="text" id="emp-code" class="confirm-input" placeholder="CÓDIGO" maxlength="20" autocomplete="off">
        <button class="btn-confirm" id="btn-confirm" onclick="confirmarAlarma()">✔ CONFIRMAR</button>
        <div class="error-txt" id="error-txt"></div>
    </div>
</div>

<div class="main">
    <div class="card">
        <div class="clock-big" id="clock-big">00:00:00</div>
        <div class="clock-date" id="clock-date"></div>

        <div class="station-info">
            <?php if ($nombreEstacion): ?>
            <span class="station-badge badge-station">📍 <?= htmlspecialchars($nombreEstacion) ?></span>
            <?php endif; ?>
            <span class="station-badge badge-ip">📡 <?= htmlspecialchars($miIP) ?></span>
        </div>

        <div class="status-info">
            <span class="status-pill pill-active" id="status-pill">
                <span class="dot dot-green" id="status-dot"></span>
                <span id="status-txt">Iniciando...</span>
            </span>
            <span id="conexion-badge" class="conexion-badge conexion-online">● Conectado</span>
        </div>

        <div class="proxima-wrap" id="proxima-wrap" style="display:none;">
            <div class="proxima-label">⏰ Próxima alarma</div>
            <div class="proxima-val" id="proxima-countdown">--:--:--</div>
            <div class="proxima-fecha" id="proxima-fecha"></div>
        </div>

        <button class="btn-debug" onclick="forzarVerificacion()">🔍 Forzar verificación ahora</button>
        <div class="debug-info" id="debug-info">Última verificación: --:--:--</div>
    </div>

    <div id="success-panel">
        <div style="font-size: 48px;">✅</div>
        <div style="font-size: 22px; font-weight: 700; color: var(--green);" id="success-title">Revisión confirmada</div>
        <div id="success-body"></div>
    </div>

    <div class="noconf-note" id="no-alarm-note" style="display:none;">
        No hay alarmas programadas para esta estación.
    </div>
</div>

<div class="firma">Sistema de Alarma de Calidad — <?= htmlspecialchars($miIP) ?> | <?= date('Y') ?></div>
<div class="volume-visualizer" id="volume-visualizer">🔊 Sonando...</div>

<script>
let alarmaId = null;
let alarmaActiva = false;
let audioLoop = null;
let proximaFecha = null;
let confirmando = false;
let erroresConsecutivos = 0;
let checkIntervalId = null;
let audioContext = null;
let isAudioInitialized = false;

const overlay = document.getElementById('alarm-overlay');
const empCode = document.getElementById('emp-code');
const statusPill = document.getElementById('status-pill');
const statusDot = document.getElementById('status-dot');
const statusTxt = document.getElementById('status-txt');
const proximaWrap = document.getElementById('proxima-wrap');
const proximaCount = document.getElementById('proxima-countdown');
const proximaFechaEl = document.getElementById('proxima-fecha');
const successPanel = document.getElementById('success-panel');
const noAlarmNote = document.getElementById('no-alarm-note');
const alarmMsg = document.getElementById('alarm-msg-text');
const debugInfo = document.getElementById('debug-info');
const conexionBadge = document.getElementById('conexion-badge');
const btnConfirm = document.getElementById('btn-confirm');
const volumeVisualizer = document.getElementById('volume-visualizer');

// =====================================================
// SONIDO MEJORADO - MÚLTIPLES OPCIONES
// =====================================================

// Opción 1: Web Audio API con sonido potente (recomendado)
async function sonidoPotente() {
    try {
        // Inicializar AudioContext (necesita interacción del usuario primero)
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        
        // Asegurar que el contexto está activo
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }
        
        const now = audioContext.currentTime;
        
        // Crear múltiples osciladores para sonido más fuerte
        const frecuencias = [880, 880, 1760, 440];
        const ganancias = [0.5, 0.5, 0.3, 0.4];
        
        frecuencias.forEach((freq, i) => {
            const osc = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const filter = audioContext.createBiquadFilter();
            
            osc.connect(filter);
            filter.connect(gain);
            gain.connect(audioContext.destination);
            
            osc.frequency.value = freq;
            osc.type = i === 0 ? 'sawtooth' : 'square'; // Sonidos más agresivos
            
            // Filter para más impacto
            filter.type = 'bandpass';
            filter.frequency.value = freq;
            filter.Q.value = 5;
            
            // Envolvente de volumen con ataque rápido
            gain.gain.setValueAtTime(0, now);
            gain.gain.linearRampToValueAtTime(ganancias[i], now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.5);
            
            osc.start(now);
            osc.stop(now + 0.5);
        });
        
        return true;
    } catch(e) {
        console.log('Web Audio falló:', e);
        return false;
    }
}

// Opción 2: Audio HTML5 con volumen máximo
function sonidoHTML5() {
    try {
        // Usar un beep en base64 para no depender de archivos externos
        const audio = new Audio();
        
        // Crear un beep simple usando AudioContext como fallback
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        const ctx = new AudioContextClass();
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        
        oscillator.frequency.value = 880;
        oscillator.type = 'square';
        
        // Volumen al máximo
        gain.gain.value = 0.8;
        
        oscillator.start();
        
        // Hacer el sonido más largo
        gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.8);
        oscillator.stop(ctx.currentTime + 0.8);
        
        setTimeout(() => ctx.close(), 1000);
        
        return true;
    } catch(e) {
        console.log('HTML5 Audio falló:', e);
        return false;
    }
}

// Opción 3: Sonido de alerta del sistema (más simple)
function sonidoSistema() {
    try {
        // Usar el sonido de alerta del navegador
        const audio = new Audio();
        audio.volume = 1.0;
        
        // Intentar cargar un beep si está disponible
        audio.src = 'data:audio/wav;base64,U3RlYWx0aCBzb3VuZA==';
        audio.play().catch(e => console.log('System sound not available'));
    } catch(e) {
        console.log('System sound falló');
    }
}

// Opción 4: Reproducción en bucle con pausas cortas
let sonidoPrincipal = null;
let sonidoInterval = null;

function sonidoAlarmaPotente() {
    if (!alarmaActiva) return;
    
    // Mostrar visualizador
    volumeVisualizer.classList.add('visible');
    
    // Reproducir sonido potente
    sonidoPotente().then(exito => {
        if (!exito) {
            sonidoHTML5();
        }
    });
    
    // También intentar con el método tradicional para redundancia
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass) {
            const ctx = new AudioContextClass();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            osc.type = 'sawtooth';
            gain.gain.value = 0.7;
            osc.start();
            gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.6);
            osc.stop(ctx.currentTime + 0.6);
            setTimeout(() => ctx.close(), 700);
        }
    } catch(e) {}
    
    // Programar siguiente sonido (más frecuente para más impacto)
    if (alarmaActiva) {
        audioLoop = setTimeout(() => sonidoAlarmaPotente(), 1500); // Cada 1.5 segundos
    }
}

function sonarBeep() {
    if (!alarmaActiva) return;
    sonidoAlarmaPotente();
}

function detenerSonido() {
    if (audioLoop) {
        clearTimeout(audioLoop);
        audioLoop = null;
    }
    if (sonidoInterval) {
        clearInterval(sonidoInterval);
        sonidoInterval = null;
    }
    if (sonidoPrincipal) {
        sonidoPrincipal.pause();
        sonidoPrincipal = null;
    }
    volumeVisualizer.classList.remove('visible');
}

// Inicializar audio con interacción del usuario (requerido por navegadores modernos)
function initAudioOnUserInteraction() {
    if (isAudioInitialized) return;
    
    if (!audioContext) {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
    }
    
    if (audioContext.state === 'suspended') {
        audioContext.resume().then(() => {
            console.log('Audio inicializado');
            isAudioInitialized = true;
        });
    } else {
        isAudioInitialized = true;
    }
}

// Registrar eventos para inicializar audio
document.addEventListener('click', initAudioOnUserInteraction);
document.addEventListener('keydown', initAudioOnUserInteraction);
document.addEventListener('touchstart', initAudioOnUserInteraction);

// También intentar inicializar al cargar la página
window.addEventListener('load', () => {
    // Pequeño delay para intentar inicializar
    setTimeout(() => {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
    }, 1000);
});

function actualizarReloj() {
    const ahora = new Date();
    const hms = ahora.toLocaleTimeString('es-CR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const fecha = ahora.toLocaleDateString('es-CR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    document.getElementById('clock-big').textContent = hms;
    document.getElementById('clock-date').textContent = fecha.charAt(0).toUpperCase() + fecha.slice(1);
    document.getElementById('reloj-top').textContent = hms;

    if (proximaFecha && !alarmaActiva) {
        const diff = Math.max(0, Math.floor((new Date(proximaFecha) - ahora) / 1000));
        if (diff > 0) {
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            proximaCount.textContent = (h > 0 ? String(h).padStart(2,'0') + ':' : '') + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        } else {
            proximaCount.textContent = '¡AHORA!';
        }
    }
}

function setEstado(tipo, msg) {
    const configs = {
        active: { cls: 'pill-active', dot: 'dot-green' },
        alarm: { cls: 'pill-alarm', dot: 'dot-red' },
        noconf: { cls: 'pill-noconf', dot: 'dot-amber' },
        ok: { cls: 'pill-ok', dot: 'dot-blue' },
        error: { cls: 'pill-error', dot: 'dot-grey' }
    };
    const c = configs[tipo] || configs.active;
    statusPill.className = 'status-pill ' + c.cls;
    statusDot.className = 'dot ' + c.dot;
    statusTxt.textContent = msg;
}

function forzarVerificacion() {
    debugInfo.textContent = '🔄 Forzando verificación...';
    checkAlarma(true);
}

async function checkAlarma(forzar = false) {
    if (alarmaActiva && !forzar) return;

    const ahora = new Date();
    debugInfo.textContent = `🕐 Última verificación: ${ahora.toLocaleTimeString()}`;

    try {
        const response = await fetch('/control_produccion/public/alarma_api.php', { 
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'accion=check_alarma',
            cache: 'no-cache'
        });
        
        const textResponse = await response.text();
        console.log('Check response:', textResponse);
        
        const res = JSON.parse(textResponse);
        
        erroresConsecutivos = 0;
        conexionBadge.className = 'conexion-badge conexion-online';
        conexionBadge.textContent = '● Conectado';

        if (!res.ok) {
            setEstado('error', 'Error del servidor');
            return;
        }

        if (res.alarma) {
            if (!alarmaActiva) {
                alarmaId = res.alarma_id;
                dispararAlarma(res.mensaje, res.estacion);
            }
        } else {
            if (res.proxima) {
                proximaFecha = res.proxima;
                proximaWrap.style.display = 'block';
                noAlarmNote.style.display = 'none';
                const dt = new Date(res.proxima);
                proximaFechaEl.textContent = dt.toLocaleDateString('es-CR', {
                    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
                });
                setEstado('active', 'Esperando alarma...');
            } else {
                proximaFecha = null;
                proximaCount.textContent = '--:--:--';
                proximaWrap.style.display = 'none';
                setEstado('noconf', 'Sin alarmas programadas');
                noAlarmNote.style.display = 'block';
            }
        }
    } catch(error) {
        erroresConsecutivos++;
        conexionBadge.className = 'conexion-badge conexion-offline';
        conexionBadge.textContent = '⚠ Sin conexión';
        setEstado('error', 'Error de conexión');
        debugInfo.textContent = `❌ Error: ${error.message}`;
        console.error('Error en checkAlarma:', error);
    }
}

function dispararAlarma(mensaje, estacion) {
    alarmaActiva = true;
    successPanel.classList.remove('visible');
    noAlarmNote.style.display = 'none';
    proximaWrap.style.display = 'none';

    alarmMsg.textContent = mensaje || 'Realizar prueba de calidad';
    overlay.classList.add('visible');
    empCode.value = '';
    document.getElementById('error-txt').textContent = '';

    setEstado('alarm', '🔔 ALARMA ACTIVA');
    
    // Iniciar sonido potente
    sonarBeep();

    setTimeout(() => empCode.focus(), 100);
}

async function confirmarAlarma() {
    if (confirmando) return;
    
    const codigo = empCode.value.trim().toUpperCase();
    if (!codigo) {
        document.getElementById('error-txt').textContent = '⚠ Ingrese su código de empleado';
        empCode.focus();
        return;
    }
    
    if (!alarmaId) {
        document.getElementById('error-txt').textContent = 'Error: ID de alarma no encontrado';
        return;
    }

    confirmando = true;
    btnConfirm.disabled = true;
    btnConfirm.textContent = '⏳ Verificando...';
    document.getElementById('error-txt').textContent = '';

    try {
        const response = await fetch('/control_produccion/public/alarma_api.php', { 
            method: 'POST', 
            body: new URLSearchParams({
                'accion': 'confirmar',
                'codigo_empleado': codigo,
                'alarma_id': alarmaId
            })
        });
        
        const textResponse = await response.text();
        console.log('Respuesta cruda:', textResponse);
        
        const res = JSON.parse(textResponse);
        
        if (res.ok) {
            detenerSonido();
            overlay.classList.remove('visible');
            alarmaId = null;
            alarmaActiva = false;

            successPanel.classList.add('visible');
            document.getElementById('success-title').textContent = '✅ Revisión confirmada';
            document.getElementById('success-body').textContent = `${res.nombre} · Turno ${res.turno} · ${res.hora}`;
            setEstado('ok', `Confirmado por ${res.nombre}`);

            setTimeout(async () => {
                successPanel.classList.remove('visible');
                await checkAlarma(true);
            }, 5000);
        } else {
            document.getElementById('error-txt').textContent = '❌ ' + (res.msg || 'Código no válido');
            empCode.value = '';
            empCode.focus();
        }
    } catch(error) {
        console.error('Error en confirmar:', error);
        document.getElementById('error-txt').textContent = '⚠ Error de conexión: ' + error.message;
    } finally {
        confirmando = false;
        btnConfirm.disabled = false;
        btnConfirm.textContent = '✔ CONFIRMAR';
    }
}

document.addEventListener('keydown', e => {
    if (alarmaActiva && e.key === 'Enter') {
        e.preventDefault();
        confirmarAlarma();
    }
});

setInterval(actualizarReloj, 1000);
actualizarReloj();
checkAlarma();
checkIntervalId = setInterval(() => checkAlarma(), 5000);
</script>
</body>
</html>