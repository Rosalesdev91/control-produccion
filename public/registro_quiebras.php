<?php
session_start();

require_once dirname(__DIR__) . '/config/database.php';
require_once 'auto_audit_empleados.php';
require_once 'registrar_actividad.php';

// Verificación de acceso
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    // Si no coincide, redirige al login
    header("Location: login.php");
    exit();
}

// Verificación del token CSRF
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Token CSRF inválido.");
    }
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function echoOptions($result, $valueField, $textField, $emptyMessage) {
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Modificar para usar el texto del campo 'textField' como valor
            echo '<option value="' . htmlspecialchars($row[$textField]) . '">' . htmlspecialchars($row[$textField]) . '</option>';
        }
    } else {
        echo '<option value="">' . $emptyMessage . '</option>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Quiebras - Sistema de Control</title>
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

    /* Graduation Table */
    .graduation-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }

    .graduation-table th {
        background-color: var(--primary-green);
        color: white;
        padding: 10px;
        text-align: center;
    }

    .graduation-table td {
        padding: 8px;
        text-align: center;
        border-bottom: 1px solid var(--border-color);
    }

    .graduation-table input {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        background: white;
        color: black;
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

    /* Footer */
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

    /* WhatsApp Button */
    .whatsapp-btn {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 9999;
        background-color: #25D366;
        padding: 10px 16px;
        border-radius: 30px;
        display: flex;
        align-items: center;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        animation: breathe 2s ease-in-out infinite;
        text-decoration: none;
        color: white;
        font-weight: bold;
    }

    /* Animations */
    @keyframes pulse {
        0% {
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        }
        50% {
            box-shadow: 0 4px 25px rgba(37, 211, 102, 0.6);
        }
        100% {
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        }
    }

    @keyframes breathe {
        0% {
            box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.5);
        }
        70% {
            box-shadow: 0 0 0 15px rgba(37, 211, 102, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(0, 0, 0, 0);
        }
    }

    @keyframes beat {
        0% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.2);
        }
        100% {
            transform: scale(1);
        }
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
        .status-card {
            flex-direction: column;
            text-align: center;
            gap: 15px;
        }
        
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

<?php
// Primero obtenemos todos los porqués de defecto con su motivo_id
$resultPorques = $conn->query("SELECT id, motivo_id, descripcion FROM porque_defecto ORDER BY motivo_id, descripcion");
$porqueDefectos = array();

while ($row = $resultPorques->fetch_assoc()) {
    $porqueDefectos[] = $row;
}
?>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="/control_produccion/public/logo.png" alt="Logo de la empresa">
            </div>
            <div class="header-info">
                <div class="clock" id="reloj"></div>
                <a href="login.php" class="logout-btn">
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
            
            <h2 style="color: var(--primary-dark); text-align: center; margin-bottom: 20px;">Registro de Quiebras (Lentes Dañados)</h2>

            <!-- Formulario único para registrar -->
            <form method="POST" action="guardar_quiebras.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="empleado_registro" value="<?php echo htmlspecialchars($_SESSION['nombre_empleado']); ?>"> 
                 
                <div class="form-group">
                    <label for="numero_orden" class="form-label">
                        <i class="fas fa-barcode"></i>
                        Número de Orden:
                    </label>
                    <input type="text" name="orden" id="orden" class="form-control" required>
                </div>

                <?php
                // Establecer zona horaria
                date_default_timezone_set('America/Guatemala');

                // Fecha actual en formato YYYY-MM-DD para el input
                $fecha_actual = date('Y-m-d');

                // Si se envió la fecha por POST, usarla; si no, usar la fecha actual
                $fecha_valor = $_POST['fecha'] ?? $fecha_actual;
                ?>

                <!-- Campo de Fecha -->
                <div class="form-group">
                    <label for="fecha" class="form-label">
                        <i class="fas fa-calendar"></i>
                        Fecha:
                    </label>
                    <input type="date" id="fecha" name="fecha" value="<?= htmlspecialchars($fecha_valor) ?>" class="form-control" readonly>
                </div>

                <!-- Campo visible para la Hora -->
                <div class="form-group">
                    <label for="hora_visible" class="form-label">
                        <i class="fas fa-clock"></i>
                        Hora:
                    </label>
                    <input type="text" id="hora_visible" class="form-control" readonly>
                </div>

                <!-- Campo oculto que se enviará al formulario con la hora en formato 24h -->
                <input type="hidden" id="hora" name="hora">

                <div class="form-columns">
                    <!-- Columna 1 -->
                    <div class="form-column">
                        <!-- Turno -->
                        <div class="form-group">
                            <label for="turno" class="form-label">
                                <i class="fas fa-clock"></i>
                                Turno:
                            </label>
                            <select name="turno" id="turno" class="form-control" required>
                                <option value="">-- Seleccione un turno --</option>
                                <?php
                                $resultTurnos = $conn->query("SELECT id, turno FROM turnos");
                                while ($row = $resultTurnos->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['turno']) . '">' . htmlspecialchars($row['turno']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Responsable (tipo) -->
                        <div class="form-group">
                            <label for="responsable" class="form-label">
                                <i class="fas fa-user-tag"></i>
                                Responsable (Tipo):
                            </label>
                            <select name="responsable" id="responsable" class="form-control" required>
                                <option value="">-- Seleccione un responsable (Tipo) --</option>
                                <?php
                                $resultResponsables = $conn->query("SELECT id, nombre FROM responsables");
                                while ($row = $resultResponsables->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['nombre']) . '">' . htmlspecialchars($row['nombre']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Nombre de Responsable (Empleado) -->
                        <div class="form-group">
                            <label for="empleado" class="form-label">
                                <i class="fas fa-user"></i>
                                Nombre del Responsable (Empleado):
                            </label>
                            <select name="empleado" id="empleado" class="form-control" required>
                                <option value="">-- Seleccione el nombre del responsable --</option>
                                <?php
                                $resultEmpleados = $conn->query("SELECT id, nombre_empleado FROM empleados");
                                while ($row = $resultEmpleados->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['nombre_empleado']) . '">' . htmlspecialchars($row['nombre_empleado']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Equipo -->
                        <div class="form-group">
                            <label for="equipo" class="form-label">
                                <i class="fas fa-cogs"></i>
                                Equipo:
                            </label>
                            <select name="equipo" id="equipo" class="form-control" required>
                                <option value="">-- Seleccione un equipo --</option>
                                <?php
                                $resultEquipos = $conn->query("SELECT id, nombre_equipo FROM equipos");
                                while ($row = $resultEquipos->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['nombre_equipo']) . '">' . htmlspecialchars($row['nombre_equipo']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <script>
                            document.getElementById('responsable').addEventListener('change', function () {
                                const valor = this.value.toLowerCase();
                                const empleado = document.getElementById('empleado');
                                const equipo = document.getElementById('equipo');

                                // Reiniciar todos a habilitado
                                empleado.disabled = false;
                                equipo.disabled = false;

                                // Aplicar condiciones
                                if (valor.includes('persona')) {
                                    empleado.disabled = false;
                                    equipo.disabled = true;
                                    equipo.value = '';
                                } else if (valor.includes('equipo')) {
                                    empleado.disabled = true;
                                    equipo.disabled = false;
                                    empleado.value = '';
                                } else if (valor.includes('material') || valor.includes('sucursal')) {
                                    empleado.disabled = true;
                                    equipo.disabled = true;
                                    empleado.value = '';
                                    equipo.value = '';
                                }
                            });
                        </script>

                        <!-- Área -->
                        <div class="form-group">
                            <label for="area" class="form-label">
                                <i class="fas fa-building"></i>
                                Área donde se detecta:
                            </label>
                            <select name="area" id="area" class="form-control" required>
                                <option value="">-- Seleccione un área --</option>
                                <?php
                                $resultAreas = $conn->query("SELECT id, area FROM areas");
                                while ($row = $resultAreas->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['area']) . '">' . htmlspecialchars($row['area']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Motivo -->
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
                                    // value = TEXTO (lo que se guardará)
                                    // data-id = ID (para filtrar en JS)
                                    echo '<option value="' . htmlspecialchars($row['motivo'], ENT_QUOTES) . '" data-id="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['motivo']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Por qué del Defecto -->
                        <div class="form-group">
                            <label for="porque_defecto" class="form-label">
                                <i class="fas fa-search"></i>
                                ¿Por qué del Defecto? (raiz)
                            </label>
                            <select name="porque_defecto" id="porque_defecto" class="form-control" required>
                                <option value="">-- Seleccione primero un motivo --</option>
                            </select>
                        </div>
                    </div>

                    <!-- Columna 2 -->
                    <div class="form-column">
                        <!-- Tipo de Visión -->
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

                        <!-- Lado del Lente -->
                        <div class="form-group">
                            <label for="lado_lente" class="form-label">
                                <i class="fas fa-glasses"></i>
                                Lado del Lente:
                            </label>
                            <select name="lado_lente" id="lado_lente" class="form-control" required>
                                <option value="">-- Seleccione el lado del lente --</option>
                                <?php
                                $resultLadosLente = $conn->query("SELECT id, lado FROM lados_lente");
                                while ($row = $resultLadosLente->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['lado']) . '">' . htmlspecialchars($row['lado']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Tipo de Montaje -->
                        <div class="form-group">
                            <label for="tipo_montaje" class="form-label">
                                <i class="fas fa-glasses"></i>
                                Tipo de Montaje:
                            </label>
                            <select name="tipo_montaje" id="tipo_montaje" class="form-control" required>
                                <option value="">-- Seleccione el tipo de montaje --</option>
                                <?php
                                $resultTiposMontaje = $conn->query("SELECT id, montaje FROM tipos_montaje");
                                while ($row = $resultTiposMontaje->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['montaje']) . '">' . htmlspecialchars($row['montaje']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Input de búsqueda -->
                        <div class="form-group">
                            <label for="buscar-material" class="form-label">
                                <i class="fas fa-search"></i>
                                Buscar material:
                            </label>
                            <input type="text" id="buscar-material" class="form-control" placeholder="Buscar material..." onkeyup="filtrarMaterial()" autocomplete="off">
                        </div>

                        <!-- Select de materiales -->
                        <div class="form-group">
                            <label for="material" class="form-label">
                                <i class="fas fa-box-open"></i>
                                Material:
                            </label>
                            <select name="material" id="material" class="form-control" required>
                                <option value="">-- Seleccione el material --</option>
                                <?php
                                $materiales = [];
                                $resultMateriales = $conn->query("SELECT id, material FROM materiales");
                                while ($row = $resultMateriales->fetch_assoc()) {
                                    $material = htmlspecialchars($row['material'], ENT_QUOTES);
                                    $materiales[] = $material;
                                    echo "<option value=\"$material\">$material</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <script>
                            // Lista original de materiales desde PHP
                            const materiales = <?php echo json_encode($materiales); ?>;

                            function filtrarMaterial() {
                                const input = document.getElementById("buscar-material");
                                const filtro = input.value.toLowerCase();
                                const select = document.getElementById("material");

                                // Guardar la primera opción por defecto
                                select.innerHTML = '<option value="">-- Seleccione el material --</option>';

                                // Agregar solo coincidencias
                                materiales.forEach(material => {
                                    if (material.toLowerCase().includes(filtro)) {
                                        const option = document.createElement("option");
                                        option.value = material;
                                        option.text = material;
                                        select.appendChild(option);
                                    }
                                });
                            }
                        </script>
                    </div>
                </div>

                <!-- Graduación -->
                <div class="form-group">
                    <fieldset style="border: 2px solid var(--primary-green); padding: 15px; border-radius: var(--border-radius-sm); background-color: #f9f9f9;">
                        <legend style="font-weight: bold; color: var(--primary-green); padding: 0 10px;">
                            <i class="fas fa-eye"></i> Graduación
                        </legend>
                        <table class="graduation-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Esfera</th>
                                    <th>Cilindro</th>
                                    <th>Adición</th>
                                    <th>Base</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>OD</strong></td>
                                    <td><input type="number" step="0.25" name="esfera_od"></td>
                                    <td><input type="number" step="0.25" name="cilindro_od"></td>
                                    <td><input type="number" step="0.25" name="adicion_od"></td>
                                    <td><input type="number" step="0.25" name="base_od"></td>
                                </tr>
                                <tr>
                                    <td><strong>OI</strong></td>
                                    <td><input type="number" step="0.25" name="esfera_oi"></td>
                                    <td><input type="number" step="0.25" name="cilindro_oi"></td>
                                    <td><input type="number" step="0.25" name="adicion_oi"></td>
                                    <td><input type="number" step="0.25" name="base_oi"></td>
                                </tr>
                            </tbody>
                        </table>
                    </fieldset>
                </div>

                <!-- Comentarios -->
                <div class="form-group">
                    <label for="comentarios_pdf" class="form-label">
                        <i class="fas fa-comment"></i>
                        Comentario de la quiebra (Solución):
                    </label>
                    <textarea name="comentarios_pdf" id="comentarios_pdf" class="form-control" rows="5" placeholder="Ingrese un comentario." required></textarea>
                </div>

                <button type="submit" name="registrar_quiebra" class="btn btn-primary" style="margin-top: 20px;">
                    <i class="fas fa-save"></i> Registrar quiebra
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const motivoSelect = document.getElementById('motivo');
    const porqueDefectoSelect = document.getElementById('porque_defecto');
    
    // Todos los porque_defectos desde PHP (debe incluir al menos: id, motivo_id, descripcion)
    const porqueDefectos = <?php echo json_encode($porqueDefectos); ?>;
    
    motivoSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const motivoId = selectedOption ? selectedOption.dataset.id : ''; // usar ID para filtrar
        // const motivoTexto = selectedOption ? selectedOption.value : ''; // texto que se guardará (si lo necesitas)

        // Limpiar el select de porqué defecto
        porqueDefectoSelect.innerHTML = '<option value="">-- Seleccione una causa --</option>';
        
        if (motivoId) {
            // Filtrar por ID de motivo
            const filteredPorques = porqueDefectos.filter(item => String(item.motivo_id) === String(motivoId));
            
            if (filteredPorques.length > 0) {
                filteredPorques.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.descripcion; // Guardar el TEXTO
                    option.textContent = item.descripcion;
                    porqueDefectoSelect.appendChild(option);
                });
            } else {
                porqueDefectoSelect.innerHTML = '<option value="">-- No hay causas para este motivo --</option>';
            }
        } else {
            porqueDefectoSelect.innerHTML = '<option value="">-- Seleccione primero un motivo --</option>';
        }
    });
});
</script>

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

    <!-- Footer -->
    <footer class="footer">
        <div>
            <i class="fas fa-cogs"></i>
            Sistema de Registro de Quiebras © <?= date("Y") ?>
        </div>
        <div class="developer">
            <i class="fas fa-code"></i>
            Desarrollado por: Nestor Rosales | Rosales_Dev91
        </div>
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

        // Limpiar formulario después del envío
        document.querySelector('form').addEventListener('submit', function (event) {
            const form = this;
            setTimeout(() => {
                form.reset();
            }, 1000);
        });
    </script>

<!-- Tracking de navegación para monitor en vivo -->
<script>
(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            modulo: pagina, 
            pagina: window.location.pathname 
        })
    }).catch(err => console.log('Tracking error:', err));
})();
</script>
</body>
</html>

<?php
if (isset($conn_produccion_quiebras) && $conn_produccion_quiebras instanceof mysqli) {
    $conn_produccion_quiebras->close();
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>