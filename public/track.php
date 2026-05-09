<?php
/**
 * track.php
 * Endpoint para guardar la última página visitada por un usuario
 * 
 * Ubicación: C:\xampp\htdocs\control_produccion\public\track.php
 * By: Nestor Rosales | Rosales_Dev91
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Recibir datos JSON
$input = json_decode(file_get_contents('php://input'), true);

// Si no se recibió JSON, intentar con POST normal
if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $modulo = $_POST['modulo'] ?? '';
    $pagina = $_POST['pagina'] ?? '';
} else {
    $modulo = $input['modulo'] ?? '';
    $pagina = $input['pagina'] ?? '';
}

// Validar que hay datos
if (empty($modulo) && empty($pagina)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se especificó módulo o página']);
    exit();
}

// Determinar el nombre del módulo
if (empty($modulo) && !empty($pagina)) {
    $modulo = basename($pagina, '.php');
}

// Guardar en sesión la última página (solo si hay sesión activa)
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['ultima_pagina'] = $pagina;
    $_SESSION['ultimo_modulo'] = $modulo;
    $_SESSION['ultima_actividad'] = time();
    
    // También guardar la IP si no está
    if (!isset($_SESSION['ip'])) {
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// Intentar guardar en BD (opcional, no crítico)
try {
    if (file_exists('../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
        
        // Verificar si existe la tabla de tracking
        $conn->query("
            CREATE TABLE IF NOT EXISTS tracking_usuarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario VARCHAR(100),
                modulo VARCHAR(100),
                pagina VARCHAR(255),
                ip VARCHAR(45),
                fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_usuario (usuario),
                INDEX idx_fecha (fecha_hora)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Obtener nombre del usuario
        $usuario = $_SESSION['empleado'] ?? $_SESSION['nombre_empleado'] ?? $_SESSION['nombre_tecnico'] ?? 'Anónimo';
        $ip = $_SESSION['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        $stmt = $conn->prepare("INSERT INTO tracking_usuarios (usuario, modulo, pagina, ip, fecha_hora) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssss", $usuario, $modulo, $pagina, $ip);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
} catch (Exception $e) {
    // No hacer nada si falla
}

echo json_encode([
    'success' => true, 
    'modulo' => $modulo,
    'pagina' => $pagina,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>