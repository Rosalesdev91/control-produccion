<?php
session_start();
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empleado_id = $_SESSION['empleado']['id'];
    $area_id = $_POST['area_id'];
    $turno_id = $_POST['turno_id'];
    $cantidad = $_POST['cantidad'];
    $observacion = $_POST['observacion'];

    $stmt = $conn->prepare("INSERT INTO produccion (empleado_id, area_id, turno_id, cantidad, observacion) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiss", $empleado_id, $area_id, $turno_id, $cantidad, $observacion);
    $stmt->execute();

    header("Location: ../public/dashboard.php");
    exit();
}
?>