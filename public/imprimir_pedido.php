<?php
require_once(__DIR__ . '/fpdf/fpdf.php');

class PDF_Code39 extends FPDF
{
    function Code39($x, $y, $code, $w, $h)
    {
        $wide = $w;
        $narrow = $w / 3;
        $barChar = [
            '0'=>'nnnwwnwnn', '1'=>'wnnwnnnnw', '2'=>'nnwwnnnnw',
            '3'=>'wnwwnnnnn', '4'=>'nnnwwnnnw', '5'=>'wnnwwnnnn',
            '6'=>'nnwwwnnnn', '7'=>'nnnwnnwnw', '8'=>'wnnwnnwnn',
            '9'=>'nnwwnnwnn', 'A'=>'wnnnnwnnw', 'B'=>'nnwnnwnnw',
            'C'=>'wnwnnwnnn', 'D'=>'nnnnwwnnw', 'E'=>'wnnnwwnnn',
            'F'=>'nnwnwwnnn', 'G'=>'nnnnnwwnw', 'H'=>'wnnnnwwnn',
            'I'=>'nnwnnwwnn', 'J'=>'nnnnwwwnn', 'K'=>'wnnnnnnww',
            'L'=>'nnwnnnnww', 'M'=>'wnwnnnnwn', 'N'=>'nnnnwnnww',
            'O'=>'wnnnwnnwn', 'P'=>'nnwnwnnwn', 'Q'=>'nnnnnnwww',
            'R'=>'wnnnnnwwn', 'S'=>'nnwnnnwwn', 'T'=>'nnnnwnwwn',
            'U'=>'wwnnnnnnw', 'V'=>'nwwnnnnnw', 'W'=>'wwwnnnnnn',
            'X'=>'nwnnwnnnw', 'Y'=>'wwnnwnnnn', 'Z'=>'nwwnwnnnn',
            '-'=>'nwnnnnwnw', '.'=>'wwnnnnwnn', ' '=>'nwwnnnwnn',
            '*'=>'nwnnwnwnn', '$'=>'nwnwnwnnn', '/' =>'nwnwnnnwn',
            '+' =>'nwnnnwnwn', '%' =>'nnnwnwnwn'
        ];
        $code = '*' . strtoupper($code) . '*';
        for ($i = 0; $i < strlen($code); $i++) {
            $char = $code[$i];
            if (!isset($barChar[$char])) {
                $this->Error('Caracter no válido en código de barras: ' . $char);
            }
            $seq = $barChar[$char];
            for ($bar = 0; $bar < 9; $bar++) {
                $lineWidth = ($seq[$bar] == 'n') ? $narrow : $wide;
                if ($bar % 2 == 0) {
                    $this->Rect($x, $y, $lineWidth, $h, 'F');
                }
                $x += $lineWidth;
            }
            $x += $narrow;
        }
    }
}

// --------------------- LÓGICA ---------------------

$conexion = new mysqli("localhost", "root", "", "produccion_quiebras");
if ($conexion->connect_error) {
    die("Conexión fallida: " . $conexion->connect_error);
}

// Guardar la referencia Odoo si se ha enviado el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['referencia_odoo'], $_POST['pedido_id'])) {
    $pedido_id = intval($_POST['pedido_id']);
    $referencia_odoo = trim($_POST['referencia_odoo']);

    if ($pedido_id > 0 && $referencia_odoo !== '') {
        $stmt = $conexion->prepare("UPDATE pedidos SET referencia_odoo = ? WHERE id = ?");
        $stmt->bind_param("si", $referencia_odoo, $pedido_id);
        $stmt->execute();
        $stmt->close();

        // Refrescar para mostrar en la tabla
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Si solo se está imprimiendo un pedido individual (PDF u otra acción)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['pedido_id']) && !isset($_POST['referencia_odoo'])) {
    $pedido_id = intval($_POST['pedido_id']);

    // Obtener datos generales del pedido
    $stmtPedido = $conexion->prepare("SELECT solicitante, motivo_solicitud, fecha FROM pedidos WHERE id = ?");
    $stmtPedido->bind_param("i", $pedido_id);
    $stmtPedido->execute();
    $resultPedido = $stmtPedido->get_result();

    if ($resultPedido->num_rows === 0) {
        die("Pedido no encontrado.");
    }

    $pedido = $resultPedido->fetch_assoc();
    $stmtPedido->close();

    // Obtener productos del pedido
    $stmtDetalle = $conexion->prepare("SELECT producto, codigo_barras, cantidad FROM detalle_pedido WHERE pedido_id = ?");
    $stmtDetalle->bind_param("i", $pedido_id);
    $stmtDetalle->execute();
    $resultDetalle = $stmtDetalle->get_result();

    $productos = [];
    while ($row = $resultDetalle->fetch_assoc()) {
        $productos[] = $row;
    }
    $stmtDetalle->close();

    // Crear PDF
    $pdf = new PDF_Code39('P', 'mm', [139.7, 215.9]);
    $pdf->AddPage();

    date_default_timezone_set('America/Costa_Rica');
    $fechaHora = date("d/m/Y H:i:s");
    $pageWidth = $pdf->GetPageWidth();

    // Logo (ajusta la ruta si es necesario)
    $logoPath = $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/logo.png';
    $logoWidth = 30;
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, $pageWidth - $logoWidth - 3, 3, $logoWidth);
    }

    // Encabezado con diseño mejorado
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(0, 80, 0); // Verde oscuro
    $pdf->Cell(0, 8, utf8_decode('MAYORISTAS ÓPTICOS J Y Z CENTROAMERICANOS S.A'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, utf8_decode('Pedido de Suministros'), 0, 1, 'C', false, '', 0, 0, 'C', true);
    
    // Línea decorativa
    $pdf->SetDrawColor(0, 100, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY(), $pageWidth-10, $pdf->GetY());
    $pdf->Ln(5);

    // Fecha y hora con estilo
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, utf8_decode("Fecha y hora del pedido: ") . $fechaHora, 0, 1, 'C');
    $pdf->Ln(3);

    require_once('phpqrcode/qrlib.php'); // Asegúrate de tener phpqrcode

    // Concatenar ID Pedido + Solicitante para el QR
    $codigo_qr_texto = $pedido_id . '-' . $pedido['solicitante'];

    // Guardar posición actual
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // Ruta temporal del QR
    $qr_temp = tempnam(sys_get_temp_dir(), 'qr') . '.png';

    // Generar QR (ahora más compacto, tamaño módulo = 3)
    QRcode::png($codigo_qr_texto, $qr_temp, QR_ECLEVEL_L, 3);

    // Insertar QR en el PDF más pequeño (15x15 mm)
    $pdf->Image($qr_temp, $x, $y, 15, 15);

    // Posicionar texto justo debajo del QR
    $pdf->SetXY($x, $y + 17); // ajustado por el tamaño nuevo
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(30, 6, utf8_decode('ID Pedido:'), 0, 0, 'L');

    $pdf->SetFont('Arial', '', 9);
    // Solo mostrar el ID (sin solicitante)
    $pdf->Cell(50, 6, $pedido_id, 0, 1, 'L');

    // Mostrar referencia Odoo debajo
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(25, 6, utf8_decode('Ref. Odoo:'), 0, 0, 'R');

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, '___________________________', 0, 1, 'L');

    $pdf->Ln(4);

    // Solicitante con fondo
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(30, 7, utf8_decode('Solicitante:'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 7, utf8_decode($pedido['solicitante']), 1, 1, 'L', true);
    $pdf->Ln(5);

    // Tabla de productos con diseño mejorado
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(50, 120, 50); // Verde oscuro
    $pdf->SetTextColor(255, 255, 255); // Blanco
    $pdf->Cell(60, 7, utf8_decode('Producto'), 1, 0, 'C', true);
    $pdf->Cell(25, 7, utf8_decode('Cantidad'), 1, 0, 'C', true);
    $pdf->Cell(0, 7, utf8_decode('Código'), 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0); // Negro
    $rowHeight = 8;

    foreach ($productos as $p) {
        if ($pdf->GetY() + $rowHeight + 35 > $pdf->GetPageHeight()) {
            $pdf->AddPage();
            if (file_exists($logoPath)) {
                $pdf->Image($logoPath, $pageWidth - $logoWidth - 3, 3, $logoWidth);
            }
            // Encabezado de tabla en nueva página
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(50, 120, 50);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(60, 7, utf8_decode('Producto'), 1, 0, 'C', true);
            $pdf->Cell(25, 7, utf8_decode('Cantidad'), 1, 0, 'C', true);
            $pdf->Cell(0, 7, utf8_decode('Código'), 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(0, 0, 0);
        }

        $nombre = utf8_decode($p['producto']);
        $cant = $p['cantidad'];
        $codigo = preg_replace('/[^0-9]/', '', $p['codigo_barras']);

        $pdf->Cell(60, $rowHeight, $nombre, 1, 0, 'L');
        $pdf->Cell(25, $rowHeight, $cant, 1, 0, 'C');
        $pdf->Cell(0, $rowHeight, $codigo, 1, 1, 'C');
    }
    $pdf->Ln(8);

    // Motivo con diseño mejorado
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, utf8_decode("Motivo de la solicitud:"), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 6, utf8_decode($pedido['motivo_solicitud']), 1, 'L');
    $pdf->Ln(10);

    // Firmas con diseño mejorado
    $w = $pdf->GetPageWidth() / 3;
    $pdf->SetFont('Arial', '', 8);
    
    // Líneas de firma con estilo
    $pdf->SetDrawColor(100, 100, 100);
    $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + $w - 10, $pdf->GetY());
    $pdf->Line($pdf->GetX() + $w, $pdf->GetY(), $pdf->GetX() + 2*$w - 10, $pdf->GetY());
    $pdf->Line($pdf->GetX() + 2*$w, $pdf->GetY(), $pdf->GetX() + 3*$w - 10, $pdf->GetY());
    $pdf->Ln(5);
    
    // Textos debajo de cada firma
    $pdf->Cell($w, 5, utf8_decode('Nombre entrega'), 0, 0, 'C');
    $pdf->Cell($w, 5, utf8_decode('Nombre recibe'), 0, 0, 'C');
    $pdf->Cell($w, 5, utf8_decode('Nombre autoriza'), 0, 1, 'C');

    $pdf->Output("I", "pedido_{$pedido_id}.pdf");
    exit;
}
?>

<?php
// 📅 Fechas para filtro
$fecha_inicio = $_GET['desde'] ?? date('Y-m-d');
$fecha_fin = $_GET['hasta'] ?? date('Y-m-d');

// 📦 Obtener pedidos para mostrar
$pedidos = [];
$stmt = $conexion->prepare("SELECT id, solicitante, fecha, estado FROM pedidos WHERE DATE(fecha) BETWEEN ? AND ? ORDER BY fecha DESC");
$stmt->bind_param("ss", $fecha_inicio, $fecha_fin);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $pedidos[] = $row;
$stmt->close();

// 2. Exportar pedidos + productos como CSV
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment;filename=pedidos_" . date("Ymd_His") . ".csv");
    $salida = fopen("php://output", "w");

    // BOM para Excel
    fprintf($salida, chr(0xEF).chr(0xBB).chr(0xBF));

    // Encabezado CSV
    fputcsv($salida, [
        'ID Pedido',
        'Solicitante',
        'Fecha',
        'Estado',
        'Referencia Odoo',
        'Producto',
        'Código de Barras',
        'Cantidad'
    ]);

    $q = $conexion->prepare("
        SELECT 
            p.id AS pedido_id,
            p.solicitante,
            p.fecha,
            p.estado,
            p.referencia_odoo,
            d.producto,
            d.codigo_barras,
            d.cantidad
        FROM pedidos p
        LEFT JOIN detalle_pedido d ON p.id = d.pedido_id
        WHERE DATE(p.fecha) BETWEEN ? AND ?
        ORDER BY p.fecha DESC, p.id ASC
    ");
    $q->bind_param("ss", $fecha_inicio, $fecha_fin);
    $q->execute();
    $res = $q->get_result();
    while ($row = $res->fetch_assoc()) {
        fputcsv($salida, [
            $row['pedido_id'],
            $row['solicitante'],
            $row['fecha'],
            $row['estado'],
            $row['referencia_odoo'],
            $row['producto'],
            $row['codigo_barras'] ?? '',
            $row['cantidad']
        ]);
    }
    fclose($salida);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Pedidos</title>
    <meta http-equiv="refresh" content="30">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    :root {
        --color-primary: #2e7d32;
        --color-primary-dark: #1b5e20;
        --color-primary-light: #81c784;
        --color-secondary: #ff8f00;
        --color-background: #2e7d32;
        --color-text: #333;
        --color-text-light: #666;
        --color-white: #fff;
        --color-success: #4caf50;
        --color-warning: #ff9800;
        --color-error: #f44336;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.08);
        --shadow-lg: 0 10px 25px rgba(0,0,0,0.1), 0 5px 10px rgba(0,0,0,0.05);
        --transition: all 0.3s ease;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        background-color: var(--color-background);
        color: var(--color-text);
        font-family: 'Poppins', sans-serif;
        line-height: 1.6;
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    header {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: var(--color-white);
        padding: 1.5rem 0;
        text-align: center;
        box-shadow: var(--shadow-md);
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
    }

    header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path fill="rgba(255,255,255,0.05)" d="M0,0 L100,0 L100,100 L0,100 Z" /></svg>');
        background-size: cover;
        opacity: 0.1;
    }

    header h1 {
        font-size: 2.2rem;
        margin-bottom: 0.5rem;
        font-weight: 600;
        position: relative;
    }

    header p {
        font-size: 1rem;
        opacity: 0.9;
        font-weight: 300;
        position: relative;
    }

    .card {
        background: var(--color-white);
        border-radius: 10px;
        box-shadow: var(--shadow-sm);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        transition: var(--transition);
    }

    .card:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .card-title {
        font-size: 1.25rem;
        font-weight: 500;
        color: var(--color-primary-dark);
    }

    label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: var(--color-text);
    }

    input[type="date"],
    select,
    button,
    .btn {
        width: 100%;
        padding: 0.75rem 1rem;
        border-radius: 6px;
        border: 1px solid #ddd;
        font-size: 1rem;
        font-family: inherit;
        transition: var(--transition);
        margin-bottom: 1rem;
    }

    input[type="date"]:focus,
    select:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.2);
    }

    button,
    .btn {
        background-color: var(--color-primary);
        color: var(--color-white);
        font-weight: 500;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    button:hover,
    .btn:hover {
        background-color: var(--color-primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    button i,
    .btn i {
        margin-right: 8px;
    }

    .btn-sound {
        background-color: var(--color-secondary);
        padding: 0.75rem 1.5rem;
        border-radius: 6px;
        font-weight: 500;
        margin-bottom: 1.5rem;
        width: auto;
        display: inline-flex;
    }

    .btn-sound:hover {
        background-color: #e65100;
    }

    .btn-mini {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        width: auto;
        margin: 0;
    }

    .btn-export {
        background-color: #1976d2;
    }

    .btn-export:hover {
        background-color: #1565c0;
    }

    .actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .table-container {
        background: var(--color-white);
        border-radius: 10px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 2rem;
        max-height: 500px;
        overflow-y: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
        min-width: 800px;
    }

    thead {
        position: sticky;
        top: 0;
        z-index: 10;
    }

    thead th {
        background: linear-gradient(to right, var(--color-primary), var(--color-primary-dark));
        color: var(--color-white);
        padding: 1rem;
        font-weight: 500;
        text-align: left;
    }

    tbody tr {
        border-bottom: 1px solid #eee;
        transition: var(--transition);
    }

    tbody tr:last-child {
        border-bottom: none;
    }

    tbody tr:hover {
        background-color: #f9f9f9;
    }

    td {
        padding: 1rem;
        color: var(--color-text);
    }

    .status {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: var(--color-primary);
        cursor: pointer;
    }

    .form-inline {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .form-inline input[type="text"] {
        flex: 1;
        padding: 0.5rem;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    .alert {
        padding: 1rem;
        border-radius: 6px;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-success {
        background-color: #e8f5e9;
        color: var(--color-success);
        border-left: 4px solid var(--color-success);
    }

    .alert-error {
        background-color: #ffebee;
        color: var(--color-error);
        border-left: 4px solid var(--color-error);
    }

    .alert i {
        font-size: 1.25rem;
    }

    .firma {
    text-align: center;
    font-size: 15px;
    color: #d4fcd4;
    padding: 15px 0;
    background-color: #003300;
    border-top: 1px solid #d4fcd4;
    width: 100%;
    position: fixed;
    left: 0;
    bottom: 0;
    box-sizing: border-box;
}

    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .badge-success {
        background-color: #e8f5e9;
        color: var(--color-success);
    }

    .badge-pending {
        background-color: #fff3e0;
        color: var(--color-warning);
    }

    @media (max-width: 768px) {
        header h1 {
            font-size: 1.8rem;
        }
        
        .container {
            padding: 15px;
        }
        
        .card {
            padding: 1rem;
        }
        
        td, th {
            padding: 0.75rem;
        }
        
        .form-inline {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }
        
        .form-inline input[type="text"] {
            width: 100%;
        }
    }

    /* Animación para nuevos pedidos */
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }

    .new-order {
        animation: pulse 1.5s infinite;
        position: relative;
    }

    .new-order::after {
        content: '';
        position: absolute;
        top: -5px;
        right: -5px;
        width: 10px;
        height: 10px;
        background-color: var(--color-success);
        border-radius: 50%;
        box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.3);
    }
</style>
</head>
<body>

<header>
    <div class="container">
        <h1><i class="fas fa-clipboard-list"></i> Sistema de Pedidos</h1>
        <p>Gestión en tiempo real de pedidos y reportes</p>
    </div>
</header>

<div class="container">
    <?php
    date_default_timezone_set("America/Costa_Rica");

    $conexion = new mysqli("localhost", "root", "", "produccion_quiebras");
    if ($conexion->connect_error) {
        die("Conexión fallida: " . $conexion->connect_error);
    }

    $conexion->query("SET time_zone = '-06:00'");

    $fecha_inicio = $_GET['desde'] ?? date("Y-m-d");
    $fecha_fin = $_GET['hasta'] ?? date("Y-m-d");

    $errores = "";
    $mostrar_mensaje_error = false;

    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_inicio) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_fin)) {
        $errores = "⚠️ Formato de fecha no válido. Se mostrarán los pedidos de hoy.";
        $fecha_inicio = $fecha_fin = date("Y-m-d");
        $mostrar_mensaje_error = true;
    } elseif ($fecha_inicio > $fecha_fin) {
        $errores = "⚠️ La fecha 'Desde' no puede ser mayor que la fecha 'Hasta'. Se mostrarán los pedidos de hoy.";
        $fecha_inicio = $fecha_fin = date("Y-m-d");
        $mostrar_mensaje_error = true;
    }

    if ($mostrar_mensaje_error && isset($_GET['desde'])) {
        $base_url = strtok($_SERVER["REQUEST_URI"], '?');
        echo "<script>location.href='$base_url?error=" . urlencode($errores) . "';</script>";
        exit;
    }

    $mensaje_url = $_GET['error'] ?? '';

    $fecha_inicio_completa = $fecha_inicio . " 00:00:00";
    $fecha_fin_completa = $fecha_fin . " 23:59:59";

    // Solo UNA consulta, usando fechas con horas completas
    $sql = "SELECT id, solicitante, fecha, estado, referencia_odoo 
            FROM pedidos 
            WHERE fecha BETWEEN ? AND ? 
            ORDER BY fecha DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("ss", $fecha_inicio_completa, $fecha_fin_completa);
    $stmt->execute();
    $result = $stmt->get_result();

    $pedidos = [];
    while ($row = $result->fetch_assoc()) {
        $pedidos[] = $row;
    }

    $stmt->close();
    ?>

    <!-- Mostrar mensaje de error si hay -->
    <?php if ($mensaje_url): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?= htmlspecialchars($mensaje_url) ?></div>
        </div>
    <?php endif; ?>

    <!-- Botón con clase estilizada -->
    <button id="activarSonido" class="btn-sound">
        <i class="fas fa-bell"></i> Activar alertas con sonido
    </button>

    <!-- Formulario de fechas -->
    <div class="card">
        <form method="GET">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label for="desde"><i class="far fa-calendar-alt"></i> Desde:</label>
                    <input type="date" name="desde" id="desde" value="<?= htmlspecialchars($fecha_inicio) ?>" required>
                </div>
                <div>
                    <label for="hasta"><i class="far fa-calendar-alt"></i> Hasta:</label>
                    <input type="date" name="hasta" id="hasta" value="<?= htmlspecialchars($fecha_fin) ?>" required>
                </div>
            </div>
            <button type="submit"><i class="fas fa-filter"></i> Filtrar</button>
            
            <div class="actions">
                <a href="?desde=<?= htmlspecialchars($fecha_inicio) ?>&hasta=<?= htmlspecialchars($fecha_fin) ?>&exportar=csv" class="btn btn-export">
                    <i class="fas fa-file-export"></i> Exportar CSV
                </a>
            </div>
        </form>
    </div>

    <!-- Listado -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fas fa-list"></i> Listado de Pedidos</h2>
            <span class="badge"><?= date("d/m/Y", strtotime($fecha_inicio)) ?> - <?= date("d/m/Y", strtotime($fecha_fin)) ?></span>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Solicitante</th>
                        <th>Fecha</th>
                        <th>PDF</th>
                        <th>Estado</th>
                        <th>Referencia Odoo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pedidos)): ?>
                        <?php foreach ($pedidos as $pedido): ?>
                            <tr>
                                <td><?= $pedido['id'] ?></td>
                                <td><?= htmlspecialchars($pedido['solicitante']) ?></td>
                                <td><?= date("d/m/Y H:i", strtotime($pedido['fecha'])) ?></td>
                                <td>
                                    <form method="POST" action="imprimir_pedido.php" style="margin:0;">
                                        <input type="hidden" name="pedido_id" value="<?= $pedido['id'] ?>">
                                        <button type="submit" class="btn-mini"><i class="fas fa-file-pdf"></i> PDF</button>
                                    </form>
                                </td>
                                <td>
                                    <label class="status">
                                        <input type="checkbox"
                                               onchange="actualizarEstado(this)"
                                               <?= $pedido['estado'] === 'Entregado' ? 'checked' : '' ?>
                                               data-id="<?= $pedido['id'] ?>">
                                        <?= $pedido['estado'] === 'Entregado' ? 
                                            '<span class="badge badge-success">Entregado</span>' : 
                                            '<span class="badge badge-pending">Pendiente</span>' ?>
                                    </label>
                                </td>
                                <td>
                                    <form method="POST" class="form-inline">
                                        <input type="hidden" name="pedido_id" value="<?= $pedido['id'] ?>">
                                        <?php if (!empty($pedido['referencia_odoo'])): ?>
                                            <span style="background:#f5f5f5; padding:0.5rem; border-radius:4px;">
                                                <?= htmlspecialchars($pedido['referencia_odoo']) ?>
                                            </span>
                                        <?php else: ?>
                                            <input type="text"
                                                   name="referencia_odoo"
                                                   value=""
                                                   placeholder="Ingresa referencia"
                                                   required>
                                            <button type="submit" class="btn-mini" title="Guardar referencia"><i class="fas fa-save"></i></button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center;">No hay pedidos en ese rango de fechas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="mensaje-nuevo-pedido" class="alert alert-success" style="display:none;">
        <i class="fas fa-box-open"></i>
        <div>📦 ¡Nuevo pedido recibido!</div>
    </div>
</div>

<!-- Pie de página -->
<div class="firma">
  Sistema de control de Pedidos | © <?= date("Y"); ?>
  <p>By: Nestor Rosales | Rosales_Dev91</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const audio = new Audio('/control_produccion/public/alerta.mp3');
    let sonidoHabilitado = localStorage.getItem("sonidoHabilitado") === "true";

    // Activar sonido manualmente
    document.getElementById('activarSonido').addEventListener('click', () => {
        audio.play()
            .then(() => {
                sonidoHabilitado = true;
                localStorage.setItem("sonidoHabilitado", "true");
                console.log("🔊 Sonido habilitado correctamente.");
                Swal.fire({
                    icon: 'success',
                    title: '✅ Sonido activado',
                    text: 'Ahora se reproducirá cuando llegue un nuevo pedido.',
                    timer: 2500,
                    showConfirmButton: false
                });
            })
            .catch(err => {
                console.warn("⚠️ El navegador bloqueó la reproducción:", err);
                Swal.fire({
                    icon: 'warning',
                    title: '⚠️ El navegador bloqueó el sonido',
                    text: 'Vuelve a hacer clic para activarlo.',
                    confirmButtonText: 'Entendido'
                });
            });
    });

    // Inicializa último pedido registrado
    if (!localStorage.getItem("ultimoPedidoID")) {
        const filas = document.querySelectorAll("tbody tr");
        let maxId = 0;
        filas.forEach(fila => {
            const id = parseInt(fila.children[0].textContent.trim(), 10);
            if (!isNaN(id) && id > maxId) maxId = id;
        });
        localStorage.setItem("ultimoPedidoID", maxId);
        console.log("🔢 Inicializado ultimoPedidoID con:", maxId);
    }

    function detectarNuevoPedido() {
        try {
            const filas = document.querySelectorAll("tbody tr");
            let maxId = 0;
            filas.forEach(fila => {
                const id = parseInt(fila.children[0].textContent.trim(), 10);
                if (!isNaN(id) && id > maxId) maxId = id;
            });

            const ultimoGuardado = parseInt(localStorage.getItem("ultimoPedidoID"), 10) || 0;

            if (maxId > ultimoGuardado) {
                console.log(`🆕 Nuevo pedido detectado: ${maxId} (antes ${ultimoGuardado})`);
                if (ultimoGuardado !== 0) mostrarMensajeNuevoPedido(maxId);
                localStorage.setItem("ultimoPedidoID", maxId);
            }
        } catch (error) {
            console.error("❌ Error al detectar nuevo pedido:", error);
        }
    }

    function mostrarMensajeNuevoPedido(pedidoId) {
        // Mostrar notificación
        const notificacion = new Notification('Nuevo pedido recibido', {
            body: `Se ha registrado el pedido #${pedidoId}`,
            icon: '/control_produccion/public/logo.png'
        });

        // Mostrar SweetAlert
        Swal.fire({
            icon: 'info',
            title: '📦 ¡Nuevo pedido recibido!',
            text: `Pedido #${pedidoId}`,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 5000,
            timerProgressBar: true
        });

        // Resaltar la fila del nuevo pedido
        const filas = document.querySelectorAll("tbody tr");
        filas.forEach(fila => {
            if (parseInt(fila.children[0].textContent.trim(), 10) === pedidoId) {
                fila.classList.add('new-order');
                setTimeout(() => fila.classList.remove('new-order'), 10000);
            }
        });

        const mensajeDiv = document.getElementById('mensaje-nuevo-pedido');
        mensajeDiv.style.display = 'flex';

        setTimeout(() => {
            mensajeDiv.style.display = 'none';
            console.log("⌛ Mensaje ocultado, estado sonido:", sonidoHabilitado);

            if (sonidoHabilitado) {
                audio.play()
                    .then(() => console.log("🔔 Audio reproducido después del mensaje"))
                    .catch(err => console.warn("❌ Error al reproducir audio:", err));
            } else {
                console.log("🔕 Sonido NO habilitado, no se reproduce");
            }
        }, 5000);
    }

    // Solicitar permisos para notificaciones
    if (Notification.permission !== "granted" && Notification.permission !== "denied") {
        Notification.requestPermission().then(permission => {
            console.log("Permiso para notificaciones:", permission);
        });
    }

    setInterval(detectarNuevoPedido, 3000);
});

function actualizarEstado(checkbox) {
    const id = checkbox.dataset.id;
    const estado = checkbox.checked ? "Entregado" : "Pendiente";
    const fila = checkbox.closest('tr');
    const badge = fila.querySelector('.badge');

    // Actualizar visualmente primero
    if (checkbox.checked) {
        badge.className = 'badge badge-success';
        badge.textContent = 'Entregado';
    } else {
        badge.className = 'badge badge-pending';
        badge.textContent = 'Pendiente';
    }

    fetch("actualizar_estado.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: `actualizar_estado=1&pedido_id=${id}&estado=${encodeURIComponent(estado)}`
    })
    .then(response => {
        if (!response.ok) throw new Error("Error en la actualización");
        return response.text();
    })
    .then(text => {
        console.log("✅ Estado actualizado:", text);
    })
    .catch(error => {
        console.error("❌ Error:", error);
        // Revertir cambios visuales si hay error
        checkbox.checked = !checkbox.checked;
        if (checkbox.checked) {
            badge.className = 'badge badge-success';
            badge.textContent = 'Entregado';
        } else {
            badge.className = 'badge badge-pending';
            badge.textContent = 'Pendiente';
        }
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'No se pudo actualizar el estado',
            timer: 2000
        });
    });
}
</script>

</body>
</html>