<?php
// cookies.php

function iniciarSesionConCookie($nombre, $rol) {
    // Crear nombre único de cookie basado en el nombre del empleado
    $cookie_name = "sesion_" . strtolower(str_replace(' ', '_', $nombre));

    // Generar o recuperar ID de sesión
    if (isset($_COOKIE[$cookie_name])) {
        $unique_session_id = $_COOKIE[$cookie_name];
    } else {
        $unique_session_id = bin2hex(random_bytes(16));
        setcookie($cookie_name, $unique_session_id, time() + (30 * 24 * 60 * 60), "/");
    }

    // Asociar el ID con la sesión actual
    session_id($unique_session_id);
    session_start();

    // Guardar variables de sesión
    $_SESSION['empleado'] = $nombre;
    $_SESSION['rol'] = $rol;
    $_SESSION['session_id'] = $unique_session_id;

    return $unique_session_id;
}
?>
