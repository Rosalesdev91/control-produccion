<?php
// config/database.php
// Ubicación: C:\xampp\htdocs\control_produccion\config\database.php

// Detectar entorno: Railway tiene variables de entorno MYSQL_*
$es_railway = !empty(getenv('MYSQL_HOST')) || !empty(getenv('MYSQLHOST'));

if ($es_railway) {
    // ── RAILWAY (producción en la nube) ──
    $db_host     = getenv('MYSQL_HOST')     ?: getenv('MYSQLHOST')     ?: 'localhost';
    $db_port     = (int)(getenv('MYSQL_PORT') ?: getenv('MYSQLPORT')   ?: 3306);
    $db_user     = getenv('MYSQL_USER')     ?: getenv('MYSQLUSER')     ?: 'root';
    $db_password = getenv('MYSQL_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
    $db_name     = getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE') ?: 'railway';
} else {
    // ── XAMPP LOCAL (desarrollo) ──
    $db_host     = 'localhost';
    $db_port     = 3306;
    $db_user     = 'root';
    $db_password = '';
    $db_name     = 'control_produccion'; // ← nombre de tu BD local
}

// Conexión
$conn = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);

if ($conn->connect_error) {
    error_log("[DB ERROR] " . $conn->connect_error);
    // Si es llamada AJAX devuelve JSON, si no devuelve HTML
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['error' => true, 'mensaje' => 'Error de conexión a base de datos.']));
    }
    http_response_code(500);
    die('<h2 style="color:red">Error de conexión a la base de datos. Contacta al administrador.</h2>');
}

$conn->set_charset("utf8mb4");
