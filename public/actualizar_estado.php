<?php
$conexion = new mysqli("localhost", "root", "", "produccion_quiebras");
if ($conexion->connect_error) {
    http_response_code(500);
    echo "Error de conexión: " . $conexion->connect_error;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_estado'])) {
    $id = intval($_POST['pedido_id']);
    $estado = ($_POST['estado'] === 'Entregado') ? 'Entregado' : 'Pendiente';

    $stmt = $conexion->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo "Error prepare(): " . $conexion->error;
        exit;
    }

    $stmt->bind_param("si", $estado, $id);
    if ($stmt->execute()) {
        http_response_code(200);
        echo "Estado actualizado correctamente";
    } else {
        http_response_code(500);
        echo "Error execute(): " . $stmt->error;
    }

    $stmt->close();
    exit;
}

http_response_code(400);
echo "Solicitud inválida";
exit;
?>
