<?php
// public/test.php
echo "<h2>Debug de Variables de Entorno</h2>";

$variables = [
    'MYSQLHOST',
    'MYSQLPORT', 
    'MYSQLUSER',
    'MYSQLPASSWORD',
    'MYSQLDATABASE',
    'MYSQL_HOST',
    'MYSQL_PORT',
    'MYSQL_USER',
    'MYSQL_PASSWORD',
    'MYSQL_DATABASE'
];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Variable</th><th>Valor</th></tr>";

foreach ($variables as $var) {
    $valor = getenv($var);
    if ($valor === false) {
        echo "<tr><td>$var</td><td style='color:red'>❌ NO DEFINIDA</td></tr>";
    } else {
        echo "<tr><td>$var</td><td>$valor</td></tr>";
    }
}
echo "</table>";

// Probar conexión manual
echo "<h3>Probando conexión manual:</h3>";

$host = getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: 'ninguno';
$port = getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: 'ninguno';
$user = getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: 'ninguno';
$pass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';
$db   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'ninguno';

echo "Intentando conectar a: $host:$port con usuario $user<br>";

mysqli_report(MYSQLI_REPORT_OFF);
$testConn = @new mysqli($host, $user, $pass, $db, (int)$port);

if ($testConn->connect_error) {
    echo "<p style='color:red'>❌ Error: " . $testConn->connect_error . "</p>";
} else {
    echo "<p style='color:green'>✅ ¡Conexión exitosa!</p>";
    $testConn->close();
}
?>