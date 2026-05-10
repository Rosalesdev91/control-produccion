<?php
// public/router.php

// Define la ruta raíz del proyecto (un nivel arriba de /public)
define('ROOT_PATH', dirname(__DIR__));

// Hace que ../config/database.php funcione en Railway y en XAMPP
set_include_path(get_include_path() . PATH_SEPARATOR . ROOT_PATH);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Archivos estáticos
$extensiones_estaticas = ['css','js','png','jpg','jpeg','gif','ico','svg','woff','woff2','ttf','eot','pdf','map'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

if (in_array($ext, $extensiones_estaticas)) {
    $archivo = __DIR__ . $uri;
    if (file_exists($archivo)) { return false; }
}

// Archivo PHP existente
$archivo_php = __DIR__ . $uri;
if (file_exists($archivo_php) && !is_dir($archivo_php)) {
    return false;
}

// Carpeta con index.php
if (is_dir($archivo_php)) {
    $index = rtrim($archivo_php, '/') . '/index.php';
    if (file_exists($index)) { require $index; exit; }
}

// Raíz → login.php
if ($uri === '/' || $uri === '') {
    $login = __DIR__ . '/login.php';
    $index = __DIR__ . '/index.php';
    if (file_exists($login)) { require $login; exit; }
    if (file_exists($index)) { require $index; exit; }
}

// 404
http_response_code(404);
echo "<h1>404 - Página no encontrada</h1>";
echo "<p><a href='/'>Volver al inicio</a></p>";