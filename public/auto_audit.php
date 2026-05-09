<?php
/**
 * auto_audit.php
 * SISTEMA DE AUDITORÍA AUTOMÁTICA - NO requiere modificar los dashboards existentes
 * 
 * Este archivo intercepta automáticamente las operaciones CRUD y las registra en auditoría.
 * 
 * Cómo funciona:
 * 1. Se incluye al inicio de cada dashboard
 * 2. Captura automáticamente POST/GET que modifican datos
 * 3. Registra quién, qué, cuándo y cómo
 * 
 * By: Nestor Rosales | Rosales_Dev91
 */

// Verificar que estamos en una sesión de administrador
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    return; // No auditar si no es admin
}

// Cargar funciones de auditoría
require_once __DIR__ . '/registrar_actividad.php';

/**
 * CLASE: Auditoría Automática
 * Detecta y registra operaciones sin modificar el código original
 */
class AutoAudit {
    private $conn;
    private $admin_nombre;
    private $admin_codigo;
    
    // Mapeo de acciones según los nombres de los formularios
    private $acciones_map = [
        // dashboard_admin_empleados.php
        'agregar_empleado' => ['accion' => 'agregar', 'tabla' => 'empleados', 'desc' => 'Agregó empleado'],
        'modificar_rol'    => ['accion' => 'modificar', 'tabla' => 'empleados', 'desc' => 'Modificó rol de empleado'],
        
        // dashboard_admin_quiebras.php
        'eliminar_quiebra_id' => ['accion' => 'eliminar', 'tabla' => 'registro_quiebras', 'desc' => 'Eliminó registro de quiebra'],
        
        // dashboard_admin_produccion.php
        'exportar_csv'     => ['accion' => 'otro', 'tabla' => 'produccion', 'desc' => 'Exportó datos de producción'],
        'buscar_orden'     => ['accion' => 'otro', 'tabla' => 'produccion', 'desc' => 'Buscó orden de producción'],
        'ver_paros'        => ['accion' => 'otro', 'tabla' => 'paro_produccion', 'desc' => 'Consultó paros de producción'],
        'ver_produccion_hora' => ['accion' => 'otro', 'tabla' => 'produccion', 'desc' => 'Consultó producción por hora'],
    ];
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->admin_nombre = $_SESSION['empleado'] ?? 'Desconocido';
        $this->admin_codigo = $_SESSION['codigo_empleado'] ?? '—';
        $this->detectarYAuditar();
    }
    
    /**
     * Detecta automáticamente qué acción se está ejecutando
     */
    private function detectarYAuditar() {
        // 1. DETECTAR ELIMINACIONES por GET (dashboard_admin_empleados.php)
        if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
            $this->auditarEliminacionEmpleado($_GET['eliminar']);
        }
        
        // 2. DETECTAR POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($this->acciones_map as $campo => $config) {
                if (isset($_POST[$campo])) {
                    $this->auditarPorPOST($campo, $config);
                    break;
                }
            }
        }
        
        // 3. DETECTAR EXPORTACIONES por GET (dashboard_admin_check.php)
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->auditarExportacion('check_calidad', $_GET);
        }
        
        // 4. DETECTAR EXPORTACIONES por POST (dashboard_admin_produccion.php)
        if (isset($_POST['exportar_csv']) || isset($_POST['exportar_csv_paros'])) {
            $this->auditarExportacion('produccion', $_POST);
        }
    }
    
    /**
     * Audita eliminación de empleado (captura datos ANTES de eliminar)
     */
    private function auditarEliminacionEmpleado($id) {
        // Obtener datos del empleado ANTES de eliminar
        $stmt = $this->conn->prepare("SELECT nombre_empleado, codigo_empleado, rol FROM empleados WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $datos_antes = $result->fetch_assoc();
        $stmt->close();
        
        if ($datos_antes) {
            registrar_accion_completa(
                $this->conn,
                'eliminar',
                'empleados',
                "Eliminó empleado: {$datos_antes['nombre_empleado']} (Código: {$datos_antes['codigo_empleado']})",
                $datos_antes,
                [],
                $id
            );
        }
    }
    
    /**
     * Audita acciones por POST
     */
    private function auditarPorPOST($campo, $config) {
        $accion = $config['accion'];
        $tabla = $config['tabla'];
        $desc_base = $config['desc'];
        
        switch ($campo) {
            case 'agregar_empleado':
                $this->auditarAgregarEmpleado($_POST);
                break;
                
            case 'modificar_rol':
                $this->auditarModificarRolEmpleado($_POST);
                break;
                
            case 'eliminar_quiebra_id':
                $this->auditarEliminacionQuiebra($_POST['eliminar_quiebra_id']);
                break;
                
            case 'exportar_csv':
            case 'exportar_csv_paros':
                // Ya se maneja en detectarYAuditar
                break;
                
            default:
                // Registrar acción genérica
                registrar_actividad(
                    $this->conn,
                    $accion,
                    $this->admin_nombre,
                    $desc_base . " - " . json_encode($_POST, JSON_UNESCAPED_UNICODE)
                );
                break;
        }
    }
    
    /**
     * Audita agregar empleado
     */
    private function auditarAgregarEmpleado($data) {
        $nombre = $data['nombre_empleado'] ?? '';
        $codigo = $data['codigo_empleado'] ?? '';
        $rol = $data['rol'] ?? '';
        
        registrar_accion_completa(
            $this->conn,
            'agregar',
            'empleados',
            "Agregó empleado: {$nombre} (Código: {$codigo}, Rol: {$rol})",
            [],
            ['nombre_empleado' => $nombre, 'codigo_empleado' => $codigo, 'rol' => $rol],
            0
        );
    }
    
    /**
     * Audita modificar rol de empleado
     */
    private function auditarModificarRolEmpleado($data) {
        $id = $data['id_empleado'] ?? 0;
        $nuevo_rol = $data['nuevo_rol'] ?? '';
        
        // Obtener datos ANTES del cambio
        $stmt = $this->conn->prepare("SELECT nombre_empleado, codigo_empleado, rol FROM empleados WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $datos_antes = $result->fetch_assoc();
        $stmt->close();
        
        if ($datos_antes) {
            registrar_accion_completa(
                $this->conn,
                'modificar',
                'empleados',
                "Modificó rol de {$datos_antes['nombre_empleado']}: {$datos_antes['rol']} → {$nuevo_rol}",
                $datos_antes,
                ['nombre_empleado' => $datos_antes['nombre_empleado'], 'codigo_empleado' => $datos_antes['codigo_empleado'], 'rol' => $nuevo_rol],
                $id
            );
        }
    }
    
    /**
     * Audita eliminación de quiebra
     */
    private function auditarEliminacionQuiebra($id) {
        // Obtener datos ANTES de eliminar
        $stmt = $this->conn->prepare("SELECT orden, empleado, motivo, equipo FROM registro_quiebras WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $datos_antes = $result->fetch_assoc();
        $stmt->close();
        
        if ($datos_antes) {
            registrar_accion_completa(
                $this->conn,
                'eliminar',
                'registro_quiebras',
                "Eliminó quiebra ID #{$id} - Orden: {$datos_antes['orden']} - Empleado: {$datos_antes['empleado']}",
                $datos_antes,
                [],
                $id
            );
        }
    }
    
    /**
     * Audita exportaciones de datos
     */
    private function auditarExportacion($tipo, $params) {
        $filtros = [];
        if (isset($params['fecha_inicio'])) $filtros['fecha_inicio'] = $params['fecha_inicio'];
        if (isset($params['fecha_fin'])) $filtros['fecha_fin'] = $params['fecha_fin'];
        if (isset($params['estado'])) $filtros['estado'] = $params['estado'];
        if (isset($params['empleado'])) $filtros['empleado'] = $params['empleado'];
        
        registrar_actividad(
            $this->conn,
            'otro',
            $this->admin_nombre,
            "Exportó datos de {$tipo}" . (!empty($filtros) ? " - Filtros: " . json_encode($filtros, JSON_UNESCAPED_UNICODE) : "")
        );
    }
}

// ============================================================================
// EJECUCIÓN AUTOMÁTICA - NO requiere modificar nada más
// ============================================================================

// Inicializar la auditoría automática SOLO si hay una conexión activa
if (isset($conn) && $conn && !$conn->connect_error) {
    new AutoAudit($conn);
}
?>