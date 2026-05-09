<?php
// ============================================================
// admin_capacitaciones.php  — Panel de administración TAO
// Solo accesible para rol 'administrador'
// ============================================================
session_start();
if (!isset($_SESSION['empleado']) || ($_SESSION['rol'] ?? '') !== 'administrador') {
    header("Location: ../../login.php");
    exit();
}
date_default_timezone_set('America/Costa_Rica');
$empleado = $_SESSION['empleado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Capacitaciones TAO</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap');
  * { box-sizing: border-box; }
  body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
  .font-display, h1, h2, h3 { font-family: 'Space Grotesk', sans-serif; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 4px 12px rgba(0,0,0,.04); }
  .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: .72rem; font-weight: 600; }
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 0; pointer-events: none; transition: opacity .2s; }
  .modal-overlay.open { opacity: 1; pointer-events: all; }
  .modal-box { background: #fff; border-radius: 16px; max-width: 600px; width: 95%; max-height: 90vh; overflow-y: auto; padding: 2rem; transform: scale(.95); transition: transform .2s; }
  .modal-overlay.open .modal-box { transform: scale(1); }
  .tab-btn { padding: 8px 18px; border-radius: 8px; font-size: .875rem; font-weight: 500; cursor: pointer; transition: all .2s; border: none; background: transparent; color: #64748b; }
  .tab-btn.active { background: #0ea5e9; color: #fff; }
  .tab-btn:hover:not(.active) { background: #e0effe; color: #0ea5e9; }
  .tab-content { display: none; }
  .tab-content.active { display: block; }
  #toast { position: fixed; bottom: 24px; right: 24px; padding: 14px 22px; border-radius: 10px; color: #fff; font-size: .875rem; font-weight: 500; z-index: 9999; transform: translateY(80px); opacity: 0; transition: all .3s; }
  #toast.show { transform: translateY(0); opacity: 1; }
  #toast.success { background: #059669; }
  #toast.error   { background: #dc2626; }
  #toast.info    { background: #0ea5e9; }
  .nivel-Critico   { background: #fee2e2; color: #991b1b; }
  .nivel-Bajo      { background: #fef3c7; color: #92400e; }
  .nivel-Medio     { background: #fef9c3; color: #713f12; }
  .nivel-Alto      { background: #dcfce7; color: #166534; }
  .nivel-Excelente { background: #d1fae5; color: #065f46; }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="bg-slate-900 text-white px-6 py-3 flex items-center justify-between shadow-lg sticky top-0 z-50">
  <div class="flex items-center gap-3">
    <div class="w-8 h-8 bg-amber-400 rounded-lg flex items-center justify-center font-bold text-slate-900 text-sm">T</div>
    <span class="font-display font-bold">Admin TAO</span>
    <span class="bg-amber-400 text-slate-900 text-xs font-bold px-2 py-0.5 rounded">ADMIN</span>
  </div>
  <div class="flex items-center gap-4">
    <span class="text-slate-400 text-sm">👤 <?= htmlspecialchars($empleado) ?></span>
    <a href="../capacitaciones.php" class="text-slate-300 hover:text-white text-sm">← Capacitaciones</a>
    <a href="../../frontend.php" class="text-slate-300 hover:text-white text-sm">Dashboard</a>
  </div>
</nav>

<!-- HEADER -->
<div class="bg-gradient-to-r from-slate-900 to-slate-700 text-white px-6 pt-8 pb-12">
  <div class="max-w-7xl mx-auto">
    <h1 class="text-2xl font-display font-bold mb-1">Panel de Administración TAO</h1>
    <p class="text-slate-300 text-sm">Gestión de cursos, evaluaciones, tickets SEDAC y métricas de alineación organizacional</p>
    <!-- KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-6" id="adminKPIs">
      <div class="bg-white/10 rounded-xl p-4"><div class="text-2xl font-bold" id="ak-capacitados">—</div><div class="text-xs text-slate-300 mt-1">Empleados capacitados</div></div>
      <div class="bg-white/10 rounded-xl p-4"><div class="text-2xl font-bold" id="ak-certs">—</div><div class="text-xs text-slate-300 mt-1">Certificados emitidos</div></div>
      <div class="bg-white/10 rounded-xl p-4"><div class="text-2xl font-bold" id="ak-tao">—</div><div class="text-xs text-slate-300 mt-1">Promedio TAO (90d)</div></div>
      <div class="bg-white/10 rounded-xl p-4"><div class="text-2xl font-bold" id="ak-cursos">—</div><div class="text-xs text-slate-300 mt-1">Cursos activos</div></div>
      <div class="bg-white/10 rounded-xl p-4"><div class="text-2xl font-bold" id="ak-sedac">—</div><div class="text-xs text-slate-300 mt-1">Tickets SEDAC abiertos</div></div>
    </div>
  </div>
</div>

<!-- CONTENIDO -->
<div class="max-w-7xl mx-auto px-4 -mt-6 pb-12">

  <!-- TABS -->
  <div class="card p-4 mb-6 flex flex-wrap gap-2">
    <button class="tab-btn active" onclick="switchTab('dashboard')">📊 Dashboard</button>
    <button class="tab-btn" onclick="switchTab('cursos')">📚 Cursos</button>
    <button class="tab-btn" onclick="switchTab('ranking')">🏆 Ranking</button>
    <button class="tab-btn" onclick="switchTab('sedac_admin')">🔧 SEDAC</button>
    <button class="tab-btn" onclick="switchTab('preguntas')">❓ Preguntas TAO</button>
  </div>

  <!-- ======================== TAB DASHBOARD ======================== -->
  <div id="tab-dashboard" class="tab-content active">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="card p-6">
        <h3 class="font-display font-bold text-slate-800 mb-4">Evolución Índice TAO (30 días)</h3>
        <canvas id="taoEvolucionChart" height="200"></canvas>
      </div>
      <div class="card p-6">
        <h3 class="font-display font-bold text-slate-800 mb-4">Top Áreas por Índice TAO</h3>
        <canvas id="areasChart" height="200"></canvas>
      </div>
      <div class="card p-6">
        <h3 class="font-display font-bold text-slate-800 mb-4">Tickets SEDAC por Estado</h3>
        <canvas id="sedacChart" height="200"></canvas>
      </div>
      <div class="card p-6">
        <h3 class="font-display font-bold text-slate-800 mb-3">Resumen General</h3>
        <div class="space-y-3 text-sm" id="resumenGeneral">Cargando...</div>
      </div>
    </div>
  </div>

  <!-- ======================== TAB CURSOS ======================== -->
  <div id="tab-cursos" class="tab-content">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-display font-bold text-slate-800">Gestión de Cursos</h2>
      <button onclick="abrirFormCurso()"
        class="bg-sky-600 hover:bg-sky-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition flex items-center gap-2">
        + Nuevo Curso
      </button>
    </div>
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Título</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Tipo</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Nivel</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Duración</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Área</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Acciones</th>
            </tr>
          </thead>
          <tbody id="tablaCursos" class="divide-y">
            <tr><td colspan="6" class="text-center text-slate-400 py-8">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ======================== TAB RANKING ======================== -->
  <div id="tab-ranking" class="tab-content">
    <h2 class="text-lg font-display font-bold text-slate-800 mb-4">Ranking de Empleados — Índice TAO</h2>
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">#</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Empleado</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Promedio TAO</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Evaluaciones</th>
              <th class="text-left px-4 py-3 font-semibold text-slate-600">Mejor nivel</th>
            </tr>
          </thead>
          <tbody id="tablaRanking" class="divide-y">
            <tr><td colspan="5" class="text-center text-slate-400 py-8">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ======================== TAB SEDAC ADMIN ======================== -->
  <div id="tab-sedac_admin" class="tab-content">
    <h2 class="text-lg font-display font-bold text-slate-800 mb-4">Gestión de Tickets SEDAC</h2>
    <div id="sedacAdminList" class="space-y-4">Cargando...</div>
  </div>

  <!-- ======================== TAB PREGUNTAS ======================== -->
  <div id="tab-preguntas" class="tab-content">
    <div class="card p-6 max-w-2xl">
      <h2 class="text-lg font-display font-bold text-slate-800 mb-4">Agregar Pregunta a Evaluación TAO</h2>
      <div class="space-y-4">
        <div>
          <label class="text-sm font-medium text-slate-700">Categoría / Dimensión</label>
          <select id="pCat" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none">
            <option>Productividad</option><option>Calidad</option><option>Costo</option>
            <option>Entrega</option><option>Seguridad</option><option>Información</option>
            <option>Moral</option><option>Liderazgo</option><option>Comunicación</option>
            <option>Trabajo en Equipo</option>
          </select>
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700">Pregunta</label>
          <textarea id="pPregunta" rows="3" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none resize-none" placeholder="Escribe la pregunta..."></textarea>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="text-xs text-slate-500">Opción A (peor)</label><input type="text" id="pA" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-1 focus:ring-sky-500 outline-none"></div>
          <div><label class="text-xs text-slate-500">Opción B</label><input type="text" id="pB" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-1 focus:ring-sky-500 outline-none"></div>
          <div><label class="text-xs text-slate-500">Opción C</label><input type="text" id="pC" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-1 focus:ring-sky-500 outline-none"></div>
          <div><label class="text-xs text-slate-500">Opción D (mejor)</label><input type="text" id="pD" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm focus:ring-1 focus:ring-sky-500 outline-none"></div>
        </div>
        <p class="text-xs text-slate-400">💡 En la metodología TAO, la opción D siempre representa el nivel más maduro/alto. La respuesta correcta se establece automáticamente como D.</p>
        <button onclick="guardarPregunta()" class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">Guardar Pregunta</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL CURSO -->
<div id="modalCurso" class="modal-overlay" onclick="if(event.target===this)cerrarModalCurso()">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-xl font-display font-bold text-slate-800" id="formCursoTitulo">Nuevo Curso</h2>
      <button onclick="cerrarModalCurso()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">×</button>
    </div>
    <input type="hidden" id="cursoId">
    <div class="space-y-4">
      <div>
        <label class="text-sm font-medium text-slate-700">Título *</label>
        <input type="text" id="cursoTitulo" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none" placeholder="Título del curso">
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">Descripción</label>
        <textarea id="cursoDescripcion" rows="3" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none resize-none" placeholder="Descripción del curso..."></textarea>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="text-sm font-medium text-slate-700">Tipo</label>
          <select id="cursoTipo" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none">
            <option value="curso">Curso</option>
            <option value="test">Test</option>
            <option value="certificacion">Certificación</option>
            <option value="taller">Taller</option>
          </select>
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700">Nivel</label>
          <select id="cursoNivel" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none">
            <option value="basico">Básico</option>
            <option value="intermedio">Intermedio</option>
            <option value="avanzado">Avanzado</option>
          </select>
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700">Duración (min)</label>
          <input type="number" id="cursoDuracion" min="1" value="60" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none">
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700">Área</label>
          <input type="text" id="cursoArea" value="General" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none" placeholder="Ej: Producción">
        </div>
      </div>
      <div class="flex gap-3 pt-2">
        <button onclick="guardarCurso()" class="flex-1 bg-sky-600 hover:bg-sky-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">Guardar Curso</button>
        <button onclick="cerrarModalCurso()" class="px-4 border rounded-xl text-slate-600 hover:bg-slate-50 transition text-sm">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL ACTUALIZAR TICKET SEDAC -->
<div id="modalSedac" class="modal-overlay" onclick="if(event.target===this)cerrarModalSedac()">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-xl font-display font-bold text-slate-800">Actualizar Ticket SEDAC</h2>
      <button onclick="cerrarModalSedac()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">×</button>
    </div>
    <input type="hidden" id="sedacId">
    <div class="space-y-4">
      <div>
        <label class="text-sm font-medium text-slate-700">Estado</label>
        <select id="sedacEstado" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none">
          <option value="abierto">Abierto</option>
          <option value="en_analisis">En Análisis</option>
          <option value="en_solucion">En Solución</option>
          <option value="cerrado">Cerrado</option>
          <option value="verificado">Verificado</option>
        </select>
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">Causa Raíz Identificada</label>
        <textarea id="sedacCausa" rows="2" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none resize-none"></textarea>
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">Solución Implementada</label>
        <textarea id="sedacSolucion" rows="3" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none resize-none"></textarea>
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">Comentarios</label>
        <textarea id="sedacComentarios" rows="2" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-sky-500 outline-none resize-none"></textarea>
      </div>
      <div class="flex gap-3">
        <button onclick="actualizarTicket()" class="flex-1 bg-sky-600 hover:bg-sky-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">Actualizar</button>
        <button onclick="cerrarModalSedac()" class="px-4 border rounded-xl text-slate-600 hover:bg-slate-50 transition text-sm">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
const API = '../backend_capacitaciones.php';

document.addEventListener('DOMContentLoaded', () => {
  cargarDashboard();
  cargarCursosAdmin();
  cargarRanking();
  cargarSedacAdmin();
});

async function api(params) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(params)) fd.append(k, v);
  const res = await fetch(API, { method: 'POST', body: fd });
  return res.json();
}

function toast(msg, type='info') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = `show ${type}`;
  setTimeout(() => el.className='', 3500);
}

function switchTab(name) {
  const tabs = ['dashboard','cursos','ranking','sedac_admin','preguntas'];
  document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', tabs[i]===name));
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.getElementById(`tab-${name}`).classList.add('active');
}

// ---- DASHBOARD ----
async function cargarDashboard() {
  const data = await api({ action: 'stats_dashboard_capacitaciones' });
  if (!data.success) return;

  document.getElementById('ak-capacitados').textContent = data.empleados_capacitados;
  document.getElementById('ak-certs').textContent = data.certificados;
  document.getElementById('ak-tao').textContent = (data.tao_promedio || 0) + '%';
  document.getElementById('ak-cursos').textContent = data.cursos_activos;

  const abiertos = (data.sedac_estados.find(e => e.estado === 'abierto') || {}).total || 0;
  document.getElementById('ak-sedac').textContent = abiertos;

  // Evolución TAO
  if (data.evolucion_tao.length) {
    new Chart(document.getElementById('taoEvolucionChart'), {
      type: 'line',
      data: {
        labels: data.evolucion_tao.map(r => r.fecha),
        datasets: [{ label: 'Promedio TAO %', data: data.evolucion_tao.map(r => r.promedio),
          borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,.1)', fill: true,
          tension: .3, pointRadius: 4 }]
      },
      options: { scales: { y: { min: 0, max: 100 } }, plugins: { legend: { display: false } } }
    });
  }

  // Top áreas
  if (data.top_areas.length) {
    new Chart(document.getElementById('areasChart'), {
      type: 'bar',
      data: {
        labels: data.top_areas.map(r => r.area),
        datasets: [{ label: 'Índice TAO', data: data.top_areas.map(r => r.prom),
          backgroundColor: ['#0ea5e9','#38bdf8','#7dd3fc','#bae6fd','#e0f2fe'] }]
      },
      options: { scales: { y: { min: 0, max: 100 } }, plugins: { legend: { display: false } }, indexAxis: 'y' }
    });
  }

  // SEDAC estados
  if (data.sedac_estados.length) {
    new Chart(document.getElementById('sedacChart'), {
      type: 'doughnut',
      data: {
        labels: data.sedac_estados.map(s => s.estado),
        datasets: [{ data: data.sedac_estados.map(s => s.total),
          backgroundColor: ['#fbbf24','#60a5fa','#818cf8','#34d399','#10b981'] }]
      },
      options: { plugins: { legend: { position: 'right' } } }
    });
  }

  document.getElementById('resumenGeneral').innerHTML = `
    <div class="flex justify-between py-2 border-b"><span class="text-slate-500">Empleados capacitados</span><span class="font-semibold">${data.empleados_capacitados}</span></div>
    <div class="flex justify-between py-2 border-b"><span class="text-slate-500">Certificados emitidos</span><span class="font-semibold">${data.certificados}</span></div>
    <div class="flex justify-between py-2 border-b"><span class="text-slate-500">Promedio TAO (90 días)</span><span class="font-semibold text-sky-600">${data.tao_promedio}%</span></div>
    <div class="flex justify-between py-2 border-b"><span class="text-slate-500">Mayor índice TAO</span><span class="font-semibold text-green-600">${data.tao_max}%</span></div>
    <div class="flex justify-between py-2"><span class="text-slate-500">Menor índice TAO</span><span class="font-semibold text-red-500">${data.tao_min}%</span></div>
  `;
}

// ---- CURSOS ADMIN ----
async function cargarCursosAdmin() {
  const data = await api({ action: 'listar_cursos' });
  const tbody = document.getElementById('tablaCursos');
  if (!data.success || !data.data.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-400 py-8">No hay cursos</td></tr>';
    return;
  }
  const tipoBadge = { curso:'bg-blue-100 text-blue-700', test:'bg-pink-100 text-pink-700',
    certificacion:'bg-green-100 text-green-700', taller:'bg-purple-100 text-purple-700' };
  const nivelBadge = { basico:'bg-green-50 text-green-700', intermedio:'bg-yellow-50 text-yellow-700', avanzado:'bg-red-50 text-red-700' };

  tbody.innerHTML = data.data.map(c => `
    <tr class="hover:bg-slate-50">
      <td class="px-4 py-3 font-medium text-slate-800">${c.titulo}</td>
      <td class="px-4 py-3"><span class="badge ${tipoBadge[c.tipo]||''}">${c.tipo}</span></td>
      <td class="px-4 py-3"><span class="badge ${nivelBadge[c.nivel]||''}">${c.nivel}</span></td>
      <td class="px-4 py-3 text-slate-500">${c.duracion_min} min</td>
      <td class="px-4 py-3 text-slate-500">${c.area}</td>
      <td class="px-4 py-3">
        <button onclick="editarCurso(${c.id},'${c.titulo.replace(/'/g,"\\'")}','${(c.descripcion||'').replace(/'/g,"\\'")}','${c.tipo}','${c.nivel}',${c.duracion_min},'${c.area}')"
          class="text-sky-600 hover:text-sky-800 text-sm font-medium mr-3">Editar</button>
        <button onclick="eliminarCurso(${c.id})"
          class="text-red-500 hover:text-red-700 text-sm font-medium">Eliminar</button>
      </td>
    </tr>
  `).join('');
}

function abrirFormCurso() {
  document.getElementById('cursoId').value = '';
  document.getElementById('cursoTitulo').value = '';
  document.getElementById('cursoDescripcion').value = '';
  document.getElementById('cursoTipo').value = 'curso';
  document.getElementById('cursoNivel').value = 'basico';
  document.getElementById('cursoDuracion').value = 60;
  document.getElementById('cursoArea').value = 'General';
  document.getElementById('formCursoTitulo').textContent = 'Nuevo Curso';
  document.getElementById('modalCurso').classList.add('open');
}

function editarCurso(id, titulo, desc, tipo, nivel, dur, area) {
  document.getElementById('cursoId').value = id;
  document.getElementById('cursoTitulo').value = titulo;
  document.getElementById('cursoDescripcion').value = desc;
  document.getElementById('cursoTipo').value = tipo;
  document.getElementById('cursoNivel').value = nivel;
  document.getElementById('cursoDuracion').value = dur;
  document.getElementById('cursoArea').value = area;
  document.getElementById('formCursoTitulo').textContent = 'Editar Curso';
  document.getElementById('modalCurso').classList.add('open');
}

function cerrarModalCurso() { document.getElementById('modalCurso').classList.remove('open'); }

async function guardarCurso() {
  const id    = document.getElementById('cursoId').value;
  const titulo = document.getElementById('cursoTitulo').value.trim();
  if (!titulo) return toast('El título es obligatorio','info');

  const data = await api({
    action: 'guardar_curso', id, titulo,
    descripcion: document.getElementById('cursoDescripcion').value,
    tipo:        document.getElementById('cursoTipo').value,
    nivel:       document.getElementById('cursoNivel').value,
    duracion_min: document.getElementById('cursoDuracion').value,
    area:        document.getElementById('cursoArea').value,
  });

  if (data.success) { toast('Curso guardado','success'); cerrarModalCurso(); cargarCursosAdmin(); }
  else toast(data.error,'error');
}

async function eliminarCurso(id) {
  if (!confirm('¿Desactivar este curso?')) return;
  const data = await api({ action: 'eliminar_curso', id });
  if (data.success) { toast('Curso desactivado','success'); cargarCursosAdmin(); }
  else toast(data.error,'error');
}

// ---- RANKING ----
async function cargarRanking() {
  const data = await api({ action: 'ranking_empleados' });
  const tbody = document.getElementById('tablaRanking');
  if (!data.success || !data.data.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-400 py-8">No hay datos de evaluaciones TAO aún</td></tr>';
    return;
  }
  const medals = ['🥇','🥈','🥉'];
  tbody.innerHTML = data.data.map((r,i) => `
    <tr class="hover:bg-slate-50">
      <td class="px-4 py-3 font-bold text-slate-500">${medals[i] || (i+1)}</td>
      <td class="px-4 py-3 font-medium text-slate-800">${r.empleado}</td>
      <td class="px-4 py-3">
        <div class="flex items-center gap-3">
          <div class="flex-1 bg-slate-100 rounded-full h-2 max-w-32">
            <div class="bg-sky-500 h-2 rounded-full" style="width:${r.prom_tao}%"></div>
          </div>
          <span class="font-semibold text-sky-600">${r.prom_tao}%</span>
        </div>
      </td>
      <td class="px-4 py-3 text-slate-500">${r.evaluaciones}</td>
      <td class="px-4 py-3"><span class="badge nivel-${r.mejor_nivel}">${r.mejor_nivel}</span></td>
    </tr>
  `).join('');
}

// ---- SEDAC ADMIN ----
async function cargarSedacAdmin() {
  const data = await api({ action: 'listar_tickets_sedac' });
  const el = document.getElementById('sedacAdminList');
  if (!data.success || !data.data.length) {
    el.innerHTML = '<div class="card p-8 text-center text-slate-400">No hay tickets SEDAC</div>';
    return;
  }
  const colores = {
    abierto:'bg-yellow-100 text-yellow-800', en_analisis:'bg-blue-100 text-blue-800',
    en_solucion:'bg-purple-100 text-purple-800', cerrado:'bg-green-100 text-green-800', verificado:'bg-teal-100 text-teal-800'
  };
  const priorColors = { baja:'text-slate-400', media:'text-yellow-500', alta:'text-orange-500', critica:'text-red-600' };

  el.innerHTML = data.data.map(t => `
    <div class="card p-5">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1">
          <div class="flex items-center gap-3 flex-wrap mb-2">
            <span class="font-display font-bold text-slate-800">${t.titulo}</span>
            <span class="badge ${colores[t.estado]||'bg-slate-100 text-slate-600'}">${t.estado}</span>
            <span class="text-sm ${priorColors[t.prioridad]||''} font-semibold capitalize">● ${t.prioridad}</span>
          </div>
          <p class="text-sm text-slate-600 mb-2">${t.problema}</p>
          <div class="text-xs text-slate-400 flex gap-4 flex-wrap">
            <span>📁 ${t.area || '—'}</span>
            <span>👤 ${t.creado_por}</span>
            <span>📅 ${t.fecha_creacion.split(' ')[0]}</span>
            ${t.solucion ? `<span class="text-green-600">✅ Solución registrada</span>` : ''}
          </div>
        </div>
        <button onclick="abrirModalSedac(${t.id},'${t.estado}')"
          class="shrink-0 bg-sky-100 text-sky-700 hover:bg-sky-200 text-xs font-semibold px-3 py-2 rounded-lg transition">
          Actualizar
        </button>
      </div>
    </div>
  `).join('');
}

let sedacIdActual = null;
function abrirModalSedac(id, estado) {
  sedacIdActual = id;
  document.getElementById('sedacId').value = id;
  document.getElementById('sedacEstado').value = estado;
  document.getElementById('sedacCausa').value = '';
  document.getElementById('sedacSolucion').value = '';
  document.getElementById('sedacComentarios').value = '';
  document.getElementById('modalSedac').classList.add('open');
}

function cerrarModalSedac() { document.getElementById('modalSedac').classList.remove('open'); }

async function actualizarTicket() {
  const data = await api({
    action: 'actualizar_ticket_sedac',
    id:          document.getElementById('sedacId').value,
    estado:      document.getElementById('sedacEstado').value,
    causa_raiz:  document.getElementById('sedacCausa').value,
    solucion:    document.getElementById('sedacSolucion').value,
    comentarios: document.getElementById('sedacComentarios').value,
  });
  if (data.success) { toast('Ticket actualizado','success'); cerrarModalSedac(); cargarSedacAdmin(); cargarDashboard(); }
  else toast(data.error||'Error','error');
}

// ---- PREGUNTAS TAO ----
async function guardarPregunta() {
  const pregunta = document.getElementById('pPregunta').value.trim();
  const opA = document.getElementById('pA').value.trim();
  const opB = document.getElementById('pB').value.trim();
  const opC = document.getElementById('pC').value.trim();
  const opD = document.getElementById('pD').value.trim();

  if (!pregunta || !opA || !opB || !opC || !opD) return toast('Completa todos los campos','info');

  // Insert directo vía fetch al backend con action de insertar pregunta
  const fd = new FormData();
  fd.append('action', 'insertar_pregunta_tao');
  fd.append('categoria', document.getElementById('pCat').value);
  fd.append('pregunta', pregunta);
  fd.append('opcion_a', opA);
  fd.append('opcion_b', opB);
  fd.append('opcion_c', opC);
  fd.append('opcion_d', opD);

  // Nota: esta acción puede agregarse al backend si se desea.
  // Por ahora mostramos instrucción de SQL generada.
  const sql = `INSERT INTO preguntas_evaluacion (capacitacion_id, categoria, pregunta, opcion_a, opcion_b, opcion_c, opcion_d, respuesta_correcta, peso)
VALUES (2, '${document.getElementById('pCat').value}', '${pregunta.replace(/'/g,"\\'")}', '${opA.replace(/'/g,"\\'")}', '${opB.replace(/'/g,"\\'")}', '${opC.replace(/'/g,"\\'")}', '${opD.replace(/'/g,"\\'")}', 'd', 2);`;

  // En una integración completa esto iría al backend.
  // Mostrar el SQL para copiar:
  alert('SQL generado (copia en tu base de datos o conecta la acción al backend):\n\n' + sql);
  toast('Pregunta lista. Ejecuta el SQL en tu base de datos.','info');
}
</script>
</body>
</html>
