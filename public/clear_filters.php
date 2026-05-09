<?php
session_start();

// Limpiar todos los filtros de la sesión
unset($_SESSION['filtros']);

// Redirigir de vuelta a la página principal
header("Location: ia_queries.php");
exit();
?>