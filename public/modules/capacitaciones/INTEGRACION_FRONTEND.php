<?php
// ============================================================
// FRAGMENTO PARA INTEGRAR EN frontend.php EXISTENTE
// Agrega el bloque TAO al dashboard principal
// Pega este código dentro del grid de tarjetas del dashboard
// ============================================================
?>

<!-- ============================================================
     TARJETA TAO — Agregar en el dashboard principal (frontend.php)
     Coloca este bloque junto a las otras tarjetas KPI
============================================================ -->

<!-- 1. ACCESO RÁPIDO AL MÓDULO TAO (en la barra de navegación) -->
<!-- Agrega este enlace al nav de frontend.php: -->
<a href="modules/capacitaciones/capacitaciones.php"
   class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-white/10 transition text-sm font-medium">
  🎓 Capacitaciones
</a>


<!-- 2. TARJETA RESUMEN TAO PARA EL DASHBOARD PRINCIPAL -->
<div class="bg-gradient-to-br from-sky-600 to-sky-800 text-white rounded-2xl p-6 shadow-lg col-span-1 md:col-span-2">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h3 class="font-bold text-lg font-display">Índice de Alineación TAO</h3>
      <p class="text-sky-200 text-sm">Totally Aligned Organization</p>
    </div>
    <a href="modules/capacitaciones/capacitaciones.php"
       class="bg-white/20 hover:bg-white/30 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
      Ver módulo →
    </a>
  </div>

  <div id="taoResumenDash" class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <!-- Cargado por JS -->
    <div class="bg-white/10 rounded-xl p-3 animate-pulse">
      <div class="h-6 bg-white/20 rounded mb-2"></div>
      <div class="h-3 bg-white/10 rounded w-2/3"></div>
    </div>
    <div class="bg-white/10 rounded-xl p-3 animate-pulse">
      <div class="h-6 bg-white/20 rounded mb-2"></div>
      <div class="h-3 bg-white/10 rounded w-2/3"></div>
    </div>
    <div class="bg-white/10 rounded-xl p-3 animate-pulse">
      <div class="h-6 bg-white/20 rounded mb-2"></div>
      <div class="h-3 bg-white/10 rounded w-2/3"></div>
    </div>
    <div class="bg-white/10 rounded-xl p-3 animate-pulse">
      <div class="h-6 bg-white/20 rounded mb-2"></div>
      <div class="h-3 bg-white/10 rounded w-2/3"></div>
    </div>
  </div>

  <!-- Mini chart TAO -->
  <div class="mt-4">
    <canvas id="taoMiniChart" height="60"></canvas>
  </div>
</div>


<!-- 3. SCRIPT PARA CARGAR DATOS TAO EN EL DASHBOARD PRINCIPAL -->
<!-- Agrega este script al final de frontend.php (antes de </body>) -->
<script>
(async function cargarResumenTAO() {
  try {
    const fd = new FormData();
    fd.append('action', 'stats_dashboard_capacitaciones');
    const res = await fetch('modules/capacitaciones/backend_capacitaciones.php',
                            { method:'POST', body: fd });
    const data = await res.json();
    if (!data.success) return;

    document.getElementById('taoResumenDash').innerHTML = `
      <div class="bg-white/10 rounded-xl p-3">
        <div class="text-2xl font-bold">${data.empleados_capacitados}</div>
        <div class="text-xs text-sky-200 mt-1">Empleados capacitados</div>
      </div>
      <div class="bg-white/10 rounded-xl p-3">
        <div class="text-2xl font-bold">${data.certificados}</div>
        <div class="text-xs text-sky-200 mt-1">Certificados emitidos</div>
      </div>
      <div class="bg-white/10 rounded-xl p-3">
        <div class="text-2xl font-bold">${data.tao_promedio || 0}%</div>
        <div class="text-xs text-sky-200 mt-1">Promedio TAO</div>
      </div>
      <div class="bg-white/10 rounded-xl p-3">
        <div class="text-2xl font-bold">${data.cursos_activos}</div>
        <div class="text-xs text-sky-200 mt-1">Cursos activos</div>
      </div>
    `;

    // Mini chart
    if (data.evolucion_tao && data.evolucion_tao.length) {
      new Chart(document.getElementById('taoMiniChart'), {
        type: 'line',
        data: {
          labels: data.evolucion_tao.map(r => r.fecha),
          datasets: [{
            data: data.evolucion_tao.map(r => r.promedio),
            borderColor: 'rgba(255,255,255,0.8)',
            backgroundColor: 'rgba(255,255,255,0.1)',
            fill: true, tension: .4, pointRadius: 2, borderWidth: 2
          }]
        },
        options: {
          scales: { x: { display: false }, y: { display: false, min: 0, max: 100 } },
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            label: ctx => `TAO: ${ctx.raw}%`
          }}},
          animation: { duration: 1000 }
        }
      });
    }
  } catch(e) { console.warn('TAO dashboard:', e); }
})();
</script>


<!-- ============================================================
     ESTRUCTURA DE CARPETAS FINAL DEL PROYECTO
     (Para referencia — coloca los archivos donde corresponde)
============================================================

control_produccion/
├── frontend.php                          ← Tu archivo existente (agregar fragmento arriba)
├── backend.php                           ← Tu archivo existente
├── api.js                                ← Tu archivo existente
├── registro_paro.php                     ← Tu archivo existente
│
├── modules/
│   └── capacitaciones/
│       ├── capacitaciones.php            ← NUEVO: Catálogo + progreso del empleado
│       ├── backend_capacitaciones.php    ← NUEVO: API REST del módulo
│       └── admin/
│           └── admin_capacitaciones.php  ← NUEVO: Panel admin
│
└── tao_schema.sql                        ← NUEVO: Tablas de BD (ejecutar una vez)

============================================================ -->
