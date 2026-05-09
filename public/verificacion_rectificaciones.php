<?php
session_start();
require_once '../config/database.php';

// Configuración inicial
$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');

// Verificación de acceso - Solo verificadores
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    header("Location: login_recti.php");
    exit();
}

// Validar y sanitizar session_id
$session_id = filter_input(INPUT_GET, 'session_id', FILTER_SANITIZE_STRING);
if (!$session_id || $session_id !== $_SESSION['session_id']) {
    header("Location: login_recti.php");
    exit();
}

// Inicializar variables de mensaje
$mensaje_escaneo = "";
$mensaje_exito = "";
$mensaje_error = "";

// Procesar búsqueda por escaneo de orden (debe ir PRIMERO que la verificación)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['buscar_orden'])) {
    $orden_escaneada = filter_input(INPUT_POST, 'orden_escaneada', FILTER_SANITIZE_STRING);
    
    if (!empty($orden_escaneada)) {
        // Buscar la orden en la base de datos
        $query = "SELECT * FROM rectificaciones WHERE orden = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $orden_escaneada);
        $stmt->execute();
        $resultado_orden = $stmt->get_result();
        
        if ($resultado_orden->num_rows > 0) {
            $orden = $resultado_orden->fetch_assoc();
            
            // Verificar si ya fue verificada
            if (!empty($orden['verificada_por'])) {
                $_SESSION['mensaje_escaneo'] = "La orden #$orden_escaneada ya fue verificada.";
            } else {
                // Abrir modal con la orden
                $_SESSION['abrir_modal'] = $orden['id'];
            }
        } else {
            $_SESSION['mensaje_escaneo'] = "La orden #$orden_escaneada no existe.";
        }
        $stmt->close();
        
        // Redirigir para evitar reenvío del formulario
        header("Location: verificacion_rectificaciones.php?session_id=" . $session_id);
        exit();
    }
}

// Procesar verificación de rectificación (debe ir DESPUÉS de la búsqueda)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verificar_rectificacion'])) {
    // Validar y sanitizar todos los inputs
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $lado = filter_input(INPUT_POST, 'lado', FILTER_SANITIZE_STRING);
    $solucion = filter_input(INPUT_POST, 'solucion', FILTER_SANITIZE_STRING);
    $responsable = filter_input(INPUT_POST, 'responsable', FILTER_SANITIZE_STRING);
    $responsable_final = filter_input(INPUT_POST, 'responsable_final', FILTER_SANITIZE_STRING);
    
    if (!$id || !$lado || !$solucion || !$responsable || !$responsable_final) {
        $_SESSION['mensaje_error'] = "Datos de entrada inválidos. Por favor, verifique la información.";
        header("Location: verificacion_rectificaciones.php?session_id=" . $session_id);
        exit();
    } else {
        $verificada_por = $_SESSION['nombre_empleado'];
        $fecha_verificacion = date('Y-m-d');
        $hora_verificacion = date('H:i:s');
        
        // Usar consultas preparadas para prevenir inyección SQL
        $query = "UPDATE rectificaciones SET 
                  verificada_por = ?, 
                  lado = ?, 
                  solucion = ?,
                  responsable = ?,
                  responsable_final = ?,
                  fecha_verificacion = ?,
                  hora_verificacion = ?
                  WHERE id = ?";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("sssssssi", $verificada_por, $lado, $solucion, $responsable, 
                             $responsable_final, $fecha_verificacion, $hora_verificacion, $id);
            
            if ($stmt->execute()) {
                // Guardar mensaje de éxito en sesión
                $_SESSION['mensaje_exito'] = "Rectificación verificada correctamente.";
                
                // Redirigir a esta misma página para evitar reenvío de formulario
                header("Location: verificacion_rectificaciones.php?session_id=" . $session_id . "&pdf=" . $id);
                exit();
            } else {
                // Log del error sin exponer detalles sensibles
                error_log("Error al verificar rectificación: " . $stmt->error);
                $_SESSION['mensaje_error'] = "Error al procesar la verificación. Por favor, intente nuevamente.";
                header("Location: verificacion_rectificaciones.php?session_id=" . $session_id);
                exit();
            }
            $stmt->close();
        } else {
            error_log("Error preparando consulta: " . $conn->error);
            $_SESSION['mensaje_error'] = "Error en el sistema. Por favor, contacte al administrador.";
            header("Location: verificacion_rectificaciones.php?session_id=" . $session_id);
            exit();
        }
    }
}

// Mostrar mensajes de éxito o error desde sesión
if (isset($_SESSION['mensaje_exito'])) {
    $mensaje_exito = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);
}

if (isset($_SESSION['mensaje_error'])) {
    $mensaje_error = $_SESSION['mensaje_error'];
    unset($_SESSION['mensaje_error']);
}

// Mostrar mensaje de escaneo desde sesión
if (isset($_SESSION['mensaje_escaneo'])) {
    $mensaje_escaneo = $_SESSION['mensaje_escaneo'];
    unset($_SESSION['mensaje_escaneo']);
}

// Consulta base - Solo rectificaciones no verificadas
$query = "SELECT * FROM rectificaciones WHERE verificada_por IS NULL OR verificada_por = ''";
$whereConditions = [];
$params = [];
$types = "";

// Búsqueda y filtrado
$search = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
    $whereConditions[] = "(orden LIKE ? OR paciente LIKE ? OR material LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= "sss";
}

if (isset($_GET['fecha_inicio']) && !empty($_GET['fecha_inicio'])) {
    $fecha_inicio = filter_input(INPUT_GET, 'fecha_inicio', FILTER_SANITIZE_STRING);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio)) {
        $whereConditions[] = "fecha >= ?";
        $params[] = $fecha_inicio;
        $types .= "s";
    }
}

if (isset($_GET['fecha_fin']) && !empty($_GET['fecha_fin'])) {
    $fecha_fin = filter_input(INPUT_GET, 'fecha_fin', FILTER_SANITIZE_STRING);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) {
        $whereConditions[] = "fecha <= ?";
        $params[] = $fecha_fin;
        $types .= "s";
    }
}

// Construir la consulta completa
if (count($whereConditions) > 0) {
    $query .= " AND " . implode(" AND ", $whereConditions);
}

// Siempre ordenar por fecha de registro descendente
$query .= " ORDER BY fecha_registro DESC";

// Ejecutar la consulta con parámetros seguros
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    error_log("Error preparando consulta: " . $conn->error);
    $result = false;
}

// Obtener datos para los selects
$lados_result = $conn->query("SELECT id, lado FROM lados_lente");
$responsables_result = $conn->query("SELECT id, nombre FROM responsables");
$responsables_final_result = $conn->query("SELECT id, nombre_empleado FROM empleados");

// Verificar si debemos abrir un modal automáticamente
$abrir_modal_id = null;
if (isset($_SESSION['abrir_modal'])) {
    $abrir_modal_id = $_SESSION['abrir_modal'];
    unset($_SESSION['abrir_modal']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Rectificaciones - Sistema de Control</title>
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

    /* Alert Styles */
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: var(--border-radius-sm);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .alert-warning {
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }

    .alert-info {
        background-color: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
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

    .btn-warning {
        background: linear-gradient(135deg, #ffc107, #e0a800);
        color: white;
    }

    .btn-warning:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 193, 7, 0.3);
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

    /* Table Styles */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
        border-radius: var(--border-radius-sm);
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .data-table th {
        background-color: var(--primary-green);
        color: white;
        padding: 12px 15px;
        text-align: left;
        font-weight: 600;
    }

    .data-table td {
        padding: 10px 15px;
        border-bottom: 1px solid var(--border-color);
        color: var(--primary-dark);
    }

    .data-table tr:nth-child(even) {
        background-color: #f8f9fa;
    }

    .data-table tr:hover {
        background-color: #e9ecef;
    }

    .search-container {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 20px;
        align-items: end;
    }

    .search-field {
        flex: 1;
        min-width: 200px;
    }

    .table-container {
        overflow-x: auto;
    }

    .pagination {
        display: flex;
        justify-content: center;
        margin-top: 20px;
        gap: 10px;
    }

    .pagination a {
        padding: 8px 15px;
        background: var(--primary-green);
        color: white;
        text-decoration: none;
        border-radius: 5px;
        transition: var(--transition);
    }

    .pagination a:hover {
        background: var(--secondary-green);
    }

    .pagination a.active {
        background: var(--dark-green);
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
    }

    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        border-radius: var(--border-radius);
        width: 80%;
        max-width: 800px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        color: var(--primary-dark);
    }

    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover,
    .close:focus {
        color: black;
        text-decoration: none;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--border-color);
    }

    .modal-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-dark);
    }

    /* Scanner Section */
    .scanner-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: var(--border-radius);
        padding: 20px;
        margin-bottom: 25px;
        border: 2px solid var(--border-color);
    }

    .scanner-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
        color: var(--primary-dark);
    }

    .scanner-title i {
        font-size: 24px;
        color: var(--primary-green);
    }

    .scanner-form {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    .scanner-input {
        flex: 1;
        min-width: 250px;
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
        
        .search-container {
            flex-direction: column;
        }
        
        .search-field {
            min-width: 100%;
        }
        
        .data-table {
            font-size: 14px;
        }
        
        .data-table th,
        .data-table td {
            padding: 8px 10px;
        }

        .modal-content {
            width: 95%;
            margin: 10% auto;
        }

        .scanner-form {
            flex-direction: column;
        }

        .scanner-input {
            min-width: 100%;
        }
    }

    @media (max-width: 480px) {
        .data-table {
            font-size: 12px;
        }
        
        .data-table th,
        .data-table td {
            padding: 6px 8px;
        }
        
        .btn {
            padding: 10px 15px;
            font-size: 12px;
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
                <div style="color: var(--light-green); margin-right: 15px;">
                    <i class="fas fa-user-check"></i> Verificador: <?php echo htmlspecialchars($_SESSION['nombre_empleado']); ?>
                </div>
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
                    <i class="fas fa-check-circle"></i>
                    <h3>Verificación de Rectificaciones</h3>
                </div>
                
                <!-- Mostrar mensajes de éxito o error -->
                <?php if (!empty($mensaje_exito)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($mensaje_exito); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($mensaje_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensaje_error); ?>
                    </div>
                <?php endif; ?>

                <!-- Mostrar mensaje de escaneo -->
                <?php if (!empty($mensaje_escaneo)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($mensaje_escaneo); ?>
                    </div>
                <?php endif; ?>

                <!-- Sección de escaneo de código de barras -->
                <div class="scanner-section">
                    <div class="scanner-title">
                        <i class="fas fa-barcode"></i>
                        <h4>Escanear Número de Orden</h4>
                    </div>
                    <form method="POST" action="" class="scanner-form">
                        <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($session_id); ?>">
                        <div class="scanner-input">
                            <label for="orden_escaneada" class="form-label">Número de orden:</label>
                            <input type="text" id="orden_escaneada" name="orden_escaneada" class="form-control" 
                                   placeholder="Escanee o ingrese el número de orden" required
                                   autofocus>
                        </div>
                        <div class="scanner-input" style="display: flex; align-items: flex-end;">
                            <button type="submit" name="buscar_orden" class="btn btn-primary">
                                <i class="fas fa-search"></i> Buscar Orden
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabla de registros -->
                <div class="table-container">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Orden</th>
                                    <th>Sucursal</th>
                                    <th>Paciente</th>
                                    <th>Material</th>
                                    <th>Motivo</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['fecha']); ?></td>
                                        <td><?php echo htmlspecialchars($row['orden']); ?></td>
                                        <td><?php echo htmlspecialchars($row['sucursal']); ?></td>                                       
                                        <td><?php echo htmlspecialchars($row['paciente']); ?></td>
                                        <td><?php echo htmlspecialchars($row['material']); ?></td>
                                        <td><?php echo htmlspecialchars($row['motivo']); ?></td>
                                        <td>
                                            <button class="btn btn-warning btn-sm" onclick="abrirModal(<?php echo (int)$row['id']; ?>)">
                                                <i class="fas fa-check"></i> Verificar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: var(--primary-dark);">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px; color: var(--success-green);"></i>
                            <h3>No hay rectificaciones pendientes</h3>
                            <p>Todas las rectificaciones han sido evaluadas y verificadas.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para verificar rectificación -->
    <div id="modalVerificar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Verificar Rectificación</h2>
                <span class="close">&times;</span>
            </div>
            <form id="formVerificar" method="POST" action="">
                <input type="hidden" name="id" id="verificar_id">
                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($session_id); ?>">
                
                <div class="form-columns">
                    <div class="form-column">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user-check"></i>
                                Verificada por:
                            </label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['nombre_empleado']); ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="form-column">
                        <div class="form-group">
                            <label for="verificar_lado" class="form-label">
                                <i class="fas fa-glasses"></i>
                                Lado:
                            </label>
                            <select name="lado" id="verificar_lado" class="form-control" required>
                                <option value="">-- Seleccione el lado --</option>
                                <?php
                                while ($row = $lados_result->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['lado']) . '">' . htmlspecialchars($row['lado']) . '</option>';
                                }
                                $lados_result->data_seek(0);
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-columns">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="verificar_responsable" class="form-label">
                                <i class="fas fa-user-tag"></i>
                                Responsable:
                            </label>
                            <select name="responsable" id="verificar_responsable" class="form-control" required>
                                <option value="">-- Seleccione el responsable --</option>
                                <?php
                                while ($row = $responsables_result->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['nombre']) . '">' . htmlspecialchars($row['nombre']) . '</option>';
                                }
                                $responsables_result->data_seek(0);
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-column">
                        <div class="form-group">
                            <label for="verificar_responsable_final" class="form-label">
                                <i class="fas fa-user-tag"></i>
                                Responsable Final:
                            </label>
                            <select name="responsable_final" id="verificar_responsable_final" class="form-control" required>
                                <option value="">-- Seleccione el responsable final --</option>
                                <?php
                                while ($row = $responsables_final_result->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['nombre_empleado']) . '">' . htmlspecialchars($row['nombre_empleado']) . '</option>';
                                }
                                $responsables_final_result->data_seek(0);
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="verificar_solucion" class="form-label">
                        <i class="fas fa-wrench"></i>
                        Solución aplicada:
                    </label>
                    <textarea name="solucion" id="verificar_solucion" class="form-control" rows="3" required placeholder="Describa la solución aplicada..."></textarea>
                </div>

                <button type="submit" name="verificar_rectificacion" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> Confirmar Verificación
                </button>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <i class="fas fa-cogs"></i>
            Sistema de Verificación de Rectificaciones © <?= date("Y") ?>
        </div>
        <div class="developer">
            <i class="fas fa-code"></i>
            Desarrollado por: Nestor Rosales | Rosales_Dev91
        </div>
    </footer>

<script>
    // Funcionalidad del modal
    const modal = document.getElementById("modalVerificar");
    const span = document.getElementsByClassName("close")[0];

    span.onclick = function() {
        modal.style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    function abrirModal(id) {
        // Obtener datos de la rectificación mediante AJAX
        fetch(`obtener_rectificacion.php?id=${id}&session_id=<?php echo htmlspecialchars($session_id); ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Llenar el formulario con los datos
                    document.getElementById('verificar_id').value = data.id;
                    
                    // Mostrar el modal
                    modal.style.display = "block";
                } else {
                    alert('Error al cargar los datos de la rectificación');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al cargar los datos de la rectificación');
            });
    }

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

    // Enfocar automáticamente el campo de escaneo al cargar la página
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('orden_escaneada').focus();
    });

    // Limpiar mensajes de alerta después de 5 segundos
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.display = 'none';
        });
    }, 5000);

    // Abrir PDF en nueva pestaña si hay parámetro pdf en la URL
    <?php if (isset($_GET['pdf'])): ?>
    window.onload = function() {
        // Abrir el PDF en una nueva pestaña
        window.open('descargar_reporte_termico.php?session_id=<?php echo htmlspecialchars($session_id); ?>&id=<?php echo (int)$_GET['pdf']; ?>', '_blank');
        
        // Eliminar el parámetro pdf de la URL sin recargar la página
        if (window.history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('pdf');
            window.history.replaceState({}, '', url);
        }
    };
    <?php endif; ?>

    // Abrir modal automáticamente si hay una orden para abrir
    <?php if (!empty($abrir_modal_id)): ?>
    window.onload = function() {
        abrirModal(<?php echo (int)$abrir_modal_id; ?>);
    };
    <?php endif; ?>
</script>

</body>
</html>