<?php
require_once dirname(__DIR__) . '/config/database.php';

if (!class_exists('FPDF')) {
    require_once(__DIR__ . '/fpdf/fpdf.php');
}

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

// Usar la conexión de database.php en lugar de crear una nueva
global $conn;

$empleados = [];
$r = $conn->query("SELECT nombre_empleado FROM empleados ORDER BY nombre_empleado");
if ($r) {
    while ($row = $r->fetch_assoc()) $empleados[] = $row['nombre_empleado'];
    $r->free();
}

$insumos = [];
$r = $conn->query("SELECT producto, codigo_barras FROM insumos_laboratorio ORDER BY producto");
if ($r) {
    while ($row = $r->fetch_assoc()) $insumos[] = ['producto' => $row['producto'], 'codigo' => $row['codigo_barras']];
    $r->free();
}

$suministros = [];
$r = $conn->query("SELECT producto, codigo_barras FROM suministros_oficina ORDER BY producto");
if ($r) {
    while ($row = $r->fetch_assoc()) $suministros[] = ['producto' => $row['producto'], 'codigo' => $row['codigo_barras']];
    $r->free();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    ob_start();

    $solicitante = isset($_POST["solicitante"]) ? trim($_POST["solicitante"]) : '';
    $motivo = isset($_POST["motivo"]) ? trim($_POST["motivo"]) : '';
    $productos_json = isset($_POST["productos"]) ? json_decode($_POST["productos"], true) : [];
    $cantidades = isset($_POST["cantidades"]) ? json_decode($_POST["cantidades"], true) : [];

    // Validar que el solicitante exista en la base de datos
    $stmt_validar = $conn->prepare("SELECT COUNT(*) FROM empleados WHERE nombre_empleado = ?");
    if ($stmt_validar) {
        $stmt_validar->bind_param("s", $solicitante);
        $stmt_validar->execute();
        $stmt_validar->bind_result($existe);
        $stmt_validar->fetch();
        $stmt_validar->close();
    } else {
        $existe = false;
    }

    if (!$existe) {
        die("El solicitante no existe en la base de datos. Por favor seleccione un nombre válido de la lista.");
    }

    if ($solicitante === '' || $motivo === '' || empty($productos_json) || empty($cantidades)) {
        die("Faltan datos para procesar el pedido.");
    }

    // Insertar pedido general
    $stmt_pedido = $conn->prepare("INSERT INTO pedidos (solicitante, motivo_solicitud) VALUES (?, ?)");
    if (!$stmt_pedido) {
        die("Error en preparación de consulta de pedido: " . $conn->error);
    }
    $stmt_pedido->bind_param("ss", $solicitante, $motivo);
    $stmt_pedido->execute();

    $pedido_id = $conn->insert_id;
    $stmt_pedido->close();

    if ($pedido_id <= 0) {
        die("Error al insertar el pedido.");
    }

    // Insertar detalles
    $stmt_detalle = $conn->prepare("INSERT INTO detalle_pedido (pedido_id, producto, codigo_barras, cantidad) VALUES (?, ?, ?, ?)");
    if (!$stmt_detalle) {
        die("Error en preparación de consulta de detalle: " . $conn->error);
    }

    $productos_finales = [];

    foreach ($productos_json as $index => $prod) {
        $producto = isset($prod["producto"]) ? $prod["producto"] : '';
        $codigo = isset($prod["codigo"]) ? $prod["codigo"] : '';
        $cantidad = isset($cantidades[$index]) ? intval($cantidades[$index]) : 0;

        if ($producto === '' || $codigo === '' || $cantidad <= 0) {
            continue;
        }

        $stmt_detalle->bind_param("issi", $pedido_id, $producto, $codigo, $cantidad);
        $stmt_detalle->execute();

        $productos_finales[] = [
            'producto' => $producto,
            'codigo' => $codigo,
            'cantidad' => $cantidad
        ];
    }
    $stmt_detalle->close();

    // Crear PDF
    $pdf = new PDF_Code39('P', 'mm', array(139.7, 215.9));
    $pdf->AddPage();

    date_default_timezone_set('America/Costa_Rica');
    $fechaHora = date("d/m/Y H:i:s");

    $pageWidth = $pdf->GetPageWidth();
    $logoPath = $_SERVER['DOCUMENT_ROOT'] . '/control_produccion/public/logo.png';
    $logoWidth = 30;

    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, $pageWidth - $logoWidth - 3, 3, $logoWidth);
    }

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 6, utf8_decode('MAYORISTAS ÓPTICOS J Y Z CENTROAMERICANOS S.A'), 0, 1, 'C');
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 8, utf8_decode('Pedido de Suministros'), 0, 1, 'C');

    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 5, utf8_decode("Fecha y hora del pedido: ") . $fechaHora, 0, 1, 'C');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(25, 5, utf8_decode('ID Pedido:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(30, 5, $pedido_id, 0, 1, 'L');
    $pdf->Ln(6);

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(28, 5, utf8_decode('Solicitante:'), 1, 0, 'L');
    $pdf->Cell(0, 5, utf8_decode($solicitante), 1, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(58, 5, utf8_decode('Producto'), 1, 0, 'C', true);
    $pdf->Cell(20, 5, utf8_decode('Cantidad'), 1, 0, 'C', true);
    $pdf->Cell(0, 5, utf8_decode('Código'), 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 7);
    $rowHeight = 8;

    foreach ($productos_finales as $p) {
        if ($pdf->GetY() + $rowHeight + 35 > $pdf->GetPageHeight()) {
            $pdf->AddPage();
            if (file_exists($logoPath)) {
                $pdf->Image($logoPath, $pageWidth - $logoWidth - 8, 8, $logoWidth);
            }
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(58, 5, utf8_decode('Producto'), 1, 0, 'C', true);
            $pdf->Cell(20, 5, utf8_decode('Cantidad'), 1, 0, 'C', true);
            $pdf->Cell(0, 5, utf8_decode('Código producto'), 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 7);
        }

        $nombre = utf8_decode($p['producto']);
        $cant = $p['cantidad'];
        $codigo = preg_replace('/[^0-9]/', '', $p['codigo']);

        $pdf->Cell(58, $rowHeight, $nombre, 1, 0, 'L');
        $pdf->Cell(20, $rowHeight, $cant, 1, 0, 'C');
        $pdf->Cell(0, $rowHeight, $codigo, 1, 1, 'C');
    }
    $pdf->Ln(3);

    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(0, 5, utf8_decode("Motivo de la solicitud:\n") . utf8_decode($motivo), 1, 'L');
    $pdf->Ln(8);

    $w = $pdf->GetPageWidth() / 3;
    $pdf->Cell($w, 7, '_________________________', 0, 0, 'C');
    $pdf->Cell($w, 7, '_________________________', 0, 0, 'C');
    $pdf->Cell($w, 7, '_________________________', 0, 1, 'C');

    $pdf->SetFont('Arial', '', 6.5);
    $pdf->Cell($w, 5, utf8_decode('Firma entrega'), 0, 0, 'C');
    $pdf->Cell($w, 5, utf8_decode('Firma recibe'), 0, 0, 'C');
    $pdf->Cell($w, 5, utf8_decode('Firma autoriza'), 0, 1, 'C');

    $pdf->Output("I", "pedido_{$pedido_id}.pdf");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Pedido - Sistema de Control</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --primary-green: #28a745;
        --primary-dark: #155724;
        --secondary-green: #1e7e34;
        --light-green: #d4fcd4;
        --success-green: #20c997;
        --dark-green: #003300;
        --white: #ffffff;
        --light-gray: #f8f9fa;
        --border-color: #dee2e6;
        --shadow: rgba(0, 0, 0, 0.15);
        --shadow-hover: rgba(0, 0, 0, 0.25);
        --border-radius: 12px;
        --border-radius-sm: 8px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        height: 100%;
        font-family: var(--font-family);
        line-height: 1.6;
        color: var(--white);
    }

    body {
        font-family: var(--font-family);
        line-height: 1.6;
        color: var(--white);
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-green) 50%, var(--primary-green) 100%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        padding-bottom: 80px;
    }

    .header {
        background: rgba(0, 51, 0, 0.95);
        backdrop-filter: blur(10px);
        border-bottom: 2px solid var(--primary-green);
        padding: 15px 0;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
    }
    
    .header-content {
        max-width: 1200px;
        margin: 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 20px;
    }

    .logo-container img {
        height: 60px;
        filter: brightness(1.1) drop-shadow(0 2px 4px rgba(0,0,0,0.3));
    }

    .header-info {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .clock {
        font-size: 18px;
        font-weight: 600;
        color: var(--light-green);
        text-shadow: 0 1px 2px rgba(0,0,0,0.5);
    }

    .logout-btn {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: var(--border-radius-sm);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        box-shadow: 0 2px 10px rgba(220, 53, 69, 0.3);
    }

    .logout-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
    }

    .main-container {
        max-width: 1000px;
        margin: 30px auto;
        padding: 0 20px 60px;
    }

    .card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: var(--border-radius);
        padding: 25px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        color: var(--primary-dark);
        margin-bottom: 25px;
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--border-color);
    }

    .card-header i {
        color: var(--primary-green);
        font-size: 24px;
    }

    .card-header h3 {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-dark);
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--primary-dark);
        font-size: 14px;
    }

    .form-control {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--border-color);
        border-radius: var(--border-radius-sm);
        font-size: 16px;
        transition: var(--transition);
        background: var(--white);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-green);
        box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: var(--border-radius-sm);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 14px;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-green), var(--success-green));
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
    }

    .btn-secondary {
        background: linear-gradient(135deg, #6c757d, #5a6268);
        color: white;
    }

    .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
    }

    .btn-danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
    }

    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(220, 53, 69, 0.3);
    }

    .radio-group {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
    }

    .radio-option {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .table-container {
        background: var(--white);
        border-radius: var(--border-radius);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 15px;
    }

    .table-wrapper {
        overflow-y: auto;
        max-height: 300px;
    }

    .table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 14px;
    }

    .table thead {
        background: linear-gradient(135deg, var(--primary-green), var(--success-green));
        color: white;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .table th {
        padding: 12px 15px;
        text-align: left;
        position: sticky;
        top: 0;
        font-weight: 600;
    }

    .table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
    }

    .table tbody tr:last-child td {
        border-bottom: none;
    }

    .table tbody tr:hover {
        background: rgba(40, 167, 69, 0.05);
        transition: background-color 0.2s ease;
    }

    .product-search {
        position: relative;
        margin-bottom: 15px;
    }

    #productoBusqueda {
        padding-left: 40px;
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%236c757d" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>');
        background-repeat: no-repeat;
        background-position: 12px center;
    }

    .footer {
        background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
        color: var(--light-green);
        text-align: center;
        padding: 15px;
        font-size: 0.9rem;
        border-top: 1px solid rgba(212, 252, 212, 0.2);
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 60;
        height: 70px;
    }
    
    .footer p {
        margin: 0;
        opacity: 0.9;
    }

    .footer .developer {
        font-size: 0.8rem;
        margin-top: 5px;
        opacity: 0.7;
    }

    .support-buttons {
        position: fixed;
        bottom: 90px;
        right: 20px;
        z-index: 1000;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .support-btn {
        background: linear-gradient(135deg, #25D366, #128C7E);
        color: white;
        padding: 15px 20px;
        border-radius: 50px;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        transition: var(--transition);
        animation: pulse 2s infinite;
    }

    .support-btn:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 8px 25px rgba(37, 211, 102, 0.4);
    }

    .support-btn.odoo {
        background: linear-gradient(135deg, #17a2b8, #138496);
        animation: none;
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        }
        50% {
            box-shadow: 0 4px 25px rgba(37, 211, 102, 0.6);
        }
        100% {
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        }
    }

    @media (max-width: 768px) {
        .main-container {
            padding: 0 15px 50px;
        }
        
        .card {
            padding: 20px;
        }
        
        .radio-group {
            flex-direction: column;
            gap: 10px;
        }
        
        .support-buttons {
            bottom: 80px;
            right: 15px;
        }
    }

    @media (max-width: 480px) {
        .header-content {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .support-btn {
            padding: 12px 16px;
            font-size: 14px;
        }
    }
</style>
</head>

<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="/control_produccion/public/logo.png" alt="Logo de la empresa">
            </div>
            <div class="header-info">
                <div class="clock" id="reloj"></div>
                <a href="login.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <h1 style="text-align: center; margin-bottom: 25px; color: var(--light-green);">Sistema de Pedidos</h1>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-clipboard-list"></i>
                <h3>Nuevo Pedido</h3>
            </div>
            <p style="color: var(--primary-dark); margin-bottom: 20px; font-weight: 400;">
                <strong style="color: red;">ATENCION:</strong> Para la debida entrega de los productos como: lapiceros, marcadores, guantes, toallas cleanex, es necesario llevar el objeto vacio, de lo contrario no se les entregará. Gracias<br><br>
                <strong style="color: red;">Para un mejor control se les solicita hacer pedidos de insumos y suministros por separado, y cada personal hacer su propio pedido.</strong>
            </p>

            <form method="POST" id="formPedido">
                <div class="form-group">
                    <label for="solicitante" class="form-label">
                        <i class="fas fa-user"></i>
                        Solicitante:
                    </label>
                    <input 
                        list="listaEmpleados" 
                        id="solicitante" 
                        name="solicitante" 
                        class="form-control" 
                        required 
                        autocomplete="off" 
                        placeholder="Seleccione o escriba el nombre del solicitante"
                    >
                    <datalist id="listaEmpleados">
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= htmlspecialchars($emp) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <small id="error-solicitante" style="color: red; display: none;">El solicitante no existe en la base de datos</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-tags"></i>
                        Tipo de producto:
                    </label>
                    <div class="radio-group">
                        <div class="radio-option">
                            <input type="radio" id="insumos" name="tipo_producto" value="insumos" onchange="cambiarTipoProducto()" checked>
                            <label for="insumos">Insumos de laboratorio</label>
                        </div>
                        <div class="radio-option">
                            <input type="radio" id="suministros" name="tipo_producto" value="suministros" onchange="cambiarTipoProducto()">
                            <label for="suministros">Suministros de oficina</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="productoBusqueda" class="form-label">
                        <i class="fas fa-search"></i>
                        Buscar producto:
                    </label>
                    <div class="product-search">
                        <input 
                            type="text" 
                            id="productoBusqueda" 
                            class="form-control" 
                            placeholder="Escriba para buscar productos..." 
                            autocomplete="off"
                        >
                    </div>
                    <select id="producto" required class="form-control" style="margin-bottom: 15px;"></select>
                </div>
                
                <div class="form-group">
                    <label for="cantidad" class="form-label">
                        <i class="fas fa-calculator"></i>
                        Cantidad:
                    </label>
                    <input 
                        type="number" 
                        id="cantidad" 
                        class="form-control" 
                        min="1" 
                        placeholder="Ingrese la cantidad requerida"
                    >
                </div>
                
                <button type="button" class="btn btn-primary" onclick="agregarProducto()">
                    <i class="fas fa-plus"></i>
                    Agregar Producto
                </button>
                
                <div class="form-group" style="margin-top: 30px;">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th width="100">Cantidad</th>
                                        <th width="120">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaProductosBody">
                                    <tr id="empty-row">
                                        <td colspan="3" style="text-align: center; color: var(--gray);">No hay productos agregados</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="motivo" class="form-label">
                        <i class="fas fa-comment"></i>
                        Motivo de la solicitud:
                    </label>
                    <textarea 
                        id="motivo" 
                        name="motivo" 
                        class="form-control" 
                        rows="4" 
                        required 
                        placeholder="Describa el motivo del pedido..."
                    ></textarea>
                </div>
                
                <div class="hidden-inputs">
                    <input type="hidden" name="productos" id="productos_hidden" required>
                    <input type="hidden" name="cantidades" id="cantidades_hidden" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-file-pdf"></i>
                    Registrar y Generar Pedido
                </button>
            </form>
        </div>
    </div>

    <div class="support-buttons">
        <a href="https://wa.me/50672360749?text=Hola, tengo una consulta acerca de" 
           target="_blank" 
           class="support-btn whatsapp"
           title="Contactar soporte por WhatsApp">
            <i class="fab fa-whatsapp"></i>
            <span>Soporte WhatsApp</span>
        </a>
        
        <a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" 
           target="_blank" 
           class="support-btn odoo"
           title="Soporte en Odoo">
            <i class="fas fa-comments"></i>
            <span>Soporte Odoo</span>
        </a>
    </div>

    <footer class="footer">
        <div>
            <i class="fas fa-cogs"></i>
            Sistema de Control de Pedidos © <?= date("Y") ?>
        </div>
        <div class="developer">
            <i class="fas fa-code"></i>
            Desarrollado por: Nestor Rosales | Rosales_Dev91
        </div>
    </footer>

    <script>
        const insumos = <?= json_encode($insumos); ?>;
        const suministros = <?= json_encode($suministros); ?>;
        const empleados = <?= json_encode($empleados); ?>;
        
        let productos = insumos;
        let productosAgregados = [];
        
        const inputBusqueda = document.getElementById('productoBusqueda');
        const selectProducto = document.getElementById('producto');
        const tablaBody = document.getElementById('tablaProductosBody');
        const emptyRow = document.getElementById('empty-row');
        const inputSolicitante = document.getElementById('solicitante');
        const errorSolicitante = document.getElementById('error-solicitante');
        const formPedido = document.getElementById('formPedido');

        function validarSolicitante() {
            const nombre = inputSolicitante.value.trim();
            const existe = empleados.includes(nombre);
            
            if (nombre && !existe) {
                errorSolicitante.style.display = 'block';
                return false;
            } else {
                errorSolicitante.style.display = 'none';
                return true;
            }
        }

        inputSolicitante.addEventListener('change', validarSolicitante);
        inputSolicitante.addEventListener('input', function() {
            errorSolicitante.style.display = 'none';
        });

        formPedido.addEventListener('submit', function(e) {
            if (!validarSolicitante()) {
                e.preventDefault();
                alert('Por favor seleccione un solicitante válido de la lista');
                inputSolicitante.focus();
            }
        });

        function filtrarProductos() {
            const filtro = inputBusqueda.value.toLowerCase();
            selectProducto.innerHTML = '';
            
            const filtrados = productos.filter(p => p.producto.toLowerCase().includes(filtro));
            
            if (filtrados.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'No se encontraron productos';
                option.disabled = true;
                selectProducto.appendChild(option);
            } else {
                filtrados.forEach(prod => {
                    const option = document.createElement('option');
                    option.value = JSON.stringify(prod);
                    option.textContent = prod.producto;
                    selectProducto.appendChild(option);
                });
            }
        }
        
        function cambiarTipoProducto() {
            const tipoSeleccionado = document.querySelector('input[name="tipo_producto"]:checked').value;
            productos = (tipoSeleccionado === 'insumos') ? insumos : suministros;
            
            inputBusqueda.value = '';
            filtrarProductos();
        }
        
        function agregarProducto() {
            const select = document.getElementById('producto');
            const cantidad = parseInt(document.getElementById('cantidad').value);
            
            if (!select.value || select.value === '' || isNaN(cantidad)) {
                alert("Por favor seleccione un producto válido e ingrese una cantidad.");
                return;
            }
            
            if (cantidad < 1) {
                alert("La cantidad debe ser al menos 1.");
                return;
            }
            
            const producto = JSON.parse(select.value);
            
            const index = productosAgregados.findIndex(p => p.codigo === producto.codigo);
            
            if (index !== -1) {
                productosAgregados[index].cantidad += cantidad;
            } else {
                productosAgregados.push({
                    producto: producto.producto,
                    codigo: producto.codigo,
                    cantidad: cantidad
                });
            }
            
            actualizarTabla();
            document.getElementById('cantidad').value = '';
            inputBusqueda.value = '';
            filtrarProductos();
        }
        
        function actualizarTabla() {
            tablaBody.innerHTML = '';
            
            if (productosAgregados.length === 0) {
                tablaBody.appendChild(emptyRow);
            } else {
                productosAgregados.forEach((p, i) => {
                    const fila = document.createElement('tr');
                    fila.innerHTML = `
                        <td>${p.producto}</td>
                        <td>${p.cantidad}</td>
                        <td>
                            <button type="button" class="btn btn-danger" onclick="eliminarProducto(${i})" style="padding: 5px 10px; font-size: 14px;">
                                <i class="fas fa-trash-alt"></i> Eliminar
                            </button>
                        </td>
                    `;
                    tablaBody.appendChild(fila);
                });
            }
            
            document.getElementById('productos_hidden').value = JSON.stringify(
                productosAgregados.map(p => ({ producto: p.producto, codigo: p.codigo }))
            );
            document.getElementById('cantidades_hidden').value = JSON.stringify(
                productosAgregados.map(p => p.cantidad)
            );
        }
        
        function eliminarProducto(index) {
            productosAgregados.splice(index, 1);
            actualizarTabla();
        }
        
        function actualizarReloj() {
            const ahora = new Date();
            const dia = String(ahora.getDate()).padStart(2, '0');
            const mes = String(ahora.getMonth() + 1).padStart(2, '0');
            const anio = ahora.getFullYear();
            const horas = String(ahora.getHours()).padStart(2, '0');
            const minutos = String(ahora.getMinutes()).padStart(2, '0');
            const segundos = String(ahora.getSeconds()).padStart(2, '0');
            
            const fecha = `${dia}/${mes}/${anio}`;
            const tiempo = `${horas}:${minutos}:${segundos}`;
            document.getElementById('reloj').textContent = `${fecha} ${tiempo}`;
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            cambiarTipoProducto();
            setInterval(actualizarReloj, 1000);
            actualizarReloj();
            
            inputBusqueda.addEventListener('input', filtrarProductos);
            
            document.querySelector('form').addEventListener('submit', function() {
                setTimeout(() => {
                    document.getElementById('motivo').value = '';
                    document.getElementById('productos_hidden').value = '';
                    document.getElementById('cantidades_hidden').value = '';
                    productosAgregados = [];
                    actualizarTabla();
                    document.getElementById('cantidad').value = '';
                    inputBusqueda.value = '';
                    filtrarProductos();
                }, 100);
            });
        });
    </script>
</body>
</html>