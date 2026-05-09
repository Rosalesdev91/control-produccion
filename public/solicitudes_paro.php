<?php
session_start();
require_once '../config/database.php';
require_once '../includes/pdf_generator.php';
require_once 'auto_audit_empleados.php';
require_once 'registrar_actividad.php';

// Verificar sesión de técnico
if (!isset($_SESSION['es_tecnico']) || !$_SESSION['es_tecnico']) {
    header('Location: login_paros.php');
    exit;
}

$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Costa_Rica');

// ==================== FILTROS ====================
$fecha_desde        = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta        = $_GET['fecha_hasta'] ?? date('Y-m-d');
$area_filtro        = $_GET['area'] ?? '';
$estado_filtro      = $_GET['estado'] ?? '';
$empleado_filtro    = $_GET['empleado'] ?? '';
$tipo_paro_filtro   = $_GET['tipo_paro'] ?? '';

// ==================== ACCIONES POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------- INICIAR PARO ----------
    if (isset($_POST['iniciar_paro'])) {
        $id_solicitud = (int)$_POST['id_solicitud'];
        if ($id_solicitud > 0) {
            $stmt = $conn->prepare("SELECT empleado, area, equipo, motivo, fecha_solicitud, tipo_paro 
                                     FROM solicitudes_paro WHERE id = ? AND estado = 'pendiente'");
            $stmt->bind_param("i", $id_solicitud);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($sol = $res->fetch_assoc()) {
                $fecha_inicio = date('Y-m-d H:i:s');
                $id_tecnico   = $_SESSION['id_tecnico'];

                $tipo_paro = null;
                if (!empty($sol['tipo_paro'])) {
                    $check = $conn->prepare("SELECT nombre FROM tipos_paro WHERE nombre = ?");
                    $check->bind_param("s", $sol['tipo_paro']);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        $tipo_paro = $sol['tipo_paro'];
                    }
                    $check->close();
                }

                $ins = $conn->prepare("INSERT INTO paro_produccion 
                    (id_solicitud, empleado, area, equipo, motivo, fecha_solicitud, fecha_inicio, activo, tipo_paro, id_tecnico, estado_paro)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'en_progreso')");
                $ins->bind_param("isssssssi",
                    $id_solicitud, $sol['empleado'], $sol['area'], $sol['equipo'],
                    $sol['motivo'], $sol['fecha_solicitud'], $fecha_inicio, $tipo_paro, $id_tecnico);

                if ($ins->execute()) {
                    $id_paro = $conn->insert_id;
                    $upd = $conn->prepare("UPDATE solicitudes_paro SET estado = 'iniciada', id_paro = ? WHERE id = ?");
                    $upd->bind_param("ii", $id_paro, $id_solicitud);
                    $upd->execute();
                    $_SESSION['mensaje_exito'] = "Paro iniciado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al iniciar: " . $conn->error;
                }
                $ins->close();
            } else {
                $_SESSION['mensaje_error'] = "Solicitud no encontrada o ya procesada.";
            }
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }

    // ---------- PAUSAR PARO ----------
    if (isset($_POST['pausar_paro'])) {
        $id_solicitud = (int)$_POST['id_solicitud'];
        $comentario_pausa = trim($_POST['comentario_pausa'] ?? '');
        
        if ($id_solicitud > 0 && $comentario_pausa !== '') {
            // Verificar que el paro esté en progreso
            $stmt = $conn->prepare("SELECT pp.id FROM paro_produccion pp 
                                    INNER JOIN solicitudes_paro sp ON pp.id = sp.id_paro 
                                    WHERE sp.id = ? AND pp.estado_paro = 'en_progreso' AND pp.activo = 1");
            $stmt->bind_param("i", $id_solicitud);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $id_paro = $row['id'];
                
                // Registrar la pausa
                $stmt2 = $conn->prepare("INSERT INTO pausas_paro 
                                        (id_paro, id_tecnico, fecha_pausa, comentario) 
                                        VALUES (?, ?, NOW(), ?)");
                $stmt2->bind_param("iis", $id_paro, $_SESSION['id_tecnico'], $comentario_pausa);
                
                if ($stmt2->execute()) {
                    $id_pausa = $conn->insert_id;
                    
                    // Actualizar estado del paro
                    $stmt3 = $conn->prepare("UPDATE paro_produccion 
                                            SET estado_paro = 'pausado', ultima_pausa_id = ? 
                                            WHERE id = ?");
                    $stmt3->bind_param("ii", $id_pausa, $id_paro);
                    
                    if ($stmt3->execute()) {
                        $_SESSION['mensaje_exito'] = "Paro pausado correctamente.";
                    } else {
                        $_SESSION['mensaje_error'] = "Error al actualizar estado del paro.";
                    }
                    $stmt3->close();
                } else {
                    $_SESSION['mensaje_error'] = "Error al registrar la pausa.";
                }
                $stmt2->close();
            } else {
                $_SESSION['mensaje_error'] = "Paro no encontrado o no está en progreso.";
            }
            $stmt->close();
        } else {
            $_SESSION['mensaje_error'] = "Comentario requerido para pausar.";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }

    // ---------- REANUDAR PARO ----------
    if (isset($_POST['reanudar_paro'])) {
        $id_solicitud = (int)$_POST['id_solicitud'];
        
        if ($id_solicitud > 0) {
            // Verificar que el paro esté pausado
            $stmt = $conn->prepare("SELECT pp.id, pp.ultima_pausa_id FROM paro_produccion pp 
                                    INNER JOIN solicitudes_paro sp ON pp.id = sp.id_paro 
                                    WHERE sp.id = ? AND pp.estado_paro = 'pausado' AND pp.activo = 1");
            $stmt->bind_param("i", $id_solicitud);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $id_paro = $row['id'];
                $ultima_pausa_id = $row['ultima_pausa_id'];
                
                // Actualizar fecha de reanudación en la última pausa
                if ($ultima_pausa_id) {
                    $stmt2 = $conn->prepare("UPDATE pausas_paro 
                                            SET fecha_reanudacion = NOW() 
                                            WHERE id = ?");
                    $stmt2->bind_param("i", $ultima_pausa_id);
                    $stmt2->execute();
                    $stmt2->close();
                }
                
                // Actualizar estado del paro
                $stmt3 = $conn->prepare("UPDATE paro_produccion 
                                        SET estado_paro = 'en_progreso', ultima_pausa_id = NULL 
                                        WHERE id = ?");
                $stmt3->bind_param("i", $id_paro);
                
                if ($stmt3->execute()) {
                    $_SESSION['mensaje_exito'] = "Paro reanudado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al reanudar el paro.";
                }
                $stmt3->close();
            } else {
                $_SESSION['mensaje_error'] = "Paro no encontrado o no está pausado.";
            }
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }

    // ---------- RECHAZAR (SOLO DESPUÉS DE INICIAR) ----------
    if (isset($_POST['rechazar_solicitud'])) {
        $id_solicitud = (int)$_POST['id_solicitud'];
        $motivo       = trim($_POST['motivo_rechazo'] ?? '');
        $no_aplica    = trim($_POST['tipo_no_aplica'] ?? '');
        if ($id_solicitud > 0 && $motivo !== '') {
            if ($no_aplica !== '') $motivo .= " - Tipo No Aplica: $no_aplica";

            $stmt = $conn->prepare("UPDATE solicitudes_paro SET estado = 'rechazada', motivo_rechazo = ?, fecha_rechazo = NOW() WHERE id = ? AND estado = 'iniciada'");
            $stmt->bind_param("si", $motivo, $id_solicitud);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Desactivar el paro en la tabla paro_produccion
                $conn->query("UPDATE paro_produccion SET activo = 0, estado_paro = 'finalizado' WHERE id_solicitud = " . (int)$id_solicitud);
                $_SESSION['mensaje_exito'] = "Paro rechazado después de iniciado.";
            } else {
                $_SESSION['mensaje_error'] = "No se pudo rechazar (¿ya fue finalizado o no está iniciado por ti?).";
            }
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }

    // ---------- FINALIZAR ----------
    if (isset($_POST['finalizar_paro'])) {
        $id_solicitud = (int)$_POST['id_solicitud'];
        $comentario   = trim($_POST['comentario_final'] ?? '');
        if ($id_solicitud > 0 && $comentario !== '') {
            $stmt = $conn->prepare("SELECT id_paro FROM solicitudes_paro WHERE id = ? AND estado = 'iniciada'");
            $stmt->bind_param("i", $id_solicitud);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $id_paro = $row['id_paro'];
                // QUITAR la validación de id_tecnico para permitir que cualquier técnico finalice
                $upd = $conn->prepare("UPDATE paro_produccion SET fecha_fin = NOW(), comentario_final = ?, activo = 0, estado_paro = 'finalizado' WHERE id = ?");
                $upd->bind_param("si", $comentario, $id_paro);
                if ($upd->execute()) {
                    $upd2 = $conn->prepare("UPDATE solicitudes_paro SET estado = 'finalizada' WHERE id = ?");
                    $upd2->bind_param("i", $id_solicitud);
                    $upd2->execute();
                    $upd2->close();

                    // === OBTENER DATOS PARA PDF ===
                    $stmt_datos = $conn->prepare("
                        SELECT sp.*, pp.fecha_inicio, pp.fecha_fin, pp.comentario_final, t.nombre_tecnico 
                        FROM solicitudes_paro sp 
                        LEFT JOIN paro_produccion pp ON sp.id_paro = pp.id 
                        LEFT JOIN tecnicos t ON pp.id_tecnico = t.id 
                        WHERE sp.id = ?
                    ");
                    $stmt_datos->bind_param("i", $id_solicitud);
                    $stmt_datos->execute();
                    $datos_solicitud = $stmt_datos->get_result()->fetch_assoc();
                    $stmt_datos->close();

                    // === GENERAR PDF ===
                    require_once '../includes/pdf_generator.php';
                    $pdf_contenido = generarPDFSolicitudString($datos_solicitud);

                    if ($pdf_contenido) {
                        $nombre_limpio = preg_replace('/[^a-zA-Z0-9\s]/', '', $datos_solicitud['empleado'] ?? 'Tecnico');
                        $nombre_pdf = sprintf('Paro_Finalizado_%03d_%s_%s.pdf', $id_solicitud, str_replace(' ', '_', $nombre_limpio), date('Y-m-d_His'));
                        $ruta_temporal = sys_get_temp_dir() . '/' . $nombre_pdf;
                        file_put_contents($ruta_temporal, $pdf_contenido);

$duracion_min = round((strtotime($datos_solicitud['fecha_fin']) - strtotime($datos_solicitud['fecha_inicio'])) / 60);

// Mensaje con iconos
$mensaje = "🔧 *PARO DE PRODUCCIÓN FINALIZADO*\n\n" .
           "🆔 *ID Solicitud:* #{$id_solicitud}\n" .
           "👤 *Empleado:* {$datos_solicitud['empleado']}\n" .
           "🏭 *Área:* {$datos_solicitud['area']}\n" .
           "⚙️ *Equipo:* {$datos_solicitud['equipo']}\n" .
           "🛠️ *Técnico:* " . ($datos_solicitud['nombre_tecnico'] ?? 'No asignado') . "\n" .
           "⏱️ *Duración:* {$duracion_min} minutos\n" .
           "💬 *Comentario:* " . substr($comentario, 0, 100) . (strlen($comentario) > 100 ? '...' : '') . "\n\n" .
           "📄 *Reporte completo adjunto*";

require_once '../whatsapp-bot/whatsapp.php';

// Enviar a 2 números
$numeros_destino = [
    "50672360749",
    "50660496788"
];

foreach ($numeros_destino as $numero) {
    enviarWhatsAppPDF($numero, $mensaje, $ruta_temporal);
}

// Eliminar archivo temporal
@unlink($ruta_temporal);


                        $_SESSION['mensaje_exito'] = $enviado 
                            ? "Paro finalizado correctamente. Reporte enviado por WhatsApp." 
                            : "Paro finalizado correctamente. Error enviando WhatsApp.";
                    } else {
                        $_SESSION['mensaje_exito'] = "Paro finalizado correctamente. Error generando PDF.";
                    }
                } else {
                    $_SESSION['mensaje_error'] = "Error al finalizar el paro.";
                }
                $upd->close();
            }
            $stmt->close();
        } else {
            $_SESSION['mensaje_error'] = "Comentario requerido para finalizar.";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }

    // ---------- GENERAR PDF ----------
    if (isset($_POST['generar_pdf'])) {
        $id_solicitud = (int)$_POST['id_solicitud'];
        if ($id_solicitud > 0) {
            $stmt = $conn->prepare("SELECT sp.*, pp.fecha_inicio, pp.fecha_fin, pp.comentario_final, t.nombre_tecnico 
                                     FROM solicitudes_paro sp 
                                     LEFT JOIN paro_produccion pp ON sp.id_paro = pp.id 
                                     LEFT JOIN tecnicos t ON pp.id_tecnico = t.id WHERE sp.id = ?");
            $stmt->bind_param("i", $id_solicitud);
            $stmt->execute();
            if ($sol = $stmt->get_result()->fetch_assoc()) {
                generarPDFSolicitud($sol);
                exit;
            }
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    }
}

// ==================== LISTAS PARA FILTROS ====================
$ESTADOS = ['pendiente'=>'Pendiente', 'iniciada'=>'Iniciada', 'finalizada'=>'Finalizada', 'rechazada'=>'Rechazada'];
$AREAS = $TIPOS_PARO = $TIPOS_NO_APLICA = $EMPLEADOS = [];

$res = $conn->query("SELECT DISTINCT area FROM areas ORDER BY area");
while ($r = $res->fetch_assoc()) $AREAS[] = $r['area'];

$res = $conn->query("SELECT nombre FROM tipos_paro ORDER BY nombre");
while ($r = $res->fetch_assoc()) $TIPOS_PARO[] = $r['nombre'];

$res = $conn->query("SELECT nombre FROM tipos_no_aplica ORDER BY nombre");
while ($r = $res->fetch_assoc()) $TIPOS_NO_APLICA[] = $r['nombre'];

$res = $conn->query("SELECT DISTINCT empleado FROM solicitudes_paro WHERE empleado IS NOT NULL ORDER BY empleado");
while ($r = $res->fetch_assoc()) $EMPLEADOS[] = $r['empleado'];

// ==================== CONSULTA PRINCIPAL ====================
$query = "
    SELECT sp.id, sp.empleado, sp.area, sp.equipo, sp.motivo, sp.fecha_solicitud, sp.estado, sp.motivo_rechazo,
           sp.tipo_paro AS nombre_tipo_paro, pp.fecha_inicio, pp.fecha_fin, pp.comentario_final, t.nombre_tecnico, 
           pp.id_tecnico, pp.estado_paro,
           CASE WHEN sp.estado = 'pendiente' THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, NOW())
                WHEN sp.estado IN ('iniciada','finalizada') AND pp.fecha_inicio IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, pp.fecha_inicio)
                WHEN sp.estado = 'rechazada' THEN TIMESTAMPDIFF(MINUTE, sp.fecha_solicitud, sp.fecha_rechazo) END AS tiempo_espera_min,
           CASE 
                WHEN pp.estado_paro = 'en_progreso' THEN 
                    TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, NOW()) - 
                    COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE, fecha_pausa, COALESCE(fecha_reanudacion, NOW()))) 
                              FROM pausas_paro WHERE id_paro = pp.id), 0)
                WHEN pp.fecha_inicio IS NOT NULL AND pp.fecha_fin IS NOT NULL THEN 
                    TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, pp.fecha_fin) -
                    COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE, fecha_pausa, COALESCE(fecha_reanudacion, fecha_fin))) 
                              FROM pausas_paro WHERE id_paro = pp.id), 0)
                WHEN pp.fecha_inicio IS NOT NULL AND sp.estado = 'iniciada' THEN 
                    TIMESTAMPDIFF(MINUTE, pp.fecha_inicio, NOW()) -
                    COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE, fecha_pausa, COALESCE(fecha_reanudacion, NOW()))) 
                              FROM pausas_paro WHERE id_paro = pp.id), 0)
                ELSE NULL 
           END AS duracion_min,
           COALESCE((SELECT SUM(TIMESTAMPDIFF(MINUTE, fecha_pausa, COALESCE(fecha_reanudacion, NOW()))) 
                     FROM pausas_paro WHERE id_paro = pp.id AND fecha_reanudacion IS NULL), 0) AS tiempo_pausado_min,
           (SELECT comentario FROM pausas_paro WHERE id_paro = pp.id AND fecha_reanudacion IS NULL ORDER BY id DESC LIMIT 1) AS ultimo_comentario_pausa
    FROM solicitudes_paro sp
    LEFT JOIN paro_produccion pp ON sp.id_paro = pp.id
    LEFT JOIN tecnicos t ON pp.id_tecnico = t.id
    WHERE DATE(sp.fecha_solicitud) BETWEEN ? AND ?
    AND sp.tipo_paro != 'Sin WIP'
";

$params = [$fecha_desde, $fecha_hasta];
$types  = "ss";
if ($area_filtro)      { $query .= " AND sp.area = ?";        $params[] = $area_filtro;      $types .= "s"; }
if ($estado_filtro)    { $query .= " AND sp.estado = ?";      $params[] = $estado_filtro;    $types .= "s"; }
if ($empleado_filtro)  { $query .= " AND sp.empleado = ?";    $params[] = $empleado_filtro;  $types .= "s"; }
if ($tipo_paro_filtro) { $query .= " AND sp.tipo_paro = ?";   $params[] = $tipo_paro_filtro; $types .= "s"; }

$query .= " ORDER BY 
    CASE WHEN sp.estado = 'pendiente' THEN 1 
         WHEN sp.estado = 'iniciada' THEN 2 
         WHEN sp.estado = 'finalizada' THEN 3 ELSE 4 END, sp.fecha_solicitud DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$solicitudes = [];
while ($row = $result->fetch_assoc()) {
    $row['tiempo_espera_formateado'] = $row['tiempo_espera_min'] !== null ? formatearTiempo($row['tiempo_espera_min']) : '-';
    
    // Calcular duración neta (sin tiempos de pausa)
    if ($row['duracion_min'] !== null) {
        $row['duracion_formateada'] = formatearTiempo($row['duracion_min']);
        // Mostrar tiempo pausado si existe
        if ($row['tiempo_pausado_min'] > 0) {
            $row['duracion_formateada'] .= ' <small style="color:#ff8800;">(P:' . formatearTiempo($row['tiempo_pausado_min']) . ')</small>';
        }
    } else {
        $row['duracion_formateada'] = '-';
    }
    
    $row['fecha_solicitud_fmt'] = date('d/m/Y H:i', strtotime($row['fecha_solicitud']));
    $row['fecha_inicio_fmt']    = $row['fecha_inicio'] ? date('d/m/Y H:i', strtotime($row['fecha_inicio'])) : '-';
    $row['fecha_fin_fmt']       = $row['fecha_fin'] ? date('d/m/Y H:i', strtotime($row['fecha_fin'])) : '-';
    $solicitudes[] = $row;
}
$stmt->close();

function formatearTiempo($minutos) {
    if (is_null($minutos)) return '-';
    $horas = floor($minutos / 60);
    $mins  = $minutos % 60;
    return $horas > 0 ? "$horas h $mins min" : "$mins min";
}

// Contadores
$pending_count = $conn->query("SELECT COUNT(*) FROM solicitudes_paro WHERE estado='pendiente'")->fetch_row()[0];
$id_tecnico = $_SESSION['id_tecnico'];
$stats = $conn->query("
    SELECT 
        COUNT(CASE WHEN sp.estado = 'pendiente' THEN 1 END) AS pendientes,
        COUNT(CASE WHEN sp.estado = 'iniciada' AND pp.id_tecnico = $id_tecnico AND pp.estado_paro = 'en_progreso' THEN 1 END) AS en_progreso,
        COUNT(CASE WHEN sp.estado = 'iniciada' AND pp.id_tecnico = $id_tecnico AND pp.estado_paro = 'pausado' THEN 1 END) AS pausados,
        COUNT(CASE WHEN sp.estado = 'finalizada' AND pp.id_tecnico = $id_tecnico AND DATE(pp.fecha_fin) = CURDATE() THEN 1 END) AS finalizadas_hoy
    FROM solicitudes_paro sp LEFT JOIN paro_produccion pp ON sp.id_paro = pp.id
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tablero Técnico - Control de Paros</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root{--p:#007bff;--s:#28a745;--d:#dc3545;--w:#ffc107;--g:#6c757d;--l:#f8f9fa;--b:#dee2e6;--r:12px;--sh:0 4px 15px rgba(0,0,0,.1);}
        body{font-family:Segoe UI,sans-serif;background:linear-gradient(135deg,#f5f7fa,#c3cfe2);margin:0;display:flex;flex-direction:column;min-height:100vh;color:#333;}
        .header{background:var(--p);color:#fff;padding:15px 0;box-shadow:0 4px 10px rgba(0,0,0,.2);}
        .header-content{max-width:1300px;margin:auto;display:flex;justify-content:space-between;align-items:center;padding:0 20px;}
        .main{max-width:1300px;margin:20px auto;padding:0 15px;flex:1;}
        .alert{padding:15px;border-radius:8px;margin-bottom:20px;}
        .alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
        .alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
        .alert-pending{background:#fff3cd;color:#856404;border-left:5px solid var(--w);animation:pulse 2s infinite;}
        @keyframes pulse{0%,100%{transform:scale(1);}50%{transform:scale(1.02);}}
        .card{background:#fff;border-radius:var(--r);box-shadow:var(--sh);margin-bottom:25px;overflow:hidden;}
        .card-header{background:#f8f9fa;padding:15px 20px;font-weight:600;display:flex;justify-content:space-between;align-items:center;}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:25px;}
        .stat{background:#fff;padding:20px;text-align:center;border-radius:var(--r);box-shadow:var(--sh);}
        .stat-number{font-size:2.2em;font-weight:700;color:var(--p);}
        .stat.pausado .stat-number{color:#ff8800;}
        .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;padding:20px;}
        .form-control{width:100%;padding:10px;border:1px solid var(--b);border-radius:8px;}
        .btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;margin:2px;font-size:13px;}
        .btn-success{background:var(--s);color:#fff;}
        .btn-danger{background:var(--d);color:#fff;}
        .btn-secondary{background:var(--g);color:#fff;}
        .btn-warning{background:var(--w);color:#856404;}
        .btn-info{background:#17a2b8;color:#fff;}
        .btn-sm{padding:6px 12px;font-size:12px;}
        .table{width:100%;border-collapse:collapse;font-size:14px;}
        .table th,.table td{padding:10px;border-bottom:1px solid var(--b);text-align:left;}
        .table th{background:#f8f9fa;position:sticky;top:0;}
        .badge{padding:4px 8px;border-radius:8px;font-size:12px;font-weight:600;}
        .badge-warning{background:var(--w);color:#856404;}
        .badge-success{background:var(--s);color:#fff;}
        .badge-danger{background:var(--d);color:#fff;}
        .badge-secondary{background:var(--g);color:#fff;}
        .badge-rojo{background:#ff4444;color:#fff;}
        .badge-pausado{background:#ff8800;color:#fff;}
        .badge-info{background:#17a2b8;color:#fff;}
        .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:1000;}
        .modal-content{background:#fff;padding:25px;border-radius:var(--r);max-width:500px;width:90%;}
        .footer{background:var(--p);color:#fff;text-align:center;padding:15px;margin-top:auto;}
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div><img src="/control_produccion/public/logo.png" height="50" alt="Logo"></div>
            <div style="display:flex;gap:20px;align-items:center;">
                <div style="background:rgba(255,255,255,.15);padding:8px 15px;border-radius:8px;">
                    Técnico: <?=htmlspecialchars($_SESSION['nombre_tecnico'] ?? 'Sin nombre')?>
                </div>
                <div class="clock" id="reloj"></div>
                <a href="login_paros.php?logout=1" class="btn btn-danger" style="text-decoration:none;">Cerrar Sesión</a>
            </div>
        </div>
    </header>

    <div class="main">
        <?php if($pending_count > 0): ?>
            <div class="alert alert-pending">¡ATENCIÓN! Hay <?=$pending_count?> solicitud(es) pendiente(s) de atención inmediata.</div>
        <?php endif; ?>

        <?php if(!empty($_SESSION['mensaje_exito'])): ?>
            <div class="alert alert-success"><?=$_SESSION['mensaje_exito']?></div>
            <?php unset($_SESSION['mensaje_exito']); ?>
        <?php endif; ?>

        <?php if(!empty($_SESSION['mensaje_error'])): ?>
            <div class="alert alert-error"><?=$_SESSION['mensaje_error']?></div>
            <?php unset($_SESSION['mensaje_error']); ?>
        <?php endif; ?>

        <div class="stats">
            <div class="stat"><div class="stat-number"><?=$stats['pendientes']??0?></div><div>Pendientes</div></div>
            <div class="stat"><div class="stat-number"><?=$stats['en_progreso']??0?></div><div>Mis Paros Activos</div></div>
            <div class="stat pausado"><div class="stat-number"><?=$stats['pausados']??0?></div><div>Paros Pausados</div></div>
            <div class="stat"><div class="stat-number"><?=$stats['finalizadas_hoy']??0?></div><div>Finalizadas Hoy</div></div>
        </div>

        <div class="card">
            <div class="card-header">Filtros de Búsqueda</div>
            <form method="GET">
                <div class="filters">
                    <div><label>Desde</label><input type="date" name="fecha_desde" class="form-control" value="<?=$fecha_desde?>" required></div>
                    <div><label>Hasta</label><input type="date" name="fecha_hasta" class="form-control" value="<?=$fecha_hasta?>" required></div>
                    <div><label>Estado</label>
                        <select name="estado" class="form-control">
                            <option value="">Todos los estados</option>
                            <?php foreach($ESTADOS as $k=>$v): ?>
                                <option value="<?=$k?>" <?=($estado_filtro==$k?'selected':'')?>><?=$v?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Tipo de Paro</label>
                        <select name="tipo_paro" class="form-control">
                            <option value="">Todos</option>
                            <?php foreach($TIPOS_PARO as $t): ?>
                                <option <?=($tipo_paro_filtro==$t?'selected':'')?>><?=htmlspecialchars($t)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="padding:20px;text-align:right;background:#f8f9fa;">
                    <button type="submit" class="btn btn-success">Aplicar Filtros</button>
                    <a href="solicitudes_paro.php" class="btn btn-secondary">Limpiar Todo</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">Solicitudes de Paro <span><?=count($solicitudes)?> registro(s)</span></div>
            <div style="overflow-x:auto;padding:10px;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th><th>Fecha Solicitud</th><th>Empleado</th><th>Área</th><th>Equipo</th><th>Tipo Paro</th>
                            <th>Motivo</th><th>Estado</th><th>Tiempo Espera</th><th>Duración Paro</th><th>Técnico</th><th>Comentario Final</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($solicitudes)): ?>
                            <tr><td colspan="13" style="text-align:center;padding:50px;color:#999;">No se encontraron solicitudes.</td></tr>
                        <?php else: foreach($solicitudes as $s): ?>
                            <tr style="background:#<?= $s['estado']=='pendiente'?'fff8e1':($s['estado']=='iniciada'?($s['estado_paro']=='pausado'?'fff4e5':'e8f5e9'):'') ?>;">
                                <td><strong>#<?=$s['id']?></strong></td>
                                <td><?=$s['fecha_solicitud_fmt']?></td>
                                <td><?=htmlspecialchars($s['empleado'])?></td>
                                <td><?=htmlspecialchars($s['area'])?></td>
                                <td><?=htmlspecialchars($s['equipo'])?></td>
                                <td><small><?=htmlspecialchars($s['nombre_tipo_paro'] ?? 'Sin tipo')?></small></td>
                                <td title="<?=htmlspecialchars($s['motivo'])?>"><?=strlen($s['motivo'])>40 ? htmlspecialchars(substr($s['motivo'],0,40)).'...' : htmlspecialchars($s['motivo'])?></td>
                                <td>
                                    <?php if($s['estado'] == 'iniciada' && $s['estado_paro'] == 'pausado'): ?>
                                        <span class="badge badge-pausado">
                                            <i class="fas fa-pause"></i> Pausado
                                        </span>
                                        <?php if($s['ultimo_comentario_pausa']): ?>
                                            <br><small style="color:#ff8800;"><?=htmlspecialchars(substr($s['ultimo_comentario_pausa'],0,30))?>...</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-<?= $s['estado']=='pendiente'?'warning':($s['estado']=='iniciada'?'success':($s['estado']=='finalizada'?'secondary':'danger')) ?>">
                                            <?=ucfirst($s['estado'])?>
                                        </span>
                                        <?php if($s['estado']=='rechazada' && $s['motivo_rechazo']): ?>
                                            <br><small style="color:#dc3545;">Rechazado: <?=htmlspecialchars(substr($s['motivo_rechazo'],0,30))?>...</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php if($s['tiempo_espera_min'] !== null): ?>
                                    <span class="badge <?= $s['tiempo_espera_min'] > 30 ? 'badge-rojo' : 'badge-warning' ?>">
                                        <?=$s['tiempo_espera_formateado']?>
                                    </span>
                                <?php else: ?>-<?php endif; ?></td>
                                <td><?=$s['duracion_formateada'] !== '-' ? '<span class="badge badge-success">'.$s['duracion_formateada'].'</span>' : '-'?></td>
                                <td><?=htmlspecialchars($s['nombre_tecnico'] ?? '-')?></td>
                                <td><small><?=htmlspecialchars($s['comentario_final'] ?? '')?></small></td>
                                <td style="white-space:nowrap;">
                                    <?php if($s['estado'] === 'pendiente'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_solicitud" value="<?=$s['id']?>">
                                            <button type="submit" name="iniciar_paro" class="btn btn-success btn-sm" onclick="return confirm('¿Iniciar este paro ahora?')">Iniciar</button>
                                        </form>
                                    <?php elseif($s['estado'] === 'iniciada'): ?>
                                        <?php if($s['estado_paro'] == 'en_progreso'): ?>
                                            <!-- Cualquier técnico puede pausar -->
                                            <button onclick="mostrarPausa(<?=$s['id']?>)" class="btn btn-warning btn-sm">Pausar</button>
                                            <!-- Cualquier técnico puede finalizar -->
                                            <button onclick="mostrarFinalizar(<?=$s['id']?>)" class="btn btn-success btn-sm">Finalizar</button>
                                            <!-- Solo el técnico que inició puede rechazar -->
                                            <?php if($s['id_tecnico'] == $_SESSION['id_tecnico']): ?>
                                                <button onclick="mostrarRechazo(<?=$s['id']?>)" class="btn btn-danger btn-sm">Rechazar</button>
                                            <?php endif; ?>
                                        <?php elseif($s['estado_paro'] == 'pausado'): ?>
                                            <!-- Cualquier técnico puede reanudar -->
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="id_solicitud" value="<?=$s['id']?>">
                                                <button type="submit" name="reanudar_paro" class="btn btn-info btn-sm" onclick="return confirm('¿Reanudar este paro?')">Reanudar</button>
                                            </form>
                                            <!-- Cualquier técnico puede finalizar -->
                                            <button onclick="mostrarFinalizar(<?=$s['id']?>)" class="btn btn-success btn-sm">Finalizar</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id_solicitud" value="<?=$s['id']?>">
                                        <button type="submit" name="generar_pdf" class="btn btn-secondary btn-sm">PDF</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Pausar -->
        <div id="modalPausar" class="modal">
            <div class="modal-content">
                <h3><i class="fas fa-pause"></i> Pausar Paro</h3>
                <form method="POST">
                    <input type="hidden" name="id_solicitud" id="idPausar">
                    <p><textarea name="comentario_pausa" class="form-control" rows="4" placeholder="Explique por qué está pausando el paro (espera de repuestos, consulta con supervisor, etc.)..." required></textarea></p>
                    <p style="text-align:right;">
                        <button type="submit" name="pausar_paro" class="btn btn-warning">Confirmar Pausa</button>
                        <button type="button" onclick="cerrarPausar()" class="btn btn-secondary">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Modal Rechazo -->
        <div id="modalRechazo" class="modal">
            <div class="modal-content">
                <h3>Motivo de Rechazo</h3>
                <form method="POST">
                    <input type="hidden" name="id_solicitud" id="idRechazo">
                    <p><textarea name="motivo_rechazo" class="form-control" rows="4" placeholder="Explique claramente el motivo del rechazo..." required></textarea></p>
                    <p>
                        <select name="tipo_no_aplica" class="form-control">
                            <option value="">Tipo No Aplica (opcional)</option>
                            <?php foreach($TIPOS_NO_APLICA as $t): ?>
                                <option><?=htmlspecialchars($t)?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p style="text-align:right;">
                        <button type="submit" name="rechazar_solicitud" class="btn btn-danger">Confirmar Rechazo</button>
                        <button type="button" onclick="cerrarRechazo()" class="btn btn-secondary">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Modal Finalizar -->
        <div id="modalFinalizar" class="modal">
            <div class="modal-content">
                <h3>Finalizar Paro</h3>
                <form method="POST">
                    <input type="hidden" name="id_solicitud" id="idFinalizar">
                    <p><textarea name="comentario_final" class="form-control" rows="5" placeholder="Describa la solución aplicada, repuestos usados, etc. (obligatorio)" required></textarea></p>
                    <p style="text-align:right;">
                        <button type="submit" name="finalizar_paro" class="btn btn-success">Finalizar Paro</button>
                        <button type="button" onclick="cerrarFinalizar()" class="btn btn-secondary">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>
    </div>

    <footer class="footer">
        Sistema de Control de Paros © <?=date("Y")?> | Desarrollado por Nestor Rosales - Rosales_Dev91
    </footer>

    <script>
        function actualizarReloj() {
            const ahora = new Date();
            const opciones = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
            document.getElementById('reloj').textContent = ahora.toLocaleDateString('es-CR', opciones).replace(/,/g, '');
        }
        setInterval(actualizarReloj, 1000); actualizarReloj();

        function mostrarPausa(id) { 
            document.getElementById('idPausar').value = id; 
            document.getElementById('modalPausar').style.display = 'flex'; 
        }
        function cerrarPausar() { 
            document.getElementById('modalPausar').style.display = 'none'; 
        }

        function mostrarRechazo(id) { 
            document.getElementById('idRechazo').value = id; 
            document.getElementById('modalRechazo').style.display = 'flex'; 
        }
        function cerrarRechazo() { 
            document.getElementById('modalRechazo').style.display = 'none'; 
        }

        function mostrarFinalizar(id) { 
            document.getElementById('idFinalizar').value = id; 
            document.getElementById('modalFinalizar').style.display = 'flex'; 
        }
        function cerrarFinalizar() { 
            document.getElementById('modalFinalizar').style.display = 'none'; 
        }

        document.addEventListener('keydown', e => { 
            if (e.key === 'Escape') { 
                cerrarPausar(); 
                cerrarRechazo(); 
                cerrarFinalizar(); 
            } 
        });
        
        document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { 
            if (e.target === m) { 
                cerrarPausar(); 
                cerrarRechazo(); 
                cerrarFinalizar(); 
            }
        }));

        setInterval(() => { 
            if (!document.querySelector('.modal[style*="flex"]')) location.reload(); 
        }, 60000);
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