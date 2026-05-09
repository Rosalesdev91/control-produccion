<?php
/**
 * include_audit.php
 * Inclusión automática de auditoría para todos los dashboards
 * 
 * Ubicación: C:\xampp\htdocs\control_produccion\public\include_audit.php
 */

// Verificar si ya se incluyó registrar_actividad.php
if (!function_exists('registrar_actividad')) {
    require_once __DIR__ . '/registrar_actividad.php';
}

// Registrar acceso al dashboard (solo si es administrador)
if (isset($_SESSION['empleado']) && isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador') {
    global $conn;
    if (isset($conn) && $conn && !$conn->connect_error) {
        $archivo_actual = basename($_SERVER['PHP_SELF']);
        registrar_actividad(
            $conn,
            'otro',
            $_SESSION['empleado'],
            "Accedió al dashboard: {$archivo_actual}"
        );
    }
}
?>