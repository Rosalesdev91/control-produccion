<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Ruta base para incluir librerías
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/');

// Incluir FPDF si no está cargado
if (!class_exists('FPDF')) {
    require_once(ROOT_PATH . 'fpdf/fpdf.php');
}

// Verificación CSRF
function verificarTokenCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die("Token CSRF no válido.");
    }
}

function Code39($pdf, $x, $y, $code, $w = 0.5, $h = 20) {
    // Caracteres permitidos
    $code = strtoupper($code);
    $narrow = $w;
    $wide = $w * 3;
    $gap = $w;

    $barChar = array(
        '0'=>'nnnwwnwnn', '1'=>'wnnwnnnnw', '2'=>'nnwwnnnnw', '3'=>'wnwwnnnnn',
        '4'=>'nnnwwnnnw', '5'=>'wnnwwnnnn', '6'=>'nnwwwnnnn', '7'=>'nnnwnnwnw',
        '8'=>'wnnwnnwnn', '9'=>'nnwwnnwnn', 'A'=>'wnnnnwnnw', 'B'=>'nnwnnwnnw',
        'C'=>'wnwnnwnnn', 'D'=>'nnnnwwnnw', 'E'=>'wnnnwwnnn', 'F'=>'nnwnwwnnn',
        'G'=>'nnnnnwwnw', 'H'=>'wnnnnwwnn', 'I'=>'nnwnnwwnn', 'J'=>'nnnnwwwnn',
        'K'=>'wnnnnnnww', 'L'=>'nnwnnnnww', 'M'=>'wnwnnnnwn', 'N'=>'nnnnwnnww',
        'O'=>'wnnnwnnwn', 'P'=>'nnwnwnnwn', 'Q'=>'nnnnnnwww', 'R'=>'wnnnnnwwn',
        'S'=>'nnwnnnwwn', 'T'=>'nnnnwnwwn', 'U'=>'wwnnnnnnw', 'V'=>'nwwnnnnnw',
        'W'=>'wwwnnnnnn', 'X'=>'nwnnwnnnw', 'Y'=>'wwnnwnnnn', 'Z'=>'nwwnwnnnn',
        '-'=>'nwnnnnwnw', '.'=>'wwnnnnwnn', ' '=>'nwwnnnwnn', '*'=>'nwnnwnwnn',
        '$'=>'nwnwnwnnn', '/'=>'nwnwnnnwn', '+'=>'nwnnnwnwn', '%'=>'nnnwnwnwn'
    );

    // Agregar * al principio y final (start/stop)
    $code = '*' . $code . '*';

    for ($i = 0; $i < strlen($code); $i++) {
        $char = $code[$i];
        if (!isset($barChar[$char])) {
            throw new Exception("Caracter inválido para Code39: $char");
        }
        $seq = $barChar[$char];
        for ($bar = 0; $bar < 9; $bar++) {
            $lineWidth = ($seq[$bar] == 'n') ? $narrow : $wide;
            if ($bar % 2 == 0) {
                // Dibuja barra (negra)
                $pdf->Rect($x, $y, $lineWidth, $h, 'F');
            }
            // mueve x según ancho línea + espacio
            $x += $lineWidth;
        }
        // espacio entre caracteres
        $x += $gap;
    }
}

function obtenerDatosOrden($conn, $orden) {
    // Traemos solo el último registro de esa orden, ordenando por id descendente y limitando a 1
    $sql = "SELECT id, orden, turno, responsable, empleado, equipo, area, motivo, porque_defecto, tipo_lente, lado_lente, tipo_montaje, tipo_vision, material, tratamiento, esfera_od, cilindro_od, adicion_od, base_od, esfera_oi, cilindro_oi, adicion_oi, base_oi 
            FROM registro_quiebras 
            WHERE orden = ? 
            ORDER BY id DESC 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $orden);
    $stmt->execute();
    $resultado = $stmt->get_result();
    return $resultado->fetch_assoc();
}

function generarPDF($registro, $numero_orden, $responsable, $comentarios_pdf) {
    date_default_timezone_set('America/Costa_Rica');
    ob_clean();

    $pdf = new FPDF();
    $pdf->AddPage();

    $pageWidth = $pdf->GetPageWidth();
    $logoPath = $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/logo.png';
    $logoWidth = 55;

    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, $pageWidth - $logoWidth - 8, 8, $logoWidth);
    }

    // Texto ID registro pequeño
$pdf->SetFont('Arial', 'B', 8);
$idRegistro = $registro['id'] ?? 'N/A';
$pdf->Cell(0, 6, 'ID Registro: ' . $idRegistro, 0, 1, 'L');

// Dibuja código de barras con ID (asumiendo es numérico o alfanumérico compatible con Code39)
if ($idRegistro !== 'N/A') {
    $x = 10;      // Posición X del código barras (ajusta si quieres)
    $y = $pdf->GetY(); // Posición Y actual para colocar código justo abajo del texto
    Code39($pdf, $x, $y, $idRegistro, 0.6, 15);
    $pdf->Ln(20); // Espacio después del código barras
}


    // Título principal
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, utf8_decode('MAYORISTAS ÓPTICOS J Y Z CENTROAMERICANOS'), 0, 1, 'C');
    $pdf->Cell(0, 7, utf8_decode('Reporte de Quiebra'), 0, 1, 'C');

    $pdf->Ln(2);

    // Datos responsables y fecha - fuente 9
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'Ingresado por: ' . $responsable, 0, 1);
    $pdf->Cell(0, 6, 'Fecha: ' . date("Y-m-d h:i:s A"), 0, 1);

    $pdf->Ln(2);

    // Tabla detalles - encabezado verde
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(0, 153, 0); // verde
    $pdf->SetTextColor(255); // blanco
    $pdf->Cell(50, 7, 'Campo', 1, 0, 'C', true);
    $pdf->Cell(140, 7, 'Detalle', 1, 1, 'C', true);

    // Texto negro para celdas
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', 8);

    // Campos como en tu código original
    $campos = [
        'Orden' => $numero_orden,
        'Turno' => $registro['turno'] ?? 'N/A',
        'Responsable' => $registro['responsable'] ?? 'N/A',
        'Empleado' => $registro['empleado'] ?? 'N/A',
        'Equipo' => trim($registro['equipo']) ?: 'N/A',
        'Área' => $registro['area'] ?? 'N/A',
        'Motivo' => $registro['motivo'] ?? 'N/A',
        'Porque defecto (Raiz)' => trim($registro['porque_defecto']) ?: 'N/A',
        'Tipo Lente' => $registro['tipo_lente'] ?? 'N/A',
        'Lado Lente' => $registro['lado_lente'] ?? 'N/A',
        'Tipo montaje' => $registro['tipo_montaje'] ?? 'N/A',
        'Tipo Visión' => $registro['tipo_vision'] ?? 'N/A',
        'Material' => $registro['material'] ?? 'N/A',
        'Tratamiento' => $registro['tratamiento'] ?? 'N/A',
    ];

    foreach ($campos as $campo => $valor) {
        $pdf->Cell(50, 6, utf8_decode($campo), 1);
        $pdf->Cell(140, 6, utf8_decode($valor), 1, 1);
    }

    // Graduación
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(0, 153, 0);
    $pdf->SetTextColor(255);
    $pdf->Cell(190, 7, utf8_decode('Graduación'), 1, 1, 'C', true);

    // Encabezado tabla graduación
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(20, 6, '', 1, 0, 'C', true);
    $pdf->Cell(42.5, 6, 'Esfera', 1, 0, 'C', true);
    $pdf->Cell(42.5, 6, 'Cilindro', 1, 0, 'C', true);
    $pdf->Cell(42.5, 6, utf8_decode('Adición'), 1, 0, 'C', true);
    $pdf->Cell(42.5, 6, 'Base', 1, 1, 'C', true);

    // Valores graduación (usar variable con valor o 'N/A')
    $valores_graduacion = [
        ['OD', $registro['esfera_od'] ?? 'N/A', $registro['cilindro_od'] ?? 'N/A', $registro['adicion_od'] ?? 'N/A', $registro['base_od'] ?? 'N/A'],
        ['OI', $registro['esfera_oi'] ?? 'N/A', $registro['cilindro_oi'] ?? 'N/A', $registro['adicion_oi'] ?? 'N/A', $registro['base_oi'] ?? 'N/A'],
    ];

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(0);
    foreach ($valores_graduacion as $fila) {
        foreach ($fila as $idx => $valor) {
            $ancho = $idx == 0 ? 20 : 42.5;
            $pdf->Cell($ancho, 6, utf8_decode($valor), 1);
        }
        $pdf->Ln();
    }

    $pdf->Ln(5);

    // Comentarios - fuente 9, altura 6 para MultiCell
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'Comentario de la quiebra:', 0, 1);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->MultiCell(0, 6, utf8_decode($comentarios_pdf), 1);

    $pdf->Ln(5);

    // Firmas - fuente 9
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(95, 7, utf8_decode('Firma supervisión: ____________________'), 0, 0, 'L');
    $pdf->Cell(95, 7, utf8_decode('Firma responsable: ____________________'), 0, 1, 'L');

    // Salida del PDF
   $nombreArchivo = 'Reporte_Quiebra_' . $numero_orden . '_' . date('Ymd_His') . '.pdf';
   $pdf->Output('I', $nombreArchivo);
    exit;
}

// --- EJECUCIÓN ---
try {
    // Validación de CSRF
    if (!function_exists('verificarTokenCSRF')) {
        function verificarTokenCSRF() {
            // Aquí puedes colocar tu validación real si la usas
            return true;
        }
    }
    verificarTokenCSRF();

    $numero_orden = $_GET['orden'] ?? null;
    if (!$numero_orden) {
        throw new Exception("Número de orden no especificado.");
    }

    $responsable = $_SESSION['nombre_empleado'] ?? 'Desconocido';
    $comentarios_pdf = isset($_GET['comentarios']) ? urldecode($_GET['comentarios']) : '';

    $registro = obtenerDatosOrden($conn, $numero_orden);
    if (!$registro) {
        throw new Exception("No se encontraron datos para la orden: " . htmlspecialchars($numero_orden));
    }

    generarPDF($registro, $numero_orden, $responsable, $comentarios_pdf);

} catch (Exception $e) {
    error_log("Error al generar el PDF: " . $e->getMessage());
    echo "Hubo un problema al generar el reporte. Intenta nuevamente más tarde.";
}
?>