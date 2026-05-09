<?php
session_start();
require_once '../config/database.php';
require_once 'auto_audit_empleados.php';
require_once 'registrar_actividad.php';

$conn->set_charset("utf8");
date_default_timezone_set('America/Costa_Rica');

$mensaje = $tipo_mensaje = '';
$codigoEmpleado = $_SESSION['codigoEmpleado'] ?? '';
$nombreEmpleado = $_SESSION['nombreEmpleado'] ?? '';

// Cambiar empleado
if (isset($_POST['cambiar_empleado'])) {
    session_unset();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Buscar empleado
    if (isset($_POST['consultar_empleado'])) {
        $codigo = trim($_POST['codigo_empleado'] ?? '');
        if ($codigo) {
            $stmt = $conn->prepare("SELECT nombre_empleado FROM empleados WHERE codigo_empleado = ?");
            $stmt->bind_param("s", $codigo);
            if ($stmt->execute() && $stmt->bind_result($nombre) && $stmt->fetch()) {
                $_SESSION['codigoEmpleado'] = $codigo;
                $_SESSION['nombreEmpleado'] = $nombre;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
            $mensaje = "Empleado no encontrado";
            $tipo_mensaje = 'error';
            $stmt->close();
        }
    }

    // Registrar marca
    if (isset($_POST['registrar_marca'])) {
        $tipo = $_POST['tipo_marca'] ?? '';
        $fecha_hora = date('Y-m-d H:i:s');

        // Validar última marca para evitar duplicados consecutivos
        $stmt = $conn->prepare("SELECT tipo_marca FROM asistencia WHERE codigo_empleado = ? AND DATE(fecha_hora) = CURDATE() ORDER BY fecha_hora DESC LIMIT 1");
        $stmt->bind_param("s", $codigoEmpleado);
        $stmt->execute();
        $stmt->bind_result($ultima_marca);
        $ultima_existe = $stmt->fetch();
        $stmt->close();

        // Validación de secuencia lógica (sin cafe3)
        $marcas_validas = [
            'cafe1_salida' => !$ultima_existe || in_array($ultima_marca, ['cafe1_entrada', 'comida_entrada', 'cafe2_entrada']),
            'cafe1_entrada' => $ultima_marca === 'cafe1_salida',
            'comida_salida' => !$ultima_existe || in_array($ultima_marca, ['cafe1_entrada', 'comida_entrada', 'cafe2_entrada']),
            'comida_entrada' => $ultima_marca === 'comida_salida',
            'cafe2_salida' => !$ultima_existe || in_array($ultima_marca, ['cafe1_entrada', 'comida_entrada', 'cafe2_entrada']),
            'cafe2_entrada' => $ultima_marca === 'cafe2_salida'
        ];

        if (isset($marcas_validas[$tipo]) && $marcas_validas[$tipo]) {
            $stmt = $conn->prepare("INSERT INTO asistencia (codigo_empleado, nombre_empleado, tipo_marca, fecha_hora) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $codigoEmpleado, $nombreEmpleado, $tipo, $fecha_hora);
            if ($stmt->execute()) {
                $mensaje = "Marca registrada: " . ucwords(str_replace('_', ' ', $tipo));
                $tipo_mensaje = 'exito';
            } else {
                $mensaje = "Error al registrar marca";
                $tipo_mensaje = 'error';
            }
            $stmt->close();
        } else {
            $mensaje = "Secuencia de marca inválida";
            $tipo_mensaje = 'error';
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Obtener marcas del día
$marcas_hoy = [];
$proxima_marca = 'cafe1_salida'; // Por defecto empezar con café 1

if ($nombreEmpleado) {
    $stmt = $conn->prepare("SELECT tipo_marca, fecha_hora FROM asistencia WHERE codigo_empleado = ? AND DATE(fecha_hora) = CURDATE() ORDER BY fecha_hora ASC");
    $stmt->bind_param("s", $codigoEmpleado);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $marcas_hoy[] = $row;
    }
    $stmt->close();

    // Determinar próxima marca válida basada en la última (sin cafe3)
    if (!empty($marcas_hoy)) {
        $ultima = end($marcas_hoy)['tipo_marca'];
        $secuencia = [
            'cafe1_salida' => 'cafe1_entrada',
            'cafe1_entrada' => 'comida_salida', // Después del café 1 puede ir comida
            'comida_salida' => 'comida_entrada',
            'comida_entrada' => 'cafe2_salida', // Después de comida puede ir café 2
            'cafe2_salida' => 'cafe2_entrada',
            'cafe2_entrada' => 'completo' // Máximo 2 cafés + comida
        ];
        $proxima_marca = $secuencia[$ultima] ?? 'completo';
    }
}

// Historial (últimos 7 días)
$historial = [];
if ($nombreEmpleado) {
    $stmt = $conn->prepare("
        SELECT DATE(fecha_hora) as fecha,
               GROUP_CONCAT(CONCAT(tipo_marca, ':', TIME_FORMAT(fecha_hora, '%H:%i')) ORDER BY fecha_hora SEPARATOR '|') as marcas
        FROM asistencia 
        WHERE codigo_empleado = ? AND fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(fecha_hora)
        ORDER BY fecha DESC
    ");
    $stmt->bind_param("s", $codigoEmpleado);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $historial[] = $row;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Asistencia - Pausas</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e7e34;
            --primary-light: #28a745;
            --primary-dark: #155724;
            --secondary-color: #6c757d;
            --danger-color: #dc3545;
            --success-bg: #d4edda;
            --success-text: #155724;
            --error-bg: #f8d7da;
            --error-text: #721c24;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --text-dark: #333333;
            --text-light: #6c757d;
            --border-radius: 12px;
            --box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .header {
            background: rgba(0, 51, 0, 0.95);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            color: var(--white);
        }

        .logo {
            height: 50px;
        }

        .clock {
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 0 20px;
            flex: 1;
            width: 100%;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
            color: var(--text-dark);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .alert-exito {
            background: var(--success-bg);
            color: var(--success-text);
            border-left: 4px solid var(--primary-light);
        }

        .alert-error {
            background: var(--error-bg);
            color: var(--error-text);
            border-left: 4px solid var(--danger-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
            color: var(--white);
            font-size: 16px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary-color));
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .btn-secondary {
            background: var(--secondary-color);
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: var(--danger-color);
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-large {
            font-size: 18px;
            padding: 20px 40px;
            width: 100%;
        }

        .estado-empleado {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 20px;
            border-radius: var(--border-radius);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-light);
        }

        .marcas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .marca-item {
            padding: 15px;
            background: var(--light-bg);
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid var(--primary-light);
            transition: var(--transition);
        }

        .marca-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .marca-tipo {
            font-weight: 600;
            color: var(--primary-light);
            margin-bottom: 5px;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }

        .marca-hora {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .marca-pendiente {
            opacity: 0.6;
            border-left-color: #ddd;
        }

        .historial-item {
            padding: 15px;
            background: var(--light-bg);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: var(--transition);
        }

        .historial-item:hover {
            background: #e9ecef;
        }

        .historial-fecha {
            font-weight: 600;
            color: var(--primary-light);
            margin-bottom: 10px;
            font-size: 18px;
        }

        .historial-marcas {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .historial-marca {
            background: var(--white);
            padding: 8px 12px;
            border-radius: 5px;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .footer {
            background: rgba(0, 51, 0, 0.95);
            padding: 15px;
            text-align: center;
            margin-top: auto;
            color: var(--white);
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .marcas-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .estado-empleado {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .container {
                padding: 0 15px;
            }
        }

        @media (max-width: 480px) {
            .marcas-grid {
                grid-template-columns: 1fr;
            }
            
            .historial-marcas {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <img src="/control_produccion/public/logo.png" alt="Logo" class="logo">
        <div class="clock" id="reloj"></div>
        <?php if ($nombreEmpleado): ?>
            <form method="POST" style="display:inline;">
                <button type="submit" name="cambiar_empleado" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </button>
            </form>
        <?php endif; ?>
    </header>

    <div class="container">
        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $tipo_mensaje === 'exito' ? 'exito' : 'error' ?>">
                <i class="fas fa-<?= $tipo_mensaje === 'exito' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if (!$nombreEmpleado): ?>
            <div class="card">
                <h2 style="text-align: center; margin-bottom: 20px;">
                    <i class="fas fa-fingerprint"></i> Identificarse
                </h2>
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Código de Empleado</label>
                        <input type="text" name="codigo_empleado" class="form-control" 
                               placeholder="Ingrese su código" required autofocus>
                    </div>
                    <button type="submit" name="consultar_empleado" class="btn btn-primary btn-large">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="estado-empleado">
                <div>
                    <h3>Bienvenido/a, <?= htmlspecialchars($nombreEmpleado) ?></h3>
                    <p style="color: #666; margin-top: 5px;">Código: <?= $codigoEmpleado ?></p>
                </div>
                <div style="text-align: right;">
                    <h4>Marcas Hoy</h4>
                    <p style="font-size: 24px; font-weight: 700; color: var(--primary-light);">
                        <?= count($marcas_hoy) ?>/6
                    </p>
                </div>
            </div>

            <?php if ($proxima_marca !== 'completo'): ?>
                <div class="card">
                    <h3 style="text-align: center; margin-bottom: 20px;">
                        <i class="fas fa-fingerprint"></i> Registrar Pausa
                    </h3>
                    <form method="POST">
                        <input type="hidden" name="tipo_marca" value="<?= $proxima_marca ?>">
                        <button type="submit" name="registrar_marca" class="btn btn-primary btn-large">
                            <i class="fas fa-fingerprint"></i>
                            <?= strtoupper(str_replace('_', ' ', $proxima_marca)) ?>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card" style="text-align: center; background: linear-gradient(135deg, #e8f5e9, #c8e6c9);">
                    <h3 style="color: var(--primary-light); margin-bottom: 10px;">
                        <i class="fas fa-check-circle"></i> ¡Completado!
                    </h3>
                    <p style="color: #666; font-size: 16px;">
                        Límite de pausas alcanzado. ¡Buen trabajo!
                    </p>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3 style="margin-bottom: 15px;">
                    <i class="fas fa-history"></i> Pausas de Hoy
                </h3>
                <div class="marcas-grid">
                    <?php
                    $todas_marcas = ['cafe1_salida', 'cafe1_entrada', 'comida_salida', 'comida_entrada', 'cafe2_salida', 'cafe2_entrada'];
                    $iconos = [
                        'cafe1_salida' => 'coffee',
                        'cafe1_entrada' => 'coffee',
                        'comida_salida' => 'utensils',
                        'comida_entrada' => 'utensils',
                        'cafe2_salida' => 'coffee',
                        'cafe2_entrada' => 'coffee'
                    ];
                    $nombres_amigables = [
                        'cafe1_salida' => 'Café 1 Salida',
                        'cafe1_entrada' => 'Café 1 Entrada',
                        'comida_salida' => 'Comida Salida',
                        'comida_entrada' => 'Comida Entrada',
                        'cafe2_salida' => 'Café 2 Salida',
                        'cafe2_entrada' => 'Café 2 Entrada'
                    ];
                    
                    $marcas_realizadas = array_column($marcas_hoy, 'tipo_marca');
                    foreach ($todas_marcas as $marca):
                        $idx = array_search($marca, $marcas_realizadas);
                        $realizada = $idx !== false;
                    ?>
                        <div class="marca-item <?= !$realizada ? 'marca-pendiente' : '' ?>">
                            <div class="marca-tipo">
                                <i class="fas fa-<?= $iconos[$marca] ?>"></i>
                                <?= $nombres_amigables[$marca] ?>
                            </div>
                            <div class="marca-hora">
                                <?= $realizada ? date('H:i', strtotime($marcas_hoy[$idx]['fecha_hora'])) : '--:--' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($historial)): ?>
                <div class="card">
                    <h3 style="margin-bottom: 15px;">
                        <i class="fas fa-calendar-alt"></i> Últimos 7 Días
                    </h3>
                    <?php foreach ($historial as $dia): ?>
                        <div class="historial-item">
                            <div class="historial-fecha">
                                <?= date('d/m/Y', strtotime($dia['fecha'])) ?>
                            </div>
                            <div class="historial-marcas">
                                <?php
                                $marcas_dia = explode('|', $dia['marcas']);
                                foreach ($marcas_dia as $marca):
                                    list($tipo, $hora) = explode(':', $marca);
                                ?>
                                    <div class="historial-marca">
                                        <i class="fas fa-<?= $iconos[$tipo] ?>"></i>
                                        <?= $nombres_amigables[$tipo] ?>: <?= $hora ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <p>Sistema de Asistencia - Pausas © <?= date('Y') ?></p>
        <small>Desarrollado por: Nestor Rosales | Rosales_Dev91</small>
    </footer>

    <script>
        function actualizarReloj() {
            const dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
            const ahora = new Date();
            const dia = dias[ahora.getDay()];
            const hora = ahora.getHours().toString().padStart(2, '0');
            const min = ahora.getMinutes().toString().padStart(2, '0');
            const seg = ahora.getSeconds().toString().padStart(2, '0');
            document.getElementById('reloj').textContent = `${dia} ${hora}:${min}:${seg}`;
        }
        
        setInterval(actualizarReloj, 1000);
        actualizarReloj();
    </script>

<!-- Tracking de navegación para monitor en vivo -->
<script>
(function() {
    const pagina = window.location.pathname.split('/').pop().replace('.php', '');
    fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            modulo: pagina, 
            pagina: window.location.pathname 
        })
    }).catch(err => console.log('Tracking error:', err));
})();
</script>
</body>
</html>

<?php $conn->close(); ?>