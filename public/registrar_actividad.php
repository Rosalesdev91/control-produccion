<?php
/**
 * registrar_actividad.php
 * Funciones centralizadas para registrar actividad y auditoría de cambios.
 * VERSIÓN CORREGIDA Y MEJORADA - Incluye todas las acciones de los módulos
 *
 * By: Nestor Rosales | Rosales_Dev91
 */

// ── Helper: obtener IP real del cliente ──
if (!function_exists('getRealIP')) {
    function getRealIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))       return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        if (!empty($_SERVER['HTTP_X_REAL_IP']))       return $_SERVER['HTTP_X_REAL_IP'];
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================================
// FUNCIÓN PARA LIMPIAR RESULTADOS PENDIENTES (MÁS ROBUSTA)
// ============================================================================
function limpiar_resultados($conn) {
    if (!$conn) return;
    
    // Limpiar todos los resultados pendientes
    while ($conn->more_results()) {
        $conn->next_result();
        if ($result = $conn->store_result()) {
            $result->free();
        }
    }
}

// ============================================================================
// VERIFICAR Y CREAR TABLA SI NO EXISTE
// ============================================================================
$tabla_actividad_verificada = false;
$tabla_auditoria_verificada = false;

function verificar_tabla_actividad($conn) {
    global $tabla_actividad_verificada;
    if ($tabla_actividad_verificada) return true;
    
    try {
        limpiar_resultados($conn);
        
        // Verificar si la tabla existe
        $check = $conn->query("SHOW TABLES LIKE 'actividad_monitor'");
        if ($check && $check->num_rows === 0) {
            $check->free();
            limpiar_resultados($conn);
            
            $conn->query("
                CREATE TABLE IF NOT EXISTS actividad_monitor (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    tipo       VARCHAR(50) NOT NULL,
                    usuario    VARCHAR(100) NOT NULL,
                    detalle    VARCHAR(500) NOT NULL,
                    ip         VARCHAR(45),
                    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tipo (tipo),
                    INDEX idx_fecha (fecha_hora),
                    INDEX idx_usuario (usuario)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            limpiar_resultados($conn);
        } elseif ($check) {
            $check->free();
        }
        
        $tabla_actividad_verificada = true;
        return true;
    } catch (Exception $e) {
        error_log("Error al verificar/crear tabla actividad_monitor: " . $e->getMessage());
        return false;
    }
}

function verificar_tabla_auditoria($conn) {
    global $tabla_auditoria_verificada;
    if ($tabla_auditoria_verificada) return true;
    
    try {
        limpiar_resultados($conn);
        
        $check = $conn->query("SHOW TABLES LIKE 'auditoria_cambios'");
        if ($check && $check->num_rows === 0) {
            $check->free();
            limpiar_resultados($conn);
            
            $conn->query("
                CREATE TABLE IF NOT EXISTS auditoria_cambios (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    tipo_accion     VARCHAR(50) NOT NULL,
                    tabla_afectada  VARCHAR(100) NOT NULL,
                    id_registro     INT DEFAULT 0,
                    descripcion     VARCHAR(500) NOT NULL,
                    datos_antes     JSON,
                    datos_despues   JSON,
                    admin_nombre    VARCHAR(150) NOT NULL,
                    admin_codigo    VARCHAR(50) NOT NULL,
                    ip              VARCHAR(45),
                    user_agent      VARCHAR(300),
                    fecha_hora      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tipo (tipo_accion),
                    INDEX idx_tabla (tabla_afectada),
                    INDEX idx_admin (admin_codigo),
                    INDEX idx_fecha (fecha_hora)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            limpiar_resultados($conn);
        } elseif ($check) {
            $check->free();
        }
        
        $tabla_auditoria_verificada = true;
        return true;
    } catch (Exception $e) {
        error_log("Error al verificar/crear tabla auditoria_cambios: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// FUNCIÓN PRINCIPAL DE REGISTRO (SIN prepare PARA EVITAR ERRORES)
// ============================================================================
function registrar_actividad($conn, $tipo, $usuario, $detalle, $ip = null) {
    if (!$conn) return false;

    // Verificar/Crear tabla
    verificar_tabla_actividad($conn);

    if ($ip === null) $ip = getRealIP();
    
    // Limitar detalles
    $detalle = substr($detalle, 0, 500);
    $usuario = substr($usuario, 0, 100);
    $tipo = substr($tipo, 0, 50);
    $ip = substr($ip, 0, 45);
    
    // Limpiar resultados pendientes
    limpiar_resultados($conn);
    
    // Escapar valores para SQL (alternativa segura a prepare)
    $tipo_esc = $conn->real_escape_string($tipo);
    $usuario_esc = $conn->real_escape_string($usuario);
    $detalle_esc = $conn->real_escape_string($detalle);
    $ip_esc = $conn->real_escape_string($ip);
    
    // Insertar usando query() en lugar de prepare() para evitar errores de sincronización
    $sql = "INSERT INTO actividad_monitor (tipo, usuario, detalle, ip, fecha_hora) 
            VALUES ('$tipo_esc', '$usuario_esc', '$detalle_esc', '$ip_esc', NOW())";
    
    $ok = $conn->query($sql);
    
    // Limpiar resultados pendientes después
    limpiar_resultados($conn);
    
    return $ok;
}

// ============================================================================
// AUTO-DETECCIÓN DE ACCIONES
// ============================================================================

// Solo ejecutar si hay una conexión activa y estamos en una petición POST/GET
if (isset($conn) && $conn && !$conn->connect_error && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['eliminar']))) {
    
    // Limpiar resultados pendientes antes de empezar
    limpiar_resultados($conn);
    
    // Obtener usuario y IP
    $usuario = $_SESSION['nombre_empleado'] ?? $_SESSION['empleado'] ?? $_SESSION['nombre_tecnico'] ?? $_SESSION['nombreEmpleado'] ?? 'Desconocido';
    $ip = $_SESSION['ip'] ?? getRealIP();
    
    // ========================================================================
    // DETECTAR ACCIONES POR GET (eliminaciones)
    // ========================================================================
    if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
        registrar_actividad($conn, 'eliminar', $usuario, "Eliminó registro ID: " . $_GET['eliminar'], $ip);
        limpiar_resultados($conn);
    }
    
    // ========================================================================
    // DETECTAR ACCIONES POR POST
    // ========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- Registro de producción (registro.php) ---
        if (isset($_POST['orden1']) && !empty($_POST['orden1']) && 
            !isset($_POST['consultar_empleado']) && 
            !isset($_POST['cambiar_empleado']) && 
            !isset($_POST['cambiar_area']) && 
            !isset($_POST['cambiar_equipo']) &&
            !isset($_POST['area_id']) &&
            !isset($_POST['equipo_id'])) {
            
            $orden = $_POST['orden1'];
            $area = $_SESSION['area_seleccionada'] ?? 'Área no seleccionada';
            $equipo = $_SESSION['equipo_seleccionado'] ?? 'Sin equipo';
            registrar_actividad($conn, 'agregar', $usuario, "📦 Registró orden de producción: {$orden} | Área: {$area} | Equipo: {$equipo}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Selección de área (registro.php) ---
        if (isset($_POST['area_id']) && !empty($_POST['area_id']) && isset($_SESSION['nombre_empleado'])) {
            $area_nombre = '';
            $area_id_esc = $conn->real_escape_string($_POST['area_id']);
            $res = $conn->query("SELECT area FROM areas WHERE id = '$area_id_esc'");
            if ($res && $row = $res->fetch_assoc()) {
                $area_nombre = $row['area'];
                $res->free();
            }
            limpiar_resultados($conn);
            
            if ($area_nombre) {
                registrar_actividad($conn, 'otro', $usuario, "📍 Seleccionó área: {$area_nombre} (ID: {$_POST['area_id']})", $ip);
                limpiar_resultados($conn);
            }
        }
        
        // --- Selección de equipo (registro.php) ---
        if (isset($_POST['equipo_id']) && !empty($_POST['equipo_id']) && isset($_SESSION['nombre_empleado'])) {
            $equipo_nombre = '';
            $equipo_id_esc = $conn->real_escape_string($_POST['equipo_id']);
            $res = $conn->query("SELECT nombre_equipo FROM equipos WHERE id = '$equipo_id_esc'");
            if ($res && $row = $res->fetch_assoc()) {
                $equipo_nombre = $row['nombre_equipo'];
                $res->free();
            }
            limpiar_resultados($conn);
            
            if ($equipo_nombre) {
                registrar_actividad($conn, 'otro', $usuario, "🔧 Seleccionó equipo: {$equipo_nombre} (ID: {$_POST['equipo_id']})", $ip);
                limpiar_resultados($conn);
            }
        }
        
        // --- Selección de turno (registro.php) ---
        if (isset($_POST['turno']) && !empty($_POST['turno']) && isset($_SESSION['nombre_empleado'])) {
            registrar_actividad($conn, 'otro', $usuario, "⏰ Seleccionó turno: {$_POST['turno']}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Cambio de empleado (registro.php / registro_asistencia.php) ---
        if (isset($_POST['cambiar_empleado']) && isset($_SESSION['nombre_empleado'])) {
            $pagina_actual = basename($_SERVER['PHP_SELF'], '.php');
            $modulo = ($pagina_actual === 'registro_asistencia') ? 'asistencia' : 'producción';
            registrar_actividad($conn, 'login', $usuario, "🚪 Cerró sesión del sistema de {$modulo}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Cambio de área (registro.php) ---
        if (isset($_POST['cambiar_area']) && isset($_SESSION['nombre_empleado']) && isset($_SESSION['area_seleccionada'])) {
            registrar_actividad($conn, 'otro', $usuario, "🔄 Cambió de área: {$_SESSION['area_seleccionada']} → (seleccionando nueva)", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Cambio de equipo (registro.php) ---
        if (isset($_POST['cambiar_equipo']) && isset($_SESSION['nombre_empleado']) && isset($_SESSION['equipo_seleccionado'])) {
            registrar_actividad($conn, 'otro', $usuario, "🔄 Cambió de equipo: {$_SESSION['equipo_seleccionado']} → (seleccionando nuevo)", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Registro de picking (registro_picking.php) ---
        if (isset($_POST['referencia1']) && !empty($_POST['referencia1']) && 
            !isset($_POST['consultar_empleado']) && 
            !isset($_POST['cambiar_empleado']) && 
            !isset($_POST['cambiar_proceso']) &&
            !isset($_POST['proceso_id'])) {
            
            $referencia = $_POST['referencia1'];
            $proceso = $_SESSION['proceso_seleccionado'] ?? 'Proceso no seleccionado';
            registrar_actividad($conn, 'agregar', $usuario, "📦 Registró referencia de picking: {$referencia} | Proceso: {$proceso}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Cambiar proceso (registro_picking.php) ---
        if (isset($_POST['cambiar_proceso']) && isset($_SESSION['nombreEmpleado'])) {
            registrar_actividad($conn, 'otro', $usuario, "🔄 Cambió de proceso en módulo de picking", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Selección de proceso por ID (registro_picking.php) ---
        if (isset($_POST['proceso_id']) && !empty($_POST['proceso_id']) && isset($_SESSION['nombreEmpleado'])) {
            $proceso_nombre = '';
            $proceso_id_esc = $conn->real_escape_string($_POST['proceso_id']);
            $res = $conn->query("SELECT proceso FROM procesos_picking WHERE id = '$proceso_id_esc'");
            if ($res && $row = $res->fetch_assoc()) {
                $proceso_nombre = $row['proceso'];
                $res->free();
            }
            limpiar_resultados($conn);
            
            if ($proceso_nombre) {
                registrar_actividad($conn, 'otro', $usuario, "📋 Seleccionó proceso: {$proceso_nombre} (ID: {$_POST['proceso_id']})", $ip);
                limpiar_resultados($conn);
            }
        }
        
        // --- Registro de asistencia (registro_asistencia.php) ---
        if (isset($_POST['registrar_marca']) && isset($_POST['tipo_marca'])) {
            $tipo_marca = $_POST['tipo_marca'];
            $nombres_marcas = [
                'cafe1_salida' => '☕ Café 1 - Salida',
                'cafe1_entrada' => '☕ Café 1 - Entrada',
                'comida_salida' => '🍽️ Comida - Salida',
                'comida_entrada' => '🍽️ Comida - Entrada',
                'cafe2_salida' => '☕ Café 2 - Salida',
                'cafe2_entrada' => '☕ Café 2 - Entrada'
            ];
            $marca_nombre = $nombres_marcas[$tipo_marca] ?? $tipo_marca;
            registrar_actividad($conn, 'agregar', $usuario, "⏰ Registró marca de asistencia: {$marca_nombre}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Solicitud de paro (registro_paro.php) ---
        if (isset($_POST['crear_solicitud']) && !isset($_POST['cambiar_area_equipo'])) {
            $tipo_paro = $_POST['tipo_paro'] ?? 'No especificado';
            $motivo = substr($_POST['motivo_solicitud'] ?? '', 0, 100);
            $area = $_SESSION['area_seleccionada'] ?? 'No especificada';
            $equipo = $_SESSION['equipo_seleccionado'] ?? 'No especificado';
            registrar_actividad($conn, 'agregar', $usuario, "⚠️ Creó solicitud de paro | Tipo: {$tipo_paro} | Área: {$area} | Equipo: {$equipo} | Motivo: {$motivo}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Selección de área/equipo (registro_paro.php) ---
        if (isset($_POST['seleccionar_area_equipo'])) {
            $area = $_POST['area'] ?? 'No especificada';
            $equipo = $_POST['equipo'] ?? 'No especificado';
            registrar_actividad($conn, 'otro', $usuario, "📍 Seleccionó área: {$area} | Equipo: {$equipo} en módulo de paros", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Cambio de área/equipo (registro_paro.php) ---
        if (isset($_POST['cambiar_area_equipo'])) {
            registrar_actividad($conn, 'otro', $usuario, "🔄 Cambió área/equipo en módulo de paros", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Forzar finalización de paro (registro_paro.php - supervisores) ---
        if (isset($_POST['forzar_finalizacion_paro'])) {
            $id_paro = (int)($_POST['id_paro_forzar'] ?? 0);
            registrar_actividad($conn, 'modificar', $usuario, "⚠️ FORZÓ finalización de paro ID #{$id_paro} (acción de supervisor)", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Finalizar paro (para empleados con tipo 'Sin WIP') ---
        if (isset($_POST['finalizar_paro']) && isset($_POST['id_solicitud']) && !isset($_POST['comentario_final'])) {
            $id_solicitud = (int)$_POST['id_solicitud'];
            registrar_actividad($conn, 'modificar', $usuario, "✅ Finalizó paro (Sin WIP) ID #{$id_solicitud}", $ip);
            limpiar_resultados($conn);
        }
        
        // ===== TAREAS TÉCNICO (tareas_tecnico.php) =====
        
        // --- Tomar tarea ---
        if (isset($_POST['tomar_tarea'])) {
            $id_tarea = (int)($_POST['id_tarea'] ?? 0);
            $accion = $_POST['accion_tomar'] ?? 'solo_asignar';
            $tipo_accion = ($accion === 'asignar_iniciar') ? 'iniciar' : 'asignar';
            registrar_actividad($conn, $tipo_accion, $usuario, "📋 Tomó la tarea ID #{$id_tarea}" . ($accion === 'asignar_iniciar' ? ' y la inició' : ''), $ip);
            limpiar_resultados($conn);
        }
        
        // --- Iniciar tarea ---
        if (isset($_POST['iniciar_tarea'])) {
            $id_tarea = (int)($_POST['id_tarea'] ?? 0);
            registrar_actividad($conn, 'iniciar', $usuario, "▶️ Inició la tarea ID #{$id_tarea}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Pausar tarea ---
        if (isset($_POST['pausar_tarea'])) {
            $id_tarea = (int)($_POST['id_tarea'] ?? 0);
            $comentario = substr($_POST['comentario_pausa'] ?? '', 0, 50);
            registrar_actividad($conn, 'pausar', $usuario, "⏸️ Pausó la tarea ID #{$id_tarea} - Motivo: {$comentario}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Reanudar tarea ---
        if (isset($_POST['reanudar_tarea'])) {
            $id_tarea = (int)($_POST['id_tarea'] ?? 0);
            registrar_actividad($conn, 'reanudar', $usuario, "▶️ Reanudó la tarea ID #{$id_tarea}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Finalizar tarea ---
        if (isset($_POST['finalizar_tarea'])) {
            $id_tarea = (int)($_POST['id_tarea'] ?? 0);
            registrar_actividad($conn, 'finalizar', $usuario, "✅ Finalizó la tarea ID #{$id_tarea}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Agregar comentario a tarea ---
        if (isset($_POST['agregar_comentario'])) {
            $id_tarea = (int)($_POST['id_tarea'] ?? 0);
            registrar_actividad($conn, 'comentar', $usuario, "💬 Agregó comentario a la tarea ID #{$id_tarea}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Registro de quiebra (registro_quiebras.php) ---
        if (isset($_POST['registrar_quiebra']) && !isset($_POST['consultar_empleado'])) {
            $orden = $_POST['orden'] ?? 'Sin orden';
            $motivo = $_POST['motivo'] ?? 'Sin motivo';
            $responsable = $_POST['responsable'] ?? 'Sin responsable';
            $empleado_responsable = $_POST['empleado'] ?? '';
            $equipo = $_POST['equipo'] ?? '';
            
            $detalle_quiebra = "💔 Registró quiebra | Orden: {$orden} | Motivo: {$motivo} | Responsable: {$responsable}";
            if (!empty($empleado_responsable)) {
                $detalle_quiebra .= " | Empleado: {$empleado_responsable}";
            }
            if (!empty($equipo)) {
                $detalle_quiebra .= " | Equipo: {$equipo}";
            }
            
            registrar_actividad($conn, 'agregar', $usuario, $detalle_quiebra, $ip);
            limpiar_resultados($conn);
        }
        
        // --- Agregar empleado (dashboard_admin_empleados.php) ---
        if (isset($_POST['agregar_empleado'])) {
            $nombre = $_POST['nombre_empleado'] ?? 'Desconocido';
            $codigo = $_POST['codigo_empleado'] ?? 'Sin código';
            registrar_actividad($conn, 'agregar', $usuario, "➕ Agregó empleado: {$nombre} (Código: {$codigo})", $ip);
            limpiar_resultados($conn);
            
            registrar_cambio_admin($conn, 'agregar', 'empleados', "Agregó empleado: {$nombre}", [], ['nombre' => $nombre, 'codigo' => $codigo], 0, $usuario, null, $ip);
            limpiar_resultados($conn);
        }
        
        // --- Modificar rol de empleado (dashboard_admin_empleados.php) ---
        if (isset($_POST['modificar_rol'])) {
            $id = $_POST['id_empleado'] ?? 0;
            $nuevo_rol = $_POST['nuevo_rol'] ?? 'empleado';
            registrar_actividad($conn, 'modificar', $usuario, "✏️ Modificó rol de empleado ID #{$id} a: {$nuevo_rol}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Eliminar quiebra (dashboard_admin_quiebras.php) ---
        if (isset($_POST['eliminar_quiebra_id'])) {
            $id = $_POST['eliminar_quiebra_id'];
            registrar_actividad($conn, 'eliminar', $usuario, "🗑️ Eliminó registro de quiebra ID #{$id}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Iniciar paro (solicitudes_paro.php - técnicos) ---
        if (isset($_POST['iniciar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad($conn, 'modificar', $usuario, "🔧 Inició atención de paro ID #{$id}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Finalizar paro (solicitudes_paro.php - técnicos) ---
        if (isset($_POST['finalizar_paro']) && isset($_POST['comentario_final'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad($conn, 'modificar', $usuario, "✅ Finalizó paro ID #{$id} | Solución: " . substr($_POST['comentario_final'] ?? '', 0, 50), $ip);
            limpiar_resultados($conn);
        }
        
        // --- Pausar paro (solicitudes_paro.php - técnicos) ---
        if (isset($_POST['pausar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            $comentario = substr($_POST['comentario_pausa'] ?? '', 0, 50);
            registrar_actividad($conn, 'otro', $usuario, "⏸️ Pausó paro ID #{$id} - Motivo: {$comentario}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Reanudar paro (solicitudes_paro.php - técnicos) ---
        if (isset($_POST['reanudar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad($conn, 'otro', $usuario, "▶️ Reanudó paro ID #{$id}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Rechazar solicitud de paro (solicitudes_paro.php - técnicos) ---
        if (isset($_POST['rechazar_solicitud'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad($conn, 'eliminar', $usuario, "❌ Rechazó solicitud de paro ID #{$id}", $ip);
            limpiar_resultados($conn);
        }
        
        // --- Login (cualquier archivo de login) ---
        $login_files = ['login_monitor.php', 'login_admin.php', 'login_paros.php', 'login_picking.php', 'login.php', 'login_tareas.php'];
        if (in_array(basename($_SERVER['PHP_SELF']), $login_files)) {
            if (isset($_POST['consultar_empleado']) || isset($_POST['login']) || isset($_POST['codigo_empleado'])) {
                $login_usuario = $_POST['codigo_empleado'] ?? $_POST['empleado'] ?? $usuario;
                registrar_actividad($conn, 'login', $login_usuario, "🔐 Inició sesión en el sistema", $ip);
                limpiar_resultados($conn);
            }
        }
        
        // --- Exportaciones CSV (cualquier dashboard) ---
        if (isset($_POST['exportar_csv']) || isset($_GET['exportar'])) {
            $modulo = basename($_SERVER['HTTP_REFERER'] ?? '', '.php');
            registrar_actividad($conn, 'otro', $usuario, "📎 Exportó datos a CSV desde {$modulo}", $ip);
            limpiar_resultados($conn);
        }
        
// --- Login de empleado en producción (consultar_empleado en registro.php) ---
if (isset($_POST['consultar_empleado']) && !empty($_POST['codigo_empleado']) && basename($_SERVER['PHP_SELF']) === 'registro.php') {
    // Verificar si el empleado existe para saber si fue exitoso o no
    $codigo = $_POST['codigo_empleado'];
    
    // Usar query() en lugar de prepare() para evitar errores
    $codigo_esc = $conn->real_escape_string($codigo);
    $res = $conn->query("SELECT nombre_empleado FROM empleados WHERE codigo_empleado = '$codigo_esc'");
    
    $existe = false;
    $nombre_encontrado = null;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $nombre_encontrado = $row['nombre_empleado'];
        $existe = true;
        $res->free();
    }
    limpiar_resultados($conn);
    
    if ($existe) {
        // Login exitoso - usar el NOMBRE del empleado
        registrar_actividad($conn, 'login', $nombre_encontrado, "🔐 Inició sesión exitosamente en el sistema de producción | Código: {$codigo}", $ip);
    } else {
        // Login fallido - mostrar código
        registrar_actividad($conn, 'login', $codigo, "❌ Intento fallido de inicio de sesión con código: {$codigo}", $ip);
    }
    limpiar_resultados($conn);
}
        
// --- Login de técnico en tareas (consultar_empleado en login_tareas.php) ---
if (isset($_POST['consultar_empleado']) && !empty($_POST['codigo_empleado']) && basename($_SERVER['PHP_SELF']) === 'login_tareas.php') {
    $codigo = $_POST['codigo_empleado'];
    registrar_actividad($conn, 'login', $codigo, "🔐 Intento de inicio de sesión en sistema de tareas | Código: {$codigo}", $ip);
    limpiar_resultados($conn);
}
        
        // --- Login de picking (consultar_empleado en registro_picking.php) ---
        if (isset($_POST['consultar_empleado']) && !empty($_POST['codigo_empleado']) && basename($_SERVER['PHP_SELF']) === 'registro_picking.php') {
            $codigo = $_POST['codigo_empleado'];
            registrar_actividad($conn, 'login', $codigo, "🔐 Intento de inicio de sesión en sistema de picking | Código: {$codigo}", $ip);
            limpiar_resultados($conn);
        }
    }
}

// ============================================================================
// FUNCIÓN DE AUDITORÍA (SIN prepare)
// ============================================================================
function registrar_cambio_admin(
    $conn,
    $tipo_accion,
    $tabla_afectada,
    $descripcion,
    $datos_antes   = [],
    $datos_despues = [],
    $id_registro   = 0,
    $admin_nombre  = null,
    $admin_codigo  = null,
    $ip            = null
) {
    if (!$conn) return false;

    verificar_tabla_auditoria($conn);
    limpiar_resultados($conn);

    if ($admin_nombre === null) $admin_nombre = $_SESSION['empleado'] ?? $_SESSION['nombre_empleado'] ?? $_SESSION['nombre_tecnico'] ?? 'Desconocido';
    if ($admin_codigo === null) $admin_codigo = $_SESSION['codigo_empleado'] ?? $_SESSION['id_tecnico'] ?? '—';
    if ($ip === null) $ip = getRealIP();

    $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido', 0, 300);
    $json_antes = !empty($datos_antes) ? json_encode($datos_antes, JSON_UNESCAPED_UNICODE) : null;
    $json_despues = !empty($datos_despues) ? json_encode($datos_despues, JSON_UNESCAPED_UNICODE) : null;
    
    // Escapar valores
    $tipo_accion_esc = $conn->real_escape_string($tipo_accion);
    $tabla_afectada_esc = $conn->real_escape_string($tabla_afectada);
    $descripcion_esc = $conn->real_escape_string($descripcion);
    $admin_nombre_esc = $conn->real_escape_string($admin_nombre);
    $admin_codigo_esc = $conn->real_escape_string($admin_codigo);
    $ip_esc = $conn->real_escape_string($ip);
    $user_agent_esc = $conn->real_escape_string($user_agent);
    
    $json_antes_sql = $json_antes ? "'" . $conn->real_escape_string($json_antes) . "'" : "NULL";
    $json_despues_sql = $json_despues ? "'" . $conn->real_escape_string($json_despues) . "'" : "NULL";
    
    $sql = "INSERT INTO auditoria_cambios 
            (tipo_accion, tabla_afectada, id_registro, descripcion, datos_antes, datos_despues, admin_nombre, admin_codigo, ip, user_agent, fecha_hora) 
            VALUES ('$tipo_accion_esc', '$tabla_afectada_esc', $id_registro, '$descripcion_esc', $json_antes_sql, $json_despues_sql, '$admin_nombre_esc', '$admin_codigo_esc', '$ip_esc', '$user_agent_esc', NOW())";
    
    $ok = $conn->query($sql);
    limpiar_resultados($conn);
    
    return $ok;
}

/**
 * Shortcut: registra en feed Y en auditoría de una sola vez
 */
function registrar_accion_completa(
    $conn,
    $tipo_accion,
    $tabla_afectada,
    $descripcion,
    $datos_antes   = [],
    $datos_despues = [],
    $id_registro   = 0
) {
    $usuario = $_SESSION['empleado'] ?? $_SESSION['nombre_empleado'] ?? $_SESSION['nombre_tecnico'] ?? 'Admin';
    registrar_actividad($conn, $tipo_accion, $usuario, $descripcion);
    return registrar_cambio_admin($conn, $tipo_accion, $tabla_afectada, $descripcion, $datos_antes, $datos_despues, $id_registro);
}

// ============================================================================
// REGISTRAR ACCESO A PÁGINAS (para tracking)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($conn) && $conn && !$conn->connect_error) {
    $pagina_actual = basename($_SERVER['PHP_SELF'], '.php');
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ultimo_modulo'] = $pagina_actual;
        $_SESSION['ultima_actividad'] = time();
    }
}
?>