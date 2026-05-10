<?php
declare(strict_types=1);

// CONFIGURACIÓN DE SESIÓN COMPATIBLE CON IPHONE, ANDROID Y TODOS LOS NAVEGADORES
$lifetime = 86400 * 7; // 7 días de sesión

$is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || 
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $is_https,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Función helper para limpiar resultados pendientes
function limpiar_resultados_login_paros($conn) {
    if (!$conn) return;
    while ($conn->more_results()) {
        $conn->next_result();
        if ($result = $conn->store_result()) {
            $result->free();
        }
    }
}

// Configuración de base de datos
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Costa_Rica');

// Limpiar mensaje de error anterior
$mensaje_error = $_SESSION['mensaje_error'] ?? '';
unset($_SESSION['mensaje_error']);

// Generar o reutilizar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $mensaje_error = "Error de seguridad. Intenta nuevamente.";
    } else {
        try {
            $contrasena = trim($_POST['contrasena'] ?? '');

            if ($contrasena === '') {
                throw new Exception("La contraseña es obligatoria.");
            }

            // Limpiar resultados pendientes antes de la consulta
            limpiar_resultados_login_paros($conn);
            
            // Usar query() en lugar de prepare() para evitar errores en Railway
            $contrasena_esc = $conn->real_escape_string($contrasena);
            $res = $conn->query("SELECT id, nombre_tecnico FROM tecnicos WHERE contrasena = '$contrasena_esc' AND activo = 1 LIMIT 1");
            
            if (!$res || $res->num_rows === 0) {
                throw new Exception("Contraseña incorrecta o técnico no encontrado.");
            }

            $row = $res->fetch_assoc();
            $res->free();
            limpiar_resultados_login_paros($conn);
            
            $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Login exitoso - Guardar en sesión
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['id_tecnico'] = $row['id'];
            $_SESSION['nombre_tecnico'] = $row['nombre_tecnico'];
            $_SESSION['es_tecnico'] = true;
            $_SESSION['login_time'] = time();

            // Redirigir al panel
            header("Location: solicitudes_paro.php");
            exit;

        } catch (Exception $e) {
            error_log("Error login_paros.php: " . $e->getMessage());
            $mensaje_error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Técnicos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #28a745;
            --dark: #155724;
            --success: #20c997;
            --danger: #dc3545;
            --light: #f8f9fa;
            --white: #ffffff;
            --radius: 12px;
            --radius-sm: 8px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #0f5132, #1e7e34, #28a745);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #333;
        }
        .login-box {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            border-radius: var(--radius);
            padding: 40px 30px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.3);
        }
        .logo {
            display: block;
            margin: 0 auto 25px;
            max-width: 110px;
            height: auto;
        }
        h3 {
            text-align: center;
            color: var(--dark);
            margin-bottom: 25px;
            font-size: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #dee2e6;
            border-radius: var(--radius-sm);
            font-size: 16px;
            transition: all 0.3s;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(40,167,69,0.15);
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--success));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40,167,69,0.3);
        }
        .alert {
            padding: 15px;
            border-radius: var(--radius-sm);
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid var(--danger);
            margin-bottom: 20px;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <img src="/control_produccion/public/logo.png" alt="Logo" class="logo">
        
        <h3>
            <i class="fas fa-sign-in-alt"></i>
            Iniciar Sesión - Técnicos
        </h3>

        <?php if ($mensaje_error): ?>
            <div class="alert">
                <strong>Error:</strong> <?= htmlspecialchars($mensaje_error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="form-group">
                <label for="contrasena">
                    Contraseña
                </label>
                <input 
                    type="password" 
                    id="contrasena" 
                    name="contrasena" 
                    class="form-control" 
                    required 
                    autocomplete="current-password"
                    autofocus>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-sign-in-alt"></i>
                Iniciar Sesión
            </button>
        </form>
    </div>
</body>
</html>