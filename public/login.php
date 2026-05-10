<?php
/**
 * login.php
 * Login para empleados con registro de actividad
 * By: Nestor Rosales | Rosales_Dev91
 */

session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once 'registrar_actividad.php';

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

// Función helper para limpiar resultados pendientes
function limpiar_resultados_login($conn) {
    if (!$conn) return;
    while ($conn->more_results()) {
        $conn->next_result();
        if ($result = $conn->store_result()) {
            $result->free();
        }
    }
}

class LoginSystem {
    private $areas_disponibles = [
        'registro.php' => [
            'nombre' => 'Registro de Producción',
            'icono' => 'bi-clipboard-check',
            'descripcion' => 'Registrar órdenes de producción'
        ],
        'registro_paro.php' => [
            'nombre' => 'Registro de Paro',
            'icono' => 'bi-pause-circle',
            'descripcion' => 'Registrar paros de producción'
        ],
        'form_pedido.php' => [
            'nombre' => 'Pedidos',
            'icono' => 'bi-cart',
            'descripcion' => 'Pedidos de insumos y suministros'
        ],
        'registro_quiebras.php' => [
            'nombre' => 'Quiebras',
            'icono' => 'bi-exclamation-triangle',
            'descripcion' => 'Registro de quiebras',
            'requiere_codigo' => true
        ]
    ];

    public function getAreas() {
        return $this->areas_disponibles;
    }

    public function validarAcceso($area) {
        if (empty($area)) {
            return ['success' => false, 'mensaje' => 'Selecciona un módulo válido.'];
        }
        if (!array_key_exists($area, $this->areas_disponibles)) {
            return ['success' => false, 'mensaje' => 'Módulo no válido.'];
        }
        return ['success' => true];
    }

    public function validarCodigoQuiebras($codigo) {
        global $conn;
        
        // Limpiar resultados pendientes antes de la consulta
        limpiar_resultados_login($conn);
        
        // Usar query() en lugar de prepare() para evitar errores en Railway
        $codigo_esc = $conn->real_escape_string($codigo);
        $res = $conn->query("SELECT id, nombre_empleado, codigo_empleado, rol FROM empleados WHERE codigo_empleado = '$codigo_esc'");
        
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $res->free();
            limpiar_resultados_login($conn);

            if ($row['rol'] === 'empleado') {
                $ip = getRealIP();
                $unique_session_id = bin2hex(random_bytes(16));
                
                $_SESSION['codigo_empleado'] = $codigo;
                $_SESSION['nombre_empleado'] = $row['nombre_empleado'];
                $_SESSION['empleado'] = $row['nombre_empleado'];
                $_SESSION['rol'] = $row['rol'];
                $_SESSION['id_empleado'] = $row['id'];
                $_SESSION['session_id'] = $unique_session_id;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['ip'] = $ip;
                $_SESSION['ultimo_acceso'] = time();
                $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
                
                // Registrar actividad de login exitoso
                registrar_actividad($conn, 'login', $row['nombre_empleado'], 
                    "🔐 Empleado inició sesión en Quiebras | Código: {$codigo} | IP: {$ip}", $ip);

                return [
                    'success' => true,
                    'redirect' => "registro_quiebras.php?session_id=$unique_session_id"
                ];
            } else {
                // Registrar intento fallido (no es empleado)
                $ip = getRealIP();
                registrar_actividad($conn, 'login', $codigo, 
                    "❌ Intento fallido de acceso a Quiebras - Usuario no es empleado | IP: {$ip}", $ip);
                return ['success' => false, 'mensaje' => 'Acceso no autorizado.'];
            }
        } else {
            // Registrar intento fallido (código incorrecto)
            $ip = getRealIP();
            registrar_actividad($conn, 'login', $codigo, 
                "❌ Intento fallido de acceso a Quiebras - Código incorrecto | IP: {$ip}", $ip);
            if ($res) $res->free();
            limpiar_resultados_login($conn);
            return ['success' => false, 'mensaje' => 'Código incorrecto.'];
        }
    }

    public function iniciarSesion($area, $conn) {
        $ip = getRealIP();
        $area_nombre = $this->areas_disponibles[$area]['nombre'];
        
        // Guardar datos en sesión (para módulos sin código)
        if (!isset($_SESSION['nombre_empleado'])) {
            $_SESSION['empleado'] = 'Empleado';
            $_SESSION['rol'] = 'empleado';
            $_SESSION['ip'] = $ip;
            $_SESSION['ultimo_acceso'] = time();
            $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
        }
        
        $_SESSION['user_rol'] = 'empleado';
        $_SESSION['user_area'] = $area;
        $_SESSION['login_time'] = time();
        $_SESSION['area_info'] = $this->areas_disponibles[$area];
        
        // Registrar actividad de acceso a módulo
        $usuario = $_SESSION['nombre_empleado'] ?? $_SESSION['empleado'] ?? 'Empleado';
        registrar_actividad($conn, 'login', $usuario, 
            "🔐 Accedió al módulo: {$area_nombre} | IP: {$ip}", $ip);
    }
}

$login = new LoginSystem();
$mensaje_error = '';
$mensaje_exito = '';
$mostrar_codigo_quiebras = false;

// ---- PROCESAR CÓDIGO QUIEBRAS ----
if (isset($_POST['codigo_quiebras'])) {
    $codigo = htmlspecialchars($_POST['codigo_quiebras'] ?? '');

    if (empty($codigo)) {
        $mensaje_error = "Ingresa tu código de empleado.";
    } else {
        $validacion = $login->validarCodigoQuiebras($codigo);
        if ($validacion['success']) {
            header("Location: " . $validacion['redirect']);
            exit();
        } else {
            $mensaje_error = $validacion['mensaje'];
        }
    }
}

// ---- PROCESAR SELECCIÓN DE MÓDULO ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['codigo_quiebras'])) {
    $area = $_POST['area'] ?? '';

    if ($area === 'registro_quiebras.php') {
        $mostrar_codigo_quiebras = true;
    } else {
        $validacion = $login->validarAcceso($area);
        if ($validacion['success']) {
            $login->iniciarSesion($area, $conn);
            $mensaje_exito = "Acceso autorizado. Redirigiendo…";
            echo "<script>
                setTimeout(() => { window.location.href = '$area'; }, 1500);
            </script>";
        } else {
            $mensaje_error = $validacion['mensaje'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIA-LAB - Acceso Empleados</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root{--g:#28a745;--dg:#218838;--lg:#d4fcd4;--vdg:#003300;--glow:0 0 15px rgba(40,167,69,.7);--gi:0 0 30px rgba(40,167,69,.9);--bg-light:rgba(14,53,23,.7);--text-dark:#333;--text-light:var(--lg);--card-bg:rgba(255,255,255,.95);--input-bg:#fff;}
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%;font-family:'Rajdhani',sans-serif;background:var(--bg-light);color:var(--text-light);overflow:hidden}
        .grid{position:fixed;inset:0;background:linear-gradient(90deg,rgba(40,167,69,.05) 1px,transparent 1px),linear-gradient(rgba(40,167,69,.05) 1px,transparent 1px);background-size:40px 40px;animation:g 25s linear infinite;will-change:background-position}
        @keyframes g{to{background-position:40px 40px}}
        .particles{position:fixed;inset:0;pointer-events:none;z-index:1}
        .wrapper{max-width:1280px;margin:auto;display:grid;grid-template-columns:1fr 1fr;gap:40px;padding:30px;align-items:center;min-height:100vh;position:relative;z-index:10}
        .brand{text-align:center;opacity:0;animation:f 1s ease-out .3s forwards}
        @keyframes f{to{opacity:1;transform:none}}
        .logo{width:200px;height:200px;margin:auto auto 20px;filter:drop-shadow(0 0 10px rgba(40,167,69,.3));animation:float 5s ease-in-out infinite}
        .logo img{width:100%;height:100%;object-fit:contain;border:2px solid var(--g);border-radius:50%;padding:12px;background:rgba(40,167,69,.1);animation:r 18s linear infinite}
        @keyframes r{to{transform:rotate(360deg)}}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}
        .title{font:900 3rem 'Orbitron',monospace;letter-spacing:3px;background:linear-gradient(90deg,var(--g),var(--dg),var(--g));-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-shadow:0 0 10px rgba(40,167,69,.5);margin-bottom:12px}
        .subtitle{font-size:1.05rem;opacity:.85;line-height:1.6;margin-bottom:20px;color:var(--text-light);text-shadow:0 0 5px rgba(212,252,212,.5)}
        .features{list-style:none;margin-top:25px}
        .features li{display:flex;align-items:center;justify-content:center;gap:10px;font-size:1rem;opacity:0;animation:s .5s ease-out forwards;color:var(--text-light);text-shadow:0 0 3px rgba(212,252,212,.3)}
        .features li:nth-child(1){animation-delay:.8s}
        .features li:nth-child(2){animation-delay:.9s}
        .features li:nth-child(3){animation-delay:1s}
        .features li:nth-child(4){animation-delay:1.1s}
        @keyframes s{to{opacity:1;transform:none}}
        .features i{color:var(--g);filter:drop-shadow(0 0 4px currentColor)}
        .card{background:var(--card-bg);backdrop-filter:blur(14px);border:1px solid rgba(40,167,69,.3);border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.1);opacity:0;transform:translateY(20px);animation:c 1s cubic-bezier(.25,.8,.25,1) .5s forwards}
        @keyframes c{to{opacity:1;transform:none}}
        .header{background:linear-gradient(135deg,rgba(40,167,69,.9),rgba(33,136,56,.95));padding:30px 25px;text-align:center;position:relative;overflow:hidden;color:#fff}
        .header::before{content:'';position:absolute;inset:-50%;background:radial-gradient(circle,rgba(255,255,255,.1) 1px,transparent 1px);background-size:18px 18px;animation:scan 7s linear infinite}
        @keyframes scan{to{transform:translate(50%,50%)}}
        .header h2{font:700 1.7rem 'Orbitron',monospace;color:var(--lg);text-shadow:var(--glow);position:relative;z-index:2}
        .header p{font-size:.9rem;opacity:.9;margin-top:6px;position:relative;z-index:2}
        .body{padding:35px;color:var(--text-dark)}
        .group{margin-bottom:22px;position:relative}
        .group label{display:flex;align-items:center;gap:8px;font-weight:600;font-size:.92rem;color:var(--text-dark);margin-bottom:8px;text-shadow:none}
        .group select,.group input{width:100%;padding:13px 16px;background:var(--input-bg);border:1px solid rgba(40,167,69,.5);border-radius:10px;color:var(--text-dark);font-size:1rem;transition:.3s;backdrop-filter:blur(4px)}
        .group select:focus,.group input:focus{outline:none;border-color:var(--g);box-shadow:var(--glow);transform:translateY(-2px)}
        .group select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 14 14'%3E%3Cpath fill='%2328a745' d='M7 10L2 5h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center}
        .input{position:relative}
        .toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--g);cursor:pointer;font-size:1.1rem;transition:.3s}
        .toggle:hover{transform:translateY(-50%) scale(1.25);filter:drop-shadow(0 0 8px currentColor)}
        .btn{width:100%;padding:15px;background:linear-gradient(135deg,var(--g),var(--dg));color:#fff;font:700 1.05rem 'Orbitron',monospace;letter-spacing:1px;border:none;border-radius:10px;cursor:pointer;transition:.4s;box-shadow:var(--glow);position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;gap:8px}
        .btn::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);transform:translateX(-100%);transition:.6s}
        .btn:hover::before{transform:translateX(100%)}
        .btn:hover{transform:translateY(-3px);box-shadow:var(--gi)}
        .btn:active{transform:none}
        .error{background:rgba(220,53,69,.15);color:#dc3545;padding:12px 16px;border-radius:8px;border:1px solid #dc3545;font-size:.88rem;margin-top:18px;display:flex;align-items:center;gap:8px;animation:p .4s ease-out;box-shadow:0 0 12px rgba(220,53,69,.3)}
        .success{background:rgba(40,167,69,.15);color:var(--g);padding:12px 16px;border-radius:8px;border:1px solid var(--g);font-size:.88rem;margin-top:18px;display:flex;align-items:center;gap:8px;animation:p .4s ease-out;box-shadow:0 0 12px rgba(40,167,69,.3)}
        @keyframes p{0%{transform:scale(.95);opacity:0}100%{transform:scale(1);opacity:1}}
        .footer{position:fixed;bottom:0;left:0;right:0;background:rgba(40,167,69,.1);backdrop-filter:blur(8px);color:var(--text-light);text-align:center;padding:10px;font-size:.82rem;border-top:1px solid rgba(40,167,69,.3);z-index:100;opacity:0;animation:f 1s ease-out 1.2s forwards}
        .clock{display:inline-block;font-weight:700;color:var(--lg);text-shadow:0 0 5px rgba(40,167,69,.8)}
        @media (max-width:992px){.wrapper{grid-template-columns:1fr;gap:25px;padding:20px}.features{display:none}}
        @media (max-width:576px){.title{font-size:2.4rem}.body{padding:25px}}
        @media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;transition-duration:.01ms !important}}
    </style>
</head>
<body>

    <canvas class="particles" id="p"></canvas>
    <div class="grid"></div>

    <div class="wrapper">
        <div class="brand">
            <div class="logo"><img src="/control_produccion/public/logo.png" alt="SIA-LAB"></div>
            <h1 class="title">SIA-LAB</h1>
            <p class="subtitle">Sistema de Control de Producción - Acceso Empleados</p>
            <div class="clock" id="reloj"></div>
            <ul class="features">
                <li><i class="bi bi-upc-scan"></i> Registro de Producción</li>
                <li><i class="bi bi-pause-circle"></i> Registro de Paros</li>
                <li><i class="bi bi-cart"></i> Pedidos de Insumos</li>
                <li><i class="bi bi-exclamation-triangle"></i> Registro de Quiebras</li>
            </ul>
        </div>

        <div class="card">
            <div class="header">
                <h2>ACCESO EMPLEADOS</h2>
                <p>Selecciona el módulo al que deseas ingresar</p>
            </div>
            <div class="body">
                <?php if ($mostrar_codigo_quiebras): ?>
                    <form method="POST" id="quiebrasForm">
                        <input type="hidden" name="area" value="registro_quiebras.php">
                        <div class="group">
                            <label for="codigo_quiebras"><i class="bi bi-key"></i> Código de Empleado</label>
                            <div class="input">
                                <input type="password" id="codigo_quiebras" name="codigo_quiebras" placeholder="••••••••" required autocomplete="off">
                                <button type="button" class="toggle" onclick="togglePassword('codigo_quiebras')"><i class="fas fa-eye" id="eye-codigo_quiebras"></i></button>
                            </div>
                        </div>
                        <button type="submit" class="btn"><i class="bi bi-box-arrow-in-right"></i> VERIFICAR CÓDIGO</button>
                        <button type="button" class="btn" style="margin-top:10px; background:#6c757d;" onclick="window.location.href='login.php'"><i class="bi bi-arrow-left"></i> VOLVER</button>
                    </form>
                <?php else: ?>
                    <form method="POST" id="loginForm">
                        <div class="group">
                            <label for="area"><i class="bi bi-grid-3x3-gap-fill"></i> Módulo</label>
                            <select id="area" name="area" required>
                                <option value="">-- SELECCIONAR --</option>
                                <?php foreach ($login->getAreas() as $url => $info): ?>
                                    <option value="<?= htmlspecialchars($url) ?>">
                                        <?= htmlspecialchars($info['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn" id="loginBtn"><i class="bi bi-box-arrow-in-right"></i> INICIAR SESIÓN</button>
                    </form>
                <?php endif; ?>

                <?php if ($mensaje_error): ?>
                    <div class="error"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($mensaje_error) ?></div>
                <?php endif; ?>
                <?php if ($mensaje_exito): ?>
                    <div class="success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensaje_exito) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        <p><strong>Sistema de Control de Producción</strong> | © <?= date("Y") ?></p>
        <p>Desarrollado por: <strong>Nestor Rosales</strong> | Rosales_Dev91</p>
    </div>

    <a href="https://wa.me/50672360749?text=Hola, tengo una consulta" target="_blank" class="whatsapp-btn">
        <i class="bi bi-whatsapp"></i> Soporte
    </a>
    <a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" target="_blank" class="odoo-message-btn">
        <i class="bi bi-chat-dots"></i> Soporte Odoo
    </a>

    <style>
        .whatsapp-btn,.odoo-message-btn{position:fixed;right:20px;z-index:9999;padding:12px 18px;border-radius:30px;color:#fff;font-weight:600;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,.2);transition:.3s;display:flex;align-items:center;gap:8px}
        .whatsapp-btn{bottom:20px;background:#25D366}
        .odoo-message-btn{bottom:80px;background:#1b4f72}
        .whatsapp-btn:hover,.odoo-message-btn:hover{transform:translateY(-3px);box-shadow:0 6px 16px rgba(0,0,0,.4)}
    </style>

    <script>
        const workerCode = `
            let particles=[],w,h,ctx;
            class P{constructor(){this.x=Math.random()*w;this.y=Math.random()*h;this.s=Math.random()*1.5+.5;this.vx=Math.random()*.6-.3;this.vy=Math.random()*.6-.3}
            update(){this.x+=this.vx;this.y+=this.vy;if(this.x>w||this.x<0)this.vx*=-1;if(this.y>h||this.y<0)this.vy*=-1}
            draw(){ctx.fillStyle='rgba(40,167,69,.3)';ctx.beginPath();ctx.arc(this.x,this.y,this.s,0,Math.PI*2);ctx.fill()}}
            self.onmessage=e=>{if(e.data.type==='init'){w=e.data.w;h=e.data.h;ctx=e.data.ctx;for(let i=0;i<60;i++)particles.push(new P());animate()}
            if(e.data.type==='resize'){w=e.data.w;h=e.data.h}}
            function animate(){ctx.clearRect(0,0,w,h);particles.forEach(p=>{p.update();p.draw()});requestAnimationFrame(animate)}
        `;
        const blob = new Blob([workerCode],{type:'application/javascript'});
        const worker = new Worker(URL.createObjectURL(blob));
        const canvas = document.getElementById('p');
        const offscreen = canvas.transferControlToOffscreen();
        canvas.width = innerWidth; canvas.height = innerHeight;
        worker.postMessage({type:'init',w:innerWidth,h:innerHeight,ctx:offscreen},[offscreen]);
        addEventListener('resize',()=>{canvas.width=innerWidth;canvas.height=innerHeight;worker.postMessage({type:'resize',w:innerWidth,h:innerHeight})});

        function togglePassword(id){
            const input=document.getElementById(id);
            const icon=document.getElementById('eye-'+id);
            if(input.type==='password'){
                input.type='text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type='password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function actualizarReloj(){
            const dias=["Domingo","Lunes","Martes","Miércoles","Jueves","Viernes","Sábado"];
            const meses=["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
            const ahora=new Date();
            const txt=`${dias[ahora.getDay()]}, ${ahora.getDate()} de ${meses[ahora.getMonth()]} de ${ahora.getFullYear()} - ${ahora.getHours().toString().padStart(2,'0')}:${ahora.getMinutes().toString().padStart(2,'0')}:${ahora.getSeconds().toString().padStart(2,'0')}`;
            const reloj=document.getElementById('reloj');
            if(reloj) reloj.textContent=txt;
        }
        setInterval(actualizarReloj,1000); actualizarReloj();

        requestIdleCallback(() => {
            const select = document.querySelector('select');
            const input = document.querySelector('input[required]');
            if(select && select.options.length>0) select.focus();
            else if(input) input.focus();
        });
        
        let enviando = false;
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if(enviando) {
                    e.preventDefault();
                    return false;
                }
                enviando = true;
                setTimeout(() => { enviando = false; }, 3000);
            });
        });
    </script>
</body>
</html>