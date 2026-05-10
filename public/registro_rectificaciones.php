<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Verificación de acceso
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    header("Location: login_recti.php");
    exit();
}

// Verificación del token CSRF
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Token CSRF inválido.");
    }
    
    // Procesar el formulario si es válido
    if (isset($_POST['registrar_rectificacion'])) {
        $fecha = $conn->real_escape_string($_POST['fecha']);
        $hora = $conn->real_escape_string($_POST['hora']);
        $orden = $conn->real_escape_string($_POST['orden']);
        $sucursal = $conn->real_escape_string($_POST['sucursal']);
        $paciente = $conn->real_escape_string($_POST['paciente']);
        $tipo_vision = $conn->real_escape_string($_POST['tipo_vision']);
        $material = $conn->real_escape_string($_POST['material']);
        $motivo = $conn->real_escape_string($_POST['motivo']);
        $empleado_registro = $conn->real_escape_string($_SESSION['nombre_empleado']);
        
        $query = "INSERT INTO rectificaciones (fecha, hora, orden, sucursal, paciente, tipo_vision, material, motivo, empleado_registro) 
                  VALUES ('$fecha', '$hora', '$orden', '$sucursal', '$paciente', '$tipo_vision', '$material', '$motivo', '$empleado_registro')";
        
        if ($conn->query($query)) {
            $mensaje_exito = "Rectificación registrada correctamente.";
        } else {
            $mensaje_error = "Error al registrar la rectificación: " . $conn->error;
        }
    }
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Establecer zona horaria
date_default_timezone_set('America/Guatemala');
$fecha_actual = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Rectificaciones - Sistema de Control</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
    <style>
    :root {
        --primary-green: #28a745;
        --primary-dark: #155724;
        --secondary-green: #1e7e34;
        --light-green: #d4fcd4;
        --success-green: #20c997;
        --dark-green: #003300;
        --white: #ffffff;
        --light-gray: #f8f9fa;
        --border-color: #dee2e6;
        --shadow: rgba(0, 0, 0, 0.15);
        --shadow-hover: rgba(0, 0, 0, 0.25);
        --border-radius: 12px;
        --border-radius-sm: 8px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        height: 100%;
        font-family: var(--font-family);
        line-height: 1.6;
        color: var(--white);
    }

    body {
        font-family: var(--font-family);
        line-height: 1.6;
        color: var(--white);
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-green) 50%, var(--primary-green) 100%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        padding-bottom: 80px;
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

    .header-info {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .clock {
        font-size: 18px;
        font-weight: 600;
        color: var(--light-green);
        text-shadow: 0 1px 2px rgba(0,0,0,0.5);
    }

    .logout-btn {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: var(--border-radius-sm);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        box-shadow: 0 2px 10px rgba(220, 53, 69, 0.3);
    }

    .logout-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
    }

    /* Main Container */
    .main-container {
        max-width: 1400px;
        margin: 30px auto;
        padding: 0 20px 60px;
        display: grid;
        grid-template-columns: 1fr;
        gap: 30px;
    }

    .content-section {
        display: flex;
        flex-direction: column;
        gap: 25px;
    }

    /* Cards */
    .card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: var(--border-radius);
        padding: 25px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        color: var(--primary-dark);
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--border-color);
    }

    .card-header i {
        color: var(--primary-green);
        font-size: 24px;
    }

    .card-header h3 {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-dark);
    }

    /* Form Styles */
    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--primary-dark);
        font-size: 14px;
    }

    .form-control {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--border-color);
        border-radius: var(--border-radius-sm);
        font-size: 16px;
        transition: var(--transition);
        background: var(--white);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-green);
        box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: var(--border-radius-sm);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 14px;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-green), var(--success-green));
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
    }

    .btn-secondary {
        background: linear-gradient(135deg, #6c757d, #5a6268);
        color: white;
    }

    .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
    }

    /* Form Columns */
    .form-columns {
        display: flex;
        gap: 30px;
        flex-wrap: wrap;
    }

    .form-column {
        flex: 1 1 300px;
        min-width: 280px;
    }

    /* Alerts */
    .alert {
        padding: 15px 20px;
        border-radius: var(--border-radius-sm);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 500;
    }

    .alert-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
        border-left: 4px solid #28a745;
    }

    .alert-danger {
        background: linear-gradient(135deg, #f8d7da, #f1b0b7);
        color: #721c24;
        border-left: 4px solid #dc3545;
    }

/* Footer fijo */
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
    z-index: 60;
    height: 70px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

/* Deja espacio para que el footer no tape contenido */
body {
    margin: 0;
    padding-bottom: 80px;
}

.footer p {
    margin: 0;
    opacity: 0.9;
}

.footer .developer {
    font-size: 0.8rem;
    margin-top: 5px;
    opacity: 0.7;
}

    /* Support Buttons */
    .support-buttons {
        position: fixed;
        bottom: 90px;
        right: 20px;
        z-index: 1000;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .support-btn {
        background: linear-gradient(135deg, #25D366, #128C7E);
        color: white;
        padding: 15px 20px;
        border-radius: 50px;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        transition: var(--transition);
        animation: pulse 2s infinite;
    }

    .support-btn:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 8px 25px rgba(37, 211, 102, 0.4);
    }

    .support-btn.odoo {
        background: linear-gradient(135deg, #17a2b8, #138496);
        animation: none;
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
        .main-container {
            grid-template-columns: 1fr;
            gap: 20px;
        }
    }

    @media (max-width: 768px) {
        .header-content {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .main-container {
            padding: 0 15px 50px;
        }
        
        .card {
            padding: 20px;
        }
        
        .form-columns {
            flex-direction: column;
            gap: 0;
        }
        
        .form-column {
            min-width: 100%;
        }
        
        .support-buttons {
            bottom: 80px;
            right: 15px;
        }
    }

    @media (max-width: 480px) {
        .support-btn {
            padding: 12px 16px;
            font-size: 14px;
        }

        .footer {
            margin-top: 20px;
            padding: 12px 0;
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
            <div class="header-info">
                <div class="clock" id="reloj"></div>
                <a href="rectificaciones_view.php?session_id=<?php echo $_SESSION['session_id']; ?>" class="btn btn-secondary" style="margin-right: 10px;">
                    <i class="fas fa-list"></i> Ver Rectificaciones
                </a>
                <a href="login_recti.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <div class="content-section">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-user-check"></i>
                    <h3>Bienvenid@, <?php echo htmlspecialchars($_SESSION['nombre_empleado']); ?></h3>
                </div>
                
                <h2 style="color: var(--primary-dark); text-align: center; margin-bottom: 20px;">Registro de Rectificaciones</h2>

                <!-- Mostrar mensajes de éxito o error -->
                <?php if (isset($mensaje_exito)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $mensaje_exito; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($mensaje_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $mensaje_error; ?>
                    </div>
                <?php endif; ?>

                <!-- Formulario de registro de rectificaciones -->
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                     
                    <!-- Fecha y Hora -->
                    <div class="form-columns">
                        <div class="form-column">
                            <div class="form-group">
                                <label for="fecha" class="form-label">
                                    <i class="fas fa-calendar"></i>
                                    Fecha:
                                </label>
                                <input type="date" id="fecha" name="fecha" value="<?php echo $fecha_actual; ?>" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-column">
                            <div class="form-group">
                                <label for="hora_visible" class="form-label">
                                    <i class="fas fa-clock"></i>
                                    Hora:
                                </label>
                                <input type="text" id="hora_visible" class="form-control" readonly>
                                <input type="hidden" id="hora" name="hora">
                            </div>
                        </div>
                    </div>

                    <!-- Sucursal y Número de Orden -->
                    <div class="form-columns">
                        <div class="form-column">
                            <div class="form-group">
                                <label for="sucursal" class="form-label">
                                    <i class="fas fa-store"></i>
                                    Sucursal:
                                </label>
                                <select name="sucursal" id="sucursal" class="form-control" required>
                                    <option value="">-- Seleccione una sucursal --</option>
                                    <?php
                                    $resultSucursales = $conn->query("SELECT id, sucursal FROM sucursales ORDER BY sucursal");
                                    while ($row = $resultSucursales->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['sucursal']) . '">' . htmlspecialchars($row['sucursal']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-column">
                            <div class="form-group">
                                <label for="orden" class="form-label">
                                    <i class="fas fa-barcode"></i>
                                    N° de Orden:
                                </label>
                                <input type="text" name="orden" id="orden" class="form-control" required>
                            </div>
                        </div>
                    </div>

                    <!-- Paciente y Tipo de Visión -->
                    <div class="form-columns">
                        <div class="form-column">
                            <div class="form-group">
                                <label for="paciente" class="form-label">
                                    <i class="fas fa-user"></i>
                                    Paciente:
                                </label>
                                <input type="text" name="paciente" id="paciente" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-column">
                            <div class="form-group">
                                <label for="tipo_vision" class="form-label">
                                    <i class="fas fa-eye"></i>
                                    Tipo de Visión:
                                </label>
                                <select name="tipo_vision" id="tipo_vision" class="form-control" required>
                                    <option value="">-- Seleccione el tipo de visión --</option>
                                    <?php
                                    $resultTiposVision = $conn->query("SELECT id, tipo_vision FROM tipos_vision");
                                    while ($row = $resultTiposVision->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['tipo_vision']) . '">' . htmlspecialchars($row['tipo_vision']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Material y Motivo -->
                    <div class="form-columns">
                        <div class="form-column">
                            <div class="form-group">
                                <label for="material" class="form-label">
                                    <i class="fas fa-box-open"></i>
                                    Material:
                                </label>
                                <select name="material" id="material" class="form-control" required>
                                    <option value="">-- Seleccione el material --</option>
                                    <?php
                                    $resultMateriales = $conn->query("SELECT id, material FROM materiales");
                                    while ($row = $resultMateriales->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['material']) . '">' . htmlspecialchars($row['material']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-column">
                            <div class="form-group">
                                <label for="motivo" class="form-label">
                                    <i class="fas fa-question-circle"></i>
                                    Motivo:
                                </label>
                                <select name="motivo" id="motivo" class="form-control" required>
                                    <option value="">-- Seleccione un motivo --</option>
                                    <?php
                                    $resultMotivos = $conn->query("SELECT id, motivo FROM motivos");
                                    while ($row = $resultMotivos->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['motivo']) . '">' . htmlspecialchars($row['motivo']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="registrar_rectificacion" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-save"></i> Registrar Rectificación
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Support Buttons -->
    <div class="support-buttons">
        <a href="https://wa.me/50672360749?text=Hola, tengo una consulta acerca de" 
           target="_blank" 
           class="support-btn whatsapp"
           title="Contactar soporte por WhatsApp">
            <i class="fab fa-whatsapp"></i>
            <span>Soporte WhatsApp</span>
        </a>
        
        <a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" 
           target="_blank" 
           class="support-btn odoo"
           title="Soporte en Odoo">
            <i class="fas fa-comments"></i>
            <span>Soporte Odoo</span>
        </a>
    </div>

<!-- Footer fijo -->
<footer class="footer">
    <p>⚙️ Sistema de Registro de Rectificaciones © 2025</p>
    <p class="developer">Desarrollado por: Nestor Rosales | Rosales_Dev91</p>
</footer>

    <script>
        // Actualiza los campos de hora cada segundo
        function actualizarHora() {
            const ahora = new Date();

            // Hora en formato 24h para enviar al servidor
            const horas24 = String(ahora.getHours()).padStart(2, '0');
            const minutos = String(ahora.getMinutes()).padStart(2, '0');
            const segundos = String(ahora.getSeconds()).padStart(2, '0');
            const hora24 = `${horas24}:${minutos}:${segundos}`;
            document.getElementById('hora').value = hora24;

            // Hora en formato 12h para mostrar al usuario
            let horas12 = ahora.getHours() % 12 || 12;
            const ampm = ahora.getHours() >= 12 ? 'PM' : 'AM';
            const horaVisible = `${String(horas12).padStart(2, '0')}:${minutos}:${segundos} ${ampm}`;
            document.getElementById('hora_visible').value = horaVisible;
        }

        // Ejecutar al cargar la página y cada segundo
        document.addEventListener('DOMContentLoaded', () => {
            actualizarHora();
            setInterval(actualizarHora, 1000);
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

        // Evitar envío del formulario con Enter
        document.querySelector('form').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        // Limpiar formulario después del envío exitoso
        <?php if (isset($mensaje_exito)): ?>
            setTimeout(() => {
                document.querySelector('form').reset();
            }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>
<?php
// Cerrar conexión
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>