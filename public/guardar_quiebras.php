<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Cargar FPDF si no está cargado
if (!class_exists('FPDF')) {
    require_once('fpdf/fpdf.php');
}

// Generación del token CSRF si no existe
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validación del token CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Token CSRF no válido.");
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Configuración de la base de datos
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'produccion_quiebras';

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Conexión fallida: " . $conn->connect_error);
    }
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Función de limpieza
function limpiar_nullable($value) {
    return isset($value) ? trim(htmlspecialchars($value)) : null;
}

// Verificar si se ha enviado el formulario
if (isset($_POST['registrar_quiebra'])) {
    
    // Obtener y limpiar los comentarios (si existen)
    $comentarios_pdf = isset($_POST['comentarios_pdf']) ? trim($_POST['comentarios_pdf']) : null;

    // Obtener y limpiar los datos del formulario
    $datos = [
        'orden' => isset($_POST['orden']) ? trim($_POST['orden']) : null,
        'fecha' => isset($_POST['fecha']) ? trim($_POST['fecha']) : null,
        'hora' => isset($_POST['hora']) ? trim($_POST['hora']) : null,
        'turno' => isset($_POST['turno']) ? trim($_POST['turno']) : null,
        'responsable' => isset($_POST['responsable']) ? trim($_POST['responsable']) : null,
        'empleado' => isset($_POST['empleado']) ? trim($_POST['empleado']) : null,
        'equipo' => isset($_POST['equipo']) ? trim($_POST['equipo']) : null,
        'area' => isset($_POST['area']) ? trim($_POST['area']) : null,
        'motivo' => isset($_POST['motivo']) ? trim($_POST['motivo']) : null,
        'porque_defecto' => isset($_POST['porque_defecto']) ? trim($_POST['porque_defecto']) : null,
        'tipo_lente' => isset($_POST['tipo_lente']) ? trim($_POST['tipo_lente']) : null,
        'lado_lente' => isset($_POST['lado_lente']) ? trim($_POST['lado_lente']) : null,
        'tipo_montaje' => isset($_POST['tipo_montaje']) ? trim($_POST['tipo_montaje']) : null,
        'tipo_vision' => isset($_POST['tipo_vision']) ? trim($_POST['tipo_vision']) : null,
        'material' => isset($_POST['material']) ? trim($_POST['material']) : null,
        'tratamiento' => isset($_POST['tratamiento']) ? trim($_POST['tratamiento']) : null,
        'esfera_od' => isset($_POST['esfera_od']) ? trim($_POST['esfera_od']) : null,
        'cilindro_od' => isset($_POST['cilindro_od']) ? trim($_POST['cilindro_od']) : null,
        'adicion_od' => isset($_POST['adicion_od']) ? trim($_POST['adicion_od']) : null,
        'base_od' => isset($_POST['base_od']) ? trim($_POST['base_od']) : null,
        'esfera_oi' => isset($_POST['esfera_oi']) ? trim($_POST['esfera_oi']) : null,
        'cilindro_oi' => isset($_POST['cilindro_oi']) ? trim($_POST['cilindro_oi']) : null,
        'adicion_oi' => isset($_POST['adicion_oi']) ? trim($_POST['adicion_oi']) : null,
        'base_oi' => isset($_POST['base_oi']) ? trim($_POST['base_oi']) : null,
        'empleado_registro' => $_SESSION['nombre_empleado'] ?? null  // ← DATO DEL EMPLEADO LOGUEADO
    ];
    
    // Limpiar los datos y seguir con el proceso
    foreach ($datos as $key => $value) {
        $datos[$key] = limpiar_nullable($value);
    }

    // Validar campo obligatorio 'orden'
    if (empty($datos['orden'])) {
        $mensaje = "El número de orden no puede estar vacío.";
        echo "<script type='text/javascript'>
                alert('$mensaje');
                window.location.href = 'registro_quiebras.php';
              </script>";
        exit();
    }

    // Consulta SQL para insertar datos (incluye empleado_registro)
    $sql = "INSERT INTO registro_quiebras 
        (orden, fecha, hora, turno, responsable, empleado, equipo, area, motivo, porque_defecto, 
         tipo_lente, lado_lente, tipo_montaje, tipo_vision, material, tratamiento, 
         esfera_od, cilindro_od, adicion_od, base_od, 
         esfera_oi, cilindro_oi, adicion_oi, base_oi, empleado_registro) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error al preparar la consulta: " . $conn->error);
        }

        // Enlace de parámetros (25 campos: 16 strings + 8 doubles + 1 string)
        $stmt->bind_param(
            'ssssssssssssssssdddddddds',
            $datos['orden'], $datos['fecha'], $datos['hora'], $datos['turno'],
            $datos['responsable'], $datos['empleado'], $datos['equipo'], $datos['area'],
            $datos['motivo'], $datos['porque_defecto'], $datos['tipo_lente'], $datos['lado_lente'],
            $datos['tipo_montaje'], $datos['tipo_vision'], $datos['material'], $datos['tratamiento'],
            $datos['esfera_od'], $datos['cilindro_od'], $datos['adicion_od'], $datos['base_od'],
            $datos['esfera_oi'], $datos['cilindro_oi'], $datos['adicion_oi'], $datos['base_oi'],
            $datos['empleado_registro']  // ← AQUÍ SE GUARDA EL NOMBRE DEL EMPLEADO LOGUEADO
        );

if ($stmt->execute()) {
    $id_insertado = $conn->insert_id;
    $mensaje = "Registro insertado correctamente. ID generado: $id_insertado";
    $token = $_SESSION['csrf_token'];

    // Variables para JS (codificadas para uso seguro en JS)
    $orden_js = json_encode($datos['orden']);
    $token_js = json_encode($token);
    $comentarios_js = json_encode($comentarios_pdf);
    $id_js = json_encode($id_insertado);
    $fecha_hora = date('Ymd_His');
    $nombre_pdf = 'Reporte_Quiebra_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $datos['orden']) . '_' . $fecha_hora . '.pdf';
    $nombre_pdf_js = json_encode($nombre_pdf);

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Descargando y mostrando PDF...</title>
    <script src='https://cdn.jsdelivr.net/npm/qz-tray@2.1.0/qz-tray.js'></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2em;
            text-align: center;
        }
        button {
            margin-top: 20px;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<script type='text/javascript'>
    const mensaje = " . json_encode($mensaje) . ";
    const orden = " . json_encode($datos['orden']) . ";
    const token = " . json_encode($token) . ";
    const comentarios = " . json_encode($comentarios_pdf) . ";
    const id = " . json_encode($id_insertado) . ";
    const nombrePDF = " . json_encode($nombre_pdf_js) . ";

    alert(mensaje);

    const urlBaseCompleta = 'generar_pdf_quiebras.php';
    const urlBaseSimplificada = 'reporte_simplificado_pdf.php';

    const queryString = '?orden=' + encodeURIComponent(orden) +
                        '&token=' + encodeURIComponent(token) +
                        '&comentarios=' + encodeURIComponent(comentarios) +
                        '&id=' + encodeURIComponent(id);

    // Función para descargar archivo automáticamente
    function descargarArchivo(url, nombre) {
        const link = document.createElement('a');
        link.href = url;
        link.download = nombre;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Siempre descargar PDF completo
    descargarArchivo(urlBaseCompleta + queryString, nombrePDF);

    // Abrir directamente PDF simplificado en nueva pestaña
    window.open(urlBaseSimplificada + queryString, '_blank');

    function limpiarYRegresar() {
        window.history.back();
    }
</script>

<p>Si el PDF no se muestra o descarga automáticamente, por favor 
    <a href='" . htmlspecialchars('generar_pdf_quiebras.php?orden=' . urlencode($datos['orden']) . 
                '&token=' . urlencode($token) . 
                '&comentarios=' . urlencode($comentarios_pdf) . 
                '&id=' . urlencode($id_insertado)) . "' target='_blank'>haz clic aquí</a>.
</p>

<button type='button' onclick='limpiarYRegresar()'>Regresar</button>
</body>
</html>";

    exit();
} else {
    throw new Exception("Error al insertar el registro: " . $stmt->error);
}

} finally {
    // Cerrar recursos
    $stmt->close();
    $conn->close();
}
}
