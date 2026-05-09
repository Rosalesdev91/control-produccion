<?php
/**
 * auto_audit_empleados.php
 * SISTEMA DE AUDITORÍA AUTOMÁTICA PARA EMPLEADOS
 * 
 * Registra las acciones de los empleados en el feed de actividad
 * (NO en auditoria_cambios porque los empleados no modifican datos críticos)
 * 
 * By: Nestor Rosales | Rosales_Dev91
 */

// Verificar que estamos en una sesión de empleado
if (!isset($_SESSION['empleado']) && !isset($_SESSION['nombre_empleado']) && !isset($_SESSION['codigoEmpleado'])) {
    return; // No auditar si no hay sesión de empleado
}

// Cargar funciones de auditoría
if (!function_exists('registrar_actividad')) {
    require_once __DIR__ . '/registrar_actividad.php';
}

// Obtener nombre del empleado (desde cualquier variable de sesión posible)
$empleado_nombre = $_SESSION['empleado'] ?? $_SESSION['nombre_empleado'] ?? $_SESSION['nombreEmpleado'] ?? 'Empleado';
$codigo_empleado = $_SESSION['codigo_empleado'] ?? $_SESSION['codigoEmpleado'] ?? '—';

/**
 * CLASE: Auditoría Automática para Empleados
 * Detecta y registra acciones sin modificar el código original
 */
class AutoAuditEmpleados {
    private $conn;
    private $empleado_nombre;
    private $codigo_empleado;
    
    // Mapeo de acciones según los formularios
    private $acciones_map = [
        // registro.php - Producción
        'consultar_empleado' => ['accion' => 'login', 'desc' => 'Consultó su información'],
        'cambiar_empleado'   => ['accion' => 'otro', 'desc' => 'Cambió de empleado'],
        'cambiar_area'       => ['accion' => 'otro', 'desc' => 'Cambió de área de trabajo'],
        'cambiar_equipo'     => ['accion' => 'otro', 'desc' => 'Cambió de equipo'],
        
        // registro_asistencia.php
        'registrar_marca'    => ['accion' => 'agregar', 'desc' => 'Registró marca de asistencia'],
        
        // registro_paro.php
        'crear_solicitud'    => ['accion' => 'agregar', 'desc' => 'Creó solicitud de paro'],
        'seleccionar_area_equipo' => ['accion' => 'otro', 'desc' => 'Seleccionó área/equipo'],
        
        // registro_picking.php
        'cambiar_proceso'    => ['accion' => 'otro', 'desc' => 'Cambió de proceso'],
        
        // registro_quiebras.php
        'registrar_quiebra'  => ['accion' => 'agregar', 'desc' => 'Registró quiebra de lente'],
    ];
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->empleado_nombre = $GLOBALS['empleado_nombre'] ?? 'Empleado';
        $this->codigo_empleado = $GLOBALS['codigo_empleado'] ?? '—';
        $this->detectarYAuditar();
    }
    
    /**
     * Detecta automáticamente qué acción se está ejecutando
     */
    private function detectarYAuditar() {
        // 1. DETECTAR ENVÍO DE ÓRDENES (registro.php - AJAX)
        if ($this->isAjaxRequest() && isset($_POST['orden1']) && !empty($_POST['orden1'])) {
            $this->auditarOrdenProduccion($_POST['orden1']);
            return;
        }
        
        // 2. DETECTAR ENVÍO DE REFERENCIAS (registro_picking.php - AJAX)
        if ($this->isAjaxRequest() && isset($_POST['referencia1']) && !empty($_POST['referencia1'])) {
            $this->auditarReferenciaPicking($_POST['referencia1']);
            return;
        }
        
        // 3. DETECTAR POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($this->acciones_map as $campo => $config) {
                if (isset($_POST[$campo])) {
                    $this->auditarPorPOST($campo, $config);
                    break;
                }
            }
        }
        
        // 4. DETECTAR SOLICITUDES DE PARO específicas (registro_paro.php)
        if (isset($_POST['crear_solicitud'])) {
            $this->auditarCreacionParo($_POST);
        }
    }
    
    /**
     * Verifica si es una petición AJAX
     */
    private function isAjaxRequest(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Audita envío de orden de producción
     */
    private function auditarOrdenProduccion($orden) {
        $area = $_SESSION['area_seleccionada'] ?? 'Área no seleccionada';
        $equipo = $_SESSION['equipo_seleccionado'] ?? 'Sin equipo';
        
        registrar_actividad(
            $this->conn,
            'agregar',
            $this->empleado_nombre,
            "📦 Registró orden de producción: {$orden} | Área: {$area} | Equipo: {$equipo}"
        );
    }
    
    /**
     * Audita envío de referencia de picking
     */
    private function auditarReferenciaPicking($referencia) {
        $proceso = $_SESSION['proceso_seleccionado'] ?? 'Proceso no seleccionado';
        
        registrar_actividad(
            $this->conn,
            'agregar',
            $this->empleado_nombre,
            "📦 Registró referencia de picking: {$referencia} | Proceso: {$proceso}"
        );
    }
    
    /**
     * Audita creación de solicitud de paro
     */
    private function auditarCreacionParo($data) {
        $tipo_paro = $data['tipo_paro'] ?? 'No especificado';
        $motivo = substr($data['motivo_solicitud'] ?? '', 0, 100);
        $area = $_SESSION['area_seleccionada'] ?? 'No especificada';
        $equipo = $_SESSION['equipo_seleccionado'] ?? 'No especificado';
        
        registrar_actividad(
            $this->conn,
            'agregar',
            $this->empleado_nombre,
            "⚠️ Creó solicitud de paro | Tipo: {$tipo_paro} | Área: {$area} | Equipo: {$equipo} | Motivo: {$motivo}"
        );
    }
    
    /**
     * Audita acciones genéricas por POST
     */
    private function auditarPorPOST($campo, $config) {
        $accion = $config['accion'];
        $desc_base = $config['desc'];
        
        switch ($campo) {
            case 'registrar_marca':
                $tipo_marca = $_POST['tipo_marca'] ?? 'desconocida';
                $nombres_marcas = [
                    'cafe1_salida' => '☕ Café 1 - Salida',
                    'cafe1_entrada' => '☕ Café 1 - Entrada',
                    'comida_salida' => '🍽️ Comida - Salida',
                    'comida_entrada' => '🍽️ Comida - Entrada',
                    'cafe2_salida' => '☕ Café 2 - Salida',
                    'cafe2_entrada' => '☕ Café 2 - Entrada'
                ];
                $marca_nombre = $nombres_marcas[$tipo_marca] ?? $tipo_marca;
                
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    "🕐 {$desc_base}: {$marca_nombre}"
                );
                break;
                
            case 'crear_solicitud':
                // Ya se maneja en auditarCreacionParo
                break;
                
            case 'seleccionar_area_equipo':
                $area = $_POST['area'] ?? 'No especificada';
                $equipo = $_POST['equipo'] ?? 'Sin equipo';
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    "📍 Seleccionó área: {$area} | Equipo: {$equipo}"
                );
                break;
                
            case 'cambiar_area':
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    "🔄 Cambió de área de trabajo"
                );
                break;
                
            case 'cambiar_equipo':
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    "🔄 Cambió de equipo"
                );
                break;
                
            case 'cambiar_proceso':
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    "🔄 Cambió de proceso en picking"
                );
                break;
                
            case 'cambiar_empleado':
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    "🔄 Cambió de empleado"
                );
                break;
                
            default:
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->empleado_nombre,
                    $desc_base
                );
                break;
        }
    }
}

// ============================================================================
// EJECUCIÓN AUTOMÁTICA - para solicitudes_paro.php (técnicos)
// ============================================================================

// Detectar acciones específicas de solicitudes_paro.php (técnicos)
if (basename($_SERVER['PHP_SELF']) === 'solicitudes_paro.php') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tecnico_nombre = $_SESSION['nombre_tecnico'] ?? 'Técnico';
        
        if (isset($_POST['iniciar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad(
                $conn,
                'modificar',
                $tecnico_nombre,
                "🔧 Inició atención de paro ID #{$id}"
            );
        }
        
        if (isset($_POST['pausar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            $comentario = substr($_POST['comentario_pausa'] ?? '', 0, 50);
            registrar_actividad(
                $conn,
                'otro',
                $tecnico_nombre,
                "⏸️ Pausó paro ID #{$id} - Motivo: {$comentario}"
            );
        }
        
        if (isset($_POST['reanudar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad(
                $conn,
                'otro',
                $tecnico_nombre,
                "▶️ Reanudó paro ID #{$id}"
            );
        }
        
        if (isset($_POST['finalizar_paro'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad(
                $conn,
                'modificar',
                $tecnico_nombre,
                "✅ Finalizó paro ID #{$id}"
            );
        }
        
        if (isset($_POST['rechazar_solicitud'])) {
            $id = $_POST['id_solicitud'] ?? 0;
            registrar_actividad(
                $conn,
                'eliminar',
                $tecnico_nombre,
                "❌ Rechazó solicitud de paro ID #{$id}"
            );
        }
    }
}

// ============================================================================
// INICIALIZAR AUDITORÍA
// ============================================================================

// Solo ejecutar si hay conexión activa y no es una petición de exportación
if (isset($conn) && $conn && !$conn->connect_error) {
    // Evitar auditar en peticiones de exportación
    if (!isset($_POST['exportar_csv']) && !isset($_GET['exportar'])) {
        new AutoAuditEmpleados($conn);
    }
}
?>