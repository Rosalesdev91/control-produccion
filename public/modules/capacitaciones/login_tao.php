<?php
/**
 * login_tao.php
 * Login para módulo TAO - Solo con código de empleado
 * Verifica rol en la base de datos (empleado, supervisor, administrador)
 */

session_start();

// Si ya hay sesión activa, redirigir
if (isset($_SESSION['empleado']) && isset($_SESSION['rol'])) {
    header("Location: capacitaciones.php");
    exit();
}

require_once '../../../config/database.php';

// Función para obtener IP real
function getRealIP() {
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    if (isset($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$error = '';
$exito = '';

// Procesar login - SOLO CON CÓDIGO
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $codigo = trim($_POST['codigo'] ?? '');
    
    if (empty($codigo)) {
        $error = "❌ Ingresa tu código de empleado.";
    } else {
        // Buscar empleado SOLO por código
        $stmt = $conn->prepare("
            SELECT id, nombre_empleado, codigo_empleado, rol 
            FROM empleados 
            WHERE codigo_empleado = ?
        ");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        if ($resultado->num_rows === 1) {
            $empleado = $resultado->fetch_assoc();
            
            $ip = getRealIP();
            $unique_session_id = bin2hex(random_bytes(16));
            
            // Crear sesión
            $_SESSION['empleado'] = $empleado['nombre_empleado'];
            $_SESSION['codigo_empleado'] = $empleado['codigo_empleado'];
            $_SESSION['nombre_empleado'] = $empleado['nombre_empleado'];
            $_SESSION['rol'] = $empleado['rol'];
            $_SESSION['id_empleado'] = $empleado['id'];
            $_SESSION['session_id'] = $unique_session_id;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['ip'] = $ip;
            $_SESSION['ultimo_acceso'] = time();
            $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
            
            // Registrar actividad (si la tabla logs_actividad existe)
            $log_stmt = $conn->prepare("
                INSERT INTO logs_actividad (empleado, accion, ip, fecha) 
                VALUES (?, 'acceso_tao', ?, NOW())
            ");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $empleado['nombre_empleado'], $ip);
                $log_stmt->execute();
            }
            
            $exito = "✅ Bienvenido {$empleado['nombre_empleado']}. Redirigiendo...";
            echo "<script>setTimeout(() => { window.location.href = 'capacitaciones.php'; }, 1500);</script>";
        } else {
            $error = "❌ Código de empleado no encontrado.";
            // Registrar intento fallido
            $ip = getRealIP();
            $log_stmt = $conn->prepare("
                INSERT INTO logs_actividad (empleado, accion, ip, fecha) 
                VALUES (?, 'intento_fallo_tao', ?, NOW())
            ");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $codigo, $ip);
                $log_stmt->execute();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TAO - Acceso Capacitaciones</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --tao: #0ea5e9;
            --tao-dark: #0284c7;
            --tao-glow: 0 0 15px rgba(14,165,233,.7);
            --tao-glow-intense: 0 0 30px rgba(14,165,233,.9);
            --bg-light: rgba(14,53,70,.7);
            --text-light: #e0f2fe;
            --card-bg: rgba(255,255,255,.95);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; font-family: 'Rajdhani', sans-serif; background: var(--bg-light); color: var(--text-light); overflow: hidden; }
        
        .grid { position: fixed; inset: 0; background: linear-gradient(90deg, rgba(14,165,233,.05) 1px, transparent 1px), linear-gradient(rgba(14,165,233,.05) 1px, transparent 1px); background-size: 40px 40px; animation: g 25s linear infinite; }
        @keyframes g { to { background-position: 40px 40px; } }
        
        .particles { position: fixed; inset: 0; pointer-events: none; z-index: 1; }
        
        .wrapper { max-width: 1280px; margin: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; padding: 30px; align-items: center; min-height: 100vh; position: relative; z-index: 10; }
        
        .brand { text-align: center; opacity: 0; animation: f 1s ease-out .3s forwards; }
        @keyframes f { to { opacity: 1; transform: none; } }
        
        .logo { width: 200px; height: 200px; margin: auto auto 20px; filter: drop-shadow(0 0 10px rgba(14,165,233,.3)); animation: float 5s ease-in-out infinite; }
        .logo img { width: 100%; height: 100%; object-fit: contain; border: 2px solid var(--tao); border-radius: 50%; padding: 12px; background: rgba(14,165,233,.1); animation: r 18s linear infinite; }
        @keyframes r { to { transform: rotate(360deg); } }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
        
        .title { font: 900 3rem 'Orbitron', monospace; letter-spacing: 3px; background: linear-gradient(90deg, var(--tao), var(--tao-dark), var(--tao)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-shadow: 0 0 10px rgba(14,165,233,.5); margin-bottom: 12px; }
        .subtitle { font-size: 1.05rem; opacity: .85; line-height: 1.6; margin-bottom: 20px; color: var(--text-light); text-shadow: 0 0 5px rgba(224,242,254,.5); }
        
        .features { list-style: none; margin-top: 25px; }
        .features li { display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 1rem; opacity: 0; animation: s .5s ease-out forwards; color: var(--text-light); }
        .features li:nth-child(1) { animation-delay: .8s; }
        .features li:nth-child(2) { animation-delay: .9s; }
        .features li:nth-child(3) { animation-delay: 1s; }
        .features li:nth-child(4) { animation-delay: 1.1s; }
        @keyframes s { to { opacity: 1; transform: none; } }
        .features i { color: var(--tao); filter: drop-shadow(0 0 4px currentColor); }
        
        .card { background: var(--card-bg); backdrop-filter: blur(14px); border: 1px solid rgba(14,165,233,.3); border-radius: 18px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.1); opacity: 0; transform: translateY(20px); animation: c 1s cubic-bezier(.25,.8,.25,1) .5s forwards; }
        @keyframes c { to { opacity: 1; transform: none; } }
        
        .header { background: linear-gradient(135deg, rgba(14,165,233,.9), rgba(2,132,199,.95)); padding: 30px 25px; text-align: center; position: relative; overflow: hidden; color: #fff; }
        .header::before { content: ''; position: absolute; inset: -50%; background: radial-gradient(circle, rgba(255,255,255,.1) 1px, transparent 1px); background-size: 18px 18px; animation: scan 7s linear infinite; }
        @keyframes scan { to { transform: translate(50%, 50%); } }
        .header h2 { font: 700 1.7rem 'Orbitron', monospace; color: #e0f2fe; text-shadow: var(--tao-glow); position: relative; z-index: 2; }
        .header p { font-size: .9rem; opacity: .9; margin-top: 6px; position: relative; z-index: 2; }
        
        .body { padding: 35px; color: #333; }
        .group { margin-bottom: 22px; position: relative; }
        .group label { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: .92rem; color: #333; margin-bottom: 8px; }
        .group input { width: 100%; padding: 13px 16px; background: #fff; border: 1px solid rgba(14,165,233,.5); border-radius: 10px; color: #333; font-size: 1rem; transition: .3s; text-align: center; letter-spacing: 2px; font-weight: bold; }
        .group input:focus { outline: none; border-color: var(--tao); box-shadow: var(--tao-glow); transform: translateY(-2px); }
        
        .btn { width: 100%; padding: 15px; background: linear-gradient(135deg, var(--tao), var(--tao-dark)); color: #fff; font: 700 1.05rem 'Orbitron', monospace; letter-spacing: 1px; border: none; border-radius: 10px; cursor: pointer; transition: .4s; box-shadow: var(--tao-glow); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255,255,255,.15), transparent); transform: translateX(-100%); transition: .6s; }
        .btn:hover::before { transform: translateX(100%); }
        .btn:hover { transform: translateY(-3px); box-shadow: var(--tao-glow-intense); }
        
        .error { background: rgba(220,53,69,.15); color: #dc3545; padding: 12px 16px; border-radius: 8px; border: 1px solid #dc3545; font-size: .88rem; margin-top: 18px; display: flex; align-items: center; gap: 8px; animation: p .4s ease-out; }
        .success { background: rgba(14,165,233,.15); color: var(--tao); padding: 12px 16px; border-radius: 8px; border: 1px solid var(--tao); font-size: .88rem; margin-top: 18px; display: flex; align-items: center; gap: 8px; animation: p .4s ease-out; }
        @keyframes p { 0% { transform: scale(.95); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        
        .footer { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(14,165,233,.1); backdrop-filter: blur(8px); color: var(--text-light); text-align: center; padding: 10px; font-size: .82rem; border-top: 1px solid rgba(14,165,233,.3); z-index: 100; opacity: 0; animation: f 1s ease-out 1.2s forwards; }
        .clock { display: inline-block; font-weight: 700; color: #e0f2fe; text-shadow: 0 0 5px rgba(14,165,233,.8); }
        
        .btn-back { background: #6c757d; margin-top: 10px; }
        .btn-back:hover { background: #5a6268; }
        
        @media (max-width: 992px) { .wrapper { grid-template-columns: 1fr; gap: 25px; padding: 20px; } .features { display: none; } }
        @media (max-width: 576px) { .title { font-size: 2.4rem; } .body { padding: 25px; } }
    </style>
</head>
<body>

    <canvas class="particles" id="p"></canvas>
    <div class="grid"></div>

    <div class="wrapper">
        <div class="brand">
            <div class="logo">
                <img src="/control_produccion/public/logo.png" alt="TAO" onerror="this.src='https://via.placeholder.com/200?text=TAO'">
            </div>
            <h1 class="title">TAO</h1>
            <p class="subtitle">Totally Aligned Organization<br>Sistema de Capacitaciones y Alineación</p>
            <div class="clock" id="reloj"></div>
            <ul class="features">
                <li><i class="bi bi-book"></i> Catálogo de Cursos</li>
                <li><i class="bi bi-graph-up"></i> Evaluación TAO</li>
                <li><i class="bi bi-trophy"></i> Certificados</li>
                <li><i class="bi bi-chat-dots"></i> Feedback Cue Cards</li>
            </ul>
        </div>

        <div class="card">
            <div class="header">
                <h2>ACCESO TAO</h2>
                <p>Ingresa con tu <strong>CÓDIGO DE EMPLEADO</strong></p>
            </div>
            <div class="body">
                <form method="POST" id="loginForm">
                    <div class="group">
                        <label for="codigo"><i class="bi bi-upc-scan"></i> Código de Empleado</label>
                        <div class="input">
                            <input type="text" id="codigo" name="codigo" placeholder="Ej: ADMIN001" required autocomplete="off" maxlength="20" style="text-transform: uppercase;">
                        </div>
                    </div>
                    <button type="submit" class="btn" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right"></i> INGRESAR
                    </button>
                </form>
                
                <a href="../../../login.php" style="text-decoration: none;">
                    <button type="button" class="btn btn-back" style="background: #6c757d; margin-top: 12px;">
                        <i class="bi bi-arrow-left"></i> VOLVER AL DASHBOARD
                    </button>
                </a>

                <?php if ($error): ?>
                    <div class="error"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($exito): ?>
                    <div class="success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($exito) ?></div>
                <?php endif; ?>
                
                <!-- Info de roles -->
                <div style="margin-top: 20px; text-align: center; font-size: 0.7rem; color: #666;">
                    <hr style="margin-bottom: 10px;">
                    <p>👑 Administrador | 👔 Supervisor | 👷 Empleado</p>
                    <p style="margin-top: 5px;">Usa tu código de empleado para acceder</p>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p><strong>Sistema TAO - Totally Aligned Organization</strong> | © <?= date("Y") ?></p>
        <p>Módulo de Capacitaciones y Alineación Organizacional</p>
    </div>

    <style>
        .whatsapp-btn, .odoo-message-btn { position: fixed; right: 20px; z-index: 9999; padding: 12px 18px; border-radius: 30px; color: #fff; font-weight: 600; text-decoration: none; box-shadow: 0 4px 12px rgba(0,0,0,.2); transition: .3s; display: flex; align-items: center; gap: 8px; }
        .whatsapp-btn { bottom: 20px; background: #25D366; }
        .odoo-message-btn { bottom: 80px; background: #1b4f72; }
        .whatsapp-btn:hover, .odoo-message-btn:hover { transform: translateY(-3px); }
    </style>

    <a href="https://wa.me/50672360749?text=Hola, tengo una consulta sobre TAO" target="_blank" class="whatsapp-btn">
        <i class="bi bi-whatsapp"></i> Soporte
    </a>

    <script>
        // Partículas
        const canvas = document.getElementById('p');
        canvas.width = innerWidth;
        canvas.height = innerHeight;
        const ctx = canvas.getContext('2d');
        let particles = [];
        
        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width;
                this.y = Math.random() * canvas.height;
                this.size = Math.random() * 2 + 0.5;
                this.vx = Math.random() * 0.6 - 0.3;
                this.vy = Math.random() * 0.6 - 0.3;
            }
            update() {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x > canvas.width || this.x < 0) this.vx *= -1;
                if (this.y > canvas.height || this.y < 0) this.vy *= -1;
            }
            draw() {
                ctx.fillStyle = 'rgba(14,165,233,0.3)';
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        
        for (let i = 0; i < 60; i++) particles.push(new Particle());
        
        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(p => { p.update(); p.draw(); });
            requestAnimationFrame(animate);
        }
        animate();
        
        window.addEventListener('resize', () => {
            canvas.width = innerWidth;
            canvas.height = innerHeight;
            particles = [];
            for (let i = 0; i < 60; i++) particles.push(new Particle());
        });
        
        // Reloj
        function actualizarReloj() {
            const dias = ["Domingo","Lunes","Martes","Miércoles","Jueves","Viernes","Sábado"];
            const meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
            const ahora = new Date();
            const txt = `${dias[ahora.getDay()]}, ${ahora.getDate()} de ${meses[ahora.getMonth()]} de ${ahora.getFullYear()} - ${ahora.getHours().toString().padStart(2,'0')}:${ahora.getMinutes().toString().padStart(2,'0')}:${ahora.getSeconds().toString().padStart(2,'0')}`;
            const reloj = document.getElementById('reloj');
            if (reloj) reloj.textContent = txt;
        }
        setInterval(actualizarReloj, 1000);
        actualizarReloj();
        
        // Prevenir envío duplicado
        let enviando = false;
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (enviando) {
                e.preventDefault();
                return false;
            }
            enviando = true;
            setTimeout(() => { enviando = false; }, 3000);
        });
        
        // Convertir a mayúsculas automáticamente
        document.getElementById('codigo').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    </script>
</body>
</html>