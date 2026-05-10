<?php
// Iniciar la sesión para verificar autenticación
session_start();

// Validar si el usuario es administrador
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_admin.php");
    exit();
}

// Incluir configuración de la base de datos
require_once dirname(__DIR__) . '/config/database.php';
require_once 'registrar_actividad.php';

// Establecer zona horaria
date_default_timezone_set('America/Guatemala');

// Conectar a la base de datos
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Inicializar mensajes de feedback
$mensaje_error = '';
$mensaje_exito = '';

// Lista blanca de tablas válidas para prevenir inyecciones SQL
$tablas_permitidas = ['areas', 'equipos', 'motivos', 'porque_defecto', 'responsables'];

// =============================================
// ELIMINAR REGISTRO - PROCESADO AL INICIO
// =============================================
if (isset($_GET['eliminar']) && isset($_GET['id']) && (isset($_GET['tab']) || isset($_GET['tabla']))) {
    $tabla = isset($_GET['tab']) ? $_GET['tab'] : $_GET['tabla'];
    $id = $_GET['id'];

    // Validar tabla y ID
    if (!in_array($tabla, $tablas_permitidas)) {
        $_SESSION['error_eliminacion'] = "Tabla no válida.";
    } elseif (!is_numeric($id)) {
        $_SESSION['error_eliminacion'] = "ID no válido.";
    } else {
        try {
            // Verificar dependencias para todas las tablas
            $dependencias = [
                'areas' => ['equipos' => 'area_id'],
                'motivos' => ['porque_defecto' => 'motivo_id'],
                'equipos' => [],
                'responsables' => [],
                'porque_defecto' => []
            ];

            if (isset($dependencias[$tabla]) && !empty($dependencias[$tabla])) {
                foreach ($dependencias[$tabla] as $tabla_dependiente => $columna) {
                    $check = $conn->prepare("SELECT COUNT(*) as total FROM " . $conn->real_escape_string($tabla_dependiente) . " WHERE " . $conn->real_escape_string($columna) . " = ?");
                    if (!$check) {
                        $_SESSION['error_eliminacion'] = "Error al preparar la consulta de verificación: " . $conn->error;
                        break;
                    }
                    $check->bind_param("i", $id);
                    $check->execute();
                    $result = $check->get_result();
                    $row = $result->fetch_assoc();

                    if ($row['total'] > 0) {
                        $_SESSION['error_eliminacion'] = "No se puede eliminar este registro porque está siendo utilizado en la tabla '$tabla_dependiente'.";
                        $check->close();
                        header("Location: administracion_tablas.php?tab=$tabla");
                        exit();
                    }
                    $check->close();
                }
            }

            if (empty($_SESSION['error_eliminacion'])) {
                // Preparar y ejecutar la consulta de eliminación
                $stmt = $conn->prepare("DELETE FROM " . $conn->real_escape_string($tabla) . " WHERE id = ?");
                if (!$stmt) {
                    $_SESSION['error_eliminacion'] = "Error al preparar la consulta de eliminación: " . $conn->error;
                } else {
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $_SESSION['exito_eliminacion'] = "Registro eliminado con éxito.";
                        } else {
                            $_SESSION['error_eliminacion'] = "No se encontró el registro con ID $id.";
                        }
                    } else {
                        $_SESSION['error_eliminacion'] = "Error al eliminar el registro: " . $conn->error;
                    }
                    $stmt->close();
                }
            }

            // Redirigir a la misma pestaña
            header("Location: administracion_tablas.php?tab=$tabla");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_eliminacion'] = "Error al eliminar: " . $e->getMessage();
            header("Location: administracion_tablas.php?tab=$tabla");
            exit();
        }
    }
}

// =============================================
// FUNCIONES CRUD PARA TODAS LAS TABLAS
// =============================================

/**
 * Obtener todos los registros de una tabla
 * @param mysqli $conn Conexión a la base de datos
 * @param string $tabla Nombre de la tabla
 * @return array Registros obtenidos
 */
function obtenerRegistros($conn, $tabla) {
    global $tablas_permitidas;
    if (!in_array($tabla, $tablas_permitidas)) {
        return [];
    }

    $sql = "SELECT * FROM " . $conn->real_escape_string($tabla) . " ORDER BY id DESC";
    $result = $conn->query($sql);
    $registros = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $registros[] = $row;
        }
    }

    return $registros;
}

/**
 * Obtener un registro específico por ID
 * @param mysqli $conn Conexión a la base de datos
 * @param string $tabla Nombre de la tabla
 * @param int $id ID del registro
 * @return array|null Registro encontrado или null
 */
function obtenerRegistroPorId($conn, $tabla, $id) {
    global $tablas_permitidas;
    if (!in_array($tabla, $tablas_permitidas) || !is_numeric($id)) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM " . $conn->real_escape_string($tabla) . " WHERE id = ?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

// =============================================
// AGREGAR NUEVO REGISTRO
// =============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_registro']) && isset($_POST['tabla'])) {
    $tabla = $_POST['tabla'];

    // Validar que la tabla esté en la lista blanca
    if (!in_array($tabla, $tablas_permitidas)) {
        $mensaje_error = "Tabla no válida.";
    } else {
        $datos = $_POST;

        try {
            switch ($tabla) {
                case 'areas':
                    $codigo_area = trim($datos['codigo_area'] ?? '');
                    $area = trim($datos['area'] ?? '');
                    $descripcion = trim($datos['descripcion'] ?? '');
                    $total_horas = $datos['total_horas'] ?? 0;
                    $activo = isset($datos['activo']) ? 1 : 0;
                    
                    if (empty($codigo_area) || empty($area)) {
                        $mensaje_error = "Los campos código y área son obligatorios.";
                        break;
                    }

                    $stmt = $conn->prepare("INSERT INTO areas (codigo_area, area, descripcion, total_horas, activo) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssii", $codigo_area, $area, $descripcion, $total_horas, $activo);
                    break;

                case 'equipos':
                    $codigo_equipo = trim($datos['codigo_equipo'] ?? '');
                    $nombre_equipo = trim($datos['nombre_equipo'] ?? '');
                    $area_id = $datos['area_id'] ?? '';
                    $trabajos_por_hora = $datos['trabajos_por_hora'] ?? 0;
                    $meta_hora_persona = $datos['meta_hora_persona'] ?? 0;
                    $activo = isset($datos['activo']) ? 1 : 0;
                    
                    if (empty($codigo_equipo) || empty($nombre_equipo) || empty($area_id)) {
                        $mensaje_error = "Los campos código, nombre y área son obligatorios.";
                        break;
                    }

                    $stmt = $conn->prepare("INSERT INTO equipos (codigo_equipo, nombre_equipo, area_id, trabajos_por_hora, meta_hora_persona, activo) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssiiii", $codigo_equipo, $nombre_equipo, $area_id, $trabajos_por_hora, $meta_hora_persona, $activo);
                    break;

                case 'motivos':
                    $motivo = trim($datos['motivo'] ?? '');
                    if (empty($motivo)) {
                        $mensaje_error = "El campo motivo es obligatorio.";
                        break;
                    }

                    $stmt = $conn->prepare("INSERT INTO motivos (motivo) VALUES (?)");
                    $stmt->bind_param("s", $motivo);
                    break;

                case 'porque_defecto':
                    $motivo_id = $datos['motivo_id'] ?? '';
                    $descripcion = trim($datos['descripcion'] ?? '');

                    if (empty($motivo_id) || empty($descripcion)) {
                        $mensaje_error = "Todos los campos son obligatorios.";
                        break;
                    }

                    $stmt = $conn->prepare("INSERT INTO porque_defecto (motivo_id, descripcion) VALUES (?, ?)");
                    $stmt->bind_param("is", $motivo_id, $descripcion);
                    break;

                case 'responsables':
                    $nombre = trim($datos['nombre'] ?? '');
                    if (empty($nombre)) {
                        $mensaje_error = "El campo nombre es obligatorio.";
                        break;
                    }

                    $stmt = $conn->prepare("INSERT INTO responsables (nombre) VALUES (?)");
                    $stmt->bind_param("s", $nombre);
                    break;
            }

            if (empty($mensaje_error) && isset($stmt)) {
                if ($stmt->execute()) {
                    $mensaje_exito = "Registro agregado con éxito.";
                } else {
                    $mensaje_error = "Error al agregar el registro: " . $conn->error;
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $mensaje_error = "Error: " . $e->getMessage();
        }
    }
}

// =============================================
// ACTUALIZAR REGISTRO
// =============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_registro']) && isset($_POST['tabla'])) {
    $tabla = $_POST['tabla'];
    $id = $_POST['id'] ?? '';

    // Validar tabla y ID
    if (!in_array($tabla, $tablas_permitidas) || !is_numeric($id)) {
        $mensaje_error = "Tabla o ID no válido.";
    } else {
        $datos = $_POST;

        try {
            switch ($tabla) {
                case 'areas':
                    $codigo_area = trim($datos['codigo_area'] ?? '');
                    $area = trim($datos['area'] ?? '');
                    $descripcion = trim($datos['descripcion'] ?? '');
                    $total_horas = $datos['total_horas'] ?? 0;
                    $activo = isset($datos['activo']) ? 1 : 0;
                    
                    if (empty($codigo_area) || empty($area)) {
                        $mensaje_error = "Los campos código y área son obligatorios.";
                        break;
                    }

                    $stmt = $conn->prepare("UPDATE areas SET codigo_area = ?, area = ?, descripcion = ?, total_horas = ?, activo = ? WHERE id = ?");
                    $stmt->bind_param("sssiii", $codigo_area, $area, $descripcion, $total_horas, $activo, $id);
                    break;

                case 'equipos':
                    $codigo_equipo = trim($datos['codigo_equipo'] ?? '');
                    $nombre_equipo = trim($datos['nombre_equipo'] ?? '');
                    $area_id = $datos['area_id'] ?? '';
                    $trabajos_por_hora = $datos['trabajos_por_hora'] ?? 0;
                    $meta_hora_persona = $datos['meta_hora_persona'] ?? 0;
                    $activo = isset($datos['activo']) ? 1 : 0;
                    
                    if (empty($codigo_equipo) || empty($nombre_equipo) || empty($area_id)) {
                        $mensaje_error = "Los campos código, nombre y área son obligatorios.";
                        break;
                    }

                    $stmt = $conn->prepare("UPDATE equipos SET codigo_equipo = ?, nombre_equipo = ?, area_id = ?, trabajos_por_hora = ?, meta_hora_persona = ?, activo = ? WHERE id = ?");
                    $stmt->bind_param("ssiiiii", $codigo_equipo, $nombre_equipo, $area_id, $trabajos_por_hora, $meta_hora_persona, $activo, $id);
                    break;

                case 'motivos':
                    $motivo = trim($datos['motivo'] ?? '');
                    if (empty($motivo)) {
                        $mensaje_error = "El campo motivo es obligatorio.";
                        break;
                    }

                    $stmt = $conn->prepare("UPDATE motivos SET motivo = ? WHERE id = ?");
                    $stmt->bind_param("si", $motivo, $id);
                    break;

                case 'porque_defecto':
                    $motivo_id = $datos['motivo_id'] ?? '';
                    $descripcion = trim($datos['descripcion'] ?? '');

                    if (empty($motivo_id) || empty($descripcion)) {
                        $mensaje_error = "Todos los campos son obligatorios.";
                        break;
                    }

                    $stmt = $conn->prepare("UPDATE porque_defecto SET motivo_id = ?, descripcion = ? WHERE id = ?");
                    $stmt->bind_param("isi", $motivo_id, $descripcion, $id);
                    break;

                case 'responsables':
                    $nombre = trim($datos['nombre'] ?? '');
                    if (empty($nombre)) {
                        $mensaje_error = "El campo nombre es obligatorio.";
                        break;
                    }

                    $stmt = $conn->prepare("UPDATE responsables SET nombre = ? WHERE id = ?");
                    $stmt->bind_param("si", $nombre, $id);
                    break;
            }

            if (empty($mensaje_error) && isset($stmt)) {
                if ($stmt->execute()) {
                    $mensaje_exito = "Registro actualizado con éxito.";
                } else {
                    $mensaje_error = "Error al actualizar el registro: " . $conn->error;
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $mensaje_error = "Error: " . $e->getMessage();
        }
    }
}

// Obtener datos para las tablas
$areas = obtenerRegistros($conn, 'areas');
$equipos = obtenerRegistros($conn, 'equipos');
$motivos = obtenerRegistros($conn, 'motivos');
$porque_defecto = obtenerRegistros($conn, 'porque_defecto');
$responsables = obtenerRegistros($conn, 'responsables');

// Obtener motivos para el formulario de porque_defecto
$motivos_options = $conn->query("SELECT id, motivo FROM motivos ORDER BY motivo");

// Obtener áreas para el formulario de equipos
$areas_options = $conn->query("SELECT id, codigo_area, area FROM areas ORDER BY area");

// Determinar pestaña activa
$tab_activa = isset($_GET['tab']) && in_array($_GET['tab'], $tablas_permitidas) ? $_GET['tab'] : 'areas';

// Mostrar mensajes de error o éxito desde la URL o sesión
if (isset($_GET['error'])) {
    $mensaje_error = urldecode($_GET['error']);
}
if (isset($_GET['success'])) {
    $mensaje_exito = urldecode($_GET['success']);
}

// Mostrar mensajes de eliminación desde la sesión
if (isset($_SESSION['error_eliminacion'])) {
    $mensaje_error = $_SESSION['error_eliminacion'];
    unset($_SESSION['error_eliminacion']);
}
if (isset($_SESSION['exito_eliminacion'])) {
    $mensaje_exito = $_SESSION['exito_eliminacion'];
    unset($_SESSION['exito_eliminacion']);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Administrativo - Gestión de Tablas</title>
    <style>
        /* Estilos generales */
        body {
            background: #155724;
            color: white;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            padding-bottom: 90px;
            position: relative;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .logo {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 200px;
            height: auto;
        }

        .firma {
            text-align: center;
            font-size: 15px;
            color: #d4fcd4;
            padding: 15px 0;
            background-color: #003300;
            border-top: 1px solid #d4fcd4;
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            box-sizing: border-box;
        }

        /* Estilos de pestañas */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #28a745;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 20px;
            background: #218838;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
            border-radius: 5px 5px 0 0;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        .tab-btn:hover {
            background: #1e7e34;
        }

        .tab-btn.active {
            background: #28a745;
        }

        .tab-content {
            display: none;
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0 0 8px 8px;
        }

        .tab-content.active {
            display: block;
        }

        /* Estilos comunes para formularios */
        form {
            background: rgba(0, 0, 0, 0.25);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        input, select, button, textarea {
            padding: 10px;
            margin: 8px 0;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            background-color: #d4edda;
            color: #155724;
            font-weight: bold;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 5px #28a745;
        }

        button {
            background: #006400;
            color: white;
            border: none;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #004d00;
        }

        /* Estilos para tablas */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background-color: #f9f9f9;
            color: #155724;
        }

        th, td {
            padding: 10px;
            border: 1px solid #333;
            text-align: center;
        }

        th {
            background: #218838;
            color: white;
        }

        tr:hover {
            background-color: #d4edda;
        }

        /* Mensajes de error/éxito */
        .mensaje-error {
            color: #721c24;
            background-color: #f8d7da;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .mensaje-exito {
            color: #155724;
            background-color: #d4edda;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        /* Estilos específicos para secciones */
        .flex-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .flex-column {
            flex: 1;
            min-width: 200px;
        }

        .filtro-item {
            margin-bottom: 15px;
        }

        .filtro-item label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        /* Estilos para botones de acción */
        .btn-action {
            padding: 5px 10px;
            border-radius: 4px;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
            margin: 2px;
        }

        .btn-modificar {
            background-color: #007bff;
        }

        .btn-eliminar {
            background-color: #dc3545;
        }

        .btn-agregar {
            background-color: #28a745;
        }

        .btn-modificar:hover {
            background-color: #0056b3;
        }

        .btn-eliminar:hover {
            background-color: #c82333;
        }

        .btn-agregar:hover {
            background-color: #1e7e34;
        }

        /* Estilos para modales */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background-color: #f0fdf4;
            padding: 20px;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .close-btn {
            background: #dc3545;
            margin-left: 10px;
        }

        /* Estilos para scroll en tablas */
        .scrollable-table {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        /* Estilos para formularios CRUD */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
            width: auto;
        }
    </style>
</head>
<body>

<img src="/control_produccion/public/logo.png" alt="Logo" class="logo">

<div class="container">
    <h1>Dashboard Administrativo - Gestión de Tablas</h1>
    <h2>Bienvenid@, <?= htmlspecialchars($_SESSION['empleado']) ?> | <a href="login_admin.php" style="color: #c3e6cb;">Cerrar sesión</a></h2>

    <!-- Mostrar mensajes de error o éxito -->
    <?php if ($mensaje_error): ?>
        <div class="mensaje-error"><?= htmlspecialchars($mensaje_error) ?></div>
    <?php endif; ?>
    
    <?php if ($mensaje_exito): ?>
        <div class="mensaje-exito"><?= htmlspecialchars($mensaje_exito) ?></div>
    <?php endif; ?>

    <!-- Pestañas principales -->
    <div class="tabs">
        <button class="tab-btn <?= $tab_activa == 'areas' ? 'active' : '' ?>" data-tab="areas">Áreas</button>
        <button class="tab-btn <?= $tab_activa == 'equipos' ? 'active' : '' ?>" data-tab="equipos">Equipos</button>
        <button class="tab-btn <?= $tab_activa == 'motivos' ? 'active' : '' ?>" data-tab="motivos">Motivos</button>
        <button class="tab-btn <?= $tab_activa == 'porque_defecto' ? 'active' : '' ?>" data-tab="porque_defecto">Porqué Defecto</button>
        <button class="tab-btn <?= $tab_activa == 'responsables' ? 'active' : '' ?>" data-tab="responsables">Responsables</button>
    </div>

    <!-- Contenido de pestañas -->
    <div id="areas" class="tab-content <?= $tab_activa == 'areas' ? 'active' : '' ?>">
        <h2>Gestión de Áreas</h2>
        
        <!-- Botón para agregar área -->
        <button class="btn-action btn-agregar" onclick="mostrarModalAgregar('areas')">Agregar Área</button>
        
        <!-- Tabla de áreas -->
        <div class="scrollable-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Código</th>
                        <th>Área</th>
                        <th>Descripción</th>  
                        <th>Horas Trabajo</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
<tbody>
    <?php foreach ($areas as $area): ?>
        <tr>
            <td><?= htmlspecialchars($area['id']) ?></td>
            <td><?= htmlspecialchars($area['codigo_area']) ?></td>
            <td><?= htmlspecialchars($area['area']) ?></td>
            <td><?= htmlspecialchars($area['descripcion']) ?></td>
            <td><?= htmlspecialchars($area['total_horas']) ?></td>                           
            <td><?= $area['activo'] ? 'Activo' : 'Inactivo' ?></td>
            <td>
<button class="btn-action btn-modificar" 
        onclick="mostrarModalEditarArea(
            <?= $area['id'] ?>, 
            '<?= htmlspecialchars($area['codigo_area'], ENT_QUOTES) ?>', 
            '<?= htmlspecialchars($area['area'], ENT_QUOTES) ?>', 
            '<?= htmlspecialchars($area['descripcion'], ENT_QUOTES) ?>', 
            <?= $area['total_horas'] ?>,
            <?= $area['activo'] ?>
        )">
    Editar
</button>
                <a href="?eliminar=1&tab=areas&id=<?= $area['id'] ?>" 
                   class="btn-action btn-eliminar" 
                   onclick="return confirm('¿Estás seguro de eliminar esta área?');">
                    Eliminar
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>
            </table>
        </div>
    </div>

    <div id="equipos" class="tab-content <?= $tab_activa == 'equipos' ? 'active' : '' ?>">
        <h2>Gestión de Equipos</h2>
        
        <!-- Botón para agregar equipo -->
        <button class="btn-action btn-agregar" onclick="mostrarModalAgregar('equipos')">Agregar Equipo</button>
        
        <!-- Tabla de equipos -->
        <div class="scrollable-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Código</th>
                        <th>Nombre del Equipo</th>
                        <th>Área</th>
                        <th>Trabajos/Hora</th>
                        <th>Meta/Hora/Persona</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipos as $equipo): 
                        // Obtener el nombre del área
                        $area_nombre = "";
                        $stmt = $conn->prepare("SELECT area FROM areas WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param("i", $equipo['area_id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result && $result->num_rows > 0) {
                                $area_row = $result->fetch_assoc();
                                $area_nombre = $area_row['area'];
                            }
                            $stmt->close();
                        }
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($equipo['id']) ?></td>
                            <td><?= htmlspecialchars($equipo['codigo_equipo']) ?></td>
                            <td><?= htmlspecialchars($equipo['nombre_equipo']) ?></td>
                            <td><?= htmlspecialchars($area_nombre) ?> (ID: <?= $equipo['area_id'] ?>)</td>
                            <td><?= htmlspecialchars($equipo['trabajos_por_hora']) ?></td>
                            <td><?= htmlspecialchars($equipo['meta_hora_persona']) ?></td>
                            <td><?= $equipo['activo'] ? 'Activo' : 'Inactivo' ?></td>
                            <td>
                                <button class="btn-action btn-modificar" onclick="mostrarModalEditarEquipo(<?= $equipo['id'] ?>, '<?= htmlspecialchars($equipo['codigo_equipo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($equipo['nombre_equipo'], ENT_QUOTES) ?>', <?= $equipo['area_id'] ?>, <?= $equipo['trabajos_por_hora'] ?>, <?= $equipo['meta_hora_persona'] ?>, <?= $equipo['activo'] ?>)">
                                    Editar
                                </button>
                                <a href="?eliminar=1&tab=equipos&id=<?= $equipo['id'] ?>" class="btn-action btn-eliminar" onclick="return confirm('¿Estás seguro de eliminar este equipo?');">
                                    Eliminar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="motivos" class="tab-content <?= $tab_activa == 'motivos' ? 'active' : '' ?>">
        <h2>Gestión de Motivos</h2>
        
        <!-- Botón para agregar motivo -->
        <button class="btn-action btn-agregar" onclick="mostrarModalAgregar('motivos')">Agregar Motivo</button>
        
        <!-- Tabla de motivos -->
        <div class="scrollable-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Motivo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($motivos as $motivo): ?>
                        <tr>
                            <td><?= htmlspecialchars($motivo['id']) ?></td>
                            <td><?= htmlspecialchars($motivo['motivo']) ?></td>
                            <td>
                                <button class="btn-action btn-modificar" onclick="mostrarModalEditar('motivos', <?= $motivo['id'] ?>, '<?= htmlspecialchars($motivo['motivo'], ENT_QUOTES) ?>')">
                                    Editar
                                </button>
                                <a href="?eliminar=1&tab=motivos&id=<?= $motivo['id'] ?>" class="btn-action btn-eliminar" onclick="return confirm('¿Estás seguro de eliminar este motivo?');">
                                    Eliminar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="porque_defecto" class="tab-content <?= $tab_activa == 'porque_defecto' ? 'active' : '' ?>">
        <h2>Gestión de Porqué Defecto</h2>
        
        <!-- Botón para agregar registro -->
        <button class="btn-action btn-agregar" onclick="mostrarModalAgregar('porque_defecto')">Agregar Registro</button>
        
        <!-- Tabla de porque_defecto -->
        <div class="scrollable-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Motivo ID</th>
                        <th>Motivo</th>
                        <th>Descripción</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($porque_defecto as $registro): 
                        // Obtener el nombre del motivo
                        $motivo_nombre = "";
                        $stmt = $conn->prepare("SELECT motivo FROM motivos WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param("i", $registro['motivo_id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result && $result->num_rows > 0) {
                                $motivo_row = $result->fetch_assoc();
                                $motivo_nombre = $motivo_row['motivo'];
                            }
                            $stmt->close();
                        }
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($registro['id']) ?></td>
                            <td><?= htmlspecialchars($registro['motivo_id']) ?></td>
                            <td><?= htmlspecialchars($motivo_nombre) ?></td>
                            <td><?= htmlspecialchars($registro['descripcion']) ?></td>
                            <td>
                                <button class="btn-action btn-modificar" onclick="mostrarModalEditarPorqueDefecto(<?= $registro['id'] ?>, <?= $registro['motivo_id'] ?>, '<?= htmlspecialchars($registro['descripcion'], ENT_QUOTES) ?>')">
                                    Editar
                                </button>
                                <a href="?eliminar=1&tab=porque_defecto&id=<?= $registro['id'] ?>" class="btn-action btn-eliminar" onclick="return confirm('¿Estás seguro de eliminar este registro?');">
                                    Eliminar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="responsables" class="tab-content <?= $tab_activa == 'responsables' ? 'active' : '' ?>">
        <h2>Gestión de Responsables</h2>
        
        <!-- Botón para agregar responsable -->
        <button class="btn-action btn-agregar" onclick="mostrarModalAgregar('responsables')">Agregar Responsable</button>
        
        <!-- Tabla de responsables -->
        <div class="scrollable-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($responsables as $responsable): ?>
                        <tr>
                            <td><?= htmlspecialchars($responsable['id']) ?></td>
                            <td><?= htmlspecialchars($responsable['nombre']) ?></td>
                            <td>
                                <button class="btn-action btn-modificar" onclick="mostrarModalEditar('responsables', <?= $responsable['id'] ?>, '<?= htmlspecialchars($responsable['nombre'], ENT_QUOTES) ?>')">
                                    Editar
                                </button>
                                <a href="?eliminar=1&tab=responsables&id=<?= $responsable['id'] ?>" class="btn-action btn-eliminar" onclick="return confirm('¿Estás seguro de eliminar este responsable?');">
                                    Eliminar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal para agregar/editar registros -->
    <div id="modalCrud" class="modal">
        <div class="modal-content">
            <h3 id="modalTitulo">Agregar Registro</h3>
            <form id="formCrud" method="POST">
                <input type="hidden" id="registroId" name="id" value="">
                <input type="hidden" id="tablaNombre" name="tabla" value="">
                
                <div id="camposFormulario">
                    <!-- Los campos se generarán dinámicamente -->
                </div>
                
                <div style="margin-top: 15px;">
                    <button type="submit" id="btnGuardar" name="agregar_registro">Guardar</button>
                    <button type="button" class="close-btn" onclick="cerrarModalCrud()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Firma -->
<div class="firma">
    Sistema de control de producción | © <?= date("Y"); ?>
    <p>By: Nestor Rosales | Rosales_Dev91</p>
</div>

<!-- Scripts -->
<script>
    // Manejo de pestañas
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function(event) {
            event.preventDefault();
            // Ocultar todos los contenidos
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Desactivar todos los botones
            document.querySelectorAll('.tab-btn').forEach(tabBtn => {
                tabBtn.classList.remove('active');
            });
            
            // Mostrar contenido seleccionado
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
            this.classList.add('active');
            
            // Actualizar la URL con el parámetro de pestaña
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);
        });
    });

    // Funciones para el modal de CRUD
    function mostrarModalAgregar(tabla) {
        document.getElementById('modalTitulo').textContent = 'Agregar ' + obtenerNombreTabla(tabla);
        document.getElementById('tablaNombre').value = tabla;
        document.getElementById('registroId').value = '';
        document.getElementById('btnGuardar').name = 'agregar_registro';
        
        let camposHTML = '';
        
switch(tabla) {
case 'areas':
    camposHTML = `
        <div class="form-group">
            <label for="codigo_area">Código:</label>
            <input type="text" id="codigo_area" name="codigo_area" required>
        </div>
        <div class="form-group">
            <label for="area">Área:</label>
            <input type="text" id="area" name="area" required>
        </div>
        <div class="form-group">
            <label for="descripcion">Descripción:</label>
            <textarea id="descripcion" name="descripcion" rows="3"></textarea>
        </div>
        <div class="form-group">
            <label for="total_horas">Total Horas:</label>
            <input type="number" id="total_horas" name="total_horas" min="0" required>
        </div>
        <div class="form-group checkbox-group">
            <input type="checkbox" id="activo" name="activo" value="1" checked>
            <label for="activo">Activo</label>
        </div>
    `;
    break;
                
            case 'equipos':
                let areasOptionsHTML = '';
                <?php 
                if ($areas_options && $areas_options->num_rows > 0) {
                    while($area = $areas_options->fetch_assoc()) {
                        echo 'areasOptionsHTML += \'<option value="' . $area['id'] . '">' . htmlspecialchars($area['codigo_area']) . ' - ' . htmlspecialchars($area['area']) . '</option>\';';
                    }
                    // Reiniciar el puntero para futuras consultas
                    $areas_options->data_seek(0);
                }
                ?>
                
                camposHTML = `
                    <div class="form-group">
                        <label for="codigo_equipo">Código:</label>
                        <input type="text" id="codigo_equipo" name="codigo_equipo" required>
                    </div>
                    <div class="form-group">
                        <label for="nombre_equipo">Nombre del Equipo:</label>
                        <input type="text" id="nombre_equipo" name="nombre_equipo" required>
                    </div>
                    <div class="form-group">
                        <label for="area_id">Área:</label>
                        <select id="area_id" name="area_id" required>
                            <option value="">Seleccione un área</option>
                            ${areasOptionsHTML}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="trabajos_por_hora">Trabajos por Hora:</label>
                        <input type="number" id="trabajos_por_hora" name="trabajos_por_hora" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label for="meta_hora_persona">Meta por Hora/Persona:</label>
                        <input type="number" id="meta_hora_persona" name="meta_hora_persona" value="0" min="0">
                    </div>
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="activo" name="activo" value="1" checked>
                        <label for="activo">Activo</label>
                    </div>
                `;
                break;
                
            case 'motivos':
                camposHTML = `
                    <div class="form-group">
                        <label for="motivo">Motivo:</label>
                        <input type="text" id="motivo" name="motivo" required>
                    </div>
                `;
                break;
                
            case 'porque_defecto':
                let motivosOptionsHTML = '';
                <?php 
                if ($motivos_options && $motivos_options->num_rows > 0) {
                    while($motivo = $motivos_options->fetch_assoc()) {
                        echo 'motivosOptionsHTML += \'<option value="' . $motivo['id'] . '">' . htmlspecialchars($motivo['motivo']) . '</option>\';';
                    }
                    // Reiniciar el puntero para futuras consultas
                    $motivos_options->data_seek(0);
                }
                ?>
                
                camposHTML = `
                    <div class="form-group">
                        <label for="motivo_id">Motivo:</label>
                        <select id="motivo_id" name="motivo_id" required>
                            <option value="">Seleccione un motivo</option>
                            ${motivosOptionsHTML}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="descripcion">Descripción:</label>
                        <textarea id="descripcion" name="descripcion" rows="4" required></textarea>
                    </div>
                `;
                break;
                
            case 'responsables':
                camposHTML = `
                    <div class="form-group">
                        <label for="nombre">Nombre:</label>
                        <input type="text" id="nombre" name="nombre" required>
                    </div>
                `;
                break;
        }
        
        document.getElementById('camposFormulario').innerHTML = camposHTML;
        document.getElementById('modalCrud').style.display = 'flex';
    }

function mostrarModalEditarArea(id, codigo, area, descripcion, total_horas, activo) {
    document.getElementById('modalTitulo').textContent = 'Editar Área';
    document.getElementById('tablaNombre').value = 'areas';
    document.getElementById('registroId').value = id;
    document.getElementById('btnGuardar').name = 'actualizar_registro';
    
    const camposHTML = `
        <div class="form-group">
            <label for="codigo_area">Código:</label>
            <input type="text" id="codigo_area" name="codigo_area" value="${codigo}" required>
        </div>
        <div class="form-group">
            <label for="area">Área:</label>
            <input type="text" id="area" name="area" value="${area}" required>
        </div>
        <div class="form-group">
            <label for="descripcion">Descripción:</label>
            <textarea id="descripcion" name="descripcion" rows="3">${descripcion}</textarea>
        </div>
        <div class="form-group">
            <label for="total_horas">Total Horas:</label>
            <input type="number" id="total_horas" name="total_horas" value="${total_horas}" min="0" required>
        </div>
        <div class="form-group checkbox-group">
            <input type="checkbox" id="activo" name="activo" value="1" ${activo ? 'checked' : ''}>
            <label for="activo">Activo</label>
        </div>
    `;
    
    document.getElementById('camposFormulario').innerHTML = camposHTML;
    document.getElementById('modalCrud').style.display = 'flex';
}

    function mostrarModalEditarEquipo(id, codigo, nombre, areaId, trabajosHora, metaHoraPersona, activo) {
        document.getElementById('modalTitulo').textContent = 'Editar Equipo';
        document.getElementById('tablaNombre').value = 'equipos';
        document.getElementById('registroId').value = id;
        document.getElementById('btnGuardar').name = 'actualizar_registro';
        
        let areasOptionsHTML = '';
        <?php 
        if ($areas_options && $areas_options->num_rows > 0) {
            while($area = $areas_options->fetch_assoc()) {
                echo 'areasOptionsHTML += \'<option value="' . $area['id'] . '"\' + (areaId == ' . $area['id'] . ' ? " selected" : "") + \'>' . htmlspecialchars($area['codigo_area']) . ' - ' . htmlspecialchars($area['area']) . '</option>\';';
            }
            // Reiniciar el puntero para futuras consultas
            $areas_options->data_seek(0);
        }
        ?>
        
        const camposHTML = `
            <div class="form-group">
                <label for="codigo_equipo">Código:</label>
                <input type="text" id="codigo_equipo" name="codigo_equipo" value="${codigo}" required>
            </div>
            <div class="form-group">
                <label for="nombre_equipo">Nombre del Equipo:</label>
                <input type="text" id="nombre_equipo" name="nombre_equipo" value="${nombre}" required>
            </div>
            <div class="form-group">
                <label for="area_id">Área:</label>
                <select id="area_id" name="area_id" required>
                    <option value="">Seleccione un área</option>
                    ${areasOptionsHTML}
                </select>
            </div>
            <div class="form-group">
                <label for="trabajos_por_hora">Trabajos por Hora:</label>
                <input type="number" id="trabajos_por_hora" name="trabajos_por_hora" value="${trabajosHora}" min="0">
            </div>
            <div class="form-group">
                <label for="meta_hora_persona">Meta por Hora/Persona:</label>
                <input type="number" id="meta_hora_persona" name="meta_hora_persona" value="${metaHoraPersona}" min="0">
            </div>
            <div class="form-group checkbox-group">
                <input type="checkbox" id="activo" name="activo" value="1" ${activo ? 'checked' : ''}>
                <label for="activo">Activo</label>
            </div>
        `;
        
        document.getElementById('camposFormulario').innerHTML = camposHTML;
        document.getElementById('modalCrud').style.display = 'flex';
    }

    function mostrarModalEditar(tabla, id, valor) {
        document.getElementById('modalTitulo').textContent = 'Editar ' + obtenerNombreTabla(tabla);
        document.getElementById('tablaNombre').value = tabla;
        document.getElementById('registroId').value = id;
        document.getElementById('btnGuardar').name = 'actualizar_registro';
        
        let camposHTML = '';
        
        switch(tabla) {
            case 'motivos':
                camposHTML = `
                    <div class="form-group">
                        <label for="motivo">Motivo:</label>
                        <input type="text" id="motivo" name="motivo" value="${valor}" required>
                    </div>
                `;
                break;
                
            case 'responsables':
                camposHTML = `
                    <div class="form-group">
                        <label for="nombre">Nombre:</label>
                        <input type="text" id="nombre" name="nombre" value="${valor}" required>
                    </div>
                `;
                break;
        }
        
        document.getElementById('camposFormulario').innerHTML = camposHTML;
        document.getElementById('modalCrud').style.display = 'flex';
    }

    function mostrarModalEditarPorqueDefecto(id, motivoId, descripcion) {
        document.getElementById('modalTitulo').textContent = 'Editar Porqué Defecto';
        document.getElementById('tablaNombre').value = 'porque_defecto';
        document.getElementById('registroId').value = id;
        document.getElementById('btnGuardar').name = 'actualizar_registro';
        
        let optionsHTML = '';
        <?php 
        if ($motivos_options && $motivos_options->num_rows > 0) {
            while($motivo = $motivos_options->fetch_assoc()) {
                echo 'optionsHTML += \'<option value="' . $motivo['id'] . '"\' + (motivoId == ' . $motivo['id'] . ' ? " selected" : "") + \'>' . htmlspecialchars($motivo['motivo']) . '</option>\';';
            }
            // Reiniciar el puntero para futuras consultas
            $motivos_options->data_seek(0);
        }
        ?>
        
        const camposHTML = `
            <div class="form-group">
                <label for="motivo_id">Motivo:</label>
                <select id="motivo_id" name="motivo_id" required>
                    <option value="">Seleccione un motivo</option>
                    ${optionsHTML}
                </select>
            </div>
            <div class="form-group">
                <label for="descripcion">Descripción:</label>
                <textarea id="descripcion" name="descripcion" rows="4" required>${descripcion}</textarea>
            </div>
        `;
        
        document.getElementById('camposFormulario').innerHTML = camposHTML;
        document.getElementById('modalCrud').style.display = 'flex';
    }

    function cerrarModalCrud() {
        document.getElementById('modalCrud').style.display = 'none';
    }

    function obtenerNombreTabla(tabla) {
        const nombres = {
            'areas': 'Área',
            'equipos': 'Equipo',
            'motivos': 'Motivo',
            'porque_defecto': 'Porqué Defecto',
            'responsables': 'Responsable'
        };
        return nombres[tabla] || 'Registro';
    }

    // Cerrar modal al hacer clic fuera del contenido
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('modalCrud');
        if (event.target === modal) {
            cerrarModalCrud();
        }
    });

    // Manejar envío del formulario
    document.getElementById('formCrud').addEventListener('submit', function(e) {
        // Validación básica
        const inputs = this.querySelectorAll('input[required], select[required], textarea[required]');
        let valid = true;
        
        inputs.forEach(input => {
            if (!input.value.trim()) {
                valid = false;
                input.style.borderColor = 'red';
            } else {
                input.style.borderColor = '';
            }
        });
        
        if (!valid) {
            e.preventDefault();
            alert('Por favor, complete todos los campos obligatorios.');
        }
    });
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
<?php
// Cerrar conexión
$conn->close();
?>