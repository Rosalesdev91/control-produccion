<?php
// public/index.php
// Página de inicio — acceso a todos los módulos del sistema
date_default_timezone_set('America/Costa_Rica');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>SIA-LAB | Sistema de Control de Producción</title>
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Barlow:wght@400;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
  <style>
    :root {
      --g: #28a745;
      --dg: #1e7e34;
      --vdg: #0a3d1a;
      --lg: #d4fcd4;
      --white: #fff;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Barlow', sans-serif;
      background: var(--vdg);
      color: var(--white);
      min-height: 100vh;
      overflow-x: hidden;
    }

    /* ── FONDO ANIMADO ── */
    .bg {
      position: fixed; inset: 0; z-index: 0;
      background:
        radial-gradient(ellipse at 20% 50%, rgba(40,167,69,.18) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 20%, rgba(30,126,52,.2) 0%, transparent 55%),
        radial-gradient(ellipse at 60% 80%, rgba(10,61,26,.4) 0%, transparent 50%),
        #0a3d1a;
    }
    .bg::before {
      content: '';
      position: absolute; inset: 0;
      background-image:
        repeating-linear-gradient(0deg, transparent, transparent 60px, rgba(40,167,69,.04) 60px, rgba(40,167,69,.04) 61px),
        repeating-linear-gradient(90deg, transparent, transparent 60px, rgba(40,167,69,.04) 60px, rgba(40,167,69,.04) 61px);
    }

    /* ── LAYOUT ── */
    .wrapper {
      position: relative; z-index: 1;
      min-height: 100vh;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 40px 20px 80px;
    }

    /* ── HEADER ── */
    .header {
      text-align: center;
      margin-bottom: 50px;
      animation: fadeDown .8s ease both;
    }

    .logo-wrap {
      width: 110px; height: 110px;
      border-radius: 50%;
      border: 3px solid var(--g);
      padding: 8px;
      margin: 0 auto 20px;
      animation: float 5s ease-in-out infinite;
      overflow: hidden;
      background: rgba(0,0,0,.2);
    }
    .logo-wrap img {
      width: 100%; height: 100%;
      object-fit: contain; border-radius: 50%;
    }

    @keyframes float {
      0%,100% { transform: translateY(0); }
      50%      { transform: translateY(-10px); }
    }

    h1 {
      font-family: 'Orbitron', monospace;
      font-size: clamp(2rem, 6vw, 3.2rem);
      font-weight: 900;
      letter-spacing: 4px;
      background: linear-gradient(135deg, #4ade80, #16a34a, #4ade80);
      background-size: 200%;
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      animation: shimmer 3s linear infinite;
    }
    @keyframes shimmer { to { background-position: 200% center; } }

    .subtitle {
      color: rgba(212,252,212,.7);
      font-size: 1rem;
      margin-top: 8px;
      letter-spacing: 1px;
    }

    .reloj {
      margin-top: 14px;
      font-size: .9rem;
      color: rgba(212,252,212,.55);
      font-family: 'Orbitron', monospace;
      letter-spacing: 1px;
    }

    /* ── GRID DE MÓDULOS ── */
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      max-width: 860px;
      width: 100%;
    }

    .card {
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(40,167,69,.2);
      border-radius: 18px;
      padding: 32px 24px;
      text-align: center;
      text-decoration: none;
      color: var(--white);
      cursor: pointer;
      transition: transform .3s, border-color .3s, background .3s, box-shadow .3s;
      animation: fadeUp .6s ease both;
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(40,167,69,.1), transparent);
      opacity: 0;
      transition: opacity .3s;
    }

    .card:hover {
      transform: translateY(-8px);
      border-color: var(--g);
      background: rgba(40,167,69,.08);
      box-shadow: 0 20px 40px rgba(0,0,0,.4), 0 0 0 1px rgba(40,167,69,.3), inset 0 1px 0 rgba(255,255,255,.1);
    }
    .card:hover::before { opacity: 1; }

    .card:nth-child(1) { animation-delay: .1s; }
    .card:nth-child(2) { animation-delay: .2s; }
    .card:nth-child(3) { animation-delay: .3s; }

    .card-icon {
      width: 64px; height: 64px;
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 18px;
      font-size: 1.8rem;
      transition: transform .3s;
    }
    .card:hover .card-icon { transform: scale(1.15); }

    .card-icon.verde  { background: rgba(40,167,69,.2);  color: #4ade80; border: 1px solid rgba(40,167,69,.3); }
    .card-icon.azul   { background: rgba(59,130,246,.2); color: #60a5fa; border: 1px solid rgba(59,130,246,.3); }
    .card-icon.naranja{ background: rgba(251,146,60,.2); color: #fb923c; border: 1px solid rgba(251,146,60,.3); }

    .card h3 {
      font-size: 1.15rem;
      font-weight: 700;
      margin-bottom: 8px;
      color: var(--white);
    }

    .card p {
      font-size: .88rem;
      color: rgba(212,252,212,.6);
      line-height: 1.5;
    }

    .card .badge {
      display: inline-block;
      margin-top: 14px;
      padding: 5px 14px;
      border-radius: 20px;
      font-size: .78rem;
      font-weight: 700;
      letter-spacing: .5px;
    }
    .badge-verde  { background: rgba(40,167,69,.2);  color: #4ade80; border: 1px solid rgba(40,167,69,.4); }
    .badge-azul   { background: rgba(59,130,246,.2); color: #60a5fa; border: 1px solid rgba(59,130,246,.4); }
    .badge-naranja{ background: rgba(251,146,60,.2); color: #fb923c; border: 1px solid rgba(251,146,60,.4); }

    /* ── FOOTER ── */
    .footer {
      position: fixed; bottom: 0; left: 0; right: 0;
      background: rgba(10,61,26,.95);
      border-top: 1px solid rgba(40,167,69,.2);
      text-align: center;
      padding: 10px;
      font-size: .8rem;
      color: rgba(212,252,212,.5);
      z-index: 10;
      backdrop-filter: blur(10px);
    }

    /* ── ANIMACIONES ── */
    @keyframes fadeDown {
      from { opacity: 0; transform: translateY(-30px); }
      to   { opacity: 1; transform: none; }
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(30px); }
      to   { opacity: 1; transform: none; }
    }

    @media (max-width: 500px) {
      .grid { grid-template-columns: 1fr; }
      h1 { font-size: 1.8rem; }
    }
  </style>
</head>
<body>

<div class="bg"></div>

<div class="wrapper">

  <div class="header">
    <div class="logo-wrap">
      <img src="logo.png" alt="SIA-LAB" onerror="this.style.display='none'">
    </div>
    <h1>SIA-LAB</h1>
    <p class="subtitle">Sistema de Control de Producción · Laboratorio Óptico</p>
    <p class="reloj" id="reloj"></p>
  </div>

  <div class="grid">

    <!-- Empleados -->
    <a href="login.php" class="card">
      <div class="card-icon verde">
        <i class="bi bi-person-badge"></i>
      </div>
      <h3>Portal Empleados</h3>
      <p>Registro de producción, quiebras y pedidos de insumos</p>
      <span class="badge badge-verde">Empleados</span>
    </a>

    <!-- Técnicos de Paros -->
    <a href="login_paros.php" class="card">
      <div class="card-icon naranja">
        <i class="bi bi-tools"></i>
      </div>
      <h3>Portal Técnicos</h3>
      <p>Gestión de solicitudes de paro y mantenimiento de equipos</p>
      <span class="badge badge-naranja">Técnicos</span>
    </a>

    <!-- Administración -->
    <a href="login_admin.php" class="card">
      <div class="card-icon azul">
        <i class="bi bi-shield-lock"></i>
      </div>
      <h3>Administración</h3>
      <p>Dashboards de producción, paros, quiebras y gestión de empleados</p>
      <span class="badge badge-azul">Administradores</span>
    </a>

  </div>

</div>

<div class="footer">
  SIA-LAB © <?= date("Y") ?> | Nestor Rosales · Rosales_Dev91
</div>

<script>
  const DIAS  = ["Domingo","Lunes","Martes","Miércoles","Jueves","Viernes","Sábado"];
  const MESES = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
  function tick() {
    const a = new Date();
    const pad = n => String(n).padStart(2,'0');
    document.getElementById('reloj').textContent =
      `${DIAS[a.getDay()]} ${a.getDate()} ${MESES[a.getMonth()]} ${a.getFullYear()} · ${pad(a.getHours())}:${pad(a.getMinutes())}:${pad(a.getSeconds())}`;
  }
  setInterval(tick, 1000); tick();
</script>

</body>
</html>
