<?php
session_start();

require_once dirname(__DIR__) . '/config/database.php';

// Verificación de acceso
if (
    !isset($_SESSION['nombre_empleado']) ||
    $_SESSION['rol'] !== 'empleado' ||
    !isset($_GET['session_id']) ||
    $_GET['session_id'] !== $_SESSION['session_id']
) {
    // Si no coincide, redirige al login
    header("Location: login_registro_quiebras.php");
    exit();
}

// Verificación del token CSRF
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Token CSRF inválido.");
    }
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function echoOptions($result, $valueField, $textField, $emptyMessage) {
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Modificar para usar el texto del campo 'textField' como valor
            echo '<option value="' . htmlspecialchars($row[$textField]) . '">' . htmlspecialchars($row[$textField]) . '</option>';
        }
    } else {
        echo '<option value="">' . $emptyMessage . '</option>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Quiebras</title>
</head> 

    <style>
        body {
            background: #155724;
            color: white;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-bottom: 80px;
        }

        .container {
            max-width: 700px;
            margin: 40px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            box-sizing: border-box;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 24px;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
            color: #444;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="time"],
        select,
        textarea {
            width: 100%;
            padding: 12px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            margin-bottom: 15px;
            font-size: 16px;
        }

        input[type="submit"] {
            background-color: #1976d2;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 20px;
            width: 100%;
        }

        input[type="submit"]:hover {
            background-color: #125ea2;
        }

        /* NUEVO: Contenedor de columnas */
        .form-columns {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .form-column {
            flex: 1 1 300px; /* Crece, encoge, base 300px */
            min-width: 280px;
        }

        /* Responsive para móviles: columna única */
        @media (max-width: 768px) {
            .form-columns {
                flex-direction: column;
                gap: 0;
            }
            .form-column {
                min-width: 100%;
            }
        }

        /* Resto de estilos ya definidos... */
        .logo-container {
            text-align: right;
            padding: 10px 20px 0 0;
        }

        .logo-container img {
            height: 100px;
        }

        .logout-link {
            display: inline-block;
            margin-top: 10px;
            color: white;
            text-decoration: underline;
            font-weight: bold;
            margin-right: 20px;
        }

        .logout-link:hover {
            color: #8fdc8b;
        }

        .reloj-container {
            width: 100%;
            text-align: center;
            font-size: 1.2rem;
            color: white;
            font-weight: bold;
            margin-top: 40px;
        }

        .reloj-container #reloj {
            font-size: 2.5rem;
            margin-top: 5px;
        }

        .firma {
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            color: #d4fcd4;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 15px 0;
            text-align: center;
            font-size: 15px;
            box-sizing: border-box;
            z-index: 9999;
        }

        input[type="number"],
textarea {
  color: black;        /* Color de texto negro */
  background-color: white; /* Fondo blanco */
  font-size: 16px;     /* Tamaño legible */
  padding: 5px;
  border: 1px solid #ccc;
  border-radius: 4px;
}

label, legend, strong {
  color: black; /* Color negro para los textos */
  font-family: Arial, sans-serif;
}

    </style>

</head>   

<body>

<div class="logo-container">
    <img src="/control_produccion/public/logo.png" alt="Logo">
</div>

<div class="container">
    <h2>Bienvenid@, <?php echo htmlspecialchars($_SESSION['nombre_empleado']); ?> | 
        <a href="login_registro_quiebras.php">Cerrar sesión</a> </h2>

    <h2>Registro de Rectificaciones</h2>

    <!-- Formulario único para registrar y buscar quiebras -->
    <form method="POST" action="guardar_quiebras.php">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"> 
     
    <label for="numero_orden">Número de Orden:</label>
    <input type="text" name="orden" id="orden">

<?php
// Establecer zona horaria
date_default_timezone_set('America/Guatemala');

// Fecha actual en formato YYYY-MM-DD para el input
$fecha_actual = date('Y-m-d');

// Si se envió la fecha por POST, usarla; si no, usar la fecha actual
$fecha_valor = $_POST['fecha'] ?? $fecha_actual;
?>

<!-- Campo de Fecha -->
<label for="fecha">Fecha:</label>
<input type="date" id="fecha" name="fecha" value="<?= htmlspecialchars($fecha_valor) ?>" readonly>

<!-- Campo visible para la Hora -->
<label for="hora_visible">Hora:</label>
<input type="text" id="hora_visible" readonly>

<!-- Campo oculto que se enviará al formulario con la hora en formato 24h -->
<input type="hidden" id="hora" name="hora">

<script>
// Actualiza los campos de hora cada segundo
function actualizarHora() {
    const ahora = new Date();

    // Hora en formato 24h para enviar al servidor
    const horas24 = String(ahora.getHours()).padStart(2, '0');
    const minutos = String(ahora.getMinutes()).padStart(2, '0');
    const segundos = String(ahora.getSeconds()).padStart(2, '0');
    const hora24 = `${horas24}:${minutos}:${segundos}`;
    document.getElementById('hora').value = hora24;

    // Hora en formato 12h para mostrar al usuario
    let horas12 = ahora.getHours() % 12 || 12;
    const ampm = ahora.getHours() >= 12 ? 'PM' : 'AM';
    const horaVisible = `${String(horas12).padStart(2, '0')}:${minutos}:${segundos} ${ampm}`;
    document.getElementById('hora_visible').value = horaVisible;
}

// Ejecutar al cargar la página y cada segundo
document.addEventListener('DOMContentLoaded', () => {
    actualizarHora();
    setInterval(actualizarHora, 1000);
});
</script>

   <?php
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'produccion_quiebras'; // Base de datos por defecto

// Crear conexión
$conn = new mysqli($host, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    error_log("Error en la conexión a la base de datos: " . $conn->connect_error);
    die("No se pudo conectar a la base de datos.");
}
?>

 <div class="form-columns">
            <!-- Columna 1 -->
            <div class="form-column">

<!-- Turno -->
<label for="turno">Turno:</label>
<select name="turno" id="turno" required>
    <option value="">-- Seleccione un turno --</option>
    <?php
    $resultTurnos = $conn->query("SELECT id, turno FROM turnos");
    while ($row = $resultTurnos->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['turno']) . '">' . htmlspecialchars($row['turno']) . '</option>';
    }
    ?>
</select>

<!-- Responsable (tipo) -->
<label for="responsable">Responsable (Tipo):</label>
<select name="responsable" id="responsable" required>
    <option value="">-- Seleccione un responsable (Tipo) --</option>
    <?php
    $resultResponsables = $conn->query("SELECT id, nombre FROM responsables");
    while ($row = $resultResponsables->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['nombre']) . '">' . htmlspecialchars($row['nombre']) . '</option>';
    }
    ?>
</select>

<!-- Nombre de Responsable (Empleado) -->
<label for="empleado">Nombre del Responsable (Empleado):</label>
<select name="empleado" id="empleado" required>
    <option value="">-- Seleccione el nombre del responsable --</option>
    <?php
    $resultEmpleados = $conn->query("SELECT id, nombre_empleado FROM empleados");
    while ($row = $resultEmpleados->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['nombre_empleado']) . '">' . htmlspecialchars($row['nombre_empleado']) . '</option>';
    }
    ?>
</select>

<!-- Equipo -->
<label for="equipo">Equipo:</label>
<select name="equipo" id="equipo" required>
    <option value="">-- Seleccione un equipo --</option>
    <?php
    $resultEquipos = $conn->query("SELECT id, nombre_equipo FROM equipos");
    while ($row = $resultEquipos->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['nombre_equipo']) . '">' . htmlspecialchars($row['nombre_equipo']) . '</option>';
    }
    ?>
</select>

<script>
    document.getElementById('responsable').addEventListener('change', function () {
        const valor = this.value.toLowerCase(); // Convertimos a minúsculas para facilitar la comparación
        const empleado = document.getElementById('empleado');
        const equipo = document.getElementById('equipo');

        // Reiniciar todos a habilitado
        empleado.disabled = false;
        equipo.disabled = false;

        // Aplicar condiciones
        if (valor.includes('persona')) {
            empleado.disabled = false;
            equipo.disabled = true;
            equipo.value = '';
        } else if (valor.includes('equipo')) {
            empleado.disabled = true;
            equipo.disabled = false;
            empleado.value = '';
        } else if (valor.includes('material') || valor.includes('sucursal')) {
            empleado.disabled = true;
            equipo.disabled = true;
            empleado.value = '';
            equipo.value = '';
        }
    });
</script>

<!-- Área -->
<label for="area">Área donde se detecta:</label>
<select name="area" id="area" required>
    <option value="">-- Seleccione un área --</option>
    <?php
    $resultAreas = $conn->query("SELECT id, area FROM areas");
    while ($row = $resultAreas->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['area']) . '">' . htmlspecialchars($row['area']) . '</option>';
    }
    ?>
</select>

<!-- Motivo -->
<label for="motivo">Motivo:</label>
<select name="motivo" id="motivo" required>
    <option value="">-- Seleccione un motivo --</option>
    <?php
    $resultMotivos = $conn->query("SELECT id, motivo FROM motivos");
    while ($row = $resultMotivos->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['motivo']) . '">' . htmlspecialchars($row['motivo']) . '</option>';
    }
    ?>
</select>

<!-- Por qué del Defecto -->
<label for="porque_defecto">¿Por qué del Defecto? (raiz)</label>
<select name="porque_defecto" id="porque_defecto" required>
    <option value="">-- Seleccione una causa --</option>
    <?php
    $resultPorques = $conn->query("SELECT id, descripcion FROM porque_defecto");
    while ($row = $resultPorques->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['descripcion']) . '">' . htmlspecialchars($row['descripcion']) . '</option>';
    }
    ?>
</select>

</div>

 <!-- Columna 2 -->
 <div class="form-column">

<!-- Tipo de Lente -->
<label for="tipo_lente">Tipo de Lente:</label>
<select name="tipo_lente" id="tipo_lente" required>
    <option value="">-- Seleccione un tipo de lente --</option>
    <?php
    $resultTiposLente = $conn->query("SELECT id, tipo_lente FROM tipos_lente");
    while ($row = $resultTiposLente->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['tipo_lente']) . '">' . htmlspecialchars($row['tipo_lente']) . '</option>';
    }
    ?>
</select>

<!-- Lado del Lente -->
<label for="lado_lente">Lado del Lente:</label>
<select name="lado_lente" id="lado_lente" required>
    <option value="">-- Seleccione el lado del lente --</option>
    <?php
    $resultLadosLente = $conn->query("SELECT id, lado FROM lados_lente");
    while ($row = $resultLadosLente->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['lado']) . '">' . htmlspecialchars($row['lado']) . '</option>';
    }
    ?>
</select>

<!-- Tipo de Montaje -->
<label for="tipo_montaje">Tipo de Montaje:</label>
<select name="tipo_montaje" id="tipo_montaje" required>
    <option value="">-- Seleccione el tipo de montaje --</option>
    <?php
    $resultTiposMontaje = $conn->query("SELECT id, montaje FROM tipos_montaje");
    while ($row = $resultTiposMontaje->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['montaje']) . '">' . htmlspecialchars($row['montaje']) . '</option>';
    }
    ?>
</select>

<!-- Tipo de Visión -->
<label for="tipo_vision">Tipo de Visión:</label>
<select name="tipo_vision" id="tipo_vision" required>
    <option value="">-- Seleccione el tipo de visión --</option>
    <?php
    $resultTiposVision = $conn->query("SELECT id, tipo_vision FROM tipos_vision");
    while ($row = $resultTiposVision->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['tipo_vision']) . '">' . htmlspecialchars($row['tipo_vision']) . '</option>';
    }
    ?>
</select>

<!-- Input de búsqueda -->
<input type="text" id="buscar-material" placeholder="Buscar material..." onkeyup="filtrarYCompletarMaterial()" autocomplete="off" style="margin-bottom:8px;" />

<!-- Select original sin cambios -->
<label for="material">Material:</label>
<select name="material" id="material" required>
    <option value="">-- Seleccione el material --</option>
    <?php
    $resultMateriales = $conn->query("SELECT id, material FROM materiales");
    while ($row = $resultMateriales->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['material']) . '">' . htmlspecialchars($row['material']) . '</option>';
    }
    ?>
</select>

<script>
function filtrarYCompletarMaterial() {
    var input = document.getElementById("buscar-material");
    var filter = input.value.toLowerCase();
    var select = document.getElementById("material");
    var options = select.getElementsByTagName("option");
    var firstMatch = null;

    for (var i = 1; i < options.length; i++) { // omitimos la primera opción
        var txt = options[i].text.toLowerCase();
        if (txt.includes(filter)) {
            options[i].style.display = "";  // mantiene visible
            if (!firstMatch) {
                firstMatch = options[i].text;
            }
        } else {
            options[i].style.display = "none"; // oculta opciones que no coinciden
        }
    }

    // Completar el input con la primera opción que coincide
    if (firstMatch && filter.length > 0) {
        // Guardamos la posición para seleccionar solo la parte autocompletada
        input.value = firstMatch;
        input.setSelectionRange(filter.length, firstMatch.length);

        // Seleccionar opción en el select
        for (var i = 1; i < options.length; i++) {
            if (options[i].text === firstMatch) {
                select.selectedIndex = i;
                break;
            }
        }
    } else {
        // Si no hay coincidencias, quitar selección
        select.selectedIndex = 0;
    }
}
</script>

<!-- Tratamiento -->
<label for="tratamiento">Tratamiento:</label>
<select name="tratamiento" id="tratamiento" required>
    <option value="">-- Seleccione el tratamiento del lente --</option>
    <?php
    $resultTratamientos = $conn->query("SELECT id, tratamiento FROM tratamientos");
    while ($row = $resultTratamientos->fetch_assoc()) {
        echo '<option value="' . htmlspecialchars($row['tratamiento']) . '">' . htmlspecialchars($row['tratamiento']) . '</option>';
    }
    ?>
</select>

</div>
<!-- Graduación -->
<fieldset style="border: 2px solidrgb(16, 129, 31); padding: 15px; border-radius: 8px; background-color: #f9f9f9;">
    <legend style="font-weight: bold; color:rgb(12, 161, 70);">Graduación</legend>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color:rgb(2, 134, 2);">
                <th style="padding: 8px; text-align: left;"></th>
                <th style="padding: 8px; text-align: center;">Esfera</th>
                <th style="padding: 8px; text-align: center;">Cilindro</th>
                <th style="padding: 8px; text-align: center;">Adición</th>
                <th style="padding: 8px; text-align: center;">Base</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding: 8px;"><strong>OD</strong></td>
                <td><input type="number" step="0.25" name="esfera_od" style="width: 100%; padding: 5px;"></td>
                <td><input type="number" step="0.25" name="cilindro_od" style="width: 100%; padding: 5px;"></td>
                <td><input type="number" step="0.25" name="adicion_od" style="width: 100%; padding: 5px;"></td>
                <td><input type="number" step="0.25" name="base_od" style="width: 100%; padding: 5px;"></td>
            </tr>
            <tr>
                <td style="padding: 8px;"><strong>OI</strong></td>
                <td><input type="number" step="0.25" name="esfera_oi" style="width: 100%; padding: 5px;"></td>
                <td><input type="number" step="0.25" name="cilindro_oi" style="width: 100%; padding: 5px;"></td>
                <td><input type="number" step="0.25" name="adicion_oi" style="width: 100%; padding: 5px;"></td>
                <td><input type="number" step="0.25" name="base_oi" style="width: 100%; padding: 5px;"></td>
            </tr>
        </tbody>
    </table>
</fieldset>


<form id="form_quiebra" method="post" action="guardar_quiebras.php">
    <label for="comentarios_pdf"><strong>Comentario de la quiebra (Solucion):</strong></label><br>
    <textarea name="comentarios_pdf" id="comentarios_pdf" rows="5" cols="60" 
              placeholder="Ingrese un comentario." required></textarea><br><br>
<input type="submit" name="registrar_quiebra" value="Registrar quiebra">

<script>
  document.querySelector('form').addEventListener('submit', function (event) {
    const form = this;

    // Si quieres prevenir el envío para procesar solo con JS, descomenta:
    // event.preventDefault();

    // Limpia el formulario 1 segundo después del submit para asegurar que se envió
    setTimeout(() => {
      form.reset();
    }, 1000);
  });
</script>


<div class="firma" style="color: white; margin-top: 20px;">
    Sistema de Registro de Rectificaciones | © <?= date("Y"); ?>
    <p>By: Nestor Rosales | Rosales_Dev91</p> 
</div>


<?php
if (isset($conn_produccion_quiebras) && $conn_produccion_quiebras instanceof mysqli) {
    $conn_produccion_quiebras->close();
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>

<script>
// Evitar envío del formulario con Enter
document.querySelector('form').addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
    }
});
</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>

<a href="https://wa.me/50672360749?text=Hola, tengo una consulta acerca de" target="_blank" class="whatsapp-btn">
  <i class="bi bi-whatsapp"></i>
  <span class="whatsapp-text">Soporte</span>
</a>

<style>
/* Estilos generales del botón de WhatsApp */
.whatsapp-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 9999;
  background-color: #25D366;
  padding: 10px 16px;
  border-radius: 30px;
  display: flex;
  align-items: center;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
  animation: breathe 2s ease-in-out infinite;
  text-decoration: none;
  color: white;
  font-weight: bold;
}

/* Ícono */
.whatsapp-btn i {
  font-size: 24px;
  margin-right: 8px;
  animation: beat 2s ease-in-out infinite;
}

/* Texto al lado del icono */
.whatsapp-text {
  font-size: 16px;
}

/* Animación de contorno respirando */
@keyframes breathe {
  0% {
    box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.5);
  }
  70% {
    box-shadow: 0 0 0 15px rgba(37, 211, 102, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(0, 0, 0, 0);
  }
}

/* Animación de latido */
@keyframes beat {
  0% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.2);
  }
  100% {
    transform: scale(1);
  }
}
</style>

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>

<!-- Estilos opcionales -->
<style>
.s_social_media a {
  margin-right: 10px;
  font-size: 24px;
  color: #fff;
  background-color: #343a40;
  padding: 10px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  transition: transform 0.2s ease-in-out, background-color 0.2s;
}

.s_social_media a:hover {
  transform: scale(1.15);
  background-color: #495057;
}

.s_social_media {
  padding: 20px 0;
}
</style>

<!-- Estilo opcional para el botón -->
<style>
.odoo-message-btn {
  display: inline-block;
  background-color: #007bff;
  color: white;
  padding: 12px 20px;
  border-radius: 6px;
  text-decoration: none;
  font-weight: 600;
  transition: background-color 0.3s ease;
  box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.odoo-message-btn:hover {
  background-color: #0056b3;
}
</style>

<!-- Botón de mensaje -->
<a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" target="_blank" class="odoo-message-btn">
  💬 Soporte al usuario Odoo
</a>

</body>
</html>
