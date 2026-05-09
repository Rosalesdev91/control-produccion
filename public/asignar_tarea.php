<?php
session_start();

// Validación de sesión
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

// Configuración directa de la base de datos
$host = 'localhost';
$dbname = 'produccion_quiebras';
$username = 'root';
$password = '';

try {
    // Cambiar a utf8mb4 que es más compatible
    $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Establecer collation correcta
    $conexion->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

date_default_timezone_set('America/Guatemala');

// Variable para controlar si se ha procesado alguna acción
$accion_procesada = false;

/* ===============================
   ELIMINAR TAREA (CON SUS DEPENDENCIAS)
=================================*/
if(isset($_POST['eliminar_tarea']) && isset($_POST['tarea_id_eliminar']) && !$accion_procesada) {
    $tarea_id = $_POST['tarea_id_eliminar'];
    
    try {
        // Iniciar transacción
        $conexion->beginTransaction();
        
        // 1. Eliminar registros de seguimiento
        $stmt = $conexion->prepare("DELETE FROM seguimiento WHERE tarea_id = ?");
        $stmt->execute([$tarea_id]);
        
        // 2. Eliminar registros de pausas
        $stmt = $conexion->prepare("DELETE FROM pausas_tarea WHERE tarea_id = ?");
        $stmt->execute([$tarea_id]);
        
        // 3. Eliminar tareas hijas (recurrentes) si existen
        $stmt = $conexion->prepare("DELETE FROM tareas WHERE tarea_origen_id = ?");
        $stmt->execute([$tarea_id]);
        
        // 4. Finalmente eliminar la tarea principal
        $stmt = $conexion->prepare("DELETE FROM tareas WHERE id = ?");
        $stmt->execute([$tarea_id]);
        
        // Confirmar transacción
        $conexion->commit();
        
        $_SESSION['mensaje_exito'] = 'eliminado';
        $accion_procesada = true;
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
        
    } catch(PDOException $e) {
        // Revertir transacción en caso de error
        $conexion->rollBack();
        error_log("Error al eliminar tarea: " . $e->getMessage());
        $_SESSION['mensaje_error'] = 'error_eliminar';
        $accion_procesada = true;
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    }
}

/* ===============================
   ACTUALIZAR TAREA (EDITAR)
=================================*/
if(isset($_POST['editar_tarea']) && !$accion_procesada){
    $tarea_id = $_POST['tarea_id'];
    $fecha_programada = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : date('Y-m-d H:i:s');
    $recurrente = isset($_POST['recurrente']) ? 1 : 0;
    $tipo_recurrencia = $_POST['tipo_recurrencia'] ?? null;
    $intervalo_recurrencia = $_POST['intervalo_recurrencia'] ?? 1;
    $dia_semana = $_POST['dia_semana'] ?? null;
    $dia_mes = $_POST['dia_mes'] ?? null;
    $fecha_fin_recurrencia = !empty($_POST['fecha_fin_recurrencia']) ? $_POST['fecha_fin_recurrencia'] : null;
    $recurrencia_indefinida = isset($_POST['recurrencia_indefinida']) ? 1 : 0;
    
    // Convertir "cualquiera" a NULL para la base de datos
    $tecnico_asignado = $_POST['tecnico'];
    if($tecnico_asignado === 'cualquiera') {
        $tecnico_asignado = null;
    }
    
    // Actualizar tarea principal
    $stmt = $conexion->prepare("UPDATE tareas SET 
        tipo_paro = ?,
        descripcion = ?,
        prioridad = ?,
        asignado_a = ?,
        equipo_id = ?,
        fecha_programada = ?,
        recurrente = ?,
        tipo_recurrencia = ?,
        intervalo_recurrencia = ?,
        dia_semana = ?,
        dia_mes = ?,
        fecha_fin_recurrencia = ?,
        recurrencia_indefinida = ?
        WHERE id = ?");
    
    $stmt->execute([
        $_POST['tipo_paro'],
        $_POST['descripcion'],
        $_POST['prioridad'],
        $tecnico_asignado,
        $_POST['equipo'] ?: null,
        $fecha_programada,
        $recurrente,
        $tipo_recurrencia,
        $intervalo_recurrencia,
        $dia_semana,
        $dia_mes,
        $fecha_fin_recurrencia,
        $recurrencia_indefinida,
        $tarea_id
    ]);
    
    // Registrar en seguimiento
    $comentario = "Tarea editada por administrador";
    if($tecnico_asignado === null) {
        $comentario .= " - Disponible para cualquier técnico";
    }
    $stmt_seg = $conexion->prepare("INSERT INTO seguimiento (tarea_id, estado_nuevo, comentario) VALUES (?, (SELECT estado FROM tareas WHERE id = ?), ?)");
    $stmt_seg->execute([$tarea_id, $tarea_id, $comentario]);
    
    $_SESSION['mensaje_exito'] = 'editado';
    $accion_procesada = true;
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

/* ===============================
   CREAR TAREA (con fecha programada y recurrencia)
=================================*/
if(isset($_POST['crear_tarea']) && !$accion_procesada){
    $fecha_programada = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : date('Y-m-d H:i:s');
    $recurrente = isset($_POST['recurrente']) ? 1 : 0;
    $tipo_recurrencia = $_POST['tipo_recurrencia'] ?? null;
    $intervalo_recurrencia = $_POST['intervalo_recurrencia'] ?? 1;
    $dia_semana = $_POST['dia_semana'] ?? null;
    $dia_mes = $_POST['dia_mes'] ?? null;
    $fecha_fin_recurrencia = !empty($_POST['fecha_fin_recurrencia']) ? $_POST['fecha_fin_recurrencia'] : null;
    $recurrencia_indefinida = isset($_POST['recurrencia_indefinida']) ? 1 : 0;
    
    // Convertir "cualquiera" a NULL para la base de datos
    $tecnico_asignado = $_POST['tecnico'];
    if($tecnico_asignado === 'cualquiera') {
        $tecnico_asignado = null;
    }
    
    // Insertar tarea principal
    $stmt = $conexion->prepare("INSERT INTO tareas (tipo_paro, descripcion, prioridad, asignado_a, equipo_id, fecha_programada, estado, recurrente, tipo_recurrencia, intervalo_recurrencia, dia_semana, dia_mes, fecha_fin_recurrencia, recurrencia_indefinida)
                                VALUES (?,?,?,?,?,?, 'pendiente', ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['tipo_paro'],
        $_POST['descripcion'],
        $_POST['prioridad'],
        $tecnico_asignado,
        $_POST['equipo'] ?: null,
        $fecha_programada,
        $recurrente,
        $tipo_recurrencia,
        $intervalo_recurrencia,
        $dia_semana,
        $dia_mes,
        $fecha_fin_recurrencia,
        $recurrencia_indefinida
    ]);
    
    $tarea_id = $conexion->lastInsertId();
    
// Si es recurrente, crear las siguientes ocurrencias hasta la fecha tope
if($recurrente && $tipo_recurrencia) {
    $fecha_base = new DateTime($fecha_programada);
    $fecha_fin = $recurrencia_indefinida ? null : ($fecha_fin_recurrencia ? new DateTime($fecha_fin_recurrencia) : null);
    
    // Variable para almacenar información adicional
    $comentario_instancias = '';
    
    // Validar que la fecha base no supere la fecha tope
    if($fecha_fin && $fecha_base > $fecha_fin) {
        $comentario_instancias = " - ADVERTENCIA: La fecha programada supera la fecha tope, no se crearon instancias recurrentes";
    } else {
        // Crear ocurrencias SOLO hasta la fecha tope, sin límite arbitrario
        $fecha_actual = clone $fecha_base;
        $contador = 0;
        
        while(true) {
            $fecha_siguiente = clone $fecha_actual;
            
            switch($tipo_recurrencia) {
                case 'semanal':
                    $fecha_siguiente->modify("+" . $intervalo_recurrencia . " week");
                    break;
                case 'mensual':
                    $fecha_siguiente->modify("+" . $intervalo_recurrencia . " month");
                    if($dia_mes) {
                        $fecha_siguiente->setDate(
                            $fecha_siguiente->format('Y'),
                            $fecha_siguiente->format('m'),
                            min($dia_mes, $fecha_siguiente->format('t'))
                        );
                    }
                    break;
                case 'trimestral':
                    $fecha_siguiente->modify("+" . ($intervalo_recurrencia * 3) . " month");
                    break;
                case 'semestral':
                    $fecha_siguiente->modify("+" . ($intervalo_recurrencia * 6) . " month");
                    break;
                case 'anual':
                    $fecha_siguiente->modify("+" . $intervalo_recurrencia . " year");
                    break;
                case 'bienal':
                    $fecha_siguiente->modify("+" . ($intervalo_recurrencia * 2) . " year");
                    break;
            }
            
            // Verificar si superó la fecha tope
            if($fecha_fin && $fecha_siguiente > $fecha_fin) {
                break;
            }
            
            // Verificar que no sea una fecha extremadamente lejana (seguridad: máximo 50 años)
            $fecha_limite_seguridad = new DateTime('+50 years');
            if($fecha_siguiente > $fecha_limite_seguridad) {
                break;
            }
            
            $stmt_recurrente = $conexion->prepare("INSERT INTO tareas (tipo_paro, descripcion, prioridad, asignado_a, equipo_id, fecha_programada, estado, recurrente, tipo_recurrencia, intervalo_recurrencia, dia_semana, dia_mes, fecha_fin_recurrencia, recurrencia_indefinida, tarea_origen_id)
                                                  VALUES (?,?,?,?,?,?, 'pendiente', 1, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_recurrente->execute([
                $_POST['tipo_paro'],
                $_POST['descripcion'] . " [Recurrente]",
                $_POST['prioridad'],
                $tecnico_asignado,
                $_POST['equipo'] ?: null,
                $fecha_siguiente->format('Y-m-d H:i:s'),
                $tipo_recurrencia,
                $intervalo_recurrencia,
                $dia_semana,
                $dia_mes,
                $fecha_fin_recurrencia,
                $recurrencia_indefinida,
                $tarea_id
            ]);
            
            $fecha_actual = $fecha_siguiente;
            $contador++;
        }
        
        $comentario_instancias = $contador > 0 ? " - Se crearon $contador instancia(s) recurrente(s)" : " - No se crearon instancias recurrentes (fecha límite muy próxima)";
    }
}

// Registrar en seguimiento
$comentario = $recurrente ? "Tarea recurrente creada (cada " . $intervalo_recurrencia . " " . $tipo_recurrencia . ")" : "Tarea creada";
if($recurrencia_indefinida) {
    $comentario .= " - Sin fecha tope";
} elseif($fecha_fin_recurrencia) {
    $comentario .= " - Hasta: " . date('d/m/Y', strtotime($fecha_fin_recurrencia));
}
if($tecnico_asignado === null) {
    $comentario .= " - Disponible para cualquier técnico";
}
// Siempre agregar info de instancias (ahora siempre existe)
$comentario .= $comentario_instancias;

$stmt_seg = $conexion->prepare("INSERT INTO seguimiento (tarea_id, estado_nuevo, comentario) VALUES (?, 'pendiente', ?)");
$stmt_seg->execute([$tarea_id, $comentario]);

$_SESSION['mensaje_exito'] = 'success' . ($recurrente ? '_recurrente' : '');
$accion_procesada = true;
header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
exit();
}

/* ===============================
   VERIFICAR Y CREAR TAREAS RECURRENTES AUTOMÁTICAMENTE
=================================*/
function verificarYCrearRecurrentes($conexion) {
    // Buscar tareas recurrentes que necesitan su PRÓXIMA instancia
    $stmt = $conexion->prepare("
        SELECT t.* 
        FROM tareas t
        WHERE t.recurrente = 1 
        AND t.estado != 'cancelada'
        AND (
            t.recurrencia_indefinida = 1 
            OR t.fecha_fin_recurrencia IS NULL 
            OR t.fecha_fin_recurrencia > NOW()
        )
        AND t.fecha_programada < NOW()
        AND NOT EXISTS (
            SELECT 1 FROM tareas t2 
            WHERE t2.tarea_origen_id = t.id 
            AND t2.fecha_programada > NOW()
        )
        LIMIT 50
    ");
    $stmt->execute();
    $tareas_recurrentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($tareas_recurrentes as $tarea) {
        // Calcular la SIGUIENTE fecha (solo una)
        $fecha_actual = new DateTime();
        $fecha_programada = new DateTime($tarea['fecha_programada']);
        
        $proxima_fecha = clone $fecha_actual;
        
        switch($tarea['tipo_recurrencia']) {
            case 'semanal':
                if($tarea['dia_semana']) {
                    $proxima_fecha->modify("next " . $tarea['dia_semana']);
                } else {
                    $proxima_fecha->modify("+" . $tarea['intervalo_recurrencia'] . " week");
                }
                break;
                
            case 'mensual':
                $proxima_fecha->modify("+" . $tarea['intervalo_recurrencia'] . " month");
                if($tarea['dia_mes']) {
                    $ultimo_dia = $proxima_fecha->format('t');
                    $dia = min($tarea['dia_mes'], $ultimo_dia);
                    $proxima_fecha->setDate(
                        $proxima_fecha->format('Y'),
                        $proxima_fecha->format('m'),
                        $dia
                    );
                }
                break;
                
            case 'trimestral':
                $proxima_fecha->modify("+" . ($tarea['intervalo_recurrencia'] * 3) . " month");
                break;
                
            case 'semestral':
                $proxima_fecha->modify("+" . ($tarea['intervalo_recurrencia'] * 6) . " month");
                break;
                
            case 'anual':
                $proxima_fecha->modify("+" . $tarea['intervalo_recurrencia'] . " year");
                $proxima_fecha->setDate(
                    $proxima_fecha->format('Y'),
                    (int)$fecha_programada->format('m'),
                    min((int)$fecha_programada->format('d'), $proxima_fecha->format('t'))
                );
                break;
                
            case 'bienal':
                $proxima_fecha->modify("+" . ($tarea['intervalo_recurrencia'] * 2) . " year");
                $proxima_fecha->setDate(
                    $proxima_fecha->format('Y'),
                    (int)$fecha_programada->format('m'),
                    min((int)$fecha_programada->format('d'), $proxima_fecha->format('t'))
                );
                break;
        }
        
        // Mantener la misma hora
        $proxima_fecha->setTime(
            (int)$fecha_programada->format('H'),
            (int)$fecha_programada->format('i'),
            (int)$fecha_programada->format('s')
        );
        
        // Verificar si la fecha supera la fecha tope
        $excede_tope = false;
        if(!$tarea['recurrencia_indefinida'] && $tarea['fecha_fin_recurrencia']) {
            $fecha_tope = new DateTime($tarea['fecha_fin_recurrencia']);
            if($proxima_fecha > $fecha_tope) {
                $excede_tope = true;
            }
        }
        
        // Solo crear si la fecha es futura y no excede la fecha tope
        if($proxima_fecha > $fecha_actual && !$excede_tope) {
            $stmt_insert = $conexion->prepare("
                INSERT INTO tareas (
                    tipo_paro, descripcion, prioridad, asignado_a, 
                    equipo_id, fecha_programada, estado, recurrente, 
                    tipo_recurrencia, intervalo_recurrencia, dia_semana, dia_mes, 
                    fecha_fin_recurrencia, recurrencia_indefinida, tarea_origen_id
                ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', 1, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert->execute([
                $tarea['tipo_paro'],
                $tarea['descripcion'],
                $tarea['prioridad'],
                $tarea['asignado_a'],
                $tarea['equipo_id'],
                $proxima_fecha->format('Y-m-d H:i:s'),
                $tarea['tipo_recurrencia'],
                $tarea['intervalo_recurrencia'],
                $tarea['dia_semana'],
                $tarea['dia_mes'],
                $tarea['fecha_fin_recurrencia'],
                $tarea['recurrencia_indefinida'],
                $tarea['id']
            ]);
        }
    }
}

// Ejecutar verificación de recurrentes
verificarYCrearRecurrentes($conexion);

/* ===============================
   OBTENER DATOS CON FILTRO DE FECHA
=================================*/
// Obtener el inicio y fin de la semana actual (lunes a domingo)
$fecha_actual = new DateTime();
$fecha_inicio_semana = clone $fecha_actual;
$fecha_inicio_semana->modify('monday this week');
$fecha_fin_semana = clone $fecha_inicio_semana;
$fecha_fin_semana->modify('+6 days');

$fecha_desde = $_GET['fecha_desde'] ?? $fecha_inicio_semana->format('Y-m-d');
$fecha_hasta = $_GET['fecha_hasta'] ?? $fecha_fin_semana->format('Y-m-d');
$vista = $_GET['vista'] ?? 'semana'; // Cambiamos el valor por defecto a 'semana'
$tecnico_filtro = $_GET['tecnico'] ?? '';
$mostrar_recurrentes = isset($_GET['recurrentes']) ? $_GET['recurrentes'] : 'todas';

// Obtener tarea específica para editar (si se solicita)
$tarea_editar = null;
if(isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $stmt_editar = $conexion->prepare("SELECT * FROM tareas WHERE id = ?");
    $stmt_editar->execute([$_GET['editar']]);
    $tarea_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// CONSULTA PRINCIPAL
$sql_tareas = "
    SELECT t.*, 
           CASE 
               WHEN t.asignado_a IS NULL THEN '👥 Cualquier técnico'
               ELSE tec.nombre_tecnico 
           END as tecnico_nombre,
           tp.nombre as tipo_paro_nombre,
           eq.nombre_equipo as equipo_nombre,
           (SELECT COUNT(*) FROM seguimiento WHERE tarea_id = t.id) as total_comentarios,
           t_origen.tipo_paro as tarea_origen_nombre,
           
           -- TIEMPO TOTAL DE PAUSAS
           COALESCE(
               (SELECT SUM(TIMESTAMPDIFF(MINUTE, fecha_pausa, COALESCE(fecha_reanudacion, NOW()))) 
                FROM pausas_tarea WHERE tarea_id = t.id), 0) as tiempo_total_pausado,
           
           -- TIEMPO TOTAL DE EJECUCIÓN
           CASE 
               WHEN t.fecha_inicio IS NOT NULL AND t.fecha_fin IS NOT NULL THEN 
                   TIMESTAMPDIFF(MINUTE, t.fecha_inicio, t.fecha_fin)
               ELSE NULL 
           END as tiempo_total_ejecucion,
           
           -- TIEMPO DE PAUSA ACTUAL
           CASE 
               WHEN t.estado = 'pausada' AND t.ultima_pausa IS NOT NULL THEN 
                   TIMESTAMPDIFF(MINUTE, t.ultima_pausa, NOW())
               ELSE 0 
           END as tiempo_pausa_actual,
           
           -- INDICADOR SI ES PROGRAMADA
           CASE 
               WHEN t.fecha_programada > NOW() AND t.estado = 'pendiente' THEN 1
               ELSE 0
           END as es_programada,
           
           -- TIEMPO RESTANTE PARA PROGRAMADAS
           CASE 
               WHEN t.fecha_programada > NOW() AND t.estado = 'pendiente' THEN 
                   TIMESTAMPDIFF(MINUTE, NOW(), t.fecha_programada)
               ELSE 0
           END as minutos_para_inicio
           
    FROM tareas t 
    LEFT JOIN tecnicos tec ON t.asignado_a = tec.id
    LEFT JOIN equipos eq ON t.equipo_id = eq.id
    LEFT JOIN tipos_tarea tp ON t.tipo_paro = tp.nombre
    LEFT JOIN tareas t_origen ON t.tarea_origen_id = t_origen.id
    WHERE 1=1
";

// Array para parámetros
$params = array();

// Aplicar filtros según la vista seleccionada
if($vista == 'hoy') {
    $sql_tareas .= " AND DATE(t.fecha_programada) = CURDATE()";
} elseif($vista == 'programadas') {
    $sql_tareas .= " AND t.fecha_programada > NOW() AND t.estado = 'pendiente'";
} elseif($vista == 'recurrentes') {
    $sql_tareas .= " AND t.recurrente = 1";
} elseif($vista == 'semana') {
    // Mostrar tareas de la semana actual (lunes a domingo)
    $sql_tareas .= " AND DATE(t.fecha_programada) BETWEEN :fecha_desde AND :fecha_hasta";
    $params[':fecha_desde'] = $fecha_desde;
    $params[':fecha_hasta'] = $fecha_hasta;
} else {
    $sql_tareas .= " AND DATE(t.fecha_creacion) BETWEEN :fecha_desde AND :fecha_hasta";
    $params[':fecha_desde'] = $fecha_desde;
    $params[':fecha_hasta'] = $fecha_hasta;
}

// Filtro por tipo de tarea recurrente
if($mostrar_recurrentes == 'si') {
    $sql_tareas .= " AND t.recurrente = 1";
} elseif($mostrar_recurrentes == 'no') {
    $sql_tareas .= " AND (t.recurrente = 0 OR t.recurrente IS NULL)";
}

// Filtro por técnico
if(!empty($tecnico_filtro)) {
    if($tecnico_filtro === 'cualquiera') {
        $sql_tareas .= " AND t.asignado_a IS NULL";
    } else {
        $sql_tareas .= " AND t.asignado_a = :tecnico";
        $params[':tecnico'] = $tecnico_filtro;
    }
}

$sql_tareas .= " ORDER BY 
    CASE 
        WHEN t.fecha_programada > NOW() AND t.estado = 'pendiente' THEN 0
        ELSE 1
    END,
    t.fecha_programada ASC,
    t.fecha_creacion DESC";

$stmt_tareas = $conexion->prepare($sql_tareas);

$params = [];
if($vista == 'semana' || $vista == 'todas') {
    $params[':fecha_desde'] = $fecha_desde;
    $params[':fecha_hasta'] = $fecha_hasta;
}
if(!empty($tecnico_filtro) && $tecnico_filtro !== 'cualquiera') {
    $params[':tecnico'] = $tecnico_filtro;
}

$stmt_tareas->execute($params);
$tareas = $stmt_tareas->fetchAll(PDO::FETCH_ASSOC);

// Obtener técnicos activos
$tecnicos = $conexion->query("
    SELECT id, nombre_tecnico as nombre 
    FROM tecnicos 
    WHERE activo = 1 OR activo IS NULL
    ORDER BY nombre_tecnico
")->fetchAll(PDO::FETCH_ASSOC);

// Obtener equipos
$equipos = $conexion->query("
    SELECT id, nombre_equipo 
    FROM equipos 
    ORDER BY nombre_equipo
")->fetchAll(PDO::FETCH_ASSOC);

// Obtener tipos de paro
$tipos_tarea = $conexion->query("
    SELECT id, nombre
    FROM tipos_tarea 
    ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas generales CON LOS MISMOS FILTROS
$sql_stats = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN recurrente = 1 THEN 1 ELSE 0 END) as total_recurrentes,
        SUM(CASE WHEN recurrente = 1 AND tipo_recurrencia = 'semanal' THEN 1 ELSE 0 END) as recurrentes_semanales,
        SUM(CASE WHEN recurrente = 1 AND tipo_recurrencia = 'mensual' THEN 1 ELSE 0 END) as recurrentes_mensuales,
        SUM(CASE WHEN recurrente = 1 AND tipo_recurrencia = 'trimestral' THEN 1 ELSE 0 END) as recurrentes_trimestrales,
        SUM(CASE WHEN recurrente = 1 AND tipo_recurrencia = 'semestral' THEN 1 ELSE 0 END) as recurrentes_semestrales,
        SUM(CASE WHEN recurrente = 1 AND tipo_recurrencia = 'anual' THEN 1 ELSE 0 END) as recurrentes_anuales,
        SUM(CASE WHEN recurrente = 1 AND tipo_recurrencia = 'bienal' THEN 1 ELSE 0 END) as recurrentes_bienales,
        SUM(CASE WHEN estado = 'pendiente' AND fecha_programada <= NOW() THEN 1 ELSE 0 END) as pendientes_ahora,
        SUM(CASE WHEN estado = 'pendiente' AND fecha_programada > NOW() THEN 1 ELSE 0 END) as programadas,
        SUM(CASE WHEN estado = 'asignada' THEN 1 ELSE 0 END) as asignadas,
        SUM(CASE WHEN estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso,
        SUM(CASE WHEN estado = 'pausada' THEN 1 ELSE 0 END) as pausadas,
        SUM(CASE WHEN estado = 'finalizada' THEN 1 ELSE 0 END) as finalizadas,
        SUM(CASE WHEN estado = 'finalizada' AND DATE(fecha_fin) = CURDATE() THEN 1 ELSE 0 END) as finalizadas_hoy,
        SUM(CASE WHEN DATE(fecha_programada) = CURDATE() THEN 1 ELSE 0 END) as tareas_hoy,
        SUM(CASE WHEN asignado_a IS NULL THEN 1 ELSE 0 END) as tareas_cualquier_tecnico,
        AVG(CASE 
            WHEN estado = 'finalizada' AND fecha_inicio IS NOT NULL AND fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, fecha_inicio, fecha_fin) 
            ELSE NULL 
        END) as tiempo_promedio_minutos,
        SUM(CASE 
            WHEN estado = 'finalizada' AND fecha_inicio IS NOT NULL AND fecha_fin IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, fecha_inicio, fecha_fin) 
            ELSE 0 
        END) as tiempo_total_minutos
    FROM tareas t
    WHERE 1=1
";

// Aplicar los MISMOS filtros que a la lista de tareas
if($vista == 'hoy') {
    $sql_stats .= " AND DATE(t.fecha_programada) = CURDATE()";
} elseif($vista == 'programadas') {
    $sql_stats .= " AND t.fecha_programada > NOW() AND t.estado = 'pendiente'";
} elseif($vista == 'recurrentes') {
    $sql_stats .= " AND t.recurrente = 1";
} elseif($vista == 'semana') {
    $sql_stats .= " AND DATE(t.fecha_programada) BETWEEN :fecha_desde_stats AND :fecha_hasta_stats";
} else {
    $sql_stats .= " AND DATE(t.fecha_creacion) BETWEEN :fecha_desde_stats AND :fecha_hasta_stats";
}

// Filtro por técnico
if(!empty($tecnico_filtro)) {
    if($tecnico_filtro === 'cualquiera') {
        $sql_stats .= " AND t.asignado_a IS NULL";
    } else {
        $sql_stats .= " AND t.asignado_a = :tecnico_stats";
    }
}

$stmt_stats = $conexion->prepare($sql_stats);

// Preparar parámetros
$params_stats = [];
if($vista == 'semana' || $vista == 'todas') {
    $params_stats[':fecha_desde_stats'] = $fecha_desde;
    $params_stats[':fecha_hasta_stats'] = $fecha_hasta;
}
if(!empty($tecnico_filtro) && $tecnico_filtro !== 'cualquiera') {
    $params_stats[':tecnico_stats'] = $tecnico_filtro;
}

$stmt_stats->execute($params_stats);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Estadísticas por técnico - VERSIÓN CORREGIDA
try {
    // Primero intentamos con una consulta más simple para evitar problemas de collation
    $sql_stats_tecnicos = "
        SELECT 
            tec.id,
            tec.nombre_tecnico as nombre,
            COUNT(t.id) as total_tareas,
            SUM(CASE WHEN t.recurrente = 1 THEN 1 ELSE 0 END) as tareas_recurrentes,
            SUM(CASE WHEN t.recurrente = 1 AND t.tipo_recurrencia = 'semanal' THEN 1 ELSE 0 END) as recurrentes_semanales,
            SUM(CASE WHEN t.recurrente = 1 AND t.tipo_recurrencia = 'mensual' THEN 1 ELSE 0 END) as recurrentes_mensuales,
            SUM(CASE WHEN t.recurrente = 1 AND t.tipo_recurrencia = 'trimestral' THEN 1 ELSE 0 END) as recurrentes_trimestrales,
            SUM(CASE WHEN t.recurrente = 1 AND t.tipo_recurrencia = 'semestral' THEN 1 ELSE 0 END) as recurrentes_semestrales,
            SUM(CASE WHEN t.recurrente = 1 AND t.tipo_recurrencia = 'anual' THEN 1 ELSE 0 END) as recurrentes_anuales,
            SUM(CASE WHEN t.recurrente = 1 AND t.tipo_recurrencia = 'bienal' THEN 1 ELSE 0 END) as recurrentes_bienales,
            SUM(CASE WHEN t.estado = 'pendiente' AND t.fecha_programada <= NOW() THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN t.estado = 'pendiente' AND t.fecha_programada > NOW() THEN 1 ELSE 0 END) as programadas,
            SUM(CASE WHEN t.estado = 'asignada' THEN 1 ELSE 0 END) as asignadas,
            SUM(CASE WHEN t.estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso,
            SUM(CASE WHEN t.estado = 'pausada' THEN 1 ELSE 0 END) as pausadas,
            SUM(CASE WHEN t.estado = 'finalizada' THEN 1 ELSE 0 END) as finalizadas,
            AVG(CASE 
                WHEN t.estado = 'finalizada' AND t.fecha_inicio IS NOT NULL AND t.fecha_fin IS NOT NULL 
                THEN TIMESTAMPDIFF(MINUTE, t.fecha_inicio, t.fecha_fin) 
                ELSE NULL 
            END) as tiempo_promedio_minutos,
            SUM(CASE 
                WHEN t.estado = 'finalizada' AND t.fecha_inicio IS NOT NULL AND t.fecha_fin IS NOT NULL 
                THEN TIMESTAMPDIFF(MINUTE, t.fecha_inicio, t.fecha_fin) 
                ELSE 0 
            END) as tiempo_total_minutos,
            COALESCE(
                (SELECT SUM(TIMESTAMPDIFF(MINUTE, p.fecha_pausa, COALESCE(p.fecha_reanudacion, NOW()))) 
                 FROM pausas_tarea p 
                 INNER JOIN tareas ta ON p.tarea_id = ta.id 
                 WHERE ta.asignado_a = tec.id), 0) as tiempo_total_pausado
        FROM tecnicos tec
        LEFT JOIN tareas t ON tec.id = t.asignado_a 
        WHERE tec.activo = 1 OR tec.activo IS NULL
        GROUP BY tec.id, tec.nombre_tecnico
        ORDER BY total_tareas DESC
    ";
    
    $stmt_stats_tecnicos = $conexion->prepare($sql_stats_tecnicos);
    $stmt_stats_tecnicos->execute();
    $stats_tecnicos = $stmt_stats_tecnicos->fetchAll(PDO::FETCH_ASSOC);
    
    // Si la consulta anterior falla, usamos una versión aún más simple
    if(empty($stats_tecnicos)) {
        // Consulta simplificada sin los CASE WHEN complejos
        $sql_simple = "
            SELECT 
                tec.id,
                tec.nombre_tecnico as nombre,
                COUNT(t.id) as total_tareas
            FROM tecnicos tec
            LEFT JOIN tareas t ON tec.id = t.asignado_a 
            WHERE tec.activo = 1 OR tec.activo IS NULL
            GROUP BY tec.id, tec.nombre_tecnico
        ";
        $stmt_simple = $conexion->prepare($sql_simple);
        $stmt_simple->execute();
        $stats_tecnicos = $stmt_simple->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch(PDOException $e) {
    // Si hay error, mostramos un array vacío
    $stats_tecnicos = [];
    error_log("Error en estadísticas de técnicos: " . $e->getMessage());
}

// Formatear tiempos
$tiempo_promedio = $stats['tiempo_promedio_minutos'] ? round($stats['tiempo_promedio_minutos']) : 0;
$tiempo_total = $stats['tiempo_total_minutos'] ?? 0;

// Calcular la semana actual para mostrar en el resumen
$semana_inicio = new DateTime($fecha_desde);
$semana_fin = new DateTime($fecha_hasta);
$periodo_display = "Semana del " . $semana_inicio->format('d/m/Y') . " al " . $semana_fin->format('d/m/Y');

// Días de la semana en español
$dias_semana = [
    'Monday' => 'Lunes',
    'Tuesday' => 'Martes',
    'Wednesday' => 'Miércoles',
    'Thursday' => 'Jueves',
    'Friday' => 'Viernes',
    'Saturday' => 'Sábado',
    'Sunday' => 'Domingo'
];

// Meses en español
$meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// Mapeo de tipos de recurrencia a texto en español
$recurrencia_textos = [
    'semanal' => 'Semanal',
    'mensual' => 'Mensual',
    'trimestral' => 'Trimestral',
    'semestral' => 'Semestral',
    'anual' => 'Anual',
    'bienal' => 'Bienal'
];

// Mapeo de iconos por tipo de recurrencia
$recurrencia_iconos = [
    'semanal' => 'fa-calendar-week',
    'mensual' => 'fa-calendar-alt',
    'trimestral' => 'fa-calendar',
    'semestral' => 'fa-calendar-check',
    'anual' => 'fa-calendar-year',
    'bienal' => 'fa-calendar-plus'
];

// Recuperar mensajes de sesión y limpiarlos
$mensaje_exito = isset($_SESSION['mensaje_exito']) ? $_SESSION['mensaje_exito'] : null;
$mensaje_error = isset($_SESSION['mensaje_error']) ? $_SESSION['mensaje_error'] : null;
unset($_SESSION['mensaje_exito']);
unset($_SESSION['mensaje_error']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tareas Técnicas</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;padding:20px}.container{max-width:1600px;margin:0 auto}.main-header{background:rgba(255,255,255,0.95);backdrop-filter:blur(10px);border-radius:20px;padding:25px 30px;margin-bottom:30px;box-shadow:0 20px 40px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center}.main-header h1{font-size:2em;color:#2d3748;display:flex;align-items:center;gap:12px}.main-header h1 i{color:#667eea;font-size:1.2em}.date-badge{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:10px 20px;border-radius:12px;font-size:0.9em;display:flex;align-items:center;gap:8px}.alert{padding:20px;border-radius:16px;margin-bottom:25px;display:flex;align-items:center;gap:15px;animation:slideDown .3s ease}@keyframes slideDown{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}.alert-success{background:linear-gradient(135deg,#48bb78 0%,#38a169 100%);color:white;box-shadow:0 10px 20px rgba(72,187,120,0.2)}.alert-danger{background:linear-gradient(135deg,#f56565 0%,#e53e3e 100%);color:white;box-shadow:0 10px 20px rgba(245,101,101,0.2)}.alert i{font-size:1.5em}.alert-content{flex:1}.alert-title{font-weight:600;font-size:1.1em;margin-bottom:5px}.alert-close{background:rgba(255,255,255,0.2);border:none;color:white;width:30px;height:30px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .3s}.alert-close:hover{background:rgba(255,255,255,0.3);transform:scale(1.1)}.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;margin-bottom:30px}.stat-card{background:white;border-radius:20px;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,0.1);transition:all .3s;border:1px solid rgba(255,255,255,0.2);position:relative;overflow:hidden}.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#667eea,#764ba2)}.stat-card:hover{transform:translateY(-5px);box-shadow:0 20px 40px rgba(0,0,0,0.15)}.stat-icon{width:45px;height:45px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:15px}.stat-icon i{font-size:1.5em}.stat-number{font-size:2em;font-weight:700;color:#2d3748;line-height:1.2}.stat-label{color:#718096;font-size:.9em;font-weight:500;margin-top:5px}.stat-trend{margin-top:10px;font-size:.85em;color:#48bb78}.filter-section{background:white;border-radius:20px;padding:25px;margin-bottom:30px;box-shadow:0 10px 30px rgba(0,0,0,0.1)}.view-tabs{display:flex;gap:10px;margin-bottom:25px;flex-wrap:wrap}.view-tab{padding:12px 25px;border-radius:12px;text-decoration:none;color:#4a5568;font-weight:600;transition:all .3s;background:#f7fafc;display:flex;align-items:center;gap:8px}.view-tab i{font-size:1em}.view-tab:hover{background:#edf2f7;transform:translateY(-2px)}.view-tab.active{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;box-shadow:0 10px 20px rgba(102,126,234,0.3)}.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;align-items:end}.filter-group{display:flex;flex-direction:column;gap:8px}.filter-group label{font-weight:600;color:#4a5568;font-size:.9em;display:flex;align-items:center;gap:5px}.filter-group input,.filter-group select{padding:12px 15px;border:2px solid #e2e8f0;border-radius:12px;font-size:.95em;transition:all .3s;background:#f7fafc}.filter-group input:focus,.filter-group select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.1)}.filter-actions{display:flex;gap:10px;align-items:center}.btn{padding:12px 25px;border:none;border-radius:12px;font-weight:600;cursor:pointer;transition:all .3s;display:inline-flex;align-items:center;gap:8px;font-size:.95em}.btn-primary{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;box-shadow:0 10px 20px rgba(102,126,234,0.3)}.btn-primary:hover{transform:translateY(-2px);box-shadow:0 15px 30px rgba(102,126,234,0.4)}.btn-secondary{background:#f7fafc;color:#4a5568;border:2px solid #e2e8f0}.btn-secondary:hover{background:#edf2f7;transform:translateY(-2px)}.btn-warning{background:#fbbf24;color:#1f2937;border:2px solid #f59e0b}.btn-warning:hover{background:#f59e0b;transform:translateY(-2px)}.btn-danger{background:#f56565;color:white;border:2px solid #e53e3e}.btn-danger:hover{background:#e53e3e;transform:translateY(-2px)}.summary-badge{margin-top:20px;padding:15px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:12px;color:white;display:flex;align-items:center;gap:15px;flex-wrap:wrap}.summary-item{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.1);padding:8px 15px;border-radius:30px}.task-form{background:white;border-radius:20px;padding:30px;margin-bottom:30px;box-shadow:0 10px 30px rgba(0,0,0,0.1)}.task-form h2{color:#2d3748;margin-bottom:25px;display:flex;align-items:center;gap:10px;font-size:1.5em}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:20px}.form-group{display:flex;flex-direction:column;gap:8px}.form-group label{font-weight:600;color:#4a5568;display:flex;align-items:center;gap:6px}.form-group input,.form-group select,.form-group textarea{padding:12px 15px;border:2px solid #e2e8f0;border-radius:12px;font-size:.95em;transition:all .3s;background:#f7fafc}.form-group textarea{min-height:100px;resize:vertical}.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.1)}.recurrente-wrapper{background:#f7fafc;padding:15px;border-radius:12px;border:2px solid #e2e8f0}.checkbox-group{display:flex;align-items:center;gap:10px;margin-bottom:15px}.checkbox-group input[type="checkbox"]{width:20px;height:20px;cursor:pointer}.checkbox-group label{cursor:pointer;font-weight:600;color:#4a5568}.recurrente-options{padding:20px;background:white;border-radius:12px;margin-top:15px;border:2px solid #e2e8f0}.recurrencia-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px}.recurrencia-item{display:flex;flex-direction:column;gap:8px}.recurrencia-item label{font-weight:600;color:#4a5568;font-size:.9em}.recurrencia-item select{padding:10px;border:2px solid #e2e8f0;border-radius:8px;background:#f7fafc}.recurrencia-item input[type="date"]{padding:10px;border:2px solid #e2e8f0;border-radius:8px;background:#f7fafc}.radio-group{display:flex;gap:20px;margin-top:5px}.radio-group label{display:flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer}.radio-group input[type="radio"]{width:auto;margin:0}.tecnicos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:25px;margin:25px 0}.tecnico-card{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:20px;padding:25px;color:white;box-shadow:0 20px 40px rgba(0,0,0,0.2);transition:all .3s;position:relative;overflow:hidden}.tecnico-card::after{content:'';position:absolute;top:0;right:0;width:150px;height:150px;background:radial-gradient(circle,rgba(255,255,255,0.1) 0%,transparent 70%);border-radius:50%}.tecnico-card:hover{transform:translateY(-5px);box-shadow:0 30px 50px rgba(0,0,0,0.3)}.tecnico-header{display:flex;align-items:center;gap:15px;margin-bottom:20px;position:relative;z-index:1}.tecnico-avatar{width:60px;height:60px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2em}.tecnico-info{flex:1}.tecnico-info h3{font-size:1.3em;margin-bottom:5px}.tecnico-total{background:rgba(255,255,255,0.2);padding:4px 12px;border-radius:20px;font-size:.85em}.tecnico-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:20px;position:relative;z-index:1}.stat-mini{text-align:center;padding:10px;background:rgba(255,255,255,0.1);border-radius:12px}.stat-mini-value{font-size:1.4em;font-weight:700;margin-bottom:5px}.stat-mini-label{font-size:.8em;opacity:.9}.tecnico-progress{margin:15px 0;position:relative;z-index:1}.progress-label{display:flex;justify-content:space-between;margin-bottom:8px;font-size:.9em}.progress-bar{height:8px;background:rgba(255,255,255,0.2);border-radius:4px;overflow:hidden}.progress-fill{height:100%;background:white;border-radius:4px;transition:width .3s ease}.tecnico-badges{display:flex;gap:10px;flex-wrap:wrap;position:relative;z-index:1}.badge-mini{background:rgba(255,255,255,0.15);padding:6px 12px;border-radius:20px;font-size:.85em;display:flex;align-items:center;gap:6px}.tasks-table-container{background:white;border-radius:20px;padding:25px;box-shadow:0 10px 30px rgba(0,0,0,0.1);overflow:hidden}.tasks-table-container h2{color:#2d3748;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between}.table-responsive{overflow-x:auto;border-radius:12px;border:1px solid #e2e8f0}table{width:100%;border-collapse:collapse;min-width:1200px}th{background:#f7fafc;padding:15px;text-align:left;font-weight:600;color:#4a5568;border-bottom:2px solid #e2e8f0;white-space:nowrap}td{padding:15px;border-bottom:1px solid #e2e8f0;vertical-align:middle}tr:hover{background:#f7fafc}tr.atrasada{background:#fff5f5}.badge{padding:5px 12px;border-radius:30px;font-size:.85em;font-weight:600;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}.estado-pendiente{background:#a0aec0;color:white}.estado-programada{background:#9f7aea;color:white}.estado-en_proceso{background:#fbbf24;color:#1f2937}.estado-pausada{background:#f97316;color:white}.estado-finalizada{background:#34d399;color:white}.estado-asignada{background:#60a5fa;color:white}.prioridad{padding:4px 10px;border-radius:30px;font-size:.8em;font-weight:600}.prioridad-baja{background:#d1fae5;color:#065f46}.prioridad-media{background:#fef3c7;color:#92400e}.prioridad-alta{background:#fee2e2;color:#991b1b}.prioridad-critica{background:#fecaca;color:#7f1d1d}.recurrente-badge{background:#9f7aea;color:white;padding:2px 8px;border-radius:30px;font-size:.75em;margin-left:5px}.equipo-tag{background:#e2e8f0;padding:4px 10px;border-radius:20px;font-size:.85em;color:#2d3748;display:inline-flex;align-items:center;gap:5px}.tiempo-container{display:flex;flex-direction:column;gap:5px}.tiempo-item{font-size:.85em;display:flex;align-items:center;gap:5px}.tiempo-ejecucion{color:#2d3748}.tiempo-pausa{color:#f97316}.tiempo-pausa-actual{color:#ef4444;font-weight:600}.tiempo-programada{color:#9f7aea}.historial-mini{background:#f7fafc;padding:8px;border-radius:8px;font-size:.8em;max-height:80px;overflow-y:auto}.historial-item{padding:4px 0;border-bottom:1px dashed #e2e8f0;display:flex;gap:5px}.historial-item:last-child{border-bottom:none}.historial-fecha{color:#718096;font-size:.75em;white-space:nowrap}.table-footer{margin-top:20px;display:flex;justify-content:space-between;align-items:center;padding:15px;background:#f7fafc;border-radius:12px}.action-buttons{display:flex;gap:8px}.btn-icon{padding:8px 12px;border:none;border-radius:8px;cursor:pointer;transition:all .3s;font-size:.9em;display:inline-flex;align-items:center;gap:5px}.btn-icon-edit{background:#667eea;color:white}.btn-icon-edit:hover{background:#5a67d8;transform:scale(1.05)}.btn-icon-delete{background:#f56565;color:white}.btn-icon-delete:hover{background:#e53e3e;transform:scale(1.05)}.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;justify-content:center;align-items:center}.modal.active{display:flex}.modal-content{background:white;border-radius:20px;max-width:800px;width:90%;max-height:90vh;overflow-y:auto;padding:30px;animation:slideUp .3s ease}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid #e2e8f0}.modal-header h3{color:#2d3748;font-size:1.5em;display:flex;align-items:center;gap:10px}.modal-close{background:none;border:none;font-size:1.5em;cursor:pointer;color:#a0aec0;transition:all .3s}.modal-close:hover{color:#4a5568;transform:rotate(90deg)}.modal-footer{display:flex;gap:10px;margin-top:20px;justify-content:flex-end}.modal-footer .btn{flex:0 0 auto}@keyframes slideUp{from{transform:translateY(50px);opacity:0}to{transform:translateY(0);opacity:1}}@media (max-width:1024px){.stats-grid{grid-template-columns:repeat(4,1fr)}}@media (max-width:768px){.main-header{flex-direction:column;gap:15px;text-align:center}.stats-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:1fr}.view-tabs{justify-content:center}.tecnicos-grid{grid-template-columns:1fr}.tecnico-stats{grid-template-columns:repeat(3,1fr)}}@media (max-width:480px){.stats-grid{grid-template-columns:1fr}.tecnico-stats{grid-template-columns:1fr}.filter-actions{flex-direction:column}.btn{width:100%;justify-content:center}}@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}.stat-card,.tecnico-card,.task-form,.filter-section,.tasks-table-container{animation:fadeIn .5s ease-out}</style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="main-header">
            <h1>
                <i class="fas fa-tasks"></i>
                Gestión de Tareas Técnicas
            </h1>
            <div class="date-badge">
                <i class="fas fa-calendar-alt"></i>
                <?= date('d/m/Y H:i') ?>
            </div>
        </div>

        <!-- Alertas usando variables de sesión -->
        <?php if($mensaje_exito == 'success'): ?>
            <div class="alert alert-success" id="successAlert">
                <i class="fas fa-check-circle"></i>
                <div class="alert-content">
                    <div class="alert-title">¡Tarea registrada exitosamente!</div>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if($mensaje_exito == 'success_recurrente'): ?>
            <div class="alert alert-success" id="successRecurrenteAlert">
                <i class="fas fa-check-circle"></i>
                <div class="alert-content">
                    <div class="alert-title">¡Tarea recurrente registrada exitosamente!</div>
                    <div><i class="fas fa-sync-alt"></i> Se han creado las instancias recurrentes hasta la fecha tope</div>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if($mensaje_exito == 'editado'): ?>
            <div class="alert alert-success" id="editAlert">
                <i class="fas fa-edit"></i>
                <div class="alert-content">
                    <div class="alert-title">¡Tarea actualizada exitosamente!</div>
                    <div>Los cambios han sido guardados correctamente</div>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if($mensaje_exito == 'eliminado'): ?>
            <div class="alert alert-success" id="deleteAlert">
                <i class="fas fa-trash-alt"></i>
                <div class="alert-content">
                    <div class="alert-title">¡Tarea eliminada exitosamente!</div>
                    <div>La tarea y todas sus dependencias han sido eliminadas</div>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if($mensaje_error == 'error_eliminar'): ?>
            <div class="alert alert-danger" id="errorDeleteAlert">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="alert-content">
                    <div class="alert-title">Error al eliminar</div>
                    <div>No se pudo eliminar la tarea. Por favor, intente nuevamente.</div>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Stats Grid Mejorado -->
        <div class="stats-grid">
            <?php
            $stats_config = [
                ['icon' => 'fa-tasks', 'color' => '#667eea', 'number' => $stats['total'], 'label' => 'Total Tareas'],
                ['icon' => 'fa-sync-alt', 'color' => '#9f7aea', 'number' => $stats['total_recurrentes'] ?? 0, 'label' => 'Recurrentes'],
                ['icon' => 'fa-users', 'color' => '#60a5fa', 'number' => $stats['tareas_cualquier_tecnico'] ?? 0, 'label' => 'Cualquier Técnico'],
                ['icon' => 'fa-clock', 'color' => '#fbbf24', 'number' => $stats['pendientes_ahora'] ?? 0, 'label' => 'Pendientes'],
                ['icon' => 'fa-calendar-alt', 'color' => '#34d399', 'number' => $stats['programadas'] ?? 0, 'label' => 'Programadas'],
                ['icon' => 'fa-play-circle', 'color' => '#60a5fa', 'number' => $stats['en_proceso'] ?? 0, 'label' => 'En Proceso'],
                ['icon' => 'fa-pause-circle', 'color' => '#f97316', 'number' => $stats['pausadas'] ?? 0, 'label' => 'Pausadas'],
                ['icon' => 'fa-check-circle', 'color' => '#34d399', 'number' => $stats['finalizadas'] ?? 0, 'label' => 'Finalizadas'],
            ];

            foreach($stats_config as $stat): ?>
                <div class="stat-card">
                    <div class="stat-icon" style="background: <?= $stat['color'] ?>20; color: <?= $stat['color'] ?>">
                        <i class="fas <?= $stat['icon'] ?>"></i>
                    </div>
                    <div class="stat-number"><?= $stat['number'] ?></div>
                    <div class="stat-label"><?= $stat['label'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Stats de Rendimiento con recurrentes extendidos -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #667eea20; color: #667eea">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-number"><?= floor($tiempo_promedio/60) ?>h <?= $tiempo_promedio%60 ?>m</div>
                <div class="stat-label">Tiempo Promedio</div>
                <div class="stat-trend">
                    <i class="fas fa-chart-line"></i> por tarea
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?= floor($tiempo_total/60) ?>h <?= $tiempo_total%60 ?>m</div>
                <div class="stat-label">Tiempo Total</div>
                <div class="stat-trend">
                    <i class="fas fa-chart-bar"></i> acumulado
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #34d39920; color: #34d399">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="stat-number"><?= $stats['finalizadas'] ? round(($stats['finalizadas']/$stats['total'])*100) : 0 ?>%</div>
                <div class="stat-label">% Completado</div>
                <div class="stat-trend">
                    <i class="fas fa-arrow-up"></i> +<?= $stats['finalizadas_hoy'] ?> hoy
                </div>
            </div>
        </div>

        <!-- Stats de Recurrencias -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stat-number"><?= $stats['recurrentes_semanales'] ?? 0 ?></div>
                <div class="stat-label">Recurrentes Semanales</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-number"><?= $stats['recurrentes_mensuales'] ?? 0 ?></div>
                <div class="stat-label">Recurrentes Mensuales</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="stat-number"><?= $stats['recurrentes_trimestrales'] ?? 0 ?></div>
                <div class="stat-label">Recurrentes Trimestrales</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-number"><?= $stats['recurrentes_semestrales'] ?? 0 ?></div>
                <div class="stat-label">Recurrentes Semestrales</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-calendar-year"></i>
                </div>
                <div class="stat-number"><?= $stats['recurrentes_anuales'] ?? 0 ?></div>
                <div class="stat-label">Recurrentes Anuales</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #9f7aea20; color: #9f7aea">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="stat-number"><?= $stats['recurrentes_bienales'] ?? 0 ?></div>
                <div class="stat-label">Recurrentes Bienales</div>
            </div>
        </div>

        <!-- Filtros y Navegación -->
        <div class="filter-section">
            <div class="view-tabs">
                <a href="?vista=semana<?= !empty($tecnico_filtro) ? '&tecnico='.$tecnico_filtro : '' ?>" 
                   class="view-tab <?= $vista == 'semana' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-week"></i> Esta Semana
                </a>
                <a href="?vista=hoy<?= !empty($tecnico_filtro) ? '&tecnico='.$tecnico_filtro : '' ?>" 
                   class="view-tab <?= $vista == 'hoy' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-day"></i> Hoy
                </a>
                <a href="?vista=programadas<?= !empty($tecnico_filtro) ? '&tecnico='.$tecnico_filtro : '' ?>" 
                   class="view-tab <?= $vista == 'programadas' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-alt"></i> Programadas
                </a>
                <a href="?vista=recurrentes<?= !empty($tecnico_filtro) ? '&tecnico='.$tecnico_filtro : '' ?>" 
                   class="view-tab <?= $vista == 'recurrentes' ? 'active' : '' ?>">
                    <i class="fas fa-sync-alt"></i> Recurrentes
                </a>
            </div>

            <form method="GET" class="filter-grid">
                <?php if($vista == 'semana'): ?>
                    <input type="hidden" name="vista" value="semana">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-start"></i> Desde:</label>
                        <input type="date" name="fecha_desde" value="<?= $fecha_desde ?>">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-end"></i> Hasta:</label>
                        <input type="date" name="fecha_hasta" value="<?= $fecha_hasta ?>">
                    </div>
                <?php elseif($vista == 'todas'): ?>
                    <input type="hidden" name="vista" value="todas">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-start"></i> Desde:</label>
                        <input type="date" name="fecha_desde" value="<?= $fecha_desde ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-end"></i> Hasta:</label>
                        <input type="date" name="fecha_hasta" value="<?= $fecha_hasta ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                <?php else: ?>
                    <input type="hidden" name="vista" value="<?= $vista ?>">
                <?php endif; ?>

                <div class="filter-group">
                    <label><i class="fas fa-user-cog"></i> Técnico:</label>
                    <select name="tecnico">
                        <option value="">Todos los técnicos</option>
                        <option value="cualquiera" <?= $tecnico_filtro == 'cualquiera' ? 'selected' : '' ?>>
                            👥 Tareas para cualquier técnico
                        </option>
                        <?php foreach($tecnicos as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $tecnico_filtro == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-sync-alt"></i> Tipo:</label>
                    <select name="recurrentes">
                        <option value="todas" <?= $mostrar_recurrentes == 'todas' ? 'selected' : '' ?>>Todas</option>
                        <option value="si" <?= $mostrar_recurrentes == 'si' ? 'selected' : '' ?>>Solo recurrentes</option>
                        <option value="no" <?= $mostrar_recurrentes == 'no' ? 'selected' : '' ?>>No recurrentes</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="asignar_tarea.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Restablecer
                    </a>
                </div>
            </form>

            <!-- Resumen -->
            <div class="summary-badge">
                <div class="summary-item">
                    <i class="fas fa-calendar-alt"></i>
                    <?php if($vista == 'hoy'): ?>
                        Tareas para hoy
                    <?php elseif($vista == 'programadas'): ?>
                        Tareas programadas a futuro
                    <?php elseif($vista == 'recurrentes'): ?>
                        Tareas recurrentes
                    <?php elseif($vista == 'semana'): ?>
                        Semana: <?= $periodo_display ?>
                    <?php else: ?>
                        Período: <?= $periodo_display ?>
                    <?php endif; ?>
                </div>
                <div class="summary-item">
                    <i class="fas fa-tasks"></i> Total: <?= count($tareas) ?>
                </div>
                <div class="summary-item">
                    <i class="fas fa-users"></i> Cualquier técnico: <?= $stats['tareas_cualquier_tecnico'] ?? 0 ?>
                </div>
                <div class="summary-item">
                    <i class="fas fa-sync-alt"></i> Recurrentes: <?= $stats['total_recurrentes'] ?? 0 ?>
                </div>
                <?php if(!empty($tecnico_filtro)): ?>
                    <div class="summary-item">
                        <i class="fas fa-user"></i> Filtrado por técnico
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario de Nueva Tarea -->
        <div class="task-form">
            <h2>
                <i class="fas fa-plus-circle" style="color: #667eea;"></i>
                Registrar Nueva Tarea
            </h2>
            <form method="POST" id="formTarea">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Tipo de Tarea *</label>
                        <select name="tipo_paro" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach($tipos_tarea as $tp): ?>
                                <option value="<?= htmlspecialchars($tp['nombre']) ?>"><?= htmlspecialchars($tp['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Prioridad</label>
                        <select name="prioridad">
                            <option value="baja">Baja</option>
                            <option value="media" selected>Media</option>
                            <option value="alta">Alta</option>
                            <option value="critica">Crítica</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user-cog"></i> Técnico Asignado *</label>
                        <select name="tecnico" required>
                            <option value="">Seleccionar...</option>
                            <option value="cualquiera">👥 Cualquier técnico disponible</option>
                            <?php foreach($tecnicos as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#718096;">Si selecciona "Cualquier técnico", la tarea estará disponible para todos</small>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-microchip"></i> Equipo</label>
                        <select name="equipo">
                            <option value="">Sin equipo</option>
                            <?php foreach($equipos as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre_equipo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-check"></i> Fecha y Hora Programada</label>
                        <input type="datetime-local" name="fecha_programada" 
                               value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>"
                               min="<?= date('Y-m-d\TH:i') ?>" id="fecha_programada">
                    </div>
                </div>

                <!-- Opción de tarea recurrente -->
                <div class="recurrente-wrapper">
                    <div class="checkbox-group">
                        <input type="checkbox" name="recurrente" id="recurrente" value="1">
                        <label for="recurrente">
                            <i class="fas fa-sync-alt"></i> Hacer esta tarea recurrente
                        </label>
                    </div>
                    
                    <div id="recurrenteOptions" class="recurrente-options" style="display:none;">
                        <div class="recurrencia-grid">
                            <div class="recurrencia-item">
                                <label><i class="fas fa-clock"></i> Tipo:</label>
                                <select name="tipo_recurrencia" id="tipo_recurrencia">
                                    <option value="semanal">Semanal</option>
                                    <option value="mensual">Mensual</option>
                                    <option value="trimestral">Trimestral</option>
                                    <option value="semestral">Semestral</option>
                                    <option value="anual">Anual</option>
                                    <option value="bienal">Bienal</option>
                                </select>
                            </div>
                            
                            <div class="recurrencia-item">
                                <label><i class="fas fa-sort-numeric-up-alt"></i> Cada:</label>
                                <select name="intervalo_recurrencia" id="intervalo_recurrencia">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="6">6</option>
                                </select>
                                <span id="intervalo_unidad">semana(s)</span>
                            </div>
                            
                            <div class="recurrencia-item" id="dia_semana_container">
                                <label><i class="fas fa-calendar-week"></i> Día:</label>
                                <select name="dia_semana">
                                    <option value="Monday">Lunes</option>
                                    <option value="Tuesday">Martes</option>
                                    <option value="Wednesday">Miércoles</option>
                                    <option value="Thursday">Jueves</option>
                                    <option value="Friday">Viernes</option>
                                    <option value="Saturday">Sábado</option>
                                    <option value="Sunday">Domingo</option>
                                </select>
                            </div>
                            
                            <div class="recurrencia-item" id="dia_mes_container" style="display:none;">
                                <label><i class="fas fa-calendar-day"></i> Día del mes:</label>
                                <select name="dia_mes">
                                    <?php for($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?= $d ?>" <?= $d == 1 ? 'selected' : '' ?>><?= $d ?></option>
                                    <?php endfor; ?>
                                </select>
                                <small style="color:#718096;">(si el día no existe, se usa el último)</small>
                            </div>
                            
                            <div class="recurrencia-item" id="dia_anual_container" style="display:none;">
                                <label><i class="fas fa-calendar-day"></i> Día y Mes:</label>
                                <div style="display: flex; gap: 10px;">
                                    <select name="dia_mes_anual" id="dia_mes_anual" style="width: 80px;">
                                        <?php for($d = 1; $d <= 31; $d++): ?>
                                            <option value="<?= $d ?>" <?= $d == 1 ? 'selected' : '' ?>><?= $d ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <select name="mes_anual" id="mes_anual" style="width: 120px;">
                                        <?php for($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?= $m ?>"><?= $meses[$m] ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <small style="color:#718096;">(para recurrencia anual y bienal)</small>
                            </div>
                        </div>
                        
                        <!-- Sección de fecha tope -->
                        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                            <label style="font-weight: 600; color: #4a5568; display: block; margin-bottom: 10px;">
                                <i class="fas fa-calendar-times"></i> Fecha de finalización de recurrencia:
                            </label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="recurrencia_tipo" value="indefinida" id="recurrencia_indefinida" checked> 
                                    <i class="fas fa-infinity"></i> Indefinida (sin fecha tope)
                                </label>
                                <label>
                                    <input type="radio" name="recurrencia_tipo" value="fecha_tope" id="recurrencia_fecha_tope"> 
                                    <i class="fas fa-calendar-alt"></i> Hasta fecha específica
                                </label>
                            </div>
                            <div id="fecha_tope_container" style="display: none; margin-top: 10px;">
                                <input type="date" name="fecha_fin_recurrencia" id="fecha_fin_recurrencia" 
                                       min="<?= date('Y-m-d') ?>" style="padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px;">
                                <small style="color:#718096; display: block; margin-top: 5px;">
                                    <i class="fas fa-info-circle"></i> Las tareas recurrentes se generarán hasta esta fecha
                                </small>
                            </div>
                            <input type="hidden" name="recurrencia_indefinida" id="recurrencia_indefinida_input" value="1">
                        </div>
                        
                        <small style="color:#718096; display:block; margin-top:15px;">
                            <i class="fas fa-info-circle"></i> Se crearán automáticamente todas las ocurrencias hasta la fecha tope seleccionada
                        </small>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label><i class="fas fa-align-left"></i> Descripción Detallada *</label>
                    <textarea name="descripcion" placeholder="Describe la tarea, incluyendo detalles importantes, equipos involucrados, etc..." required></textarea>
                </div>

                <button type="submit" name="crear_tarea" class="btn btn-primary" style="margin-top: 20px; width: 100%; justify-content: center;">
                    <i class="fas fa-save"></i> Registrar Tarea
                </button>
            </form>
        </div>

        <!-- Modal de Edición de Tarea -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>
                        <i class="fas fa-edit"></i>
                        Editar Tarea
                    </h3>
                    <button class="modal-close" onclick="closeEditModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST" id="editForm">
                    <input type="hidden" name="tarea_id" id="edit_tarea_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Tipo de Tarea *</label>
                            <select name="tipo_paro" id="edit_tipo_paro" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach($tipos_tarea as $tp): ?>
                                    <option value="<?= htmlspecialchars($tp['nombre']) ?>"><?= htmlspecialchars($tp['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-exclamation-triangle"></i> Prioridad</label>
                            <select name="prioridad" id="edit_prioridad">
                                <option value="baja">Baja</option>
                                <option value="media">Media</option>
                                <option value="alta">Alta</option>
                                <option value="critica">Crítica</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user-cog"></i> Técnico Asignado *</label>
                            <select name="tecnico" id="edit_tecnico" required>
                                <option value="">Seleccionar...</option>
                                <option value="cualquiera">👥 Cualquier técnico disponible</option>
                                <?php foreach($tecnicos as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-microchip"></i> Equipo</label>
                            <select name="equipo" id="edit_equipo">
                                <option value="">Sin equipo</option>
                                <?php foreach($equipos as $e): ?>
                                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre_equipo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Fecha y Hora Programada</label>
                            <input type="datetime-local" name="fecha_programada" id="edit_fecha_programada">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Descripción Detallada *</label>
                        <textarea name="descripcion" id="edit_descripcion" required></textarea>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="editar_tarea" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Guardar Cambios
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal de Confirmación de Eliminación -->
        <div id="deleteModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>
                        <i class="fas fa-exclamation-triangle" style="color: #f56565;"></i>
                        Confirmar Eliminación
                    </h3>
                    <button class="modal-close" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body" style="margin-bottom: 20px;">
                    <p>¿Está seguro que desea eliminar esta tarea?</p>
                    <p style="color: #f56565; font-size: 0.9em; margin-top: 10px;">
                        <i class="fas fa-info-circle"></i> Esta acción eliminará:
                    </p>
                    <ul style="margin-left: 20px; color: #718096;">
                        <li>La tarea principal</li>
                        <li>Todas las tareas recurrentes asociadas</li>
                        <li>Todo el historial de seguimiento</li>
                        <li>Los registros de pausas</li>
                    </ul>
                    <p style="margin-top: 15px; font-weight: 600;">Esta acción no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="deleteForm">
                        <input type="hidden" name="tarea_id_eliminar" id="delete_tarea_id">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="submit" name="eliminar_tarea" class="btn btn-danger">
                            <i class="fas fa-trash-alt"></i> Eliminar
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Rendimiento por Técnico -->
        <?php if(!empty($stats_tecnicos)): ?>
            <div class="tasks-table-container" style="margin-bottom: 30px;">
                <h2>
                    <i class="fas fa-users" style="color: #667eea;"></i>
                    Rendimiento por Técnico
                </h2>
                
                <div class="tecnicos-grid">
                    <?php foreach($stats_tecnicos as $tecnico): 
                        $tiempo_promedio_tec = $tecnico['tiempo_promedio_minutos'] ? round($tecnico['tiempo_promedio_minutos']) : 0;
                        $tiempo_total_tec = $tecnico['tiempo_total_minutos'] ?? 0;
                        $tiempo_pausado_tec = $tecnico['tiempo_total_pausado'] ?? 0;
                        $porcentaje_recurrentes = $tecnico['total_tareas'] > 0 ? round(($tecnico['tareas_recurrentes'] / $tecnico['total_tareas']) * 100) : 0;
                    ?>
                        <div class="tecnico-card">
                            <div class="tecnico-header">
                                <div class="tecnico-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="tecnico-info">
                                    <h3><?= htmlspecialchars($tecnico['nombre']) ?></h3>
                                    <span class="tecnico-total"><?= $tecnico['total_tareas'] ?> tareas</span>
                                </div>
                            </div>
                            
                            <div class="tecnico-stats">
                                <div class="stat-mini">
                                    <div class="stat-mini-value"><?= floor($tiempo_promedio_tec/60) ?>h <?= $tiempo_promedio_tec%60 ?>m</div>
                                    <div class="stat-mini-label">⏱️ Promedio</div>
                                </div>
                                <div class="stat-mini">
                                    <div class="stat-mini-value"><?= floor($tiempo_total_tec/60) ?>h <?= $tiempo_total_tec%60 ?>m</div>
                                    <div class="stat-mini-label">⌛ Total</div>
                                </div>
                                <div class="stat-mini">
                                    <div class="stat-mini-value"><?= floor($tiempo_pausado_tec/60) ?>h <?= $tiempo_pausado_tec%60 ?>m</div>
                                    <div class="stat-mini-label">⏸️ Pausas</div>
                                </div>
                            </div>
                            
                            <div class="tecnico-progress">
                                <div class="progress-label">
                                    <span>Tareas recurrentes</span>
                                    <span><?= $tecnico['tareas_recurrentes'] ?> (<?= $porcentaje_recurrentes ?>%)</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $porcentaje_recurrentes ?>%;"></div>
                                </div>
                            </div>
                            
                            <div class="tecnico-badges">
                                <span class="badge-mini" title="Semanales">
                                    <i class="fas fa-calendar-week"></i> <?= $tecnico['recurrentes_semanales'] ?? 0 ?>
                                </span>
                                <span class="badge-mini" title="Mensuales">
                                    <i class="fas fa-calendar-alt"></i> <?= $tecnico['recurrentes_mensuales'] ?? 0 ?>
                                </span>
                                <span class="badge-mini" title="Trimestrales">
                                    <i class="fas fa-calendar"></i> <?= $tecnico['recurrentes_trimestrales'] ?? 0 ?>
                                </span>
                                <span class="badge-mini" title="Semestrales">
                                    <i class="fas fa-calendar-check"></i> <?= $tecnico['recurrentes_semestrales'] ?? 0 ?>
                                </span>
                                <span class="badge-mini" title="Anuales">
                                    <i class="fas fa-calendar-year"></i> <?= $tecnico['recurrentes_anuales'] ?? 0 ?>
                                </span>
                                <span class="badge-mini" title="Bienales">
                                    <i class="fas fa-calendar-plus"></i> <?= $tecnico['recurrentes_bienales'] ?? 0 ?>
                                </span>
                                <span class="badge-mini" title="Finalizadas">
                                    <i class="fas fa-check-circle"></i> <?= $tecnico['finalizadas'] ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

<!-- Lista de Tareas -->
<div class="tasks-table-container">
    <h2>
        <i class="fas fa-list"></i>
        <?php if($vista == 'hoy'): ?>
            Tareas para Hoy
        <?php elseif($vista == 'programadas'): ?>
            Tareas Programadas a Futuro
        <?php elseif($vista == 'recurrentes'): ?>
            Tareas Recurrentes
        <?php elseif($vista == 'semana'): ?>
            Tareas de la Semana (<?= $semana_inicio->format('d/m/Y') ?> - <?= $semana_fin->format('d/m/Y') ?>)
        <?php else: ?>
            Todas las Tareas
        <?php endif; ?>
        <span class="badge" style="background: #667eea; color: white;"><?= count($tareas) ?> registros</span>
    </h2>
    
    <?php if(empty($tareas)): ?>
        <div style="text-align: center; padding: 60px 20px; background: #f7fafc; border-radius: 16px;">
            <i class="fas fa-folder-open" style="font-size: 4em; color: #a0aec0; margin-bottom: 20px;"></i>
            <h3 style="color: #4a5568; margin-bottom: 10px;">No hay tareas en esta semana</h3>
            <p style="color: #718096;">Utiliza el formulario de arriba para crear una nueva tarea</p>
        </div>
    <?php else: ?>
        <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 1200px;">
                <thead style="position: sticky; top: 0; background: #f7fafc; z-index: 10;">
                    <tr>
                        <th>ID</th>
                        <th>Tarea</th>
                        <th>Descripción</th>
                        <th>Técnico</th>
                        <th>Equipo</th>
                        <th>Prioridad</th>
                        <th>Estado</th>
                        <th>Programada</th>
                        <th>Tiempos</th>
                        <th>Últimos cambios</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tareas as $t): 
                        // Obtener historial
                        $historial = $conexion->prepare("
                            SELECT * FROM seguimiento 
                            WHERE tarea_id = ? 
                            ORDER BY fecha DESC 
                            LIMIT 3
                        ");
                        $historial->execute([$t['id']]);
                        $historial_rows = $historial->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Calcular tiempos
                        $tiempo_ejec = $t['tiempo_total_ejecucion'] ?? 0;
                        $tiempo_pausa = $t['tiempo_total_pausado'] ?? 0;
                        $tiempo_pausa_actual = $t['tiempo_pausa_actual'] ?? 0;
                        $minutos_para_inicio = $t['minutos_para_inicio'] ?? 0;
                        
                        // Determinar estado visual
                        $estado_visual = $t['estado'];
                        if($t['fecha_programada'] > date('Y-m-d H:i:s') && $t['estado'] == 'pendiente') {
                            $estado_visual = 'programada';
                        }
                        
                        // Verificar si está atrasada
                        $fecha_prog = new DateTime($t['fecha_programada']);
                        $ahora = new DateTime();
                        $atrasada = ($fecha_prog <= $ahora && $t['estado'] == 'pendiente');
                        
                        // Texto de recurrencia
                        $recurrencia_texto = '';
                        if($t['recurrente'] && $t['tipo_recurrencia']) {
                            switch($t['tipo_recurrencia']) {
                                case 'semanal':
                                    $recurrencia_texto = 'Cada ' . $t['intervalo_recurrencia'] . ' semana(s), ' . ($dias_semana[$t['dia_semana']] ?? '');
                                    break;
                                case 'mensual':
                                    $recurrencia_texto = 'Cada ' . $t['intervalo_recurrencia'] . ' mes(es), día ' . ($t['dia_mes'] ?? '1');
                                    break;
                                case 'trimestral':
                                    $recurrencia_texto = 'Cada ' . $t['intervalo_recurrencia'] . ' trimestre(s)';
                                    break;
                                case 'semestral':
                                    $recurrencia_texto = 'Cada ' . $t['intervalo_recurrencia'] . ' semestre(s)';
                                    break;
                                case 'anual':
                                    $recurrencia_texto = 'Cada ' . $t['intervalo_recurrencia'] . ' año(s)';
                                    break;
                                case 'bienal':
                                    $recurrencia_texto = 'Cada ' . $t['intervalo_recurrencia'] . ' bienio(s)';
                                    break;
                            }
                            if(!$t['recurrencia_indefinida'] && $t['fecha_fin_recurrencia']) {
                                $recurrencia_texto .= ' (hasta: ' . date('d/m/Y', strtotime($t['fecha_fin_recurrencia'])) . ')';
                            } elseif($t['recurrencia_indefinida']) {
                                $recurrencia_texto .= ' (indefinida)';
                            }
                        }
                        
                        // Icono según tipo de recurrencia
                        $icono_recurrencia = $recurrencia_iconos[$t['tipo_recurrencia']] ?? 'fa-sync-alt';
                    ?>
                    <tr class="<?= $atrasada ? 'atrasada' : '' ?>">
                        <td>
                            <strong>#<?= $t['id'] ?></strong>
                            <?php if($t['recurrente']): ?>
                                <span class="recurrente-badge" title="<?= htmlspecialchars($recurrencia_texto) ?>">
                                    <i class="fas <?= $icono_recurrencia ?>"></i>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($t['tipo_paro'] ?? 'Sin tipo') ?>
                            <?php if($t['recurrente'] && $t['tipo_recurrencia']): ?>
                                <br>
                                <small style="color: #9f7aea;">
                                    <i class="fas <?= $icono_recurrencia ?>"></i>
                                    <?= $recurrencia_textos[$t['tipo_recurrencia']] ?? ucfirst($t['tipo_recurrencia']) ?> (c/<?= $t['intervalo_recurrencia'] ?>)
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="max-width: 250px;">
                                <?= nl2br(htmlspecialchars(substr($t['descripcion'] ?? '', 0, 100))) ?>
                                <?php if(strlen($t['descripcion'] ?? '') > 100): ?>...<?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if($t['asignado_a'] === null): ?>
                                <span class="badge" style="background: #9f7aea; color: white;">
                                    <i class="fas fa-users"></i> Cualquier técnico
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($t['tecnico_nombre'] ?? 'No asignado') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if(!empty($t['equipo_nombre'])): ?>
                                <span class="equipo-tag">
                                    <i class="fas fa-cog"></i> <?= htmlspecialchars($t['equipo_nombre']) ?>
                                </span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <span class="prioridad prioridad-<?= $t['prioridad'] ?>">
                                <?= ucfirst($t['prioridad'] ?? 'media') ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge estado-<?= $estado_visual ?>">
                                <?= str_replace('_', ' ', $estado_visual) ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 0.85em;">
                                <i class="fas fa-calendar-alt"></i> 
                                <?= $fecha_prog->format('d/m/Y H:i') ?>
                                <?php if($fecha_prog > $ahora && $t['estado'] == 'pendiente'): ?>
                                    <br><small style="color: #9f7aea; font-weight: 600;">
                                        en <?= floor($minutos_para_inicio/60) ?>h <?= $minutos_para_inicio%60 ?>m
                                    </small>
                                <?php elseif($atrasada): ?>
                                    <br><small style="color: #e53e3e; font-weight: 600;">
                                        <i class="fas fa-exclamation-circle"></i> Atrasada
                                    </small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="tiempo-container">
                                <?php if($tiempo_ejec > 0): ?>
                                    <span class="tiempo-item tiempo-ejecucion" title="Tiempo total de ejecución">
                                        <i class="fas fa-clock"></i> <?= floor($tiempo_ejec/60) ?>h <?= $tiempo_ejec%60 ?>m
                                    </span>
                                <?php endif; ?>
                                
                                <?php if($tiempo_pausa > 0): ?>
                                    <span class="tiempo-item tiempo-pausa" title="Tiempo total en pausa">
                                        <i class="fas fa-pause-circle"></i> <?= floor($tiempo_pausa/60) ?>h <?= $tiempo_pausa%60 ?>m
                                    </span>
                                <?php endif; ?>
                                
                                <?php if($tiempo_pausa_actual > 0 && $t['estado'] == 'pausada'): ?>
                                    <span class="tiempo-item tiempo-pausa-actual" title="Tiempo de pausa actual">
                                        <i class="fas fa-hourglass-half"></i> <?= floor($tiempo_pausa_actual/60) ?>h <?= $tiempo_pausa_actual%60 ?>m
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if(!empty($historial_rows)): ?>
                                <div class="historial-mini">
                                    <?php foreach($historial_rows as $h): ?>
                                        <div class="historial-item">
                                            <span class="historial-fecha">
                                                <?= date('d/m H:i', strtotime($h['fecha'])) ?>
                                            </span>
                                            <span><?= htmlspecialchars(substr($h['comentario'] ?? '', 0, 20)) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #a0aec0; font-size: 0.85em;">Sin seguimiento</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-icon-edit" onclick="editarTarea(<?= $t['id'] ?>)" title="Editar tarea">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-icon btn-icon-delete" onclick="confirmarEliminar(<?= $t['id'] ?>)" title="Eliminar tarea">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span><i class="fas fa-clock"></i> Actualizado: <?= date('d/m/Y H:i:s') ?></span>
            <span><i class="fas fa-list"></i> Mostrando <?= count($tareas) ?> registros</span>
        </div>
    <?php endif; ?>
</div>

    <script>
    // Función para editar tarea
    function editarTarea(tareaId) {
        // Obtener datos de la tarea mediante AJAX
        fetch('obtener_tarea.php?id=' + tareaId)
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    // Llenar el formulario de edición
                    document.getElementById('edit_tarea_id').value = data.tarea.id;
                    document.getElementById('edit_tipo_paro').value = data.tarea.tipo_paro;
                    document.getElementById('edit_prioridad').value = data.tarea.prioridad;
                    document.getElementById('edit_descripcion').value = data.tarea.descripcion;
                    
                    // Asignar técnico (manejar caso null)
                    if(data.tarea.asignado_a === null) {
                        document.getElementById('edit_tecnico').value = 'cualquiera';
                    } else {
                        document.getElementById('edit_tecnico').value = data.tarea.asignado_a;
                    }
                    
                    document.getElementById('edit_equipo').value = data.tarea.equipo_id || '';
                    
                    // Formatear fecha para datetime-local
                    if(data.tarea.fecha_programada) {
                        const fecha = new Date(data.tarea.fecha_programada.replace(' ', 'T'));
                        const fechaFormateada = fecha.toISOString().slice(0, 16);
                        document.getElementById('edit_fecha_programada').value = fechaFormateada;
                    }
                    
                    // Mostrar modal
                    document.getElementById('editModal').classList.add('active');
                } else {
                    alert('Error al cargar los datos de la tarea');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al cargar los datos de la tarea');
            });
    }

    // Función para confirmar eliminación
    function confirmarEliminar(tareaId) {
        document.getElementById('delete_tarea_id').value = tareaId;
        document.getElementById('deleteModal').classList.add('active');
    }

    // Cerrar modal de edición
    function closeEditModal() {
        document.getElementById('editModal').classList.remove('active');
    }
    
    // Cerrar modal de eliminación
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    // Cerrar modales al hacer clic fuera de ellos
    window.onclick = function(event) {
        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');
        if (event.target == editModal) {
            closeEditModal();
        }
        if (event.target == deleteModal) {
            closeDeleteModal();
        }
    }

    // Mostrar/ocultar opciones de recurrencia
    document.getElementById('recurrente').addEventListener('change', function() {
        const options = document.getElementById('recurrenteOptions');
        options.style.display = this.checked ? 'block' : 'none';
        if(this.checked) {
            actualizarOpcionesRecurrencia();
        }
    });

    // Cambiar opciones según tipo de recurrencia
    document.getElementById('tipo_recurrencia').addEventListener('change', actualizarOpcionesRecurrencia);
    document.getElementById('intervalo_recurrencia').addEventListener('change', actualizarUnidad);

    // Manejo de fecha tope
    const radioIndefinida = document.getElementById('recurrencia_indefinida');
    const radioFechaTope = document.getElementById('recurrencia_fecha_tope');
    const fechaTopeContainer = document.getElementById('fecha_tope_container');
    const recurrenciaIndefinidaInput = document.getElementById('recurrencia_indefinida_input');
    
    if(radioIndefinida && radioFechaTope) {
        radioIndefinida.addEventListener('change', function() {
            if(this.checked) {
                fechaTopeContainer.style.display = 'none';
                recurrenciaIndefinidaInput.value = '1';
            }
        });
        
        radioFechaTope.addEventListener('change', function() {
            if(this.checked) {
                fechaTopeContainer.style.display = 'block';
                recurrenciaIndefinidaInput.value = '0';
            }
        });
    }

    function actualizarOpcionesRecurrencia() {
        const tipo = document.getElementById('tipo_recurrencia').value;
        const diaSemanaContainer = document.getElementById('dia_semana_container');
        const diaMesContainer = document.getElementById('dia_mes_container');
        const diaAnualContainer = document.getElementById('dia_anual_container');
        const intervaloUnidad = document.getElementById('intervalo_unidad');

        // Ocultar todos primero
        if(diaSemanaContainer) diaSemanaContainer.style.display = 'none';
        if(diaMesContainer) diaMesContainer.style.display = 'none';
        if(diaAnualContainer) diaAnualContainer.style.display = 'none';

        // Mostrar según tipo
        switch(tipo) {
            case 'semanal':
                if(diaSemanaContainer) diaSemanaContainer.style.display = 'block';
                if(intervaloUnidad) intervaloUnidad.textContent = 'semana(s)';
                break;
            case 'mensual':
                if(diaMesContainer) diaMesContainer.style.display = 'block';
                if(intervaloUnidad) intervaloUnidad.textContent = 'mes(es)';
                break;
            case 'trimestral':
                if(intervaloUnidad) intervaloUnidad.textContent = 'trimestre(s)';
                break;
            case 'semestral':
                if(intervaloUnidad) intervaloUnidad.textContent = 'semestre(s)';
                break;
            case 'anual':
            case 'bienal':
                if(diaAnualContainer) diaAnualContainer.style.display = 'block';
                if(intervaloUnidad) intervaloUnidad.textContent = tipo == 'anual' ? 'año(s)' : 'bienio(s)';
                break;
        }

        actualizarFechaPorRecurrencia();
    }

    function actualizarUnidad() {
        const tipo = document.getElementById('tipo_recurrencia')?.value;
        const intervalo = document.getElementById('intervalo_recurrencia')?.value;
        const intervaloUnidad = document.getElementById('intervalo_unidad');

        if(!tipo || !intervaloUnidad) return;

        switch(tipo) {
            case 'semanal':
                intervaloUnidad.textContent = intervalo == 1 ? 'semana' : 'semanas';
                break;
            case 'mensual':
                intervaloUnidad.textContent = intervalo == 1 ? 'mes' : 'meses';
                break;
            case 'trimestral':
                intervaloUnidad.textContent = intervalo == 1 ? 'trimestre' : 'trimestres';
                break;
            case 'semestral':
                intervaloUnidad.textContent = intervalo == 1 ? 'semestre' : 'semestres';
                break;
            case 'anual':
                intervaloUnidad.textContent = intervalo == 1 ? 'año' : 'años';
                break;
            case 'bienal':
                intervaloUnidad.textContent = intervalo == 1 ? 'bienio' : 'bienios';
                break;
        }
    }

    function actualizarFechaPorRecurrencia() {
        const fechaInput = document.getElementById('fecha_programada');
        if(!fechaInput || !fechaInput.value) return;

        const fecha = new Date(fechaInput.value);
        const tipo = document.getElementById('tipo_recurrencia')?.value;

        if(tipo == 'semanal') {
            const diaSemanaSelect = document.querySelector('[name="dia_semana"]');
            if(diaSemanaSelect) {
                const diaSemana = diaSemanaSelect.value;
                const diasMap = {
                    'Sunday': 0, 'Monday': 1, 'Tuesday': 2, 'Wednesday': 3,
                    'Thursday': 4, 'Friday': 5, 'Saturday': 6
                };

                const diaActual = fecha.getDay();
                const diaObjetivo = diasMap[diaSemana];
                let diff = diaObjetivo - diaActual;
                if(diff < 0) diff += 7;

                if(diff > 0) {
                    fecha.setDate(fecha.getDate() + diff);
                    actualizarInputFecha(fecha);
                }
            }
        } else if(tipo == 'mensual') {
            const diaMesSelect = document.querySelector('[name="dia_mes"]');
            if(diaMesSelect) {
                const diaMes = parseInt(diaMesSelect.value);
                const ultimoDia = new Date(fecha.getFullYear(), fecha.getMonth() + 1, 0).getDate();
                fecha.setDate(Math.min(diaMes, ultimoDia));
                actualizarInputFecha(fecha);
            }
        } else if(tipo == 'anual' || tipo == 'bienal') {
            const diaMesAnual = document.getElementById('dia_mes_anual');
            const mesAnual = document.getElementById('mes_anual');
            if(diaMesAnual && mesAnual) {
                const dia = parseInt(diaMesAnual.value);
                const mes = parseInt(mesAnual.value);
                const ultimoDia = new Date(fecha.getFullYear(), mes, 0).getDate();
                fecha.setMonth(mes - 1);
                fecha.setDate(Math.min(dia, ultimoDia));
                actualizarInputFecha(fecha);
            }
        }
    }

    function actualizarInputFecha(fecha) {
        const year = fecha.getFullYear();
        const month = String(fecha.getMonth() + 1).padStart(2, '0');
        const day = String(fecha.getDate()).padStart(2, '0');
        const hours = String(fecha.getHours()).padStart(2, '0');
        const minutes = String(fecha.getMinutes()).padStart(2, '0');
        const fechaInput = document.getElementById('fecha_programada');
        if(fechaInput) {
            fechaInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
        }
    }

    // Event listeners para cambios en días
    const diaSemanaSelect = document.querySelector('[name="dia_semana"]');
    const diaMesSelect = document.querySelector('[name="dia_mes"]');
    const diaMesAnual = document.getElementById('dia_mes_anual');
    const mesAnual = document.getElementById('mes_anual');
    
    if(diaSemanaSelect) diaSemanaSelect.addEventListener('change', actualizarFechaPorRecurrencia);
    if(diaMesSelect) diaMesSelect.addEventListener('change', actualizarFechaPorRecurrencia);
    if(diaMesAnual) diaMesAnual.addEventListener('change', actualizarFechaPorRecurrencia);
    if(mesAnual) mesAnual.addEventListener('change', actualizarFechaPorRecurrencia);

    // Auto-cerrar alerta después de 5 segundos
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            if(alert) {
                alert.style.animation = 'slideDown 0.3s reverse';
                setTimeout(() => alert.remove(), 300);
            }
        });
    }, 5000);

    // Inicializar
    actualizarUnidad();
    </script>
</body>
</html>