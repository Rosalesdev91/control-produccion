<?php
session_start();
require dirname(__DIR__) . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'];
    $codigo = $_POST['codigo'];

    $stmt = $conn->prepare("SELECT * FROM empleados WHERE nombre = ? AND codigo = ?");
    $stmt->bind_param("ss", $nombre, $codigo);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 1) {
        $_SESSION['empleado'] = $res->fetch_assoc();
        header("Location: ../public/dashboard.php");
        exit();
    } else {
        $error = "Datos incorrectos.";
    }
}
?>