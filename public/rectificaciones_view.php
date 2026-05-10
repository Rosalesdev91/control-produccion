<?php
// Iniciar la sesión para gestionar el acceso del usuario
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Configuración inicial de la base de datos
$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');

// Verificación de acceso del usuario
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    header("Location: login_recti.php");
    exit();
}

// Inicializar variables de filtros con sanitización
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : "";
$fecha_inicio = isset($_GET['fecha_inicio']) ? $conn->real_escape_string($_GET['fecha_inicio']) : "";
$fecha_fin = isset($_GET['fecha_fin']) ? $conn->real_escape_string($_GET['fecha_fin']) : "";
$filtro_sucursal = isset($_GET['filtro_sucursal']) ? $conn->real_escape_string($_GET['filtro_sucursal']) : "";
$filtro_motivo = isset($_GET['filtro_motivo']) ? $conn->real_escape_string($_GET['filtro_motivo']) : "";
$filtro_responsable = isset($_GET['filtro_responsable']) ? $conn->real_escape_string($_GET['filtro_responsable']) : "";
$filtro_responsable_final = isset($_GET['filtro_responsable_final']) ? $conn->real_escape_string($_GET['filtro_responsable_final']) : "";

// Procesar la modificación de una rectificación
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['modificar_rectificacion'])) {
    $id = $conn->real_escape_string($_POST['id']);
    $orden = $conn->real_escape_string($_POST['orden']);
    $sucursal = $conn->real_escape_string($_POST['sucursal']);
    $paciente = $conn->real_escape_string($_POST['paciente']);
    $tipo_vision = $conn->real_escape_string($_POST['tipo_vision']);
    $material = $conn->real_escape_string($_POST['material']);
    $motivo = $conn->real_escape_string($_POST['motivo']);
    $lado = $conn->real_escape_string($_POST['lado']);
    
    // Actualizar el registro en la base de datos
    $query = "UPDATE rectificaciones SET 
              orden = '$orden', 
              sucursal = '$sucursal', 
              paciente = '$paciente', 
              tipo_vision = '$tipo_vision', 
              material = '$material', 
              motivo = '$motivo', 
              lado = '$lado' 
              WHERE id = $id";
    
    if ($conn->query($query)) {
        // Construir URL con filtros para mantener el estado
        $filtros_url = "&search=" . urlencode($search) .
                       "&fecha_inicio=" . urlencode($fecha_inicio) .
                       "&fecha_fin=" . urlencode($fecha_fin) .
                       "&filtro_sucursal=" . urlencode($filtro_sucursal) .
                       "&filtro_motivo=" . urlencode($filtro_motivo) .
                       "&filtro_responsable=" . urlencode($filtro_responsable) .
                       "&filtro_responsable_final=" . urlencode($filtro_responsable_final);
        
        header("Location: rectificaciones_view.php?session_id=" . $_SESSION['session_id'] . "&success=1" . $filtros_url);
        exit();
    } else {
        $mensaje_error = "Error al modificar la rectificación: " . $conn->error;
    }
}

// Procesar la generación de un reporte PDF
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['generar_reporte'])) {
    $filtros_reporte = compact('search', 'fecha_inicio', 'fecha_fin', 'filtro_sucursal', 'filtro_motivo', 'filtro_responsable', 'filtro_responsable_final');
    $_SESSION['filtros_reporte'] = array_filter($filtros_reporte); // Solo guardar filtros no vacíos
    header("Location: generar_reporte.php?session_id=" . $_SESSION['session_id']);
    exit();
}

// Construir consulta base para los registros
$query = "SELECT * FROM rectificaciones";
$whereConditions = [];
$filtros_aplicados = false;

// Aplicar filtros a la consulta
if (!empty($search)) {
    $whereConditions[] = "(orden LIKE '%$search%' OR paciente LIKE '%$search%' OR material LIKE '%$search%')";
    $filtros_aplicados = true;
}
if (!empty($fecha_inicio)) {
    $whereConditions[] = "fecha >= '$fecha_inicio'";
    $filtros_aplicados = true;
}
if (!empty($fecha_fin)) {
    $whereConditions[] = "fecha <= '$fecha_fin'";
    $filtros_aplicados = true;
}
if (!empty($filtro_sucursal)) {
    $whereConditions[] = "sucursal = '$filtro_sucursal'";
    $filtros_aplicados = true;
}
if (!empty($filtro_motivo)) {
    $whereConditions[] = "motivo = '$filtro_motivo'";
    $filtros_aplicados = true;
}
if (!empty($filtro_responsable)) {
    $whereConditions[] = "responsable = '$filtro_responsable'";
    $filtros_aplicados = true;
}
if (!empty($filtro_responsable_final)) {
    $whereConditions[] = "responsable_final = '$filtro_responsable_final'";
    $filtros_aplicados = true;
}

// Aplicar filtro por defecto al día actual si no hay otros filtros
if (!$filtros_aplicados) {
    $hoy = date('Y-m-d');
    $whereConditions[] = "DATE(fecha) = '$hoy'";
}

// Completar consulta con condiciones
if (!empty($whereConditions)) {
    $query .= " WHERE " . implode(" AND ", $whereConditions);
}
$query .= " ORDER BY fecha_registro DESC";
$result = $conn->query($query);

// Obtener el total de registros
$total_query = "SELECT COUNT(*) as total FROM rectificaciones";
if (!empty($whereConditions)) {
    $total_query .= " WHERE " . implode(" AND ", $whereConditions);
}
$total_result = $conn->query($total_query);
$total_registros = $total_result->fetch_assoc()['total'];

// Consultas para estadísticas
$conteo_sucursal_query = "SELECT sucursal, COUNT(*) as cantidad FROM rectificaciones" . (!empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "") . " GROUP BY sucursal ORDER BY cantidad DESC";
$conteo_sucursal_result = $conn->query($conteo_sucursal_query);

$conteo_motivo_query = "SELECT motivo, COUNT(*) as cantidad FROM rectificaciones" . (!empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "") . " GROUP BY motivo ORDER BY cantidad DESC";
$conteo_motivo_result = $conn->query($conteo_motivo_query);

$conteo_responsable_query = "SELECT responsable, COUNT(*) as cantidad FROM rectificaciones" . (!empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "") . " GROUP BY responsable ORDER BY cantidad DESC";
$conteo_responsable_result = $conn->query($conteo_responsable_query);

$conteo_responsable_final_query = "SELECT responsable_final, COUNT(*) as cantidad FROM rectificaciones WHERE responsable_final IS NOT NULL AND responsable_final != ''" . (!empty($whereConditions) ? " AND " . implode(" AND ", $whereConditions) : "") . " GROUP BY responsable_final ORDER BY cantidad DESC";
$conteo_responsable_final_result = $conn->query($conteo_responsable_final_query);

// Consultas para los filtros desplegables
$sucursales_result = $conn->query("SELECT id, sucursal FROM sucursales ORDER BY sucursal");
$tipos_vision_result = $conn->query("SELECT id, tipo_vision FROM tipos_vision");
$materiales_result = $conn->query("SELECT id, material FROM materiales");
$motivos_result = $conn->query("SELECT id, motivo FROM motivos");
$lados_result = $conn->query("SELECT id, lado FROM lados_lente");
$responsables_result = $conn->query("SELECT DISTINCT responsable FROM rectificaciones WHERE responsable IS NOT NULL AND responsable != '' ORDER BY responsable");
$responsables_final_result = $conn->query("SELECT DISTINCT responsable_final FROM rectificaciones WHERE responsable_final IS NOT NULL AND responsable_final != '' ORDER BY responsable_final");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registros de Rectificaciones - Sistema de Control</title>
    <!-- Importar Font Awesome y Bootstrap Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        /* Definición de variables CSS para consistencia */
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

        /* Estilos generales */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            line-height: 1.6;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-green) 50%, var(--primary-green) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-bottom: 80px;
            color: var(--primary-dark);
        }

        /* Header */
        .header {
            background: rgba(0, 51, 0, 0.95);
            border-bottom: 2px solid var(--primary-green);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px var(--shadow);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }

        .logo-container img {
            height: 50px;
            filter: brightness(1.1);
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .clock {
            font-size: 16px;
            font-weight: 600;
            color: var(--light-green);
        }

        .logout-btn, .btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: var(--white);
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow-hover);
        }

        /* Main Container */
        .main-container {
            max-width: 1900px;
            margin: 20px auto;
            padding: 0 15px;
        }

        /* Card */
        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: 0 4px 16px var(--shadow);
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }

        .card-header i {
            color: var(--primary-green);
            font-size: 20px;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 700;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 5px;
            display: block;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        /* Botones */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--success-green));
            color: var(--white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow-hover);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: var(--white);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow-hover);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: var(--white);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow-hover);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: var(--white);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow-hover);
        }

        /* Form Columns */
        .form-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            max-height: 500px;
            border-radius: var(--border-radius-sm);
            box-shadow: 0 4px 12px var(--shadow);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table th {
            background: var(--primary-green);
            color: var(--white);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table tr:nth-child(even) {
            background: var(--light-gray);
        }

        .data-table tr:hover {
            background: #edf7ee;
        }

        /* Column widths */
        .data-table th:nth-child(1), .data-table td:nth-child(1) { width: 150px; } /* Fecha/Hora Registro */
        .data-table th:nth-child(2), .data-table td:nth-child(2) { width: 150px; } /* Fecha/Hora Verificación */
        .data-table th:nth-child(3), .data-table td:nth-child(3) { width: 120px; } /* Sucursal */
        .data-table th:nth-child(4), .data-table td:nth-child(4) { width: 100px; } /* Orden */
        .data-table th:nth-child(5), .data-table td:nth-child(5) { width: 150px; } /* Paciente */
        .data-table th:nth-child(6), .data-table td:nth-child(6) { width: 100px; } /* Tipo Visión */
        .data-table th:nth-child(7), .data-table td:nth-child(7) { width: 120px; } /* Material */
        .data-table th:nth-child(8), .data-table td:nth-child(8) { width: 120px; } /* Responsable */
        .data-table th:nth-child(9), .data-table td:nth-child(9) { width: 120px; } /* Responsable Final */
        .data-table th:nth-child(10), .data-table td:nth-child(10) { width: 120px; } /* Verificado por */
        .data-table th:nth-child(11), .data-table td:nth-child(11) { width: 150px; } /* Motivo */
        .data-table th:nth-child(12), .data-table td:nth-child(12) { width: 80px; } /* Lado */
        .data-table th:nth-child(13), .data-table td:nth-child(13) { width: 200px; } /* Solución */
        .data-table th:nth-child(14), .data-table td:nth-child(14) { width: 120px; } /* Registrado por */
        .data-table th:nth-child(15), .data-table td:nth-child(15) { width: 100px; } /* Acciones */

        /* Alerts */
        .alert {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: var(--border-radius-sm);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Stats Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: 0 4px 12px var(--shadow);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .stat-total {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-green);
            text-align: center;
        }

        .stat-list {
            max-height: 120px;
            overflow-y: auto;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }

        .stat-value {
            color: var(--primary-green);
            font-weight: 600;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: var(--white);
            margin: 10% auto;
            padding: 20px;
            border-radius: var(--border-radius);
            max-width: 600px;
            box-shadow: 0 4px 20px var(--shadow);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .close {
            font-size: 24px;
            cursor: pointer;
            color: #aaa;
        }

        .close:hover {
            color: #000;
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
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 10px;
            }

            .form-columns {
                grid-template-columns: 1fr;
            }

            .table-container {
                max-height: 400px;
            }

            .data-table {
                font-size: 12px;
            }

            .stats-container {
                grid-template-columns: 1fr;
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
                <a href="registro_rectificaciones.php?session_id=<?php echo $_SESSION['session_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-plus"></i> Nueva Rectificación
                </a>
                <a href="registro_rectificaciones.php?session_id=<?php echo $_SESSION['session_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <a href="login_recti.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Registros -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-table"></i>
                <h3>Registros de Rectificaciones</h3>
                <?php if (!$filtros_aplicados): ?>
                    <span style="background: var(--primary-green); color: var(--white); padding: 5px 10px; border-radius: 20px; font-size: 12px;">
                        <i class="fas fa-info-circle"></i> Mostrando registros del día: <?php echo date('d/m/Y'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Mensajes de retroalimentación -->
            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Rectificación modificada correctamente.
                </div>
            <?php endif; ?>
            <?php if (isset($mensaje_error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $mensaje_error; ?>
                </div>
            <?php endif; ?>
            <?php if (!$filtros_aplicados): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Mostrando registros del día actual. Usa los filtros para ver otros.
                </div>
            <?php endif; ?>

            <!-- Formulario de filtros -->
            <form method="GET" action="">
                <input type="hidden" name="session_id" value="<?php echo $_SESSION['session_id']; ?>">
                <div class="form-columns">
                    <div class="form-group">
                        <label class="form-label">Búsqueda general:</label>
                        <input type="text" name="search" class="form-control" placeholder="Orden, paciente o material" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha inicio:</label>
                        <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha fin:</label>
                        <input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sucursal:</label>
                        <select name="filtro_sucursal" class="form-control">
                            <option value="">Todas las sucursales</option>
                            <?php while ($sucursal = $sucursales_result->fetch_assoc()): ?>
                                <option value="<?php echo $sucursal['sucursal']; ?>" <?php echo $filtro_sucursal == $sucursal['sucursal'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sucursal['sucursal']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Motivo:</label>
                        <select name="filtro_motivo" class="form-control">
                            <option value="">Todos los motivos</option>
                            <?php while ($motivo = $motivos_result->fetch_assoc()): ?>
                                <option value="<?php echo $motivo['motivo']; ?>" <?php echo $filtro_motivo == $motivo['motivo'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($motivo['motivo']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Responsable:</label>
                        <select name="filtro_responsable" class="form-control">
                            <option value="">Todos los responsables</option>
                            <?php while ($responsable = $responsables_result->fetch_assoc()): ?>
                                <option value="<?php echo $responsable['responsable']; ?>" <?php echo $filtro_responsable == $responsable['responsable'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($responsable['responsable']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Responsable Final:</label>
                        <select name="filtro_responsable_final" class="form-control">
                            <option value="">Todos los responsables finales</option>
                            <?php while ($responsable_final = $responsables_final_result->fetch_assoc()): ?>
                                <option value="<?php echo $responsable_final['responsable_final']; ?>" <?php echo $filtro_responsable_final == $responsable_final['responsable_final'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($responsable_final['responsable_final']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <a href="rectificaciones_view.php?session_id=<?php echo $_SESSION['session_id']; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Limpiar
                        </a>
                    </div>
                </div>
            </form>

            <!-- Botón para generar reporte -->
            <form method="POST" action="">
                <input type="hidden" name="session_id" value="<?php echo $_SESSION['session_id']; ?>">
                <button type="submit" name="generar_reporte" class="btn btn-info">
                    <i class="fas fa-file-pdf"></i> Generar Reporte
                </button>
            </form>

            <!-- Tabla de registros -->
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fecha/Hora Registro</th>
                            <th>Fecha/Hora Verificación</th>
                            <th>Sucursal</th>
                            <th>Orden</th>
                            <th>Paciente</th>
                            <th>Tipo Visión</th>
                            <th>Material</th>
                            <th>Responsable</th>
                            <th>Responsable Final</th>
                            <th>Verificado por</th>
                            <th>Motivo</th>
                            <th>Lado</th>
                            <th>Solución</th>
                            <th>Registrado por</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['fecha'] . ' ' . $row['hora']); ?></td>
                                    <td><?php echo !empty($row['fecha_verificacion']) && !empty($row['hora_verificacion']) ? htmlspecialchars($row['fecha_verificacion'] . ' ' . $row['hora_verificacion']) : 'No verificado'; ?></td>
                                    <td><?php echo htmlspecialchars($row['sucursal']); ?></td>
                                    <td><?php echo htmlspecialchars($row['orden']); ?></td>
                                    <td><?php echo htmlspecialchars($row['paciente']); ?></td>
                                    <td><?php echo htmlspecialchars($row['tipo_vision']); ?></td>
                                    <td><?php echo htmlspecialchars($row['material']); ?></td>
                                    <td><?php echo htmlspecialchars($row['responsable']); ?></td>
                                    <td><?php echo htmlspecialchars($row['responsable_final']); ?></td>
                                    <td><?php echo htmlspecialchars($row['verificada_por']); ?></td>
                                    <td><?php echo htmlspecialchars($row['motivo']); ?></td>
                                    <td><?php echo htmlspecialchars($row['lado']); ?></td>
                                    <td><?php echo htmlspecialchars($row['solucion']); ?></td>
                                    <td><?php echo htmlspecialchars($row['empleado_registro']); ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-sm" onclick="abrirModal(<?php echo $row['id']; ?>, this)">
                                            <i class="fas fa-edit"></i> Modificar
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="15">No se encontraron registros.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
                <!-- Estadísticas -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-chart-bar"></i>
                    <h4>Total de Registros</h4>
                </div>
                <div class="stat-total"><?php echo $total_registros; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-store"></i>
                    <h4>Por Sucursal</h4>
                </div>
                <div class="stat-list">
                    <?php while ($row = $conteo_sucursal_result->fetch_assoc()): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($row['sucursal']); ?></span>
                            <span class="stat-value"><?php echo $row['cantidad']; ?></span>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-exclamation-circle"></i>
                    <h4>Por Motivo</h4>
                </div>
                <div class="stat-list">
                    <?php while ($row = $conteo_motivo_result->fetch_assoc()): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($row['motivo']); ?></span>
                            <span class="stat-value"><?php echo $row['cantidad']; ?></span>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-user-tie"></i>
                    <h4>Por Responsable</h4>
                </div>
                <div class="stat-list">
                    <?php while ($row = $conteo_responsable_result->fetch_assoc()): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($row['responsable']); ?></span>
                            <span class="stat-value"><?php echo $row['cantidad']; ?></span>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-user-check"></i>
                    <h4>Por Responsable Final</h4>
                </div>
                <div class="stat-list">
                    <?php while ($row = $conteo_responsable_final_result->fetch_assoc()): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($row['responsable_final']); ?></span>
                            <span class="stat-value"><?php echo $row['cantidad']; ?></span>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para modificar rectificación -->
    <div id="modalModificar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Modificar Rectificación</h2>
                <span class="close">&times;</span>
            </div>
            <form id="formModificar" method="POST" action="">
                <input type="hidden" name="id" id="modalId">
                <input type="hidden" name="modificar_rectificacion" value="1">
                <input type="hidden" name="session_id" value="<?php echo $_SESSION['session_id']; ?>">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                <input type="hidden" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                <input type="hidden" name="filtro_sucursal" value="<?php echo htmlspecialchars($filtro_sucursal); ?>">
                <input type="hidden" name="filtro_motivo" value="<?php echo htmlspecialchars($filtro_motivo); ?>">
                <input type="hidden" name="filtro_responsable" value="<?php echo htmlspecialchars($filtro_responsable); ?>">
                <input type="hidden" name="filtro_responsable_final" value="<?php echo htmlspecialchars($filtro_responsable_final); ?>">
                
                <div class="form-columns">
                    <div class="form-group">
                        <label class="form-label">Número de Orden:</label>
                        <input type="text" name="orden" id="modalOrden" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sucursal:</label>
                        <select name="sucursal" id="modalSucursal" class="form-control" required>
                            <option value="">Seleccione una sucursal</option>
                            <?php while ($sucursal = $sucursales_result->fetch_assoc()): ?>
                                <option value="<?php echo $sucursal['sucursal']; ?>"><?php echo htmlspecialchars($sucursal['sucursal']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Paciente:</label>
                        <input type="text" name="paciente" id="modalPaciente" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipo de Visión:</label>
                        <select name="tipo_vision" id="modalTipoVision" class="form-control" required>
                            <option value="">Seleccione un tipo</option>
                            <?php while ($tipo = $tipos_vision_result->fetch_assoc()): ?>
                                <option value="<?php echo $tipo['tipo_vision']; ?>"><?php echo htmlspecialchars($tipo['tipo_vision']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Material:</label>
                        <select name="material" id="modalMaterial" class="form-control" required>
                            <option value="">Seleccione un material</option>
                            <?php while ($material = $materiales_result->fetch_assoc()): ?>
                                <option value="<?php echo $material['material']; ?>"><?php echo htmlspecialchars($material['material']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Motivo:</label>
                        <select name="motivo" id="modalMotivo" class="form-control" required>
                            <option value="">Seleccione un motivo</option>
                            <?php while ($motivo = $motivos_result->fetch_assoc()): ?>
                                <option value="<?php echo $motivo['motivo']; ?>"><?php echo htmlspecialchars($motivo['motivo']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Lado:</label>
                        <select name="lado" id="modalLado" class="form-control" required>
                            <option value="">Seleccione un lado</option>
                            <?php while ($lado = $lados_result->fetch_assoc()): ?>
                                <option value="<?php echo $lado['lado']; ?>"><?php echo htmlspecialchars($lado['lado']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <i class="fas fa-cogs"></i>
            Sistema de Registro de Rectificaciones © <?= date("Y") ?>
        </div>
        <div class="developer">
            <i class="fas fa-code"></i>
            Desarrollado por: Nestor Rosales | Rosales_Dev91
        </div>
    </footer>

    <script>
        // Actualizar el reloj en tiempo real
        function actualizarReloj() {
            const ahora = new Date();
            document.getElementById('reloj').textContent = ahora.toLocaleString('es-ES', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
            });
        }
        setInterval(actualizarReloj, 1000);
        actualizarReloj();

        // Funcionalidad del modal
        const modal = document.getElementById("modalModificar");

        function abrirModal(id, button) {
            const fila = button.closest('tr');
            const celdas = fila.querySelectorAll('td');
            
            document.getElementById('modalId').value = id;
            document.getElementById('modalOrden').value = celdas[3].textContent;
            document.getElementById('modalSucursal').value = celdas[2].textContent;
            document.getElementById('modalPaciente').value = celdas[4].textContent;
            document.getElementById('modalTipoVision').value = celdas[5].textContent;
            document.getElementById('modalMaterial').value = celdas[6].textContent;
            document.getElementById('modalMotivo').value = celdas[10].textContent;
            document.getElementById('modalLado').value = celdas[11].textContent;
            
            modal.style.display = "block";
        }

        function cerrarModal() {
            modal.style.display = "none";
        }

        document.querySelector(".close").onclick = cerrarModal;
        window.onclick = (event) => {
            if (event.target === modal) cerrarModal();
        };
    </script>
</body>
</html>