<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Verificar acceso
if (!isset($_SESSION['nombre_empleado']) || $_SESSION['rol'] !== 'empleado' || !isset($_GET['session_id']) || $_GET['session_id'] !== $_SESSION['session_id']) {
    header("Location: login_recti.php");
    exit();
}

// Obtener el ID de la rectificación desde la URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ERROR: ID de rectificación no válido");
}

// Incluir FPDF
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/');
if (!class_exists('FPDF')) {
    require_once(ROOT_PATH . 'fpdf/fpdf.php');
}

$id = $conn->real_escape_string($_GET['id']);
$query = "SELECT * FROM rectificaciones WHERE id = $id";
$result = $conn->query($query);

if (!$result) {
    die("ERROR en consulta: " . $conn->error);
}

if ($result->num_rows === 0) {
    die("ERROR: No se encontró la rectificación con ID $id");
}

$rectificacion = $result->fetch_assoc();

// Verificar que la rectificación esté verificada
if (empty($rectificacion['verificada_por'])) {
    die("ERROR: La rectificación no ha sido verificada aún");
}

function generarPDFRectificacion($rectificacion) {
    date_default_timezone_set('America/Costa_Rica');
    ob_clean();

    // Etiqueta térmica: 56 mm ancho x 38 mm alto
    $pdf = new FPDF('P', 'mm', [56, 38]);
    $pdf->AddPage();
    $pdf->SetMargins(2, 2, 2);
    $pdf->SetAutoPageBreak(false);

    // Configuración de fuentes y tamaños
    $fontSizeTitulo = 7;
    $fontSizeNormal = 6;
    $fontSizePequeño = 5;
    $lineHeight = 3;
    $anchoUtil = 38 - 4; // Ancho útil considerando márgenes

    // Encabezado
    $pdf->SetFont('Arial', 'B', $fontSizeTitulo);
    // Movemos el cursor a la posición X deseada (0 = extremo izquierdo)
    $pdf->SetX(0);
    $pdf->Cell($anchoUtil, $lineHeight, utf8_decode('RECTIFICACIÓN VERIFICADA'), 0, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', $fontSizePequeño);
    $pdf->Cell($anchoUtil, $lineHeight, utf8_decode('F: ' . date('d/m/Y', strtotime($rectificacion['fecha_verificacion'])) . ' H: ' . $rectificacion['hora_verificacion']), 0, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', $fontSizePequeño);
    $pdf->Cell($anchoUtil, $lineHeight, utf8_decode('Verif: ' . $rectificacion['verificada_por']), 0, 1);
    $pdf->Line(2, $pdf->GetY(), 54, $pdf->GetY());
    $pdf->Ln(1);

// Información compacta
$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell(8, $lineHeight, utf8_decode('ORDEN:'), 0, 0);
$pdf->SetFont('Arial', '', $fontSizeNormal);
$pdf->Cell($anchoUtil - 8, $lineHeight, utf8_decode($rectificacion['orden']), 0, 1);

$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell(11, $lineHeight, utf8_decode('SUCURSAL:'), 0, 0);
$pdf->SetFont('Arial', '', $fontSizeNormal);

// Acortar texto de sucursal si es muy largo
$sucursal = $rectificacion['sucursal'];
if (strlen($sucursal) > 25) {
    $sucursal = substr($sucursal, 0, 17) . '...';
}
$pdf->Cell($anchoUtil - 11, $lineHeight, utf8_decode($sucursal), 0, 1);

$pdf->Line(2, $pdf->GetY(), 54, $pdf->GetY());
$pdf->Ln(1);

$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell(7, $lineHeight, utf8_decode('LADO:'), 0, 0);
$pdf->SetFont('Arial', '', $fontSizeNormal);
$pdf->Cell($anchoUtil - 7, $lineHeight, utf8_decode($rectificacion['lado']), 0, 1);

$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell(15, $lineHeight, utf8_decode('RESPONSABLE:'), 0, 0);
$pdf->SetFont('Arial', '', $fontSizeNormal);

// Acortar responsable si es muy largo
$responsable = $rectificacion['responsable'];
if (strlen($responsable) > 15) {
    $responsable = substr($responsable, 0, 12) . '...';
}
$pdf->Cell($anchoUtil - 15, $lineHeight, utf8_decode($responsable), 0, 1);

// Responsable final con formato de Motivo (compacto, letra más pequeña)
$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell($anchoUtil, $lineHeight, utf8_decode('RESP. FINAL:'), 0, 1);
$pdf->SetFont('Arial', '', $fontSizePequeño);

// Acortar responsable final para que quepa
$responsableFinal = $rectificacion['responsable_final'];
if (strlen($responsableFinal) > 50) {
    $responsableFinal = substr($responsableFinal, 0, 47) . '...';
}
$pdf->MultiCell($anchoUtil, $lineHeight, utf8_decode($responsableFinal), 0, 'L');

$pdf->Line(2, $pdf->GetY(), 54, $pdf->GetY());
$pdf->Ln(1);

// Motivo con formato de Responsable final (en línea, letra normal)
$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell(10, $lineHeight, utf8_decode('MOTIVO:'), 0, 0);
$pdf->SetFont('Arial', '', $fontSizeNormal);

// Acortar motivo si es muy largo
$motivo = $rectificacion['motivo'];
if (strlen($motivo) > 30) {
    $motivo = substr($motivo, 0, 27) . '...';
}
$pdf->Cell($anchoUtil - 10, $lineHeight, utf8_decode($motivo), 0, 1);

    $pdf->Line(2, $pdf->GetY(), 54, $pdf->GetY());
    $pdf->Ln(1);

// Solución compacta
$pdf->SetFont('Arial', 'B', $fontSizePequeño);
$pdf->Cell($anchoUtil, $lineHeight, utf8_decode('SOLUCIÓN:'), 0, 1);

$pdf->SetFont('Arial', '', $fontSizeNormal);

// Acortar solución para que quepa
$solucion = $rectificacion['solucion'];
if (strlen($solucion) > 70) {
    $solucion = substr($solucion, 0, 47) . '...';
}
$pdf->MultiCell($anchoUtil, $lineHeight, utf8_decode($solucion), 0, 'L');

    // Pie de página
    $pdf->SetFont('Arial', 'B', 4);
    $pdf->Cell($anchoUtil, 2, utf8_decode('© ' . date('Y') . ' - Sistema de Rectificaciones'), 0, 1, 'C');
    $pdf->Cell($anchoUtil, 2, utf8_decode('Desarrollado por Nestor Rosales - Rosales_Dev91'), 0, 1, 'C');

    $nombreArchivo = 'Rectificacion_' . $rectificacion['orden'] . '.pdf';
    $pdf->Output('I', $nombreArchivo);
    exit;
}

// Generar el PDF
generarPDFRectificacion($rectificacion);
?>