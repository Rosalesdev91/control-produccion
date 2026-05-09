<?php
// ============================================================
// capacitaciones.php  — Catálogo de cursos + Mi progreso
// Módulo de Capacitaciones TAO
// ============================================================
session_start();
if (!isset($_SESSION['empleado'])) {
    header("Location: ../login.php");
    exit();
}
date_default_timezone_set('America/Costa_Rica');
$empleado = $_SESSION['empleado'];
$rol      = $_SESSION['rol'] ?? 'operario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Capacitaciones TAO — Control de Producción</title>

<!-- Tailwind CSS (CDN) -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          tao: {
            50:  '#f0f7ff',
            100: '#e0effe',
            500: '#0ea5e9',
            600: '#0284c7',
            700: '#0369a1',
            900: '#0c4a6e',
          },
          gold: {
            400: '#fbbf24',
            500: '#f59e0b',
          }
        }
      }
    }
  }
</script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap');

  * { box-sizing: border-box; }
  body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
  h1, h2, h3, .font-display { font-family: 'Space Grotesk', sans-serif; }

  .card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 4px 12px rgba(0,0,0,.04);
    transition: transform .2s, box-shadow .2s;
  }
  .card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(0,0,0,.1); }

  .badge {
    display: inline-flex; align-items: center;
    padding: 2px 10px; border-radius: 999px;
    font-size: .72rem; font-weight: 600; letter-spacing: .02em;
  }

  .progress-bar-wrap {
    background: #e2e8f0; border-radius: 999px; height: 8px; overflow: hidden;
  }
  .progress-bar-fill {
    height: 100%; border-radius: 999px;
    background: linear-gradient(90deg, #0ea5e9, #0369a1);
    transition: width .6s ease;
  }

  /* Tabs */
  .tab-btn {
    padding: 8px 20px; border-radius: 8px;
    font-size: .875rem; font-weight: 500;
    cursor: pointer; transition: all .2s;
    border: none; background: transparent; color: #64748b;
  }
  .tab-btn.active { background: #0ea5e9; color: #fff; }
  .tab-btn:hover:not(.active) { background: #e0effe; color: #0ea5e9; }

  .tab-content { display: none; }
  .tab-content.active { display: block; }

  /* Modal */
  .modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5);
    display: flex; align-items: center; justify-content: center;
    z-index: 1000; opacity: 0; pointer-events: none; transition: opacity .2s;
  }
  .modal-overlay.open { opacity: 1; pointer-events: all; }
  .modal-box {
    background: #fff; border-radius: 16px;
    max-width: 680px; width: 95%;
    max-height: 90vh; overflow-y: auto;
    padding: 2rem;
    transform: scale(.95); transition: transform .2s;
  }
  .modal-overlay.open .modal-box { transform: scale(1); }

  /* Nivel TAO badge colors */
  .nivel-Critico   { background: #fee2e2; color: #991b1b; }
  .nivel-Bajo      { background: #fef3c7; color: #92400e; }
  .nivel-Medio     { background: #fef9c3; color: #713f12; }
  .nivel-Alto      { background: #dcfce7; color: #166534; }
  .nivel-Excelente { background: #d1fae5; color: #065f46; }

  .tipo-curso          { background: #dbeafe; color: #1e40af; }
  .tipo-test           { background: #fce7f3; color: #9d174d; }
  .tipo-certificacion  { background: #d1fae5; color: #065f46; }
  .tipo-taller         { background: #ede9fe; color: #5b21b6; }

  .nivel-basico      { background: #f0fdf4; color: #15803d; }
  .nivel-intermedio  { background: #fef3c7; color: #b45309; }
  .nivel-avanzado    { background: #fee2e2; color: #dc2626; }

  /* Radar chart container */
  #radarChart { max-width: 400px; max-height: 400px; }

  /* Toast */
  #toast {
    position: fixed; bottom: 24px; right: 24px;
    padding: 14px 22px; border-radius: 10px;
    color: #fff; font-size: .875rem; font-weight: 500;
    z-index: 9999; transform: translateY(80px); opacity: 0;
    transition: all .3s; max-width: 360px;
    box-shadow: 0 4px 20px rgba(0,0,0,.2);
  }
  #toast.show { transform: translateY(0); opacity: 1; }
  #toast.success { background: #059669; }
  #toast.error   { background: #dc2626; }
  #toast.info    { background: #0ea5e9; }
</style>
</head>
<body>

<!-- ====================================================
     NAVBAR (igual estilo que frontend.php existente)
==================================================== -->
<nav class="bg-tao-900 text-white px-6 py-3 flex items-center justify-between shadow-lg sticky top-0 z-50">
  <div class="flex items-center gap-3">
    <div class="w-8 h-8 bg-gold-500 rounded-lg flex items-center justify-center font-display font-bold text-tao-900 text-sm">T</div>
    <div>
      <span class="font-display font-bold text-lg leading-none">TAO</span>
      <span class="text-tao-100 text-sm ml-2">Capacitaciones</span>
    </div>
  </div>
  <div class="flex items-center gap-4">
    <span class="text-tao-100 text-sm hidden sm:block">👤 <?= htmlspecialchars($empleado) ?></span>
    <a href="../frontend.php" class="text-tao-100 hover:text-white text-sm flex items-center gap-1">
      ← Dashboard
    </a>
    <?php if ($rol === 'administrador'): ?>
    <a href="admin/admin_capacitaciones.php" class="bg-gold-500 text-tao-900 text-sm font-semibold px-3 py-1 rounded-lg hover:bg-gold-400 transition">
      Admin
    </a>
    <?php endif; ?>
  </div>
</nav>

<!-- ====================================================
     ENCABEZADO
==================================================== -->
<div class="bg-gradient-to-r from-tao-900 to-tao-700 text-white px-6 pt-8 pb-12">
  <div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-display font-bold mb-1">Centro de Capacitación TAO</h1>
    <p class="text-tao-100 text-sm">Totally Aligned Organization — Desarrolla tus competencias y alinéate con los objetivos de la empresa</p>

    <!-- KPIs rápidos -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6" id="kpiBar">
      <div class="bg-white/10 rounded-xl p-4">
        <div class="text-2xl font-bold" id="kpi-cursos">—</div>
        <div class="text-xs text-tao-100 mt-1">Cursos disponibles</div>
      </div>
      <div class="bg-white/10 rounded-xl p-4">
        <div class="text-2xl font-bold" id="kpi-completados">—</div>
        <div class="text-xs text-tao-100 mt-1">Completados</div>
      </div>
      <div class="bg-white/10 rounded-xl p-4">
        <div class="text-2xl font-bold" id="kpi-puntaje-tao">—</div>
        <div class="text-xs text-tao-100 mt-1">Último índice TAO</div>
      </div>
      <div class="bg-white/10 rounded-xl p-4">
        <div class="text-2xl font-bold" id="kpi-certificados">—</div>
        <div class="text-xs text-tao-100 mt-1">Certificados</div>
      </div>
    </div>
  </div>
</div>

<!-- ====================================================
     CONTENIDO PRINCIPAL
==================================================== -->
<div class="max-w-6xl mx-auto px-4 -mt-6 pb-12">

  <!-- TABS -->
  <div class="card p-4 mb-6 flex flex-wrap gap-2">
    <button class="tab-btn active" onclick="switchTab('cursos')">📚 Catálogo</button>
    <button class="tab-btn" onclick="switchTab('miprogreso')">📈 Mi Progreso</button>
    <button class="tab-btn" onclick="switchTab('evaluacion_tao')">🎯 Evaluación TAO</button>
    <button class="tab-btn" onclick="switchTab('sedac')">🔧 SEDAC</button>
    <button class="tab-btn" onclick="switchTab('cue')">💬 Feedback Cue</button>
  </div>

  <!-- ========================
       TAB: CATÁLOGO DE CURSOS
  ======================== -->
  <div id="tab-cursos" class="tab-content active">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-display font-bold text-slate-800">Catálogo de Cursos</h2>
      <div class="flex gap-2">
        <select id="filtroNivel" onchange="filtrarCursos()" class="text-sm border rounded-lg px-3 py-2 text-slate-600 focus:ring-2 focus:ring-tao-500 outline-none">
          <option value="">Todos los niveles</option>
          <option value="basico">Básico</option>
          <option value="intermedio">Intermedio</option>
          <option value="avanzado">Avanzado</option>
        </select>
        <select id="filtroTipo" onchange="filtrarCursos()" class="text-sm border rounded-lg px-3 py-2 text-slate-600 focus:ring-2 focus:ring-tao-500 outline-none">
          <option value="">Todos los tipos</option>
          <option value="curso">Curso</option>
          <option value="test">Test</option>
          <option value="certificacion">Certificación</option>
          <option value="taller">Taller</option>
        </select>
      </div>
    </div>

    <div id="gridCursos" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <!-- Skeleton loaders -->
      <?php for ($i=0; $i<6; $i++): ?>
      <div class="card p-5 animate-pulse">
        <div class="h-4 bg-slate-200 rounded w-3/4 mb-3"></div>
        <div class="h-3 bg-slate-100 rounded w-full mb-2"></div>
        <div class="h-3 bg-slate-100 rounded w-2/3"></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- ========================
       TAB: MI PROGRESO
  ======================== -->
  <div id="tab-miprogreso" class="tab-content">
    <h2 class="text-lg font-display font-bold text-slate-800 mb-4">Mi Progreso</h2>
    <div id="miProgresoList" class="space-y-4">
      <div class="card p-8 text-center text-slate-400">Cargando progreso...</div>
    </div>
  </div>

  <!-- ========================
       TAB: EVALUACIÓN TAO
  ======================== -->
  <div id="tab-evaluacion_tao" class="tab-content">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Formulario de evaluación -->
      <div class="lg:col-span-2">
        <div class="card p-6">
          <h2 class="text-lg font-display font-bold text-slate-800 mb-2">Evaluación de Madurez Organizacional</h2>
          <p class="text-sm text-slate-500 mb-6">Responde con honestidad. Los resultados ayudan a identificar áreas de mejora en tu equipo y organización. (Metodología PQCDSIM)</p>

          <div class="mb-4">
            <label class="text-sm font-medium text-slate-700">Área / Departamento</label>
            <input type="text" id="taoArea" placeholder="Ej: Producción, Calidad, Logística..."
              class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-tao-500 outline-none">
          </div>

          <div id="preguntasTAO" class="space-y-6">
            <div class="text-center py-8 text-slate-400">Cargando preguntas...</div>
          </div>

          <button onclick="enviarEvaluacionTAO()"
            class="mt-6 w-full bg-tao-600 hover:bg-tao-700 text-white font-semibold py-3 rounded-xl transition flex items-center justify-center gap-2">
            <span>Enviar Evaluación TAO</span>
            <span id="taoSpinner" class="hidden">⏳</span>
          </button>
        </div>
      </div>

      <!-- Historial y radar -->
      <div class="space-y-5">
        <div class="card p-5">
          <h3 class="font-display font-bold text-slate-700 mb-3 text-sm uppercase tracking-wide">Historial TAO</h3>
          <div id="historialTAO" class="space-y-3 text-sm text-slate-500">Cargando...</div>
        </div>

        <div class="card p-5" id="radarContainer" style="display:none">
          <h3 class="font-display font-bold text-slate-700 mb-3 text-sm uppercase tracking-wide">Último Resultado</h3>
          <canvas id="radarChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================
       TAB: SEDAC
  ======================== -->
  <div id="tab-sedac" class="tab-content">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Nuevo ticket -->
      <div class="card p-6">
        <h2 class="text-lg font-display font-bold text-slate-800 mb-4">Nuevo Ticket SEDAC</h2>
        <p class="text-xs text-slate-500 mb-4">SEDAC: <em>Structure to Enhance Daily Activity through Creativity</em>. Registra problemas para resolverlos en equipo.</p>
        <div class="space-y-3">
          <div>
            <label class="text-sm font-medium text-slate-700">Título del Problema</label>
            <input type="text" id="sedacTitulo" placeholder="Resumen breve del problema..."
              class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-tao-500 outline-none">
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Descripción Detallada</label>
            <textarea id="sedacProblema" rows="4" placeholder="Describe el problema, cuándo ocurre, impacto..."
              class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-tao-500 outline-none resize-none"></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-sm font-medium text-slate-700">Área</label>
              <input type="text" id="sedacArea" placeholder="Ej: Producción"
                class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-tao-500 outline-none">
            </div>
            <div>
              <label class="text-sm font-medium text-slate-700">Prioridad</label>
              <select id="sedacPrioridad" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-tao-500 outline-none">
                <option value="baja">Baja</option>
                <option value="media" selected>Media</option>
                <option value="alta">Alta</option>
                <option value="critica">Crítica</option>
              </select>
            </div>
          </div>
          <button onclick="crearTicketSEDAC()"
            class="w-full bg-tao-600 hover:bg-tao-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">
            Registrar Problema
          </button>
        </div>
      </div>

      <!-- Lista de tickets -->
      <div class="card p-6">
        <h2 class="text-lg font-display font-bold text-slate-800 mb-4">Mis Tickets</h2>
        <div id="listaSEDAC" class="space-y-3 max-h-96 overflow-y-auto">
          <div class="text-center text-slate-400 text-sm py-4">Cargando tickets...</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================
       TAB: CUE CARDS
  ======================== -->
  <div id="tab-cue" class="tab-content">
    <div class="max-w-lg mx-auto card p-8">
      <h2 class="text-lg font-display font-bold text-slate-800 mb-2">Feedback del Día</h2>
      <p class="text-sm text-slate-500 mb-6">Responde estas 3 preguntas rápidas para ayudar a mejorar el ambiente y los procesos de tu área. Solo toma 1 minuto.</p>
      <div id="cuePreguntas" class="space-y-6">
        <div class="text-center text-slate-400">Cargando preguntas...</div>
      </div>
      <button onclick="enviarCue()"
        class="mt-6 w-full bg-tao-600 hover:bg-tao-700 text-white font-semibold py-3 rounded-xl transition text-sm">
        Enviar Feedback
      </button>
    </div>
  </div>

</div><!-- /max-w-6xl -->

<!-- ====================================================
     MODAL: DETALLE DE CURSO
==================================================== -->
<div id="modalCurso" class="modal-overlay" onclick="if(event.target===this)cerrarModal()">
  <div class="modal-box">
    <div class="flex items-start justify-between mb-4">
      <h2 id="modalTitulo" class="text-xl font-display font-bold text-slate-800"></h2>
      <button onclick="cerrarModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none ml-4">×</button>
    </div>
    <p id="modalDescripcion" class="text-slate-500 text-sm mb-5"></p>
    <div id="modalModulos" class="space-y-3 mb-6"></div>
    <div class="flex gap-3">
      <button id="btnIniciarCurso" onclick="iniciarCurso()"
        class="flex-1 bg-tao-600 hover:bg-tao-700 text-white font-semibold py-2.5 rounded-xl transition text-sm">
        Iniciar / Continuar Curso
      </button>
      <button id="btnIrTest" onclick="irAlTest()"
        class="flex-1 border border-tao-600 text-tao-600 hover:bg-tao-50 font-semibold py-2.5 rounded-xl transition text-sm hidden">
        📝 Tomar Test
      </button>
    </div>
  </div>
</div>

<!-- ====================================================
     MODAL: TEST DE EVALUACIÓN
==================================================== -->
<div id="modalTest" class="modal-overlay" onclick="">
  <div class="modal-box" style="max-width:760px">
    <div class="flex items-start justify-between mb-4">
      <h2 id="testTitulo" class="text-xl font-display font-bold text-slate-800"></h2>
      <button onclick="cerrarTest()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none ml-4">×</button>
    </div>
    <div id="testPreguntas" class="space-y-6 mb-6"></div>
    <button onclick="enviarTest()"
      class="w-full bg-tao-600 hover:bg-tao-700 text-white font-semibold py-3 rounded-xl transition">
      Enviar Respuestas
    </button>
  </div>
</div>

<!-- TOAST -->
<div id="toast"></div>


<!-- ====================================================
     JAVASCRIPT
==================================================== -->
<script>
const API = 'backend_capacitaciones.php';
let cursoActualId = null;
let cursoActualTipo = null;
let todosCursos = [];
let radarChartInstance = null;

// ===============================
// INICIALIZACIÓN
// ===============================
document.addEventListener('DOMContentLoaded', () => {
  cargarCursos();
  cargarProgreso();
  cargarHistorialTAO();
  cargarPreguntasTAO();
  cargarTicketsSEDAC();
  cargarCueCards();
  actualizarKPIs();
});


// ===============================
// UTILIDADES
// ===============================
async function api(params) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(params)) fd.append(k, v);
  const res = await fetch(API, { method: 'POST', body: fd });
  return res.json();
}

function toast(msg, type = 'info') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = `show ${type}`;
  setTimeout(() => el.className = '', 3500);
}

function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach((b, i) => {
    const tabId = ['cursos','miprogreso','evaluacion_tao','sedac','cue'][i];
    b.classList.toggle('active', tabId === name);
  });
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.getElementById(`tab-${name}`).classList.add('active');
}

function badgeColor(tipo) {
  const map = {
    'curso': 'tipo-curso', 'test': 'tipo-test',
    'certificacion': 'tipo-certificacion', 'taller': 'tipo-taller'
  };
  return map[tipo] || 'bg-slate-100 text-slate-600';
}

function nivelColor(nivel) {
  return { basico: 'nivel-basico', intermedio: 'nivel-intermedio', avanzado: 'nivel-avanzado' }[nivel] || '';
}

function estadoIcon(estado) {
  return {
    pendiente: '⬜', en_progreso: '🔵', completado: '✅', certificado: '🏆'
  }[estado] || '⬜';
}


// ===============================
// CATÁLOGO DE CURSOS
// ===============================
async function cargarCursos() {
  const data = await api({ action: 'listar_cursos' });
  if (!data.success) return;
  todosCursos = data.data;
  renderCursos(todosCursos);
  document.getElementById('kpi-cursos').textContent = todosCursos.length;

  const completados = todosCursos.filter(c => c.mi_progreso === 'completado' || c.mi_progreso === 'certificado').length;
  document.getElementById('kpi-completados').textContent = completados;
}

function renderCursos(lista) {
  const grid = document.getElementById('gridCursos');
  if (!lista.length) {
    grid.innerHTML = '<div class="col-span-3 text-center text-slate-400 py-12">No hay cursos disponibles</div>';
    return;
  }
  grid.innerHTML = lista.map(c => `
    <div class="card p-5 cursor-pointer flex flex-col gap-3" onclick="abrirCurso(${c.id})">
      <div class="flex items-start justify-between gap-2">
        <h3 class="font-display font-bold text-slate-800 text-sm leading-snug">${c.titulo}</h3>
        <span class="badge ${badgeColor(c.tipo)} whitespace-nowrap">${c.tipo}</span>
      </div>
      <p class="text-xs text-slate-500 line-clamp-2 flex-1">${c.descripcion || 'Sin descripción'}</p>
      <div class="flex items-center gap-2 flex-wrap">
        <span class="badge ${nivelColor(c.nivel)}">${c.nivel}</span>
        <span class="text-xs text-slate-400">⏱ ${c.duracion_min} min</span>
        <span class="text-xs text-slate-400">📁 ${c.total_modulos} módulos</span>
      </div>
      <div>
        <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
          <span>${estadoIcon(c.mi_progreso)} ${c.mi_progreso === 'pendiente' ? 'No iniciado' : c.mi_progreso}</span>
          ${c.mi_puntaje > 0 ? `<span class="font-semibold text-tao-600">${c.mi_puntaje}%</span>` : ''}
        </div>
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" style="width:${c.mi_progreso==='completado'||c.mi_progreso==='certificado' ? 100 : (c.mi_progreso==='en_progreso' ? 50 : 0)}%"></div>
        </div>
      </div>
    </div>
  `).join('');
}

function filtrarCursos() {
  const nivel = document.getElementById('filtroNivel').value;
  const tipo  = document.getElementById('filtroTipo').value;
  const filtrados = todosCursos.filter(c =>
    (!nivel || c.nivel === nivel) && (!tipo || c.tipo === tipo)
  );
  renderCursos(filtrados);
}


// ===============================
// MODAL DETALLE CURSO
// ===============================
async function abrirCurso(id) {
  cursoActualId = id;
  const data = await api({ action: 'detalle_curso', id });
  if (!data.success) return toast('Error al cargar el curso', 'error');

  const c = data.data;
  cursoActualTipo = c.tipo;

  document.getElementById('modalTitulo').textContent = c.titulo;
  document.getElementById('modalDescripcion').textContent = c.descripcion || '';

  const modHtml = c.modulos.length
    ? c.modulos.map(m => `
        <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
          <span class="text-lg">${m.tipo_contenido==='video' ? '🎬' : m.tipo_contenido==='test' ? '📝' : '📄'}</span>
          <div>
            <div class="font-medium text-sm text-slate-800">${m.orden}. ${m.titulo}</div>
            <div class="text-xs text-slate-400 mt-0.5">${m.tipo_contenido} · ${m.duracion_min} min</div>
          </div>
        </div>
      `).join('')
    : '<p class="text-sm text-slate-400">Este curso aún no tiene módulos configurados.</p>';

  document.getElementById('modalModulos').innerHTML = modHtml;

  const btnTest = document.getElementById('btnIrTest');
  if (c.tipo === 'test' || c.tipo === 'certificacion') {
    btnTest.classList.remove('hidden');
  } else {
    btnTest.classList.add('hidden');
  }

  document.getElementById('modalCurso').classList.add('open');
}

function cerrarModal() {
  document.getElementById('modalCurso').classList.remove('open');
}

async function iniciarCurso() {
  if (!cursoActualId) return;
  const data = await api({ action: 'iniciar_curso', capacitacion_id: cursoActualId });
  if (data.success) {
    toast('¡Curso iniciado! Tu progreso quedará registrado.', 'success');
    cerrarModal();
    cargarCursos();
    cargarProgreso();
  } else {
    toast(data.error || 'Error al iniciar', 'error');
  }
}

function irAlTest() {
  cerrarModal();
  abrirModalTest(cursoActualId);
}


// ===============================
// MODAL TEST
// ===============================
async function abrirModalTest(id) {
  const data = await api({ action: 'obtener_preguntas', capacitacion_id: id });
  if (!data.success) return toast('Error al cargar preguntas', 'error');

  cursoActualId = id;
  const preguntas = data.data;
  if (!preguntas.length) return toast('Este test no tiene preguntas configuradas.', 'info');

  const html = preguntas.map((p, i) => `
    <div class="p-4 bg-slate-50 rounded-xl" data-pregunta="${p.id}">
      <p class="font-medium text-sm text-slate-800 mb-3">${i+1}. ${p.pregunta}</p>
      <div class="space-y-2">
        ${['a','b','c','d'].filter(l => p['opcion_'+l]).map(l => `
          <label class="flex items-center gap-3 p-2 rounded-lg cursor-pointer hover:bg-tao-50 transition">
            <input type="radio" name="preg_${p.id}" value="${l}" class="text-tao-600">
            <span class="text-sm text-slate-700">${p['opcion_'+l]}</span>
          </label>
        `).join('')}
      </div>
    </div>
  `).join('');

  document.getElementById('testTitulo').textContent = `Test — ${preguntas.length} preguntas`;
  document.getElementById('testPreguntas').innerHTML = html;
  document.getElementById('modalTest').classList.add('open');
}

function cerrarTest() {
  document.getElementById('modalTest').classList.remove('open');
}

async function enviarTest() {
  const divs = document.querySelectorAll('#testPreguntas [data-pregunta]');
  const respuestas = [];
  let incompleto = false;

  divs.forEach(div => {
    const pid = div.dataset.pregunta;
    const sel = div.querySelector(`input[name="preg_${pid}"]:checked`);
    if (!sel) { incompleto = true; return; }
    respuestas.push({ pregunta_id: pid, respuesta: sel.value });
  });

  if (incompleto) return toast('Por favor responde todas las preguntas.', 'info');

  const data = await api({
    action: 'enviar_test',
    capacitacion_id: cursoActualId,
    respuestas: JSON.stringify(respuestas)
  });

  if (data.success) {
    cerrarTest();
    toast(data.mensaje, data.aprobado ? 'success' : 'info');
    if (data.folio) {
      setTimeout(() => toast(`🎓 Certificado emitido: ${data.folio}`, 'success'), 2000);
    }
    cargarCursos();
    cargarProgreso();
    actualizarKPIs();
  } else {
    toast(data.error || 'Error al enviar', 'error');
  }
}


// ===============================
// MI PROGRESO
// ===============================
async function cargarProgreso() {
  const data = await api({ action: 'progreso_empleado' });
  if (!data.success) return;

  const lista = data.data;
  const el = document.getElementById('miProgresoList');

  if (!lista.length) {
    el.innerHTML = '<div class="card p-8 text-center text-slate-400">Aún no has iniciado ningún curso. ¡Empieza hoy!</div>';
    return;
  }

  el.innerHTML = lista.map(p => `
    <div class="card p-5 flex items-center gap-4">
      <div class="text-3xl">${estadoIcon(p.estado)}</div>
      <div class="flex-1 min-w-0">
        <div class="font-display font-bold text-slate-800 text-sm truncate">${p.titulo}</div>
        <div class="flex items-center gap-3 mt-1 text-xs text-slate-500">
          <span class="badge ${badgeColor(p.tipo)}">${p.tipo}</span>
          <span>${p.estado}</span>
          ${p.puntaje > 0 ? `<span class="font-semibold text-tao-600">${p.puntaje}%</span>` : ''}
          ${p.fecha_inicio ? `<span>Inicio: ${p.fecha_inicio.split(' ')[0]}</span>` : ''}
        </div>
        <div class="progress-bar-wrap mt-2">
          <div class="progress-bar-fill" style="width:${p.estado==='completado'||p.estado==='certificado' ? 100 : (p.estado==='en_progreso' ? 50 : 5)}%"></div>
        </div>
      </div>
      ${p.estado === 'completado' ? `
        <button onclick="abrirModalTest(${p.capacitacion_id})"
          class="shrink-0 text-xs bg-tao-100 text-tao-700 font-semibold px-3 py-2 rounded-lg hover:bg-tao-200 transition">
          Tomar Test
        </button>` : ''}
    </div>
  `).join('');
}


// ===============================
// EVALUACIÓN TAO
// ===============================
async function cargarPreguntasTAO() {
  const data = await api({ action: 'obtener_preguntas_tao' });
  if (!data.success) return;

  const preguntas = data.data;
  const el = document.getElementById('preguntasTAO');

  if (!preguntas.length) {
    el.innerHTML = '<p class="text-slate-400 text-sm text-center">No hay preguntas configuradas.</p>';
    return;
  }

  el.innerHTML = preguntas.map((p, i) => `
    <div class="p-5 border border-slate-200 rounded-xl" data-tao-pregunta="${p.id}">
      <div class="flex items-start gap-2 mb-3">
        <span class="badge bg-tao-100 text-tao-700 shrink-0">${p.categoria}</span>
        <p class="font-medium text-sm text-slate-800">${p.pregunta}</p>
      </div>
      <div class="grid grid-cols-1 gap-2">
        ${['a','b','c','d'].filter(l => p['opcion_'+l]).map((l, idx) => `
          <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer hover:border-tao-400 hover:bg-tao-50 transition has-[:checked]:border-tao-500 has-[:checked]:bg-tao-50">
            <input type="radio" name="tao_${p.id}" value="${l}" class="mt-0.5 text-tao-600 shrink-0">
            <div>
              <span class="text-xs font-bold text-tao-600 mr-2">${l.toUpperCase()})</span>
              <span class="text-sm text-slate-700">${p['opcion_'+l]}</span>
            </div>
          </label>
        `).join('')}
      </div>
    </div>
  `).join('');
}

async function enviarEvaluacionTAO() {
  const area = document.getElementById('taoArea').value.trim();
  const divs = document.querySelectorAll('[data-tao-pregunta]');
  const respuestas = [];
  let incompleto = false;

  divs.forEach(div => {
    const pid = div.dataset.taoPregunta;
    const sel = div.querySelector(`input[name="tao_${pid}"]:checked`);
    if (!sel) { incompleto = true; return; }
    respuestas.push({ pregunta_id: pid, respuesta: sel.value });
  });

  if (incompleto) return toast('Por favor responde todas las preguntas antes de enviar.', 'info');
  if (!area) return toast('Por favor indica tu área de trabajo.', 'info');

  document.getElementById('taoSpinner').classList.remove('hidden');

  const data = await api({
    action: 'enviar_evaluacion_tao',
    area,
    respuestas: JSON.stringify(respuestas)
  });

  document.getElementById('taoSpinner').classList.add('hidden');

  if (data.success) {
    toast(`Evaluación TAO completada. Índice: ${data.promedio}% — Nivel: ${data.nivel}`, 'success');
    renderRadar(data.dimensiones);
    cargarHistorialTAO();
    document.getElementById('kpi-puntaje-tao').textContent = data.promedio + '%';
  } else {
    toast(data.error || 'Error al enviar', 'error');
  }
}

function renderRadar(dimensiones) {
  document.getElementById('radarContainer').style.display = 'block';
  const ctx = document.getElementById('radarChart').getContext('2d');
  if (radarChartInstance) radarChartInstance.destroy();

  const labels = Object.keys(dimensiones);
  const valores = Object.values(dimensiones);

  radarChartInstance = new Chart(ctx, {
    type: 'radar',
    data: {
      labels,
      datasets: [{
        label: 'Índice TAO',
        data: valores,
        backgroundColor: 'rgba(14,165,233,.2)',
        borderColor: '#0ea5e9',
        borderWidth: 2,
        pointBackgroundColor: '#0369a1',
        pointRadius: 4,
      }]
    },
    options: {
      scales: { r: { min: 0, max: 100, ticks: { stepSize: 25 } } },
      plugins: { legend: { display: false } }
    }
  });
}

async function cargarHistorialTAO() {
  const data = await api({ action: 'historial_tao_empleado' });
  const el = document.getElementById('historialTAO');

  if (!data.success || !data.data.length) {
    el.innerHTML = '<p class="text-xs">No hay evaluaciones previas.</p>';
    document.getElementById('kpi-puntaje-tao').textContent = '—';
    return;
  }

  el.innerHTML = data.data.map(r => `
    <div class="flex items-center justify-between">
      <div>
        <div class="font-medium text-slate-700">${r.puntaje_total}%</div>
        <div class="text-xs text-slate-400">${r.area || 'General'} · ${r.fecha_evaluacion.split(' ')[0]}</div>
      </div>
      <span class="badge nivel-${r.nivel_alineacion}">${r.nivel_alineacion}</span>
    </div>
  `).join('');

  document.getElementById('kpi-puntaje-tao').textContent = data.data[0].puntaje_total + '%';
}


// ===============================
// SEDAC
// ===============================
async function cargarTicketsSEDAC() {
  const data = await api({ action: 'listar_tickets_sedac' });
  const el = document.getElementById('listaSEDAC');
  if (!data.success || !data.data.length) {
    el.innerHTML = '<p class="text-sm text-slate-400 text-center py-4">No hay tickets activos.</p>';
    return;
  }
  const colores = {
    abierto: 'bg-yellow-100 text-yellow-800', en_analisis: 'bg-blue-100 text-blue-800',
    en_solucion: 'bg-purple-100 text-purple-800', cerrado: 'bg-green-100 text-green-800',
    verificado: 'bg-teal-100 text-teal-800'
  };
  const prioridades = {
    baja: 'text-slate-400', media: 'text-yellow-500', alta: 'text-orange-500', critica: 'text-red-600'
  };
  el.innerHTML = data.data.map(t => `
    <div class="p-3 border rounded-xl">
      <div class="flex items-start justify-between gap-2">
        <div class="font-medium text-sm text-slate-800">${t.titulo}</div>
        <span class="badge ${colores[t.estado] || 'bg-slate-100 text-slate-600'} whitespace-nowrap">${t.estado}</span>
      </div>
      <p class="text-xs text-slate-500 mt-1 line-clamp-2">${t.problema}</p>
      <div class="flex items-center gap-3 mt-2 text-xs text-slate-400">
        <span class="${prioridades[t.prioridad] || ''} font-semibold capitalize">● ${t.prioridad}</span>
        <span>${t.area || '—'}</span>
        <span>${t.fecha_creacion.split(' ')[0]}</span>
      </div>
    </div>
  `).join('');
}

async function crearTicketSEDAC() {
  const titulo   = document.getElementById('sedacTitulo').value.trim();
  const problema = document.getElementById('sedacProblema').value.trim();
  const area     = document.getElementById('sedacArea').value.trim();
  const prioridad = document.getElementById('sedacPrioridad').value;

  if (!titulo || !problema) return toast('Título y descripción son obligatorios', 'info');

  const data = await api({ action: 'crear_ticket_sedac', titulo, problema, area, prioridad });
  if (data.success) {
    toast('Ticket SEDAC registrado correctamente', 'success');
    document.getElementById('sedacTitulo').value = '';
    document.getElementById('sedacProblema').value = '';
    document.getElementById('sedacArea').value = '';
    cargarTicketsSEDAC();
  } else {
    toast(data.error || 'Error al crear ticket', 'error');
  }
}


// ===============================
// CUE CARDS
// ===============================
async function cargarCueCards() {
  const data = await api({ action: 'preguntas_cue' });
  const el = document.getElementById('cuePreguntas');
  if (!data.success || !data.data.length) {
    el.innerHTML = '<p class="text-slate-400 text-sm text-center">No hay preguntas disponibles hoy.</p>';
    return;
  }
  el.innerHTML = data.data.map(p => `
    <div data-cue="${p.id}">
      <p class="font-medium text-sm text-slate-800 mb-3">${p.pregunta}</p>
      ${p.tipo === 'si_no' ? `
        <div class="flex gap-3">
          <label class="flex-1 p-3 border rounded-xl text-center cursor-pointer hover:border-tao-400 transition has-[:checked]:border-tao-500 has-[:checked]:bg-tao-50">
            <input type="radio" name="cue_${p.id}" value="si" class="sr-only"> ✅ Sí
          </label>
          <label class="flex-1 p-3 border rounded-xl text-center cursor-pointer hover:border-tao-400 transition has-[:checked]:border-tao-500 has-[:checked]:bg-tao-50">
            <input type="radio" name="cue_${p.id}" value="no" class="sr-only"> ❌ No
          </label>
        </div>
      ` : `
        <div class="flex gap-2 justify-between">
          ${[1,2,3,4,5].map(n => `
            <label class="flex-1 p-3 border rounded-xl text-center cursor-pointer text-sm font-bold hover:border-tao-400 transition has-[:checked]:border-tao-500 has-[:checked]:bg-tao-50">
              <input type="radio" name="cue_${p.id}" value="${n}" class="sr-only">${n}
            </label>
          `).join('')}
        </div>
        <div class="flex justify-between text-xs text-slate-400 mt-1">
          <span>Muy mal</span><span>Excelente</span>
        </div>
      `}
    </div>
  `).join('');
}

async function enviarCue() {
  const divs = document.querySelectorAll('[data-cue]');
  const respuestas = [];
  let incompleto = false;

  divs.forEach(div => {
    const pid = div.dataset.cue;
    const sel = div.querySelector(`input[name="cue_${pid}"]:checked`);
    if (!sel) { incompleto = true; return; }
    respuestas.push({ pregunta_id: pid, respuesta: sel.value });
  });

  if (incompleto) return toast('Por favor responde todas las preguntas', 'info');

  const data = await api({ action: 'enviar_cue', respuestas: JSON.stringify(respuestas) });
  if (data.success) {
    toast('¡Gracias por tu feedback! 🙏', 'success');
    document.getElementById('cuePreguntas').innerHTML =
      '<div class="text-center py-8"><div class="text-5xl mb-3">🙌</div><p class="text-slate-600 font-medium">Feedback registrado para hoy</p><p class="text-sm text-slate-400">Vuelve mañana para las preguntas del día</p></div>';
  }
}


// ===============================
// KPIs GENERALES
// ===============================
async function actualizarKPIs() {
  // Certificados del empleado actual
  try {
    const data = await api({ action: 'historial_tests' });
    if (data.success) {
      const certs = data.data.filter(t => t.estado === 'certificado' || t.puntaje >= 70).length;
      document.getElementById('kpi-certificados').textContent = certs;
    }
  } catch(e) {}
}
</script>
</body>
</html>
