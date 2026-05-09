<?php
session_start();
require_once '../config/database.php';

// Verificación de acceso
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit();
}

if (isset($_GET['id'])) {
    $id = $conn->real_escape_string($_GET['id']);
    $query = "SELECT * FROM rectificaciones WHERE id = $id";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $rectificacion = $result->fetch_assoc();
        echo json_encode(['success' => true] + $rectificacion);
    } else {
        echo json_encode(['success' => false, 'error' => 'Rectificación no encontrada']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'ID no proporcionado']);
}

$conn->close();
?>