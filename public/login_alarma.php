<?php
// ============================================================
//  login_alarma.php  —  Login/Logout para Administradores
// ============================================================
session_start();
require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('America/Costa_Rica');

// ── LOGOUT ───────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login_alarma.php");
    exit();
}

// ── YA LOGUEADO ──────────────────────────────────────────────
if (isset($_SESSION['empleado']) && isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador') {
    header("Location: admin_alarma.php");
    exit();
}

$error = '';

// ── POST: VALIDAR CREDENCIALES ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = trim($_POST['codigo'] ?? '');

    if (empty($codigo)) {
        $error = 'Por favor, ingrese el código de empleado.';
    } else {
        $stmt = $conn->prepare(
            "SELECT nombre_empleado, codigo_empleado, rol
             FROM empleados WHERE codigo_empleado = ? AND rol = 'administrador'"
        );
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $u = $res->fetch_assoc();
            $_SESSION['empleado']         = $u['nombre_empleado'];
            $_SESSION['codigo_empleado']  = $u['codigo_empleado'];
            $_SESSION['nombre_empleado']  = $u['nombre_empleado'];
            $_SESSION['rol']              = $u['rol'];
            $_SESSION['ip']               = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $stmt->close();
            header("Location: admin_alarma.php");
            exit();
        } else {
            $error = 'Código no válido o sin permisos de administrador.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Administrador — Alarma de Calidad</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:    #185FA5;
            --blue-dk: #0e4275;
            --red:     #B91C1C;
            --border:  #dde1e7;
            --text:    #111827;
            --muted:   #6b7280;
            --radius:  14px;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--blue-dk) 0%, #071e37 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container { max-width: 400px; width: 100%; }

        .card {
            background: #fff;
            border-radius: var(--radius);
            padding: 36px 32px 28px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }

        .logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .logo-icon { font-size: 52px; display: block; margin-bottom: 12px; }
        .logo h1   { font-size: 22px; color: var(--blue); font-weight: 700; }
        .logo p    { font-size: 13px; color: var(--muted); margin-top: 4px; }

        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 7px;
        }
        .form-group input {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 15px;
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
            transition: border-color .2s, box-shadow .2s;
            text-transform: uppercase;
            color: var(--text);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(24,95,165,.12);
        }

        .btn-login {
            width: 100%;
            background: var(--blue);
            color: #fff;
            border: none;
            padding: 13px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s, transform .1s;
            margin-top: 6px;
        }
        .btn-login:hover  { background: var(--blue-dk); }
        .btn-login:active { transform: scale(.98); }

        .error-msg {
            background: #FEE2E2;
            color: var(--red);
            border-left: 4px solid var(--red);
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
        }

        .hint {
            font-size: 12px;
            color: var(--muted);
            text-align: center;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: rgba(255,255,255,.7);
            text-decoration: none;
            font-size: 13px;
            transition: color .2s;
        }
        .back-link:hover { color: #fff; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="logo">
            <span class="logo-icon">⚙</span>
            <h1>Alarma de Calidad</h1>
            <p>Acceso de Administrador</p>
        </div>

        <?php if ($error): ?>
        <div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <div class="form-group">
                <label>Código de empleado</label>
                <input type="text" name="codigo"
                       placeholder="CÓDIGO"
                       value="<?= htmlspecialchars($_POST['codigo'] ?? '') ?>"
                       autofocus required maxlength="50">
            </div>
            <button type="submit" class="btn-login">🔐 Ingresar al Panel</button>
        </form>

        <div class="hint">
            Solo administradores pueden acceder a esta sección.<br>
            Los operarios no necesitan iniciar sesión.
        </div>
    </div>

    <a href="../index.php" class="back-link">← Volver al inicio</a>
</div>
</body>
</html>
