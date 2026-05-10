<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

class LoginPickingSystem {
    private $modulos_disponibles = [
        'registro_picking.php' => [
            'nombre' => 'Registro de Picking',
            'icono' => 'bi-upc-scan',
            'descripcion' => 'Escaneo de referencias y registro de producción',
            'color' => '#28a745',
            'acceso_libre' => true // Nuevo: indica que no requiere autenticación
        ],
        'admin_produccion_picking.php' => [
            'nombre' => 'Panel Administrativo',
            'icono' => 'bi-speedometer2',
            'descripcion' => 'Dashboard, empleados, procesos y producción',
            'color' => '#17a2b8',
            'requiere_admin' => true
        ]
    ];

    public function getModulos() {
        return $this->modulos_disponibles;
    }

    public function validarAcceso($modulo) {
        if (empty($modulo)) {
            return ['success' => false, 'mensaje' => 'Selecciona un módulo válido.'];
        }
        if (!array_key_exists($modulo, $this->modulos_disponibles)) {
            return ['success' => false, 'mensaje' => 'Módulo no válido.'];
        }
        return ['success' => true];
    }
    
    // Nuevo método: verificar si el módulo es de acceso libre
    public function esAccesoLibre($modulo) {
        return isset($this->modulos_disponibles[$modulo]['acceso_libre']) && 
               $this->modulos_disponibles[$modulo]['acceso_libre'] === true;
    }

    public function autenticarEmpleado($codigo, $modulo) {
        global $conn;
        
        $stmt = $conn->prepare("SELECT id, nombre_empleado, codigo_empleado, rol FROM empleados_picking WHERE codigo_empleado = ? AND activo = 1");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 1) {
            $row = $resultado->fetch_assoc();
            $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            
            // Verificar si tiene permisos para el módulo seleccionado
            if ($modulo === 'admin_produccion.php' && $row['rol'] !== 'admin') {
                return ['success' => false, 'mensaje' => 'No tienes permisos de administrador para acceder a este módulo.'];
            }
            
            // Generar sesión única
            $session_id = bin2hex(random_bytes(16));
            $csrf_token = bin2hex(random_bytes(32));
            
            $_SESSION['picking_empleado_id'] = $row['id'];
            $_SESSION['picking_codigo'] = $row['codigo_empleado'];
            $_SESSION['picking_nombre'] = $row['nombre_empleado'];
            $_SESSION['picking_rol'] = $row['rol'];
            $_SESSION['picking_session_id'] = $session_id;
            $_SESSION['picking_csrf'] = $csrf_token;
            $_SESSION['picking_login_time'] = time();
            $_SESSION['picking_autenticado'] = true;
            
            // Si es admin, también guardar sesión de admin
            if ($row['rol'] === 'admin') {
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_usuario'] = $row['codigo_empleado'];
                $_SESSION['admin_nombre'] = $row['nombre_empleado'];
                $_SESSION['admin_session_id'] = $session_id;
                $_SESSION['admin_login_time'] = time();
                $_SESSION['admin_autenticado'] = true;
            }
            
            return [
                'success' => true,
                'empleado' => $row['nombre_empleado'],
                'rol' => $row['rol'],
                'session_id' => $session_id,
                'modulo' => $modulo
            ];
        } else {
            return ['success' => false, 'mensaje' => 'Código de empleado incorrecto o usuario inactivo.'];
        }
    }

    public function logout() {
        unset(
            $_SESSION['picking_empleado_id'],
            $_SESSION['picking_codigo'],
            $_SESSION['picking_nombre'],
            $_SESSION['picking_rol'],
            $_SESSION['picking_session_id'],
            $_SESSION['picking_csrf'],
            $_SESSION['picking_autenticado'],
            $_SESSION['admin_id'],
            $_SESSION['admin_usuario'],
            $_SESSION['admin_nombre'],
            $_SESSION['admin_session_id'],
            $_SESSION['admin_autenticado']
        );
        session_destroy();
    }
}

$login = new LoginPickingSystem();
$mensaje_error = '';
$mensaje_exito = '';
$modulo_seleccionado = $_POST['modulo'] ?? $_GET['modulo'] ?? '';
$redireccion_inmediata = false; // Nueva variable para redirección inmediata

// =============================================
// PROCESAR LOGOUT
// =============================================
if (isset($_GET['logout'])) {
    $login->logout();
    header("Location: login_picking.php");
    exit();
}

// =============================================
// PROCESAR LOGIN DE EMPLEADO (SOLO PARA ADMIN)
// =============================================
if (isset($_POST['login_empleado'])) {
    $codigo = trim($_POST['codigo_empleado'] ?? '');
    $modulo = trim($_POST['modulo_seleccionado'] ?? '');
    
    if (empty($codigo)) {
        $mensaje_error = "Ingresa tu código de empleado.";
    } elseif (empty($modulo)) {
        $mensaje_error = "Error: Módulo no especificado.";
    } else {
        $auth = $login->autenticarEmpleado($codigo, $modulo);
        if ($auth['success']) {
            $mensaje_exito = "Acceso autorizado. Bienvenido, {$auth['empleado']}.";
            $url_destino = $auth['modulo'] . "?session_id={$auth['session_id']}";
            echo "<script>
                setTimeout(() => { window.location.href = '{$url_destino}'; }, 1500);
            </script>";
        } else {
            $mensaje_error = $auth['mensaje'];
        }
    }
}

// =============================================
// PROCESAR SELECCIÓN DE MÓDULO
// =============================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['seleccionar_modulo'])) {
    $modulo_seleccionado = $_POST['modulo'] ?? '';
    $validacion = $login->validarAcceso($modulo_seleccionado);
    if (!$validacion['success']) {
        $mensaje_error = $validacion['mensaje'];
        $modulo_seleccionado = '';
    } else {
        // Verificar si es acceso libre (Registro de Picking)
        if ($login->esAccesoLibre($modulo_seleccionado)) {
            $redireccion_inmediata = true;
        }
    }
}

// =============================================
// REDIRECCIÓN INMEDIATA PARA MÓDULOS DE ACCESO LIBRE
// =============================================
if ($redireccion_inmediata && !empty($modulo_seleccionado)) {
    $session_id = bin2hex(random_bytes(16)); // Generar session_id para tracking
    
    // Establecer sesión mínima para el módulo de acceso libre
    $_SESSION['picking_modulo_libre'] = true;
    $_SESSION['picking_session_id'] = $session_id;
    $_SESSION['picking_acceso_temporal'] = time();
    
    $url_destino = $modulo_seleccionado . "?acceso_libre=1&session_id={$session_id}";
    header("Location: " . $url_destino);
    exit();
}

// Estadísticas para el panel lateral
$stats = [];
if ($conn) {
    $hoy = date('Y-m-d');
    $result = $conn->query("SELECT COUNT(*) as total FROM produccion_picking WHERE DATE(fecha) = '$hoy'");
    $stats['hoy'] = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM empleados_picking");
    $stats['empleados'] = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM procesos_picking");
    $stats['procesos'] = $result->fetch_assoc()['total'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM produccion_picking");
    $stats['total'] = $result->fetch_assoc()['total'] ?? 0;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PICKING · Control de Producción</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        /* [MANTENER TODOS LOS ESTILOS EXISTENTES - SIN CAMBIOS] */
        :root {
            --primary: #00c853;
            --primary-dark: #00a844;
            --primary-light: #d4fcd4;
            --primary-glow: 0 0 20px rgba(0, 200, 83, 0.7);
            --primary-glow-intense: 0 0 35px rgba(0, 200, 83, 0.9);
            --bg-dark: #0a1f0a;
            --bg-card: rgba(18, 30, 18, 0.85);
            --text-light: #e8f5e9;
            --text-dark: #1b3b1b;
            --border-glow: 1px solid rgba(0, 200, 83, 0.4);
            --shadow-cyber: 0 0 30px rgba(0, 200, 83, 0.2);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
            font-family: 'Rajdhani', sans-serif;
            background: radial-gradient(circle at 20% 30%, #0e2b0e, #030803);
            color: var(--text-light);
            overflow: hidden;
            position: relative;
        }
        
        .matrix-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, rgba(0,200,83,0.02) 1px, transparent 1px),
                        linear-gradient(0deg, rgba(0,200,83,0.02) 1px, transparent 1px);
            background-size: 35px 35px;
            pointer-events: none;
            z-index: 0;
            animation: matrixMove 20s linear infinite;
        }
        
        @keyframes matrixMove {
            0% { background-position: 0 0; }
            100% { background-position: 35px 35px; }
        }
        
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }
        
        .particle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0.3;
            box-shadow: 0 0 6px var(--primary);
            animation: float 15s infinite ease-in-out;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0); opacity: 0.2; }
            50% { transform: translateY(-30px) translateX(10px); opacity: 0.6; }
        }
        
        .wrapper {
            max-width: 1300px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 40px;
            padding: 40px;
            align-items: center;
            min-height: 100vh;
            position: relative;
            z-index: 10;
        }
        
        .brand {
            text-align: center;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 1s ease-out 0.3s forwards;
            backdrop-filter: blur(10px);
            background: rgba(0, 40, 0, 0.3);
            border-radius: 30px;
            padding: 40px 30px;
            border: var(--border-glow);
            box-shadow: var(--shadow-cyber);
        }
        
        @keyframes fadeInUp {
            to { opacity: 1; transform: translateY(0); }
        }
        
        .logo-container {
            width: 180px;
            height: 180px;
            margin: 0 auto 25px;
            position: relative;
        }
        
        .logo-ring {
            position: absolute;
            top: -10px;
            left: -10px;
            right: -10px;
            bottom: -10px;
            border: 2px solid transparent;
            border-top-color: var(--primary);
            border-right-color: var(--primary);
            border-radius: 50%;
            animation: spin 4s linear infinite;
        }
        
        .logo-ring-inner {
            position: absolute;
            top: 5px;
            left: 5px;
            right: 5px;
            bottom: 5px;
            border: 2px solid transparent;
            border-bottom-color: var(--primary);
            border-left-color: var(--primary);
            border-radius: 50%;
            animation: spin 6s linear infinite reverse;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .logo {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
            background: rgba(0, 200, 83, 0.1);
            padding: 15px;
            border: 1px solid rgba(0, 200, 83, 0.5);
            animation: pulseGlow 3s infinite;
        }
        
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(0,200,83,0.3); }
            50% { box-shadow: 0 0 40px rgba(0,200,83,0.6); }
        }
        
        .title {
            font: 900 3rem 'Orbitron', monospace;
            background: linear-gradient(135deg, #00e676, #00c853, #00a844);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: 3px;
            margin-bottom: 10px;
            text-shadow: 0 0 15px rgba(0,200,83,0.5);
        }
        
        .badge-picking {
            display: inline-block;
            background: rgba(0,200,83,0.2);
            border: 1px solid var(--primary);
            color: var(--primary-light);
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: bold;
            margin-bottom: 20px;
            letter-spacing: 2px;
            text-transform: uppercase;
            box-shadow: 0 0 10px rgba(0,200,83,0.3);
        }
        
        .stats-grid {
            display: flex;
            justify-content: space-around;
            margin: 30px 0;
            gap: 15px;
        }
        
        .stat-item {
            background: rgba(0,0,0,0.3);
            border-radius: 12px;
            padding: 15px 10px;
            flex: 1;
            border: 1px solid rgba(0,200,83,0.2);
            backdrop-filter: blur(5px);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 900;
            color: var(--primary);
            text-shadow: 0 0 15px var(--primary);
            line-height: 1;
        }
        
        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--primary-light);
            opacity: 0.9;
        }
        
        .features {
            list-style: none;
            margin-top: 25px;
        }
        
        .features li {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 12px;
            opacity: 0;
            animation: slideIn 0.5s ease-out forwards;
            color: var(--text-light);
        }
        
        .features li:nth-child(1) { animation-delay: 0.8s; }
        .features li:nth-child(2) { animation-delay: 0.9s; }
        .features li:nth-child(3) { animation-delay: 1s; }
        .features li:nth-child(4) { animation-delay: 1.1s; }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .features i {
            color: var(--primary);
            filter: drop-shadow(0 0 5px currentColor);
            font-size: 1.2rem;
        }
        
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(0,200,83,0.3);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5), var(--shadow-cyber);
            opacity: 0;
            transform: translateY(30px);
            animation: cardAppear 0.8s cubic-bezier(0.25,0.8,0.25,1) 0.5s forwards;
            position: relative;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, transparent, rgba(0,200,83,0.1), transparent);
            border-radius: 24px;
            z-index: -1;
        }
        
        @keyframes cardAppear {
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card-header {
            background: linear-gradient(135deg, rgba(0,200,83,0.9), rgba(0,150,60,0.95));
            padding: 30px 25px;
            text-align: center;
            position: relative;
            overflow: hidden;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .card-header::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            animation: scan 15s linear infinite;
            opacity: 0.3;
        }
        
        @keyframes scan {
            from { transform: translateY(0) rotate(0); }
            to { transform: translateY(-30px) rotate(1deg); }
        }
        
        .card-header h2 {
            font: 700 1.8rem 'Orbitron', monospace;
            color: white;
            text-shadow: 0 0 15px rgba(0,0,0,0.5);
            letter-spacing: 3px;
            margin-bottom: 5px;
        }
        
        .card-header p {
            font-size: 0.95rem;
            opacity: 0.95;
            color: #e8f5e9;
        }
        
        .card-body {
            padding: 35px;
            color: var(--text-light);
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--primary-light);
            margin-bottom: 8px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .form-group label i {
            color: var(--primary);
            font-size: 1rem;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 18px;
            background: rgba(10, 20, 10, 0.7);
            border: 1px solid rgba(0,200,83,0.4);
            border-radius: 12px;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(4px);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,200,83,0.2), 0 0 20px rgba(0,200,83,0.4);
            transform: translateY(-2px);
            background: rgba(20, 40, 20, 0.8);
        }
        
        .input-group {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 1.1rem;
            transition: 0.3s;
        }
        
        .toggle-password:hover {
            transform: translateY(-50%) scale(1.2);
            filter: drop-shadow(0 0 8px currentColor);
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            font: 700 1.1rem 'Orbitron', monospace;
            letter-spacing: 2px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: 0.4s;
            box-shadow: 0 0 20px rgba(0,200,83,0.4);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 0 30px rgba(0,200,83,0.7);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-back {
            background: rgba(255,255,255,0.1);
            box-shadow: none;
            border: 1px solid rgba(0,200,83,0.5);
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.15);
            box-shadow: 0 0 15px rgba(0,200,83,0.3);
        }
        
        .alert {
            padding: 15px 18px;
            border-radius: 12px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: alertPulse 0.5s ease-out;
            backdrop-filter: blur(10px);
        }
        
        .alert-error {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid #ff6b6b;
            color: #ffb3b3;
            box-shadow: 0 0 15px rgba(220,53,69,0.3);
        }
        
        .alert-success {
            background: rgba(0, 200, 83, 0.15);
            border: 1px solid var(--primary);
            color: var(--primary-light);
            box-shadow: 0 0 15px rgba(0,200,83,0.3);
        }
        
        @keyframes alertPulse {
            0% { transform: scale(0.95); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .clock-container {
            font-family: 'Orbitron', monospace;
            font-size: 1.2rem;
            margin-top: 20px;
            padding: 12px;
            background: rgba(0,0,0,0.3);
            border-radius: 50px;
            border: 1px solid rgba(0,200,83,0.3);
            display: inline-block;
            color: var(--primary-light);
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 30, 0, 0.7);
            backdrop-filter: blur(10px);
            color: var(--text-light);
            text-align: center;
            padding: 12px;
            font-size: 0.85rem;
            border-top: 1px solid rgba(0,200,83,0.3);
            z-index: 100;
            opacity: 0;
            animation: fadeIn 1s ease-out 1.5s forwards;
        }
        
        @keyframes fadeIn {
            to { opacity: 1; }
        }
        
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: rgba(0,200,83,0.6);
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(0,200,83,0.3);
        }
        
        .divider span {
            padding: 0 15px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .badge-modulo {
            display: inline-block;
            padding: 5px 15px;
            background: rgba(0,200,83,0.15);
            border-radius: 20px;
            font-size: 0.8rem;
            border: 1px solid rgba(0,200,83,0.4);
            margin-bottom: 15px;
        }
        
        .modulo-info {
            background: rgba(0,200,83,0.1);
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        .modulo-libre-badge {
            display: inline-block;
            background: rgba(0,200,83,0.3);
            border: 1px solid var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-left: 10px;
            text-transform: uppercase;
            vertical-align: middle;
        }
        
        @media (max-width: 992px) {
            .wrapper { grid-template-columns: 1fr; gap: 25px; }
            .brand { order: 2; }
            .card { order: 1; }
        }
        
        @media (max-width: 576px) {
            .wrapper { padding: 20px; }
            .title { font-size: 2.2rem; }
            .card-body { padding: 25px; }
            .stats-grid { flex-direction: column; }
        }
    </style>
</head>
<body>

    <!-- Fondo matrix -->
    <div class="matrix-bg"></div>
    
    <!-- Partículas generadas por JS -->
    <div class="particles" id="particles"></div>

    <div class="wrapper">
        <!-- PANEL IZQUIERDO - BRANDING Y ESTADÍSTICAS -->
        <div class="brand">
            <div class="logo-container">
                <div class="logo-ring"></div>
                <div class="logo-ring-inner"></div>
                <img src="/control_produccion/public/logo.png" alt="Picking System" class="logo" onerror="this.src='https://via.placeholder.com/180x180?text=PICKING'">
            </div>
            
            <span class="badge-picking">
                <i class="bi bi-upc-scan"></i> PICKING SYSTEM v2.0
            </span>
            
            <h1 class="title">PICK·CTRL</h1>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($stats['hoy'] ?? 0) ?></div>
                    <div class="stat-label">HOY</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($stats['empleados'] ?? 0) ?></div>
                    <div class="stat-label">EMPLEADOS</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($stats['procesos'] ?? 0) ?></div>
                    <div class="stat-label">PROCESOS</div>
                </div>
            </div>
            
            <div class="clock-container" id="reloj">
                <?= date('H:i:s') ?>
            </div>
            
            <ul class="features">
                <li><i class="bi bi-upc-scan"></i> Escaneo de referencias</li>
                <li><i class="bi bi-bar-chart"></i> Producción en tiempo real</li>
                <li><i class="bi bi-people"></i> Control de empleados</li>
                <li><i class="bi bi-gear"></i> Gestión de procesos</li>
            </ul>
        </div>

        <!-- PANEL DERECHO - LOGIN / SELECCIÓN -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <?php if (!empty($modulo_seleccionado) && !$redireccion_inmediata): ?>
                        <i class="bi bi-person-badge"></i> 
                        <?= $modulo_seleccionado === 'admin_produccion.php' ? 'ACCESO ADMINISTRADOR' : 'ACCESO EMPLEADO' ?>
                    <?php else: ?>
                        <i class="bi bi-box-seam"></i> SISTEMA PICKING
                    <?php endif; ?>
                </h2>
                <p>
                    <?php if (!empty($modulo_seleccionado) && !$redireccion_inmediata): ?>
                        Ingresa tu código de empleado
                    <?php else: ?>
                        Selecciona el módulo al que deseas acceder
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="card-body">
                
                <?php if (!empty($modulo_seleccionado) && !$redireccion_inmediata): ?>
                    <!-- ========== FORMULARIO LOGIN SOLO PARA ADMIN ========== -->
                    <?php 
                    $modulo_info = $login->getModulos()[$modulo_seleccionado] ?? null;
                    if ($modulo_info): 
                    ?>
                    <div class="modulo-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong><?= htmlspecialchars($modulo_info['nombre']) ?>:</strong> 
                        <?= htmlspecialchars($modulo_info['descripcion']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="modulo_seleccionado" value="<?= htmlspecialchars($modulo_seleccionado) ?>">
                        
                        <div class="form-group">
                            <label for="codigo_empleado">
                                <i class="bi bi-upc-scan"></i> Código de Empleado
                            </label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control" 
                                       id="codigo_empleado" 
                                       name="codigo_empleado" 
                                       placeholder="Ingresa tu código" 
                                       required 
                                       autofocus
                                       autocomplete="off">
                                <button type="button" class="toggle-password" onclick="togglePassword('codigo_empleado')">
                                    <i class="fas fa-eye" id="eye-codigo_empleado"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" name="login_empleado" class="btn">
                            <i class="bi bi-box-arrow-in-right"></i> INGRESAR
                        </button>
                        
                        <button type="button" class="btn btn-back" onclick="window.location.href='login_picking.php'">
                            <i class="bi bi-arrow-left"></i> VOLVER
                        </button>
                    </form>
                    
                <?php else: ?>
                    <!-- ========== SELECCIÓN DE MÓDULO ========== -->
                    <form method="POST">
                        <div class="form-group">
                            <label for="modulo">
                                <i class="bi bi-grid-3x3-gap-fill"></i> Módulo
                            </label>
                            <select class="form-control" id="modulo" name="modulo" required>
                                <option value="">-- SELECCIONAR MÓDULO --</option>
                                <?php foreach ($login->getModulos() as $url => $modulo): ?>
                                    <option value="<?= htmlspecialchars($url) ?>" 
                                            style="background: #0a1f0a; color: white;">
                                        <?= htmlspecialchars($modulo['nombre']) ?>
                                        <?php if ($login->esAccesoLibre($url)): ?>
                                            <span class="modulo-libre-badge">ACCESO DIRECTO</span>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($login->esAccesoLibre($modulo_seleccionado)): ?>
                                <small style="display: block; margin-top: 10px; color: var(--primary);">
                                    <i class="bi bi-info-circle"></i> Este módulo es de acceso directo, no requiere código de empleado.
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <button type="submit" name="seleccionar_modulo" class="btn">
                            <i class="bi bi-arrow-right-circle"></i> CONTINUAR
                        </button>
                    </form>
                    
                    <div class="divider">
                        <span>INFORMACIÓN</span>
                    </div>
                    
                    <div style="text-align: center; color: rgba(212,252,212,0.8); font-size: 0.9rem;">
                        <i class="bi bi-upc-scan"></i> Registro de picking - Acceso directo<br>
                        <i class="bi bi-shield-lock"></i> Panel Administrativo - Requiere autenticación
                    </div>
                <?php endif; ?>
                
                <!-- Mensajes de error/éxito -->
                <?php if ($mensaje_error): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?= htmlspecialchars($mensaje_error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($mensaje_exito): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        <?= htmlspecialchars($mensaje_exito) ?>
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="footer">
        <p><strong>SISTEMA DE CONTROL DE PRODUCCIÓN · PICKING</strong> | © <?= date("Y") ?> NESTOR ROSALES | ROSALES_DEV91</p>
    </div>

    <!-- Botones flotantes -->
    <a href="https://wa.me/50672360749?text=Hola, necesito soporte técnico - Sistema Picking" target="_blank" style="position: fixed; bottom: 80px; right: 20px; background: #25D366; color: white; padding: 14px 22px; border-radius: 50px; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); z-index: 9999; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.2);">
        <i class="bi bi-whatsapp" style="font-size: 1.4rem;"></i> Soporte Técnico
    </a>
    
    <a href="https://grnoma.odoo.com/web" target="_blank" style="position: fixed; bottom: 150px; right: 20px; background: #1b4f72; color: white; padding: 14px 22px; border-radius: 50px; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); z-index: 9999; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.2);">
        <i class="bi bi-chat-dots" style="font-size: 1.4rem;"></i> Soporte Odoo
    </a>

    <script>
        // =========================================
        // PARTÍCULAS FLOTANTES
        // =========================================
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const count = 60;
            
            for (let i = 0; i < count; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
                const x = Math.random() * 100;
                const y = Math.random() * 100;
                const size = Math.random() * 3 + 1;
                const delay = Math.random() * 10;
                const duration = Math.random() * 10 + 10;
                
                particle.style.left = x + '%';
                particle.style.top = y + '%';
                particle.style.width = size + 'px';
                particle.style.height = size + 'px';
                particle.style.animationDelay = delay + 's';
                particle.style.animationDuration = duration + 's';
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // =========================================
        // RELOJ DIGITAL
        // =========================================
        function actualizarReloj() {
            const ahora = new Date();
            const horas = ahora.getHours().toString().padStart(2, '0');
            const minutos = ahora.getMinutes().toString().padStart(2, '0');
            const segundos = ahora.getSeconds().toString().padStart(2, '0');
            document.getElementById('reloj').innerHTML = `${horas}:${minutos}:${segundos}`;
        }
        
        // =========================================
        // TOGGLE PASSWORD
        // =========================================
        function togglePassword(id) {
            const input = document.getElementById(id);
            const eyeIcon = document.getElementById('eye-' + id);
            
            if (input.type === 'password') {
                input.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
        
        // =========================================
        // INICIALIZACIÓN
        // =========================================
        window.onload = function() {
            createParticles();
            actualizarReloj();
            setInterval(actualizarReloj, 1000);
            
            setTimeout(() => {
                const firstInput = document.querySelector('input, select');
                if (firstInput) firstInput.focus();
            }, 600);
        };
        
        // =========================================
        // SEGURIDAD: Prevenir volver atrás después de logout
        // =========================================
        if (window.performance && window.performance.navigation.type === 2) {
            window.location.href = 'login_picking.php';
        }
    </script>
    
    <script src="https://kit.fontawesome.com/your-kit-id.js" crossorigin="anonymous"></script>
    <script>
        if (typeof fa === 'undefined') {
            document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">');
        }
    </script>
</body>
</html>