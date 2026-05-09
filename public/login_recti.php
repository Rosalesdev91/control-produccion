<?php
session_start();

class LoginSystem {
    private $areas_disponibles = [
        'registro_rectificaciones.php' => [
            'nombre' => 'Registro de rectificaciones',
            'icono' => 'bi-clipboard-check',
            'descripcion' => 'Registrar órdenes de rectificaciones',
            'requiere_codigo' => true // Nueva propiedad para indicar que requiere código
        ],
            'verificacion_rectificaciones.php' => [
            'nombre' => 'Verificacion de rectificaciones',
            'icono' => 'bi-clipboard-check',
            'descripcion' => 'Verificar órdenes de rectificaciones',
            'requiere_codigo' => true // Nueva propiedad para indicar que requiere código
        ]
    ];

    public function getAreas() {
        return $this->areas_disponibles;
    }

    public function validarAcceso($rol, $area) {
        if ($rol !== 'empleado' || empty($area)) {
            return ['success' => false, 'mensaje' => 'Por favor selecciona un rol y área válida.'];
        }

        if (!array_key_exists($area, $this->areas_disponibles)) {
            return ['success' => false, 'mensaje' => 'Área no válida seleccionada.'];
        }

        // Si el área requiere código, no redirigir inmediatamente
        if ($this->areas_disponibles[$area]['requiere_codigo']) {
            return ['success' => true, 'requiere_codigo' => true];
        }

        return ['success' => true, 'requiere_codigo' => false];
    }

    public function validarCodigo($codigo, $area) {
        require_once '../config/database.php';

        $stmt = $conn->prepare("SELECT id, nombre_empleado, rol FROM empleados WHERE codigo_empleado = ?");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 1) {
            $row = $resultado->fetch_assoc();

            if ($row['rol'] === 'empleado') {
                // Generar un session_id único por usuario
                $unique_session_id = bin2hex(random_bytes(16));

                // Guardar datos en sesión
                $_SESSION['codigo_empleado'] = $codigo;
                $_SESSION['nombre_empleado'] = $row['nombre_empleado'];
                $_SESSION['rol'] = $row['rol'];
                $_SESSION['session_id'] = $unique_session_id;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                return [
                    'success' => true,
                    'redirect' => "$area?session_id=$unique_session_id"
                ];
            } else {
                return ['success' => false, 'mensaje' => 'Acceso no autorizado. Solo empleados pueden acceder a esta sección.'];
            }
        } else {
            return ['success' => false, 'mensaje' => 'Código incorrecto.'];
        }
    }

    public function iniciarSesion($rol, $area) {
        $_SESSION['user_rol'] = $rol;
        $_SESSION['user_area'] = $area;
        $_SESSION['login_time'] = time();
        $_SESSION['area_info'] = $this->areas_disponibles[$area];
    }
}

$login = new LoginSystem();
$mensaje_error = '';
$mensaje_exito = '';
$mostrar_codigo = false;
$area_requiere_codigo = '';

// Procesar formulario de código para áreas que lo requieren
if (isset($_POST['codigo_empleado']) && isset($_POST['area_codigo'])) {
    $codigo = htmlspecialchars($_POST['codigo_empleado'] ?? '');
    $area = $_POST['area_codigo'];

    if (empty($codigo)) {
        $mensaje_error = "Por favor ingresa tu código de empleado";
        $mostrar_codigo = true;
        $area_requiere_codigo = $area;
    } else {
        $validacion = $login->validarCodigo($codigo, $area);
        
        if ($validacion['success']) {
            header("Location: " . $validacion['redirect']);
            exit();
        } else {
            $mensaje_error = $validacion['mensaje'];
            $mostrar_codigo = true;
            $area_requiere_codigo = $area;
        }
    }
}

// Procesar formulario normal para selección de área
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['codigo_empleado'])) {
    $rol = $_POST['rol'] ?? '';
    $area = $_POST['area'] ?? '';

    $validacion = $login->validarAcceso($rol, $area);
    
    if ($validacion['success']) {
        if ($validacion['requiere_codigo']) {
            // Mostrar formulario de código
            $mostrar_codigo = true;
            $area_requiere_codigo = $area;
        } else {
            $login->iniciarSesion($rol, $area);
            $mensaje_exito = "Acceso autorizado. Redirigiendo...";
            
            // Pequeña pausa para mostrar el mensaje de éxito
            echo "<script>
                setTimeout(function() {
                    window.location.href = '$area';
                }, 1500);
            </script>";
        }
    } else {
        $mensaje_error = $validacion['mensaje'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Registro y Control de Rectificaciones - Acceso</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #155724;
            --secondary-green: #1e7e34;
            --accent-green: #28a745;
            --light-green: #d4fcd4;
            --dark-green: #003300;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #dee2e6;
            --shadow: rgba(0, 0, 0, 0.1);
            --error-red: #dc3545;
            --success-green: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 50%, var(--accent-green) 100%);
            position: relative;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: rgba(0, 51, 0, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid var(--primary-green);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }

        .logo-container img {
            height: 60px;
            filter: brightness(1.1) drop-shadow(0 2px 4px rgba(0,0,0,0.3));
        }

        .clock {
            font-size: 18px;
            font-weight: 600;
            color: var(--light-green);
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding-bottom: 100px; /* Espacio para el footer */
        }

        .login-container {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 160px); /* Ajustado para considerar header y footer */
            padding: 40px 20px;
        }

        .login-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            animation: slideIn 0.8s ease-out;
            margin-bottom: 20px;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header-text {
            text-align: center;
            margin-bottom: 30px;
        }

        .header-text h1 {
            color: var(--primary-green);
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .header-text h2 {
            color: var(--secondary-green);
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .header-text p {
            color: #666;
            font-size: 0.95rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: var(--white);
        }

        .form-control:focus {
            border-color: var(--accent-green);
            outline: none;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent-green), var(--secondary-green));
            color: var(--white);
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(40, 167, 69, 0.4);
        }

        .btn-login .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid var(--white);
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Messages */
        .message {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.5s ease-out;
        }

        .message-error {
            background-color: #f8d7da;
            color: var(--error-red);
            border-left: 4px solid #dc3545;
        }

        .message-success {
            background-color: #d4edda;
            color: var(--success-green);
            border-left: 4px solid #28a745;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Areas Info */
        .areas-info {
            margin-top: 30px;
            padding: 20px;
            background-color: var(--light-gray);
            border-radius: 12px;
            border-left: 4px solid var(--accent-green);
        }

        .areas-info h4 {
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .area-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
            transition: all 0.2s ease;
        }

        .area-item:last-child {
            border-bottom: none;
        }

        .area-item:hover {
            transform: translateX(5px);
        }

        .area-item i {
            margin-right: 10px;
            font-size: 1.1rem;
            color: var(--accent-green);
        }

        .area-name {
            font-weight: 600;
            flex: 1;
        }

        .area-description {
            font-size: 0.85rem;
            color: #6c757d;
            font-style: italic;
        }

        /* Footer - Fijo en la parte inferior */
        .footer {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: var(--light-green);
            text-align: center;
            padding: 15px;
            font-size: 0.9rem;
            border-top: 1px solid rgba(212, 252, 212, 0.2);
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 10;
            height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .footer p {
            margin: 0;
        }

        .footer .developer {
            font-size: 0.8rem;
            margin-top: 5px;
            opacity: 0.8;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .login-box {
                padding: 30px;
            }
            
            .header-text h1 {
                font-size: 1.5rem;
            }
            
            .area-description {
                display: none;
            }
            
            .footer {
                height: auto;
                padding: 10px;
            }
        }

        @media (max-width: 480px) {
            .login-box {
                padding: 25px 20px;
            }
            
            .header-text h1 {
                font-size: 1.3rem;
            }
            
            .form-group label {
                font-size: 0.95rem;
            }
            
            .form-control {
                padding: 12px 15px;
            }
            
            .clock {
                font-size: 14px;
            }
            
            .logo-container img {
                height: 50px;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="/control_produccion/public/logo.png" alt="Logo de la empresa">
            </div>
            <div class="clock" id="reloj"></div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-content">
        <div class="login-container">
            <div class="login-box">
                <div class="header-text">
                    <h1><i class="bi bi-shield-check"></i> Acceso al Sistema</h1>
                    <h2>Registro y control de rectificaciones</h2>
                    <p>Ingrese sus credenciales para continuar</p>
                </div>

                <?php if ($mostrar_codigo && !empty($area_requiere_codigo)): ?>
                    <!-- Formulario específico para áreas que requieren código -->
                    <form method="POST" id="codigoForm">
                        <input type="hidden" name="area_codigo" value="<?= htmlspecialchars($area_requiere_codigo) ?>">
                        <input type="hidden" name="rol" value="empleado">
                        
                        <div class="form-group">
                            <label for="codigo_empleado">
                                <i class="bi bi-key"></i>
                                Código de Empleado:
                            </label>
                            <input type="password" id="codigo_empleado" name="codigo_empleado" class="form-control" required placeholder="Ingresa tu código de acceso">
                            <small class="text-muted">Solo personal autorizado puede acceder a esta área</small>
                        </div>

                        <button type="submit" class="btn-login">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span class="btn-text">Verificar Código</span>
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Formulario normal para selección de área -->
                    <form method="POST" id="loginForm">
                        <div class="form-group">
                            <label for="rol">
                                <i class="bi bi-person-badge"></i>
                                Rol de Usuario:
                            </label>
                            <select id="rol" name="rol" class="form-control" required>
                                <option value="">Selecciona tu rol</option>
                                <option value="empleado">👤 Empleado</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="area">
                                <i class="bi bi-building"></i>
                                Área de Trabajo:
                            </label>
                            <select id="area" name="area" class="form-control" required>
                                <option value="">Selecciona el área</option>
                                <?php foreach ($login->getAreas() as $url => $info): ?>
                                    <option value="<?= htmlspecialchars($url) ?>">
                                        <?= htmlspecialchars($info['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn-login" id="loginBtn">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span class="btn-text">Iniciar Sesión</span>
                            <div class="spinner"></div>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!empty($mensaje_error)): ?>
                    <div class="message message-error">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?= htmlspecialchars($mensaje_error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($mensaje_exito)): ?>
                    <div class="message message-success">
                        <i class="bi bi-check-circle"></i>
                        <?= htmlspecialchars($mensaje_exito) ?>
                    </div>
                <?php endif; ?>

                <div class="areas-info">
                    <h4>
                        <i class="bi bi-info-circle"></i>
                        Áreas Disponibles:
                    </h4>
                    <?php foreach ($login->getAreas() as $url => $info): ?>
                        <div class="area-item">
                            <i class="<?= $info['icono'] ?>"></i>
                            <span class="area-name"><?= htmlspecialchars($info['nombre']) ?></span>
                            <span class="area-description"><?= htmlspecialchars($info['descripcion']) ?></span>
                            <?php if (isset($info['requiere_codigo']) && $info['requiere_codigo']): ?>
                                <span class="badge bg-warning" style="margin-left: 10px;">Requiere código</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Fijo -->
    <footer class="footer">
        <p>Sistema de Control de Rectificaciones © <?= date("Y") ?></p>
        <p class="developer">Desarrollado por Nestor Rosales | Rosales_Dev91</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const btnText = loginBtn.querySelector('.btn-text');
            const spinner = loginBtn.querySelector('.spinner');
            const rolSelect = document.getElementById('rol');
            const areaSelect = document.getElementById('area');

            // Manejo del envío del formulario
            form.addEventListener('submit', function(e) {
                const rol = rolSelect.value;
                const area = areaSelect.value;

                if (!rol || !area) {
                    e.preventDefault();
                    mostrarError('Por favor completa todos los campos');
                    return;
                }

                // Mostrar estado de carga
                loginBtn.disabled = true;
                btnText.style.display = 'none';
                spinner.style.display = 'block';
            });

            // Validación en tiempo real
            rolSelect.addEventListener('change', function() {
                validarFormulario();
            });

            areaSelect.addEventListener('change', function() {
                validarFormulario();
            });

            function validarFormulario() {
                const rol = rolSelect.value;
                const area = areaSelect.value;

                if (rol && area) {
                    loginBtn.style.opacity = '1';
                } else {
                    loginBtn.style.opacity = '0.8';
                }
            }

            function mostrarError(mensaje) {
                // Crear elemento de error temporal
                const errorDiv = document.createElement('div');
                errorDiv.className = 'message message-error';
                errorDiv.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ${mensaje}`;
                
                // Remover error anterior si existe
                const errorAnterior = form.querySelector('.message-error');
                if (errorAnterior) {
                    errorAnterior.remove();
                }
                
                // Agregar nuevo error
                form.appendChild(errorDiv);
                
                // Scroll hacia el error
                errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Remover después de 5 segundos
                setTimeout(() => {
                    if (errorDiv.parentNode) {
                        errorDiv.remove();
                    }
                }, 5000);
            }

            // Efectos de hover
            const formControls = document.querySelectorAll('.form-control');
            formControls.forEach(control => {
                control.addEventListener('mouseenter', function() {
                    this.style.borderColor = 'var(--accent-green)';
                });

                control.addEventListener('mouseleave', function() {
                    if (!this.matches(':focus')) {
                        this.style.borderColor = 'var(--border-color)';
                    }
                });
            });

            // Auto-focus en el primer campo
            setTimeout(() => {
                rolSelect.focus();
            }, 500);
        });

        // Reloj en vivo
        function actualizarReloj() {
            const dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
            const meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio",
                "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

            const ahora = new Date();
            const diaSemana = dias[ahora.getDay()];
            const dia = ahora.getDate();
            const mes = meses[ahora.getMonth()];
            const año = ahora.getFullYear();

            const horas = ahora.getHours().toString().padStart(2, '0');
            const minutos = ahora.getMinutes().toString().padStart(2, '0');
            const segundos = ahora.getSeconds().toString().padStart(2, '0');

            const fechaHora = `${diaSemana}, ${dia} de ${mes} de ${año} - ${horas}:${minutos}:${segundos}`;
            const relojElemento = document.getElementById('reloj');
            if (relojElemento) {
                relojElemento.textContent = fechaHora;
            }
        }
        setInterval(actualizarReloj, 1000);
        actualizarReloj();
    </script>
</body>
</html>