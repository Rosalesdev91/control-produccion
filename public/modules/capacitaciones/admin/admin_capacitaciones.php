<?php
// ============================================================
// admin_capacitaciones.php — Panel de Administración TAO
// Requiere rol: administrador o supervisor
// Base de datos: produccion_quiebras
// ============================================================
declare(strict_types=1);
session_start();
if(empty($_SESSION['empleado'])||!in_array($_SESSION['rol']??'',['administrador','supervisor'])){
    header('Location: ../login_tao.php'); exit();
}
date_default_timezone_set('America/Costa_Rica');
$empleado = $_SESSION['empleado'];
$codigo   = $_SESSION['codigo_empleado']??'';
$rol      = $_SESSION['rol'];
$isAdmin  = $rol==='administrador';
$csrf_token = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
<title>Admin TAO — Control de Producción</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;900&family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{--tao:#0ea5e9;--tao2:#0369a1;--gold:#f59e0b;--surface:#f8fafc;--border:#e2e8f0;--text:#1e293b;--muted:#64748b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--surface);color:var(--text)}
.font-orb{font-family:'Orbitron',monospace}
.font-raj{font-family:'Rajdhani',sans-serif}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}

.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#0f172a,#1e3a5f);display:flex;flex-direction:column;z-index:200}
.main-wrap{margin-left:240px;min-height:100vh;display:flex;flex-direction:column}
@media(max-width:900px){.sidebar{display:none}.main-wrap{margin-left:0}}

.sb-logo{padding:20px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px}
.sb-badge{width:36px;height:36px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:'Orbitron',monospace;font-weight:900;font-size:12px;color:#1a1a1a}
.sb-nav{flex:1;overflow-y:auto;padding:10px 6px}
.sb-item{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:8px;cursor:pointer;transition:.2s;color:rgba(255,255,255,.6);font-size:.8rem;font-weight:500;border:none;background:none;width:100%;text-align:left}
.sb-item:hover{background:rgba(14,165,233,.15);color:#fff}
.sb-item.active{background:rgba(14,165,233,.2);color:#fff;border:1px solid rgba(14,165,233,.3)}
.sb-item i{width:18px;text-align:center;font-size:1rem}
.sb-section{font-size:.6rem;font-weight:700;letter-spacing:.1em;color:rgba(255,255,255,.25);padding:12px 12px 4px;text-transform:uppercase}
.sb-user{padding:14px;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.2)}

.topbar{background:#fff;border-bottom:1px solid var(--border);padding:0 24px;height:58px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:100}

.content{flex:1;padding:24px;max-width:1440px;width:100%}
.tab-content{display:none}
.tab-content.active{display:block}

.card{background:#fff;border:1px solid var(--border);border-radius:13px;box-shadow:0 1px 3px rgba(0,0,0,.04),0 4px 12px rgba(0,0,0,.03)}

.kpi{background:#fff;border:1px solid var(--border);border-radius:13px;padding:18px;position:relative;overflow:hidden}
.kpi::after{content:'';position:absolute;right:-12px;top:-12px;width:60px;height:60px;border-radius:50%;opacity:.08}
.kpi-val{font-size:1.9rem;font-weight:700;font-family:'Orbitron',monospace;line-height:1}
.kpi-lbl{font-size:.72rem;color:var(--muted);font-weight:500;margin-top:4px}

.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:.68rem;font-weight:700}
.b-curso{background:#dbeafe;color:#1e40af}.b-test{background:#fce7f3;color:#9d174d}
.b-cert{background:#d1fae5;color:#065f46}.b-taller{background:#ede9fe;color:#5b21b6}
.b-basico{background:#f0fdf4;color:#166534}.b-inter{background:#fef3c7;color:#92400e}.b-avanz{background:#fee2e2;color:#991b1b}
.b-Critico{background:#fee2e2;color:#991b1b}.b-Bajo{background:#fef3c7;color:#92400e}
.b-Medio{background:#fefce8;color:#713f12}.b-Alto{background:#dcfce7;color:#166534}.b-Excelente{background:#d1fae5;color:#065f46}
.b-admin{background:#fef3c7;color:#92400e}.b-supervisor{background:#ede9fe;color:#5b21b6}.b-empleado{background:#f0f9ff;color:#0369a1}
.b-abierto{background:#fef9c3;color:#713f12}.b-en_analisis{background:#dbeafe;color:#1e40af}
.b-en_solucion{background:#ede9fe;color:#5b21b6}.b-cerrado{background:#dcfce7;color:#166534}.b-verificado{background:#ccfbf1;color:#0f766e}

.pbar{background:#e2e8f0;border-radius:999px;height:5px;overflow:hidden}
.pbar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--tao),var(--tao2));transition:width .8s}

.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);z-index:500;opacity:0;pointer-events:none;transition:opacity .25s;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-bg.open{opacity:1;pointer-events:all}
.modal-box{background:#fff;border-radius:16px;max-width:600px;width:100%;max-height:92vh;overflow-y:auto;transform:scale(.94);transition:transform .25s;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-bg.open .modal-box{transform:scale(1)}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:11px;color:#fff;font-size:.85rem;font-weight:600;z-index:9999;transform:translateY(70px);opacity:0;transition:all .3s}
#toast.show{transform:translateY(0);opacity:1}
#toast.s{background:#059669}#toast.e{background:#dc2626}#toast.i{background:#0ea5e9}

.data-table{width:100%;border-collapse:collapse;font-size:.8rem}
.data-table th{padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);background:#f8fafc;border-bottom:1px solid var(--border)}
.data-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.data-table tr:hover td{background:#f8fafc}
.data-table tr:last-child td{border-bottom:none}

.ticket-card{border-left:4px solid;border-radius:0 12px 12px 0;background:#fff;border-top:1px solid var(--border);border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.tc-critica{border-left-color:#dc2626}.tc-alta{border-left-color:#f97316}.tc-media{border-left-color:#eab308}.tc-baja{border-left-color:#94a3b8}

/* Loading skeleton */
.skeleton{background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200% 100%;animation:loading 1.5s infinite}
@keyframes loading{0%{background-position:200% 0}100%{background-position:-200% 0}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sb-logo">
    <div class="sb-badge">ADM</div>
    <div>
      <div class="font-orb text-white text-xs font-bold">Admin TAO</div>
      <div class="text-xs" style="color:rgba(255,255,255,.4)"><?= ucfirst($rol) ?></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Dashboard</div>
    <button class="sb-item active" onclick="switchTab('dashboard',this)"><i class="bi bi-speedometer2"></i> Resumen General</button>
    <button class="sb-item" onclick="switchTab('empleados',this)"><i class="bi bi-people"></i> Empleados & TAO</button>
    <button class="sb-item" onclick="switchTab('ranking',this)"><i class="bi bi-trophy"></i> Ranking TAO</button>

    <div class="sb-section">Gestión</div>
    <button class="sb-item" onclick="switchTab('cursos',this)"><i class="bi bi-mortarboard"></i> Cursos</button>
    <button class="sb-item" onclick="switchTab('sedac',this)"><i class="bi bi-tools"></i> Tickets SEDAC</button>
    <button class="sb-item" onclick="switchTab('cue_admin',this)"><i class="bi bi-chat-dots"></i> Cue Cards</button>
  </nav>
  <div class="sb-user">
    <div class="flex items-center gap-2 mb-2">
      <div class="w-7 h-7 rounded-full bg-amber-400 flex items-center justify-center text-slate-900 font-bold text-xs flex-shrink-0"><?= strtoupper(substr($empleado,0,1)) ?></div>
      <div class="min-w-0">
        <div class="text-white text-xs font-semibold truncate" style="max-width:150px"><?= htmlspecialchars($empleado) ?></div>
        <div class="text-xs" style="color:rgba(255,255,255,.35)"><?= htmlspecialchars($codigo) ?></div>
      </div>
    </div>
    <a href="../capacitaciones.php" class="sb-item" style="font-size:.75rem;padding:7px 10px;color:rgba(100,200,255,.6)">
      <i class="bi bi-arrow-left"></i> Ir a Capacitaciones
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="main-wrap">

  <header class="topbar">
    <div class="font-orb text-amber-600 font-bold text-sm">ADMIN TAO</div>
    <span class="text-slate-300 text-xs hidden sm:block">|</span>
    <span class="text-slate-500 text-xs hidden sm:block">Panel de Administración · <?= date('d/m/Y H:i') ?></span>
    <span class="ml-auto badge b-<?= $rol ?>"> <?= ucfirst($rol) ?></span>
    <a href="../../../frontend.php" class="text-slate-400 hover:text-slate-700 text-sm ml-2" title="Dashboard Principal"><i class="bi bi-house-door"></i></a>
  </header>

  <main class="content mx-auto w-full">

    <!-- =========================================
      DASHBOARD
    ========================================= -->
    <div id="tab-dashboard" class="tab-content active">
      <h1 class="font-orb text-lg font-bold text-slate-800 mb-5">Resumen General TAO</h1>

      <!-- KPIs row -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6" id="adminKPIs">
        <div class="kpi"><div class="kpi-val text-sky-600" id="ak-emp">—</div><div class="kpi-lbl">Total empleados</div></div>
        <div class="kpi"><div class="kpi-val text-emerald-600" id="ak-cap">—</div><div class="kpi-lbl">Capacitados</div></div>
        <div class="kpi"><div class="kpi-val text-purple-600" id="ak-certs">—</div><div class="kpi-lbl">Certificados</div></div>
        <div class="kpi"><div class="kpi-val text-amber-500" id="ak-tao">—</div><div class="kpi-lbl">Promedio TAO</div></div>
        <div class="kpi"><div class="kpi-val text-sky-600" id="ak-cursos">—</div><div class="kpi-lbl">Cursos activos</div></div>
        <div class="kpi"><div class="kpi-val text-rose-500" id="ak-cue">—</div><div class="kpi-lbl">Cue hoy</div></div>
      </div>

      <!-- Charts row -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
        <div class="card p-5 lg:col-span-2">
          <h3 class="text-sm font-bold text-slate-600 mb-4">Evolución Índice TAO — 30 días</h3>
          <canvas id="chartEvol" height="120"></canvas>
        </div>
        <div class="card p-5">
          <h3 class="text-sm font-bold text-slate-600 mb-4">Distribución Niveles TAO</h3>
          <canvas id="chartNiveles" height="200"></canvas>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="card p-5">
          <h3 class="text-sm font-bold text-slate-600 mb-4">Top Áreas — Índice TAO Promedio</h3>
          <canvas id="chartAreas" height="180"></canvas>
        </div>
        <div class="card p-5">
          <h3 class="text-sm font-bold text-slate-600 mb-4">Tickets SEDAC por Estado</h3>
          <canvas id="chartSedac" height="180"></canvas>
          <div id="sedacResumen" class="mt-4 space-y-2 text-sm"></div>
        </div>
      </div>
    </div>

    <!-- =========================================
      EMPLEADOS
    ========================================= -->
    <div id="tab-empleados" class="tab-content">
      <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
        <h1 class="font-orb text-lg font-bold text-slate-800">Empleados &amp; Desempeño TAO</h1>
        <input type="text" id="buscarEmp" onkeyup="filtrarEmpleados()" placeholder="Buscar empleado..."
          class="border rounded-xl px-4 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none">
      </div>
      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Empleado</th><th>Código</th><th>Rol</th><th>Cursos</th><th>Certificados</th><th>Último TAO</th><th>Nivel TAO</th><th></th></tr></thead>
            <tbody id="tbodyEmpleados"><tr><td colspan="8" class="text-center py-8"><div class="skeleton h-4 w-32 mx-auto rounded"></div></td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- =========================================
      RANKING
    ========================================= -->
    <div id="tab-ranking" class="tab-content">
      <h1 class="font-orb text-lg font-bold text-slate-800 mb-5">🏆 Ranking TAO</h1>
      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>#</th><th>Empleado</th><th>Promedio TAO</th><th>Evaluaciones</th><th>Cursos</th><th>Certificados</th><th>Nivel</th></tr></thead>
            <tbody id="tbodyRanking"><tr><td colspan="7" class="text-center py-8"><div class="skeleton h-4 w-32 mx-auto rounded"></div></td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- =========================================
      CURSOS
    ========================================= -->
    <div id="tab-cursos" class="tab-content">
      <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
        <h1 class="font-orb text-lg font-bold text-slate-800">Gestión de Cursos</h1>
        <?php if($isAdmin): ?>
        <button onclick="abrirFormCurso()" class="bg-sky-600 hover:bg-sky-700 text-white text-sm font-bold px-4 py-2 rounded-xl transition flex items-center gap-2">
          <i class="bi bi-plus-lg"></i> Nuevo Curso
        </button>
        <?php endif; ?>
      </div>
      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Título</th><th>Tipo</th><th>Nivel</th><th>Área</th><th>Duración</th><th>Estado</th><?php if($isAdmin):?><th>Acciones</th><?php endif;?></tr></thead>
            <tbody id="tablaCursos"><tr><td colspan="7" class="text-center py-8"><div class="skeleton h-4 w-32 mx-auto rounded"></div></td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- =========================================
      SEDAC ADMIN
    ========================================= -->
    <div id="tab-sedac" class="tab-content">
      <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
        <h1 class="font-orb text-lg font-bold text-slate-800">Gestión SEDAC</h1>
        <div class="flex gap-2 flex-wrap">
          <select id="filtroSedacEstado" onchange="filtrarSEDAC()" class="border rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none">
            <option value="">Todos los estados</option>
            <option value="abierto">Abierto</option>
            <option value="en_analisis">En análisis</option>
            <option value="en_solucion">En solución</option>
            <option value="cerrado">Cerrado</option>
            <option value="verificado">Verificado</option>
          </select>
          <select id="filtroSedacPrioridad" onchange="filtrarSEDAC()" class="border rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none">
            <option value="">Todas las prioridades</option>
            <option value="critica">Crítica</option>
            <option value="alta">Alta</option>
            <option value="media">Media</option>
            <option value="baja">Baja</option>
          </select>
        </div>
      </div>
      <div id="listaSedacAdmin" class="space-y-3"></div>
    </div>

    <!-- =========================================
      CUE ADMIN
    ========================================= -->
    <div id="tab-cue_admin" class="tab-content">
      <h1 class="font-orb text-lg font-bold text-slate-800 mb-2">Feedback Cue Cards</h1>
      <p class="text-sm text-slate-500 mb-5">Gestión de preguntas del día y vista de participación</p>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <?php if($isAdmin): ?>
        <div class="card p-6">
          <h2 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="bi bi-plus-circle text-sky-500"></i> Nueva Pregunta Cue</h2>
          <div class="space-y-3">
            <div>
              <label class="text-xs font-semibold text-slate-600 block mb-1">Pregunta</label>
              <textarea id="cuePregunta" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none resize-none" placeholder="¿Cómo...?"></textarea>
            </div>
            <div>
              <label class="text-xs font-semibold text-slate-600 block mb-1">Tipo de respuesta</label>
              <select id="cueTipo" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none">
                <option value="escala_1_5">Escala 1-5</option>
                <option value="si_no">Sí / No</option>
              </select>
            </div>
            <button onclick="guardarPreguntaCue()" class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">Guardar Pregunta</button>
          </div>
        </div>
        <?php endif; ?>
        <div class="card p-5">
          <h2 class="font-semibold text-slate-700 mb-3 flex items-center gap-2"><i class="bi bi-bar-chart text-sky-500"></i> Participación hoy</h2>
          <div class="text-center py-6"><div class="text-5xl font-bold text-sky-600 font-orb" id="cueHoyCount">—</div><div class="text-sm text-slate-400 mt-1">empleados dieron feedback hoy</div></div>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- MODALES (igual que antes) -->
<div id="modalCurso" class="modal-bg" onclick="if(event.target===this)cerrarModal('modalCurso')"><div class="modal-box"><div class="p-5 border-b flex items-center justify-between"><h2 class="font-orb font-bold text-slate-800" id="formCursoTitle">Nuevo Curso</h2><button onclick="cerrarModal('modalCurso')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">×</button></div><div class="p-5 space-y-4"><input type="hidden" id="cursoId"><div><label class="text-xs font-semibold text-slate-600 block mb-1">Título *</label><input type="text" id="cursoTit" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none"></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Descripción</label><textarea id="cursoDesc" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-400 outline-none resize-none"></textarea></div><div class="grid grid-cols-2 gap-3"><div><label class="text-xs font-semibold text-slate-600 block mb-1">Tipo</label><select id="cursoTipo" class="w-full border rounded-lg px-3 py-2 text-sm"><option value="curso">Curso</option><option value="test">Test</option><option value="certificacion">Certificación</option><option value="taller">Taller</option></select></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Nivel</label><select id="cursoNivel" class="w-full border rounded-lg px-3 py-2 text-sm"><option value="basico">Básico</option><option value="intermedio">Intermedio</option><option value="avanzado">Avanzado</option></select></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Duración (min)</label><input type="number" id="cursoDur" value="60" min="1" class="w-full border rounded-lg px-3 py-2 text-sm"></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Área</label><input type="text" id="cursoArea" value="General" class="w-full border rounded-lg px-3 py-2 text-sm"></div></div><div class="flex gap-3 pt-1"><button onclick="guardarCurso()" class="flex-1 bg-sky-600 hover:bg-sky-700 text-white font-bold py-2.5 rounded-xl transition text-sm">Guardar</button><button onclick="cerrarModal('modalCurso')" class="px-4 border rounded-xl text-slate-600 hover:bg-slate-50 transition text-sm">Cancelar</button></div></div></div></div>

<div id="modalSedac" class="modal-bg" onclick="if(event.target===this)cerrarModal('modalSedac')"><div class="modal-box"><div class="p-5 border-b flex items-center justify-between"><h2 class="font-orb font-bold text-slate-800">Actualizar Ticket SEDAC</h2><button onclick="cerrarModal('modalSedac')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">×</button></div><div class="p-5 space-y-4"><input type="hidden" id="sedacId"><div><label class="text-xs font-semibold text-slate-600 block mb-1">Estado</label><select id="sedacEstado" class="w-full border rounded-lg px-3 py-2 text-sm"><option value="abierto">Abierto</option><option value="en_analisis">En Análisis</option><option value="en_solucion">En Solución</option><option value="cerrado">Cerrado</option><option value="verificado">Verificado</option></select></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Asignado a</label><input type="text" id="sedacAsignado" class="w-full border rounded-lg px-3 py-2 text-sm"></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Causa Raíz</label><textarea id="sedacCausa" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm resize-none"></textarea></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Solución Implementada</label><textarea id="sedacSol" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm resize-none"></textarea></div><div><label class="text-xs font-semibold text-slate-600 block mb-1">Comentarios</label><textarea id="sedacCom" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm resize-none"></textarea></div><div class="flex gap-3"><button onclick="actualizarSedac()" class="flex-1 bg-sky-600 hover:bg-sky-700 text-white font-bold py-2.5 rounded-xl transition text-sm">Actualizar</button><button onclick="cerrarModal('modalSedac')" class="px-4 border rounded-xl text-slate-600 hover:bg-slate-50 transition text-sm">Cancelar</button></div></div></div></div>

<div id="modalHist" class="modal-bg" onclick="if(event.target===this)cerrarModal('modalHist')"><div class="modal-box"><div class="p-5 border-b flex items-center justify-between"><h2 class="font-orb font-bold text-slate-800" id="histModalTitle">Historial</h2><button onclick="cerrarModal('modalHist')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">×</button></div><div class="p-5"><div id="histModalBody" class="space-y-3 text-sm"></div></div></div></div>

<div id="toast"></div>

<script>
const API = '../backend_capacitaciones.php';
let todosSedac=[], todosEmpleados=[];
let activeCharts = {};

document.addEventListener('DOMContentLoaded', () => {
  cargarDashboard();
  cargarEmpleados();
  cargarRanking();
  cargarCursosAdmin();
  cargarSedacAdmin();
});

async function api(params) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(params)) fd.append(k, v);
  
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  if (csrfToken) fd.append('csrf_token', csrfToken);
  
  try {
    const r = await fetch(API, { method: 'POST', body: fd });
    const text = await r.text(); // Leer como texto primero
    
    // Intentar parsear JSON
    try {
      const data = JSON.parse(text);
      return data;
    } catch (e) {
      // Si no es JSON, mostrar el error HTML
      console.error('Respuesta no es JSON:', text.substring(0, 500));
      toast('Error del servidor. Revisa la consola.', 'e');
      return { success: false, error: 'Respuesta inválida del servidor' };
    }
  } catch (error) {
    console.error('API Error:', error);
    toast('Error de conexión con el servidor', 'e');
    return { success: false, error: error.message };
  }
}

function toast(msg, t = 'i') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = `show ${t}`;
  setTimeout(() => el.className = '', 3500);
}

function abrirModal(id) { document.getElementById(id).classList.add('open'); }
function cerrarModal(id) { document.getElementById(id).classList.remove('open'); }

function switchTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelectorAll('.sb-item').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/[&<>]/g, function(m) {
    if (m === '&') return '&amp;';
    if (m === '<') return '&lt;';
    if (m === '>') return '&gt;';
    return m;
  });
}

// ── DASHBOARD ────────────────────────────────────────
async function cargarDashboard() {
  const d = await api({ action: 'stats_admin' });
  if (!d.success) {
    console.error('Error cargando dashboard:', d.error);
    return;
  }

  document.getElementById('ak-emp').textContent = d.empleados_total || 0;
  document.getElementById('ak-cap').textContent = d.empleados_capacitados || 0;
  document.getElementById('ak-certs').textContent = d.certificados || 0;
  document.getElementById('ak-tao').textContent = (d.tao_promedio || 0) + '%';
  document.getElementById('ak-cursos').textContent = d.cursos_activos || 0;
  document.getElementById('ak-cue').textContent = d.cue_hoy || 0;
  document.getElementById('cueHoyCount').textContent = d.cue_hoy || 0;

  // Destruir gráficos anteriores
  Object.values(activeCharts).forEach(chart => { try { chart.destroy(); } catch(e) {} });
  activeCharts = {};

  // Evolución
  if (d.evolucion_tao?.length) {
    activeCharts.evol = new Chart(document.getElementById('chartEvol'), {
      type: 'line',
      data: {
        labels: d.evolucion_tao.map(r => r.fecha),
        datasets: [{
          label: 'TAO %', data: d.evolucion_tao.map(r => r.promedio || r.prom),
          borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,.08)',
          fill: true, tension: .35, pointRadius: 3, pointBackgroundColor: '#0369a1', borderWidth: 2
        }]
      },
      options: { responsive: true, maintainAspectRatio: true, scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 } } }, plugins: { legend: { display: false } } }
    });
  }

  // Niveles
  if (d.distribucion_niveles?.length) {
    const colors = { Critico: '#ef4444', Bajo: '#f59e0b', Medio: '#eab308', Alto: '#22c55e', Excelente: '#10b981' };
    activeCharts.niveles = new Chart(document.getElementById('chartNiveles'), {
      type: 'doughnut',
      data: {
        labels: d.distribucion_niveles.map(n => n.nivel_alineacion),
        datasets: [{ data: d.distribucion_niveles.map(n => n.total || n.cantidad || 0), backgroundColor: d.distribucion_niveles.map(n => colors[n.nivel_alineacion] || '#94a3b8'), borderWidth: 1 }]
      },
      options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'right', labels: { font: { size: 11 }, boxWidth: 12 } } }, cutout: '60%' }
    });
  }

  // Top áreas
  if (d.top_areas?.length) {
    activeCharts.areas = new Chart(document.getElementById('chartAreas'), {
      type: 'bar',
      data: {
        labels: d.top_areas.map(a => a.area),
        datasets: [{ label: 'Índice TAO', data: d.top_areas.map(a => a.promedio || a.prom || 0), backgroundColor: ['#0ea5e9', '#38bdf8', '#7dd3fc', '#bae6fd', '#e0f2fe', '#f0f9ff'], borderRadius: 6 }]
      },
      options: { indexAxis: 'y', responsive: true, maintainAspectRatio: true, scales: { x: { min: 0, max: 100 } }, plugins: { legend: { display: false } } }
    });
  }

  // SEDAC
  if (d.sedac_estados?.length) {
    const sc = { abierto: '#fbbf24', en_analisis: '#60a5fa', en_solucion: '#a78bfa', cerrado: '#34d399', verificado: '#2dd4bf' };
    activeCharts.sedac = new Chart(document.getElementById('chartSedac'), {
      type: 'doughnut',
      data: {
        labels: d.sedac_estados.map(s => s.estado),
        datasets: [{ data: d.sedac_estados.map(s => s.total || s.cantidad || 0), backgroundColor: d.sedac_estados.map(s => sc[s.estado] || '#94a3b8'), borderWidth: 1 }]
      },
      options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'right', labels: { font: { size: 10 }, boxWidth: 10 } } }, cutout: '55%' }
    });
    document.getElementById('sedacResumen').innerHTML = d.sedac_estados.map(s => `<div class="flex justify-between text-slate-500"><span class="capitalize">${s.estado}</span><span class="font-bold text-slate-700">${s.total || s.cantidad || 0}</span></div>`).join('');
  }
}

// ── EMPLEADOS ────────────────────────────────────────
async function cargarEmpleados() {
  const d = await api({ action: 'lista_empleados_admin' });
  if (!d.success) { document.getElementById('tbodyEmpleados').innerHTML = `<tr><td colspan="8" class="text-center text-red-500 py-8">Error: ${d.error || 'Sin datos'}</td></tr>`; return; }
  todosEmpleados = d.data || [];
  renderEmpleados(todosEmpleados);
}

function renderEmpleados(lista) {
  const tbody = document.getElementById('tbodyEmpleados');
  if (!lista.length) { tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-400 py-8">Sin datos</td></tr>'; return; }
  const rolBadge = { administrador: 'b-admin', supervisor: 'b-supervisor', empleado: 'b-empleado' };
  tbody.innerHTML = lista.map(e => `<tr><td class="font-semibold text-slate-800">${escapeHtml(e.nombre_empleado)}</td><td class="text-slate-400 font-mono text-xs">${e.codigo_empleado}</td><td><span class="badge ${rolBadge[e.rol] || 'b-empleado'}">${e.rol}</span></td><td><span class="font-bold text-sky-600">${e.cursos_completados || 0}</span></td><td><span class="font-bold text-emerald-600">${e.certificados || 0}</span></td><td>${e.ultimo_tao != null ? `<span class="font-bold font-orb text-amber-600">${e.ultimo_tao}%</span>` : '<span class="text-slate-300">—</span>'}</td><td>${e.nivel_tao ? `<span class="badge b-${e.nivel_tao}">${e.nivel_tao}</span>` : '<span class="text-slate-300 text-xs">Sin evaluar</span>'}</td><td><button onclick="verHistorial(\'${escapeHtml(e.nombre_empleado)}\')" class="text-xs text-sky-600 hover:text-sky-800 font-semibold">Ver</button></td></tr>`).join('');
}

function filtrarEmpleados() {
  const q = document.getElementById('buscarEmp').value.toLowerCase();
  renderEmpleados(todosEmpleados.filter(e => e.nombre_empleado.toLowerCase().includes(q) || e.codigo_empleado.toLowerCase().includes(q)));
}

async function verHistorial(nombre) {
  const d = await api({ action: 'historial_tests_admin', empleado: nombre });
  document.getElementById('histModalTitle').textContent = `Historial — ${nombre}`;
  if (!d.success || !d.data?.length) {
    document.getElementById('histModalBody').innerHTML = '<p class="text-slate-400 text-sm text-center">Sin historial de cursos.</p>';
  } else {
    document.getElementById('histModalBody').innerHTML = d.data.map(p => `<div class="flex items-center justify-between p-3 rounded-xl border"><div><div class="font-semibold text-slate-700 text-xs">${escapeHtml(p.titulo)}</div><div class="text-xs text-slate-400 mt-0.5 capitalize">${p.estado} ${p.puntaje > 0 ? `· ${p.puntaje}%` : ''}</div></div><span class="text-xs text-slate-400">${(p.fecha_completado || p.fecha_inicio || '').split(' ')[0]}</span></div>`).join('');
  }
  abrirModal('modalHist');
}

// ── RANKING ──────────────────────────────────────────
async function cargarRanking() {
  const d = await api({ action: 'ranking_empleados' });
  if (!d.success) { document.getElementById('tbodyRanking').innerHTML = `<tr><td colspan="7" class="text-center text-red-500 py-8">Error: ${d.error || 'Sin datos'}</td></tr>`; return; }
  const medals = ['🥇', '🥈', '🥉'];
  document.getElementById('tbodyRanking').innerHTML = (d.data || []).map((r, i) => `<tr><td class="font-bold text-lg">${medals[i] || '#' + (i + 1)}</td><td class="font-semibold text-slate-800">${escapeHtml(r.empleado)}</td><td><div class="flex items-center gap-3"><div class="flex-1 pbar max-w-28"><div class="pbar-fill" style="width:${r.prom_tao}%"></div></div><span class="font-bold text-sky-600 font-orb text-sm">${r.prom_tao}%</span></div></td><td class="text-slate-500">${r.evaluaciones}</td><td class="text-slate-500">${r.cursos_completados || 0}</td><td class="text-emerald-600 font-semibold">${r.certificados_count || 0}</td><td>${r.ultimo_nivel ? `<span class="badge b-${r.ultimo_nivel}">${r.ultimo_nivel}</span>` : '—'}</td></tr>`).join('');
}

// ── CURSOS ADMIN ─────────────────────────────────────
async function cargarCursosAdmin() {
  const d = await api({ action: 'listar_cursos' });
  if (!d.success) { document.getElementById('tablaCursos').innerHTML = `<tr><td colspan="7" class="text-center text-red-500 py-8">Error: ${d.error || 'Sin datos'}</td></tr>`; return; }
  const tipoBadge = { curso: 'b-curso', test: 'b-test', certificacion: 'b-cert', taller: 'b-taller' };
  const nivelBadge = { basico: 'b-basico', intermedio: 'b-inter', avanzado: 'b-avanz' };
  const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
  document.getElementById('tablaCursos').innerHTML = (d.data || []).map(c => `<tr><td class="font-semibold text-slate-800 max-w-xs"><div class="truncate" title="${escapeHtml(c.titulo)}">${escapeHtml(c.titulo)}</div></td><td><span class="badge ${tipoBadge[c.tipo] || ''}">${c.tipo}</span></td><td><span class="badge ${nivelBadge[c.nivel] || ''}">${c.nivel}</span></td><td class="text-slate-500 text-xs">${escapeHtml(c.area)}</td><td class="text-slate-500 text-xs">${c.duracion_min} min</td><td><span class="badge ${c.activo ? 'b-cert' : 'b-Critico'}">${c.activo ? 'Activo' : 'Inactivo'}</span></td>${isAdmin ? `<td><button onclick='editarCurso(${JSON.stringify(c).replace(/'/g, "&#39;")})' class="text-xs text-sky-600 hover:text-sky-800 font-semibold mr-2">Editar</button><button onclick="toggleCurso(${c.id})" class="text-xs ${c.activo ? 'text-rose-500 hover:text-rose-700' : 'text-emerald-600 hover:text-emerald-800'} font-semibold">${c.activo ? 'Desactivar' : 'Activar'}</button></td>` : ''}</tr>`).join('');
}

function abrirFormCurso() {
  document.getElementById('cursoId').value = '';
  document.getElementById('cursoTit').value = '';
  document.getElementById('cursoDesc').value = '';
  document.getElementById('cursoTipo').value = 'curso';
  document.getElementById('cursoNivel').value = 'basico';
  document.getElementById('cursoDur').value = '60';
  document.getElementById('cursoArea').value = 'General';
  document.getElementById('formCursoTitle').textContent = 'Nuevo Curso';
  abrirModal('modalCurso');
}

function editarCurso(c) {
  document.getElementById('cursoId').value = c.id;
  document.getElementById('cursoTit').value = c.titulo;
  document.getElementById('cursoDesc').value = c.descripcion || '';
  document.getElementById('cursoTipo').value = c.tipo;
  document.getElementById('cursoNivel').value = c.nivel;
  document.getElementById('cursoDur').value = c.duracion_min;
  document.getElementById('cursoArea').value = c.area;
  document.getElementById('formCursoTitle').textContent = 'Editar Curso';
  abrirModal('modalCurso');
}

async function guardarCurso() {
  const id = document.getElementById('cursoId').value;
  const titulo = document.getElementById('cursoTit').value.trim();
  if (!titulo) return toast('El título es obligatorio', 'i');
  const d = await api({
    action: 'guardar_curso', id, titulo,
    descripcion: document.getElementById('cursoDesc').value,
    tipo: document.getElementById('cursoTipo').value,
    nivel: document.getElementById('cursoNivel').value,
    duracion_min: document.getElementById('cursoDur').value,
    area: document.getElementById('cursoArea').value
  });
  if (d.success) { toast('Curso guardado', 's'); cerrarModal('modalCurso'); cargarCursosAdmin(); }
  else toast(d.error || 'Error', 'e');
}

async function toggleCurso(id) {
  const d = await api({ action: 'toggle_curso', id });
  if (d.success) { toast('Estado actualizado', 's'); cargarCursosAdmin(); }
  else toast(d.error || 'Error', 'e');
}

// ── SEDAC ADMIN ──────────────────────────────────────
async function cargarSedacAdmin() {
  const d = await api({ action: 'listar_sedac' });
  if (!d.success) { document.getElementById('listaSedacAdmin').innerHTML = `<div class="card p-8 text-center text-red-500 text-sm">Error: ${d.error || 'Sin datos'}</div>`; return; }
  todosSedac = d.data || [];
  renderSedac(todosSedac);
}

function filtrarSEDAC() {
  const est = document.getElementById('filtroSedacEstado').value;
  const pri = document.getElementById('filtroSedacPrioridad').value;
  renderSedac(todosSedac.filter(t => (!est || t.estado === est) && (!pri || t.prioridad === pri)));
}

function renderSedac(lista) {
  const el = document.getElementById('listaSedacAdmin');
  if (!lista.length) { el.innerHTML = '<div class="card p-8 text-center text-slate-400 text-sm">No hay tickets.</div>'; return; }
  const priColors = { critica: 'text-red-600', alta: 'text-orange-500', media: 'text-amber-500', baja: 'text-slate-400' };
  el.innerHTML = lista.map(t => `<div class="ticket-card tc-${t.prioridad} p-5"><div class="flex items-start justify-between gap-4"><div class="flex-1 min-w-0"><div class="flex flex-wrap items-center gap-2 mb-1"><span class="font-bold text-slate-800 text-sm">${escapeHtml(t.titulo || 'Sin título')}</span><span class="badge b-${t.estado}">${t.estado}</span><span class="text-xs font-bold ${priColors[t.prioridad] || ''} capitalize">● ${t.prioridad}</span></div><p class="text-xs text-slate-500 mb-2 line-clamp-2">${escapeHtml(t.problema)}</p><div class="flex flex-wrap gap-4 text-xs text-slate-400"><span><i class="bi bi-person me-1"></i>${escapeHtml(t.creado_por)}</span>${t.area ? `<span><i class="bi bi-building me-1"></i>${escapeHtml(t.area)}</span>` : ''}<span><i class="bi bi-calendar me-1"></i>${t.fecha_creacion?.split(' ')[0] || ''}</span>${t.asignado_a ? `<span class="text-sky-600"><i class="bi bi-person-check me-1"></i>${escapeHtml(t.asignado_a)}</span>` : ''}</div></div><button onclick='abrirSedacModal(${t.id},"${t.estado}","${escapeHtml(t.asignado_a || '')}")' class="flex-shrink-0 bg-sky-100 text-sky-700 hover:bg-sky-200 text-xs font-bold px-3 py-2 rounded-lg transition"><i class="bi bi-pencil me-1"></i>Gestionar</button></div></div>`).join('');
}

function abrirSedacModal(id, estado, asignado) {
  document.getElementById('sedacId').value = id;
  document.getElementById('sedacEstado').value = estado;
  document.getElementById('sedacAsignado').value = asignado;
  document.getElementById('sedacCausa').value = '';
  document.getElementById('sedacSol').value = '';
  document.getElementById('sedacCom').value = '';
  abrirModal('modalSedac');
}

async function actualizarSedac() {
  const d = await api({
    action: 'actualizar_sedac',
    id: document.getElementById('sedacId').value,
    estado: document.getElementById('sedacEstado').value,
    asignado_a: document.getElementById('sedacAsignado').value,
    causa_raiz: document.getElementById('sedacCausa').value,
    solucion: document.getElementById('sedacSol').value,
    comentarios: document.getElementById('sedacCom').value,
  });
  if (d.success) { toast('Ticket actualizado', 's'); cerrarModal('modalSedac'); cargarSedacAdmin(); cargarDashboard(); }
  else toast(d.error || 'Error', 'e');
}

// ── CUE ADMIN ────────────────────────────────────────
async function guardarPreguntaCue() {
  const preg = document.getElementById('cuePregunta').value.trim();
  const tipo = document.getElementById('cueTipo').value;
  if (!preg) return toast('Escribe la pregunta', 'i');
  const sql = `INSERT INTO cue_preguntas (pregunta, tipo, activa) VALUES ('${preg.replace(/'/g, "\\'")}', '${tipo}', 1);`;
  navigator.clipboard?.writeText(sql).then(() => toast('SQL copiado al portapapeles. Ejecútalo en tu BD.', 'i')).catch(() => toast(sql, 'i'));
}
</script>
</body>
</html>