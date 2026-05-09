<?php
// ============================================================
//  alarma_api.php  —  API AJAX para el sistema de alarmas
//  VERSIÓN CON ALARMAS RECURRENTES AUTOMÁTICAS
// ============================================================
session_start();
require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('America/Costa_Rica');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

$accion = trim($_POST['accion'] ?? '');

function calcularTurno() {
    $h = (int)date('H');
    if ($h >= 6 && $h < 14) return 'Diurno';
    if ($h >= 14 && $h < 22) return 'Vespertino';
    return 'Nocturno';
}

function getClientIP() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

switch ($accion) {

    case 'check_alarma':
        $ip = getClientIP();
        $ahora = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "SELECT id, mensaje, nombre_estacion, fecha_disparo, intervalo_minutos
             FROM alarma_programadas
             WHERE ip_destino = ?
               AND activa = 1
               AND confirmada = 0
               AND fecha_disparo <= ?
             ORDER BY fecha_disparo ASC
             LIMIT 1"
        );
        $stmt->bind_param("ss", $ip, $ahora);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            echo json_encode([
                'ok' => true,
                'alarma' => true,
                'alarma_id' => (int)$row['id'],
                'mensaje' => $row['mensaje'] ?? 'Realizar prueba de calidad',
                'estacion' => $row['nombre_estacion'] ?? $ip,
                'fecha_alarma' => $row['fecha_disparo'],
            ]);
        } else {
            $stmt = $conn->prepare(
                "SELECT fecha_disparo FROM alarma_programadas
                 WHERE ip_destino = ? AND activa = 1 AND confirmada = 0 AND fecha_disparo > ?
                 ORDER BY fecha_disparo ASC LIMIT 1"
            );
            $stmt->bind_param("ss", $ip, $ahora);
            $stmt->execute();
            $res2 = $stmt->get_result();
            $prox = $res2->fetch_assoc();
            $stmt->close();

            echo json_encode([
                'ok' => true,
                'alarma' => false,
                'proxima' => $prox['fecha_disparo'] ?? null,
            ]);
        }
        break;

    case 'confirmar':
        // Obtener datos
        $codigo = trim(strtoupper($_POST['codigo_empleado'] ?? ''));
        $alarma_id = (int)($_POST['alarma_id'] ?? 0);
        $ip = getClientIP();

        if (!$codigo || !$alarma_id) {
            echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']);
            exit;
        }

        // Verificar empleado
        $stmt = $conn->prepare("SELECT nombre_empleado FROM empleados WHERE codigo_empleado = ?");
        if (!$stmt) {
            echo json_encode(['ok' => false, 'msg' => 'Error preparando consulta: ' . $conn->error]);
            exit;
        }
        
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $stmt->bind_result($nombre);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found) {
            echo json_encode(['ok' => false, 'msg' => "Código '$codigo' no reconocido"]);
            exit;
        }

        // Verificar alarma
        $stmt = $conn->prepare("SELECT id, fecha_disparo, nombre_estacion, intervalo_minutos, mensaje
             FROM alarma_programadas
             WHERE id = ? AND ip_destino = ? AND activa = 1 AND confirmada = 0");
        $stmt->bind_param("is", $alarma_id, $ip);
        $stmt->execute();
        $res = $stmt->get_result();
        $alarm = $res->fetch_assoc();
        $stmt->close();

        if (!$alarm) {
            echo json_encode(['ok' => false, 'msg' => 'Alarma no válida o ya confirmada']);
            exit;
        }

        $turno = calcularTurno();
        $ahora = date('Y-m-d H:i:s');
        $segundos = max(0, (int)(strtotime($ahora) - strtotime($alarm['fecha_disparo'])));

        // Actualizar alarma actual como confirmada
        $stmt = $conn->prepare("UPDATE alarma_programadas
             SET confirmada = 1, fecha_confirmacion = ?, confirmada_por_codigo = ?,
                 confirmada_por_nombre = ?, segundos_respuesta = ?
             WHERE id = ?");
        $stmt->bind_param("sssii", $ahora, $codigo, $nombre, $segundos, $alarma_id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            echo json_encode(['ok' => false, 'msg' => 'Error al guardar confirmación']);
            exit;
        }

        // Registrar en historial
        $stmt = $conn->prepare("INSERT INTO alarma_registros
             (alarma_id, codigo_empleado, nombre_empleado, ip_estacion, nombre_estacion,
              fecha_alarma, fecha_confirmacion, turno, segundos_respuesta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssi", $alarma_id, $codigo, $nombre, $ip,
            $alarm['nombre_estacion'], $alarm['fecha_disparo'], $ahora, $turno, $segundos);
        $stmt->execute();
        $stmt->close();

        // =====================================================
        // REPROGRAMACIÓN AUTOMÁTICA para alarmas recurrentes
        // =====================================================
        if ($alarm['intervalo_minutos'] > 0) {
            $nueva_fecha = date('Y-m-d H:i:s', strtotime($alarm['fecha_disparo'] . ' + ' . $alarm['intervalo_minutos'] . ' minutes'));
            
            // Verificar si ya existe una alarma futura similar para evitar duplicados
            $checkStmt = $conn->prepare(
                "SELECT id FROM alarma_programadas 
                 WHERE ip_destino = ? 
                   AND activa = 1 
                   AND confirmada = 0 
                   AND fecha_disparo >= NOW()
                   AND ABS(TIMESTAMPDIFF(SECOND, fecha_disparo, ?)) < 60
                 LIMIT 1"
            );
            $checkStmt->bind_param("ss", $ip, $nueva_fecha);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows === 0) {
                // Crear nueva ocurrencia recurrente
                $insertStmt = $conn->prepare(
                    "INSERT INTO alarma_programadas 
                     (ip_destino, nombre_estacion, programada_por, fecha_disparo, 
                      intervalo_minutos, mensaje, activa, alarma_origen_id)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?)"
                );
                
                $programada_por = 'SISTEMA_RECURRENTE';
                $intervalo = $alarm['intervalo_minutos'];
                $mensaje = $alarm['mensaje'] ?? 'Realizar prueba de calidad';
                
                $insertStmt->bind_param(
                    "ssssisi", 
                    $ip, 
                    $alarm['nombre_estacion'],
                    $programada_por,
                    $nueva_fecha,
                    $intervalo,
                    $mensaje,
                    $alarma_id
                );
                
                $insertStmt->execute();
                $insertStmt->close();
                
                error_log("Alarma recurrente reprogramada: ID original {$alarma_id} -> Nueva fecha {$nueva_fecha}");
            }
            $checkStmt->close();
        }

        echo json_encode([
            'ok' => true,
            'nombre' => $nombre,
            'turno' => $turno,
            'hora' => date('H:i:s'),
            'msg' => "✅ Revisión confirmada por $nombre ($turno)",
        ]);
        break;

    case 'verificar_empleado':
        $codigo = trim(strtoupper($_POST['codigo_empleado'] ?? ''));
        if (!$codigo) {
            echo json_encode(['ok' => false, 'msg' => 'Código requerido']);
            exit;
        }
        $stmt = $conn->prepare("SELECT nombre_empleado FROM empleados WHERE codigo_empleado = ?");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();
        $stmt->bind_result($nombre);
        $found = $stmt->fetch();
        $stmt->close();
        echo json_encode(['ok' => true, 'existe' => $found, 'nombre' => $nombre ?? null]);
        break;

    case 'get_estaciones':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }
        $res = $conn->query("SELECT * FROM alarma_estaciones WHERE activa = 1 ORDER BY nombre");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['ok' => true, 'estaciones' => $rows]);
        break;

    case 'guardar_estacion':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $nom = trim($_POST['nombre'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        $desc = trim($_POST['descripcion'] ?? '');

        if (!$nom || !$ip) {
            echo json_encode(['ok' => false, 'msg' => 'Nombre e IP son requeridos']);
            exit;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'msg' => 'IP no válida']);
            exit;
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE alarma_estaciones SET nombre=?, ip=?, descripcion=? WHERE id=?");
            $stmt->bind_param("sssi", $nom, $ip, $desc, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO alarma_estaciones (nombre, ip, descripcion, activa) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $nom, $ip, $desc);
        }
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Estación guardada' : 'Error al guardar']);
        break;

    case 'eliminar_estacion':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE alarma_estaciones SET activa=0 WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok]);
        break;

    case 'programar_alarma':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }

        $ip_destino = trim($_POST['ip_destino'] ?? '');
        $nom_estacion = trim($_POST['nombre_estacion'] ?? '');
        $fecha_disparo = trim($_POST['fecha_disparo'] ?? '');
        $intervalo = max(0, (int)($_POST['intervalo_minutos'] ?? 0));
        $mensaje = trim($_POST['mensaje'] ?? 'Realizar prueba de calidad');
        $codigo_admin = $_SESSION['codigo_empleado'] ?? '';

        if (!$ip_destino || !$fecha_disparo) {
            echo json_encode(['ok' => false, 'msg' => 'IP y fecha/hora son requeridos']);
            exit;
        }
        if (!filter_var($ip_destino, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'msg' => 'IP no válida']);
            exit;
        }

        $stmt = $conn->prepare(
            "INSERT INTO alarma_programadas
             (ip_destino, nombre_estacion, programada_por, fecha_disparo, intervalo_minutos, mensaje, activa)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->bind_param("ssssis", $ip_destino, $nom_estacion, $codigo_admin, $fecha_disparo, $intervalo, $mensaje);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Alarma programada correctamente' : 'Error al programar']);
        break;

    case 'cancelar_alarma':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE alarma_programadas SET activa=0 WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok]);
        break;

    case 'get_alarmas_admin':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }

        $stmt = $conn->prepare(
            "SELECT id, ip_destino, nombre_estacion,
                    DATE_FORMAT(fecha_disparo,'%d/%m/%Y %H:%i') AS disparo,
                    intervalo_minutos, mensaje, activa, confirmada,
                    DATE_FORMAT(fecha_confirmacion,'%H:%i:%s') AS hora_conf,
                    confirmada_por_nombre, segundos_respuesta,
                    alarma_origen_id
             FROM alarma_programadas
             WHERE fecha_disparo >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY fecha_disparo DESC
             LIMIT 200"
        );
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        echo json_encode(['ok' => true, 'alarmas' => $rows]);
        break;

    case 'get_stats':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }

        $total = (int)($conn->query("SELECT COUNT(*) c FROM alarma_registros")->fetch_assoc()['c'] ?? 0);
        $hoy = (int)($conn->query("SELECT COUNT(*) c FROM alarma_registros WHERE DATE(fecha_alarma)=CURDATE()")->fetch_assoc()['c'] ?? 0);
        $semana = (int)($conn->query("SELECT COUNT(*) c FROM alarma_registros WHERE YEARWEEK(fecha_alarma)=YEARWEEK(CURDATE())")->fetch_assoc()['c'] ?? 0);
        $mes = (int)($conn->query("SELECT COUNT(*) c FROM alarma_registros WHERE MONTH(fecha_alarma)=MONTH(CURDATE()) AND YEAR(fecha_alarma)=YEAR(CURDATE())")->fetch_assoc()['c'] ?? 0);
        $pend = (int)($conn->query("SELECT COUNT(*) c FROM alarma_programadas WHERE activa=1 AND confirmada=0 AND fecha_disparo > NOW()")->fetch_assoc()['c'] ?? 0);
        $avg_row = $conn->query("SELECT AVG(segundos_respuesta) a, MIN(segundos_respuesta) m FROM alarma_registros WHERE segundos_respuesta IS NOT NULL")->fetch_assoc();

        echo json_encode([
            'ok' => true,
            'total' => $total,
            'hoy' => $hoy,
            'semana' => $semana,
            'mes' => $mes,
            'pendientes' => $pend,
            'promedio_segundos' => (int)round($avg_row['a'] ?? 0),
            'respuesta_rapida' => (int)($avg_row['m'] ?? 0),
        ]);
        break;

    case 'get_registros_admin':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }

        $fecha = trim($_POST['fecha'] ?? '');
        $empleado = trim($_POST['empleado'] ?? '');
        $turno = trim($_POST['turno'] ?? '');

        $sql = "SELECT r.codigo_empleado, r.nombre_empleado, r.ip_estacion,
                       r.nombre_estacion, r.turno,
                       DATE_FORMAT(r.fecha_alarma,'%d/%m/%Y %H:%i:%s') AS fecha_alarma,
                       DATE_FORMAT(r.fecha_confirmacion,'%H:%i:%s') AS hora_confirmacion,
                       r.segundos_respuesta
                FROM alarma_registros r WHERE 1=1";
        $params = [];
        $types = '';

        if ($fecha) {
            $sql .= " AND DATE(r.fecha_alarma) = ?";
            $params[] = $fecha;
            $types .= 's';
        }
        if ($empleado) {
            $sql .= " AND (r.codigo_empleado LIKE ? OR r.nombre_empleado LIKE ?)";
            $like = "%$empleado%";
            $params[] = $like;
            $params[] = $like;
            $types .= 'ss';
        }
        if ($turno) {
            $sql .= " AND r.turno = ?";
            $params[] = $turno;
            $types .= 's';
        }
        $sql .= " ORDER BY r.fecha_alarma DESC LIMIT 500";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        echo json_encode(['ok' => true, 'registros' => $rows]);
        break;

    case 'limpiar_recurrentes_vencidas':
        if (empty($_SESSION['rol']) || $_SESSION['rol'] !== 'administrador') {
            echo json_encode(['ok' => false, 'msg' => 'Sin permisos']);
            exit;
        }
        
        $stmt = $conn->prepare(
            "UPDATE alarma_programadas 
             SET activa = 0 
             WHERE intervalo_minutos > 0 
               AND activa = 1 
               AND confirmada = 0 
               AND fecha_disparo < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute();
        $afectadas = $stmt->affected_rows;
        $stmt->close();
        
        echo json_encode([
            'ok' => true, 
            'limpiadas' => $afectadas,
            'msg' => "Se limpiaron $afectadas alarmas recurrentes vencidas"
        ]);
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Acción desconocida: ' . $accion]);
        break;
}
?>