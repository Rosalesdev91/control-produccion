<?php
session_start();
require_once '../config/database.php';
require_once 'auto_audit_empleados.php';
require_once 'registrar_actividad.php';
date_default_timezone_set('America/Costa_Rica');

$mensaje = '';
$codigoEmpleado = $_POST['codigo_empleado'] ?? '';
$nombreEmpleado = '';
$proceso_id = $_POST['proceso_id'] ?? '';

// 0. Limpieza de sesión para cambiar empleado o proceso
if (isset($_POST['cambiar_empleado'])) {
    unset(
        $_SESSION['codigoEmpleado'],
        $_SESSION['nombreEmpleado'],
        $_SESSION['empleado'],
        $_SESSION['proceso_seleccionado'],
        $_SESSION['proceso_id'],
        $_SESSION['referencias_escaneadas'],
        $_SESSION['referencias_escaneadas_lista'],
        $_SESSION['ultimos_registros'] // Limpiar también últimos registros
    );
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['cambiar_proceso'])) {
    unset(
        $_SESSION['proceso_seleccionado'],
        $_SESSION['proceso_id'],
        $_SESSION['referencias_escaneadas'],
        $_SESSION['referencias_escaneadas_lista'],
        $_SESSION['ultimos_registros'] // Limpiar también últimos registros
    );
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 1. Inicializar variables desde sesión o POST
$proceso_seleccionado = $_SESSION['proceso_seleccionado'] ?? '';
$referencia = strtoupper(trim($_POST['referencia1'] ?? ''));
$empleado = $_SESSION['empleado'] ?? '';
$nombreEmpleado = $_SESSION['nombreEmpleado'] ?? null;

// 2. Validar y obtener proceso desde ID
if (isset($_POST['proceso_id']) && !empty($_POST['proceso_id'])) {
    $stmt_proceso = $conn->prepare("SELECT id, proceso FROM procesos_picking WHERE id = ?");
    $stmt_proceso->bind_param("i", $_POST['proceso_id']);
    $stmt_proceso->execute();
    $stmt_proceso->bind_result($proceso_id_db, $proceso_nombre);
    if ($stmt_proceso->fetch()) {
        $proceso_seleccionado = $proceso_nombre;
        $_SESSION['proceso_seleccionado'] = $proceso_nombre;
        $_SESSION['proceso_id'] = $proceso_id_db;
    } else {
        $mensaje = "Proceso no encontrado con el ID: " . htmlspecialchars($_POST['proceso_id']);
        $proceso_seleccionado = '';
        unset($_SESSION['proceso_seleccionado'], $_SESSION['proceso_id']);
    }
    $stmt_proceso->close();
} elseif (!empty($_SESSION['proceso_id']) && !empty($_SESSION['proceso_seleccionado'])) {
    $proceso_id = $_SESSION['proceso_id'];
    $proceso_seleccionado = $_SESSION['proceso_seleccionado'];
}

// ✅ Libera la sesión para no bloquear otras peticiones
session_write_close();

// 🔁 Reabrimos sesión antes de modificar datos en $_SESSION
session_start();

// Inicializar array de últimos registros para control de duplicados
if (!isset($_SESSION['ultimos_registros'])) {
    $_SESSION['ultimos_registros'] = [];
}

// 5. Inicializar sesión si no existe
if (!isset($_SESSION['referencias_escaneadas_lista'])) {
    $_SESSION['referencias_escaneadas_lista'] = [];
} else {
    foreach ($_SESSION['referencias_escaneadas_lista'] as $key => $item) {
        if (is_string($item)) {
            $_SESSION['referencias_escaneadas_lista'][$key] = [
                'referencia' => $item,
                'proceso' => $proceso_seleccionado,
                'fecha_hora' => date("d/m/Y H:i:s"),
                'timestamp' => time()
            ];
        } else {
            if (!isset($item['fecha_hora'])) {
                $_SESSION['referencias_escaneadas_lista'][$key]['fecha_hora'] = date("d/m/Y H:i:s");
            }
            if (!isset($item['proceso'])) {
                $_SESSION['referencias_escaneadas_lista'][$key]['proceso'] = $proceso_seleccionado;
            }
            if (!isset($item['timestamp'])) {
                $_SESSION['referencias_escaneadas_lista'][$key]['timestamp'] = time();
            }
        }
    }
}

if (!isset($_SESSION['referencias_escaneadas'])) {
    $_SESSION['referencias_escaneadas'] = 0;
}

// Función para verificar duplicados en los últimos 5 minutos
function es_duplicado_reciente($referencia, $proceso, $ultimos_registros) {
    $tiempo_actual = time();
    foreach ($ultimos_registros as $registro) {
        if ($registro['referencia'] === $referencia && 
            $registro['proceso'] === $proceso && 
            ($tiempo_actual - $registro['timestamp']) <= 300) { // 300 segundos = 5 minutos
            return true;
        }
    }
    return false;
}

// Limpiar registros antiguos del array de últimos registros (mayores a 5 minutos)
function limpiar_registros_antiguos(&$ultimos_registros) {
    $tiempo_actual = time();
    $ultimos_registros = array_filter($ultimos_registros, function($registro) use ($tiempo_actual) {
        return ($tiempo_actual - $registro['timestamp']) <= 300;
    });
}

function referencia_proceso_ya_registrada($referencia, $proceso, $lista) {
    foreach ($lista as $item) {
        if ($item['referencia'] === $referencia && $item['proceso'] === $proceso) {
            return true;
        }
    }
    return false;
}

// 6. Procesar registro (CON COOLDOWN DE 5 MINUTOS)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['consultar_empleado']) && !isset($_POST['cambiar_empleado']) && !isset($_POST['cambiar_proceso']) && !isset($_POST['proceso_id'])) {
    
    // Validar datos requeridos
    if ($proceso_seleccionado && $referencia && $empleado) {

        // Limpiar registros antiguos antes de verificar duplicados
        limpiar_registros_antiguos($_SESSION['ultimos_registros']);

        // VERIFICAR DUPLICADO EN LOS ÚLTIMOS 5 MINUTOS
        if (es_duplicado_reciente($referencia, $proceso_seleccionado, $_SESSION['ultimos_registros'])) {
            $mensaje = "Esta referencia ya fue registrada'.";
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false, 
                    'mensaje' => $mensaje,
                    'duplicado' => true
                ]);
                exit;
            } else {
                $_SESSION['mensaje_error'] = $mensaje;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        }

        // === INSERTAR REGISTRO ===
        $stmt = $conn->prepare("INSERT INTO produccion_picking (empleado, proceso, referencia, fecha) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $empleado, $proceso_seleccionado, $referencia);

        if ($stmt->execute()) {
            $timestamp_actual = time();
            $new_item = [
                'referencia' => $referencia,
                'proceso' => $proceso_seleccionado,
                'fecha_hora' => date("d/m/Y H:i:s", $timestamp_actual),
                'timestamp' => $timestamp_actual
            ];

            // Registrar en el array de últimos registros para control de duplicados
            $_SESSION['ultimos_registros'][] = [
                'referencia' => $referencia,
                'proceso' => $proceso_seleccionado,
                'timestamp' => $timestamp_actual
            ];

            // Mantener solo los últimos 50 registros para no saturar la sesión
            if (count($_SESSION['ultimos_registros']) > 50) {
                $_SESSION['ultimos_registros'] = array_slice($_SESSION['ultimos_registros'], -50);
            }

            // Evitar duplicados en sesión de lista visible
            if (!referencia_proceso_ya_registrada($referencia, $proceso_seleccionado, $_SESSION['referencias_escaneadas_lista'])) {
                $_SESSION['referencias_escaneadas_lista'][] = $new_item;
                $_SESSION['referencias_escaneadas']++;
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'referencias' => [$new_item]
                ]);
                exit;
            } else {
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        } else {
            $mensaje = "Error al registrar: " . $stmt->error;
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'mensaje' => $mensaje]);
                exit;
            }
        }
        $stmt->close();

    } else {
        $mensaje = "Faltan datos obligatorios.";
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'mensaje' => $mensaje]);
            exit;
        }
    }
}

// 7. Consultar empleado
if (isset($_POST['consultar_empleado']) && !empty($codigoEmpleado)) {
    $stmt = $conn->prepare("SELECT id, nombre_empleado FROM empleados_picking WHERE codigo_empleado = ?");
    $stmt->bind_param("s", $codigoEmpleado);
    $stmt->execute();
    $stmt->bind_result($empleado_id_db, $nombre);
    if ($stmt->fetch()) {
        $nombreEmpleado = $nombre;
        $_SESSION['codigoEmpleado'] = $codigoEmpleado;
        $_SESSION['nombreEmpleado'] = $nombreEmpleado;
        $_SESSION['empleado'] = $nombreEmpleado;
        $_SESSION['empleado_id'] = $empleado_id_db;
        unset($_SESSION['referencias_escaneadas'], $_SESSION['referencias_escaneadas_lista'], $_SESSION['proceso_seleccionado'], $_SESSION['proceso_id'], $_SESSION['ultimos_registros']);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $mensaje = "Empleado no encontrado con el código: $codigoEmpleado";
    }
    $stmt->close();
} elseif (!empty($_SESSION['codigoEmpleado']) && !empty($_SESSION['nombreEmpleado'])) {
    $codigoEmpleado = $_SESSION['codigoEmpleado'];
    $nombreEmpleado = $_SESSION['nombreEmpleado'];
    $_SESSION['empleado'] = $nombreEmpleado;
}

// 8. Obtener procesos
$PROCESOS = [];
$PROCESOS_DATA = [];

$result = $conn->query("SELECT id, proceso FROM procesos_picking ORDER BY proceso ASC");
while ($row = $result->fetch_assoc()) {
    $PROCESOS[] = $row['proceso'];
    $PROCESOS_DATA[] = ['id' => $row['id'], 'proceso' => $row['proceso']];
}

// 9. Funciones auxiliares
date_default_timezone_set('America/Guatemala');

function actualizarContadores($conn, $empleado, $proceso = null) {
    $contador_proceso = 0;
    $contador_total = 0;

    if ($proceso) {
        $query_proceso = "SELECT COUNT(*) FROM produccion_picking WHERE empleado = ? AND proceso = ?";
        $stmt1 = $conn->prepare($query_proceso);
        $stmt1->bind_param("ss", $empleado, $proceso);
        $stmt1->execute();
        $stmt1->bind_result($contador_proceso);
        $stmt1->fetch();
        $stmt1->close();
    }

    $query_total = "SELECT COUNT(*) FROM produccion_picking WHERE empleado = ?";
    $stmt3 = $conn->prepare($query_total);
    $stmt3->bind_param("s", $empleado);
    $stmt3->execute();
    $stmt3->bind_result($contador_total);
    $stmt3->fetch();
    $stmt3->close();

    return [$contador_proceso, $contador_total];
}

// --------------------------------------------
// BLOQUE PRINCIPAL
// --------------------------------------------
$produccion_hoy = [];
$trabajos_por_proceso = [];

if (!empty($empleado)) {
    $query = "SELECT proceso, referencia, fecha FROM produccion_picking 
              WHERE empleado = ? ORDER BY fecha DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $empleado);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $produccion_hoy[] = $row;
        $proceso = $row['proceso'];
        $trabajos_por_proceso[$proceso] = ($trabajos_por_proceso[$proceso] ?? 0) + 1;
    }
    $stmt->close();

    list(, $contador_produccion_total) = actualizarContadores($conn, $empleado, null);
    $contador_produccion_proceso = !empty($proceso_seleccionado)
        ? actualizarContadores($conn, $empleado, $proceso_seleccionado)[0]
        : 0;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5, user-scalable=yes">
    <title>Registro de Picking</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: linear-gradient(145deg, #0f4a1f 0%, #1e7e34 100%);
            color: white;
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            margin: 0;
            padding: 0 0 90px 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .logo-container {
            display: flex;
            justify-content: flex-end;
            padding: 15px 30px 5px 15px;
        }

        .logo-container img {
            height: 90px;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));
            transition: transform 0.2s;
        }

        .logo-container img:hover {
            transform: scale(1.02);
        }

        #reloj {
            text-align: center;
            font-weight: 600;
            font-size: 28px;
            background: rgba(10, 50, 20, 0.6);
            backdrop-filter: blur(4px);
            padding: 18px 10px;
            margin: 10px 20px 20px;
            color: #f0fff0;
            border-radius: 60px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.5);
            letter-spacing: 1px;
            border: 1px solid #90ee90;
        }

        h2 {
            text-align: center;
            font-size: 26px;
            margin: 15px 0 5px;
            text-shadow: 2px 2px 4px #00000050;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        h2 a {
            background: #dc3545;
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 18px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            transition: all 0.2s;
            border: 1px solid #ffb3b3;
        }

        h2 a:hover {
            background: #b02a37;
            transform: scale(1.05);
            box-shadow: 0 8px 12px rgba(0,0,0,0.4);
            text-decoration: none;
        }

        .main-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
            max-width: 1300px;
            margin: 0 auto;
            padding: 10px 20px;
            flex: 1;
        }

        .form-container {
            flex: 1 1 500px;
            max-width: 550px;
        }

        .procesos-reference-container {
            flex: 1 1 320px;
            max-width: 350px;
            background: rgba(30, 126, 52, 0.8);
            backdrop-filter: blur(8px);
            padding: 20px 18px;
            border-radius: 20px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.4);
            border: 1px solid #7ccf7c;
            transition: all 0.2s;
        }

        .procesos-reference-container:hover {
            box-shadow: 0 16px 32px rgba(0,0,0,0.5);
            border-color: #aaffaa;
        }

        .procesos-reference-container h3 {
            margin: 0 0 16px 0;
            font-size: 22px;
            font-weight: 600;
            color: #f0fff0;
            text-align: center;
            border-bottom: 2px dashed #b3ffb3;
            padding-bottom: 10px;
        }

        .procesos-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
            background-color: #ffffff;
            color: #000000;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
        }

        .procesos-table th, .procesos-table td {
            padding: 12px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .procesos-table thead {
            background: #0f4a1f;
            color: white;
            font-weight: 600;
        }

        .procesos-table tbody tr {
            transition: background 0.1s;
        }

        .procesos-table tbody tr:hover {
            background: #e0ffe0;
        }

        #codigoEmpleadoContainer, #nombreEmpleadoContainer, 
        #procesoContainer, #procesoSeleccionadoContainer {
            max-width: 550px;
            margin: 20px auto;
            background: linear-gradient(145deg, #166b2a, #1a7a30);
            padding: 20px 25px;
            border-radius: 28px;
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(0,0,0,0.4);
            border: 1px solid #98fb98;
        }

        #nombreEmpleadoContainer, #procesoSeleccionadoContainer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 20px;
            font-weight: 500;
        }

        #nombreEmpleadoContainer strong, #procesoSeleccionadoContainer strong {
            background: #0f3a1f;
            padding: 10px 18px;
            border-radius: 40px;
            color: #ffffff;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.4);
            word-break: break-word;
        }

        #codigoEmpleadoContainer form, #procesoContainer form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        #codigoEmpleadoContainer label, #procesoContainer label {
            font-weight: 600;
            font-size: 18px;
            color: #e6ffe6;
            letter-spacing: 0.5px;
        }

        input[type="text"] {
            width: 100%;
            padding: 14px 18px;
            margin: 8px 0;
            font-size: 18px;
            border-radius: 50px;
            border: none;
            background: #f9fff9;
            box-shadow: inset 0 4px 10px rgba(0,20,0,0.1);
            transition: all 0.2s;
            border: 2px solid transparent;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #a5d6a5;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(100, 200, 100, 0.3);
        }

        button {
            background: linear-gradient(145deg, #0a4d0a, #0a5c0a);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 60px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            border: 1px solid #b3ffb3;
            box-shadow: 0 6px 0 #052005, 0 8px 12px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        button:hover {
            background: #0a6c0a;
            border-color: #e6ffe6;
            transform: translateY(-2px);
            box-shadow: 0 8px 0 #052005, 0 12px 18px rgba(0,0,0,0.4);
        }

        button:active {
            transform: translateY(4px);
            box-shadow: 0 2px 0 #052005, 0 8px 12px rgba(0,0,0,0.4);
        }

        #formCambiarEmpleado button, #formCambiarProceso button {
            background: #5a4a00;
            border-color: #ffe066;
            box-shadow: 0 4px 0 #2f2a00;
            padding: 12px 18px;
            font-size: 16px;
        }

        #formProduccion {
            max-width: 550px;
            margin: 30px auto 40px;
            background: linear-gradient(145deg, #1e7e34, #166b2a);
            padding: 28px 30px;
            border-radius: 32px;
            box-shadow: 0 16px 28px rgba(0,0,0,0.5);
            border: 1px solid #b3ffb3;
        }

        #formProduccion label {
            display: block;
            margin-top: 5px;
            margin-bottom: 12px;
            font-weight: 600;
            font-size: 22px;
            color: #ffffff;
            text-shadow: 1px 1px 2px black;
        }

        #formProduccion input[type="text"] {
            font-size: 22px;
            padding: 16px 20px;
            background: #fefffe;
            border: 3px solid #0f4a1f;
        }

        .bloques-contenedor {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
            margin: 30px auto;
            max-width: 1300px;
            padding: 0 20px;
        }

        .bloque-verde {
            flex: 1 1 450px;
            padding: 24px;
            background: linear-gradient(145deg, #1f8a3c, #197a32);
            border-radius: 28px;
            color: #ffffff;
            box-shadow: 0 16px 30px #00000050;
            border: 1px solid #b3e6b3;
            transition: transform 0.2s;
        }

        .bloque-verde:hover {
            transform: scale(1.01);
            border-color: #ffffff;
        }

        .lista-blanca {
            margin-left: 24px;
            list-style-type: disc;
            color: white;
            font-size: 18px;
            line-height: 1.6;
        }

        .lista-blanca li {
            margin-bottom: 6px;
        }

        .resumen-produccion {
            color: #ffffcc;
            font-weight: 700;
            margin-bottom: 18px;
            font-size: 20px;
            background: rgba(0,30,0,0.5);
            padding: 12px 18px;
            border-radius: 50px;
            text-align: center;
        }

        .tabla-produccion {
            width: 100%;
            border-collapse: collapse;
            font-size: 16px;
            background-color: #ffffff;
            color: #000000;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 18px #00000030;
        }

        .tabla-produccion thead {
            background: #0f4a1f;
            color: white;
            font-size: 17px;
        }

        .tabla-produccion th, .tabla-produccion td {
            padding: 14px 12px;
            border: 1px solid #ccc;
            text-align: center;
        }

        .tabla-produccion tbody tr:nth-child(even) {
            background-color: #f8fff8;
        }

        .tabla-produccion tbody tr:hover {
            background: #d0f0d0;
        }

        .whatsapp-btn {
            position: fixed;
            bottom: 25px;
            right: 25px;
            z-index: 9999;
            background: #25D366;
            padding: 14px 22px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 6px 18px #00000050;
            animation: breathe 2s infinite;
            text-decoration: none;
            color: white;
            font-weight: 700;
            font-size: 17px;
            border: 2px solid white;
        }

        .whatsapp-btn i {
            font-size: 28px;
            animation: beat 2s infinite;
        }

        @keyframes breathe {
            0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.6); }
            70% { box-shadow: 0 0 0 18px rgba(37, 211, 102, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 0, 0, 0); }
        }

        @keyframes beat {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        .odoo-message-btn {
            position: fixed;
            bottom: 110px;
            right: 25px;
            z-index: 9999;
            background: #238b1f;
            color: white;
            padding: 14px 22px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            font-size: 17px;
            transition: 0.2s;
            box-shadow: 0 6px 12px rgba(0,0,0,0.4);
            border: 2px solid white;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .odoo-message-btn:hover {
            background: #1a6917;
            transform: scale(1.05);
            border-color: #d4fcd4;
        }

        .firma {
            text-align: center;
            font-size: 17px;
            color: #e6ffe6;
            padding: 20px 15px;
            background: #0a3a1a;
            border-top: 2px solid #98fb98;
            margin-top: 40px;
            box-shadow: 0 -6px 16px rgba(0,0,0,0.4);
        }

        .firma p {
            margin: 8px 0 0;
            font-size: 16px;
            color: #ccffcc;
            font-style: italic;
        }

        #error-msg {
            color: #ffdddd;
            background: #8b0000;
            padding: 12px 18px;
            border-radius: 60px;
            font-weight: 600;
            margin-top: 18px;
            text-align: center;
            border-left: 6px solid #ffb3b3;
            font-size: 17px;
        }

        .texto-blanco {
            color: #ffffb3;
            font-weight: 800;
        }

        .badge-duplicado {
            background: #ff9800;
            color: #000;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 700;
            display: inline-block;
            margin-left: 12px;
        }

        @media (max-width: 750px) {
            body { padding-bottom: 130px; }
            #reloj { font-size: 20px; }
            .form-container, .procesos-reference-container { flex: 1 1 100%; max-width: 100%; }
            .whatsapp-btn, .odoo-message-btn { padding: 12px 18px; font-size: 15px; right: 15px; }
            .odoo-message-btn { bottom: 100px; }
            .whatsapp-btn { bottom: 25px; }
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <img src="/control_produccion/public/logo.png" alt="Logo">
    </div>

    <div id="reloj"></div>

    <h2>Bienvenid@ | 
    <a href="login_picking.php"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a></h2>

    <div class="main-container">
        <div class="form-container">
            <!-- Contenedor Código Empleado -->
            <div id="codigoEmpleadoContainer" style="<?= empty($nombreEmpleado) ? '' : 'display:none;' ?>">
                <form method="POST" action="">
                    <label for="codigo_empleado"><i class="bi bi-person-badge"></i> Código empleado:</label>
                    <input
                        type="text"
                        id="codigo_empleado"
                        name="codigo_empleado"
                        autocomplete="off"
                        autofocus
                        value=""
                        placeholder="Ingrese código"
                    />
                    <button type="submit" name="consultar_empleado" value="1">
                        <i class="bi bi-search"></i> Buscar empleado
                    </button>
                </form>
            </div>

            <!-- Contenedor Nombre Empleado y botón para cambiar -->
            <div id="nombreEmpleadoContainer" style="<?= empty($nombreEmpleado) ? 'display:none;' : '' ?>">
                <span><i class="bi bi-person-check"></i> Empleado:</span>
                <strong id="nombre_empleado"><?= htmlspecialchars($nombreEmpleado ?? '---') ?></strong>

                <form method="POST" id="formCambiarEmpleado" style="display:inline;">
                    <input type="hidden" name="cambiar_empleado" value="1" />
                    <button type="submit"><i class="bi bi-arrow-repeat"></i> Cambiar</button>
                </form>
            </div>

            <!-- Contenedor Proceso -->
            <div id="procesoContainer" style="<?= empty($nombreEmpleado) || !empty($proceso_seleccionado) ? 'display:none;' : '' ?>">
                <form method="POST" action="">
                    <label for="proceso_id"><i class="bi bi-diagram-3"></i> ID Del Proceso:</label>
                    <input
                        type="text"
                        id="proceso_id"
                        name="proceso_id"
                        autocomplete="off"
                        value=""
                        placeholder="Ej: 101"
                        required
                    />
                    <button type="submit"><i class="bi bi-check-lg"></i> Seleccionar</button>
                </form>
            </div>

            <!-- Contenedor Proceso Seleccionado y botón para cambiar -->
            <div id="procesoSeleccionadoContainer" style="<?= empty($proceso_seleccionado) ? 'display:none;' : '' ?>">
                <span><i class="bi bi-gear"></i> Proceso:</span>
                <strong id="proceso_seleccionado"><?= htmlspecialchars($proceso_seleccionado ?? '---') ?></strong>

                <form method="POST" id="formCambiarProceso" style="display:inline;">
                    <input type="hidden" name="cambiar_proceso" value="1" />
                    <button type="submit"><i class="bi bi-arrow-repeat"></i> Cambiar</button>
                </form>
            </div>

            <!-- Formulario Producción -->
            <form method="POST" action="" id="formProduccion">
                <fieldset id="produccionFieldset" <?= (empty($nombreEmpleado) || empty($proceso_seleccionado)) ? 'disabled' : '' ?> style="border: none; padding: 0;">
                    <?php if (!empty($_SESSION['mensaje_error'])): ?>
                        <p style="color: #ffd1d1; background-color: #8b1a1a; padding: 14px 18px; border-radius: 60px; font-weight: bold; text-align: center; border-left: 8px solid #ffb3b3;">
                            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['mensaje_error'] ?>
                        </p>
                        <?php unset($_SESSION['mensaje_error']); ?>
                    <?php endif; ?>

                    <?php if (!empty($mensaje)): ?>
                        <p style="color: #ffd1d1; background-color: #8b1a1a; padding: 14px 18px; border-radius: 60px; font-weight: bold; text-align: center; border-left: 8px solid #ffb3b3;">
                            <i class="bi bi-exclamation-triangle"></i> <?= $mensaje ?>
                        </p>
                    <?php endif; ?>

                    <label for="referencia"><i class="bi bi-upc-scan"></i> Referencia de Producción:</label>
                    <input type="text" name="referencia1" id="referencia" class="referencia" maxlength="20" required placeholder="Escanee o ingrese referencia" autocomplete="off" />
                    
                    <div id="error-msg"></div>
                </fieldset>
            </form>
        </div>

        <!-- Contenedor de Referencia de Procesos -->
        <div class="procesos-reference-container" style="<?= empty($nombreEmpleado) || !empty($proceso_seleccionado) ? 'display:none;' : '' ?>">
            <h3><i class="bi bi-list-ul"></i> Referencia de Procesos</h3>
            <div style="overflow-x:auto; max-height: 360px; overflow-y: auto; border-radius: 16px;">
                <table class="procesos-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Proceso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($PROCESOS_DATA as $proceso): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($proceso['id']) ?></strong></td>
                                <td><?= htmlspecialchars($proceso['proceso']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top: 15px; color: #e6ffe6; text-align: center; font-size: 15px;">
                <i class="bi bi-info-circle"></i> Ingrese el ID para iniciar
            </p>
        </div>
    </div>

    <?php if (!empty($nombreEmpleado)): ?>
        <?php if (!empty($contador_produccion_proceso)): ?>
            <p class="resumen-produccion" style="max-width: 600px; margin: 20px auto;">
                <i class="bi bi-check-circle"></i> Producción en "<span style="background: #0f4a1f; padding: 6px 16px; border-radius: 50px;"><?= htmlspecialchars($proceso_seleccionado) ?></span>": 
                <span id="contador_proceso_php" style="background: #ffd700; color: #0a3a0a; padding: 6px 16px; border-radius: 50px; font-weight: 800;"><?= htmlspecialchars($contador_produccion_proceso) ?></span> referencias.
            </p>
        <?php endif; ?>

        <?php
            $hay_referencias = !empty($_SESSION['referencias_escaneadas']);
            $mostrar_bloque = !empty($trabajos_por_proceso) || $hay_referencias;
            $clase_contenedor = $hay_referencias ? 'multiple' : 'centrado';
        ?>

        <?php if ($mostrar_bloque): ?>
        <div class="bloques-contenedor <?= $clase_contenedor ?>">
            
            <?php if ($hay_referencias): ?>
                <div class="bloque-verde">
                    <p style="font-size: 22px; font-weight: 700; background: rgba(0,0,0,0.2); padding: 12px; border-radius: 60px; text-align: center;">
                        <i class="bi bi-clipboard-check"></i> 
                        <span class="texto-blanco"><?= htmlspecialchars($_SESSION['referencias_escaneadas']) ?></span> referencias registradas hoy.
                        <?= count($_SESSION['referencias_escaneadas_lista']) > 20 ? '<span style="background: #ff9800; padding: 4px 14px; border-radius: 30px; color: black; margin-left: 12px; font-size: 15px;">Últimas 20</span>' : '' ?>
                    </p>

                    <?php if (!empty($_SESSION['referencias_escaneadas_lista'])): ?>
                        <div style="overflow-x:auto; max-height: 500px; overflow-y: auto; border-radius: 20px; border: 2px solid #fff;">
                            <table class="tabla-produccion">
                                <thead>
                                    <tr>
                                        <th>Referencia</th>
                                        <th>Proceso</th>
                                        <th>Fecha y Hora</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $lista = array_slice(array_reverse($_SESSION['referencias_escaneadas_lista']), 0, 20);
                                    foreach ($lista as $item): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($item['referencia']) ?></strong></td>
                                            <td><?= htmlspecialchars($item['proceso']) ?></td>
                                            <td>
                                                <?= isset($item['fecha_hora']) 
                                                    ? htmlspecialchars($item['fecha_hora']) 
                                                    : '<em>Sin fecha</em>' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($trabajos_por_proceso)): 
                $total_referencias_procesos = array_sum($trabajos_por_proceso);
            ?>
            <div class="bloque-verde">
                <?php if (!empty($contador_produccion_total)): ?>
                    <p class="resumen-produccion">
                        <i class="bi bi-bar-chart"></i> Producción general: 
                        <span id="contador_total_php" style="background: #ffd700; color: #0a3a0a; padding: 6px 18px; border-radius: 50px; font-weight: 800;"><?= htmlspecialchars($contador_produccion_total) ?></span> total referencias.
                    </p>
                <?php endif; ?>

                <p style="margin: 16px 0 10px; font-size: 20px; font-weight: 600; border-bottom: 2px solid #98fb98; padding-bottom: 12px;">
                    <i class="bi bi-pie-chart"></i> Producción por proceso (Total: <?= htmlspecialchars($total_referencias_procesos) ?>):
                </p>
                <ul class="lista-blanca">
                    <?php foreach ($trabajos_por_proceso as $proceso => $cantidad): ?>
                        <?php if ($cantidad > 0): ?>
                            <li>
                                <?= htmlspecialchars($proceso) ?>: 
                                <span style="background: #0a4a1a; padding: 4px 14px; border-radius: 40px; font-weight: 700; color: #ffffb3;"><?= htmlspecialchars($cantidad) ?></span> ref.
                                <?php if ($proceso === $proceso_seleccionado): ?>
                                    <span style="background: #ffc107; color: #0a3a0a; padding: 4px 12px; border-radius: 40px; font-size: 14px; margin-left: 8px; font-weight: 800;">Actual</span>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <script>
        // Botón para cambiar de empleado
        document.getElementById('formCambiarEmpleado')?.addEventListener('submit', function (e) {
            e.preventDefault();
            document.getElementById('codigoEmpleadoContainer').style.display = '';
            document.getElementById('nombreEmpleadoContainer').style.display = 'none';
            document.getElementById('procesoContainer').style.display = '';
            document.getElementById('procesoSeleccionadoContainer').style.display = 'none';
            document.querySelector('.procesos-reference-container').style.display = '';

            const inputCodigo = document.getElementById('codigo_empleado');
            inputCodigo.value = '';
            inputCodigo.focus();
            document.getElementById('produccionFieldset').disabled = true;
            this.submit();
        });

        // Botón para cambiar de proceso
        document.getElementById('formCambiarProceso')?.addEventListener('submit', function (e) {
            e.preventDefault();
            document.getElementById('procesoContainer').style.display = '';
            document.getElementById('procesoSeleccionadoContainer').style.display = 'none';
            document.querySelector('.procesos-reference-container').style.display = '';

            const inputProceso = document.getElementById('proceso_id');
            inputProceso.value = '';
            inputProceso.focus();
            document.getElementById('produccionFieldset').disabled = true;
            this.submit();
        });

        <?php if (!empty($nombreEmpleado) && !empty($proceso_seleccionado)): ?>
            window.addEventListener('DOMContentLoaded', function () {
                document.getElementById('referencia').focus();
            });
        <?php elseif (!empty($nombreEmpleado)): ?>
            window.addEventListener('DOMContentLoaded', function () {
                document.getElementById('proceso_id').focus();
            });
        <?php endif; ?>

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
            if (relojElemento) relojElemento.textContent = fechaHora;
        }
        setInterval(actualizarReloj, 1000);
        actualizarReloj();

        const inputReferencia = document.getElementById('referencia');
        const form = document.getElementById('formProduccion');
        const errorMsg = document.getElementById('error-msg');
        let enviando = false;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            enviarReferencia();
        });

        inputReferencia.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                enviarReferencia();
            }
        });

        function enviarReferencia() {
            if (enviando) return;
            if (!inputReferencia.value.trim()) {
                mostrarError('❌ Ingrese una referencia');
                inputReferencia.focus();
                return;
            }
            
            enviando = true;
            mostrarError('');

            const formData = new FormData(form);
            formData.append('ajax', '1');

            fetch(form.action || '', {
                method: form.method,
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    inputReferencia.value = '';
                    location.reload();
                } else {
                    if (data.duplicado) {
                        mostrarError('⏳ DUPLICADO: ' + (data.mensaje || 'Ya registrado en últimos 5 minutos'));
                        // Pequeña vibración en el input
                        inputReferencia.style.border = '3px solid #ff9800';
                        setTimeout(() => inputReferencia.style.border = '3px solid #0f4a1f', 500);
                    } else {
                        mostrarError('❌ ' + (data.mensaje || 'Error al registrar'));
                    }
                    inputReferencia.focus();
                }
            })
            .catch(err => {
                mostrarError('❌ Error de conexión');
                console.error(err);
                inputReferencia.focus();
            })
            .finally(() => {
                enviando = false;
            });
        }

        function mostrarError(mensaje) {
            if (errorMsg) {
                errorMsg.innerHTML = mensaje;
                errorMsg.style.color = 'white';
                errorMsg.style.background = '#8b1a1a';
                errorMsg.style.padding = '12px 18px';
                errorMsg.style.borderRadius = '60px';
                errorMsg.style.fontWeight = 'bold';
            } else if (mensaje) {
                alert(mensaje);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if (empty($nombreEmpleado)): ?>
                document.getElementById('codigo_empleado').focus();
            <?php elseif (empty($proceso_seleccionado)): ?>
                document.getElementById('proceso_id').focus();
            <?php else: ?>
                document.getElementById('referencia').focus();
            <?php endif; ?>
        });

        // Auto limpiar mensajes de error después de 6 segundos
        setInterval(function() {
            const errorDiv = document.getElementById('error-msg');
            if (errorDiv && errorDiv.innerHTML.includes('DUPLICADO')) {
                // No limpiar inmediatamente, dejar que el operador lea
            }
        }, 1000);
    </script>

    <div class="firma">
        Sistema de control de producción | © <?php echo date("Y"); ?>
        <p>Desarrollado por: Nestor Rosales | Rosales_Dev91</p> 
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>

    <a href="https://wa.me/50672360749?text=Hola, tengo una consulta acerca de" target="_blank" class="whatsapp-btn">
        <i class="bi bi-whatsapp"></i>
        <span class="whatsapp-text">Soporte</span>
    </a>

    <a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" target="_blank" class="odoo-message-btn">
        <i class="bi bi-chat-dots"></i> Soporte Odoo
    </a>

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