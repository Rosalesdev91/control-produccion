<?php
// config/database.php

// Detectar si estamos en Railway o en XAMPP local
$es_railway = !empty(getenv('MYSQL_HOST')) || !empty(getenv('MYSQLHOST'));

if ($es_railway) {
    // ── RAILWAY ──
    $db_host     = getenv('MYSQL_HOST')     ?: getenv('MYSQLHOST')     ?: 'localhost';
    $db_port     = (int)(getenv('MYSQL_PORT') ?: getenv('MYSQLPORT')   ?: 3306);
    $db_user     = getenv('MYSQL_USER')     ?: getenv('MYSQLUSER')     ?: 'root';
    $db_password = getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
    $db_name     = getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE') ?: 'railway';
} else {
    // ── XAMPP LOCAL ──
    $db_host     = 'localhost';
    $db_port     = 3306;
    $db_user     = 'root';
    $db_password = '';
    $db_name     = 'control_produccion';
}

// Suprimir advertencias nativas y manejar error manualmente
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

if ($conn->connect_error) {
    error_log("[DB ERROR] " . $conn->connect_error);
    $es_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
               str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($es_ajax) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['error' => true, 'mensaje' => 'Error de conexión a la base de datos.']));
    }
    http_response_code(500);
    die('
    <div style="font-family:Arial;text-align:center;padding:60px;background:#0a3d1a;color:#d4fcd4;min-height:100vh">
        <h2>&#9888;&#65039; Error de conexión</h2>
        <p>No se pudo conectar a la base de datos.</p>
        <p style="font-size:.85rem;opacity:.6">Contactá al administrador del sistema.</p>
    </div>');
}

$conn->set_charset("utf8mb4");
