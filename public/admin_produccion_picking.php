<?php
// =============================================
// admin_produccion_picking.php
// Panel de Administración - Producción Picking
// Enfoque: Producción por DÍA (Tiquetes/Transferencias)
// CORREGIDO Y OPTIMIZADO
// =============================================

session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once 'registrar_actividad.php';

date_default_timezone_set('America/Costa_Rica');
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Configurar charset para evitar problemas con caracteres especiales
$conn->set_charset("utf8mb4");

// =============================================
// VERIFICAR ACCESO ADMINISTRADOR
// =============================================
$es_admin = false;
if (isset($_SESSION['admin_id']) || (isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador')) {
    $es_admin = true;
    $admin_nombre = $_SESSION['admin_usuario'] ?? $_SESSION['empleado'] ?? 'Administrador';
} else {
    header("Location: login_admin.php");
    exit();
}

// =============================================
// VARIABLES INICIALES
// =============================================
$mensaje_error = '';
$mensaje_exito = '';
$tab_activa = isset($_GET['tab']) ? $_GET['tab'] : 'produccion_diaria';

// Tablas permitidas para CRUD
$tablas_permitidas = ['empleados_picking', 'procesos_picking'];

// =============================================
// PROCESAR ELIMINACIONES
// =============================================
if (isset($_GET['eliminar']) && isset($_GET['id']) && isset($_GET['tabla'])) {
    $tabla = $_GET['tabla'];
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    if (!in_array($tabla, $tablas_permitidas)) {
        $_SESSION['error_eliminacion'] = "Tabla no válida.";
    } elseif ($id === false || $id <= 0) {
        $_SESSION['error_eliminacion'] = "ID no válido.";
    } else {
        try {
            if ($tabla === 'empleados_picking') {
                $check = $conn->prepare("SELECT COUNT(*) as total FROM produccion_picking WHERE empleado = (SELECT nombre_empleado FROM empleados_picking WHERE id = ?)");
                $check->bind_param("i", $id);
                $check->execute();
                $result = $check->get_result();
                $row = $result->fetch_assoc();
                $check->close();

                if ($row['total'] > 0) {
                    $_SESSION['error_eliminacion'] = "No se puede eliminar: El empleado tiene {$row['total']} registros de producción.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM empleados_picking WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        $_SESSION['exito_eliminacion'] = "Empleado eliminado correctamente.";
                    } else {
                        $_SESSION['error_eliminacion'] = "Error al eliminar: " . $conn->error;
                    }
                    $stmt->close();
                }
            } elseif ($tabla === 'procesos_picking') {
                $check = $conn->prepare("SELECT COUNT(*) as total FROM produccion_picking WHERE proceso = (SELECT proceso FROM procesos_picking WHERE id = ?)");
                $check->bind_param("i", $id);
                $check->execute();
                $result = $check->get_result();
                $row = $result->fetch_assoc();
                $check->close();

                if ($row['total'] > 0) {
                    $_SESSION['error_eliminacion'] = "No se puede eliminar: El proceso tiene {$row['total']} registros de producción.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM procesos_picking WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        $_SESSION['exito_eliminacion'] = "Proceso eliminado correctamente.";
                    } else {
                        $_SESSION['error_eliminacion'] = "Error al eliminar: " . $conn->error;
                    }
                    $stmt->close();
                }
            }
            header("Location: admin_produccion_picking.php?tab=" . ($tabla === 'empleados_picking' ? 'empleados' : 'procesos'));
            exit();
        } catch (Exception $e) {
            $_SESSION['error_eliminacion'] = "Error en el sistema: " . $e->getMessage();
            header("Location: admin_produccion_picking.php?tab=" . ($tabla === 'empleados_picking' ? 'empleados' : 'procesos'));
            exit();
        }
    }
}

// =============================================
// PROCESAR AGREGAR / EDITAR - CORREGIDO
// =============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // IMPORTANTE: Verificar qué acción se está ejecutando
    $accion = '';
    
    // Agregar empleado - CORREGIDO
    if (isset($_POST['accion']) && $_POST['accion'] === 'agregar_empleado') {
        $accion = 'agregar_empleado';
        $codigo = trim($_POST['codigo_empleado'] ?? '');
        $nombre = trim($_POST['nombre_empleado'] ?? '');
        
        if (empty($codigo) || empty($nombre)) {
            $_SESSION['mensaje_error'] = "Todos los campos son obligatorios.";
            $_SESSION['error_detalle'] = "Código y nombre son requeridos";
        } else {
            // Verificar si ya existe el código
            $check = $conn->prepare("SELECT id FROM empleados_picking WHERE codigo_empleado = ?");
            $check->bind_param("s", $codigo);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['mensaje_error'] = "El código de empleado ya existe.";
            } else {
                $stmt = $conn->prepare("INSERT INTO empleados_picking (codigo_empleado, nombre_empleado) VALUES (?, ?)");
                $stmt->bind_param("ss", $codigo, $nombre);
                
                if ($stmt->execute()) {
                    $_SESSION['mensaje_exito'] = "✅ Empleado '$nombre' agregado correctamente.";
                    $_SESSION['empleado_agregado'] = true;
                } else {
                    $_SESSION['mensaje_error'] = "Error al agregar: " . $conn->error;
                }
                $stmt->close();
            }
            $check->close();
        }
        
        header("Location: admin_produccion_picking.php?tab=empleados");
        exit();
    }
    
    // Editar empleado - CORREGIDO
    if (isset($_POST['accion']) && $_POST['accion'] === 'editar_empleado') {
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        $codigo = trim($_POST['codigo_empleado'] ?? '');
        $nombre = trim($_POST['nombre_empleado'] ?? '');
        
        if (!$id || empty($codigo) || empty($nombre)) {
            $_SESSION['mensaje_error'] = "Datos inválidos para editar empleado.";
        } else {
            // Verificar si el código ya existe en otro registro
            $check = $conn->prepare("SELECT id FROM empleados_picking WHERE codigo_empleado = ? AND id != ?");
            $check->bind_param("si", $codigo, $id);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['mensaje_error'] = "El código de empleado ya está siendo usado por otro empleado.";
            } else {
                $stmt = $conn->prepare("UPDATE empleados_picking SET codigo_empleado = ?, nombre_empleado = ? WHERE id = ?");
                $stmt->bind_param("ssi", $codigo, $nombre, $id);
                
                if ($stmt->execute()) {
                    $_SESSION['mensaje_exito'] = "✅ Empleado actualizado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al actualizar: " . $conn->error;
                }
                $stmt->close();
            }
            $check->close();
        }
        
        header("Location: admin_produccion_picking.php?tab=empleados");
        exit();
    }
    
    // Agregar proceso - CORREGIDO
    if (isset($_POST['accion']) && $_POST['accion'] === 'agregar_proceso') {
        $proceso = trim($_POST['proceso'] ?? '');
        
        if (empty($proceso)) {
            $_SESSION['mensaje_error'] = "El nombre del proceso es obligatorio.";
        } else {
            // Verificar si ya existe
            $check = $conn->prepare("SELECT id FROM procesos_picking WHERE proceso = ?");
            $check->bind_param("s", $proceso);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['mensaje_error'] = "El proceso '$proceso' ya existe.";
            } else {
                $stmt = $conn->prepare("INSERT INTO procesos_picking (proceso) VALUES (?)");
                $stmt->bind_param("s", $proceso);
                
                if ($stmt->execute()) {
                    $_SESSION['mensaje_exito'] = "✅ Proceso '$proceso' agregado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al agregar: " . $conn->error;
                }
                $stmt->close();
            }
            $check->close();
        }
        
        header("Location: admin_produccion_picking.php?tab=procesos");
        exit();
    }
    
    // Editar proceso - CORREGIDO
    if (isset($_POST['accion']) && $_POST['accion'] === 'editar_proceso') {
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        $proceso = trim($_POST['proceso'] ?? '');
        
        if (!$id || empty($proceso)) {
            $_SESSION['mensaje_error'] = "Datos inválidos para editar proceso.";
        } else {
            // Verificar si el proceso ya existe en otro registro
            $check = $conn->prepare("SELECT id FROM procesos_picking WHERE proceso = ? AND id != ?");
            $check->bind_param("si", $proceso, $id);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['mensaje_error'] = "El nombre del proceso ya está siendo usado por otro proceso.";
            } else {
                $stmt = $conn->prepare("UPDATE procesos_picking SET proceso = ? WHERE id = ?");
                $stmt->bind_param("si", $proceso, $id);
                
                if ($stmt->execute()) {
                    $_SESSION['mensaje_exito'] = "✅ Proceso actualizado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al actualizar: " . $conn->error;
                }
                $stmt->close();
            }
            $check->close();
        }
        
        header("Location: admin_produccion_picking.php?tab=procesos");
        exit();
    }
}

// =============================================
// CONSULTAS PRINCIPALES - PRODUCCIÓN POR DÍA
// =============================================

// Verificar si la columna 'tiquete_transferencia' existe, si no, usar 'referencia'
$check_column = $conn->query("SHOW COLUMNS FROM produccion_picking LIKE 'tiquete_transferencia'");
$referencia_column = ($check_column && $check_column->num_rows > 0) ? 'tiquete_transferencia' : 'referencia';

// 1. PRODUCCIÓN AGRUPADA POR DÍA
$produccion_dias = $conn->query("
    SELECT 
        DATE(fecha) as fecha,
        COUNT(*) as total_referencias,
        COUNT(DISTINCT empleado) as empleados_activos,
        COUNT(DISTINCT proceso) as procesos_utilizados,
        COUNT(DISTINCT {$referencia_column}) as referencias_unicas
    FROM produccion_picking
    GROUP BY DATE(fecha)
    ORDER BY fecha DESC
    LIMIT 30
");

if (!$produccion_dias) {
    die("Error en consulta de producción diaria: " . $conn->error);
}

// 2. PRODUCCIÓN DE HOY
$hoy = date('Y-m-d');
$produccion_hoy_detalle = $conn->query("
    SELECT 
        p.*,
        e.codigo_empleado,
        p.{$referencia_column} as referencia_actual
    FROM produccion_picking p
    LEFT JOIN empleados_picking e ON p.empleado = e.nombre_empleado
    WHERE DATE(p.fecha) = '$hoy'
    ORDER BY p.fecha DESC
");

// 3. RESUMEN HOY
$resumen_hoy = $conn->query("
    SELECT 
        COUNT(*) as total_hoy,
        COUNT(DISTINCT empleado) as empleados_hoy,
        COUNT(DISTINCT proceso) as procesos_hoy
    FROM produccion_picking 
    WHERE DATE(fecha) = '$hoy'
")->fetch_assoc();

// 4. ESTADÍSTICAS POR EMPLEADO (GUARDAR EN ARRAY)
$stats_empleados_array = [];
$stats_empleados_result = $conn->query("
    SELECT 
        e.id,
        e.codigo_empleado,
        e.nombre_empleado,
        COUNT(p.id) as total_produccion,
        SUM(CASE WHEN DATE(p.fecha) = '$hoy' THEN 1 ELSE 0 END) as produccion_hoy,
        MAX(p.fecha) as ultima_produccion,
        COUNT(DISTINCT DATE(p.fecha)) as dias_trabajados
    FROM empleados_picking e
    LEFT JOIN produccion_picking p ON e.nombre_empleado = p.empleado
    GROUP BY e.id
    ORDER BY total_produccion DESC
");

if ($stats_empleados_result) {
    while($row = $stats_empleados_result->fetch_assoc()) {
        $stats_empleados_array[] = $row;
    }
}

// 5. ESTADÍSTICAS POR PROCESO (GUARDAR EN ARRAY)
$stats_procesos_array = [];
$stats_procesos_result = $conn->query("
    SELECT 
        pr.id,
        pr.proceso,
        COUNT(p.id) as total_produccion,
        SUM(CASE WHEN DATE(p.fecha) = '$hoy' THEN 1 ELSE 0 END) as produccion_hoy,
        COUNT(DISTINCT p.empleado) as empleados_diferentes,
        COUNT(DISTINCT DATE(p.fecha)) as dias_con_produccion
    FROM procesos_picking pr
    LEFT JOIN produccion_picking p ON pr.proceso = p.proceso
    GROUP BY pr.id
    ORDER BY total_produccion DESC
");

if ($stats_procesos_result) {
    while($row = $stats_procesos_result->fetch_assoc()) {
        $stats_procesos_array[] = $row;
    }
}

// 6. LISTAS PARA CRUD
$empleados_lista = $conn->query("SELECT * FROM empleados_picking ORDER BY nombre_empleado ASC");
$procesos_lista = $conn->query("SELECT * FROM procesos_picking ORDER BY proceso ASC");

// 7. TOP 10 EMPLEADOS (GUARDAR EN ARRAY)
$top_empleados_array = [];
$top_empleados_result = $conn->query("
    SELECT 
        empleado,
        COUNT(*) as total,
        COUNT(DISTINCT DATE(fecha)) as dias
    FROM produccion_picking 
    GROUP BY empleado
    ORDER BY total DESC
    LIMIT 10
");

if ($top_empleados_result) {
    while($row = $top_empleados_result->fetch_assoc()) {
        $top_empleados_array[] = $row;
    }
}

// 8. PRODUCCIÓN SEMANAL
$produccion_semanal = $conn->query("
    SELECT 
        DATE(fecha) as fecha,
        DAYNAME(fecha) as dia_semana,
        DAYOFWEEK(fecha) as num_dia,
        COUNT(*) as total
    FROM produccion_picking 
    WHERE fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(fecha)
    ORDER BY fecha ASC
");

// 9. TOTALES GENERALES
$totales_generales = $conn->query("
    SELECT 
        COUNT(*) as produccion_total,
        COUNT(DISTINCT empleado) as empleados_total_hist,
        COUNT(DISTINCT proceso) as procesos_total_hist,
        MIN(DATE(fecha)) as fecha_inicio,
        MAX(DATE(fecha)) as fecha_fin
    FROM produccion_picking
")->fetch_assoc();

// =============================================
// RECUPERAR MENSAJES DE SESIÓN - CORREGIDO
// =============================================
if (isset($_SESSION['mensaje_error'])) {
    $mensaje_error = $_SESSION['mensaje_error'];
    unset($_SESSION['mensaje_error']);
    if (isset($_SESSION['error_detalle'])) {
        $error_detalle = $_SESSION['error_detalle'];
        unset($_SESSION['error_detalle']);
    }
}

if (isset($_SESSION['mensaje_exito'])) {
    $mensaje_exito = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);
}

if (isset($_SESSION['error_eliminacion'])) {
    $mensaje_error = $_SESSION['error_eliminacion'];
    unset($_SESSION['error_eliminacion']);
}
if (isset($_SESSION['exito_eliminacion'])) {
    $mensaje_exito = $_SESSION['exito_eliminacion'];
    unset($_SESSION['exito_eliminacion']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Producción - Administración Picking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #155724;
            --secondary-green: #218838;
            --accent-yellow: #ffc107;
            --light-green: #d4edda;
        }
        
        body {
            background: var(--primary-green);
            color: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            padding-bottom: 80px;
            position: relative;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .logo {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 200px;
            height: auto;
            z-index: 100;
        }
        
        .firma {
            text-align: center;
            font-size: 14px;
            color: #d4fcd4;
            padding: 15px 0;
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            z-index: 1000;
        }
        
        .firma p {
            margin: 5px 0 0;
            font-size: 13px;
            color: #a3d9a3;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--secondary-green);
            flex-wrap: wrap;
            margin-top: 100px;
            gap: 5px;
        }
        
        .tab-btn {
            padding: 12px 25px;
            background: #1e7e34;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            transition: all 0.3s ease;
            border-radius: 8px 8px 0 0;
            margin-right: 2px;
            margin-bottom: 2px;
        }
        
        .tab-btn:hover { 
            background: #166b2c; 
            transform: translateY(-2px);
        }
        
        .tab-btn.active {
            background: var(--secondary-green);
            box-shadow: 0 -3px 0 var(--accent-yellow) inset;
        }
        
        .tab-content {
            display: none;
            padding: 25px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0 8px 8px 8px;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .tab-content.active { display: block; }
        
        .stat-card {
            background: linear-gradient(135deg, #1e7e34 0%, #155724 100%);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            border-left: 6px solid var(--accent-yellow);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            position: relative;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card h3 { 
            margin: 10px 0 5px; 
            font-size: 2.2rem; 
            font-weight: bold;
        }
        
        .stat-card h5 {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.95;
        }
        
        .stat-icon {
            font-size: 3rem;
            opacity: 0.2;
            position: absolute;
            right: 20px;
            top: 20px;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            color: #155724;
            overflow-x: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .table-container h5 {
            color: var(--primary-green);
            margin-bottom: 15px;
            font-weight: bold;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        
        th {
            background: var(--secondary-green);
            color: white;
            padding: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        td { 
            padding: 12px; 
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        tr:hover { 
            background-color: #f8f9fa; 
        }
        
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 13px;
            margin: 2px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-action:hover {
            transform: scale(1.05);
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-editar { background-color: #007bff; }
        .btn-eliminar { background-color: #dc3545; }
        .btn-agregar {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.2s;
        }
        
        .btn-agregar:hover {
            background-color: #218838;
            transform: scale(1.05);
        }
        
        .btn-ver { background-color: #17a2b8; }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 12px;
            width: 500px;
            max-width: 95%;
            max-height: 85vh;
            overflow-y: auto;
            color: #155724;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .form-group { 
            margin-bottom: 20px; 
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #155724;
        }
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 2px solid #ced4da;
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            border-color: #28a745;
            outline: none;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }
        
        .close-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            margin-left: 10px;
            transition: all 0.2s;
        }
        
        .close-btn:hover {
            background: #5a6268;
        }
        
        .badge-produccion {
            background: var(--secondary-green);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: bold;
        }
        
        .scrollable { 
            max-height: 600px; 
            overflow-y: auto; 
            overflow-x: hidden;
        }
        
        .mensaje-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 5px solid #dc3545;
        }
        
        .mensaje-exito {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 5px solid #28a745;
        }
        
        .filtros {
            background: rgba(255,255,255,0.1);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            backdrop-filter: blur(5px);
        }
        
        .filtros input, .filtros select {
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            min-width: 200px;
        }
        
        .chart-bar {
            background: linear-gradient(180deg, #28a745 0%, #1e7e34 100%);
            width: 35px;
            margin: 0 auto;
            border-radius: 6px 6px 0 0;
            transition: height 0.3s ease;
        }
        
        .dataTables_wrapper {
            color: #155724;
        }
        
        .dataTables_filter input {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 5px 10px;
            margin-left: 10px;
        }
        
        @media (max-width: 768px) {
            .logo {
                position: static;
                display: block;
                margin: 0 auto 20px;
            }
            
            .tabs {
                margin-top: 20px;
            }
            
            .tab-btn {
                flex: 1 1 calc(50% - 5px);
                font-size: 14px;
                padding: 10px;
            }
            
            .stat-card h3 {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>

<img src="/control_produccion/public/logo.png" alt="Logo" class="logo" onerror="this.style.display='none'">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-2"><i class="bi bi-box-seam"></i> Panel Producción - Picking</h1>
            <p class="mb-0">
                <i class="bi bi-person-circle"></i> Bienvenido, <strong><?= htmlspecialchars($admin_nombre) ?></strong> | 
                <a href="login_picking.php" style="color: #ffc107; text-decoration: none;">
                    <i class="bi bi-box-arrow-right"></i> Cerrar sesión
                </a>
            </p>
        </div>
        <div>
            <span class="badge-produccion">
                <i class="bi bi-calendar3"></i> <?= date('d/m/Y') ?> 
                <i class="bi bi-clock ms-2"></i> <?= date('H:i') ?>
            </span>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($mensaje_error): ?>
        <div class="mensaje-error">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($mensaje_error) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($mensaje_exito): ?>
        <div class="mensaje-exito">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= htmlspecialchars($mensaje_exito) ?>
        </div>
    <?php endif; ?>

    <!-- Pestañas -->
    <div class="tabs">
        <button class="tab-btn <?= $tab_activa == 'produccion_diaria' ? 'active' : '' ?>" data-tab="produccion_diaria">
            <i class="bi bi-calendar-week"></i> Producción por Día
        </button>
        <button class="tab-btn <?= $tab_activa == 'produccion_hoy' ? 'active' : '' ?>" data-tab="produccion_hoy">
            <i class="bi bi-calendar-day"></i> Producción de Hoy
        </button>
        <button class="tab-btn <?= $tab_activa == 'empleados' ? 'active' : '' ?>" data-tab="empleados">
            <i class="bi bi-people"></i> Empleados
        </button>
        <button class="tab-btn <?= $tab_activa == 'procesos' ? 'active' : '' ?>" data-tab="procesos">
            <i class="bi bi-gear"></i> Procesos
        </button>
        <button class="tab-btn <?= $tab_activa == 'estadisticas' ? 'active' : '' ?>" data-tab="estadisticas">
            <i class="bi bi-graph-up"></i> Estadísticas
        </button>
        <button class="tab-btn <?= $tab_activa == 'historial' ? 'active' : '' ?>" data-tab="historial">
            <i class="bi bi-clock-history"></i> Historial
        </button>
    </div>

    <!-- ========================================= -->
    <!-- PESTAÑA 1: PRODUCCIÓN POR DÍA -->
    <!-- ========================================= -->
    <div id="produccion_diaria" class="tab-content <?= $tab_activa == 'produccion_diaria' ? 'active' : '' ?>">
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-box"></i></div>
                    <h5>Total Histórico</h5>
                    <h3><?= number_format($totales_generales['produccion_total'] ?? 0) ?></h3>
                    <small>tiquetes/transferencias</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="background: linear-gradient(135deg, #0062cc 0%, #004085 100%);">
                    <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
                    <h5>Empleados</h5>
                    <h3><?= number_format($totales_generales['empleados_total_hist'] ?? 0) ?></h3>
                    <small>han participado</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="background: linear-gradient(135deg, #e0a800 0%, #b38f00 100%);">
                    <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
                    <h5>Procesos</h5>
                    <h3><?= number_format($totales_generales['procesos_total_hist'] ?? 0) ?></h3>
                    <small>diferentes</small>
                </div>
            </div>
        </div>

        <h2 class="text-white mb-3"><i class="bi bi-calendar-check"></i> Producción Diaria (Últimos 30 días)</h2>
        
        <div class="table-container">
            <table id="tablaProduccionDiaria" class="display table table-striped" style="width:100%">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Día</th>
                        <th>Tiquetes/Transferencias</th>
                        <th>Empleados Activos</th>
                        <th>Procesos Utilizados</th>
                        <th>Referencias Únicas</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($produccion_dias && $produccion_dias->num_rows > 0): ?>
                        <?php while($dia = $produccion_dias->fetch_assoc()): 
                            $fecha_obj = new DateTime($dia['fecha']);
                            $hoy_check = ($dia['fecha'] == $hoy);
                            $dias_es = ['Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 
                                       'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 
                                       'Sunday' => 'Domingo'];
                            $dia_nombre = $dias_es[$fecha_obj->format('l')] ?? $fecha_obj->format('l');
                        ?>
                        <tr style="<?= $hoy_check ? 'background-color: #fff3cd;' : '' ?>">
                            <td><strong><?= $fecha_obj->format('d/m/Y') ?></strong></td>
                            <td><?= $dia_nombre ?></td>
                            <td><span class="badge bg-success" style="font-size: 1rem; padding: 8px 12px;"><?= number_format($dia['total_referencias']) ?></span></td>
                            <td><?= $dia['empleados_activos'] ?></td>
                            <td><?= $dia['procesos_utilizados'] ?></td>
                            <td><?= $dia['referencias_unicas'] ?></td>
                            <td>
                                <a href="?tab=historial&fecha_desde=<?= $dia['fecha'] ?>&fecha_hasta=<?= $dia['fecha'] ?>" 
                                   class="btn-action btn-ver">
                                    <i class="bi bi-eye"></i> Ver
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                No hay datos de producción disponibles
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Gráfico de producción semanal -->
        <div class="mt-4">
            <div class="table-container">
                <h5 class="mb-3"><i class="bi bi-bar-chart-fill"></i> Producción Últimos 7 Días</h5>
                <div style="display: flex; gap: 15px; align-items: flex-end; height: 250px; padding: 20px 0;">
                    <?php 
                    if ($produccion_semanal && $produccion_semanal->num_rows > 0) {
                        $max_semanal = 1;
                        $datos_semanal = [];
                        while($sem = $produccion_semanal->fetch_assoc()) {
                            $datos_semanal[] = $sem;
                            if ($sem['total'] > $max_semanal) $max_semanal = $sem['total'];
                        }
                        foreach($datos_semanal as $sem): 
                            $altura = max(20, ($sem['total'] / $max_semanal) * 200);
                            $dias_abr = ['Sunday' => 'Dom', 'Monday' => 'Lun', 'Tuesday' => 'Mar', 
                                        'Wednesday' => 'Mié', 'Thursday' => 'Jue', 'Friday' => 'Vie', 
                                        'Saturday' => 'Sáb'];
                            $dia_sem = $dias_abr[$sem['dia_semana']] ?? substr($sem['dia_semana'], 0, 3);
                    ?>
                    <div style="flex: 1; text-align: center;">
                        <div class="chart-bar" style="height: <?= $altura ?>px;"></div>
                        <div style="margin-top: 10px;">
                            <strong style="font-size: 1.2rem; color: #155724;"><?= $sem['total'] ?></strong>
                        </div>
                        <div style="margin-top: 5px;">
                            <span style="background: #e9ecef; padding: 5px 10px; border-radius: 15px; font-weight: bold;">
                                <?= $dia_sem ?>
                            </span>
                        </div>
                        <small style="color: #6c757d;"><?= date('d/m', strtotime($sem['fecha'])) ?></small>
                    </div>
                    <?php 
                        endforeach; 
                    } else {
                        echo '<div class="text-center w-100 py-4">No hay datos de la última semana</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- PESTAÑA 2: PRODUCCIÓN DE HOY -->
    <!-- ========================================= -->
    <div id="produccion_hoy" class="tab-content <?= $tab_activa == 'produccion_hoy' ? 'active' : '' ?>">
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-box"></i></div>
                    <h5>Producción Hoy</h5>
                    <h3><?= number_format($resumen_hoy['total_hoy'] ?? 0) ?></h3>
                    <small>tiquetes/transferencias</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="background: linear-gradient(135deg, #0062cc 0%, #004085 100%);">
                    <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
                    <h5>Empleados Activos</h5>
                    <h3><?= number_format($resumen_hoy['empleados_hoy'] ?? 0) ?></h3>
                    <small>trabajando hoy</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="background: linear-gradient(135deg, #e0a800 0%, #b38f00 100%);">
                    <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
                    <h5>Procesos Hoy</h5>
                    <h3><?= number_format($resumen_hoy['procesos_hoy'] ?? 0) ?></h3>
                    <small>diferentes procesos</small>
                </div>
            </div>
        </div>

        <div class="table-container">
            <h5 class="mb-3"><i class="bi bi-clock-history"></i> Detalle de Producción - <?= date('d/m/Y') ?></h5>
            <div class="scrollable">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Empleado</th>
                            <th>Código</th>
                            <th>Proceso</th>
                            <th>Referencia (Tiquete/Transferencia)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($produccion_hoy_detalle && $produccion_hoy_detalle->num_rows > 0): ?>
                            <?php while($row = $produccion_hoy_detalle->fetch_assoc()): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= date('H:i:s', strtotime($row['fecha'])) ?></span></td>
                                <td><?= htmlspecialchars($row['empleado'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-dark"><?= htmlspecialchars($row['codigo_empleado'] ?? 'N/A') ?></span></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($row['proceso'] ?? 'N/A') ?></span></td>
                                <td><strong><?= htmlspecialchars($row['referencia_actual'] ?? $row['referencia'] ?? 'N/A') ?></strong></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <i class="bi bi-inbox" style="font-size: 3rem;"></i><br>
                                    <h5 class="mt-3">No hay producciones registradas hoy</h5>
                                    <p class="text-muted">Los registros aparecerán cuando los empleados registren su producción</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- PESTAÑA 3: EMPLEADOS (CRUD) -->
    <!-- ========================================= -->
    <div id="empleados" class="tab-content <?= $tab_activa == 'empleados' ? 'active' : '' ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white"><i class="bi bi-people-fill"></i> Gestión de Empleados</h2>
            <button class="btn-agregar" onclick="mostrarModalAgregarEmpleado()">
                <i class="bi bi-plus-circle"></i> Nuevo Empleado
            </button>
        </div>

        <div class="table-container">
            <table id="tablaEmpleados" class="display table table-striped" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Hoy</th>
                        <th>Total</th>
                        <th>Días</th>
                        <th>Última Prod.</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($stats_empleados_array)): ?>
                        <?php foreach($stats_empleados_array as $emp): ?>
                        <tr>
                            <td><?= $emp['id'] ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($emp['codigo_empleado'] ?? '') ?></span></td>
                            <td><strong><?= htmlspecialchars($emp['nombre_empleado'] ?? '') ?></strong></td>
                            <td>
                                <span class="badge bg-success">
                                    <?= number_format($emp['produccion_hoy'] ?? 0) ?>
                                </span>
                            </td>
                            <td><?= number_format($emp['total_produccion'] ?? 0) ?></td>
                            <td><?= $emp['dias_trabajados'] ?? 0 ?></td>
                            <td>
                                <?php if ($emp['ultima_produccion']): ?>
                                    <?= date('d/m/Y', strtotime($emp['ultima_produccion'])) ?>
                                    <br>
                                    <small><?= date('H:i', strtotime($emp['ultima_produccion'])) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Nunca</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-action btn-editar" onclick='editarEmpleado(<?= json_encode($emp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                                <a href="?eliminar=1&tabla=empleados_picking&id=<?= $emp['id'] ?>&tab=empleados" 
                                   class="btn-action btn-eliminar" 
                                   onclick="return confirm('¿Está seguro de eliminar este empleado?\n\nEsta acción eliminará permanentemente al empleado y no se podrá recuperar.')">
                                    <i class="bi bi-trash"></i> Eliminar
                                </a>
                                <button class="btn-action btn-ver" onclick="verProduccionEmpleado('<?= htmlspecialchars($emp['nombre_empleado'] ?? '', ENT_QUOTES) ?>')">
                                    <i class="bi bi-eye"></i> Ver
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="bi bi-people" style="font-size: 2rem;"></i><br>
                                No hay empleados registrados
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- PESTAÑA 4: PROCESOS (CRUD) -->
    <!-- ========================================= -->
    <div id="procesos" class="tab-content <?= $tab_activa == 'procesos' ? 'active' : '' ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white"><i class="bi bi-gear-fill"></i> Gestión de Procesos</h2>
            <button class="btn-agregar" onclick="mostrarModalAgregarProceso()">
                <i class="bi bi-plus-circle"></i> Nuevo Proceso
            </button>
        </div>

        <div class="table-container">
            <table id="tablaProcesos" class="display table table-striped" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Proceso</th>
                        <th>Hoy</th>
                        <th>Total</th>
                        <th>Empleados</th>
                        <th>Días</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($stats_procesos_array)): ?>
                        <?php foreach($stats_procesos_array as $proc): ?>
                        <tr>
                            <td><?= $proc['id'] ?></td>
                            <td><strong><?= htmlspecialchars($proc['proceso'] ?? '') ?></strong></td>
                            <td>
                                <span class="badge bg-success">
                                    <?= number_format($proc['produccion_hoy'] ?? 0) ?>
                                </span>
                            </td>
                            <td><?= number_format($proc['total_produccion'] ?? 0) ?></td>
                            <td><?= $proc['empleados_diferentes'] ?? 0 ?></td>
                            <td><?= $proc['dias_con_produccion'] ?? 0 ?></td>
                            <td>
                                <button class="btn-action btn-editar" onclick='editarProceso(<?= json_encode($proc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                                <a href="?eliminar=1&tabla=procesos_picking&id=<?= $proc['id'] ?>&tab=procesos" 
                                   class="btn-action btn-eliminar" 
                                   onclick="return confirm('¿Está seguro de eliminar este proceso?\n\nEsta acción eliminará permanentemente el proceso y no se podrá recuperar.')">
                                    <i class="bi bi-trash"></i> Eliminar
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-gear" style="font-size: 2rem;"></i><br>
                                No hay procesos registrados
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- PESTAÑA 5: ESTADÍSTICAS -->
    <!-- ========================================= -->
    <div id="estadisticas" class="tab-content <?= $tab_activa == 'estadisticas' ? 'active' : '' ?>">
        <h2 class="text-white mb-4"><i class="bi bi-graph-up"></i> Estadísticas de Producción</h2>
        
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="table-container">
                    <h5 class="mb-3"><i class="bi bi-trophy-fill text-warning"></i> Top 10 Empleados</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Empleado</th>
                                    <th>Total</th>
                                    <th>Días</th>
                                    <th>Promedio/Día</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (!empty($top_empleados_array)) {
                                    $pos = 1;
                                    foreach($top_empleados_array as $top): 
                                        $promedio = ($top['dias'] > 0) ? round($top['total'] / $top['dias'], 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($pos <= 3): ?>
                                            <span class="badge bg-warning text-dark"><?= $pos++ ?></span>
                                        <?php else: ?>
                                            <?= $pos++ ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($top['empleado'] ?? '') ?></td>
                                    <td><span class="badge bg-success"><?= number_format($top['total'] ?? 0) ?></span></td>
                                    <td><?= $top['dias'] ?? 0 ?></td>
                                    <td><strong><?= $promedio ?></strong></td>
                                </tr>
                                <?php 
                                    endforeach; 
                                } else {
                                    echo '<tr><td colspan="5" class="text-center py-4">No hay datos disponibles</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="table-container">
                    <h5 class="mb-3"><i class="bi bi-pie-chart-fill"></i> Producción por Proceso</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Proceso</th>
                                    <th>Total</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (!empty($stats_procesos_array)) {
                                    $total_general = max(1, $totales_generales['produccion_total'] ?? 1);
                                    foreach($stats_procesos_array as $proc): 
                                        $porcentaje = round(($proc['total_produccion'] / $total_general) * 100, 1);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($proc['proceso'] ?? '') ?></td>
                                    <td><?= number_format($proc['total_produccion'] ?? 0) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="me-2"><?= $porcentaje ?>%</span>
                                            <div class="progress flex-grow-1" style="height: 8px;">
                                                <div class="progress-bar bg-success" style="width: <?= $porcentaje ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                                    endforeach; 
                                } else {
                                    echo '<tr><td colspan="3" class="text-center py-4">No hay datos disponibles</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================= -->
    <!-- PESTAÑA 6: HISTORIAL CON FILTROS -->
    <!-- ========================================= -->
    <div id="historial" class="tab-content <?= $tab_activa == 'historial' ? 'active' : '' ?>">
        <h2 class="text-white mb-4"><i class="bi bi-clock-history"></i> Historial de Producción</h2>
        
        <?php
        // Filtros para historial
        $where = [];
        $params = [];
        $types = "";

        if (!empty($_GET['fecha_desde'])) {
            $where[] = "DATE(p.fecha) >= ?";
            $params[] = $_GET['fecha_desde'];
            $types .= "s";
        }
        if (!empty($_GET['fecha_hasta'])) {
            $where[] = "DATE(p.fecha) <= ?";
            $params[] = $_GET['fecha_hasta'];
            $types .= "s";
        }
        if (!empty($_GET['empleado_filtro'])) {
            $where[] = "p.empleado LIKE ?";
            $params[] = "%{$_GET['empleado_filtro']}%";
            $types .= "s";
        }
        if (!empty($_GET['proceso_filtro'])) {
            $where[] = "p.proceso LIKE ?";
            $params[] = "%{$_GET['proceso_filtro']}%";
            $types .= "s";
        }

        $sql = "SELECT 
                    p.*, 
                    e.codigo_empleado,
                    p.{$referencia_column} as referencia_actual
                FROM produccion_picking p
                LEFT JOIN empleados_picking e ON p.empleado = e.nombre_empleado";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY p.fecha DESC LIMIT 1000";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $historial = $stmt->get_result();
        $total_registros = $historial ? $historial->num_rows : 0;
        ?>

        <div class="filtros">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="historial">
                <div class="col-md-2">
                    <label class="form-label text-white">Fecha Desde:</label>
                    <input type="date" name="fecha_desde" class="form-control" value="<?= htmlspecialchars($_GET['fecha_desde'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label text-white">Fecha Hasta:</label>
                    <input type="date" name="fecha_hasta" class="form-control" value="<?= htmlspecialchars($_GET['fecha_hasta'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label text-white">Empleado:</label>
                    <input type="text" name="empleado_filtro" class="form-control" placeholder="Nombre del empleado" 
                           value="<?= htmlspecialchars($_GET['empleado_filtro'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label text-white">Proceso:</label>
                    <input type="text" name="proceso_filtro" class="form-control" placeholder="Nombre del proceso" 
                           value="<?= htmlspecialchars($_GET['proceso_filtro'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-search"></i> Filtrar
                    </button>
                    <a href="?tab=historial" class="btn btn-secondary w-100 mt-2">
                        <i class="bi bi-eraser"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge bg-info p-3">
                    <i class="bi bi-files"></i> Total registros: <strong><?= $total_registros ?></strong>
                </span>
                <a href="exportar_produccion.php?<?= http_build_query($_GET) ?>" class="btn btn-info text-white">
                    <i class="bi bi-download"></i> Exportar CSV
                </a>
            </div>
            <div class="scrollable">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Empleado</th>
                            <th>Código</th>
                            <th>Proceso</th>
                            <th>Referencia (Tiquete/Transferencia)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($historial && $historial->num_rows > 0): ?>
                            <?php while($row = $historial->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
                                <td><span class="badge bg-secondary"><?= date('H:i:s', strtotime($row['fecha'])) ?></span></td>
                                <td><?= htmlspecialchars($row['empleado'] ?? '') ?></td>
                                <td><span class="badge bg-dark"><?= htmlspecialchars($row['codigo_empleado'] ?? '') ?></span></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($row['proceso'] ?? '') ?></span></td>
                                <td><strong><?= htmlspecialchars($row['referencia_actual'] ?? $row['referencia'] ?? '') ?></strong></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <i class="bi bi-inbox" style="font-size: 3rem;"></i><br>
                                    <h5 class="mt-3">No hay registros con los filtros seleccionados</h5>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- MODALES -->
<!-- ========================================= -->

<!-- Modal Empleado - CORREGIDO -->
<div id="modalEmpleado" class="modal">
    <div class="modal-content">
        <h3 id="tituloModalEmpleado" class="mb-4"><i class="bi bi-person-plus-fill"></i> Agregar Empleado</h3>
        <form method="POST" id="formEmpleado" action="admin_produccion_picking.php">
            <input type="hidden" name="accion" id="empleado_accion" value="agregar_empleado">
            <input type="hidden" name="id" id="empleado_id" value="">
            <div class="form-group">
                <label for="empleado_codigo">Código de Empleado:</label>
                <input type="text" name="codigo_empleado" id="empleado_codigo" required maxlength="20" 
                       placeholder="Ej: EMP001" autocomplete="off" class="form-control">
            </div>
            <div class="form-group">
                <label for="empleado_nombre">Nombre Completo:</label>
                <input type="text" name="nombre_empleado" id="empleado_nombre" required 
                       placeholder="Ej: Juan Pérez" autocomplete="off" class="form-control">
            </div>
            <div class="text-end mt-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save"></i> <span id="btnEmpleadoTexto">Guardar</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalEmpleado')">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Proceso - CORREGIDO -->
<div id="modalProceso" class="modal">
    <div class="modal-content">
        <h3 id="tituloModalProceso" class="mb-4"><i class="bi bi-gear-fill"></i> Agregar Proceso</h3>
        <form method="POST" id="formProceso" action="admin_produccion_picking.php">
            <input type="hidden" name="accion" id="proceso_accion" value="agregar_proceso">
            <input type="hidden" name="id" id="proceso_id" value="">
            <div class="form-group">
                <label for="proceso_nombre">Nombre del Proceso:</label>
                <input type="text" name="proceso" id="proceso_nombre" required 
                       placeholder="Ej: Picking General" autocomplete="off" class="form-control">
            </div>
            <div class="text-end mt-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save"></i> <span id="btnProcesoTexto">Guardar</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalProceso')">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ver Producción Empleado -->
<div id="modalVerProduccion" class="modal">
    <div class="modal-content" style="width: 800px;">
        <h3 class="mb-4"><i class="bi bi-person-workspace"></i> Producción de: <span id="empleadoVerNombre" class="text-success"></span></h3>
        <div id="contenidoProduccionEmpleado" style="max-height: 500px; overflow-y: auto;">
            <div class="text-center py-4">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-2">Cargando registros de producción...</p>
            </div>
        </div>
        <div class="text-end mt-4">
            <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalVerProduccion')">
                <i class="bi bi-x-circle"></i> Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Firma -->
<div class="firma">
    <i class="bi bi-code-square"></i> Sistema de control de producción - Módulo Administración Picking 
    <br>
    <span style="font-size: 13px;">© <?= date("Y"); ?> | Desarrollado por: Nestor Rosales | Rosales_Dev91</span>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
    // =========================================
    // PESTAÑAS
    // =========================================
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            
            document.getElementById(tabId).classList.add('active');
            this.classList.add('active');
            
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);
        });
    });

// =========================================
// EMPLEADOS - CORREGIDO
// =========================================
function mostrarModalAgregarEmpleado() {
    document.getElementById('tituloModalEmpleado').innerHTML = '<i class="bi bi-person-plus-fill"></i> Agregar Empleado';
    document.getElementById('empleado_accion').value = 'agregar_empleado';
    document.getElementById('empleado_id').value = '';
    document.getElementById('empleado_codigo').value = '';
    document.getElementById('empleado_nombre').value = '';
    document.getElementById('btnEmpleadoTexto').textContent = 'Guardar';
    document.getElementById('modalEmpleado').style.display = 'flex';
    
    // Enfocar el primer campo
    setTimeout(() => {
        document.getElementById('empleado_codigo').focus();
    }, 100);
}

function editarEmpleado(empleado) {
    document.getElementById('tituloModalEmpleado').innerHTML = '<i class="bi bi-pencil-square"></i> Editar Empleado';
    document.getElementById('empleado_accion').value = 'editar_empleado';
    document.getElementById('empleado_id').value = empleado.id;
    document.getElementById('empleado_codigo').value = empleado.codigo_empleado;
    document.getElementById('empleado_nombre').value = empleado.nombre_empleado;
    document.getElementById('btnEmpleadoTexto').textContent = 'Actualizar';
    document.getElementById('modalEmpleado').style.display = 'flex';
}

// =========================================
// PROCESOS - CORREGIDO
// =========================================
function mostrarModalAgregarProceso() {
    document.getElementById('tituloModalProceso').innerHTML = '<i class="bi bi-plus-circle"></i> Agregar Proceso';
    document.getElementById('proceso_accion').value = 'agregar_proceso';
    document.getElementById('proceso_id').value = '';
    document.getElementById('proceso_nombre').value = '';
    document.getElementById('btnProcesoTexto').textContent = 'Guardar';
    document.getElementById('modalProceso').style.display = 'flex';
}

function editarProceso(proceso) {
    document.getElementById('tituloModalProceso').innerHTML = '<i class="bi bi-pencil-square"></i> Editar Proceso';
    document.getElementById('proceso_accion').value = 'editar_proceso';
    document.getElementById('proceso_id').value = proceso.id;
    document.getElementById('proceso_nombre').value = proceso.proceso;
    document.getElementById('btnProcesoTexto').textContent = 'Actualizar';
    document.getElementById('modalProceso').style.display = 'flex';
}

    // =========================================
    // VER PRODUCCIÓN EMPLEADO
    // =========================================
    function verProduccionEmpleado(nombre) {
        document.getElementById('empleadoVerNombre').textContent = nombre;
        document.getElementById('contenidoProduccionEmpleado').innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-2">Cargando registros de producción...</p>
            </div>
        `;
        document.getElementById('modalVerProduccion').style.display = 'flex';
        
        fetch('ajax_produccion_empleado_picking.php?empleado=' + encodeURIComponent(nombre))
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la respuesta del servidor');
                }
                return response.text();
            })
            .then(html => {
                document.getElementById('contenidoProduccionEmpleado').innerHTML = html;
            })
            .catch(error => {
                document.getElementById('contenidoProduccionEmpleado').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i> 
                        Error al cargar los datos: ${error.message}
                    </div>
                `;
            });
    }

    // =========================================
    // GENERALES
    // =========================================
    function cerrarModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        
        // Limpiar formularios
        if (modalId === 'modalEmpleado') {
            document.getElementById('formEmpleado').reset();
        } else if (modalId === 'modalProceso') {
            document.getElementById('formProceso').reset();
        }
    }

    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
            
            // Limpiar formularios al cerrar modal clickeando fuera
            if (event.target.id === 'modalEmpleado') {
                document.getElementById('formEmpleado').reset();
            } else if (event.target.id === 'modalProceso') {
                document.getElementById('formProceso').reset();
            }
        }
    });

    // Cerrar modal con tecla ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal').forEach(modal => {
                if (modal.style.display === 'flex') {
                    modal.style.display = 'none';
                    
                    // Limpiar formularios
                    if (modal.id === 'modalEmpleado') {
                        document.getElementById('formEmpleado').reset();
                    } else if (modal.id === 'modalProceso') {
                        document.getElementById('formProceso').reset();
                    }
                }
            });
        }
    });

    // =========================================
    // DATATABLES
    // =========================================
    $(document).ready(function() {
        // Inicializar DataTables
        if ($.fn.DataTable) {
            $('#tablaProduccionDiaria, #tablaEmpleados, #tablaProcesos').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json',
                    decimal: ",",
                    thousands: "."
                },
                pageLength: 25,
                order: [[0, 'desc']],
                responsive: true,
                columnDefs: [
                    { targets: 'no-sort', orderable: false }
                ]
            });
        }
        
        // Verificar y aplicar DataTables a las tablas existentes
        if ($('#tablaProduccionDiaria').length && !$.fn.DataTable.isDataTable('#tablaProduccionDiaria')) {
            $('#tablaProduccionDiaria').DataTable();
        }
        if ($('#tablaEmpleados').length && !$.fn.DataTable.isDataTable('#tablaEmpleados')) {
            $('#tablaEmpleados').DataTable();
        }
        if ($('#tablaProcesos').length && !$.fn.DataTable.isDataTable('#tablaProcesos')) {
            $('#tablaProcesos').DataTable();
        }
    });

    // Prevenir envío duplicado de formularios
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando...';
            }
        });
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
<?php $conn->close(); ?>