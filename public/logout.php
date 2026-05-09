<?php
session_start();

// Limpiar todas las variables de sesión
$_SESSION = array();

// Destruir la sesión
session_destroy();

// Redirigir a la página de login adecuada
if (isset($_GET['admin']) && $_GET['admin'] == '1') {
    header("Location: login_admin.php");
} else {
    header("Location: login.php");
}

exit();