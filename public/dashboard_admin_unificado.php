<?php
session_start();

// Validación de sesión
if (!isset($_SESSION['empleado']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login_unificado.php");
    exit();
}

require_once '../config/database.php';

// Configuración inicial
date_default_timezone_set('America/Guatemala');
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// =============================================
// SECCIÓN DE EMPLEADOS (similar a dashboard_admin_empleados.php)
// =============================================
$mensaje_error = '';
$mensaje_exito = '';

// Agregar empleado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_empleado'])) {
    $nombre = trim($_POST['nombre_empleado']);
    $codigo_empleado = trim($_POST['codigo_empleado']);
    $rol = $_POST['rol'];

    if (!empty($nombre) && !empty($codigo_empleado) && !empty($rol)) {
        $verificar = $conn->prepare("SELECT id FROM empleados WHERE codigo_empleado = ?");
        $verificar->bind_param("s", $codigo_empleado);
        $verificar->execute();
        $verificar->store_result();

        if ($verificar->num_rows > 0) {
            $mensaje_error = "El código de empleado ya existe.";
        } else {
            $stmt = $conn->prepare("INSERT INTO empleados (nombre_empleado, codigo_empleado, rol) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sss", $nombre, $codigo_empleado, $rol);
                $stmt->execute();
                $stmt->close();
                $mensaje_exito = "Empleado agregado con éxito.";
                header("Refresh:0");
                exit();
            } else {
                $mensaje_error = "Error al preparar la consulta.";
            }
        }
        $verificar->close();
    } else {
        $mensaje_error = "Todos los campos son obligatorios.";
    }
}

// Modificar rol
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modificar_rol'])) {
    $id = $_POST['id_empleado'];
    $nuevo_rol = $_POST['nuevo_rol'];

    if (is_numeric($id) && !empty($nuevo_rol)) {
        $stmt = $conn->prepare("UPDATE empleados SET rol = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $nuevo_rol, $id);
            $stmt->execute();
            $stmt->close();
            $mensaje_exito = "Rol modificado con éxito.";
            header("Refresh:0");
            exit();
        } else {
            $mensaje_error = "Error al preparar la consulta de modificación.";
        }
    } else {
        $mensaje_error = "Datos inválidos para modificar el rol.";
    }
}

// Eliminar empleado
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    if (is_numeric($id)) {
        $stmt = $conn->prepare("DELETE FROM empleados WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
            exit();
        }
    }
}

// Obtener empleados
$empleados = $conn->query("SELECT * FROM empleados");

// =============================================
// SECCIÓN DE PRODUCCIÓN (similar a dashboard_admin_produccion.php)
// =============================================
$empleado_seleccionado = $_POST['empleado'] ?? '';
$area_seleccionada = $_POST['area'] ?? '';
$turno_seleccionado = $_POST['turno'] ?? '';
$fecha_inicio = $_POST['fecha_inicio'] ?? '';
$fecha_fin = $_POST['fecha_fin'] ?? '';
$hora_inicio = $_POST['hora_inicio'] ?? '00:00';
$ampm_inicio = $_POST['ampm_inicio'] ?? 'AM';
$hora_fin = $_POST['hora_fin'] ?? '11:59';
$ampm_fin = $_POST['ampm_fin'] ?? 'PM';
$agrupar_por = $_POST['agrupar_por'] ?? '';
$hay_filtros = isset($_POST['buscar']) || isset($_POST['exportar_csv']);

// Función para convertir hora AM/PM a 24h
function convertirHoraConSegundos($hora, $ampm) {
    if (empty($hora)) return '00:00:00';
    list($h, $m) = explode(':', $hora);
    $h = (int)$h;
    if (strtoupper($ampm) === 'PM' && $h < 12) $h += 12;
    elseif (strtoupper($ampm) === 'AM' && $h == 12) $h = 0;
    return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . $m . ':00';
}

// Validación de fechas
$errores = [];
if ($hay_filtros) {
    if (!empty($fecha_inicio) || !empty($fecha_fin)) {
        if (empty($fecha_inicio) || empty($fecha_fin)) {
            $errores[] = "Debes seleccionar un rango completo de fechas.";
        } else {
            $fecha_inicio_valida = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
            $fecha_fin_valida = DateTime::createFromFormat('Y-m-d', $fecha_fin);

            if (!$fecha_inicio_valida || !$fecha_fin_valida
                || $fecha_inicio_valida->format('Y-m-d') !== $fecha_inicio
                || $fecha_fin_valida->format('Y-m-d') !== $fecha_fin) {
                $errores[] = "Formato de fecha inválido. Usa YYYY-MM-DD.";
            } elseif ($fecha_inicio > $fecha_fin) {
                $errores[] = "La fecha inicio no puede ser mayor a la fecha fin.";
            }
        }
    }
}

// Construcción dinámica del WHERE para producción
$condiciones = [];
$parametros = [];
$tipos = '';

if ($empleado_seleccionado !== '') {
    $condiciones[] = "todas.empleado = ?";
    $parametros[] = $empleado_seleccionado;
    $tipos .= 's';
}
if ($area_seleccionada !== '') {
    $condiciones[] = "todas.area = ?";
    $parametros[] = $area_seleccionada;
    $tipos .= 's';
}
if ($turno_seleccionado !== '') {
    $condiciones[] = "todas.turno = ?";
    $parametros[] = $turno_seleccionado;
    $tipos .= 's';
}

if (empty($errores) && !empty($fecha_inicio) && !empty($fecha_fin)) {
    $hora_inicio_24 = convertirHoraConSegundos($hora_inicio, $ampm_inicio);
    $hora_fin_24 = convertirHoraConSegundos($hora_fin, $ampm_fin);

    $fecha_inicio_esc = $fecha_inicio . " " . $hora_inicio_24;
    $fecha_fin_esc = $fecha_fin . " " . $hora_fin_24;

    $condiciones[] = "todas.fecha BETWEEN ? AND ?";
    $parametros[] = $fecha_inicio_esc;
    $parametros[] = $fecha_fin_esc;
    $tipos .= 'ss';
}

$where_sql = !empty($condiciones) ? "WHERE " . implode(' AND ', $condiciones) : "";

// Función para obtener resumen agrupado
function obtenerResumen($conn, $campo, $condiciones, $tipos, $parametros) {
    $cond_sin_prefijo = [];
    foreach ($condiciones as $cond) {
        $cond_sin_prefijo[] = preg_replace('/\btodas\./', '', $cond);
    }
    $where_subconsulta = !empty($cond_sin_prefijo) ? "WHERE " . implode(" AND ", $cond_sin_prefijo) : "";

    $parametros_duplicados = array_merge($parametros, $parametros);
    $tipos_duplicados = $tipos . $tipos;

    $sql = "
        SELECT todas.$campo, COUNT(*) AS total_ordenes
        FROM (
            SELECT empleado, area, turno, fecha FROM produccion $where_subconsulta
            UNION ALL
            SELECT empleado, area, turno, fecha FROM registros_antiguos $where_subconsulta
        ) AS todas
        GROUP BY todas.$campo
        ORDER BY total_ordenes DESC
    ";

    $stmt = $conn->prepare($sql);
    if ($tipos_duplicados !== '') {
        $bind_params = array_merge([$tipos_duplicados], $parametros_duplicados);
        $refs = [];
        foreach ($bind_params as $key => $val) {
            $refs[$key] = &$bind_params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();

    $datos = [];
    while ($row = $resultado->fetch_assoc()) {
        $datos[] = $row;
    }
    $stmt->close();
    return $datos;
}

// Exportar CSV producción
if (isset($_POST['exportar_csv']) && empty($errores)) {
    $_SESSION['exportando_csv'] = true;

    $fecha_inicio_raw = $_POST['fecha_inicio'] ?? '';
    $fecha_fin_raw = $_POST['fecha_fin'] ?? '';
    $hora_inicio_raw = $_POST['hora_inicio'] ?? '';
    $ampm_inicio_raw = $_POST['ampm_inicio'] ?? '';
    $hora_fin_raw = $_POST['hora_fin'] ?? '';
    $ampm_fin_raw = $_POST['ampm_fin'] ?? '';
    $hora_inicio_formato = date("h:i A", strtotime("$hora_inicio_raw $ampm_inicio_raw"));
    $hora_fin_formato = date("h:i A", strtotime("$hora_fin_raw $ampm_fin_raw"));
    $agrupar_por = $_POST['agrupar_por'] ?? '';

    $cond_export = [];
    $params_export = [];
    $types_export = '';

    if ($empleado_seleccionado !== '') {
        $cond_export[] = "empleado = ?";
        $params_export[] = $empleado_seleccionado;
        $types_export .= 's';
    }
    if ($area_seleccionada !== '') {
        $cond_export[] = "area = ?";
        $params_export[] = $area_seleccionada;
        $types_export .= 's';
    }
    if ($turno_seleccionado !== '') {
        $cond_export[] = "turno = ?";
        $params_export[] = $turno_seleccionado;
        $types_export .= 's';
    }
    if (!empty($fecha_inicio_raw) && !empty($fecha_fin_raw)) {
        $fecha_inicio_export = $fecha_inicio_raw . " " . convertirHoraConSegundos($hora_inicio_raw, $ampm_inicio_raw);
        $fecha_fin_export = $fecha_fin_raw . " " . convertirHoraConSegundos($hora_fin_raw, $ampm_fin_raw);
        $cond_export[] = "fecha BETWEEN ? AND ?";
        $params_export[] = $fecha_inicio_export;
        $params_export[] = $fecha_fin_export;
        $types_export .= 'ss';
    }

    $resumenes_exportar = [];
    if ($agrupar_por === 'todos') {
        foreach (['empleado', 'area', 'turno'] as $campo) {
            $resumenes_exportar[$campo] = obtenerResumen($conn, $campo, $cond_export, $types_export, $params_export);
        }
    } elseif (in_array($agrupar_por, ['empleado', 'area', 'turno'])) {
        $resumenes_exportar[$agrupar_por] = obtenerResumen($conn, $agrupar_por, $cond_export, $types_export, $params_export);
    }

    header('Content-Type: text/csv; charset=utf-8');
    $fecha_actual = date('Y-m-d_H-i-s');
    $nombre_archivo = "resumen_produccion_{$fecha_actual}.csv";
    header("Content-Disposition: attachment; filename=\"{$nombre_archivo}\"");
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8

    fputcsv($output, ["Resumen de producción desde $fecha_inicio_raw $hora_inicio_formato hasta $fecha_fin_raw $hora_fin_formato"]);
    fputcsv($output, []);

    $etiquetas = [
        'empleado' => 'Empleado',
        'area' => 'Área',
        'turno' => 'Turno'
    ];

    foreach ($resumenes_exportar as $tipo => $resumen) {
        if ($tipo === 'empleado') {
            fputcsv($output, ["Resumen por Empleado"]);
            fputcsv($output, ["Empleado", "Área", "Turno", "Total de Órdenes", "Total de Quiebras", "% de Quiebras"]);

            foreach ($resumen as $fila) {
                $empleado = $fila['empleado'];
                $total_ordenes = $fila['total_ordenes'];

                $stmt_area_cant = $conn->prepare("
                    SELECT area, COUNT(*) AS ordenes_area
                    FROM produccion
                    WHERE empleado = ? AND fecha BETWEEN ? AND ?
                      AND area <> '' AND area IS NOT NULL AND area NOT LIKE 'N/A'
                    GROUP BY area
                ");
                $stmt_area_cant->bind_param("sss", $empleado, $fecha_inicio_export, $fecha_fin_export);
                $stmt_area_cant->execute();
                $res_area_cant = $stmt_area_cant->get_result();

                $areas_cant = [];
                while ($fila_area = $res_area_cant->fetch_assoc()) {
                    $areas_cant[] = trim($fila_area['area']) . " ({$fila_area['ordenes_area']})";
                }
                $stmt_area_cant->close();

                $area = !empty($areas_cant) ? implode(", ", $areas_cant) : 'Sin datos';

                $stmt_turno = $conn->prepare("
                    SELECT DISTINCT turno
                    FROM produccion
                    WHERE empleado = ? AND fecha BETWEEN ? AND ?
                      AND turno <> '' AND turno IS NOT NULL AND turno NOT LIKE 'N/A'
                ");
                $stmt_turno->bind_param("sss", $empleado, $fecha_inicio_export, $fecha_fin_export);
                $stmt_turno->execute();
                $res_turno = $stmt_turno->get_result();

                $turnos = [];
                while ($fila_turno = $res_turno->fetch_assoc()) {
                    $turno_db = trim($fila_turno['turno']);
                    if (!in_array($turno_db, $turnos)) {
                        $turnos[] = $turno_db;
                    }
                }
                $stmt_turno->close();

                $turno = !empty($turnos) ? implode(", ", $turnos) : 'Sin datos';

                $stmt_quiebras = $conn->prepare("
                    SELECT COUNT(*) AS total_quiebras
                    FROM registro_quiebras
                    WHERE empleado = ? AND fecha BETWEEN ? AND ?
                ");
                $stmt_quiebras->bind_param("sss", $empleado, $fecha_inicio_export, $fecha_fin_export);
                $stmt_quiebras->execute();
                $res_quiebras = $stmt_quiebras->get_result();
                $total_quiebras = $res_quiebras->fetch_assoc()['total_quiebras'] ?? 0;
                $stmt_quiebras->close();

                $denominador = $total_ordenes + $total_quiebras;
                $porcentaje = ($denominador > 0) ? round(($total_quiebras / $denominador) * 100, 2) : 0;

                fputcsv($output, [$empleado, $area, $turno, $total_ordenes, $total_quiebras, "{$porcentaje}%"]);
            }
            fputcsv($output, []);
        } else {
            fputcsv($output, ["Resumen por " . ($etiquetas[$tipo] ?? $tipo)]);
            fputcsv($output, [($etiquetas[$tipo] ?? $tipo), "Total de Órdenes"]);

            foreach ($resumen as $fila) {
                fputcsv($output, [$fila[$tipo], $fila['total_ordenes']]);
            }
            fputcsv($output, []);
        }
    }

    fclose($output);
    unset($_SESSION['exportando_csv']);
    exit();
}

// Obtener resumen producción si hay filtros
$resumenes = [];
if ($hay_filtros && !isset($_POST['exportar_csv']) && empty($errores)) {
    if ($agrupar_por === 'todos') {
        foreach (['empleado', 'area', 'turno'] as $campo) {
            $resumenes[$campo] = obtenerResumen($conn, $campo, $condiciones, $tipos, $parametros);
        }
    } elseif (in_array($agrupar_por, ['empleado', 'area', 'turno'])) {
        $resumenes[$agrupar_por] = obtenerResumen($conn, $agrupar_por, $condiciones, $tipos, $parametros);
    }
}

// Búsqueda por orden
$historial_orden = [];
if (isset($_POST['buscar_orden'])) {
    $orden_buscar = trim($_POST['orden_buscar']);
    $stmt = $conn->prepare("
        SELECT empleado, area, turno, fecha
        FROM (
            SELECT empleado, area, turno, fecha, orden FROM produccion
            UNION ALL
            SELECT empleado, area, turno, fecha, orden FROM registros_antiguos
        ) AS todas
        WHERE orden = ?
        ORDER BY fecha DESC
    ");
    $stmt->bind_param("s", $orden_buscar);
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $historial_orden[] = $fila;
    }
    $stmt->close();
}

// =============================================
// SECCIÓN DE QUIEBRAS (similar a dashboard_admin_quiebras.php)
// =============================================
$filtros_quiebras = [
    'turno' => $_GET['turno_q'] ?? '',
    'responsable' => $_GET['responsable_q'] ?? '',
    'empleado' => $_GET['empleado_q'] ?? '',
    'equipo' => $_GET['equipo_q'] ?? '',
    'motivo' => $_GET['motivo_q'] ?? '',
    'fecha_inicio' => $_GET['fecha_inicio_q'] ?? '',
    'hora_inicio' => $_GET['hora_inicio_q'] ?? '',
    'ampm_inicio' => $_GET['ampm_inicio_q'] ?? 'AM',
    'fecha_fin' => $_GET['fecha_fin_q'] ?? '',
    'hora_fin' => $_GET['hora_fin_q'] ?? '',
    'ampm_fin' => $_GET['ampm_fin_q'] ?? 'PM',
    'id' => $_GET['id_q'] ?? '',
    'orden' => $_GET['orden_q'] ?? '',
    'agrupar_por' => $_GET['agrupar_por_q'] ?? '',
];

$agrupar_por_q = $filtros_quiebras['agrupar_por'];
$id_buscar_q = isset($_GET['id_q']) ? trim($_GET['id_q']) : '';
$orden_buscar_q = isset($_GET['orden_q']) ? trim($_GET['orden_q']) : '';

// Función para obtener quiebras filtradas
function obtenerQuiebrasFiltradas($conn, $filtros) {
    $filtrosActivos = false;
    foreach (['turno', 'responsable', 'empleado', 'equipo', 'motivo', 'fecha_inicio', 'fecha_fin', 'id', 'orden'] as $campo) {
        if (!empty($filtros[$campo]) && $filtros[$campo] != 'todos') {
            $filtrosActivos = true;
            break;
        }
    }
    if (!$filtrosActivos) {
        return [];
    }

    $sql = "SELECT * FROM registro_quiebras WHERE 1=1";
    $params = [];
    $types = "";

    foreach (['turno', 'responsable', 'empleado', 'equipo', 'motivo'] as $campo) {
        if (!empty($filtros[$campo]) && $filtros[$campo] != 'todos') {
            $sql .= " AND $campo = ?";
            $params[] = $filtros[$campo];
            $types .= "s";
        }
    }

    if (!empty($filtros['fecha_inicio'])) {
        $hora_inicio = !empty($filtros['hora_inicio']) && !empty($filtros['ampm_inicio'])
            ? date("H:i:s", strtotime($filtros['hora_inicio'] . " " . $filtros['ampm_inicio']))
            : '00:00:00';

        $fechaHoraInicio = $filtros['fecha_inicio'] . ' ' . $hora_inicio;
        $sql .= " AND CONCAT(fecha, ' ', hora) >= ?";
        $params[] = $fechaHoraInicio;
        $types .= "s";
    }

    if (!empty($filtros['fecha_fin'])) {
        $hora_fin = !empty($filtros['hora_fin']) && !empty($filtros['ampm_fin'])
            ? date("H:i:s", strtotime($filtros['hora_fin'] . " " . $filtros['ampm_fin']))
            : '23:59:59';

        $fechaHoraFin = $filtros['fecha_fin'] . ' ' . $hora_fin;
        $sql .= " AND CONCAT(fecha, ' ', hora) <= ?";
        $params[] = $fechaHoraFin;
        $types .= "s";
    }

    if (!empty($filtros['id'])) {
        $sql .= " AND id LIKE ?";
        $params[] = "%" . $filtros['id'] . "%";
        $types .= "s";
    }

    if (!empty($filtros['orden'])) {
        $sql .= " AND orden LIKE ?";
        $params[] = "%" . $filtros['orden'] . "%";
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $quiebras = [];

    while ($row = $result->fetch_assoc()) {
        $quiebras[] = $row;
    }

    return $quiebras;
}

$data_quiebras = obtenerQuiebrasFiltradas($conn, $filtros_quiebras);

// Preparar datos para gráficos de quiebras
$campos_para_graficos = ['turno', 'responsable', 'empleado', 'equipo', 'motivo'];
foreach ($campos_para_graficos as $campo) {
    ${"labels_q_$campo"} = [];
    ${"counts_q_$campo"} = [];

    $conteo = [];
    foreach ($data_quiebras as $row) {
        $valor = $row[$campo] ?? 'No especificado';
        $conteo[$valor] = ($conteo[$valor] ?? 0) + 1;
    }

    foreach ($conteo as $label => $count) {
        ${"labels_q_$campo"}[] = $label;
        ${"counts_q_$campo"}[] = $count;
    }
}

// Agrupar quiebras
$agrupados_q = [];
if ($agrupar_por_q && $agrupar_por_q != 'todo') {
    foreach ($data_quiebras as $row) {
        if (!empty($row[$agrupar_por_q])) {
            $clave = $row[$agrupar_por_q];
            $agrupados_q[$clave][] = $row;
        }
    }
} else {
    $agrupados_q['Todos'] = $data_quiebras;
}

$totalRegistros_q = count($data_quiebras);

// Obtener opciones para filtros de quiebras
$turnos_q = $conn->query("SELECT DISTINCT turno FROM registro_quiebras WHERE turno IS NOT NULL AND turno != ''")->fetch_all(MYSQLI_ASSOC);
$responsables_q = $conn->query("SELECT DISTINCT responsable FROM registro_quiebras WHERE responsable IS NOT NULL AND responsable != ''")->fetch_all(MYSQLI_ASSOC);
$empleados_q = $conn->query("SELECT DISTINCT empleado FROM registro_quiebras WHERE empleado IS NOT NULL AND empleado != ''")->fetch_all(MYSQLI_ASSOC);
$equipos_q = $conn->query("SELECT DISTINCT equipo FROM registro_quiebras WHERE equipo IS NOT NULL AND equipo != ''")->fetch_all(MYSQLI_ASSOC);
$motivos_q = $conn->query("SELECT DISTINCT motivo FROM registro_quiebras WHERE motivo IS NOT NULL AND motivo != ''")->fetch_all(MYSQLI_ASSOC);

// Eliminar quiebra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['eliminar_quiebra_id'])) {
    $idAEliminar = filter_var($_POST['eliminar_quiebra_id'], FILTER_SANITIZE_STRING);

    if ($stmtEliminar = $conn->prepare("DELETE FROM registro_quiebras WHERE id = ?")) {
        $stmtEliminar->bind_param("s", $idAEliminar);
        $stmtEliminar->execute();
        $stmtEliminar->close();

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true]);
            exit;
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=quiebras");
        exit;
    }
}

// =============================================
// SECCIÓN DE PAROS DE PRODUCCIÓN
// =============================================
$equipo_seleccionado = $_POST['equipo'] ?? '';
$fecha_inicio_paro = $_POST['fecha_inicio_paro'] ?? '';
$hora_inicio_paro = $_POST['hora_inicio_paro'] ?? '';
$ampm_inicio_paro = $_POST['ampm_inicio_paro'] ?? 'AM';
$fecha_fin_paro = $_POST['fecha_fin_paro'] ?? '';
$hora_fin_paro = $_POST['hora_fin_paro'] ?? '';
$ampm_fin_paro = $_POST['ampm_fin_paro'] ?? 'PM';
$mostrar_paros = isset($_POST['ver_paros']);
$exportar_csv_paros = isset($_POST['exportar_csv_paros']);

// Conversión de hora AM/PM a 24h
$hora_inicio_24_paro = date("H:i:s", strtotime("$hora_inicio_paro $ampm_inicio_paro"));
$hora_fin_24_paro = date("H:i:s", strtotime("$hora_fin_paro $ampm_fin_paro"));

$inicio_paro = (!empty($fecha_inicio_paro)) ? "$fecha_inicio_paro $hora_inicio_24_paro" : null;
$fin_paro = (!empty($fecha_fin_paro)) ? "$fecha_fin_paro $hora_fin_24_paro" : null;

// Si no hay filtros aplicados, usar la fecha actual
if (!$mostrar_paros && !$exportar_csv_paros && empty($equipo_seleccionado) && empty($fecha_inicio_paro) && empty($fecha_fin_paro)) {
    $hoy = date('Y-m-d');
    $inicio_paro = "$hoy 00:00:00";
    $fin_paro = "$hoy 23:59:59";
}

// Filtro WHERE para paros
$where_paro = [];

if (!empty($equipo_seleccionado)) {
    $equipo_esc = $conn->real_escape_string($equipo_seleccionado);
    $where_paro[] = "equipo = '$equipo_esc'";
}

if ($inicio_paro && $fin_paro) {
    $inicio_esc = $conn->real_escape_string($inicio_paro);
    $fin_esc = $conn->real_escape_string($fin_paro);
    $where_paro[] = "(
        (fecha_inicio BETWEEN '$inicio_esc' AND '$fin_esc') OR
        (fecha_fin BETWEEN '$inicio_esc' AND '$fin_esc') OR
        (fecha_inicio <= '$inicio_esc' AND fecha_fin >= '$fin_esc')
    )";
} elseif ($inicio_paro) {
    $inicio_esc = $conn->real_escape_string($inicio_paro);
    $where_paro[] = "fecha_fin >= '$inicio_esc'";
} elseif ($fin_paro) {
    $fin_esc = $conn->real_escape_string($fin_paro);
    $where_paro[] = "fecha_inicio <= '$fin_esc'";
}

$where_sql_paro = count($where_paro) ? 'WHERE ' . implode(' AND ', $where_paro) : '';
$sql_paros = "SELECT * FROM paro_produccion $where_sql_paro ORDER BY fecha_inicio DESC";
$result_paros = ($mostrar_paros || $exportar_csv_paros || empty($_POST)) ? $conn->query($sql_paros) : null;

// Función para mostrar fecha y hora en formato AM/PM
function formatoHoraAMPM($fechaHora) {
    if (!$fechaHora) return '';
    $dt = new DateTime($fechaHora);
    return $dt->format('Y-m-d h:i A');
}

// Obtener equipos para filtro
$equipos_paro = $conn->query("SELECT DISTINCT nombre_equipo FROM equipos ORDER BY nombre_equipo");

// =============================================
// HTML - DASHBOARD UNIFICADO
// =============================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Administrativo Unificado</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            max-width: 1200px;
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

        input, select, button {
            padding: 10px;
            margin: 8px 0;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            background-color: #d4edda;
            color: #155724;
            font-weight: bold;
        }

        input:focus, select:focus {
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

        /* Estilos para gráficos */
        .chart-container {
            background: #1e7e34;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }

        canvas {
            background: white;
            border-radius: 5px;
            padding: 10px;
        }

        /* Estilos para tabs internos */
        .inner-tabs {
            margin-top: 15px;
        }

        .inner-tab-btn {
            padding: 8px 15px;
            background: #1e7e34;
            color: white;
            border: none;
            cursor: pointer;
            margin-right: 5px;
            border-radius: 5px;
        }

        .inner-tab-btn.active {
            background: #28a745;
        }

        .inner-tab-content {
            display: none;
            padding: 15px;
            background: rgba(0, 0, 0, 0.15);
            border-radius: 0 0 5px 5px;
        }

        .inner-tab-content.active {
            display: block;
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
            width: 400px;
            max-width: 90%;
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

        /* Estilos para botones de acción */
        .btn-action {
            padding: 5px 10px;
            border-radius: 4px;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-modificar {
            background-color: #007bff;
        }

        .btn-eliminar {
            background-color: #dc3545;
        }

        .btn-modificar:hover {
            background-color: #0056b3;
        }

        .btn-eliminar:hover {
            background-color: #c82333;
        }
    </style>
</head>
<body>

<img src="/control_produccion/public/logo.png" alt="Logo" class="logo">

<div class="container">
    <h1>Dashboard Administrativo Unificado</h1>
    <h2>Bienvenid@, <?= htmlspecialchars($_SESSION['empleado']) ?> | <a href="logout.php" style="color: #c3e6cb;">Cerrar sesión</a></h2>

    <!-- Pestañas principales -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="produccion">Producción</button>
        <button class="tab-btn" data-tab="quiebras">Quiebras</button>
        <button class="tab-btn" data-tab="empleados">Empleados</button>
        <button class="tab-btn" data-tab="paros">Paros</button>
    </div>

    <!-- Contenido de pestañas -->
    <div id="produccion" class="tab-content active">
        <h2>Resumen de Producción</h2>
        
        <!-- Formulario de filtros -->
        <form method="POST">
            <div class="flex-row">
                <div class="flex-column">
                    <label>Agrupar por:</label>
                    <select name="agrupar_por">
                        <option value=""></option>
                        <option value="empleado" <?= ($agrupar_por === 'empleado') ? 'selected' : '' ?>>Empleado</option>
                        <option value="area" <?= ($agrupar_por === 'area') ? 'selected' : '' ?>>Área</option>
                        <option value="turno" <?= ($agrupar_por === 'turno') ? 'selected' : '' ?>>Turno</option>
                        <option value="todos" <?= ($agrupar_por === 'todos') ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Empleado:</label>
                    <select name="empleado">
                        <option value=""></option>
                        <?php 
                        $empleados_prod = $conn->query("SELECT DISTINCT empleado FROM (SELECT empleado FROM produccion UNION ALL SELECT empleado FROM registros_antiguos) AS empleados_total");
                        while ($row = $empleados_prod->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($row['empleado']) ?>" <?= ($empleado_seleccionado === $row['empleado']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($row['empleado']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Área:</label>
                    <select name="area">
                        <option value=""></option>
                        <?php 
                        $areas_prod = $conn->query("SELECT DISTINCT area FROM (SELECT area FROM produccion UNION ALL SELECT area FROM registros_antiguos) AS areas_total");
                        while ($row = $areas_prod->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($row['area']) ?>" <?= ($area_seleccionada === $row['area']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($row['area']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Turno:</label>
                    <select name="turno">
                        <option value=""></option>
                        <?php 
                        $turnos_prod = $conn->query("SELECT DISTINCT turno FROM (SELECT turno FROM produccion UNION ALL SELECT turno FROM registros_antiguos) AS turnos_total");
                        while ($row = $turnos_prod->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($row['turno']) ?>" <?= ($turno_seleccionado === $row['turno']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($row['turno']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <div class="flex-row">
                <div class="flex-column">
                    <label>Fecha Inicio:</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Hora Inicio:</label>
                    <input type="time" name="hora_inicio" value="<?= htmlspecialchars($hora_inicio) ?>">
                </div>
                
                <div class="flex-column">
                    <label>AM/PM:</label>
                    <select name="ampm_inicio">
                        <option value="AM" <?= ($ampm_inicio === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= ($ampm_inicio === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Fecha Fin:</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Hora Fin:</label>
                    <input type="time" name="hora_fin" value="<?= htmlspecialchars($hora_fin) ?>">
                </div>
                
                <div class="flex-column">
                    <label>AM/PM:</label>
                    <select name="ampm_fin">
                        <option value="AM" <?= ($ampm_fin === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= ($ampm_fin === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" name="buscar">Aplicar Filtros</button>
            <button type="submit" name="exportar_csv">Exportar CSV</button>
        </form>
        
        <!-- Resultados de producción -->
        <?php if ($hay_filtros && !empty($resumenes)): ?>
            <?php
            $rango_inicio = date('d/m/Y h:i A', strtotime("$fecha_inicio $hora_inicio $ampm_inicio"));
            $rango_fin = date('d/m/Y h:i A', strtotime("$fecha_fin $hora_fin $ampm_fin"));
            ?>
            
            <h3>Rango filtrado: del <?= $rango_inicio ?> al <?= $rango_fin ?></h3>
            
            <?php foreach ($resumenes as $tipo => $datos): ?>
                <div class="chart-container">
                    <h3>Resumen por <?= ucfirst($tipo) ?></h3>
                    
                    <div class="scrollable-table">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= ucfirst($tipo) ?></th>
                                    <th>Total de Órdenes</th>
                                    <?php if ($tipo === 'empleado'): ?>
                                        <th>Total de Quiebras</th>
                                        <th>% de Quiebras</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($datos as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row[$tipo]) ?></td>
                                        <td><?= $row['total_ordenes'] ?></td>
                                        <?php if ($tipo === 'empleado'): ?>
                                            <?php
                                            $total_quiebras = $conn->query("SELECT COUNT(*) AS total FROM registro_quiebras WHERE empleado = '".$conn->real_escape_string($row['empleado'])."' AND fecha BETWEEN '$fecha_inicio 00:00:00' AND '$fecha_fin 23:59:59'")->fetch_assoc()['total'];
                                            $porcentaje = ($row['total_ordenes'] + $total_quiebras) > 0 ? round(($total_quiebras / ($row['total_ordenes'] + $total_quiebras)) * 100, 2) : 0;
                                            ?>
                                            <td><?= $total_quiebras ?></td>
                                            <td><?= $porcentaje ?>%</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <canvas id="grafico_<?= $tipo ?>"></canvas>
                    <script>
                        const ctx_<?= $tipo ?> = document.getElementById('grafico_<?= $tipo ?>').getContext('2d');
                        new Chart(ctx_<?= $tipo ?>, {
                            type: 'bar',
                            data: {
                                labels: <?= json_encode(array_column($datos, $tipo)) ?>,
                                datasets: [{
                                    label: 'Total de Órdenes',
                                    data: <?= json_encode(array_column($datos, 'total_ordenes')) ?>,
                                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                scales: {
                                    y: {
                                        beginAtZero: true
                                    }
                                }
                            }
                        });
                    </script>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Búsqueda por orden -->
        <h3>Buscar historial por Orden</h3>
        <form method="POST">
            <input type="text" name="orden_buscar" placeholder="Ingrese número o código de orden" required>
            <button type="submit" name="buscar_orden">Buscar</button>
        </form>
        
        <?php if (!empty($historial_orden)): ?>
            <div class="scrollable-table">
                <table>
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Área</th>
                            <th>Turno</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial_orden as $fila): ?>
                            <tr>
                                <td><?= htmlspecialchars($fila['empleado']) ?></td>
                                <td><?= htmlspecialchars($fila['area']) ?></td>
                                <td><?= htmlspecialchars($fila['turno']) ?></td>
                                <td><?= date('d/m/Y h:i A', strtotime($fila['fecha'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (isset($_POST['buscar_orden'])): ?>
            <p>No se encontraron registros para esa orden.</p>
        <?php endif; ?>
    </div>

    <!-- Pestaña de Quiebras -->
    <div id="quiebras" class="tab-content">
        <h2>Registro de Quiebras</h2>
        
        <!-- Formulario de filtros -->
        <form method="GET">
            <input type="hidden" name="tab" value="quiebras">
            
            <div class="flex-row">
                <div class="flex-column">
                    <label>Agrupar por:</label>
                    <select name="agrupar_por_q">
                        <option value="todo" <?= ($agrupar_por_q === 'todo') ? 'selected' : '' ?>>Mostrar todo</option>
                        <option value="turno" <?= ($agrupar_por_q === 'turno') ? 'selected' : '' ?>>Turno</option>
                        <option value="responsable" <?= ($agrupar_por_q === 'responsable') ? 'selected' : '' ?>>Responsable</option>
                        <option value="empleado" <?= ($agrupar_por_q === 'empleado') ? 'selected' : '' ?>>Empleado</option>
                        <option value="equipo" <?= ($agrupar_por_q === 'equipo') ? 'selected' : '' ?>>Equipo</option>
                        <option value="motivo" <?= ($agrupar_por_q === 'motivo') ? 'selected' : '' ?>>Motivo</option>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Turno:</label>
                    <select name="turno_q">
                        <option value="">-- Todos --</option>
                        <?php foreach ($turnos_q as $turno): ?>
                            <option value="<?= htmlspecialchars($turno['turno']) ?>" <?= ($filtros_quiebras['turno'] === $turno['turno']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($turno['turno']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Responsable:</label>
                    <select name="responsable_q">
                        <option value="">-- Todos --</option>
                        <?php foreach ($responsables_q as $responsable): ?>
                            <option value="<?= htmlspecialchars($responsable['responsable']) ?>" <?= ($filtros_quiebras['responsable'] === $responsable['responsable']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($responsable['responsable']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="flex-row">
                <div class="flex-column">
                    <label>Empleado:</label>
                    <select name="empleado_q">
                        <option value="">-- Todos --</option>
                        <?php foreach ($empleados_q as $empleado): ?>
                            <option value="<?= htmlspecialchars($empleado['empleado']) ?>" <?= ($filtros_quiebras['empleado'] === $empleado['empleado']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($empleado['empleado']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Equipo:</label>
                    <select name="equipo_q">
                        <option value="">-- Todos --</option>
                        <?php foreach ($equipos_q as $equipo): ?>
                            <option value="<?= htmlspecialchars($equipo['equipo']) ?>" <?= ($filtros_quiebras['equipo'] === $equipo['equipo']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($equipo['equipo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Motivo:</label>
                    <select name="motivo_q">
                        <option value="">-- Todos --</option>
                        <?php foreach ($motivos_q as $motivo): ?>
                            <option value="<?= htmlspecialchars($motivo['motivo']) ?>" <?= ($filtros_quiebras['motivo'] === $motivo['motivo']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($motivo['motivo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="flex-row">
                <div class="flex-column">
                    <label>Fecha Inicio:</label>
                    <input type="date" name="fecha_inicio_q" value="<?= htmlspecialchars($filtros_quiebras['fecha_inicio']) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Hora Inicio:</label>
                    <input type="time" name="hora_inicio_q" value="<?= htmlspecialchars($filtros_quiebras['hora_inicio']) ?>">
                </div>
                
                <div class="flex-column">
                    <label>AM/PM:</label>
                    <select name="ampm_inicio_q">
                        <option value="AM" <?= ($filtros_quiebras['ampm_inicio'] === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= ($filtros_quiebras['ampm_inicio'] === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Fecha Fin:</label>
                    <input type="date" name="fecha_fin_q" value="<?= htmlspecialchars($filtros_quiebras['fecha_fin']) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Hora Fin:</label>
                    <input type="time" name="hora_fin_q" value="<?= htmlspecialchars($filtros_quiebras['hora_fin']) ?>">
                </div>
                
                <div class="flex-column">
                    <label>AM/PM:</label>
                    <select name="ampm_fin_q">
                        <option value="AM" <?= ($filtros_quiebras['ampm_fin'] === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= ($filtros_quiebras['ampm_fin'] === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
            </div>
            
            <div class="flex-row">
                <div class="flex-column">
                    <label>Buscar por ID:</label>
                    <input type="text" name="id_q" placeholder="ID de quiebra" value="<?= htmlspecialchars($filtros_quiebras['id']) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Buscar por Orden:</label>
                    <input type="text" name="orden_q" placeholder="Número de orden" value="<?= htmlspecialchars($filtros_quiebras['orden']) ?>">
                </div>
            </div>
            
            <button type="submit">Filtrar</button>
        </form>
        
        <!-- Resultados de quiebras -->
        <h3>Total de Quiebras: <?= $totalRegistros_q ?> registros</h3>
        
        <?php if (!empty($agrupados_q)): ?>
            <?php foreach ($agrupados_q as $grupo => $filas): ?>
                <div class="chart-container">
                    <h4><?= ($agrupar_por_q === 'todo' || empty($agrupar_por_q)) ? 'Todos' : htmlspecialchars($grupo) ?> (<?= count($filas) ?>)</h4>
                    
                    <div class="scrollable-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>N° Orden</th>
                                    <th>Turno</th>
                                    <th>Responsable</th>
                                    <th>Empleado</th>
                                    <th>Equipo</th>
                                    <th>Motivo</th>
                                    <th>Defecto</th>
                                    <th>Lado Lente</th>
                                    <th>Fecha y Hora</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filas as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['id']) ?></td>
                                        <td><?= htmlspecialchars($row['orden']) ?></td>
                                        <td><?= htmlspecialchars($row['turno']) ?></td>
                                        <td><?= htmlspecialchars($row['responsable']) ?></td>
                                        <td><?= htmlspecialchars($row['empleado']) ?></td>
                                        <td><?= htmlspecialchars($row['equipo']) ?></td>
                                        <td><?= htmlspecialchars($row['motivo']) ?></td>
                                        <td><?= htmlspecialchars($row['porque_defecto']) ?></td>
                                        <td><?= htmlspecialchars($row['lado_lente']) ?></td>
                                        <td><?= date('d/m/Y h:i A', strtotime($row['fecha'] . ' ' . $row['hora'])) ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('¿Eliminar esta quiebra?');">
                                                <input type="hidden" name="eliminar_quiebra_id" value="<?= htmlspecialchars($row['id']) ?>">
                                                <button type="submit" class="btn-action btn-eliminar">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Gráficos de quiebras -->
            <div class="chart-container">
                <h3>Gráficos de Quiebras</h3>
                
                <div class="flex-row">
                    <?php foreach ($campos_para_graficos as $campo): ?>
                        <div class="flex-column">
                            <canvas id="grafico_q_<?= $campo ?>" style="width:100%; height:300px;"></canvas>
                            <script>
                                const ctx_q_<?= $campo ?> = document.getElementById('grafico_q_<?= $campo ?>').getContext('2d');
                                new Chart(ctx_q_<?= $campo ?>, {
                                    type: 'pie',
                                    data: {
                                        labels: <?= json_encode(${"labels_q_$campo"}) ?>,
                                        datasets: [{
                                            data: <?= json_encode(${"counts_q_$campo"}) ?>,
                                            backgroundColor: [
                                                'rgba(255, 99, 132, 0.7)',
                                                'rgba(54, 162, 235, 0.7)',
                                                'rgba(255, 206, 86, 0.7)',
                                                'rgba(75, 192, 192, 0.7)',
                                                'rgba(153, 102, 255, 0.7)',
                                                'rgba(255, 159, 64, 0.7)'
                                            ]
                                        }]
                                    },
                                    options: {
                                        title: {
                                            display: true,
                                            text: 'Quiebras por <?= ucfirst($campo) ?>'
                                        }
                                    }
                                });
                            </script>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <p>No hay resultados para mostrar.</p>
        <?php endif; ?>
    </div>

    <!-- Pestaña de Empleados -->
    <div id="empleados" class="tab-content">
        <h2>Administración de Empleados</h2>
        
        <?php if ($mensaje_error): ?>
            <div class="mensaje-error"><?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>
        
        <?php if ($mensaje_exito): ?>
            <div class="mensaje-exito"><?= htmlspecialchars($mensaje_exito) ?></div>
        <?php endif; ?>
        
        <!-- Formulario para agregar empleado -->
        <h3>Agregar Empleado</h3>
        <form method="POST">
            <div class="flex-row">
                <div class="flex-column">
                    <label>Nombre del empleado:</label>
                    <input type="text" name="nombre_empleado" required>
                </div>
                
                <div class="flex-column">
                    <label>Código de empleado:</label>
                    <input type="text" name="codigo_empleado" required>
                </div>
                
                <div class="flex-column">
                    <label>Rol:</label>
                    <select name="rol" required>
                        <option value="empleado">Empleado</option>
                        <option value="administrador">Administrador</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" name="agregar_empleado">Agregar Empleado</button>
        </form>
        
        <!-- Lista de empleados -->
        <h3>Lista de Empleados</h3>
        <div class="scrollable-table">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Código</th>
                        <th>Rol</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($empleado = $empleados->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($empleado['nombre_empleado']) ?></td>
                            <td><?= htmlspecialchars($empleado['codigo_empleado']) ?></td>
                            <td><?= htmlspecialchars($empleado['rol']) ?></td>
                            <td>
                                <button class="btn-action btn-modificar" onclick="mostrarModalModificar(<?= $empleado['id'] ?>, '<?= htmlspecialchars($empleado['nombre_empleado']) ?>', '<?= htmlspecialchars($empleado['rol']) ?>')">
                                    Modificar Rol
                                </button>
                                <a href="?eliminar=<?= $empleado['id'] ?>" class="btn-action btn-eliminar" onclick="return confirm('¿Eliminar este empleado?');">
                                    Eliminar
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Modal para modificar rol -->
        <div id="modalModificarRol" class="modal">
            <div class="modal-content">
                <h3>Modificar Rol</h3>
                <form method="POST">
                    <input type="hidden" name="id_empleado" id="idEmpleadoModal">
                    <p>Empleado: <strong id="nombreEmpleadoModal"></strong></p>
                    
                    <label>Nuevo Rol:</label>
                    <select name="nuevo_rol" id="nuevoRolModal" required>
                        <option value="empleado">Empleado</option>
                        <option value="administrador">Administrador</option>
                    </select>
                    
                    <div style="margin-top: 15px;">
                        <button type="submit" name="modificar_rol">Guardar Cambios</button>
                        <button type="button" class="close-btn" onclick="cerrarModalModificar()">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pestaña de Paros de Producción -->
    <div id="paros" class="tab-content">
        <h2>Registro de Paros de Producción</h2>
        
        <!-- Formulario de filtros -->
        <form method="POST">
            <div class="flex-row">
                <div class="flex-column">
                    <label>Equipo:</label>
                    <select name="equipo">
                        <option value="">-- Todos --</option>
                        <?php while ($eq = $equipos_paro->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($eq['nombre_equipo']) ?>" <?= ($equipo_seleccionado === $eq['nombre_equipo']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($eq['nombre_equipo']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Fecha Inicio:</label>
                    <input type="date" name="fecha_inicio_paro" value="<?= htmlspecialchars($fecha_inicio_paro) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Hora Inicio:</label>
                    <input type="time" name="hora_inicio_paro" value="<?= htmlspecialchars($hora_inicio_paro) ?>">
                </div>
                
                <div class="flex-column">
                    <label>AM/PM:</label>
                    <select name="ampm_inicio_paro">
                        <option value="AM" <?= ($ampm_inicio_paro === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= ($ampm_inicio_paro === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
                
                <div class="flex-column">
                    <label>Fecha Fin:</label>
                    <input type="date" name="fecha_fin_paro" value="<?= htmlspecialchars($fecha_fin_paro) ?>">
                </div>
                
                <div class="flex-column">
                    <label>Hora Fin:</label>
                    <input type="time" name="hora_fin_paro" value="<?= htmlspecialchars($hora_fin_paro) ?>">
                </div>
                
                <div class="flex-column">
                    <label>AM/PM:</label>
                    <select name="ampm_fin_paro">
                        <option value="AM" <?= ($ampm_fin_paro === 'AM') ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= ($ampm_fin_paro === 'PM') ? 'selected' : '' ?>>PM</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" name="ver_paros">Ver Paros</button>
            <button type="submit" name="exportar_csv_paros">Exportar CSV</button>
        </form>
        
        <!-- Resultados de paros -->
        <?php if ($result_paros && $result_paros->num_rows > 0): ?>
            <div class="scrollable-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empleado</th>
                            <th>Área</th>
                            <th>Equipo</th>
                            <th>Motivo</th>
                            <th>Fecha Inicio</th>
                            <th>Fecha Fin</th>
                            <th>Activo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result_paros->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['id']) ?></td>
                                <td><?= htmlspecialchars($row['empleado']) ?></td>
                                <td><?= htmlspecialchars($row['area']) ?></td>
                                <td><?= htmlspecialchars($row['equipo']) ?></td>
                                <td><?= htmlspecialchars($row['motivo']) ?></td>
                                <td><?= formatoHoraAMPM($row['fecha_inicio']) ?></td>
                                <td><?= formatoHoraAMPM($row['fecha_fin']) ?></td>
                                <td><?= $row['activo'] ? 'Sí' : 'No' ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($mostrar_paros): ?>
            <p>No se encontraron registros con los filtros aplicados.</p>
        <?php endif; ?>
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
        btn.addEventListener('click', function() {
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
            
            // Guardar en localStorage la pestaña activa
            localStorage.setItem('tabActiva', tabId);
        });
    });
    
    // Al cargar la página, mostrar la pestaña guardada o la primera
    window.addEventListener('DOMContentLoaded', function() {
        const tabActiva = localStorage.getItem('tabActiva') || 'produccion';
        document.getElementById(tabActiva).classList.add('active');
        document.querySelector(`.tab-btn[data-tab="${tabActiva}"]`).classList.add('active');
    });
    
    // Funciones para el modal de modificación de empleados
    function mostrarModalModificar(id, nombre, rol) {
        document.getElementById('idEmpleadoModal').value = id;
        document.getElementById('nombreEmpleadoModal').textContent = nombre;
        document.getElementById('nuevoRolModal').value = rol;
        document.getElementById('modalModificarRol').style.display = 'flex';
    }
    
    function cerrarModalModificar() {
        document.getElementById('modalModificarRol').style.display = 'none';
    }
    
    // Cerrar modal al hacer clic fuera
    window.addEventListener('click', function(event) {
        if (event.target === document.getElementById('modalModificarRol')) {
            cerrarModalModificar();
        }
    });
    
    // Confirmación para eliminar quiebras
    document.querySelectorAll('form[data-orden]').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('¿Estás seguro de eliminar este registro?')) {
                e.preventDefault();
            }
        });
    });
</script>

</body>
</html>