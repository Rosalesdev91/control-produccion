<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

require_once dirname(__DIR__) . '/config/database.php';
require_once 'registrar_actividad.php';
date_default_timezone_set('America/Costa_Rica');

$message = '';
$messageType = ''; // 'success' o 'error'

// Función auxiliar para mostrar mensajes
function setMessage(string $text, string $type = 'success'): void
{
    global $message, $messageType;
    $message = $text;
    $messageType = $type;
}

// Función para calcular tiempo transcurrido
function tiempoTranscurrido($fecha_inicio): string
{
    $inicio = new DateTime($fecha_inicio);
    $ahora = new DateTime();
    $diferencia = $inicio->diff($ahora);
    
    $horas = $diferencia->h + ($diferencia->days * 24);
    $minutos = $diferencia->i;
    
    return sprintf("%02d:%02d", $horas, $minutos);
}

// === PROCESAMIENTO CRUD ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['tabla'])) {
    $tabla = $_POST['tabla'];
    $accion = $_POST['accion'];

    try {
        $conn->begin_transaction(); // Transacción para operaciones críticas

        switch ($tabla) {
            // ---------------- ÁREAS ----------------
            case 'areas':
                $nombre = trim($_POST['nombre'] ?? '');
                $id = (int)($_POST['id'] ?? 0);

                if (empty($nombre)) {
                    throw new Exception("El nombre del área es requerido.");
                }

                if ($accion === 'crear') {
                    $check = $conn->prepare("SELECT 1 FROM areas WHERE area = ?");
                    $check->bind_param("s", $nombre);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        throw new Exception("El área ya existe.");
                    }
                    $stmt = $conn->prepare("INSERT INTO areas (area, activo) VALUES (?, 1)");
                    $stmt->bind_param("s", $nombre);
                } elseif ($accion === 'editar') {
                    $stmt = $conn->prepare("UPDATE areas SET area = ? WHERE id = ?");
                    $stmt->bind_param("si", $nombre, $id);
                } elseif ($accion === 'toggle') {
                    $activo = (int)!($_POST['activo'] ?? 0);
                    $stmt = $conn->prepare("UPDATE areas SET activo = ? WHERE id = ?");
                    $stmt->bind_param("ii", $activo, $id);
                }
                $stmt->execute();
                setMessage("Área actualizada correctamente.");
                break;

            // ---------------- EQUIPOS ----------------
            case 'equipos':
                $nombre = trim($_POST['nombre_equipo'] ?? '');
                $area_id = (int)($_POST['area_id'] ?? 0);
                $id = (int)($_POST['id'] ?? 0);

                if (empty($nombre)) {
                    throw new Exception("El nombre del equipo es requerido.");
                }

                if ($accion === 'crear') {
                    $check = $conn->prepare("SELECT 1 FROM equipos WHERE nombre_equipo = ?");
                    $check->bind_param("s", $nombre);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        throw new Exception("El equipo ya existe.");
                    }
                    $stmt = $conn->prepare("INSERT INTO equipos (nombre_equipo, area_id, activo) VALUES (?, ?, 1)");
                    $stmt->bind_param("si", $nombre, $area_id);
                } elseif ($accion === 'editar') {
                    $stmt = $conn->prepare("UPDATE equipos SET nombre_equipo = ?, area_id = ? WHERE id = ?");
                    $stmt->bind_param("sii", $nombre, $area_id, $id);
                } elseif ($accion === 'toggle') {
                    $activo = (int)!($_POST['activo'] ?? 0);
                    $stmt = $conn->prepare("UPDATE equipos SET activo = ? WHERE id = ?");
                    $stmt->bind_param("ii", $activo, $id);
                }
                $stmt->execute();
                setMessage("Equipo actualizado correctamente.");
                break;

            // ---------------- TIPOS PARO ----------------
            case 'tipos_paro':
                $nombre = trim($_POST['nombre'] ?? '');
                $id = (int)($_POST['id'] ?? 0);

                if (empty($nombre)) {
                    throw new Exception("El nombre es requerido.");
                }

                if ($accion === 'crear') {
                    $check = $conn->prepare("SELECT 1 FROM tipos_paro WHERE nombre = ?");
                    $check->bind_param("s", $nombre);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        throw new Exception("El tipo de paro ya existe.");
                    }
                    $stmt = $conn->prepare("INSERT INTO tipos_paro (nombre) VALUES (?)");
                    $stmt->bind_param("s", $nombre);
                } elseif ($accion === 'editar') {
                    $stmt = $conn->prepare("UPDATE tipos_paro SET nombre = ? WHERE id = ?");
                    $stmt->bind_param("si", $nombre, $id);
                } elseif ($accion === 'eliminar') {
                    $stmt = $conn->prepare("DELETE FROM tipos_paro WHERE id = ?");
                    $stmt->bind_param("i", $id);
                }
                $stmt->execute();
                setMessage("Tipo de paro actualizado correctamente.");
                break;

// ---------------- TÉCNICOS ----------------
case 'tecnicos':
    $nombre = trim($_POST['nombre_tecnico'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $contrasena = $_POST['contrasena'] ?? '';

    if (empty($nombre)) {
        throw new Exception("El nombre del técnico es requerido.");
    }

    if ($accion === 'crear') {
        if (empty($contrasena)) {
            throw new Exception("La contraseña es obligatoria al crear un técnico.");
        }
        // ❌ ANTES: $hash = password_hash($contrasena, PASSWORD_DEFAULT);
        // ✅ AHORA: Guardar en texto plano
        $contrasena_plano = $contrasena; // Sin encriptar

        $check = $conn->prepare("SELECT 1 FROM tecnicos WHERE nombre_tecnico = ?");
        $check->bind_param("s", $nombre);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception("El técnico ya existe.");
        }
        $stmt = $conn->prepare("INSERT INTO tecnicos (nombre_tecnico, contrasena, activo) VALUES (?, ?, 1)");
        $stmt->bind_param("ss", $nombre, $contrasena_plano); // Guardar texto plano
    } elseif ($accion === 'editar') {
        if (!empty($contrasena)) {
            // ❌ ANTES: $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            // ✅ AHORA: Guardar en texto plano
            $contrasena_plano = $contrasena;
            $stmt = $conn->prepare("UPDATE tecnicos SET nombre_tecnico = ?, contrasena = ? WHERE id = ?");
            $stmt->bind_param("ssi", $nombre, $contrasena_plano, $id);
        } else {
            $stmt = $conn->prepare("UPDATE tecnicos SET nombre_tecnico = ? WHERE id = ?");
            $stmt->bind_param("si", $nombre, $id);
        }
    } elseif ($accion === 'toggle') {
        $activo = (int)!($_POST['activo'] ?? 0);
        $stmt = $conn->prepare("UPDATE tecnicos SET activo = ? WHERE id = ?");
        $stmt->bind_param("ii", $activo, $id);
    }
    $stmt->execute();
    setMessage("Técnico actualizado correctamente.");
    break;

            // ---------------- TIPOS NO APLICA ----------------
            case 'tipos_no_aplica':
                $nombre = trim($_POST['nombre'] ?? '');
                $id = (int)($_POST['id'] ?? 0);

                if (empty($nombre)) {
                    throw new Exception("El nombre es requerido.");
                }

                if ($accion === 'crear') {
                    $check = $conn->prepare("SELECT 1 FROM tipos_no_aplica WHERE nombre = ?");
                    $check->bind_param("s", $nombre);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        throw new Exception("El tipo no aplica ya existe.");
                    }
                    $stmt = $conn->prepare("INSERT INTO tipos_no_aplica (nombre) VALUES (?)");
                    $stmt->bind_param("s", $nombre);
                } elseif ($accion === 'editar') {
                    $stmt = $conn->prepare("UPDATE tipos_no_aplica SET nombre = ? WHERE id = ?");
                    $stmt->bind_param("si", $nombre, $id);
                } elseif ($accion === 'eliminar') {
                    $stmt = $conn->prepare("DELETE FROM tipos_no_aplica WHERE id = ?");
                    $stmt->bind_param("i", $id);
                }
                $stmt->execute();
                setMessage("Tipo 'No Aplica' actualizado correctamente.");
                break;

            // ---------------- PAROS (desde admin) ----------------
            case 'paros':
                $equipo_id = (int)$_POST['equipo_id'];
                $equipo_nombre = trim($_POST['equipo_nombre']);
                $area_nombre = trim($_POST['area_nombre']);
                $empleado = $_SESSION['empleado'] ?? 'Administrador';

                if ($accion === 'iniciar_paro') {
                    // Verificar paro activo
                    $check = $conn->prepare("SELECT 1 FROM paro_produccion pp JOIN equipos e ON pp.equipo = e.nombre_equipo WHERE e.id = ? AND pp.activo = 1");
                    $check->bind_param("i", $equipo_id);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        setMessage("Ya existe un paro activo para este equipo.", "error");
                    } else {
                        $motivo = "Paro iniciado desde panel de administración";
                        $tipo_paro = "Administrativo";
                        $fecha = date('Y-m-d H:i:s');

                        // Insertar solicitud
                        $stmt1 = $conn->prepare("INSERT INTO solicitudes_paro (empleado, area, equipo, motivo, tipo_paro, fecha_solicitud, estado) VALUES (?, ?, ?, ?, ?, ?, 'iniciada')");
                        $stmt1->bind_param("ssssss", $empleado, $area_nombre, $equipo_nombre, $motivo, $tipo_paro, $fecha);
                        $stmt1->execute();
                        $id_solicitud = $conn->insert_id;

                        // Insertar paro
                        $stmt2 = $conn->prepare("INSERT INTO paro_produccion (id_solicitud, area, equipo, empleado, fecha_inicio, activo, tipo_paro) VALUES (?, ?, ?, ?, ?, 1, ?)");
                        $stmt2->bind_param("isssss", $id_solicitud, $area_nombre, $equipo_nombre, $empleado, $fecha, $tipo_paro);
                        $stmt2->execute();

                        setMessage("Paro iniciado correctamente para: " . htmlspecialchars($equipo_nombre));
                    }
                    $check->close();
                } elseif ($accion === 'finalizar_paro') {
                    $stmt = $conn->prepare("SELECT pp.id, sp.id AS solicitud_id FROM paro_produccion pp JOIN equipos e ON pp.equipo = e.nombre_equipo JOIN solicitudes_paro sp ON pp.id_solicitud = sp.id WHERE e.id = ? AND pp.activo = 1");
                    $stmt->bind_param("i", $equipo_id);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($row = $result->fetch_assoc()) {
                        $fecha_fin = date('Y-m-d H:i:s');

                        $upd1 = $conn->prepare("UPDATE paro_produccion SET fecha_fin = ?, activo = 0 WHERE id = ?");
                        $upd1->bind_param("si", $fecha_fin, $row['id']);
                        $upd1->execute();

                        $upd2 = $conn->prepare("UPDATE solicitudes_paro SET estado = 'finalizada' WHERE id = ?");
                        $upd2->bind_param("i", $row['solicitud_id']);
                        $upd2->execute();

                        setMessage("Paro finalizado correctamente para: " . htmlspecialchars($equipo_nombre));
                    } else {
                        setMessage("No hay paro activo para este equipo.", "error");
                    }
                    $stmt->close();
                }
                break;
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        setMessage("Error: " . $e->getMessage(), "error");
    }
}

// === CARGA DE DATOS ===
$areas = $conn->query("SELECT * FROM areas ORDER BY area")->fetch_all(MYSQLI_ASSOC);
$areas_activas = $conn->query("SELECT id, area FROM areas WHERE activo = 1 ORDER BY area")->fetch_all(MYSQLI_ASSOC);

$equipos = $conn->query("
    SELECT e.*, a.area, a.activo AS area_activa,
           pp.id AS paro_id, 
           pp.fecha_inicio AS paro_inicio,
           pp.empleado AS paro_empleado,
           pp.tipo_paro AS paro_tipo,
           sp.motivo AS paro_motivo,
           sp.estado AS paro_estado,
           sp.fecha_solicitud AS paro_fecha_solicitud
    FROM equipos e
    JOIN areas a ON e.area_id = a.id
    LEFT JOIN paro_produccion pp ON pp.equipo = e.nombre_equipo AND pp.activo = 1
    LEFT JOIN solicitudes_paro sp ON pp.id_solicitud = sp.id
    ORDER BY a.area, e.nombre_equipo
")->fetch_all(MYSQLI_ASSOC);

// Marcar estado de paro
foreach ($equipos as &$e) {
    $e['paro_activo'] = !empty($e['paro_id']);
    $e['paro_duracion'] = $e['paro_activo'] ? 
        tiempoTranscurrido($e['paro_inicio']) : '';
}
unset($e);

$paros_detalles = [];
foreach ($equipos as $e) {
    if ($e['paro_activo']) {
        $paros_detalles[$e['id']] = [
            'equipo' => $e['nombre_equipo'],
            'area' => $e['area'],
            'fecha_inicio' => $e['paro_inicio'],
            'duracion' => $e['paro_duracion'],
            'empleado' => $e['paro_empleado'] ?? 'No especificado',
            'tipo_paro' => $e['paro_tipo'] ?? 'No especificado',
            'motivo' => $e['paro_motivo'] ?? 'No especificado',
            'estado' => $e['paro_estado'] ?? 'activo',
            'fecha_solicitud' => $e['paro_fecha_solicitud'] ?? $e['paro_inicio']
        ];
    }
}

// Convierte a JSON para usar en JavaScript
$paros_json = json_encode($paros_detalles);

$tipos_paro = $conn->query("SELECT * FROM tipos_paro ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$tecnicos = $conn->query("SELECT * FROM tecnicos ORDER BY nombre_tecnico")->fetch_all(MYSQLI_ASSOC);
$tipos_no_aplica = $conn->query("SELECT * FROM tipos_no_aplica ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Determinar pestaña activa
$tab_activa = isset($_GET['tab']) ? $_GET['tab'] : 'areas';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - Catálogos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #eab308;
            --info: #3b82f6;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --transition: all 0.2s ease-in-out;
            --radius: 0.5rem;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f8fafc;
            color: var(--gray-800); 
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header mejorado */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            color: white;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            color: white;
        }
        
        .header-title p {
            margin: 0.25rem 0 0 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.875rem;
        }
        
        .header-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        /* Botones */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        .btn-primary {
            background: white;
            color: var(--primary);
        }
        
        .btn-primary:hover {
            background: var(--gray-100);
        }
        
        .btn-icon {
            padding: 0.5rem;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .btn-icon:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        /* Estilos de pestañas (igual a administracion_tablas) */
        .tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--primary);
            flex-wrap: wrap;
            gap: 0.25rem;
        }
        
        .tab-btn {
            padding: 0.75rem 1.25rem;
            background: var(--gray-200);
            color: var(--gray-700);
            border: none;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: var(--transition);
            border-radius: var(--radius) var(--radius) 0 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .tab-btn:hover {
            background: var(--gray-300);
        }
        
        .tab-btn.active {
            background: var(--primary);
            color: white;
        }
        
        .tab-content {
            display: none;
            padding: 1.5rem;
            background: white;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
            min-height: 400px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Contenido de pestañas */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .content-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            color: var(--gray-800);
        }
        
        .content-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        /* Formularios compactos */
        .form-input {
            padding: 0.5rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.875rem;
            width: 100%;
            box-sizing: border-box;
        }
        
        .search-input {
            width: 200px;
        }
        
        /* Tablas compactas */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            max-height: 500px;
            overflow-y: auto;
        }
        
        .table-compact {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 0;
            font-size: 0.875rem;
        }
        
        .table-compact th {
            background: var(--gray-100);
            color: var(--gray-600);
            font-weight: 500;
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .table-compact td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .table-compact tr:hover {
            background: var(--gray-50);
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Acciones en tabla */
        .table-actions {
            display: flex;
            gap: 0.25rem;
            flex-wrap: nowrap;
        }
        
        .btn-action {
            padding: 0.375rem 0.5rem;
            border-radius: 0.375rem;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: var(--transition);
            color: var(--gray-500);
        }
        
        .btn-action:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        .btn-action.edit:hover {
            color: var(--warning);
        }
        
        .btn-action.toggle:hover {
            color: var(--success);
        }
        
        .btn-action.delete:hover {
            color: var(--danger);
        }
        
        .btn-action.paro:hover {
            color: var(--info);
        }
        
        .btn-action.info:hover {
            color: var(--info);
        }
        
        /* Mensajes */
        .message {
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
        }
        
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        /* Paro activo */
        .paro-active-row {
            background: rgba(239, 68, 68, 0.05) !important;
            position: relative;
        }
        
        .paro-active-row::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--danger);
            border-radius: var(--radius) 0 0 var(--radius);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .modal-title {
            margin: 0;
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .close-modal {
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--gray-500);
        }
        
        /* Formulario modal */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0.75rem;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .content-header {
                flex-direction: column;
                gap: 0.75rem;
                align-items: flex-start;
            }
            
            .content-actions {
                width: 100%;
            }
            
            .search-input {
                width: 100%;
            }
            
            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            
            .tab-btn {
                white-space: nowrap;
            }
        }
        
        .info-card {
            background: var(--gray-50);
            padding: 0.75rem;
            border-radius: var(--radius);
            border-left: 3px solid var(--primary);
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <h1>Panel de Administración - Catálogos</h1>
                <p>Gestión de catálogos del sistema de paros</p>
            </div>
            <div class="header-actions">
                <a href="dashboard_admin_paros.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Volver al Dashboard
                </a>
            </div>
        </div>
    </header>

    <!-- Mensaje de notificación -->
    <?php if ($message): ?>
    <div class="message">
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>">
            <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pestañas principales -->
    <div class="tabs">
        <button class="tab-btn <?= $tab_activa == 'areas' ? 'active' : '' ?>" data-tab="areas">
            <i class="fas fa-map-marker-alt"></i> Áreas
        </button>
        <button class="tab-btn <?= $tab_activa == 'equipos' ? 'active' : '' ?>" data-tab="equipos">
            <i class="fas fa-cogs"></i> Equipos
        </button>
        <button class="tab-btn <?= $tab_activa == 'tipos_paro' ? 'active' : '' ?>" data-tab="tipos_paro">
            <i class="fas fa-exclamation-triangle"></i> Tipos de Paro
        </button>
        <button class="tab-btn <?= $tab_activa == 'tecnicos' ? 'active' : '' ?>" data-tab="tecnicos">
            <i class="fas fa-user-tie"></i> Técnicos
        </button>
        <button class="tab-btn <?= $tab_activa == 'no_aplica' ? 'active' : '' ?>" data-tab="no_aplica">
            <i class="fas fa-ban"></i> No Aplica
        </button>
    </div>

    <!-- Contenido de pestañas -->
    
    <!-- ÁREAS -->
    <div id="areas" class="tab-content <?= $tab_activa == 'areas' ? 'active' : '' ?>">
        <div class="content-header">
            <h2 class="content-title">Gestión de Áreas</h2>
            <div class="content-actions">
                <input type="text" placeholder="Buscar áreas..." class="form-input search-input" onkeyup="searchTable(this, 'areas-table')">
                <button class="btn btn-primary" onclick="openModal('create-area')">
                    <i class="fas fa-plus"></i> Crear Área
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="table-compact" id="areas-table">
                <thead>
                    <tr>
                        <th>Área</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($areas as $a): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($a['area']) ?></strong></td>
                        <td>
                            <span class="badge <?= $a['activo'] ? 'badge-success' : 'badge-danger' ?>">
                                <i class="fas <?= $a['activo'] ? 'fa-check' : 'fa-times' ?>"></i>
                                <?= $a['activo'] ? 'Activa' : 'Inactiva' ?>
                            </span>
                        </td>
                        <td class="table-actions">
                            <button class="btn-action edit" onclick="openEditModal('area', <?= $a['id'] ?>, '<?= htmlspecialchars($a['area']) ?>', <?= $a['activo'] ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="tabla" value="areas">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="activo" value="<?= $a['activo'] ?>">
                                <input type="hidden" name="accion" value="toggle">
                                <button type="submit" class="btn-action toggle" title="<?= $a['activo'] ? 'Desactivar' : 'Activar' ?>">
                                    <i class="fas <?= $a['activo'] ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- EQUIPOS -->
    <div id="equipos" class="tab-content <?= $tab_activa == 'equipos' ? 'active' : '' ?>">
        <div class="content-header">
            <h2 class="content-title">Gestión de Equipos</h2>
            <div class="content-actions">
                <input type="text" placeholder="Buscar equipos..." class="form-input search-input" onkeyup="searchTable(this, 'equipos-table')">
                <button class="btn btn-primary" onclick="openModal('create-equipo')">
                    <i class="fas fa-plus"></i> Crear Equipo
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="table-compact" id="equipos-table">
                <thead>
                    <tr>
                        <th>Equipo</th>
                        <th>Área</th>
                        <th>Estado</th>
                        <th>Paro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipos as $e): ?>
                    <tr class="<?= $e['paro_activo'] ? 'paro-active-row' : '' ?>">
                        <td><strong><?= htmlspecialchars($e['nombre_equipo']) ?></strong></td>
                        <td><?= htmlspecialchars($e['area']) ?></td>
                        <td>
                            <span class="badge <?= $e['activo'] ? 'badge-success' : 'badge-danger' ?>">
                                <i class="fas <?= $e['activo'] ? 'fa-check' : 'fa-times' ?>"></i>
                                <?= $e['activo'] ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $e['paro_activo'] ? 'badge-danger' : 'badge-success' ?>">
                                <i class="fas <?= $e['paro_activo'] ? 'fa-exclamation-triangle' : 'fa-check-circle' ?>"></i>
                                <?= $e['paro_activo'] ? 'Paro Activo' : 'Sin Paro' ?>
                            </span>
                            <?php if ($e['paro_activo'] && $e['paro_inicio']): ?>
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                                    <i class="fas fa-clock"></i> Desde: <?= date('H:i', strtotime($e['paro_inicio'])) ?>
                                    <br>
                                    <button class="btn-action info" 
                                            onclick="verDetallesParo(<?= $e['id'] ?>, '<?= htmlspecialchars($e['nombre_equipo']) ?>')"
                                            style="background: none; border: none; color: var(--info); cursor: pointer; padding: 0; margin-top: 2px;">
                                        <i class="fas fa-info-circle"></i> Ver detalles
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <button class="btn-action edit" onclick="openEditModal('equipo', <?= $e['id'] ?>, '<?= htmlspecialchars($e['nombre_equipo']) ?>', <?= $e['activo'] ?>, <?= $e['area_id'] ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="tabla" value="equipos">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <input type="hidden" name="activo" value="<?= $e['activo'] ?>">
                                <input type="hidden" name="accion" value="toggle">
                                <button type="submit" class="btn-action toggle" title="<?= $e['activo'] ? 'Desactivar' : 'Activar' ?>">
                                    <i class="fas <?= $e['activo'] ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="tabla" value="paros">
                                <input type="hidden" name="equipo_id" value="<?= $e['id'] ?>">
                                <input type="hidden" name="equipo_nombre" value="<?= htmlspecialchars($e['nombre_equipo']) ?>">
                                <input type="hidden" name="area_nombre" value="<?= htmlspecialchars($e['area']) ?>">
                                <input type="hidden" name="accion" value="<?= $e['paro_activo'] ? 'finalizar_paro' : 'iniciar_paro' ?>">
                                <button type="submit" class="btn-action paro" title="<?= $e['paro_activo'] ? 'Finalizar Paro' : 'Iniciar Paro' ?>" onclick="return confirm('¿<?= $e['paro_activo'] ? 'Finalizar' : 'Iniciar' ?> el paro del equipo <?= htmlspecialchars($e['nombre_equipo']) ?>?')">
                                    <i class="fas <?= $e['paro_activo'] ? 'fa-stop-circle' : 'fa-play-circle' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TIPOS DE PARO -->
    <div id="tipos_paro" class="tab-content <?= $tab_activa == 'tipos_paro' ? 'active' : '' ?>">
        <div class="content-header">
            <h2 class="content-title">Tipos de Paro</h2>
            <div class="content-actions">
                <input type="text" placeholder="Buscar tipos de paro..." class="form-input search-input" onkeyup="searchTable(this, 'tipos-paro-table')">
                <button class="btn btn-primary" onclick="openModal('create-tipo-paro')">
                    <i class="fas fa-plus"></i> Agregar
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="table-compact" id="tipos-paro-table">
                <thead>
                    <tr>
                        <th>Tipo de Paro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tipos_paro as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['nombre']) ?></td>
                        <td class="table-actions">
                            <button class="btn-action edit" onclick="openEditModal('tipo-paro', <?= $t['id'] ?>, '<?= htmlspecialchars($t['nombre']) ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="tabla" value="tipos_paro">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <button type="submit" class="btn-action delete" onclick="return confirm('¿Eliminar este tipo de paro?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TÉCNICOS -->
    <div id="tecnicos" class="tab-content <?= $tab_activa == 'tecnicos' ? 'active' : '' ?>">
        <div class="content-header">
            <h2 class="content-title">Gestión de Técnicos</h2>
            <div class="content-actions">
                <input type="text" placeholder="Buscar técnicos..." class="form-input search-input" onkeyup="searchTable(this, 'tecnicos-table')">
                <button class="btn btn-primary" onclick="openModal('create-tecnico')">
                    <i class="fas fa-plus"></i> Crear Técnico
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="table-compact" id="tecnicos-table">
                <thead>
                    <tr>
                        <th>Técnico</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tecnicos as $t): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($t['nombre_tecnico']) ?></strong></td>
                        <td>
                            <span class="badge <?= $t['activo'] ? 'badge-success' : 'badge-danger' ?>">
                                <i class="fas <?= $t['activo'] ? 'fa-check' : 'fa-times' ?>"></i>
                                <?= $t['activo'] ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td class="table-actions">
                            <button class="btn-action edit" onclick="openEditModal('tecnico', <?= $t['id'] ?>, '<?= htmlspecialchars($t['nombre_tecnico']) ?>', <?= $t['activo'] ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="tabla" value="tecnicos">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="activo" value="<?= $t['activo'] ?>">
                                <input type="hidden" name="accion" value="toggle">
                                <button type="submit" class="btn-action toggle" title="<?= $t['activo'] ? 'Desactivar' : 'Activar' ?>">
                                    <i class="fas <?= $t['activo'] ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TIPOS NO APLICA -->
    <div id="no_aplica" class="tab-content <?= $tab_activa == 'no_aplica' ? 'active' : '' ?>">
        <div class="content-header">
            <h2 class="content-title">Tipos "No Aplica"</h2>
            <div class="content-actions">
                <input type="text" placeholder="Buscar tipos no aplica..." class="form-input search-input" onkeyup="searchTable(this, 'no-aplica-table')">
                <button class="btn btn-primary" onclick="openModal('create-no-aplica')">
                    <i class="fas fa-plus"></i> Agregar
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="table-compact" id="no-aplica-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tipos_no_aplica as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['nombre']) ?></td>
                        <td class="table-actions">
                            <button class="btn-action edit" onclick="openEditModal('no-aplica', <?= $t['id'] ?>, '<?= htmlspecialchars($t['nombre']) ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="tabla" value="tipos_no_aplica">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <button type="submit" class="btn-action delete" onclick="return confirm('¿Eliminar este tipo?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div id="modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"></h2>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <form id="modal-form" method="POST" onsubmit="return validateForm()">
                <!-- Contenido dinámico -->
            </form>
        </div>
    </div>
    
    <!-- Modal de detalles del paro -->
    <div id="modal-paro-detalles" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 class="modal-title">Detalles del Paro Activo</h2>
                <span class="close-modal" onclick="closeParoModal()">&times;</span>
            </div>
            <div id="paro-detalles-content" style="padding: 1rem 0;">
                <!-- Contenido dinámico -->
            </div>
        </div>
    </div>
</div>

<script>
// Manejo de pestañas (igual a administracion_tablas)
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function(event) {
        event.preventDefault();
        // Ocultar todos los contenidos
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Desactivar todos los botones
        document.querySelectorAll('.tab-btn').forEach(tabBtn => {
            tabBtn.classList.remove('active');
        });
        
        // Mostrar contenido seleccionado
        const tabId = this.getAttribute('data-tab');
        document.getElementById(tabId).classList.add('active');
        this.classList.add('active');
        
        // Actualizar la URL con el parámetro de pestaña
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    });
});

// Funciones para modales
const modal = document.getElementById('modal');
const modalTitle = document.querySelector('.modal-title');
const modalForm = document.getElementById('modal-form');

function openModal(type) {
    modal.style.display = 'flex';
    modalForm.innerHTML = '';
    modalForm.action = '';
    let content = '';

    if (type === 'create-area') {
        modalTitle.textContent = 'Crear Nueva Área';
        content = `
            <input type="hidden" name="tabla" value="areas">
            <input type="hidden" name="accion" value="crear">
            <div class="form-group">
                <label class="form-label">Nombre del Área</label>
                <input type="text" name="nombre" class="form-input" placeholder="Ej: Inyección" required>
            </div>
            <button type="submit" class="btn btn-primary">Crear</button>
        `;
    } else if (type === 'create-equipo') {
        modalTitle.textContent = 'Crear Nuevo Equipo';
        content = `
            <input type="hidden" name="tabla" value="equipos">
            <input type="hidden" name="accion" value="crear">
            <div class="form-group">
                <label class="form-label">Nombre del Equipo</label>
                <input type="text" name="nombre_equipo" class="form-input" placeholder="Ej: Máquina 01" required>
            </div>
            <div class="form-group">
                <label class="form-label">Área</label>
                <select name="area_id" class="form-input" required>
                    <?php foreach ($areas_activas as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['area']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Crear</button>
        `;
    } else if (type === 'create-tipo-paro') {
        modalTitle.textContent = 'Agregar Tipo de Paro';
        content = `
            <input type="hidden" name="tabla" value="tipos_paro">
            <input type="hidden" name="accion" value="crear">
            <div class="form-group">
                <label class="form-label">Nombre</label>
                <input type="text" name="nombre" class="form-input" placeholder="Ej: Falla Eléctrica" required>
            </div>
            <button type="submit" class="btn btn-primary">Agregar</button>
        `;
    } else if (type === 'create-tecnico') {
        modalTitle.textContent = 'Crear Nuevo Técnico';
        content = `
            <input type="hidden" name="tabla" value="tecnicos">
            <input type="hidden" name="accion" value="crear">
            <div class="form-group">
                <label class="form-label">Nombre del Técnico</label>
                <input type="text" name="nombre_tecnico" class="form-input" placeholder="Ej: Juan Pérez" required>
            </div>
            <div class="form-group">
                <label class="form-label">Contraseña</label>
                <input type="password" name="contrasena" class="form-input" placeholder="Obligatoria" required>
            </div>
            <button type="submit" class="btn btn-primary">Crear</button>
        `;
    } else if (type === 'create-no-aplica') {
        modalTitle.textContent = 'Agregar Tipo No Aplica';
        content = `
            <input type="hidden" name="tabla" value="tipos_no_aplica">
            <input type="hidden" name="accion" value="crear">
            <div class="form-group">
                <label class="form-label">Nombre</label>
                <input type="text" name="nombre" class="form-input" placeholder="Ej: Cambio de Turno" required>
            </div>
            <button type="submit" class="btn btn-primary">Agregar</button>
        `;
    }

    modalForm.innerHTML = content;
}

function openEditModal(type, id, name, activo = null, area_id = null) {
    openModal(`edit-${type}`);
    modalTitle.textContent = `Editar ${type.charAt(0).toUpperCase() + type.slice(1)}`;
    let content = `
        <input type="hidden" name="tabla" value="${type === 'area' ? 'areas' : type === 'equipo' ? 'equipos' : type === 'tipo-paro' ? 'tipos_paro' : type === 'tecnico' ? 'tecnicos' : 'tipos_no_aplica'}">
        <input type="hidden" name="id" value="${id}">
        <input type="hidden" name="accion" value="editar">
    `;

    if (type === 'area' || type === 'tipo-paro' || type === 'no-aplica') {
        content += `
            <div class="form-group">
                <label class="form-label">Nombre</label>
                <input type="text" name="${type === 'area' ? 'nombre' : 'nombre'}" class="form-input" value="${name}" required>
            </div>
        `;
    } else if (type === 'equipo') {
        content += `
            <div class="form-group">
                <label class="form-label">Nombre del Equipo</label>
                <input type="text" name="nombre_equipo" class="form-input" value="${name}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Área</label>
                <select name="area_id" class="form-input" required>
                    <?php foreach ($areas_activas as $a): ?>
                        <option value="<?= $a['id'] ?>" ${area_id == <?= $a['id'] ?> ? 'selected' : ''}><?= htmlspecialchars($a['area']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        `;
    } else if (type === 'tecnico') {
        content += `
            <div class="form-group">
                <label class="form-label">Nombre del Técnico</label>
                <input type="text" name="nombre_tecnico" class="form-input" value="${name}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Nueva Contraseña (opcional)</label>
                <input type="password" name="contrasena" class="form-input" placeholder="Dejar en blanco para no cambiar">
            </div>
        `;
    }

    content += `<button type="submit" class="btn btn-primary">Guardar Cambios</button>`;
    modalForm.innerHTML = content;
}

function closeModal() {
    modal.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == modal) {
        closeModal();
    }
}

// Validación de formulario
function validateForm() {
    let valid = true;
    modalForm.querySelectorAll('input[required]').forEach(input => {
        if (!input.value.trim()) {
            input.style.borderColor = '#ef4444';
            valid = false;
        } else {
            input.style.borderColor = '';
        }
    });
    return valid;
}

// Búsqueda en tabla
function searchTable(input, tableId) {
    const filter = input.value.toLowerCase();
    const table = document.getElementById(tableId);
    const tr = table.getElementsByTagName('tr');
    for (let i = 1; i < tr.length; i++) {
        let found = false;
        const td = tr[i].getElementsByTagName('td');
        for (let j = 0; j < td.length; j++) {
            if (td[j] && td[j].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        tr[i].style.display = found ? '' : 'none';
    }
}

// Auto-cerrar mensajes después de 5 segundos
setTimeout(() => {
    const message = document.querySelector('.message');
    if (message) {
        message.style.opacity = '0';
        setTimeout(() => {
            if (message.parentNode) {
                message.parentNode.removeChild(message);
            }
        }, 300);
    }
}, 5000);
</script>
<script>
// Datos de paros (convertidos desde PHP)
const parosDetalles = <?= $paros_json ?? '{}' ?>;

function verDetallesParo(equipoId, equipoNombre) {
    const modal = document.getElementById('modal-paro-detalles');
    const contenido = document.getElementById('paro-detalles-content');
    
    if (parosDetalles[equipoId]) {
        const paro = parosDetalles[equipoId];
        
        contenido.innerHTML = `
            <div style="margin-bottom: 1.5rem;">
                <h3 style="margin: 0 0 0.5rem 0; color: var(--gray-800);">
                    <i class="fas fa-cogs"></i> ${equipoNombre}
                </h3>
                <div style="font-size: 0.875rem; color: var(--gray-600);">
                    <i class="fas fa-map-marker-alt"></i> ${paro.area}
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="info-card">
                    <div class="form-label">Fecha de Inicio</div>
                    <div style="font-weight: 500;">${formatFecha(paro.fecha_inicio)}</div>
                </div>
                <div class="info-card">
                    <div class="form-label">Duración</div>
                    <div style="font-weight: 500; color: var(--danger);">
                        <i class="fas fa-clock"></i> ${paro.duracion} horas
                    </div>
                </div>
                <div class="info-card">
                    <div class="form-label">Empleado</div>
                    <div style="font-weight: 500;">${paro.empleado}</div>
                </div>
                <div class="info-card">
                    <div class="form-label">Tipo de Paro</div>
                    <div style="font-weight: 500;">${paro.tipo_paro}</div>
                </div>
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <div class="form-label">Motivo del Paro</div>
                <div style="background: var(--gray-100); padding: 0.75rem; border-radius: var(--radius); font-size: 0.875rem;">
                    ${paro.motivo}
                </div>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                <div style="font-size: 0.75rem; color: var(--gray-500);">
                    <i class="fas fa-history"></i> Actualizado: ${formatFecha(paro.fecha_solicitud)}
                </div>
                <div>
                    <span class="badge badge-danger">
                        <i class="fas fa-exclamation-circle"></i> ${paro.estado.toUpperCase()}
                    </span>
                </div>
            </div>
        `;
        
        modal.style.display = 'flex';
    } else {
        contenido.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <i class="fas fa-info-circle" style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem;"></i>
                <p>No se encontraron detalles del paro activo.</p>
            </div>
        `;
        modal.style.display = 'flex';
    }
}

function formatFecha(fechaStr) {
    if (!fechaStr) return 'No disponible';
    const fecha = new Date(fechaStr);
    return fecha.toLocaleDateString('es-CR', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function closeParoModal() {
    document.getElementById('modal-paro-detalles').style.display = 'none';
}

// Cierra el modal al hacer clic fuera
document.getElementById('modal-paro-detalles').addEventListener('click', function(e) {
    if (e.target === this) {
        closeParoModal();
    }
});
</script>
</body>
</html>