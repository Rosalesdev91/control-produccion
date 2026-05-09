<?php
/**
 * debug_sessions.php
 * Para depurar qué información tienen las sesiones
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Depuración de Sesiones</h1>";

// Mostrar sesión actual
echo "<h2>Sesión actual (Administrador):</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Leer todas las sesiones
$session_save_path = session_save_path();
if (empty($session_save_path)) {
    $session_save_path = ini_get('session.save_path');
}
if (empty($session_save_path)) {
    $session_save_path = sys_get_temp_dir();
}

echo "<h2>Directorio de sesiones: $session_save_path</h2>";

if (is_dir($session_save_path)) {
    $session_files = glob($session_save_path . '/sess_*');
    echo "<h2>Archivos de sesión encontrados: " . count($session_files) . "</h2>";
    
    foreach ($session_files as $file) {
        $session_data = file_get_contents($file);
        echo "<h3>Archivo: " . basename($file) . "</h3>";
        echo "<pre>";
        
        // Mostrar contenido bruto
        echo "=== CONTENIDO BRUTO ===\n";
        echo htmlspecialchars(substr($session_data, 0, 500)) . "...\n\n";
        
        // Buscar específicamente ultimo_modulo
        echo "=== BUSCANDO 'ultimo_modulo' ===\n";
        if (preg_match('/ultimo_modulo\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
            echo "✅ ENCONTRADO: ultimo_modulo = " . $m[2] . "\n";
        } else {
            echo "❌ NO encontrado 'ultimo_modulo'\n";
        }
        
        // Buscar empleado
        echo "\n=== BUSCANDO 'empleado' ===\n";
        if (preg_match('/empleado\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
            echo "✅ ENCONTRADO: empleado = " . $m[2] . "\n";
        } elseif (preg_match('/nombre_empleado\|s:([0-9]+):"([^"]+)"/', $session_data, $m)) {
            echo "✅ ENCONTRADO: nombre_empleado = " . $m[2] . "\n";
        } else {
            echo "❌ NO encontrado empleado\n";
        }
        
        echo "</pre><hr>";
    }
}
?>