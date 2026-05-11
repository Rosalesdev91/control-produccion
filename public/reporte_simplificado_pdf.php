<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Definir ROOT_PATH solo si no está definida
if (!defined('ROOT_PATH')) {
    // En Railway, la estructura es diferente
    if (getenv('RAILWAY_ENVIRONMENT') || !empty(getenv('MYSQL_HOST'))) {
        // Estamos en Railway
        define('ROOT_PATH', dirname(__DIR__) . '/public/');
    } else {
        // Entorno local
        define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/');
    }
}

// Incluir FPDF desde la ruta correcta
if (!class_exists('FPDF')) {
    // Buscar FPDF en diferentes ubicaciones posibles
    $fpdf_paths = [
        ROOT_PATH . 'fpdf/fpdf.php',
        dirname(__DIR__) . '/public/fpdf/fpdf.php',
        dirname(__DIR__) . '/fpdf/fpdf.php',
        __DIR__ . '/fpdf/fpdf.php'
    ];
    
    $fpdf_encontrado = false;
    foreach ($fpdf_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $fpdf_encontrado = true;
            break;
        }
    }
    
    if (!$fpdf_encontrado) {
        die("Error: No se encontró FPDF. Por favor, asegúrate de que la librería FPDF esté instalada en /public/fpdf/");
    }
}

function obtenerDatosOrden($conn, $orden) {
    $sql = "SELECT * FROM registro_quiebras WHERE orden = ? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $orden);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function generarPDFSimplificado($registro, $numero_orden, $responsable, $comentarios_pdf) {
    date_default_timezone_set('America/Costa_Rica');
    
    // Limpiar cualquier salida previa
    if (ob_get_length()) {
        ob_clean();
    }
    
    // Configurar headers para PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Etiqueta_Quiebra_' . $numero_orden . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Etiqueta térmica en HORIZONTAL: 56 mm ancho x 38 mm alto
    $pdf = new FPDF('P', 'mm', [56, 38]);
    $pdf->AddPage();
    $pdf->SetMargins(2, 2, 2);
    $pdf->SetAutoPageBreak(false);

    // Tamaño de fuente más pequeño
    $fontSize = 6;
    $lineHeight = 2.8;
    $anchoUtil = 56 - 4;

    $pdf->SetFont('Arial', 'B', $fontSize);
    $pdf->Cell($anchoUtil, $lineHeight, 'ID Registro: ' . ($registro['id'] ?? 'N/A'), 0, 1);
    $pdf->Cell($anchoUtil, $lineHeight, 'Ingreso: ' . $responsable, 0, 1);
    $pdf->Cell($anchoUtil, $lineHeight, 'Fecha: ' . date("Y-m-d H:i"), 0, 1);
    $pdf->Ln(1);

    $campos = [
        'Orden' => $numero_orden,
        'Responsable' => $registro['responsable'] ?? 'N/A',
        'Equipo' => trim($registro['equipo']) ?: 'N/A',
        'Motivo' => $registro['motivo'] ?? 'N/A',
        'Lado Lente' => $registro['lado_lente'] ?? 'N/A',
    ];

    foreach ($campos as $campo => $valor) {
        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->Cell($anchoUtil, $lineHeight, utf8_decode("$campo:"), 0, 1);

        $pdf->SetFont('Arial', '', $fontSize);
        $maxChars = 35;
        if (mb_strlen($valor) > $maxChars) {
            $valor = mb_substr($valor, 0, $maxChars - 3) . '...';
        }
        $pdf->Cell($anchoUtil, $lineHeight, utf8_decode($valor), 0, 1);
    }

    $pdf->Output('I', 'Etiqueta_Quiebra_' . $numero_orden . '.pdf');
    exit;
}

try {
    // Verificar conexión a BD
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Error de conexión a la base de datos");
    }
    
    $orden = isset($_GET['orden']) ? trim($_GET['orden']) : null;
    if (!$orden) {
        throw new Exception("Orden no especificada.");
    }

    $responsable = $_SESSION['nombre_empleado'] ?? 'Desconocido';
    $comentarios = isset($_GET['comentarios']) ? trim($_GET['comentarios']) : '';

    $registro = obtenerDatosOrden($conn, $orden);
    if (!$registro) {
        throw new Exception("No se encontraron datos para la orden: $orden");
    }

    generarPDFSimplificado($registro, $orden, $responsable, $comentarios);

} catch (Exception $e) {
    error_log("Error en reporte_simplificado_pdf: " . $e->getMessage());
    
    // Limpiar cualquier salida previa
    if (ob_get_length()) {
        ob_clean();
    }
    
    // Mostrar error en formato HTML o JSON según el contexto
    $es_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
               str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    
    if ($es_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => true, 'mensaje' => $e->getMessage()]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo "<div style='font-family:Arial;padding:20px;color:#d32f2f;'>
                <h2>Error al generar el PDF</h2>
                <p>" . htmlspecialchars($e->getMessage()) . "</p>
              </div>";
    }
    exit;
}
?>
