<?php
/**
 * auditoria_admin_api.php
 * Endpoint JSON para el panel de auditoría de cambios de administradores.
 *
 * Ubicación: C:\xampp\htdocs\control_produccion\public\auditoria_admin_api.php
 * BD config:  C:\xampp\htdocs\control_produccion\config\database.php
 *
 * By: Nestor Rosales | Rosales_Dev91
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
date_default_timezone_set('America/Guatemala');

function enviar_json($datos, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

// Solo administradores
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] != 'administrador') {
    enviar_json(['error' => 'Acceso denegado - Se requieren permisos de administrador'], 403);
}

// ── Ruta a la BD: mismo nivel que public/ ──
require_once dirname(__DIR__) . '/config/database.php';

if (!isset($conn) || $conn->connect_error) {
    enviar_json(['error' => 'Error de conexión a la base de datos'], 500);
}

// ── Asegurar que la tabla auditoria_cambios exista ──
$conn->query("
    CREATE TABLE IF NOT EXISTS auditoria_cambios (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        tipo_accion     ENUM('agregar','modificar','eliminar','login','otro') NOT NULL,
        tabla_afectada  VARCHAR(100) NOT NULL,
        id_registro     INT DEFAULT 0,
        descripcion     VARCHAR(500) NOT NULL,
        datos_antes     JSON,
        datos_despues   JSON,
        admin_nombre    VARCHAR(150) NOT NULL,
        admin_codigo    VARCHAR(50)  NOT NULL,
        ip              VARCHAR(45),
        user_agent      VARCHAR(300),
        fecha_hora      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tipo  (tipo_accion),
        INDEX idx_tabla (tabla_afectada),
        INDEX idx_admin (admin_codigo),
        INDEX idx_fecha (fecha_hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Parámetros de filtro desde GET ──
$filtro_tipo  = $_GET['tipo']   ?? 'todos';
$filtro_admin = $_GET['admin']  ?? '';
$filtro_tabla = $_GET['tabla']  ?? '';
$filtro_fecha = $_GET['fecha']  ?? '';
$limite       = min((int)($_GET['limite'] ?? 100), 500);

// ── Construir cláusula WHERE dinámica ──
$where  = [];
$params = [];
$types  = '';

if ($filtro_tipo !== 'todos' && $filtro_tipo !== '') {
    $where[]  = 'tipo_accion = ?';
    $params[] = $filtro_tipo;
    $types   .= 's';
}
if ($filtro_admin !== '') {
    $where[]  = '(admin_nombre LIKE ? OR admin_codigo LIKE ?)';
    $like     = "%{$filtro_admin}%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($filtro_tabla !== '') {
    $where[]  = 'tabla_afectada = ?';
    $params[] = $filtro_tabla;
    $types   .= 's';
}
if ($filtro_fecha !== '') {
    $where[]  = 'DATE(fecha_hora) = ?';
    $params[] = $filtro_fecha;
    $types   .= 's';
}

$sql_where = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Consulta principal ──
$sql = "
    SELECT
        id,
        tipo_accion,
        tabla_afectada,
        id_registro,
        descripcion,
        datos_antes,
        datos_despues,
        admin_nombre,
        admin_codigo,
        ip,
        DATE_FORMAT(fecha_hora, '%d/%m/%Y %H:%i:%s') AS fecha_hora,
        DATE_FORMAT(fecha_hora, '%Y-%m-%d')           AS solo_fecha
    FROM auditoria_cambios
    $sql_where
    ORDER BY fecha_hora DESC
    LIMIT ?
";

$params[] = $limite;
$types   .= 'i';

$registros = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['datos_antes']   = $row['datos_antes']   ? json_decode($row['datos_antes'],   true) : null;
        $row['datos_despues'] = $row['datos_despues'] ? json_decode($row['datos_despues'], true) : null;
        $registros[] = $row;
    }
    $stmt->close();
}

// ── Totales por tipo (para los contadores del panel) ──
$totales = ['agregar' => 0, 'modificar' => 0, 'eliminar' => 0, 'login' => 0, 'otro' => 0];
$res_tot = $conn->query("SELECT tipo_accion, COUNT(*) as n FROM auditoria_cambios GROUP BY tipo_accion");
if ($res_tot) {
    while ($r = $res_tot->fetch_assoc()) {
        $totales[$r['tipo_accion']] = (int)$r['n'];
    }
}

// ── Lista de admins para el select de filtro ──
$admins_lista = [];
$res_adm = $conn->query(
    "SELECT DISTINCT admin_nombre, admin_codigo FROM auditoria_cambios ORDER BY admin_nombre"
);
if ($res_adm) {
    while ($r = $res_adm->fetch_assoc()) $admins_lista[] = $r;
}

// ── Módulos/tablas para el select de filtro ──
$tablas_lista = [];
$res_tab = $conn->query(
    "SELECT DISTINCT tabla_afectada FROM auditoria_cambios ORDER BY tabla_afectada"
);
if ($res_tab) {
    while ($r = $res_tab->fetch_assoc()) $tablas_lista[] = $r['tabla_afectada'];
}

// ── Cambios en las últimas 24 horas ──
$cambios_hoy = 0;
$res_hoy = $conn->query(
    "SELECT COUNT(*) as n FROM auditoria_cambios WHERE fecha_hora >= NOW() - INTERVAL 24 HOUR"
);
if ($res_hoy && $r = $res_hoy->fetch_assoc()) $cambios_hoy = (int)$r['n'];

enviar_json([
    'ok'          => true,
    'timestamp'   => date('Y-m-d H:i:s'),
    'total'       => count($registros),
    'cambios_hoy' => $cambios_hoy,
    'totales'     => $totales,
    'admins'      => $admins_lista,
    'tablas'      => $tablas_lista,
    'registros'   => $registros
]);
