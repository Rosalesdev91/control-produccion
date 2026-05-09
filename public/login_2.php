<?php
session_start();

// ================ LOGOUT ================
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login_2.php");
    exit();
}

// ================ VALIDACIÓN DE CÓDIGO ================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['codigo_quiebras'])) {
    $codigo = trim($_POST['codigo_quiebras']);

    if (empty($codigo)) {
        $mensaje_error = "Por favor ingresa tu código de empleado.";
    } else {
        require_once '../config/database2.php';

        $stmt = $conn->prepare("SELECT id, nombre_empleado, rol FROM empleados WHERE codigo_empleado = ?");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 1) {
            $row = $resultado->fetch_assoc();

            // Permitir empleados y opcionalmente admins
            if (in_array($row['rol'], ['empleado', 'admin'])) {
                $unique_session_id = bin2hex(random_bytes(16));

                $_SESSION['codigo_empleado'] = $codigo;
                $_SESSION['nombre_empleado'] = $row['nombre_empleado'];
                $_SESSION['rol'] = $row['rol'];
                $_SESSION['session_id'] = $unique_session_id;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['login_time'] = time();

                // Cambia aquí el nombre exacto de tu dashboard
                header("Location: dashboard_quiebras_bodega.php");
                exit();
            } else {
                $mensaje_error = "Acceso no autorizado para este módulo.";
            }
        } else {
            $mensaje_error = "Código de empleado incorrecto.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIA-LAB - Registro de Quiebras</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root{--g:#28a745;--dg:#218838;--lg:#d4fcd4;--bg:#0f3a18;--glow:0 0 20px rgba(40,167,69,.8);}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--lg);font-family:'Rajdhani',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
        .grid{position:absolute;inset:0;background:linear-gradient(90deg,rgba(40,167,69,.03) 1px,transparent 1px),linear-gradient(rgba(40,167,69,.03) 1px,transparent 1px);background-size:50px 50px;animation:g 30s linear infinite}
        @keyframes g{to{background-position:50px 50px}}
        
        .card{background:rgba(255,255,255,.95);backdrop-filter:blur(16px);border:2px solid var(--g);border-radius:20px;padding:40px 30px;width:420px;max-width:92vw;box-shadow:0 15px 40px rgba(0,0,0,.4);position:relative;overflow:hidden}
        .card::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(40,167,69,.15) 1px,transparent 1px);background-size:20px 20px;animation:scan 8s linear infinite}
        @keyframes scan{to{transform:translate(50%,50%)}}
        
        .logo{margin-bottom:25px;text-align:center}
        .logo img{width:140px;height:140px;object-fit:contain;border:4px solid var(--g);border-radius:50%;padding:12px;background:rgba(40,167,69,.1);animation:float 6s ease-in-out infinite}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-15px)}}
        
        h1{font:900 2.8rem 'Orbitron',sans-serif;text-align:center;margin:20px 0;background:linear-gradient(90deg,var(--g),var(--dg));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        p.subtitle{text-align:center;font-size:1.1rem;opacity:.9;margin-bottom:30px}
        
        label{display:block;font-weight:700;font-size:1.2rem;margin-bottom:12px;color:#155724}
        .input{position:relative}
        input[type="password"],input[type="text"]{width:100%;padding:16px 50px 16px 18px;background:white;border:2px solid var(--g);border-radius:12px;font-size:1.1rem;color:#155724;transition:.3s}
        input:focus{outline:none;border-color:var(--dg);box-shadow:var(--glow)}
        
        .toggle{position:absolute;right:15px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--g);font-size:1.4rem;cursor:pointer;transition:.3s}
        .toggle:hover{transform:translateY(-50%) scale(1.3)}
        
        .btn{width:100%;margin-top:25px;padding:16px;background:linear-gradient(135deg,var(--g),var(--dg));color:white;font:700 1.3rem 'Orbitron',sans-serif;border:none;border-radius:12px;cursor:pointer;box-shadow:var(--glow);transition:.4s;display:flex;align-items:center;justify-content:center;gap:10px}
        .btn:hover{transform:translateY(-4px);box-shadow:0 0 30px rgba(40,167,69,.9)}
        
        .error{background:rgba(220,53,69,.2);color:#dc3545;padding:15px;border-radius:10px;border:1px solid #dc3545;margin-top:20px;text-align:center;font-weight:600}
        
        .footer{position:fixed;bottom:0;left:0;right:0;background:rgba(40,167,69,.1);backdrop-filter:blur(8px);color:var(--text-light);text-align:center;padding:10px;font-size:.82rem;border-top:1px solid rgba(40,167,69,.3);z-index:100;opacity:0;animation:f 1s ease-out 1.2s forwards}

    </style>
</head>
<body>

<div class="grid"></div>

<div class="card">
    <div class="logo">
        <img src="/control_produccion/public/logo.png" alt="SIA-LAB">
    </div>
    <h1>CONTROL DE QUIEBRAS</h1>
    <p class="subtitle">Acceso exclusivo - Bodega</p>

    <form method="POST">
        <label for="codigo_quiebras">Código de Empleado</label>
        <div class="input">
            <input type="password" id="codigo_quiebras" name="codigo_quiebras" placeholder="••••••••" required autocomplete="off">
            <button type="button" class="toggle" onclick="togglePass()">
                <i class="fas fa-eye" id="eye"></i>
            </button>
        </div>

        <?php if (!empty($mensaje_error)): ?>
            <div class="error"><?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>

        <button type="submit" class="btn">
            <i class="bi bi-box-arrow-in-right"></i> INGRESAR
        </button>
    </form>
</div>

    <div class="footer">
        <p><strong>Sistema de Control de Producción</strong> | © <?= date("Y") ?></p>
        <p>Desarrollado por: <strong>Nestor Rosales</strong> | Rosales_Dev91</p>
    </div>

<script>
function togglePass() {
    const input = document.getElementById('codigo_quiebras');
    const icon = document.getElementById('eye');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Foco automático y selección del texto al cargar
window.onload = () => {
    const input = document.getElementById('codigo_quiebras');
    input.focus();
    input.select();
};
</script>

</body>
</html>