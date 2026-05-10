<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once 'registrar_actividad.php';
date_default_timezone_set('America/Costa_Rica');

// =============================================
// FUNCIONES PARA OBTENER INFORMACIÓN DEL CLIENTE
// =============================================

if (!function_exists('getRealIP')) {
    function getRealIP() {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('getHostnameFromIP')) {
    function getHostnameFromIP($ip) {
        if ($ip && $ip != '0.0.0.0' && $ip != '::1') {
            $hostname = @gethostbyaddr($ip);
            if ($hostname && $hostname != $ip) {
                return $hostname;
            }
        }
        return 'localhost';
    }
}

if (!function_exists('getUserAgent')) {
    function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
    }
}

// =============================================
// FIN DE LAS FUNCIONES
// =============================================

$mensaje = '';
$codigoEmpleado = $_POST['codigo_empleado'] ?? '';
$nombreEmpleado = '';
$area_id = $_POST['area_id'] ?? '';
$equipo_id = $_POST['equipo_id'] ?? '';

// 0. Limpieza de sesión para cambiar empleado, área o equipo
if (isset($_POST['cambiar_empleado'])) {
    unset(
        $_SESSION['codigoEmpleado'],
        $_SESSION['nombreEmpleado'],
        $_SESSION['empleado'],
        $_SESSION['rol'],
        $_SESSION['codigo_empleado'],
        $_SESSION['id_empleado'],
        $_SESSION['ip'],
        $_SESSION['hostname'],
        $_SESSION['user_agent'],
        $_SESSION['ultimo_acceso'],
        $_SESSION['fecha_login'],
        $_SESSION['area_seleccionada'],
        $_SESSION['area_id'],
        $_SESSION['equipo_seleccionado'],
        $_SESSION['equipo_id'],
        $_SESSION['turno_seleccionado'],
        $_SESSION['ordenes_escaneadas'],
        $_SESSION['ordenes_escaneadas_lista']
    );
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['cambiar_area'])) {
    unset(
        $_SESSION['area_seleccionada'],
        $_SESSION['area_id'],
        $_SESSION['equipo_seleccionado'],
        $_SESSION['equipo_id'],
        $_SESSION['ordenes_escaneadas'],
        $_SESSION['ordenes_escaneadas_lista']
    );
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['cambiar_equipo'])) {
    unset(
        $_SESSION['equipo_seleccionado'],
        $_SESSION['equipo_id'],
        $_SESSION['ordenes_escaneadas'],
        $_SESSION['ordenes_escaneadas_lista']
    );
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 1. Inicializar variables desde sesión o POST
$area_seleccionada = $_SESSION['area_seleccionada'] ?? '';
$equipo_seleccionado = $_SESSION['equipo_seleccionado'] ?? '';
$turno_seleccionado = $_POST['turno'] ?? $_SESSION['turno_seleccionado'] ?? '';
$orden = strtoupper(trim($_POST['orden1'] ?? ''));
$empleado = $_SESSION['empleado'] ?? '';
$nombreEmpleado = $_SESSION['nombreEmpleado'] ?? null;

// 2. Validar y obtener área desde ID
if (isset($_POST['area_id']) && !empty($_POST['area_id'])) {
    $stmt_area = $conn->prepare("SELECT id, area FROM areas WHERE id = ?");
    $stmt_area->bind_param("s", $_POST['area_id']);
    $stmt_area->execute();
    $stmt_area->bind_result($area_id_db, $area_nombre);
    if ($stmt_area->fetch()) {
        $area_seleccionada = $area_nombre;
        $_SESSION['area_seleccionada'] = $area_nombre;
        $_SESSION['area_id'] = $area_id_db;
    } else {
        $mensaje = "Área no encontrada con el ID: " . htmlspecialchars($_POST['area_id']);
        $area_seleccionada = '';
        unset($_SESSION['area_seleccionada'], $_SESSION['area_id']);
    }
    $stmt_area->close();
} elseif (!empty($_SESSION['area_id']) && !empty($_SESSION['area_seleccionada'])) {
    $area_id = $_SESSION['area_id'];
    $area_seleccionada = $_SESSION['area_seleccionada'];
}

// 2.1 Validar y obtener equipo desde ID - CON CONDICIÓN PARA ÁREAS SIN EQUIPOS
if (isset($_POST['equipo_id']) && !empty($_POST['equipo_id'])) {
    // Verificar que el equipo pertenezca al área seleccionada
    $stmt_equipo = $conn->prepare("SELECT id, nombre_equipo FROM equipos WHERE id = ? AND area_id = ?");
    $stmt_equipo->bind_param("ss", $_POST['equipo_id'], $_SESSION['area_id']);
    $stmt_equipo->execute();
    $stmt_equipo->bind_result($equipo_id_db, $equipo_nombre);
    if ($stmt_equipo->fetch()) {
        $equipo_seleccionado = $equipo_nombre;
        $_SESSION['equipo_seleccionado'] = $equipo_nombre;
        $_SESSION['equipo_id'] = $equipo_id_db;
    } else {
        $mensaje = "Equipo no encontrado con el ID: " . htmlspecialchars($_POST['equipo_id']) . " o no pertenece al área seleccionada";
        $equipo_seleccionado = '';
        unset($_SESSION['equipo_seleccionado'], $_SESSION['equipo_id']);
    }
    $stmt_equipo->close();
} elseif (!empty($_SESSION['equipo_id']) && !empty($_SESSION['equipo_seleccionado'])) {
    $equipo_id = $_SESSION['equipo_id'];
    $equipo_seleccionado = $_SESSION['equipo_seleccionado'];
} else {
    // Verificar si el área seleccionada tiene equipos asignados
    if (!empty($_SESSION['area_id'])) {
        $stmt_check_equipos = $conn->prepare("SELECT COUNT(*) FROM equipos WHERE area_id = ?");
        $stmt_check_equipos->bind_param("s", $_SESSION['area_id']);
        $stmt_check_equipos->execute();
        $stmt_check_equipos->bind_result($cantidad_equipos);
        $stmt_check_equipos->fetch();
        $stmt_check_equipos->close();
        
        // Si el área NO tiene equipos, establecer valores vacíos y continuar
        if ($cantidad_equipos == 0) {
            $equipo_seleccionado = '';
            $_SESSION['equipo_seleccionado'] = '';
            $_SESSION['equipo_id'] = '';
        }
    }
}

// 3. Guardar selección en sesión
if (isset($_POST['turno'])) $_SESSION['turno_seleccionado'] = $_POST['turno'];

// ✅ Libera la sesión para no bloquear otras peticiones
session_write_close();

// 4. Validar orden
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$ordenValida = preg_match('/^(JIM|JIMRECTI|JIMWAR)[0-9]{8}$/i', $orden);

if (!empty($orden) && !$ordenValida) {
    $mensajeError = "⚠️ Orden inválida: debe comenzar con <strong>JIM</strong>, <strong>JIMRECTI</strong> o <strong>JIMWAR</strong>, seguido de 8 dígitos.";

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'mensaje' => $mensajeError
        ]);
        exit;
    } else {
        session_start(); // 🔁 Reabrir sesión para almacenar mensaje
        $_SESSION['mensaje_error'] = $mensajeError;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// 🔁 Reabrimos sesión antes de modificar datos en $_SESSION
session_start();

// 5. Inicializar sesión si no existe
if (!isset($_SESSION['ordenes_escaneadas_lista'])) {
    $_SESSION['ordenes_escaneadas_lista'] = [];
} else {
    foreach ($_SESSION['ordenes_escaneadas_lista'] as $key => $item) {
        if (is_string($item)) {
            $_SESSION['ordenes_escaneadas_lista'][$key] = [
                'orden' => $item,
                'area' => $area_seleccionada,
                'equipo' => $equipo_seleccionado,
                'fecha_hora' => date("d/m/Y H:i:s")
            ];
        } else {
            if (!isset($item['fecha_hora'])) {
                $_SESSION['ordenes_escaneadas_lista'][$key]['fecha_hora'] = date("d/m/Y H:i:s");
            }
            if (!isset($item['area'])) {
                $_SESSION['ordenes_escaneadas_lista'][$key]['area'] = $area_seleccionada;
            }
            if (!isset($item['equipo'])) {
                $_SESSION['ordenes_escaneadas_lista'][$key]['equipo'] = $equipo_seleccionado;
            }
        }
    }
}

if (!isset($_SESSION['ordenes_escaneadas'])) {
    $_SESSION['ordenes_escaneadas'] = 0;
}

function orden_area_equipo_ya_registrada($orden, $area, $equipo, $lista) {
    foreach ($lista as $item) {
        if ($item['orden'] === $orden && $item['area'] === $area && $item['equipo'] === $equipo) {
            return true;
        }
    }
    return false;
}

// 6. Procesar registro (con cooldown de 15 minutos + validación de duplicado en áreas especiales)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['consultar_empleado']) && !isset($_POST['cambiar_empleado']) && !isset($_POST['cambiar_area']) && !isset($_POST['cambiar_equipo']) && !isset($_POST['area_id']) && !isset($_POST['equipo_id'])) {
    
    // Verificar si el área tiene equipos
    $area_tiene_equipos = false;
    if (!empty($_SESSION['area_id'])) {
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM equipos WHERE area_id = ?");
        $stmt_check->bind_param("s", $_SESSION['area_id']);
        $stmt_check->execute();
        $stmt_check->bind_result($cantidad);
        $stmt_check->fetch();
        $stmt_check->close();
        $area_tiene_equipos = ($cantidad > 0);
    }

    // Equipo para validación (vacío si no aplica)
    $equipo_para_validar = $area_tiene_equipos ? $equipo_seleccionado : '';

    // Validar datos requeridos
    if ($area_seleccionada && (!$area_tiene_equipos || $equipo_seleccionado) && $turno_seleccionado && $orden && $empleado) {

        $areas_especiales = ['encintado', 'bloqueo', 'generado', 'pulido', 'desbloqueo/láser', 'antirayas', 'trazado', 'corte/montaje'];
        $es_area_especial = in_array(strtolower($area_seleccionada), $areas_especiales);

        $mensaje = '';

        // === 1. ÁREAS ESPECIALES: Validar duplicado en CUALQUIER equipo dentro de 15 min ===
        if ($es_area_especial) {
            $query_bloqueo = "SELECT equipo, UNIX_TIMESTAMP(fecha) AS ts 
                              FROM produccion 
                              WHERE empleado = ? AND area = ? AND orden = ? 
                              AND fecha >= (NOW() - INTERVAL 15 MINUTE)
                              ORDER BY fecha DESC LIMIT 1";
            $stmt_bloqueo = $conn->prepare($query_bloqueo);
            $stmt_bloqueo->bind_param("sss", $empleado, $area_seleccionada, $orden);
            $stmt_bloqueo->execute();
            $stmt_bloqueo->bind_result($equipo_encontrado, $ts_encontrado);
            if ($stmt_bloqueo->fetch()) {
                $segundos_restantes = 900 - (time() - $ts_encontrado);
                $mensaje = "Ya existe la orden registrada en esta área. Fue registrada en el equipo: <b>" . htmlspecialchars($equipo_encontrado) . "</b>. Debes pasar a la siguente área para registrar.";

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'mensaje' => $mensaje, 'cooldown' => true, 'segundos' => $segundos_restantes]);
                    exit;
                } else {
                    $_SESSION['mensaje_error'] = $mensaje;
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                }
            }
            $stmt_bloqueo->close();
        }

        // === 2. COOLDOWN: Validar mismo equipo (o área sin equipos) en los últimos 15 min ===
        $query_cooldown = "SELECT UNIX_TIMESTAMP(fecha) AS ts 
                           FROM produccion 
                           WHERE empleado = ? AND area = ? AND equipo = ? AND orden = ? 
                           AND fecha >= (NOW() - INTERVAL 15 MINUTE)
                           ORDER BY fecha DESC LIMIT 1";
        $stmt_cooldown = $conn->prepare($query_cooldown);
        $stmt_cooldown->bind_param("ssss", $empleado, $area_seleccionada, $equipo_para_validar, $orden);
        $stmt_cooldown->execute();
        $stmt_cooldown->bind_result($ts_cooldown);
        if ($stmt_cooldown->fetch()) {
            $segundos_restantes = 900 - (time() - $ts_cooldown);
$mensaje = "Ya registraste esta orden en " . 
           ($area_tiene_equipos 
               ? "el equipo <strong>" . htmlspecialchars($equipo_seleccionado) . "</strong>" 
               : "el área <strong>" . htmlspecialchars($area_seleccionada) . "</strong>"
           ) . 
           ". <br><span style='color:#ffeb3b;'>Pasa a la siguiente área para continuar.</span>";
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'mensaje' => $mensaje, 'cooldown' => true, 'segundos' => $segundos_restantes]);
                exit;
            } else {
                $_SESSION['mensaje_error'] = $mensaje;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        }
        $stmt_cooldown->close();

        // === 3. INSERTAR REGISTRO ===
        $stmt = $conn->prepare("INSERT INTO produccion (empleado, area, equipo, orden, turno, fecha) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssss", $empleado, $area_seleccionada, $equipo_para_validar, $orden, $turno_seleccionado);

        if ($stmt->execute()) {
            $new_item = [
                'orden' => $orden,
                'area' => $area_seleccionada,
                'equipo' => $equipo_para_validar,
                'fecha_hora' => date("d/m/Y H:i:s")
            ];

            // Evitar duplicados en sesión
            if (!orden_area_equipo_ya_registrada($orden, $area_seleccionada, $equipo_para_validar, $_SESSION['ordenes_escaneadas_lista'])) {
                $_SESSION['ordenes_escaneadas_lista'][] = $new_item;
                $_SESSION['ordenes_escaneadas']++;
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'ordenes' => [$new_item]
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

// 7. Consultar empleado - MODIFICADO para guardar TODOS los datos de sesión
if (isset($_POST['consultar_empleado']) && !empty($codigoEmpleado)) {
    // Obtener TODOS los datos del empleado (incluyendo rol y código)
    $stmt = $conn->prepare("SELECT id, nombre_empleado, codigo_empleado, rol FROM empleados WHERE codigo_empleado = ?");
    $stmt->bind_param("s", $codigoEmpleado);
    $stmt->execute();
    $stmt->bind_result($id_empleado, $nombre, $codigo, $rol);
    
    if ($stmt->fetch()) {
        $nombreEmpleado = $nombre;
        
        // Obtener información del cliente
        $ip = getRealIP();
        $hostname = getHostnameFromIP($ip);
        $user_agent = getUserAgent();
        
        // Guardar TODA la información en sesión (COMPATIBLE CON EL MONITOR)
        $_SESSION['codigoEmpleado'] = $codigoEmpleado;
        $_SESSION['nombreEmpleado'] = $nombreEmpleado;
        $_SESSION['empleado'] = $nombreEmpleado;
        
        // === NUEVAS VARIABLES PARA EL MONITOR ===
        $_SESSION['rol'] = $rol;
        $_SESSION['codigo_empleado'] = $codigo;
        $_SESSION['id_empleado'] = $id_empleado;
        $_SESSION['ip'] = $ip;
        $_SESSION['hostname'] = $hostname;
        $_SESSION['user_agent'] = $user_agent;
        $_SESSION['ultimo_acceso'] = time();
        $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
        
        // Limpiar datos de producción anteriores
        unset(
            $_SESSION['ordenes_escaneadas'], 
            $_SESSION['ordenes_escaneadas_lista'], 
            $_SESSION['area_seleccionada'], 
            $_SESSION['area_id'], 
            $_SESSION['equipo_seleccionado'], 
            $_SESSION['equipo_id']
        );

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
    
    // Asegurar que las variables del monitor existan (por si acaso)
    if (!isset($_SESSION['rol'])) {
        // Recuperar rol de la BD si no está en sesión
        $stmt = $conn->prepare("SELECT id, codigo_empleado, rol FROM empleados WHERE codigo_empleado = ?");
        $stmt->bind_param("s", $codigoEmpleado);
        $stmt->execute();
        $stmt->bind_result($id_emp, $cod_emp, $rol_emp);
        if ($stmt->fetch()) {
            $_SESSION['rol'] = $rol_emp;
            $_SESSION['codigo_empleado'] = $cod_emp;
            $_SESSION['id_empleado'] = $id_emp;
        }
        $stmt->close();
    }
    
    // Asegurar que IP esté registrada
    if (!isset($_SESSION['ip'])) {
        $_SESSION['ip'] = getRealIP();
        $_SESSION['hostname'] = getHostnameFromIP($_SESSION['ip']);
        $_SESSION['user_agent'] = getUserAgent();
        $_SESSION['ultimo_acceso'] = time();
        $_SESSION['fecha_login'] = date('Y-m-d H:i:s');
    }
}

// 8. Obtener áreas, equipos y turnos
$AREAS = [];
$AREAS_DATA = []; // Array para almacenar id => nombre de áreas
$EQUIPOS = [];
$EQUIPOS_DATA = []; // Array para almacenar id => nombre de equipos
$TURNOS = [];

$result = $conn->query("SELECT id, area FROM areas ORDER BY area ASC");
while ($row = $result->fetch_assoc()) {
    $AREAS[] = $row['area'];
    $AREAS_DATA[] = ['id' => $row['id'], 'area' => $row['area']]; // Almacena id y nombre
}

// Obtener equipos según el área seleccionada
if (!empty($_SESSION['area_id'])) {
    $area_id_session = $_SESSION['area_id'];
    $result = $conn->query("SELECT id, nombre_equipo FROM equipos WHERE area_id = $area_id_session ORDER BY nombre_equipo ASC");
} else {
    $result = $conn->query("SELECT id, nombre_equipo FROM equipos ORDER BY nombre_equipo ASC");
}

while ($row = $result->fetch_assoc()) {
    $EQUIPOS[] = $row['nombre_equipo'];
    $EQUIPOS_DATA[] = ['id' => $row['id'], 'nombre_equipo' => $row['nombre_equipo']]; // Almacena id y nombre
}

$result = $conn->query("SELECT turno FROM turnos ORDER BY turno ASC");
while ($row = $result->fetch_assoc()) {
    $TURNOS[] = $row['turno'];
}

// 9. Funciones auxiliares
date_default_timezone_set('America/Guatemala');

function detectarTurno() {
    $hora = date("H:i");
    if ($hora >= "06:00" && $hora < "14:00") return "A";
    if ($hora >= "14:00" && $hora < "21:30") return "B";
    return "C";
}

function obtenerRangoTurnoC() {
    $now = new DateTime();
    if ((int)$now->format('H') >= 21) {
        $inicio = new DateTime('today 21:30:00');
        $fin = new DateTime('tomorrow 06:00:00');
    } else {
        $inicio = new DateTime('yesterday 21:30:00');
        $fin = new DateTime('today 06:00:00');
    }
    return [$inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];
}

function actualizarContadores($conn, $empleado, $area = null, $equipo = null, $turno = null) {
    $contador_area = 0;
    $contador_equipo = 0;
    $contador_total = 0;

    if ($turno === "C") {
        list($inicio, $fin) = obtenerRangoTurnoC();
        $cond_fecha = "fecha >= ? AND fecha < ?";
    } else {
        $cond_fecha = "DATE(fecha) = CURDATE()";
    }

    if ($area) {
        $query_area = "SELECT COUNT(*) FROM produccion WHERE empleado = ? AND area = ? AND turno = ? AND $cond_fecha";
        $stmt1 = $conn->prepare($query_area);
        ($turno === "C")
            ? $stmt1->bind_param("sssss", $empleado, $area, $turno, $inicio, $fin)
            : $stmt1->bind_param("sss", $empleado, $area, $turno);
        $stmt1->execute();
        $stmt1->bind_result($contador_area);
        $stmt1->fetch();
        $stmt1->close();
    }

    if ($equipo) {
        $query_equipo = "SELECT COUNT(*) FROM produccion WHERE empleado = ? AND equipo = ? AND turno = ? AND $cond_fecha";
        $stmt2 = $conn->prepare($query_equipo);
        ($turno === "C")
            ? $stmt2->bind_param("sssss", $empleado, $equipo, $turno, $inicio, $fin)
            : $stmt2->bind_param("sss", $empleado, $equipo, $turno);
        $stmt2->execute();
        $stmt2->bind_result($contador_equipo);
        $stmt2->fetch();
        $stmt2->close();
    }

    $query_total = "SELECT COUNT(*) FROM produccion WHERE empleado = ? AND turno = ? AND $cond_fecha";
    $stmt3 = $conn->prepare($query_total);
    ($turno === "C")
        ? $stmt3->bind_param("ssss", $empleado, $turno, $inicio, $fin)
        : $stmt3->bind_param("ss", $empleado, $turno);
    $stmt3->execute();
    $stmt3->bind_result($contador_total);
    $stmt3->fetch();
    $stmt3->close();

    return [$contador_area, $contador_equipo, $contador_total];
}

// --------------------------------------------
// BLOQUE PRINCIPAL
// --------------------------------------------
$produccion_hoy = [];
$trabajos_por_area = [];
$trabajos_por_equipo = [];

$turno_seleccionado = detectarTurno();

if (!empty($empleado)) {
    if ($turno_seleccionado === "C") {
        list($inicio, $fin) = obtenerRangoTurnoC();
        $query = "SELECT area, equipo, orden, turno, fecha FROM produccion 
                  WHERE empleado = ? AND turno = ? AND fecha >= ? AND fecha < ? ORDER BY fecha DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssss", $empleado, $turno_seleccionado, $inicio, $fin);
    } else {
        $query = "SELECT area, equipo, orden, turno, fecha FROM produccion 
                  WHERE empleado = ? AND DATE(fecha) = CURDATE() ORDER BY fecha DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $empleado);
    }

    $stmt->execute();
    $result = $stmt->get_result();

// === OBTENER ÁREAS QUE TIENEN EQUIPOS (una sola vez) ===
$areas_con_equipos = [];
$stmt_areas = $conn->query("
    SELECT DISTINCT a.area 
    FROM areas a 
    INNER JOIN equipos e ON a.id = e.area_id
");
while ($row = $stmt_areas->fetch_assoc()) {
    $areas_con_equipos[$row['area']] = true;
}
$stmt_areas->close();

$trabajos_por_equipo = []; // Reiniciar para evitar datos viejos

while ($row = $result->fetch_assoc()) {
    $produccion_hoy[] = $row;
    $area = $row['area'];
    $equipo = trim($row['equipo']); // Limpiar espacios

    // 1. Siempre contar en área
    $trabajos_por_area[$area] = ($trabajos_por_area[$area] ?? 0) + 1;

    // 2. Solo contar en equipo si:
    //    - El equipo tiene nombre (no vacío)
    //    - Y el área tiene equipos asignados
    if (!empty($equipo) && isset($areas_con_equipos[$area])) {
        $trabajos_por_equipo[$equipo] = ($trabajos_por_equipo[$equipo] ?? 0) + 1;
    }
    // → Órdenes con equipo vacío o de áreas sin equipos → NO van a $trabajos_por_equipo
}

$stmt->close();

// === CONTADORES GENERALES ===
list(, , $contador_produccion_total) = actualizarContadores($conn, $empleado, null, null, $turno_seleccionado);

$contador_produccion_area = !empty($area_seleccionada)
    ? actualizarContadores($conn, $empleado, $area_seleccionada, null, $turno_seleccionado)[0]
    : 0;

$contador_produccion_equipo = !empty($equipo_seleccionado)
    ? actualizarContadores($conn, $empleado, null, $equipo_seleccionado, $turno_seleccionado)[1]
    : 0;
}

// Verificar si el área actual tiene equipos (para mostrar/ocultar secciones)
$area_tiene_equipos = false;
if (!empty($_SESSION['area_id'])) {
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM equipos WHERE area_id = ?");
    $stmt_check->bind_param("s", $_SESSION['area_id']);
    $stmt_check->execute();
    $stmt_check->bind_result($cantidad);
    $stmt_check->fetch();
    $stmt_check->close();
    $area_tiene_equipos = ($cantidad > 0);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Registro de Producción</title>
    <style>
        body {
            background-color: #155724; /* Verde de fondo */
            color: white;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            padding-bottom: 70px; /* Espacio para el footer */
            box-sizing: border-box;
        }

        .main-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .form-container {
            flex: 1 1 500px;
            max-width: 500px;
        }

        .areas-reference-container, .equipos-reference-container {
            flex: 1 1 300px;
            max-width: 300px;
            background-color: #1e7e34;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 0 8px rgba(0,0,0,0.4);
        }

        .areas-reference-container h3, .equipos-reference-container h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #d4fcd4;
            text-align: center;
        }

        .areas-table, .equipos-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background-color: #ffffff;
            color: #000000;
            border-radius: 5px;
            overflow: hidden;
        }

        .areas-table th, .areas-table td,
        .equipos-table th, .equipos-table td {
            padding: 10px;
            border: 1px solid #cccccc;
            text-align: left;
        }

        .areas-table thead, .equipos-table thead {
            background-color: #28a745;
            color: white;
        }

        .areas-table tr:nth-child(even),
        .equipos-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        .logo-container {
            text-align: right;
            padding: 10px 20px 0 0;
        }

        .logo-container img {
            height: 100px;
        }

        #reloj {
            text-align: center;
            font-weight: bold;
            font-size: 28px;
            background-color: #155724; /* Verde oscuro para contraste */
            padding: 15px 0;
            margin-bottom: 20px;
            color: white;
            box-shadow: 0px 0px 10px rgba(0,0,0,0.5);
        }

        form {
            max-width: 500px;
            margin: auto;
            background-color: #1e7e34; /* Verde intermedio */
            padding: 20px;
            border-radius: 10px;
        }

        h2, p {
            text-align: center;
        }

        select, input[type="text"], button {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            font-size: 16px;
            border-radius: 5px;
            border: none;
            box-sizing: border-box;
        }

        button {
            background-color: #004d00;
            color: white;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: #003300;
        }

        a {
            color: #d4fcd4;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .firma {
            text-align: center;
            font-size: 16px;
            color: #d4fcd4;
            padding: 15px 0;
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            box-sizing: border-box;
            z-index: 1000;
        }

        .firma p {
            margin: 5px 0 0 0;
            font-size: 16px;
            color: #b2e0b2;
        }

        /* Contenedores */
        #codigoEmpleadoContainer, #nombreEmpleadoContainer, 
        #areaContainer, #areaSeleccionadaContainer,
        #equipoContainer, #equipoSeleccionadoContainer {
            max-width: 500px;
            margin: 20px auto;
            background-color: #1e7e34; /* Verde intermedio */
            padding: 15px 20px;
            border-radius: 10px;
            color: #d4fcd4;
            box-shadow: 0 0 8px rgba(0,0,0,0.4);
        }

        /* Formulario código empleado, área y equipo */
        #codigoEmpleadoContainer form, #areaContainer form, #equipoContainer form,
        #formCambiarEmpleado, #formCambiarArea, #formCambiarEquipo {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        /* Label */
        #codigoEmpleadoContainer label, #areaContainer label, #equipoContainer label {
            flex-basis: 100%;
            font-weight: bold;
            margin-bottom: 5px;
            color: #d4fcd4;
        }

        /* Inputs y select */
        #codigoEmpleadoContainer input[type="text"],
        #areaContainer input[type="text"],
        #equipoContainer input[type="text"],
        #formProduccion select,
        #formProduccion input[type="text"] {
            flex: 1 1 200px;
            padding: 8px 12px;
            border-radius: 5px;
            border: none;
            font-size: 16px;
        }

        /* Botones */
        #codigoEmpleadoContainer button,
        #areaContainer button,
        #equipoContainer button,
        #formCambiarEmpleado button,
        #formCambiarArea button,
        #formCambiarEquipo button,
        #formProduccion button {
            background-color: #004d00;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        #codigoEmpleadoContainer button:hover,
        #areaContainer button:hover,
        #equipoContainer button:hover,
        #formCambiarEmpleado button:hover,
        #formCambiarArea button:hover,
        #formCambiarEquipo button:hover,
        #formProduccion button:hover {
            background-color: #003300;
        }

        /* Texto empleado, área, equipo y botones cambiar alineados */
        #nombreEmpleadoContainer, #areaSeleccionadaContainer, #equipoSeleccionadoContainer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            font-size: 18px;
        }

        #nombreEmpleadoContainer strong, #areaSeleccionadaContainer strong, #equipoSeleccionadoContainer strong {
            color: #ffffff;
        }

        /* Form Producción */
        #formProduccion {
            max-width: 500px;
            margin: 20px auto 40px auto;
            background-color: #1e7e34;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.4);
        }

        #formProduccion label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
            color: #d4fcd4;
        }

        #formProduccion input[type="text"] {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <img src="/control_produccion/public/logo.png" alt="Logo">
    </div>

    <div id="reloj"></div>

    <h2>Bienvenid@ | 
    <a href="login.php">Cerrar sesión</a></h2>

    <div class="main-container">
        <div class="form-container">
            <!-- Contenedor Código Empleado -->
            <div id="codigoEmpleadoContainer" style="<?= empty($nombreEmpleado) ? '' : 'display:none;' ?>">
                <form method="POST" action="">
                    <label for="codigo_empleado">Código empleado:</label>
                    <input
                        type="text"
                        id="codigo_empleado"
                        name="codigo_empleado"
                        autocomplete="off"
                        autofocus
                        value=""
                    />
                    <button type="submit" name="consultar_empleado" value="1">
                        Buscar empleado
                    </button>
                </form>
            </div>

            <!-- Contenedor Nombre Empleado y botón para cambiar -->
            <div id="nombreEmpleadoContainer" style="<?= empty($nombreEmpleado) ? 'display:none;' : '' ?>">
                Empleado: <strong id="nombre_empleado"><?= htmlspecialchars($nombreEmpleado ?? '---') ?></strong>

                <form method="POST" id="formCambiarEmpleado" style="display:inline;">
                    <input type="hidden" name="cambiar_empleado" value="1" />
                    <button type="submit">Cambiar empleado</button>
                </form>
            </div>

            <!-- Contenedor Área -->
            <div id="areaContainer" style="<?= empty($nombreEmpleado) || !empty($area_seleccionada) ? 'display:none;' : '' ?>">
                <form method="POST" action="">
                    <label for="area_id">Seleccione ID Del Área:</label>
                    <input
                        type="text"
                        id="area_id"
                        name="area_id"
                        autocomplete="off"
                        value=""
                        required
                    />
                    <button type="submit">Seleccionar área</button>
                </form>
            </div>

            <!-- Contenedor Área Seleccionada y botón para cambiar -->
            <div id="areaSeleccionadaContainer" style="<?= empty($area_seleccionada) ? 'display:none;' : '' ?>">
                Área: <strong id="area_seleccionada"><?= htmlspecialchars($area_seleccionada ?? '---') ?></strong>

                <form method="POST" id="formCambiarArea" style="display:inline;">
                    <input type="hidden" name="cambiar_area" value="1" />
                    <button type="submit">Cambiar área</button>
                </form>
            </div>

            <!-- Contenedor Equipo - SOLO MOSTRAR SI EL ÁREA TIENE EQUIPOS -->
            <div id="equipoContainer" style="<?= (empty($area_seleccionada) || !empty($equipo_seleccionado) || !$area_tiene_equipos) ? 'display:none;' : '' ?>">
                <form method="POST" action="">
                    <label for="equipo_id">Seleccione ID Del Equipo:</label>
                    <input
                        type="text"
                        id="equipo_id"
                        name="equipo_id"
                        autocomplete="off"
                        value=""
                        required
                    />
                    <button type="submit">Seleccionar equipo</button>
                </form>
            </div>

            <!-- Contenedor Equipo Seleccionado y botón para cambiar - SOLO MOSTRAR SI EL ÁREA TIENE EQUIPOS -->
            <div id="equipoSeleccionadoContainer" style="<?= (empty($equipo_seleccionado) || !$area_tiene_equipos) ? 'display:none;' : '' ?>">
                Equipo: <strong id="equipo_seleccionado"><?= htmlspecialchars($equipo_seleccionado ?? '---') ?></strong>

                <form method="POST" id="formCambiarEquipo" style="display:inline;">
                    <input type="hidden" name="cambiar_equipo" value="1" />
                    <button type="submit">Cambiar equipo</button>
                </form>
            </div>

            <!-- Formulario Producción -->
            <form method="POST" action="" id="formProduccion">
                <fieldset id="produccionFieldset" <?= (empty($nombreEmpleado) || empty($area_seleccionada) || ($area_tiene_equipos && empty($equipo_seleccionado))) ? 'disabled' : '' ?>>
                    <label for="turno">Turno:</label>
                    <select name="turno_visible" id="turno" disabled>
                        <?php foreach ($TURNOS as $turno): ?>
                            <option
                                value="<?= htmlspecialchars($turno) ?>"
                                <?= ($turno === $turno_seleccionado) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($turno) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Este input oculto es el que realmente se envía -->
                    <input type="hidden" name="turno" value="<?= htmlspecialchars($turno_seleccionado) ?>">

                    <?php if (!empty($_SESSION['mensaje_error'])): ?>
                        <p style="max-width: 500px; margin: 10px auto; color: #ffd1d1; background-color: #5a0000; padding: 10px 15px; border-radius: 8px; font-weight: bold; text-align: center;">
                            <?= $_SESSION['mensaje_error'] ?>
                        </p>
                        <?php unset($_SESSION['mensaje_error']); ?>
                    <?php endif; ?>

                    <?php if (!empty($mensaje)): ?>
                        <p style="max-width: 500px; margin: 10px auto; color: #ffd1d1; background-color: #5a0000; padding: 10px 15px; border-radius: 8px; font-weight: bold; text-align: center;">
                            <?= $mensaje ?>
                        </p>
                    <?php endif; ?>

                    <label for="orden">Orden de Producción:</label><br />
                    <input type="text" name="orden1" id="orden" class="orden" maxlength="20" required /><br />

                    <!-- Mensaje de error aquí -->
                    <div id="error-msg" style="color: red; margin-top: 10px;"></div>
                </fieldset>
            </form>
        </div>

        <!-- Contenedor de Referencia de Áreas -->
        <div class="areas-reference-container" style="<?= empty($nombreEmpleado) || !empty($area_seleccionada) ? 'display:none;' : '' ?>">
            <h3>Referencia de Áreas</h3>
            <div style="overflow-x:auto; max-height: 300px; overflow-y: auto; border: 3px solid #ccc; border-radius: 8px;">
                <table class="areas-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Área</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($AREAS_DATA as $area): ?>
                            <tr>
                                <td><?= htmlspecialchars($area['id']) ?></td>
                                <td><?= htmlspecialchars($area['area']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Contenedor de Referencia de Equipos - SOLO MOSTRAR SI EL ÁREA TIENE EQUIPOS -->
        <div class="equipos-reference-container" style="<?= (empty($area_seleccionada) || !empty($equipo_seleccionado) || !$area_tiene_equipos) ? 'display:none;' : '' ?>">
            <h3>Referencia de Equipos</h3>
            <div style="overflow-x:auto; max-height: 300px; overflow-y: auto; border: 3px solid #ccc; border-radius: 8px;">
                <table class="equipos-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Equipo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($EQUIPOS_DATA as $equipo): ?>
                            <tr>
                                <td><?= htmlspecialchars($equipo['id']) ?></td>
                                <td><?= htmlspecialchars($equipo['nombre_equipo']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
        .bloques-contenedor {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin: 20px auto;
            box-sizing: border-box;
        }

        /* Bloque individual */
        .bloque-verde {
            flex: 1 1 480px; /* Ocupa al menos 480px, pero puede crecer */
            padding: 15px;
            background-color: #28a745;
            border-radius: 10px;
            color: #ffffff;
            font-weight: normal;
            box-sizing: border-box;
        }

        /* Si solo hay un bloque, se centra y se limita el ancho */
        .bloques-contenedor.centrado {
            max-width: 600px;
        }

        /* Si hay múltiples bloques, se permite mayor ancho */
        .bloques-contenedor.multiple {
            max-width: 1040px;
        }

        /* Lista blanca */
        .lista-blanca {
            margin-left: 20px;
            list-style-type: disc;
            color: rgb(255, 255, 255);
        }

        /* Estilo para contador resumen */
        .resumen-produccion {
            color: #d4fcd4;
            font-weight: bold;
            margin-bottom: 10px;
        }

        /* Texto blanco fuerte */
        .texto-blanco {
            color: #ffffff;
            font-weight: bold;
        }

        /* Tabla de órdenes escaneadas */
        .tabla-produccion {
            width: 100%;
            border-collapse: collapse;
            font-size: 16px;
            background-color: #ffffff;
            color: #000000;
            margin-top: 10px;
            border-radius: 5px;
            overflow: hidden;
        }

        .tabla-produccion thead {
            background-color: #28a745;
            color: white;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .tabla-produccion th,
        .tabla-produccion td {
            padding: 12px 16px;
            border: 1px solid #cccccc;
            text-align: center;
        }

        .tabla-produccion tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        .tabla-produccion tr:hover {
            /* background-color: #eaffea; */
        }
    </style>

    <?php if (!empty($nombreEmpleado)): ?>

        <?php if (!empty($contador_produccion_area)): ?>
            <p class="resumen-produccion" style="text-align: center;">
                Total de Producción en: "<span><?= htmlspecialchars($area_seleccionada) ?></span>": 
                <span id="contador_area_php"><?= htmlspecialchars($contador_produccion_area) ?></span> órdenes registradas.
            </p>
        <?php endif; ?>

        <?php
            $hay_ordenes = !empty($_SESSION['ordenes_escaneadas']);
            $mostrar_bloque = !empty($trabajos_por_area) || $hay_ordenes;
            $clase_contenedor = $hay_ordenes ? 'multiple' : 'centrado';
        ?>

        <?php if ($mostrar_bloque): ?>
        <div class="bloques-contenedor <?= $clase_contenedor ?>">
            
            <?php if ($hay_ordenes): ?>
                <div class="bloque-verde">
                    <p>
                        Se han registrado <span class="texto-blanco"><?= htmlspecialchars($_SESSION['ordenes_escaneadas']) ?></span> órdenes.
                        <?= count($_SESSION['ordenes_escaneadas_lista']) > 20 ? '(mostrando las últimas 20)' : '' ?>
                    </p>

                    <?php if (!empty($_SESSION['ordenes_escaneadas_lista'])): ?>
                        <div style="overflow-x:auto; max-height: 600px; overflow-y: auto; border: 3px solid #ccc; background-color: #ffffff; border-radius: 8px;">
                            <table class="tabla-produccion">
                                <thead>
                                    <tr>
                                        <th>Orden</th>
                                        <th>Área</th>
                                        <th>Fecha y Hora</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Mostrar solo las últimas 20
                                    $lista = array_slice(array_reverse($_SESSION['ordenes_escaneadas_lista']), 0, 20);
                                    foreach ($lista as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['orden']) ?></td>
                                            <td><?= htmlspecialchars($item['area']) ?></td>
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

            <?php if (!empty($trabajos_por_area)): 
    // Calcular el total sumando todos los valores de $trabajos_por_area
    $total_ordenes_areas = array_sum($trabajos_por_area);
?>

    <div class="bloque-verde">
        <?php if (!empty($contador_produccion_total)): ?>
            <p class="resumen-produccion">
                Producción general: 
                <span id="contador_total_php"><?= htmlspecialchars($contador_produccion_total) ?></span> órdenes total producción.
            </p>
        <?php endif; ?>

<?php if (!empty($trabajos_por_equipo)): 
    $total_ordenes_equipos = array_sum($trabajos_por_equipo);
?>
    <p class="resumen-produccion">
        Producción por equipo (Total: <?= htmlspecialchars($total_ordenes_equipos) ?> órdenes):
    </p>
    <ul class="lista-blanca">
        <?php foreach ($trabajos_por_equipo as $equipo => $cantidad): ?>
            <li>
                <?= htmlspecialchars($equipo) ?>: <?= htmlspecialchars($cantidad) ?> órdenes
                <?php if ($equipo === $equipo_seleccionado): ?>
                    <strong>(Equipo actual)</strong>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

        <p style="margin: 10px 0 5px 0;">Producción por área (Total: <?= htmlspecialchars($total_ordenes_areas) ?> órdenes):</p>
        <ul class="lista-blanca">
            <?php foreach ($trabajos_por_area as $area => $cantidad): ?>
                <?php if ($cantidad > 0): ?>
                    <li><?= htmlspecialchars($area) ?>: <?= htmlspecialchars($cantidad) ?> órdenes</li>
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
        document.getElementById('formCambiarEmpleado')?.addEventListener('submit', function () {
            document.getElementById('codigoEmpleadoContainer').style.display = '';
            document.getElementById('nombreEmpleadoContainer').style.display = 'none';
            document.getElementById('areaContainer').style.display = '';
            document.getElementById('areaSeleccionadaContainer').style.display = 'none';
            document.querySelector('.areas-reference-container').style.display = '';

            const inputCodigo = document.getElementById('codigo_empleado');
            inputCodigo.value = '';
            inputCodigo.focus();

            // Deshabilitar campos de producción
            document.getElementById('produccionFieldset').disabled = true;
        });

        // Botón para cambiar de área
        document.getElementById('formCambiarArea')?.addEventListener('submit', function () {
            document.getElementById('areaContainer').style.display = '';
            document.getElementById('areaSeleccionadaContainer').style.display = 'none';
            document.querySelector('.areas-reference-container').style.display = '';

            const inputArea = document.getElementById('area_id');
            inputArea.value = '';
            inputArea.focus();

            // Deshabilitar campos de producción
            document.getElementById('produccionFieldset').disabled = true;
        });

        // Si ya hay un empleado válido y área seleccionada, enfocar directamente en el campo de orden
        <?php if (!empty($nombreEmpleado) && !empty($area_seleccionada)): ?>
            window.addEventListener('DOMContentLoaded', function () {
                document.getElementById('orden').focus();
            });
        <?php elseif (!empty($nombreEmpleado)): ?>
            window.addEventListener('DOMContentLoaded', function () {
                document.getElementById('area_id').focus();
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
            if (relojElemento) {
                relojElemento.textContent = fechaHora;
            }
        }
        setInterval(actualizarReloj, 1000);
        actualizarReloj();

        const inputOrden = document.getElementById('orden');
        const form = document.getElementById('formProduccion');
        const tablaBody = document.querySelector('#tablaOrdenes tbody');
        const errorMsg = document.getElementById('error-msg');

        let enviando = false;

        if (inputOrden && form && tablaBody) {
            inputOrden.addEventListener('input', () => {
                const valor = inputOrden.value.trim();
                const ordenValida = /^(JIM|JIMRECTI|JIMWAR)[0-9]{8}$/i.test(valor);

                if (ordenValida && !enviando) {
                    enviarOrden();
                }
            });

            form.addEventListener('submit', e => e.preventDefault());

            function enviarOrden() {
                if (enviando) return;
                enviando = true;

                const formData = new FormData(form);

                fetch(form.action || '', {
                    method: form.method,
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.ordenes && data.ordenes.length > 0) {
                        const item = data.ordenes[0];
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${item.orden}</td>
                            <td>${item.area}</td>
                            <td>${item.fecha_hora}</td>
                        `;
                        tablaBody.prepend(tr);

                        // Limitar a 20 filas máximo
                        while (tablaBody.children.length > 20) {
                            tablaBody.removeChild(tablaBody.lastChild);
                        }

                        inputOrden.value = '';
                        if (errorMsg) errorMsg.textContent = '';
                    } else {
                        mostrarError(data.mensaje || 'Error desconocido');
                    }
                })
                .catch(err => {
                    mostrarError('Error de red o servidor');
                    console.error(err);
                })
                .finally(() => {
                    enviando = false;
                    inputOrden.focus();
                });
            }

            function mostrarError(mensaje) {
                if (errorMsg) {
                    errorMsg.textContent = mensaje;
                    errorMsg.style.color = 'red';
                } else {
                    alert(mensaje);
                }
            }
        }

                // Enfocar automáticamente el campo de entrada apropiado
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (empty($nombreEmpleado)): ?>
                document.getElementById('codigo_empleado').focus();
            <?php elseif (empty($area_seleccionada)): ?>
                document.getElementById('area_id').focus();
            <?php elseif (empty($equipo_seleccionado) && $area_tiene_equipos): ?>
                document.getElementById('equipo_id').focus();
            <?php else: ?>
                document.getElementById('orden').focus();
            <?php endif; ?>
        });
    </script>

    <div class="firma">
        Sistema de control de producción | © <?php echo date("Y"); ?>
        <p>Desarrollado por: Nestor Rosales | Rosales_Dev91</p> 
    </div>

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>

    <!-- Botón flotante WhatsApp -->
    <a href="https://wa.me/50672360749?text=Hola, tengo una consulta acerca de" target="_blank" class="whatsapp-btn">
        <i class="bi bi-whatsapp"></i>
        <span class="whatsapp-text">Soporte</span>
    </a>

    <!-- Botón de soporte Odoo -->
    <a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" target="_blank" class="odoo-message-btn">
        💬 Soporte al usuario Odoo
    </a>

    <style>
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

        .whatsapp-btn i {
            font-size: 24px;
            margin-right: 8px;
            animation: beat 2s ease-in-out infinite;
        }

        .whatsapp-text {
            font-size: 16px;
        }

        /* Animaciones */
        @keyframes breathe {
            0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.5); }
            70% { box-shadow: 0 0 0 15px rgba(37, 211, 102, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 0, 0, 0); }
        }

        @keyframes beat {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* Botón Odoo fijo, arriba del botón WhatsApp */
        .odoo-message-btn {
            position: fixed;
            bottom: 80px;
            right: 20px;
            z-index: 9999;
            background-color: rgb(27, 177, 19);
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s ease;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .odoo-message-btn:hover {
            background-color: rgb(27, 177, 19);
        }

        /* Estilos opcionales para redes sociales */
        .s_social_media a {
            margin-right: 10px;
            font-size: 24px;
            color: #fff;
            background-color: #343a40;
            padding: 10px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: transform 0.2s ease-in-out, background-color 0.2s;
        }

        .s_social_media a:hover {
            transform: scale(1.15);
            background-color: #495057;
        }

        .s_social_media {
            padding: 20px 0;
        }
    </style>

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