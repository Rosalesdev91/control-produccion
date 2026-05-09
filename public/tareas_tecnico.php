<?php
session_start();

// Configuración directa de la base de datos
$host = 'localhost';
$dbname = 'produccion_quiebras';
$username = 'root';
$password = '';

try {
    $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

require_once 'registrar_actividad.php';
date_default_timezone_set('America/Guatemala');

// Verificar sesión de técnico
if (!isset($_SESSION['es_tecnico']) || !$_SESSION['es_tecnico']) {
    header('Location: login_tareas.php');
    exit;
}

$id_tecnico = $_SESSION['id_tecnico'];

// ==================== PROCESAR ACCIONES POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // TOMAR TAREA (para tareas de "cualquier técnico")
    if (isset($_POST['tomar_tarea'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        $accion_tomar = $_POST['accion_tomar'] ?? 'solo_asignar';
        $comentario_asignar = trim($_POST['comentario_tomar'] ?? 'Tarea tomada por técnico');
        
        try {
            $conexion->beginTransaction();
            
            // Verificar que la tarea está disponible
            $stmt_check = $conexion->prepare("SELECT id FROM tareas WHERE id = ? AND asignado_a IS NULL AND estado = 'pendiente'");
            $stmt_check->execute([$id_tarea]);
            
            if ($stmt_check->rowCount() > 0) {
                // Asignar la tarea al técnico actual
                $stmt = $conexion->prepare("UPDATE tareas SET asignado_a = ? WHERE id = ? AND asignado_a IS NULL");
                $stmt->execute([$id_tecnico, $id_tarea]);
                
                if ($stmt->rowCount() > 0) {
                    // Registrar la asignación
                    $stmt2 = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, ?, 'asignada')");
                    $stmt2->execute([$id_tarea, "Tarea tomada por " . ($_SESSION['nombre_tecnico'] ?? 'Técnico') . ": $comentario_asignar"]);
                    
                    // Si el técnico eligió asignar e iniciar
                    if ($accion_tomar === 'asignar_iniciar') {
                        $comentario_inicio = trim($_POST['comentario_inicio_tomar'] ?? 'Inicio inmediato después de asignación');
                        
                        // Iniciar la tarea
                        $stmt_inicio = $conexion->prepare("UPDATE tareas SET estado = 'en_proceso', fecha_inicio = NOW() WHERE id = ? AND asignado_a = ? AND estado = 'asignada'");
                        $stmt_inicio->execute([$id_tarea, $id_tecnico]);
                        
                        if ($stmt_inicio->rowCount() > 0) {
                            // Registrar el inicio
                            $stmt3 = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, ?, 'en_proceso')");
                            $stmt3->execute([$id_tarea, $comentario_inicio]);
                            
                            $_SESSION['mensaje_exito'] = "✅ Tarea asignada e iniciada correctamente. Ya está en proceso.";
                        } else {
                            throw new Exception("La tarea se asignó pero no se pudo iniciar automáticamente.");
                        }
                    } else {
                        $_SESSION['mensaje_exito'] = "✅ Tarea asignada correctamente. Ya puedes iniciarla cuando quieras.";
                    }
                    
                    $conexion->commit();
                } else {
                    throw new Exception("No se pudo tomar la tarea.");
                }
            } else {
                throw new Exception("La tarea ya no está disponible o fue tomada por otro técnico.");
            }
        } catch(Exception $e) {
            $conexion->rollBack();
            $_SESSION['mensaje_error'] = "Error: " . $e->getMessage();
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
    
    // INICIAR TAREA
    if (isset($_POST['iniciar_tarea'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        $comentario = trim($_POST['comentario_inicio'] ?? 'Inicio de tarea');
        
        try {
            $conexion->beginTransaction();
            
            $stmt_check = $conexion->prepare("SELECT id FROM tareas WHERE id = ? AND asignado_a = ? AND estado IN ('asignada', 'pendiente')");
            $stmt_check->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt_check->rowCount() > 0) {
                $stmt = $conexion->prepare("UPDATE tareas SET estado = 'en_proceso', fecha_inicio = NOW() WHERE id = ? AND asignado_a = ? AND estado IN ('asignada', 'pendiente')");
                $stmt->execute([$id_tarea, $id_tecnico]);
                
                if ($stmt->rowCount() > 0) {
                    $stmt2 = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, ?, 'en_proceso')");
                    $stmt2->execute([$id_tarea, $comentario]);
                    
                    $conexion->commit();
                    $_SESSION['mensaje_exito'] = "✅ Tarea iniciada correctamente.";
                } else {
                    throw new Exception("No se pudo iniciar la tarea.");
                }
            } else {
                throw new Exception("No tienes permiso para iniciar esta tarea.");
            }
        } catch(Exception $e) {
            $conexion->rollBack();
            $_SESSION['mensaje_error'] = "Error: " . $e->getMessage();
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
    
    // PAUSAR TAREA
    if (isset($_POST['pausar_tarea'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        $comentario = trim($_POST['comentario_pausa'] ?? '');
        
        if ($comentario === '') {
            $_SESSION['mensaje_error'] = "Debe ingresar un motivo para pausar la tarea.";
            header("Location: tareas_tecnico.php");
            exit;
        }
        
        try {
            $conexion->beginTransaction();
            
            $stmt_check = $conexion->prepare("SELECT id FROM tareas WHERE id = ? AND asignado_a = ? AND estado = 'en_proceso'");
            $stmt_check->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt_check->rowCount() === 0) {
                throw new Exception("La tarea no está en proceso o no te pertenece.");
            }
            
            $stmt = $conexion->prepare("UPDATE tareas SET estado = 'pausada', ultima_pausa = NOW() WHERE id = ? AND asignado_a = ? AND estado = 'en_proceso'");
            $stmt->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt->rowCount() > 0) {
                $stmt2 = $conexion->prepare("INSERT INTO pausas_tarea (tarea_id, id_tecnico, fecha_pausa, comentario) VALUES (?, ?, NOW(), ?)");
                $stmt2->execute([$id_tarea, $id_tecnico, $comentario]);
                
                $stmt3 = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, ?, 'pausada')");
                $stmt3->execute([$id_tarea, $comentario]);
                
                $conexion->commit();
                $_SESSION['mensaje_exito'] = "⏸️ Tarea pausada correctamente.";
            } else {
                throw new Exception("No se pudo pausar la tarea.");
            }
        } catch (Exception $e) {
            $conexion->rollBack();
            $_SESSION['mensaje_error'] = "Error al pausar la tarea: " . $e->getMessage();
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
    
    // REANUDAR TAREA
    if (isset($_POST['reanudar_tarea'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        
        try {
            $conexion->beginTransaction();
            
            $stmt_check = $conexion->prepare("SELECT id FROM tareas WHERE id = ? AND asignado_a = ? AND estado = 'pausada'");
            $stmt_check->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt_check->rowCount() === 0) {
                throw new Exception("La tarea no está pausada o no te pertenece.");
            }
            
            // Obtener la pausa activa
            $stmt = $conexion->prepare("SELECT id FROM pausas_tarea WHERE tarea_id = ? AND fecha_reanudacion IS NULL ORDER BY fecha_pausa DESC LIMIT 1");
            $stmt->execute([$id_tarea]);
            $pausa = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pausa) {
                $stmt2 = $conexion->prepare("UPDATE pausas_tarea SET fecha_reanudacion = NOW() WHERE id = ?");
                $stmt2->execute([$pausa['id']]);
            }
            
            $stmt3 = $conexion->prepare("UPDATE tareas SET estado = 'en_proceso', ultima_pausa = NULL WHERE id = ? AND asignado_a = ?");
            $stmt3->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt3->rowCount() > 0) {
                $stmt4 = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, 'Tarea reanudada después de pausa', 'en_proceso')");
                $stmt4->execute([$id_tarea]);
                
                $conexion->commit();
                $_SESSION['mensaje_exito'] = "▶️ Tarea reanudada correctamente.";
            } else {
                throw new Exception("No se pudo reanudar la tarea.");
            }
        } catch (Exception $e) {
            $conexion->rollBack();
            $_SESSION['mensaje_error'] = "Error al reanudar la tarea: " . $e->getMessage();
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
    
    // FINALIZAR TAREA
    if (isset($_POST['finalizar_tarea'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        $comentario = trim($_POST['comentario_final'] ?? '');
        $solucion = trim($_POST['solucion_aplicada'] ?? '');
        
        if ($comentario === '' || $solucion === '') {
            $_SESSION['mensaje_error'] = "Debe ingresar comentario y solución aplicada.";
            header("Location: tareas_tecnico.php");
            exit;
        }
        
        try {
            $conexion->beginTransaction();
            
            $stmt_check = $conexion->prepare("SELECT id FROM tareas WHERE id = ? AND asignado_a = ? AND estado IN ('en_proceso', 'pausada')");
            $stmt_check->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt_check->rowCount() === 0) {
                throw new Exception("La tarea no está en proceso o pausada, o no te pertenece.");
            }
            
            // Cerrar pausas activas
            $stmt_pausas = $conexion->prepare("UPDATE pausas_tarea SET fecha_reanudacion = NOW() WHERE tarea_id = ? AND fecha_reanudacion IS NULL");
            $stmt_pausas->execute([$id_tarea]);
            
            $stmt = $conexion->prepare("UPDATE tareas SET estado = 'finalizada', fecha_fin = NOW(), comentario_final = ?, solucion_aplicada = ? WHERE id = ? AND asignado_a = ?");
            $stmt->execute([$comentario, $solucion, $id_tarea, $id_tecnico]);
            
            if ($stmt->rowCount() > 0) {
                $stmt2 = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, ?, 'finalizada')");
                $stmt2->execute([$id_tarea, $comentario]);
                
                $conexion->commit();
                $_SESSION['mensaje_exito'] = "✅ Tarea finalizada correctamente.";
            } else {
                throw new Exception("No se pudo finalizar la tarea.");
            }
        } catch (Exception $e) {
            $conexion->rollBack();
            $_SESSION['mensaje_error'] = "Error al finalizar la tarea: " . $e->getMessage();
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
    
    // AGREGAR COMENTARIO
    if (isset($_POST['agregar_comentario'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        $comentario = trim($_POST['nuevo_comentario'] ?? '');
        
        if ($comentario !== '') {
            $stmt_check = $conexion->prepare("SELECT id FROM tareas WHERE id = ? AND (asignado_a = ? OR (asignado_a IS NULL AND estado = 'pendiente'))");
            $stmt_check->execute([$id_tarea, $id_tecnico]);
            
            if ($stmt_check->rowCount() > 0) {
                $stmt = $conexion->prepare("INSERT INTO seguimiento (tarea_id, comentario, estado_nuevo) VALUES (?, ?, 'comentario')");
                
                if ($stmt->execute([$id_tarea, $comentario])) {
                    $_SESSION['mensaje_exito'] = "💬 Comentario agregado correctamente.";
                } else {
                    $_SESSION['mensaje_error'] = "Error al agregar comentario.";
                }
            } else {
                $_SESSION['mensaje_error'] = "No tienes permiso para comentar esta tarea.";
            }
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
    
    // GENERAR PDF
    if (isset($_POST['generar_pdf'])) {
        $id_tarea = (int)$_POST['id_tarea'];
        
        $stmt = $conexion->prepare("
            SELECT t.*, tec.nombre_tecnico, tp.nombre as tipo_tarea_nombre, eq.nombre_equipo
            FROM tareas t
            LEFT JOIN tecnicos tec ON t.asignado_a = tec.id
            LEFT JOIN tipos_tarea tp ON TRIM(t.tipo_paro) = TRIM(tp.nombre)
            LEFT JOIN equipos eq ON t.equipo_id = eq.id
            WHERE t.id = ?
        ");
        $stmt->execute([$id_tarea]);
        $tarea = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tarea) {
            $stmt2 = $conexion->prepare("SELECT * FROM seguimiento WHERE tarea_id = ? ORDER BY fecha DESC");
            $stmt2->execute([$id_tarea]);
            $seguimiento = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            
            $tarea['seguimiento'] = $seguimiento;
            
            if (function_exists('generarPDFTarea')) {
                generarPDFTarea($tarea);
            } else {
                $_SESSION['mensaje_error'] = "Error: Función de generación de PDF no encontrada.";
                header("Location: tareas_tecnico.php");
            }
            exit;
        }
        header("Location: tareas_tecnico.php");
        exit;
    }
}

// ==================== OBTENER FECHAS DE LA SEMANA ACTUAL ====================
$fecha_inicio_semana = date('Y-m-d', strtotime('monday this week'));
$fecha_fin_semana = date('Y-m-d', strtotime('sunday this week'));

// ==================== OBTENER TAREAS CON FILTROS ====================

$estado_filtro = $_GET['estado'] ?? 'todas';
$busqueda = $_GET['busqueda'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

// Si no se especifican fechas, usar la semana actual por defecto
if (empty($fecha_desde) && empty($fecha_hasta)) {
    $fecha_desde = $fecha_inicio_semana;
    $fecha_hasta = $fecha_fin_semana;
}

$estados_validos = ['asignada', 'en_proceso', 'pausada', 'pendiente', 'programada', 'finalizada', 'disponibles', 'todas'];
if (!in_array($estado_filtro, $estados_validos)) {
    $estado_filtro = 'todas';
}

$sql_tareas = "
    SELECT t.*, 
           tec.nombre_tecnico,
           CASE 
               WHEN t.asignado_a IS NULL THEN '👥 Cualquier técnico'
               ELSE tec.nombre_tecnico 
           END as tecnico_display,
           tp.nombre as tipo_tarea_nombre,
           eq.nombre_equipo,
           (SELECT COUNT(*) FROM seguimiento WHERE tarea_id = t.id) as total_comentarios,
           (SELECT comentario FROM seguimiento WHERE tarea_id = t.id ORDER BY fecha DESC LIMIT 1) as ultimo_comentario,
           (SELECT fecha FROM seguimiento WHERE tarea_id = t.id ORDER BY fecha DESC LIMIT 1) as fecha_ultimo_comentario,
           CASE 
               WHEN t.estado = 'pausada' AND t.ultima_pausa IS NOT NULL THEN 
                   TIMESTAMPDIFF(MINUTE, t.ultima_pausa, NOW())
               ELSE 0 
           END as tiempo_pausado_actual,
           COALESCE(
               (SELECT SUM(TIMESTAMPDIFF(MINUTE, fecha_pausa, COALESCE(fecha_reanudacion, NOW()))) 
                FROM pausas_tarea WHERE tarea_id = t.id), 0) as tiempo_total_pausado,
           CASE 
               WHEN t.estado = 'finalizada' AND t.fecha_inicio IS NOT NULL AND t.fecha_fin IS NOT NULL THEN 
                   TIMESTAMPDIFF(MINUTE, t.fecha_inicio, t.fecha_fin)
               ELSE NULL 
           END as tiempo_ejecucion,
           CASE 
               WHEN t.fecha_programada > NOW() AND t.estado = 'pendiente' THEN 1
               ELSE 0
           END as es_programada,
           CASE 
               WHEN t.fecha_programada > NOW() AND t.estado = 'pendiente' THEN 
                   TIMESTAMPDIFF(MINUTE, NOW(), t.fecha_programada)
               ELSE 0
           END as minutos_para_inicio,
           CASE 
               WHEN t.asignado_a IS NULL AND t.estado = 'pendiente' AND t.fecha_programada <= NOW() THEN 1
               ELSE 0
           END as disponible_para_tomar
    FROM tareas t
    LEFT JOIN tecnicos tec ON t.asignado_a = tec.id
    LEFT JOIN tipos_tarea tp ON TRIM(t.tipo_paro) = TRIM(tp.nombre)
    LEFT JOIN equipos eq ON t.equipo_id = eq.id
    WHERE 1=1
";

$params = [];

// Filtro por fecha - Usar la fecha apropiada según el tipo de tarea
// Para tareas programadas (con fecha_programada futura o pasada), usar fecha_programada
// Para tareas normales, usar fecha_creacion
$sql_tareas .= " AND (
    (t.fecha_programada IS NOT NULL AND DATE(t.fecha_programada) BETWEEN :fecha_desde AND :fecha_hasta)
    OR 
    (t.fecha_programada IS NULL AND DATE(t.fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta)
    OR
    (t.estado IN ('en_proceso', 'pausada', 'finalizada') AND DATE(t.fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta)
)";

$params[':fecha_desde'] = $fecha_desde;
$params[':fecha_hasta'] = $fecha_hasta;

if ($estado_filtro === 'disponibles') {
    $sql_tareas .= " AND t.asignado_a IS NULL AND t.estado = 'pendiente' AND t.fecha_programada <= NOW()";
} else {
    $sql_tareas .= " AND (t.asignado_a = :id_tecnico OR t.asignado_a IS NULL)";
    $params[':id_tecnico'] = $id_tecnico;
}

if ($estado_filtro !== 'todas' && $estado_filtro !== 'disponibles') {
    if ($estado_filtro == 'programada') {
        $sql_tareas .= " AND t.estado = 'pendiente' AND t.fecha_programada > NOW()";
    } elseif ($estado_filtro == 'pendiente') {
        $sql_tareas .= " AND t.estado = 'pendiente' AND t.fecha_programada <= NOW()";
    } else {
        $sql_tareas .= " AND t.estado = :estado";
        $params[':estado'] = $estado_filtro;
    }
} elseif ($estado_filtro === 'todas') {
    $sql_tareas .= " AND (t.estado IN ('pendiente', 'asignada', 'en_proceso', 'pausada') OR (t.asignado_a IS NULL AND t.estado = 'pendiente'))";
}

if (!empty($busqueda)) {
    $sql_tareas .= " AND (t.descripcion LIKE :busqueda OR tp.nombre LIKE :busqueda OR eq.nombre_equipo LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$sql_tareas .= " ORDER BY 
    CASE 
        WHEN t.estado = 'en_proceso' THEN 1
        WHEN t.estado = 'pausada' THEN 2
        WHEN t.asignado_a IS NULL AND t.estado = 'pendiente' AND t.fecha_programada <= NOW() THEN 3
        WHEN t.estado = 'pendiente' AND t.fecha_programada <= NOW() THEN 4
        WHEN t.estado = 'pendiente' AND t.fecha_programada > NOW() THEN 5
        WHEN t.estado = 'asignada' THEN 6
        WHEN t.estado = 'finalizada' THEN 7
        ELSE 8
    END,
    CASE 
        WHEN t.estado = 'pendiente' AND t.fecha_programada > NOW() THEN t.fecha_programada
        ELSE COALESCE(t.fecha_programada, t.fecha_creacion)
    END DESC";

$stmt_tareas = $conexion->prepare($sql_tareas);
$stmt_tareas->execute($params);
$tareas = $stmt_tareas->fetchAll(PDO::FETCH_ASSOC);

// ==================== ESTADÍSTICAS ====================
$sql_stats = "
    SELECT 
        COUNT(CASE WHEN asignado_a = :id_tecnico AND estado = 'pendiente' AND fecha_programada <= NOW() 
            AND ( (fecha_programada IS NOT NULL AND DATE(fecha_programada) BETWEEN :fecha_desde AND :fecha_hasta) 
                OR (fecha_programada IS NULL AND DATE(fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta) )
            THEN 1 END) as pendientes,
        COUNT(CASE WHEN asignado_a = :id_tecnico2 AND estado = 'en_proceso' 
            AND DATE(fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta THEN 1 END) as en_proceso,
        COUNT(CASE WHEN asignado_a = :id_tecnico3 AND estado = 'pausada' 
            AND DATE(fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta THEN 1 END) as pausadas,
        COUNT(CASE WHEN asignado_a = :id_tecnico4 AND estado = 'pendiente' AND fecha_programada > NOW() 
            AND DATE(fecha_programada) BETWEEN :fecha_desde AND :fecha_hasta THEN 1 END) as programadas,
        COUNT(CASE WHEN asignado_a IS NULL AND estado = 'pendiente' AND fecha_programada <= NOW() 
            AND ( (fecha_programada IS NOT NULL AND DATE(fecha_programada) BETWEEN :fecha_desde AND :fecha_hasta) 
                OR (fecha_programada IS NULL AND DATE(fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta) )
            THEN 1 END) as disponibles,
        COUNT(CASE WHEN asignado_a = :id_tecnico5 AND estado = 'finalizada' AND DATE(fecha_fin) = CURDATE() 
            AND DATE(fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta THEN 1 END) as finalizadas_hoy,
        COUNT(CASE WHEN asignado_a = :id_tecnico6 AND estado = 'finalizada' 
            AND DATE(fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta THEN 1 END) as total_finalizadas
    FROM tareas
";

$stmt_stats = $conexion->prepare($sql_stats);
$stmt_stats->execute([
    ':id_tecnico' => $id_tecnico,
    ':id_tecnico2' => $id_tecnico,
    ':id_tecnico3' => $id_tecnico,
    ':id_tecnico4' => $id_tecnico,
    ':id_tecnico5' => $id_tecnico,
    ':id_tecnico6' => $id_tecnico,
    ':fecha_desde' => $fecha_desde,
    ':fecha_hasta' => $fecha_hasta
]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

function formatearTiempo($minutos) {
    if (!$minutos || $minutos <= 0) return '0m';
    $horas = floor($minutos / 60);
    $mins = $minutos % 60;
    return ($horas > 0 ? $horas . 'h ' : '') . $mins . 'm';
}

// Función para obtener el nombre de la semana
function obtenerNombreSemana($fecha_inicio, $fecha_fin) {
    $inicio = date('d/m', strtotime($fecha_inicio));
    $fin = date('d/m/Y', strtotime($fecha_fin));
    $numero_semana = date('W', strtotime($fecha_inicio));
    return "Semana $numero_semana ($inicio - $fin)";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Tareas - Panel Técnico</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; color: #333; }
        
        :root {
            --primary: #3498db; --success: #27ae60; --danger: #e74c3c; --warning: #f39c12;
            --secondary: #7f8c8d; --pausado: #e67e22; --programada: #9b59b6; --disponible: #2ecc71;
            --light: #f8f9fa; --dark: #2c3e50; --border: #dee2e6; --radius: 10px; --shadow: 0 4px 15px rgba(0,0,0,.1);
        }
        
        .header { background: var(--dark); color: white; padding: 15px 0; box-shadow: var(--shadow); position: sticky; top: 0; z-index: 100; }
        .header-content { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 20px; }
        .user-info { display: flex; align-items: center; gap: 20px; background: rgba(255,255,255,.1); padding: 8px 20px; border-radius: 50px; }
        .main { max-width: 1400px; margin: 20px auto; padding: 0 20px; }
        
        .alert { padding: 15px 20px; border-radius: var(--radius); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 5px solid var(--success); }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 5px solid var(--danger); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: var(--radius); box-shadow: var(--shadow); text-align: center; }
        .stat-number { font-size: 2.2em; font-weight: 700; color: var(--dark); }
        .stat-label { color: var(--secondary); font-size: .9em; text-transform: uppercase; }
        .stat-disponible { border-left: 4px solid var(--disponible); }
        
        .filters-section { background: white; border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 25px; padding: 20px; }
        .filters-grid { display: grid; grid-template-columns: 1fr 1fr 2fr auto; gap: 15px; align-items: end; }
        .form-control { width: 100%; padding: 10px 15px; border: 2px solid var(--border); border-radius: 8px; font-size: 14px; }
        .form-control:focus { outline: none; border-color: var(--primary); }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-secondary { background: var(--secondary); color: white; }
        .btn-programada { background: var(--programada); color: white; }
        .btn-disponible { background: var(--disponible); color: white; animation: pulse 2s infinite; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-icon { padding: 5px 8px; font-size: 11px; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .table-container { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow-x: auto; margin-top: 20px; }
        .tasks-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .tasks-table th { background: var(--dark); color: white; padding: 15px 10px; text-align: left; font-weight: 600; font-size: 13px; }
        .tasks-table td { padding: 15px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
        .tasks-table tbody tr:hover { background: #f5f5f5; }
        .tasks-table tr.disponible-row { background: #e8f8f5; border-left: 4px solid var(--disponible); }
        
        .estado-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; color: white; display: inline-block; }
        .estado-pendiente { background: var(--secondary); }
        .estado-programada { background: var(--programada); }
        .estado-asignada { background: var(--primary); }
        .estado-en_proceso { background: var(--success); }
        .estado-pausada { background: var(--pausado); }
        .estado-finalizada { background: var(--dark); }
        .estado-disponible { background: var(--disponible); }
        
        .badge-prioridad { padding: 3px 8px; border-radius: 20px; font-size: 11px; background: #ffeaa7; color: #d35400; white-space: nowrap; }
        .badge-tipo { padding: 3px 8px; border-radius: 20px; font-size: 11px; background: #d4e6f1; color: #2874a6; white-space: nowrap; }
        .pausa-indicator { display: inline-flex; align-items: center; background: #fff4e5; padding: 3px 10px; border-radius: 20px; font-size: 11px; color: var(--pausado); border: 1px solid #ffd699; }
        .programada-indicator { display: inline-flex; align-items: center; background: #f3e5f5; padding: 3px 10px; border-radius: 20px; font-size: 11px; color: var(--programada); border: 1px solid #ce93d8; }
        .disponible-indicator { display: inline-flex; align-items: center; background: #e8f8f5; padding: 3px 10px; border-radius: 20px; font-size: 11px; color: var(--disponible); border: 1px solid #a3e4d7; }
        
        .task-actions { display: flex; flex-wrap: wrap; gap: 5px; min-width: 150px; }
        .comments-section { max-width: 250px; max-height: 80px; overflow-y: auto; padding: 8px; background: #f8f9fa; border-radius: 6px; font-size: 11px; border: 1px solid #e0e0e0; }
        .comment-item { padding: 4px 0; border-bottom: 1px dashed #dee2e6; }
        .comment-date { color: var(--secondary); font-size: 9px; font-weight: 500; }
        .task-description { max-width: 250px; font-size: 12px; }
        .equipo-info { display: flex; align-items: center; gap: 5px; color: var(--dark); font-weight: 500; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; padding: 30px; border-radius: var(--radius); max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        textarea { width: 100%; padding: 10px; border: 2px solid var(--border); border-radius: 6px; min-height: 100px; resize: vertical; }
        
        .footer { background: var(--dark); color: white; text-align: center; padding: 15px; margin-top: 40px; font-size: 13px; }
        .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: var(--radius); color: var(--secondary); }
        
        .disponible-actions { display: flex; flex-direction: column; gap: 5px; }
        .btn-option-active { border: 2px solid white !important; transform: scale(1.02); }
        .week-info { background: linear-gradient(135deg, var(--primary), #2980b9); color: white; padding: 15px; border-radius: var(--radius); margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .week-info h3 { margin: 0; font-size: 1.1rem; }
        .week-info .week-dates { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 25px; font-size: 0.9rem; }
        
        @media (max-width:1024px){ .stats-grid{ grid-template-columns: repeat(4,1fr); } .table-container{ overflow-x:scroll; } .tasks-table{ min-width:1200px; } .filters-grid{ grid-template-columns: 1fr; gap: 10px; } }
        @media (max-width:768px){ .stats-grid{ grid-template-columns: repeat(2,1fr); } }
        
        .date-range { display: flex; gap: 10px; align-items: center; }
        .date-range input { width: 100%; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: var(--secondary); }
        .filter-actions { display: flex; gap: 10px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-area">
                <h2 style="font-size:1.2rem"><i class="fas fa-tasks"></i> Mis Tareas</h2>
            </div>
            <div class="user-info">
                <span><i class="fas fa-user-cog"></i> <?= htmlspecialchars($_SESSION['nombre_tecnico'] ?? 'Técnico') ?></span>
                <span><i class="fas fa-clock"></i> <span id="reloj"></span></span>
                <a href="login_tareas.php?logout=1" class="btn btn-sm btn-danger" onclick="return confirm('¿Cerrar sesión?')">Salir</a>
            </div>
        </div>
    </header>
    
    <div class="main">
        <?php if (isset($_SESSION['mensaje_exito'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['mensaje_exito']) ?></div>
            <?php unset($_SESSION['mensaje_exito']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['mensaje_error'])): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['mensaje_error']) ?></div>
            <?php unset($_SESSION['mensaje_error']); ?>
        <?php endif; ?>
        
        <!-- Información de la semana actual -->
        <div class="week-info">
            <h3><i class="fas fa-calendar-week"></i> Tareas de la semana</h3>
            <div class="week-dates">
                <i class="fas fa-calendar-alt"></i> <?= obtenerNombreSemana($fecha_desde, $fecha_hasta) ?>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?= (int)($stats['pendientes']??0) ?></div><div class="stat-label">Mis Pendientes</div></div>
            <div class="stat-card"><div class="stat-number"><?= (int)($stats['programadas']??0) ?></div><div class="stat-label">Mis Programadas</div></div>
            <div class="stat-card"><div class="stat-number"><?= (int)($stats['en_proceso']??0) ?></div><div class="stat-label">En Proceso</div></div>
            <div class="stat-card"><div class="stat-number" style="color:var(--pausado);"><?= (int)($stats['pausadas']??0) ?></div><div class="stat-label">Pausadas</div></div>
            <div class="stat-card stat-disponible"><div class="stat-number" style="color:var(--disponible);"><?= (int)($stats['disponibles']??0) ?></div><div class="stat-label">Disponibles</div></div>
            <div class="stat-card"><div class="stat-number"><?= (int)($stats['finalizadas_hoy']??0) ?></div><div class="stat-label">Finalizadas Hoy</div></div>
            <div class="stat-card"><div class="stat-number"><?= (int)($stats['total_finalizadas']??0) ?></div><div class="stat-label">Totales Semana</div></div>
        </div>
        
        <!-- Filtros con rango de fechas -->
        <div class="filters-section">
            <form method="GET">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> Estado</label>
                        <select name="estado" class="form-control">
                            <option value="todas" <?= $estado_filtro=='todas'?'selected':'' ?>>Todas (Mías + Disponibles)</option>
                            <option value="disponibles" <?= $estado_filtro=='disponibles'?'selected':'' ?>>📦 Solo Disponibles</option>
                            <option value="pendiente" <?= $estado_filtro=='pendiente'?'selected':'' ?>>Mis Pendientes</option>
                            <option value="programada" <?= $estado_filtro=='programada'?'selected':'' ?>>Mis Programadas</option>
                            <option value="en_proceso" <?= $estado_filtro=='en_proceso'?'selected':'' ?>>En Proceso</option>
                            <option value="pausada" <?= $estado_filtro=='pausada'?'selected':'' ?>>Pausadas</option>
                            <option value="finalizada" <?= $estado_filtro=='finalizada'?'selected':'' ?>>Finalizadas</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-start"></i> Desde</label>
                        <input type="date" name="fecha_desde" class="form-control" value="<?= htmlspecialchars($fecha_desde) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-end"></i> Hasta</label>
                        <input type="date" name="fecha_hasta" class="form-control" value="<?= htmlspecialchars($fecha_hasta) ?>">
                    </div>
                    
                    <div class="filter-group" style="grid-column: span 2;">
                        <label><i class="fas fa-search"></i> Búsqueda</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" name="busqueda" class="form-control" placeholder="Buscar por descripción, tipo o equipo..." value="<?= htmlspecialchars($busqueda) ?>">
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                                <a href="tareas_tecnico.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Limpiar</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if(!empty($fecha_desde) || !empty($fecha_hasta)): ?>
                    <div style="margin-top: 15px; padding: 10px; background: #e8f0fe; border-radius: 8px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-filter" style="color: var(--primary);"></i>
                        <span style="font-size: 13px; color: #2c3e50;">
                            <strong>Filtro activo:</strong> 
                            <?php if(!empty($fecha_desde)): ?>Desde <?= date('d/m/Y', strtotime($fecha_desde)) ?><?php endif; ?>
                            <?php if(!empty($fecha_hasta)): ?> hasta <?= date('d/m/Y', strtotime($fecha_hasta)) ?><?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Tabla de tareas -->
        <?php if (empty($tareas)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle fa-4x" style="color:var(--success);margin-bottom:15px;"></i>
                <h3>No hay tareas para esta semana</h3>
                <p>No hay tareas registradas en el período <?= date('d/m/Y', strtotime($fecha_desde)) ?> - <?= date('d/m/Y', strtotime($fecha_hasta)) ?></p>
                <p style="margin-top: 10px; color: var(--primary);">
                    <i class="fas fa-info-circle"></i> Puedes cambiar el rango de fechas para ver otras tareas
                </p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="tasks-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Prioridad</th>
                            <th>Tipo</th>
                            <th>Equipo</th>
                            <th>Descripción</th>
                            <th>Técnico</th>
                            <th>Estado</th>
                            <th>Programación</th>
                            <th>Comentarios</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tareas as $tarea): 
                            $estado_visual = $tarea['estado'];
                            $es_disponible = ($tarea['asignado_a'] === null && $tarea['estado'] == 'pendiente' && !$tarea['es_programada']);
                            
                            if ($tarea['es_programada']) {
                                $estado_visual = 'programada';
                            } elseif ($es_disponible) {
                                $estado_visual = 'disponible';
                            }
                            
                            $fila_clase = $es_disponible ? 'disponible-row' : '';
                            $estado_clase = $estado_visual;
                        ?>
                            <tr class="<?= $fila_clase ?>">
                                <td><strong>#<?= (int)($tarea['id']??0) ?></strong> </td>
                                <td><span class="badge-prioridad"><?= htmlspecialchars(ucfirst($tarea['prioridad']??'Media')) ?></span></td>
                                <td><span class="badge-tipo"><?= htmlspecialchars($tarea['tipo_tarea_nombre']??'General') ?></span></td>
                                <td>
                                    <?php if(!empty($tarea['nombre_equipo'])): ?>
                                        <div class="equipo-info"><i class="fas fa-cog"></i> <?= htmlspecialchars($tarea['nombre_equipo']) ?></div>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <div class="task-description">
                                        <?= nl2br(htmlspecialchars(substr($tarea['descripcion']??'',0,100))) ?><?php if(strlen($tarea['descripcion']??'')>100): ?>...<?php endif; ?>
                                        
                                        <?php if($tarea['tiempo_pausado_actual'] > 0 && $tarea['estado'] == 'pausada'): ?>
                                            <div class="pausa-indicator" style="margin-top:5px;">
                                                <i class="fas fa-hourglass-half"></i> Pausa: <?= formatearTiempo($tarea['tiempo_pausado_actual']) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if($tarea['tiempo_ejecucion'] > 0): ?>
                                            <div style="margin-top:5px; font-size:11px; color:var(--success);">
                                                <i class="fas fa-clock"></i> Ejecución: <?= formatearTiempo($tarea['tiempo_ejecucion']) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if($es_disponible): ?>
                                            <div class="disponible-indicator" style="margin-top:5px;">
                                                <i class="fas fa-hand-pointer"></i> Disponible para tomar
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                 </td>
                                <td>
                                    <?php if($tarea['asignado_a'] === null): ?>
                                        <span class="badge" style="background: var(--disponible); color: white; padding: 3px 8px; border-radius: 12px;">
                                            <i class="fas fa-users"></i> Cualquier técnico
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($tarea['nombre_tecnico'] ?? 'Asignado') ?>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <span class="estado-badge estado-<?= $estado_clase ?>">
                                        <?= str_replace('_', ' ', $estado_visual) ?>
                                    </span>
                                 </td>
                                <td>
                                    <?php if($tarea['es_programada']): ?>
                                        <div class="programada-indicator">
                                            <i class="fas fa-calendar-alt"></i> 
                                            <?= date('d/m H:i', strtotime($tarea['fecha_programada'])) ?>
                                            <?php if($tarea['minutos_para_inicio'] > 0): ?>
                                                <br><small>en <?= formatearTiempo($tarea['minutos_para_inicio']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif($tarea['fecha_programada']): ?>
                                        <span style="color:#999; font-size:11px;">
                                            <i class="fas fa-clock"></i> <?= date('d/m H:i', strtotime($tarea['fecha_programada'])) ?>
                                        </span>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <?php if(!empty($tarea['ultimo_comentario'])): ?>
                                        <div class="comments-section">
                                            <div class="comment-item">
                                                <span class="comment-date">
                                                    <?= isset($tarea['fecha_ultimo_comentario'])?date('d/m H:i',strtotime($tarea['fecha_ultimo_comentario'])):'' ?>:
                                                </span> 
                                                <?= htmlspecialchars(substr($tarea['ultimo_comentario'],0,50)) ?>
                                                <?php if(strlen($tarea['ultimo_comentario'])>50): ?>...<?php endif; ?>
                                            </div>
                                            <?php if(($tarea['total_comentarios']??0)>1): ?>
                                                <small style="color:var(--primary);">+ <?= (int)($tarea['total_comentarios']-1) ?> más</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                 </td>
                                 <td>
                                    <div class="task-actions">
                                        <?php if($es_disponible): ?>
                                            <div class="disponible-actions">
                                                <button type="button" onclick="mostrarTomar(<?= (int)($tarea['id']??0) ?>)" class="btn btn-disponible btn-sm" title="Tomar tarea">
                                                    <i class="fas fa-hand-pointer"></i> Tomar Tarea
                                                </button>
                                                <small style="color: var(--disponible); font-size: 10px;">
                                                    <i class="fas fa-info-circle"></i> Puedes asignar o asignar e iniciar
                                                </small>
                                            </div>
                                        
                                        <?php elseif($tarea['asignado_a'] == $id_tecnico): ?>
                                            <?php if($tarea['estado'] == 'pendiente' && !$tarea['es_programada']): ?>
                                                <button type="button" onclick="mostrarInicio(<?= (int)($tarea['id']??0) ?>)" class="btn btn-success btn-icon" title="Iniciar"><i class="fas fa-play"></i></button>
                                            
                                            <?php elseif($tarea['estado'] == 'en_proceso'): ?>
                                                <button type="button" onclick="mostrarPausa(<?= (int)($tarea['id']??0) ?>)" class="btn btn-warning btn-icon" title="Pausar"><i class="fas fa-pause"></i></button>
                                                <button type="button" onclick="mostrarFinalizar(<?= (int)($tarea['id']??0) ?>)" class="btn btn-success btn-icon" title="Finalizar"><i class="fas fa-check"></i></button>
                                            
                                            <?php elseif($tarea['estado'] == 'pausada'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="id_tarea" value="<?= (int)($tarea['id']??0) ?>">
                                                    <button type="submit" name="reanudar_tarea" class="btn btn-primary btn-icon" title="Reanudar" onclick="return confirm('¿Reanudar esta tarea?')"><i class="fas fa-play"></i></button>
                                                </form>
                                                <button type="button" onclick="mostrarFinalizar(<?= (int)($tarea['id']??0) ?>)" class="btn btn-success btn-icon" title="Finalizar"><i class="fas fa-check"></i></button>
                                            
                                            <?php elseif($tarea['es_programada']): ?>
                                                <span class="btn btn-programada btn-icon" style="opacity:0.5; cursor:default;" title="No disponible hasta la fecha programada">
                                                    <i class="fas fa-clock"></i>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if($tarea['estado'] != 'finalizada' && !$tarea['es_programada']): ?>
                                                <button type="button" onclick="mostrarComentario(<?= (int)($tarea['id']??0) ?>)" class="btn btn-secondary btn-icon" title="Comentar"><i class="fas fa-comment"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id_tarea" value="<?= (int)($tarea['id']??0) ?>">
                                            <button type="submit" name="generar_pdf" class="btn btn-secondary btn-icon" title="Generar PDF"><i class="fas fa-file-pdf"></i></button>
                                        </form>
                                    </div>
                                 </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Tomar Tarea -->
    <div id="modalTomar" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-hand-pointer" style="color: var(--disponible);"></i> Tomar Tarea</h3>
            <p style="margin: 10px 0; color: #666;">¿Cómo deseas tomar esta tarea?</p>
            <form method="POST" id="formTomarTarea">
                <input type="hidden" name="id_tarea" id="idTomar">
                <input type="hidden" name="accion_tomar" id="accionTomar" value="solo_asignar">
                
                <div id="campoComentarioAsignar" style="display: block;">
                    <div class="form-group">
                        <textarea name="comentario_tomar" placeholder="Comentario opcional para la asignación"></textarea>
                    </div>
                </div>
                
                <div id="campoComentarioInicio" style="display: none;">
                    <div class="form-group">
                        <textarea name="comentario_inicio_tomar" placeholder="Comentario para el inicio de la tarea (opcional)"></textarea>
                    </div>
                </div>
                
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" name="tomar_tarea" class="btn btn-disponible" id="btnConfirmarTomar">Confirmar</button>
                    <button type="button" onclick="cerrarModal('modalTomar')" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Inicio -->
    <div id="modalInicio" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-play" style="color: var(--success);"></i> Iniciar Tarea</h3>
            <form method="POST">
                <input type="hidden" name="id_tarea" id="idInicio">
                <div class="form-group">
                    <textarea name="comentario_inicio" placeholder="Comentario inicial (opcional)"></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" name="iniciar_tarea" class="btn btn-success">Iniciar</button>
                    <button type="button" onclick="cerrarModal('modalInicio')" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Pausa -->
    <div id="modalPausa" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-pause" style="color: var(--warning);"></i> Pausar Tarea</h3>
            <form method="POST" onsubmit="return validarPausa()">
                <input type="hidden" name="id_tarea" id="idPausa">
                <div class="form-group">
                    <textarea name="comentario_pausa" id="comentario_pausa" placeholder="Motivo de la pausa..." required></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" name="pausar_tarea" class="btn btn-warning">Pausar</button>
                    <button type="button" onclick="cerrarModal('modalPausa')" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Finalizar -->
    <div id="modalFinalizar" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-check" style="color: var(--success);"></i> Finalizar Tarea</h3>
            <form method="POST">
                <input type="hidden" name="id_tarea" id="idFinalizar">
                <div class="form-group">
                    <textarea name="comentario_final" placeholder="Comentario final..." required></textarea>
                </div>
                <div class="form-group">
                    <textarea name="solucion_aplicada" placeholder="Solución aplicada..." required></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" name="finalizar_tarea" class="btn btn-success">Finalizar</button>
                    <button type="button" onclick="cerrarModal('modalFinalizar')" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Comentario -->
    <div id="modalComentario" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-comment" style="color: var(--primary);"></i> Agregar Comentario</h3>
            <form method="POST">
                <input type="hidden" name="id_tarea" id="idComentario">
                <div class="form-group">
                    <textarea name="nuevo_comentario" placeholder="Escribe tu comentario..." required></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" name="agregar_comentario" class="btn btn-primary">Enviar</button>
                    <button type="button" onclick="cerrarModal('modalComentario')" class="btn btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">Sistema de Gestión de Tareas © <?= date('Y') ?></footer>
    
    <script>
        // Reloj en tiempo real
        function actualizarReloj(){
            const r = document.getElementById('reloj');
            if(r) r.textContent = new Date().toLocaleTimeString('es-GT', {
                hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false
            });
        }
        setInterval(actualizarReloj,1000);
        actualizarReloj();
        
        // Validar pausa
        function validarPausa(){
            const comentario = document.getElementById('comentario_pausa').value.trim();
            if (!comentario) {
                alert('Debe ingresar un motivo para pausar la tarea');
                return false;
            }
            return true;
        }
        
        // Funciones para modales
        function mostrarModal(modalId, inputId, tareaId) {
            const modal = document.getElementById(modalId);
            const input = document.getElementById(inputId);
            if (input) input.value = tareaId;
            
            const textarea = modal.querySelector('textarea');
            if (textarea) textarea.value = '';
            
            modal.style.display = 'flex';
        }
        
        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function mostrarTomar(id) { 
            mostrarModal('modalTomar', 'idTomar', id);
            setTimeout(() => {
                seleccionarAccion('solo_asignar');
            }, 100);
        }
        
        function mostrarInicio(id) { mostrarModal('modalInicio', 'idInicio', id); }
        function mostrarPausa(id) { mostrarModal('modalPausa', 'idPausa', id); }
        function mostrarFinalizar(id) { mostrarModal('modalFinalizar', 'idFinalizar', id); }
        function mostrarComentario(id) { mostrarModal('modalComentario', 'idComentario', id); }
        
        // Selección de acción en modal tomar
        function seleccionarAccion(accion) {
            document.getElementById('accionTomar').value = accion;
            
            const btnAsignar = document.getElementById('btnSoloAsignar');
            const btnIniciar = document.getElementById('btnAsignarIniciar');
            const campoAsignar = document.getElementById('campoComentarioAsignar');
            const campoInicio = document.getElementById('campoComentarioInicio');
            const btnConfirmar = document.getElementById('btnConfirmarTomar');
            
            if (accion === 'solo_asignar') {
                btnAsignar.style.background = 'var(--primary)';
                btnAsignar.style.border = '2px solid white';
                btnIniciar.style.background = 'var(--success)';
                btnIniciar.style.border = '2px solid transparent';
                campoAsignar.style.display = 'block';
                campoInicio.style.display = 'none';
                btnConfirmar.innerHTML = '<i class="fas fa-hand-pointer"></i> Asignar Tarea';
            } else {
                btnIniciar.style.background = 'var(--primary)';
                btnIniciar.style.border = '2px solid white';
                btnAsignar.style.background = 'var(--secondary)';
                btnAsignar.style.border = '2px solid transparent';
                campoAsignar.style.display = 'none';
                campoInicio.style.display = 'block';
                btnConfirmar.innerHTML = '<i class="fas fa-play"></i> Asignar e Iniciar';
            }
        }
        
        // Cerrar modales con ESC
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                cerrarModal('modalTomar');
                cerrarModal('modalInicio');
                cerrarModal('modalPausa');
                cerrarModal('modalFinalizar');
                cerrarModal('modalComentario');
            }
        });
        
        // Cerrar modales haciendo clic fuera
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) cerrarModal(this.id);
            });
        });
        
        // Auto-refresh cada 2 minutos solo si no hay modales abiertos
        setInterval(() => {
            const modalesAbiertos = Array.from(document.querySelectorAll('.modal'))
                .some(m => m.style.display === 'flex');
            if (!modalesAbiertos) {
                location.reload();
            }
        }, 120000);
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