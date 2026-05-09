<?php
session_start();

$host = 'localhost';
$dbname = 'produccion_quiebras';
$username = 'root';
$password = '';

try {
    $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión']);
    exit();
}

if(isset($_GET['id']) && is_numeric($_GET['id'])) {
    $stmt = $conexion->prepare("SELECT * FROM tareas WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $tarea = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($tarea) {
        echo json_encode(['success' => true, 'tarea' => $tarea]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Tarea no encontrada']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'ID no válido']);
}
?>