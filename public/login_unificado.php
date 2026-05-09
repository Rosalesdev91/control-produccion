<?php
session_start();

$error = '';
$rol = '';
$codigo = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $rol = $_POST['rol'] ?? '';
    $codigo = $_POST['codigo'] ?? '';

    if ($rol === 'administrador') {
        $codigo = htmlspecialchars(trim($codigo));

        require_once '../config/database.php';

        $stmt = $conn->prepare("SELECT id, nombre_empleado, rol FROM empleados WHERE codigo_empleado = ?");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows == 1) {
            $row = $resultado->fetch_assoc();

            if ($row['rol'] === 'administrador') {
                $_SESSION['empleado'] = $row['nombre_empleado'];
                $_SESSION['rol'] = $row['rol'];

                // Redirigir a un único dashboard unificado
                header("Location: dashboard_admin_unificado.php");
                exit();
            } else {
                $error = "No tienes permisos para acceder como administrador.";
            }
        } else {
            $error = "Código incorrecto o no registrado.";
        }
    } else {
        $error = "Rol no válido o no proporcionado.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Login</title>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: url('/control_produccion/public/fondo.png') no-repeat left center fixed;
            background-size:  1200px 750px;
        }

        .login-container {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            height: 100vh;
            background-color: rgba(40, 167, 69, 0.6); /* menos opaco para ver fondo */
            padding-right: 40px; /* más espacio a la derecha */
            box-sizing: border-box;
        }

        .login-box {
            background-color: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            box-sizing: border-box;
        }

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img {
            max-width: 150px;
        }

        h2 {
            text-align: center;
            color: #333;
            margin: 5px 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            display: block;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border-radius: 5px;
            border: 1px solid #ddd;
            font-size: 16px;
            margin-top: 8px;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #28a745;
            outline: none;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: #28a745;
            color: white;
            font-size: 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: #218838;
        }

        .error-message {
            color: red;
            text-align: center;
            font-size: 14px;
            margin-top: 15px;
        }

        .firma {
            text-align: center;
            font-size: 15px;
            color: #d4fcd4;
            padding: 10px 0;
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            position: fixed;
            bottom: 0;
            width: 100%;
        }
    </style>
</head>

<body>

<div class="login-container">
    <div class="login-box">

        <div class="logo">
            <img src="/control_produccion/public/logo.png" alt="Logo" />
        </div>

        <h2>Iniciar Sesión</h2>
        <h2>Control de producción</h2>

        <form method="post" id="loginForm" novalidate>
            <div class="form-group">
                <label for="rol">Rol:</label>
                <select id="rol" name="rol" required>
                    <option value="">Selecciona tu rol</option>
                    <option value="administrador" <?= $rol === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                </select>
            </div>

            <div class="form-group">
                <label for="codigo">Código del Empleado:</label>
                <input
                    type="password"
                    id="codigo"
                    name="codigo"
                    value="<?= htmlspecialchars($codigo) ?>"
                    required
                />
            </div>

            <button type="submit">Entrar</button>
        </form>

        <?php if ($error): ?>
            <p class="error-message"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Pie de página -->
<div class="firma">
  Sistema de control de producción | © <?= date("Y"); ?>
  <p>By: Nestor Rosales | Rosales_Dev91</p>
</div>

</body>
</html>