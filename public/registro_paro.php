<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once '../includes/pdf_generator.php';
include_once "C:/xampp/htdocs/control_produccion/whatsapp-bot/whatsapp.php";
require_once 'auto_audit_empleados.php';
require_once 'registrar_actividad.php';


// Configuración inicial
$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');
// Al inicio, después de session_start(), puedes definir manualmente para pruebas:
$_SESSION['es_supervisor'] = true; // Solo para pruebas, luego lo quitas
// Al inicio, después de session_start(), puedes definir manualmente para pruebas:
$_SESSION['rol'] = true; // Solo para pruebas, luego lo quitas

// Inicialización de variables
$mensaje = '';
$codigoEmpleado = $_SESSION['codigoEmpleado'] ?? '';
$nombreEmpleado = $_SESSION['nombreEmpleado'] ?? '';
$area_seleccionada = $_SESSION['area_seleccionada'] ?? '';
$equipo_seleccionado = $_SESSION['equipo_seleccionado'] ?? '';
$ultimo_motivo_solicitud = '';
$solicitudes_pendientes = [];
$paros_activos = [];
$paro_activo_otro_empleado = null;
$historial = [];

// Limpieza al cambiar empleado
if (isset($_POST['cambiar_empleado'])) {
    unset(
        $_SESSION['codigoEmpleado'],
        $_SESSION['nombreEmpleado'],
        $_SESSION['empleado'],
        $_SESSION['area_seleccionada'],
        $_SESSION['equipo_seleccionado'],
        $_SESSION['mensaje_exito'],
        $_SESSION['mensaje_error']
    );
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Procesamiento del formulario principal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Consultar empleado
    if (isset($_POST['consultar_empleado'])) {
        $codigoEmpleado = trim($_POST['codigo_empleado'] ?? '');
        
        if (!empty($codigoEmpleado)) {
            $stmt = $conn->prepare("SELECT nombre_empleado FROM empleados WHERE codigo_empleado = ?");
            $stmt->bind_param("s", $codigoEmpleado);
            
            if ($stmt->execute() && $stmt->bind_result($nombre) && $stmt->fetch()) {
                $_SESSION['codigoEmpleado'] = $codigoEmpleado;
                $_SESSION['nombreEmpleado'] = $nombre;
                $_SESSION['empleado'] = $nombre;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $mensaje = "Empleado no encontrado con el código: " . htmlspecialchars($codigoEmpleado);
            }
            $stmt->close();
        }
    }
    
    // Selección de área y equipo
    elseif (isset($_POST['seleccionar_area_equipo'])) {
        $area = trim($_POST['area'] ?? '');
        $equipo = trim($_POST['equipo'] ?? '');
        
        if (!empty($area)) {
            $_SESSION['area_seleccionada'] = $area;
            $_SESSION['equipo_seleccionado'] = $equipo;
            $_SESSION['mensaje_exito'] = "Área y equipo seleccionados correctamente";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    // Cambiar área/equipo
    elseif (isset($_POST['cambiar_area_equipo'])) {
        unset($_SESSION['area_seleccionada'], $_SESSION['equipo_seleccionado']);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Crear solicitud de paro (EQUIPO OPCIONAL + SIN ENLACE EN WHATSAPP)
    elseif (isset($_POST['crear_solicitud'])) {
        $area        = $_SESSION['area_seleccionada'] ?? '';
        $equipo      = $_SESSION['equipo_seleccionado'] ?? '';   // Puede estar vacío
        $motivo      = trim($_POST['motivo_solicitud'] ?? '');
        $tipo_paro   = trim($_POST['tipo_paro'] ?? '');
        $empleado    = $_SESSION['nombreEmpleado'] ?? '';

        // Validación básica
        if (empty($area) || empty($motivo) || empty($tipo_paro) || empty($empleado)) {
            $_SESSION['mensaje_error'] = "Área, tipo de paro, motivo y empleado son obligatorios.";
        } else {

            // VALIDAR DUPLICADOS (área + equipo o solo área)
            if (!empty($equipo)) {
                $sql_check = "SELECT id FROM solicitudes_paro 
                              WHERE empleado = ? AND area = ? AND equipo = ? 
                              AND estado IN ('pendiente', 'iniciada')";
                $params    = "sss";
                $values    = [$empleado, $area, $equipo];
            } else {
                $sql_check = "SELECT id FROM solicitudes_paro 
                              WHERE empleado = ? AND area = ? AND (equipo = '' OR equipo IS NULL)
                              AND estado IN ('pendiente', 'iniciada')";
                $params    = "ss";
                $values    = [$empleado, $area];
            }

            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param($params, ...$values);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $_SESSION['mensaje_error'] = "Ya existe una solicitud pendiente o iniciada para " .
                    (!empty($equipo) ? "el equipo '$equipo' en el área '$area'" : "el área '$area'");
            } else {
                // CREAR SOLICITUD
                $fecha_solicitud = date('Y-m-d H:i:s');

                $stmt = $conn->prepare("INSERT INTO solicitudes_paro 
                    (empleado, area, equipo, motivo, tipo_paro, fecha_solicitud, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pendiente')");
                $stmt->bind_param("ssssss", $empleado, $area, $equipo, $motivo, $tipo_paro, $fecha_solicitud);

                if ($stmt->execute()) {
                    $id_solicitud = $conn->insert_id;
                    $_SESSION['mensaje_exito'] = "Solicitud de paro creada correctamente. ID: $id_solicitud";

                    // AUTO-INICIAR "Sin WIP" y "Servidor"
                    if ($tipo_paro === 'Sin WIP' || $tipo_paro === 'Servidor') {
                        $fecha_inicio = date('Y-m-d H:i:s');
                        $stmt_pp = $conn->prepare("INSERT INTO paro_produccion 
                            (id_solicitud, area, equipo, empleado, fecha_inicio, activo, tipo_paro) 
                            VALUES (?, ?, ?, ?, ?, 1, ?)");
                        $stmt_pp->bind_param("isssss", $id_solicitud, $area, $equipo, $empleado, $fecha_inicio, $tipo_paro);
                        $stmt_pp->execute();
                        $stmt_pp->close();

                        $conn->query("UPDATE solicitudes_paro SET estado = 'iniciada' WHERE id = $id_solicitud");
                    }
                    // NOTIFICACIÓN WHATSAPP (sin enlace) - CON ICONOS
                    else {
                        $equipoTexto = !empty($equipo) ? $equipo : 'Área completa';
                        $mensajeWhatsApp = 
                            "⚠️ *ALERTA: SOLICITUD DE PARO REGISTRADA* ⚠️\n\n" .
                            "🔢 *NÚMERO DE SOLICITUD:* `$id_solicitud`\n" .
                            "👤 *EMPLEADO:* $empleado\n" .
                            "🏭 *ÁREA:* $area\n" .
                            "🔧 *EQUIPO:* $equipoTexto\n" .
                            "⏹️ *TIPO DE PARO:* $tipo_paro\n" .
                            "❗ *MOTIVO:* $motivo\n" .
                            "📅 *FECHA Y HORA:* $fecha_solicitud\n\n" .
                            "🛑 _Favor de atender con urgencia_";

                        enviarWhatsAppATodos($mensajeWhatsApp);
                    }

                    // Limpiar selección
                    unset($_SESSION['area_seleccionada'], $_SESSION['equipo_seleccionado']);
                }
                $stmt->close();
            }
            $stmt_check->close();
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Finalizar paro para Sin WIP y Servidor
    elseif (isset($_POST['finalizar_paro'])) {
        $id_solicitud = (int)($_POST['id_solicitud'] ?? 0);
        
        if ($id_solicitud > 0 && !empty($nombreEmpleado)) {
            $stmt_check = $conn->prepare("SELECT sp.id, sp.tipo_paro, pp.activo, pp.id AS pp_id 
                                         FROM solicitudes_paro sp 
                                         JOIN paro_produccion pp ON sp.id = pp.id_solicitud 
                                         WHERE sp.id = ? AND sp.empleado = ? AND sp.tipo_paro IN ('Sin WIP', 'Servidor') AND pp.activo = 1");
            $stmt_check->bind_param("is", $id_solicitud, $nombreEmpleado);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($row = $result_check->fetch_assoc()) {
                $fecha_fin = date('Y-m-d H:i:s');
                $stmt_fin = $conn->prepare("UPDATE paro_produccion SET fecha_fin = ?, activo = 0 WHERE id = ?");
                $stmt_fin->bind_param("si", $fecha_fin, $row['pp_id']);
                
                if ($stmt_fin->execute()) {
                    $stmt_est = $conn->prepare("UPDATE solicitudes_paro SET estado = 'finalizada' WHERE id = ?");
                    $stmt_est->bind_param("i", $id_solicitud);
                    $stmt_est->execute();
                    $stmt_est->close();
                    $_SESSION['mensaje_exito'] = "Paro finalizado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al finalizar el paro: " . $conn->error;
                }
                $stmt_fin->close();
            } else {
                $_SESSION['mensaje_error'] = "No se puede finalizar este paro. Verifique que sea 'Sin WIP' o 'Servidor' y esté activo.";
            }
            $stmt_check->close();
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Forzar finalización de paro (solo para supervisores)
    elseif (isset($_POST['forzar_finalizacion_paro'])) {
        $id_paro = (int)($_POST['id_paro_forzar'] ?? 0);
        $es_supervisor = $_SESSION['rol'] === 'admin' || $_SESSION['es_supervisor'] ?? false;
        
        if ($es_supervisor && $id_paro > 0) {
            // Verificar que el paro existe y está activo
            $stmt_check = $conn->prepare("SELECT id_solicitud FROM paro_produccion WHERE id = ? AND activo = 1");
            $stmt_check->bind_param("i", $id_paro);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($row = $result_check->fetch_assoc()) {
                $fecha_fin = date('Y-m-d H:i:s');
                
                // Finalizar el paro
                $stmt_fin = $conn->prepare("UPDATE paro_produccion SET fecha_fin = ?, activo = 0 WHERE id = ?");
                $stmt_fin->bind_param("si", $fecha_fin, $id_paro);
                
                if ($stmt_fin->execute()) {
                    // Actualizar estado de la solicitud
                    $stmt_est = $conn->prepare("UPDATE solicitudes_paro SET estado = 'finalizada' WHERE id = ?");
                    $stmt_est->bind_param("i", $row['id_solicitud']);
                    $stmt_est->execute();
                    $stmt_est->close();
                    
                    $_SESSION['mensaje_exito'] = "Paro finalizado forzosamente por supervisor. Ahora puedes crear una nueva solicitud.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al forzar la finalización del paro.";
                }
                $stmt_fin->close();
            } else {
                $_SESSION['mensaje_error'] = "No se encontró un paro activo con el ID especificado.";
            }
            $stmt_check->close();
        } else {
            $_SESSION['mensaje_error'] = "No tienes permisos para realizar esta acción.";
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Generar PDF
    elseif (isset($_POST['generar_pdf'])) {
        $id_solicitud = (int)($_POST['id_solicitud'] ?? 0);
        
        if ($id_solicitud > 0) {
            $stmt = $conn->prepare("SELECT sp.*, pp.fecha_inicio, pp.fecha_fin, t.nombre_tecnico 
                                  FROM solicitudes_paro sp 
                                  LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
                                  LEFT JOIN tecnicos t ON pp.id_tecnico = t.id
                                  WHERE sp.id = ? AND sp.empleado = ?");
            $stmt->bind_param("is", $id_solicitud, $nombreEmpleado);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($solicitud = $result->fetch_assoc()) {
                try {
                    generarPDFSolicitud($solicitud);
                    exit;
                } catch (Exception $e) {
                    $_SESSION['mensaje_error'] = "Error al generar PDF: " . $e->getMessage();
                }
            } else {
                $_SESSION['mensaje_error'] = "Solicitud no encontrada.";
            }
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Listado de áreas y equipos
$AREAS = [];
$equipos_por_area = [];  // <--- NUEVO: equipos agrupados por área
$TIPOS_PARO = [];

// === ÁREAS ===
$result_areas = $conn->query("SELECT area FROM areas WHERE activo = 1 ORDER BY area ASC");
if ($result_areas) {
    while ($row = $result_areas->fetch_assoc()) {
        $AREAS[] = $row['area'];
    }
    $result_areas->free();
}

// === Tipos de paro ===
$result_tipos_paro = $conn->query("SELECT nombre FROM tipos_paro ORDER BY nombre ASC");
if ($result_tipos_paro) {
    while ($row = $result_tipos_paro->fetch_assoc()) {
        $TIPOS_PARO[] = $row['nombre'];
    }
    $result_tipos_paro->free();
}

// === EQUIPOS POR ÁREA (usando area_id) ===
$result = $conn->query("
    SELECT e.nombre_equipo, a.area 
    FROM equipos e
    INNER JOIN areas a ON e.area_id = a.id
    WHERE e.activo = 1 AND a.activo = 1
    ORDER BY a.area, e.nombre_equipo
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $equipos_por_area[$row['area']][] = $row['nombre_equipo'];
    }
    $result->free();
}

// Historial de solicitudes y paros para el empleado actual
if (!empty($nombreEmpleado)) {
    // Solicitudes pendientes e iniciadas
    $query_solicitudes = "
        SELECT 
            sp.id,
            sp.area,
            sp.equipo,
            sp.motivo,
            sp.tipo_paro,
            sp.fecha_solicitud,
            sp.estado,
            pp.comentario_final,
            pp.id                AS paro_id,
            pp.fecha_inicio,
            pp.fecha_fin,
            pp.activo,
            t.nombre_tecnico,
            TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, COALESCE(pp.fecha_inicio, NOW())) AS tiempo_respuesta,
            CASE 
                WHEN pp.fecha_inicio IS NOT NULL AND pp.fecha_fin IS NOT NULL THEN
                    TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin)
                WHEN pp.fecha_inicio IS NOT NULL AND pp.activo = 1 THEN
                    TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, NOW())
                ELSE NULL
            END AS duracion_paro
        FROM solicitudes_paro sp
        LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
        LEFT JOIN tecnicos t        ON pp.id_tecnico = t.id
        WHERE sp.empleado = ?
          AND sp.estado IN ('pendiente', 'iniciada')
        ORDER BY sp.fecha_solicitud DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($query_solicitudes);
    if ($stmt && $stmt->bind_param("s", $nombreEmpleado) && $stmt->execute()) {
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $row['fecha_solicitud_formatted'] = date('d-m-Y H:i:s', strtotime($row['fecha_solicitud']));
            $row['fecha_inicio_formatted'] = $row['fecha_inicio'] ? 
                date('d-m-Y H:i:s', strtotime($row['fecha_inicio'])) : '-';
            $row['fecha_fin_formatted'] = $row['fecha_fin'] ? 
                date('d-m-Y H:i:s', strtotime($row['fecha_fin'])) : '-';
            $row['tiempo_respuesta_formatted'] = $row['tiempo_respuesta'] !== null ? 
                round($row['tiempo_respuesta'], 1) . ' min' : '-';
            $row['duracion_paro_formatted'] = $row['duracion_paro'] !== null ? 
                round($row['duracion_paro'], 1) . ' min' : '-';
            $solicitudes_pendientes[] = $row;
        }
        $stmt->close();
    }
    
    // Historial completo con filtro de rango de fechas (últimos 30 días por defecto)
    $fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

    $query_historial = "
        SELECT 
            sp.id,
            sp.area,
            sp.equipo,
            sp.motivo,
            sp.tipo_paro,
            sp.fecha_solicitud,
            sp.estado,
            sp.motivo_rechazo,
            pp.comentario_final,
            pp.fecha_inicio,
            pp.fecha_fin,
            t.nombre_tecnico,
            TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_inicio) AS tiempo_respuesta,
            CASE 
                WHEN pp.fecha_inicio IS NOT NULL AND pp.fecha_fin IS NOT NULL THEN
                    TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin)
                ELSE NULL
            END AS duracion_paro
        FROM solicitudes_paro sp
        LEFT JOIN paro_produccion pp ON sp.id = pp.id_solicitud
        LEFT JOIN tecnicos t        ON pp.id_tecnico = t.id
        WHERE sp.empleado = ?
          AND DATE(sp.fecha_solicitud) BETWEEN ? AND ?
        ORDER BY sp.fecha_solicitud DESC
        LIMIT 100
    ";
    $stmt = $conn->prepare($query_historial);
    if ($stmt && $stmt->bind_param("sss", $nombreEmpleado, $fecha_desde, $fecha_hasta) && $stmt->execute()) {
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $row['fecha_solicitud_formatted'] = date('d-m-Y H:i:s', strtotime($row['fecha_solicitud']));
            $row['fecha_inicio_formatted'] = $row['fecha_inicio'] ? 
                date('d-m-Y H:i:s', strtotime($row['fecha_inicio'])) : '-';
            $row['fecha_fin_formatted'] = $row['fecha_fin'] ? 
                date('d-m-Y H:i:s', strtotime($row['fecha_fin'])) : '-';
            $row['tiempo_respuesta_formatted'] = $row['tiempo_respuesta'] !== null ? 
                round($row['tiempo_respuesta'], 1) . ' min' : '-';
            $row['duracion_paro_formatted'] = $row['duracion_paro'] !== null ? 
                round($row['duracion_paro'], 1) . ' min' : '-';
            $historial[] = $row;
        }
        $stmt->close();
    }
    
    // Último motivo de solicitud
    if (!empty($area_seleccionada) && !empty($equipo_seleccionado)) {
        $stmt = $conn->prepare("
            SELECT motivo 
            FROM solicitudes_paro 
            WHERE empleado = ? AND area = ? AND equipo = ? 
            ORDER BY fecha_solicitud DESC 
            LIMIT 1
        ");
        if ($stmt && $stmt->bind_param("sss", $nombreEmpleado, $area_seleccionada, $equipo_seleccionado)) {
            $stmt->execute();
            $stmt->bind_result($motivo_reciente);
            if ($stmt->fetch()) {
                $ultimo_motivo_solicitud = $motivo_reciente;
            }
            $stmt->close();
        }

        // Verificar paro activo de otro empleado
        $stmt = $conn->prepare("
            SELECT id, empleado, motivo, fecha_inicio 
            FROM paro_produccion 
            WHERE area = ? AND equipo = ? AND activo = 1 AND empleado != ?
            LIMIT 1
        ");
        if ($stmt && $stmt->bind_param("sss", $area_seleccionada, $equipo_seleccionado, $nombreEmpleado)) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $paro_activo_otro_empleado = $row;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Solicitudes de Paro - Sistema de Control</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            --danger-red: #dc3545;
            --warning-yellow: #ffc107;
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-green) 50%, var(--primary-green) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

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
            box-shadow: 0 2px 4px var(--shadow);
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            box-shadow: 0 4px 8px var(--shadow-hover);
            transform: translateY(-2px);
        }

        .main-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
            flex: 1;
        }

        .content-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 4px var(--shadow);
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f1aeb5);
            color: var(--danger-red);
            border-left: 4px solid var(--danger-red);
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: var(--primary-dark);
            border-left: 4px solid var(--success-green);
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border-left: 4px solid var(--warning-yellow);
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 6px var(--shadow);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--light-gray), #e9ecef);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .status-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 6px var(--shadow);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .status-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .status-icon {
            font-size: 24px;
            color: var(--success-green);
        }

        .status-text h4 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .status-text p {
            margin: 5px 0 0;
            color: #666;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            transition: var(--transition);
            background: #fff;
            color: #333;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .form-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--white);
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-green), var(--primary-dark));
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #545b62);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-green), #17a2b8);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #17a2b8, var(--success-green));
            transform: translateY(-2px);
        }

        .table-container {
            padding: 20px;
            overflow-x: auto;
        }

        .table-wrapper {
            min-width: 100%;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            color: #333;
            font-size: 14px;
        }

        .table th,
        .table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background: var(--light-gray);
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .solicitud-pendiente {
            background: rgba(255, 193, 7, 0.1);
        }

        .solicitud-iniciada {
            background: rgba(40, 167, 69, 0.1);
        }

        .solicitud-finalizada {
            background: rgba(108, 117, 125, 0.1);
        }

        .solicitud-rechazada {
            background: rgba(220, 53, 69, 0.1);
        }

        .badge {
            padding: 5px 10px;
            border-radius: var(--border-radius-sm);
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            color: var(--white);
        }

        .badge-warning {
            background: var(--warning-yellow);
            color: #856404;
        }

        .badge-success {
            background: var(--success-green);
        }

        .badge-danger {
            background: var(--danger-red);
        }

        .badge-secondary {
            background: #6c757d;
        }

        .tiempo-respuesta {
            font-weight: 500;
            color: #e74c3c;
        }

        .filters-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
        }

        .footer {
            background: var(--dark-green);
            color: var(--white);
            text-align: center;
            padding: 20px;
            margin-top: auto;
            box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.2);
        }

        .footer div {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .developer {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 5px;
        }

        .stats-mini {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-mini {
            background: rgba(40, 167, 69, 0.1);
            padding: 10px;
            border-radius: var(--border-radius-sm);
            text-align: center;
            border-left: 4px solid var(--primary-green);
        }

        .stat-mini-number {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .stat-mini-label {
            font-size: 12px;
            color: #666;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 10px;
            }

            .logo-container img {
                height: 50px;
            }

            .clock {
                font-size: 16px;
            }

            .card-header {
                font-size: 16px;
                flex-direction: column;
                align-items: flex-start;
            }

            .table th,
            .table td {
                padding: 8px 4px;
                font-size: 12px;
            }

            .form-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .filters-container {
                grid-template-columns: 1fr;
            }

            .stats-mini {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
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

    <div class="main-container">
        <div class="content-section">
            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['mensaje_error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($_SESSION['mensaje_error']) ?>
                </div>
                <?php unset($_SESSION['mensaje_error']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['mensaje_exito'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($_SESSION['mensaje_exito']) ?>
                </div>
                <?php unset($_SESSION['mensaje_exito']); ?>
            <?php endif; ?>

            <?php if (empty($nombreEmpleado)): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user-search"></i>
                        <h3>Buscar Empleado</h3>
                    </div>
                    <form method="POST" action="">
                        <div class="form-group" style="padding: 20px;">
                            <label for="codigo_empleado" class="form-label">
                                <i class="fas fa-id-badge"></i>
                                Código del Empleado
                            </label>
                            <input
                                type="text"
                                id="codigo_empleado"
                                name="codigo_empleado"
                                class="form-control"
                                placeholder="Ingrese el código del empleado"
                                autocomplete="off"
                                autofocus
                                required
                                value="<?= htmlspecialchars($_POST['codigo_empleado'] ?? '') ?>"
                            >
                        </div>
                        <div class="form-buttons" style="padding: 0 20px 20px;">
                            <button type="submit" name="consultar_empleado" value="1" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                                Consultar Empleado
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="status-card">
                    <div class="status-info">
                        <i class="fas fa-user status-icon"></i>
                        <div class="status-text">
                            <h4><?= htmlspecialchars($nombreEmpleado) ?></h4>
                        </div>
                    </div>
                    <form method="POST" action="">
                        <button type="submit" name="cambiar_empleado" value="1" class="btn btn-secondary">
                            <i class="fas fa-user-slash"></i>
                            Cambiar Empleado
                        </button>
                    </form>
                </div>

                <!-- Estadísticas mini -->
                <div class="stats-mini">
                    <div class="stat-mini">
                        <div class="stat-mini-number"><?= count($solicitudes_pendientes) ?></div>
                        <div class="stat-mini-label">Activas</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-number"><?= count($historial) ?></div>
                        <div class="stat-mini-label">Historial</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-number">
                            <?= count(array_filter($solicitudes_pendientes, fn($s) => $s['estado'] === 'pendiente')) ?>
                        </div>
                        <div class="stat-mini-label">Pendientes</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-number">
                            <?= count(array_filter($solicitudes_pendientes, fn($s) => $s['estado'] === 'iniciada')) ?>
                        </div>
                        <div class="stat-mini-label">En Progreso</div>
                    </div>
                </div>

                <?php if ($paro_activo_otro_empleado): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div style="margin-left: 10px;">
                            <strong>⚠️ EQUIPO EN PARO ACTIVO</strong><br>
                            No se puede crear una nueva solicitud porque el equipo <strong><?= htmlspecialchars($equipo_seleccionado) ?></strong> 
                            en el área <strong><?= htmlspecialchars($area_seleccionada) ?></strong> ya se encuentra en estado de PARO.<br><br>
                            
                            <div style="background: #fff3cd; padding: 10px; border-radius: 8px; margin: 10px 0;">
                                <i class="fas fa-info-circle"></i> <strong>Detalles del paro activo:</strong><br>
                                • <strong>Empleado que reportó:</strong> <?= htmlspecialchars($paro_activo_otro_empleado['empleado']) ?><br>
                                • <strong>Fecha y hora de inicio:</strong> <?= date('d/m/Y H:i:s', strtotime($paro_activo_otro_empleado['fecha_inicio'])) ?><br>
                                • <strong>Motivo:</strong> <?= htmlspecialchars($paro_activo_otro_empleado['motivo']) ?>
                            </div>
                            
                            <?php 
                            // Verificar si el usuario actual tiene rol de supervisor/admin
                            $es_supervisor = $_SESSION['rol'] === 'admin' || $_SESSION['es_supervisor'] ?? false;
                            if ($es_supervisor): 
                            ?>
                                <div style="margin-top: 15px;">
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="forzar_finalizacion_paro" value="1">
                                        <input type="hidden" name="id_paro_forzar" value="<?= $paro_activo_otro_empleado['id'] ?>">
                                        <button type="submit" class="btn btn-danger" 
                                                onclick="return confirm('¿Está seguro de FORZAR la finalización de este paro?\n\nEsta acción finalizará el paro actual y permitirá crear una nueva solicitud.')">
                                            <i class="fas fa-power-off"></i> Forzar Finalización del Paro
                                        </button>
                                    </form>
                                    <span style="margin-left: 10px; font-size: 12px; color: #856404;">
                                        <i class="fas fa-exclamation-circle"></i> Solo supervisores pueden forzar la finalización
                                    </span>
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 15px; background: #f8d7da; padding: 8px; border-radius: 5px;">
                                    <i class="fas fa-lock"></i> 
                                    <small>No puedes crear una nueva solicitud mientras este paro esté activo. 
                                    Contacta a un supervisor o espera a que el técnico finalice el paro actual.</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif (empty($area_seleccionada)): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-cogs"></i>
                            <h3>Seleccionar Área y Equipo</h3>
                        </div>
                        <form method="POST" action="">
                            <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                                <div class="form-group">
                                    <label for="area" class="form-label">
                                        <i class="fas fa-map-marked-alt"></i>
                                        Área
                                    </label>
                                    <select name="area" id="area" class="form-control" required>
                                        <option value="">Seleccione un área</option>
                                        <?php foreach ($AREAS as $area): ?>
                                            <option value="<?= htmlspecialchars($area) ?>" 
                                                <?= ($area === $area_seleccionada) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($area) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="equipo" class="form-label">
                                        <i class="fas fa-tools"></i>
                                        Equipo
                                    </label>
                                    <select name="equipo" id="equipo" class="form-control">
                                        <option value="">Seleccione un equipo</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-buttons" style="padding: 0 20px 20px;">
                                <button type="submit" name="seleccionar_area_equipo" value="1" class="btn btn-primary">
                                    <i class="fas fa-check"></i>
                                    Confirmar Selección
                                </button>
                            </div>
                        </form>
                    </div>

                    <script>
                    // Datos de equipos por área
                    const equiposPorArea = <?= json_encode($equipos_por_area, JSON_UNESCAPED_UNICODE) ?>;

                    const selectArea = document.getElementById('area');
                    const selectEquipo = document.getElementById('equipo');

                    function actualizarEquipos() {
                        const areaSeleccionada = selectArea.value;
                        const equipos = areaSeleccionada ? (equiposPorArea[areaSeleccionada] || []) : [];

                        if (!areaSeleccionada) {
                            selectEquipo.innerHTML = '<option value="">Seleccione un área primero</option>';
                            selectEquipo.disabled = true;
                            selectEquipo.removeAttribute('required');
                        } else if (equipos.length === 0) {
                            selectEquipo.innerHTML = '<option value="" selected>Área sin equipo asignado</option>';
                            selectEquipo.disabled = false;
                            selectEquipo.removeAttribute('required');
                        } else {
                            selectEquipo.innerHTML = '<option value="">Seleccione un equipo</option>';
                            equipos.forEach(equipo => {
                                const option = document.createElement('option');
                                option.value = equipo;
                                option.textContent = equipo;
                                if (equipo === "<?= htmlspecialchars($equipo_seleccionado) ?>") {
                                    option.selected = true;
                                }
                                selectEquipo.appendChild(option);
                            });
                            selectEquipo.disabled = false;
                            selectEquipo.setAttribute('required', 'required');
                        }
                    }

                    document.addEventListener('DOMContentLoaded', function() {
                        actualizarEquipos();
                        selectArea.addEventListener('change', actualizarEquipos);
                    });
                    </script>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h3>Nueva Solicitud de Paro</h3>
                            <span style="margin-left: auto;">
                                Área: <?= htmlspecialchars($area_seleccionada) ?> | 
                                Equipo: <?= htmlspecialchars($equipo_seleccionado) ?>
                            </span>
                        </div>
                        <form method="POST" action="">
                            <div style="padding: 20px; display: grid; grid-template-columns: 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label for="tipo_paro" class="form-label">
                                        <i class="fas fa-tools"></i>
                                        Tipo de Paro
                                    </label>
                                    <select name="tipo_paro" id="tipo_paro" class="form-control" required>
                                        <option value="">Seleccione el tipo de paro</option>
                                        <?php foreach ($TIPOS_PARO as $tipo): ?>
                                            <option value="<?= htmlspecialchars($tipo) ?>" <?= ($tipo === ($_POST['tipo_paro'] ?? '')) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tipo) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>                                
                                </div>
                                <div class="form-group">
                                    <label for="motivo_solicitud" class="form-label">
                                        <i class="fas fa-comment"></i>
                                        Motivo del Paro
                                    </label>
                                    <textarea
                                        name="motivo_solicitud"
                                        id="motivo_solicitud"
                                        class="form-control"
                                        rows="4"
                                        placeholder="Describa detalladamente el motivo del paro de producción..."
                                        required
                                    ><?= htmlspecialchars($_POST['motivo_solicitud'] ?? '') ?></textarea>
                                    
                                    <?php if (!empty($ultimo_motivo_solicitud)): ?>
                                        <small style="color: var(--primary-dark); margin-top: 8px; display: block;">
                                            <i class="fas fa-history"></i>
                                            <strong>Último motivo usado:</strong> <?= htmlspecialchars($ultimo_motivo_solicitud) ?>
                                            <button type="button" onclick="reutilizarMotivo()" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px; margin-left: 10px;">
                                                Reutilizar
                                            </button>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-buttons" style="padding: 0 20px 20px;">
                                <button type="submit" name="crear_solicitud" value="1" class="btn btn-danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Crear Solicitud de Paro
                                </button>
                                <button type="submit" name="cambiar_area_equipo" value="1" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Cambiar Área/Equipo
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($solicitudes_pendientes)): ?>
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>Mis Solicitudes Activas</h3>
                            <span style="margin-left: auto;">
                                <?= count($solicitudes_pendientes) ?> solicitudes
                            </span>
                        </div>
                        <div class="table-container">
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Fecha Solicitud</th>
                                            <th>Área</th>
                                            <th>Equipo</th>
                                            <th>Tipo</th>
                                            <th>Motivo</th>
                                            <th>Estado</th>
                                            <th>Técnico</th>
                                            <th>T.Respuesta</th>
                                            <th>Duración</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($solicitudes_pendientes as $solicitud): ?>
                                            <tr class="solicitud-<?= $solicitud['estado'] ?>">
                                                <td><?= htmlspecialchars($solicitud['id']) ?></td>
                                                <td><?= $solicitud['fecha_solicitud_formatted'] ?></td>
                                                <td><?= htmlspecialchars($solicitud['area']) ?></td>
                                                <td><?= htmlspecialchars($solicitud['equipo']) ?></td>
                                                <td><?= htmlspecialchars($solicitud['tipo_paro']) ?></td>
                                                <td title="<?= htmlspecialchars($solicitud['motivo']) ?>">
                                                    <?= strlen($solicitud['motivo']) > 30
                                                        ? htmlspecialchars(substr($solicitud['motivo'], 0, 30)) . '...'
                                                        : htmlspecialchars($solicitud['motivo']) ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $solicitud['estado'] === 'pendiente' ? 'warning' : 'success' ?>">
                                                        <?= ucfirst($solicitud['estado']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($solicitud['nombre_tecnico'] ?? '-') ?></td>
                                                <td>
                                                    <span class="badge badge-<?= $solicitud['tiempo_respuesta'] && $solicitud['tiempo_respuesta'] > 30 ? 'danger' : 'secondary' ?>">
                                                        <?= $solicitud['tiempo_respuesta_formatted'] ?? '-' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-success">
                                                        <?= $solicitud['duracion_paro_formatted'] ?? '-' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="id_solicitud" value="<?= $solicitud['id'] ?>">
                                                        <button type="submit" name="generar_pdf" value="1"
                                                                class="btn btn-secondary" style="padding:6px 12px;font-size:12px;">
                                                            <i class="fas fa-file-pdf"></i> PDF
                                                        </button>
                                                        <?php if (($solicitud['tipo_paro'] === 'Sin WIP' || $solicitud['tipo_paro'] === 'Servidor') && $solicitud['estado'] === 'iniciada' && $solicitud['activo'] == 1): ?>
                                                            <button type="submit" name="finalizar_paro" value="1"
                                                                    class="btn btn-success" style="padding:6px 12px;font-size:12px;" 
                                                                    onclick="return confirm('¿Está seguro de finalizar este paro?');">
                                                                <i class="fas fa-stop"></i> Finalizar
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filtros para el historial -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history"></i>
                        <h3>Historial de Solicitudes</h3>
                        <span style="margin-left: auto;">
                            <?= count($historial) ?> registros encontrados
                        </span>
                    </div>
                    
                    <!-- Filtros de fecha -->
                    <div class="filters-container">
                        <form method="GET" action="" style="display: contents;">
                            <div class="form-group">
                                <label for="fecha_desde" class="form-label">
                                    <i class="fas fa-calendar"></i> Fecha Desde
                                </label>
                                <input type="date" id="fecha_desde" name="fecha_desde" class="form-control" 
                                       value="<?= htmlspecialchars($fecha_desde) ?>">
                            </div>
                            <div class="form-group">
                                <label for="fecha_hasta" class="form-label">
                                    <i class="fas fa-calendar"></i> Fecha Hasta
                                </label>
                                <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control" 
                                       value="<?= htmlspecialchars($fecha_hasta) ?>">
                            </div>
                            <div class="form-group" style="display: flex; align-items: end; gap: 10px;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Filtrar
                                </button>
                                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>

                    <?php if (!empty($historial)): ?>
                        <div class="table-container">
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Fecha</th>
                                            <th>Área</th>
                                            <th>Equipo</th>
                                            <th>Tipo</th>
                                            <th>Motivo</th>
                                            <th>Estado</th>
                                            <th>Técnico</th>
                                            <th>T.Respuesta</th>
                                            <th>Duración</th>
                                            <th>Comentario Final</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historial as $registro): ?>
                                            <tr class="solicitud-<?= htmlspecialchars($registro['estado']) ?>">
                                                <td><?= htmlspecialchars($registro['id']) ?></td>
                                                <td>
                                                    <?php 
                                                    $fecha = $registro['fecha_solicitud'] ?? $registro['fecha_solicitud_formatted'] ?? '';
                                                    echo $fecha ? date('d/m H:i', strtotime($fecha)) : '-';
                                                    ?>
                                                </td>
                                                <td><?= htmlspecialchars($registro['area'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($registro['equipo'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($registro['tipo_paro'] ?? '') ?></td>
                                                <td title="<?= htmlspecialchars($registro['motivo'] ?? '') ?>">
                                                    <?php 
                                                    $motivo = $registro['motivo'] ?? '';
                                                    echo strlen($motivo) > 25 
                                                        ? htmlspecialchars(substr($motivo, 0, 25)) . '...' 
                                                        : htmlspecialchars($motivo);
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $estado = $registro['estado'] ?? 'desconocido';
                                                    $badgeClass = $estado === 'rechazada' ? 'danger' : 'secondary';
                                                    ?>
                                                    <span class="badge badge-<?= $badgeClass ?>">
                                                        <?= ucfirst($estado) ?>
                                                    </span>
                                                    <?php if (!empty($registro['motivo_rechazo'])): ?>
                                                        <small title="<?= htmlspecialchars($registro['motivo_rechazo']) ?>" 
                                                               style="display:block; color:#dc3545; margin-top:4px;">
                                                            <?php
                                                            $rechazo = $registro['motivo_rechazo'];
                                                            echo strlen($rechazo) > 20
                                                                ? htmlspecialchars(substr($rechazo, 0, 20)) . '...'
                                                                : htmlspecialchars($rechazo);
                                                            ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($registro['nombre_tecnico'] ?? '-') ?></td>
                                                <td>
                                                    <span class="badge badge-secondary">
                                                        <?= htmlspecialchars($registro['tiempo_respuesta_formatted'] ?? '-') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-success">
                                                        <?= htmlspecialchars($registro['duracion_paro_formatted'] ?? '-') ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($registro['comentario_final'] ?? '') ?></td>
                                                <td>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="id_solicitud" value="<?= $registro['id'] ?>">
                                                        <button type="submit" name="generar_pdf" value="1"
                                                                class="btn btn-secondary" style="padding:6px 12px; font-size:12px;">
                                                            <i class="fas fa-file-pdf"></i> PDF
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; padding:40px; color:#666;">
                            <i class="fas fa-info-circle" style="font-size:48px; margin-bottom:20px; display:block;"></i>
                            <h4>No hay registros en el historial</h4>
                            <p>No se encontraron solicitudes finalizadas o rechazadas en el rango de fechas seleccionado.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <div>
            <i class="fas fa-cogs"></i>
            Sistema de Control de Paros © <?= date("Y") ?>
        </div>
        <div class="developer">
            <i class="fas fa-code"></i>
            Desarrollado por: Nestor Rosales | Rosales_Dev91
        </div>
    </footer>

    <script>
        function reutilizarMotivo() {
            const ultimoMotivo = <?= json_encode($ultimo_motivo_solicitud) ?>;
            document.getElementById('motivo_solicitud').value = ultimoMotivo;
        }

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

        // Validación de fechas en filtros
        function validarFechas() {
            const fechaDesde = document.getElementById('fecha_desde');
            const fechaHasta = document.getElementById('fecha_hasta');
            
            if (fechaDesde && fechaHasta) {
                fechaHasta.addEventListener('change', function() {
                    if (fechaDesde.value && this.value && this.value < fechaDesde.value) {
                        alert('La fecha hasta no puede ser anterior a la fecha desde');
                        this.value = fechaDesde.value;
                    }
                });
                
                fechaDesde.addEventListener('change', function() {
                    if (!fechaHasta.value) {
                        fechaHasta.value = this.value;
                    }
                });
            }
        }

        // Confirmaciones para acciones importantes
        function confirmarFinalizacion() {
            return confirm('¿Está seguro que desea finalizar este paro de producción?\n\nEsta acción no se puede deshacer.');
        }

        function confirmarSolicitud() {
            const motivo = document.getElementById('motivo_solicitud');
            const tipo = document.getElementById('tipo_paro');
            
            if (motivo && tipo) {
                if (motivo.value.trim().length < 10) {
                    alert('El motivo debe tener al menos 10 caracteres para una mejor descripción.');
                    return false;
                }
                
                return confirm(`¿Confirma crear la solicitud de paro?\n\nTipo: ${tipo.options[tipo.selectedIndex].text}\nMotivo: ${motivo.value.substring(0, 100)}${motivo.value.length > 100 ? '...' : ''}`);
            }
            return true;
        }

        // Asignar confirmaciones a formularios
        document.addEventListener('DOMContentLoaded', function() {
            // Crear solicitud
            const crearForm = document.querySelector('button[name="crear_solicitud"]');
            if (crearForm) {
                crearForm.closest('form').onsubmit = confirmarSolicitud;
            }
            
            // Validar fechas
            validarFechas();
        });

        // Actualizar reloj
        setInterval(actualizarReloj, 1000);
        actualizarReloj();

        // Auto-refresh cada 30 segundos
        let autoRefreshTimer = setInterval(() => {
            // Solo refrescar si no hay modales abiertos o formularios siendo llenados
            const activeElement = document.activeElement;
            if (!activeElement || (activeElement.tagName !== 'INPUT' && activeElement.tagName !== 'TEXTAREA')) {
                location.reload();
            }
        }, 30000);

        // Pausar auto-refresh cuando el usuario está escribiendo
        document.addEventListener('focusin', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                clearInterval(autoRefreshTimer);
            }
        });

        document.addEventListener('focusout', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                autoRefreshTimer = setInterval(() => {
                    const activeElement = document.activeElement;
                    if (!activeElement || (activeElement.tagName !== 'INPUT' && activeElement.tagName !== 'TEXTAREA')) {
                        location.reload();
                    }
                }, 30000);
            }
        });

        // Notificación visual para solicitudes pendientes
        const pendientes = <?= count(array_filter($solicitudes_pendientes, fn($s) => $s['estado'] === 'pendiente')) ?>;
        if (pendientes > 0) {
            document.title = `(${pendientes}) Solicitudes Pendientes - Sistema de Control`;
        }

        // Resaltar tiempos críticos
        document.addEventListener('DOMContentLoaded', function() {
            const tiemposBadges = document.querySelectorAll('.badge');
            tiemposBadges.forEach(badge => {
                const texto = badge.textContent.trim();
                if (texto.includes('min')) {
                    const minutos = parseInt(texto);
                    if (minutos > 60) {
                        badge.style.animation = 'blink 1s infinite';
                    }
                }
            });
        });

        // Animación para tiempos críticos
        const style = document.createElement('style');
        style.textContent = `
            @keyframes blink {
                0% { opacity: 1; }
                50% { opacity: 0.5; }
                100% { opacity: 1; }
            }
        `;
        document.head.appendChild(style);
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