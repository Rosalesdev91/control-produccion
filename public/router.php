<?php
// public/router.php
// Este archivo reemplaza la función de Apache/.htaccess en Railway
// Ubicación: C:\xampp\htdocs\control_produccion\public\router.php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Servir archivos estáticos directamente (CSS, JS, imágenes, PDFs)
$extensiones_estaticas = ['css','js','png','jpg','jpeg','gif','ico','svg','woff','woff2','ttf','eot','pdf','map'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

if (in_array($ext, $extensiones_estaticas)) {
    $archivo = __DIR__ . $uri;
    if (file_exists($archivo)) {
        return false; // PHP built-in server sirve el archivo directamente
    }
}

// Si la URI apunta a un archivo PHP existente, servirlo
$archivo_php = __DIR__ . $uri;
if (file_exists($archivo_php) && !is_dir($archivo_php)) {
    return false;
}

// Si la URI es una carpeta con index.php, redirigir
if (is_dir($archivo_php)) {
    $index = rtrim($archivo_php, '/') . '/index.php';
    if (file_exists($index)) {
        require $index;
        exit;
    }
}

// Raíz → index.php o login.php
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
