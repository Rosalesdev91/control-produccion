<?php
session_start();
require_once '../config/database.php';

define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/');

if (!class_exists('FPDF')) {
    require_once(ROOT_PATH . 'fpdf/fpdf.php');
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
    ob_clean();

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

        $pdf->SetFont('Arial', 'B', $fontSize);
        $maxChars = 35;
        if (mb_strlen($valor) > $maxChars) {
            $valor = mb_substr($valor, 0, $maxChars - 3) . '...';
        }
        $pdf->Cell($anchoUtil, $lineHeight, utf8_decode($valor), 0, 1);
    }

    $nombreArchivo = 'Etiqueta_Quiebra_' . $numero_orden . '.pdf';
    $pdf->Output('I', $nombreArchivo);
    exit;
}

try {
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
    error_log("Error: " . $e->getMessage());
    echo "Error al generar el PDF: " . htmlspecialchars($e->getMessage());
}
?>
