<?php
session_start();
require_once '../config/database.php';

// Guardamos el nombre original por si alguien está logueado
$nombre_original = $_SESSION['nombre_empleado']['nombre_empleado'] ?? null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imprimir Etiqueta Térmica</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body{margin:0;padding:0;font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#003300,#006400,#008000);height:100vh;display:flex;align-items:center;justify-content:center;}
        .box{background:#fff;color:#003300;padding:50px 40px;border-radius:20px;box-shadow:0 20px 50px rgba(0,0,0,.6);text-align:center;width:90%;max-width:500px;}
        h1{color:#006400;margin:0 0 10px;}
        .icon{font-size:80px;color:#28a745;margin-bottom:20px;animation:pulse 1.5s infinite;}
        input[type=text]{width:100%;padding:18px;font-size:26px;text-align:center;border:3px solid #28a745;border-radius:15px;margin:20px 0;}
        input:focus{outline:none;border-color:#006400;box-shadow:0 0 0 5px rgba(40,167,69,.3);}
        .btn{background:linear-gradient(135deg,#28a745,#20c997);color:white;padding:20px;font-size:24px;font-weight:bold;border:none;border-radius:15px;width:100%;cursor:pointer;}
        .btn:hover{transform:scale(1.05);box-shadow:0 10px 30px rgba(40,167,69,.5);}
        .msg{margin:20px 0;padding:18px;border-radius:12px;font-weight:bold;font-size:19px;}
        .ok{background:#d4edda;color:#155724;}
        .no{background:#f8d7da;color:#721c24;}
        @keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}
    </style>
</head>
<body>

<div class="box">
    <div class="icon">Print Icon</div>
    <h1>Imprimir Etiqueta</h1>
    <p style="color:#006400;font-size:19px;margin:10px 0 35px;">Quiebras de Lentes</p>

    <?php
    if (!empty($_GET['orden'] ?? '')) {
        $orden = trim($_GET['orden']);

        // BUSCAMOS EL NOMBRE REAL QUE REGISTRÓ LA QUIEBRA
        $stmt = $conn->prepare("SELECT empleado_registro FROM registro_quiebras WHERE orden = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $orden);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $nombre_real = $row['empleado_registro'];

            echo '<div class="msg ok">
                    Check Icon ¡Orden encontrada!<br>
                    <strong>'.$orden.'</strong><br>
                    Registrada por: <strong>'.$nombre_real.'</strong>
                  </div>';

            // TRUCO MÁGICO: cambiamos temporalmente la sesión
            $_SESSION['nombre_empleado'] = $nombre_real;

            // Abrimos el PDF (él va a leer el nombre de la sesión → sale el correcto)
            echo "<script>
                const pdfWindow = window.open('reporte_simplificado_pdf.php?orden=".urlencode($orden)."', '_blank');
                
                // Restauramos el nombre original después de 2 segundos (por si acaso)
                setTimeout(() => {
                    fetch('restaurar_sesion.php?nombre=".urlencode($nombre_original ?? '')."');
                    location.href = 'imprimir_etiqueta.php';
                }, 2000);
            </script>";
            exit;
        } else {
            echo '<div class="msg no">
                    Cross Icon No hay quiebra registrada para la orden <strong>'.$orden.'</strong>
                  </div>';
        }
        $stmt->close();
    }
    ?>

    <form method="GET">
        <input type="text" name="orden" placeholder="Ej: JIM00378287" required autofocus autocomplete="off">
        <button type="submit" class="btn">Print Icon IMPRIMIR ETIQUETA</button>
    </form>
</div>

</body>
</html>