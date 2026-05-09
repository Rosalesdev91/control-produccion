<?php
// Mostrar el mensaje pasado por la URL
if (isset($_GET['mensaje'])) {
    echo "<h1>" . htmlspecialchars($_GET['mensaje']) . "</h1>";
}
?>
