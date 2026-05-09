<?php
// mover_antiguos.php

$host = 'localhost';
$user = 'root';
$pass = ''; // tu contraseña si aplica
$db   = 'produccion_quiebras';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Paso 1: Mover registros antiguos
$sql_insert = "
    INSERT INTO registros_antiguos (empleado, area, equipo, orden, turno, fecha)
    SELECT empleado, area, equipo, orden, turno, fecha
    FROM produccion
    WHERE id < (
        SELECT MIN(id) FROM (
            SELECT id FROM produccion
            ORDER BY id DESC
            LIMIT 30000
        ) AS ultimos
    );
";

$conn->query($sql_insert);

// Paso 2: Eliminar los registros ya movidos
$sql_delete = "
    DELETE FROM produccion
    WHERE id < (
        SELECT MIN(id) FROM (
            SELECT id FROM produccion
            ORDER BY id DESC
            LIMIT 30000
        ) AS ultimos
    );
";

$conn->query($sql_delete);

$conn->close();