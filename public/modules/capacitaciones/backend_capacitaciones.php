<?php
// ============================================================
// backend_capacitaciones.php
// Backend del módulo de Capacitaciones y Evaluaciones TAO
// Compatible con la arquitectura existente de control_produccion
// ============================================================

session_start();
set_time_limit(300);

// Verificar autenticación (igual que backend.php existente)
if (!isset($_SESSION['empleado'], $_SESSION['rol'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit();
}

require_once '../config/database.php';
date_default_timezone_set('America/Costa_Rica');

$conn->set_charset("utf8mb4");

$action = $_REQUEST['action'] ?? '';
$response = ['success' => false, 'error' => 'Acción no reconocida'];

// ============================================================
// ROUTER DE ACCIONES
// ============================================================
switch ($action) {
    // ---- CATÁLOGO DE CURSOS ----
    case 'listar_cursos':
        $response = listarCursos($conn);
        break;

    case 'detalle_curso':
        $response = detalleCurso($conn, (int)($_REQUEST['id'] ?? 0));
        break;

    case 'guardar_curso':
        checkAdmin();
        $response = guardarCurso($conn, $_POST);
        break;

    case 'eliminar_curso':
        checkAdmin();
        $response = eliminarCurso($conn, (int)($_POST['id'] ?? 0));
        break;

    // ---- MÓDULOS DE CURSO ----
    case 'listar_modulos':
        $response = listarModulos($conn, (int)($_REQUEST['capacitacion_id'] ?? 0));
        break;

    case 'guardar_modulo':
        checkAdmin();
        $response = guardarModulo($conn, $_POST);
        break;

    case 'eliminar_modulo':
        checkAdmin();
        $response = eliminarModulo($conn, (int)($_POST['id'] ?? 0));
        break;

    // ---- PROGRESO DEL EMPLEADO ----
    case 'iniciar_curso':
        $response = iniciarCurso($conn, $_POST);
        break;

    case 'completar_modulo':
        $response = completarModulo($conn, $_POST);
        break;

    case 'progreso_empleado':
        $response = progresoEmpleado($conn, $_SESSION['empleado']);
        break;

    // ---- EVALUACIONES / TESTS ----
    case 'obtener_preguntas':
        $response = obtenerPreguntas($conn, (int)($_REQUEST['capacitacion_id'] ?? 0));
        break;

    case 'enviar_test':
        $response = enviarTest($conn, $_POST);
        break;

    case 'historial_tests':
        $response = historialTests($conn, $_SESSION['empleado']);
        break;

    // ---- EVALUACIÓN TAO PQCDSIM ----
    case 'obtener_preguntas_tao':
        $response = obtenerPreguntasTAO($conn);
        break;

    case 'enviar_evaluacion_tao':
        $response = enviarEvaluacionTAO($conn, $_POST);
        break;

    case 'resultado_tao':
        $response = resultadoTAO($conn, (int)($_REQUEST['id'] ?? 0));
        break;

    case 'historial_tao_empleado':
        $response = historialTAOEmpleado($conn, $_SESSION['empleado']);
        break;

    // ---- SEDAC ----
    case 'listar_tickets_sedac':
        $response = listarTicketsSEDAC($conn);
        break;

    case 'crear_ticket_sedac':
        $response = crearTicketSEDAC($conn, $_POST);
        break;

    case 'actualizar_ticket_sedac':
        $response = actualizarTicketSEDAC($conn, $_POST);
        break;

    // ---- CUE CARDS ----
    case 'preguntas_cue':
        $response = preguntasCue($conn);
        break;

    case 'enviar_cue':
        $response = enviarCue($conn, $_POST);
        break;

    // ---- DASHBOARD ADMIN ----
    case 'stats_dashboard_capacitaciones':
        checkAdmin();
        $response = statsDashboard($conn);
        break;

    case 'ranking_empleados':
        $response = rankingEmpleados($conn);
        break;

    case 'reporte_area':
        checkAdmin();
        $response = reporteArea($conn, $_REQUEST['area'] ?? '');
        break;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();


// ============================================================
// HELPER: Verificar rol admin
// ============================================================
function checkAdmin(): void
{
    if (($_SESSION['rol'] ?? '') !== 'administrador') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Sin permisos de administrador']);
        exit();
    }
}


// ============================================================
// FUNCIONES — CURSOS
// ============================================================

function listarCursos(mysqli $conn): array
{
    $empleado = $_SESSION['empleado'] ?? '';

    $sql = "SELECT c.*,
                   COALESCE(p.estado, 'pendiente') AS mi_progreso,
                   COALESCE(p.puntaje, 0)           AS mi_puntaje,
                   (SELECT COUNT(*) FROM modulos_capacitacion m WHERE m.capacitacion_id = c.id) AS total_modulos
            FROM capacitaciones c
            LEFT JOIN progreso_capacitacion p
                   ON p.capacitacion_id = c.id AND p.empleado = ? AND p.modulo_id IS NULL
            WHERE c.activo = 1
            ORDER BY c.nivel, c.titulo";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $empleado);
    $stmt->execute();
    $result = $stmt->get_result();

    $cursos = [];
    while ($row = $result->fetch_assoc()) {
        $cursos[] = $row;
    }

    return ['success' => true, 'data' => $cursos];
}


function detalleCurso(mysqli $conn, int $id): array
{
    if ($id <= 0) return ['success' => false, 'error' => 'ID inválido'];

    $stmt = $conn->prepare("SELECT * FROM capacitaciones WHERE id = ? AND activo = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $curso = $stmt->get_result()->fetch_assoc();

    if (!$curso) return ['success' => false, 'error' => 'Curso no encontrado'];

    // Módulos
    $stmt2 = $conn->prepare(
        "SELECT * FROM modulos_capacitacion WHERE capacitacion_id = ? ORDER BY orden"
    );
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $modulos = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    $curso['modulos'] = $modulos;
    return ['success' => true, 'data' => $curso];
}


function guardarCurso(mysqli $conn, array $data): array
{
    $id          = (int)($data['id'] ?? 0);
    $titulo      = trim($data['titulo'] ?? '');
    $descripcion = trim($data['descripcion'] ?? '');
    $tipo        = $data['tipo'] ?? 'curso';
    $duracion    = (int)($data['duracion_min'] ?? 0);
    $nivel       = $data['nivel'] ?? 'basico';
    $area        = trim($data['area'] ?? 'General');
    $creado_por  = $_SESSION['empleado'];

    if (empty($titulo)) return ['success' => false, 'error' => 'El título es obligatorio'];

    if ($id > 0) {
        $stmt = $conn->prepare(
            "UPDATE capacitaciones SET titulo=?, descripcion=?, tipo=?, duracion_min=?, nivel=?, area=? WHERE id=?"
        );
        $stmt->bind_param('sssiisi', $titulo, $descripcion, $tipo, $duracion, $nivel, $area, $id);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO capacitaciones (titulo,descripcion,tipo,duracion_min,nivel,area,creado_por) VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('sssiiss', $titulo, $descripcion, $tipo, $duracion, $nivel, $area, $creado_por);
    }

    $stmt->execute();
    $insertId = $id > 0 ? $id : $conn->insert_id;

    return ['success' => true, 'id' => $insertId, 'message' => 'Curso guardado correctamente'];
}


function eliminarCurso(mysqli $conn, int $id): array
{
    if ($id <= 0) return ['success' => false, 'error' => 'ID inválido'];
    $stmt = $conn->prepare("UPDATE capacitaciones SET activo=0 WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return ['success' => true, 'message' => 'Curso desactivado'];
}


// ============================================================
// FUNCIONES — MÓDULOS
// ============================================================

function listarModulos(mysqli $conn, int $capacitacion_id): array
{
    $stmt = $conn->prepare(
        "SELECT * FROM modulos_capacitacion WHERE capacitacion_id = ? ORDER BY orden"
    );
    $stmt->bind_param('i', $capacitacion_id);
    $stmt->execute();
    $modulos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return ['success' => true, 'data' => $modulos];
}


function guardarModulo(mysqli $conn, array $data): array
{
    $id             = (int)($data['id'] ?? 0);
    $cap_id         = (int)($data['capacitacion_id'] ?? 0);
    $orden          = (int)($data['orden'] ?? 1);
    $titulo         = trim($data['titulo'] ?? '');
    $tipo_contenido = $data['tipo_contenido'] ?? 'texto';
    $contenido      = trim($data['contenido'] ?? '');
    $duracion       = (int)($data['duracion_min'] ?? 0);

    if (empty($titulo) || $cap_id <= 0) {
        return ['success' => false, 'error' => 'Datos incompletos'];
    }

    if ($id > 0) {
        $stmt = $conn->prepare(
            "UPDATE modulos_capacitacion SET orden=?,titulo=?,tipo_contenido=?,contenido=?,duracion_min=? WHERE id=?"
        );
        $stmt->bind_param('isssii', $orden, $titulo, $tipo_contenido, $contenido, $duracion, $id);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO modulos_capacitacion (capacitacion_id,orden,titulo,tipo_contenido,contenido,duracion_min) VALUES (?,?,?,?,?,?)"
        );
        $stmt->bind_param('iisssi', $cap_id, $orden, $titulo, $tipo_contenido, $contenido, $duracion);
    }

    $stmt->execute();
    return ['success' => true, 'message' => 'Módulo guardado'];
}


function eliminarModulo(mysqli $conn, int $id): array
{
    if ($id <= 0) return ['success' => false, 'error' => 'ID inválido'];
    $stmt = $conn->prepare("DELETE FROM modulos_capacitacion WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return ['success' => true, 'message' => 'Módulo eliminado'];
}


// ============================================================
// FUNCIONES — PROGRESO
// ============================================================

function iniciarCurso(mysqli $conn, array $data): array
{
    $empleado       = $_SESSION['empleado'];
    $capacitacion_id = (int)($data['capacitacion_id'] ?? 0);

    if ($capacitacion_id <= 0) return ['success' => false, 'error' => 'ID inválido'];

    // Verificar si ya existe
    $stmt = $conn->prepare(
        "SELECT id FROM progreso_capacitacion WHERE empleado=? AND capacitacion_id=? AND modulo_id IS NULL"
    );
    $stmt->bind_param('si', $empleado, $capacitacion_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => true, 'message' => 'Ya iniciado previamente'];
    }

    $stmt = $conn->prepare(
        "INSERT INTO progreso_capacitacion (empleado, capacitacion_id, estado, fecha_inicio)
         VALUES (?, ?, 'en_progreso', NOW())"
    );
    $stmt->bind_param('si', $empleado, $capacitacion_id);
    $stmt->execute();

    return ['success' => true, 'message' => 'Curso iniciado'];
}


function completarModulo(mysqli $conn, array $data): array
{
    $empleado        = $_SESSION['empleado'];
    $capacitacion_id = (int)($data['capacitacion_id'] ?? 0);
    $modulo_id       = (int)($data['modulo_id'] ?? 0);

    $stmt = $conn->prepare(
        "INSERT INTO progreso_capacitacion (empleado, capacitacion_id, modulo_id, estado, fecha_completado)
         VALUES (?, ?, ?, 'completado', NOW())
         ON DUPLICATE KEY UPDATE estado='completado', fecha_completado=NOW()"
    );
    $stmt->bind_param('sii', $empleado, $capacitacion_id, $modulo_id);
    $stmt->execute();

    // Verificar si completó todos los módulos
    $stmt2 = $conn->prepare(
        "SELECT COUNT(*) as total FROM modulos_capacitacion WHERE capacitacion_id=?"
    );
    $stmt2->bind_param('i', $capacitacion_id);
    $stmt2->execute();
    $total = $stmt2->get_result()->fetch_assoc()['total'];

    $stmt3 = $conn->prepare(
        "SELECT COUNT(*) as completados FROM progreso_capacitacion
         WHERE empleado=? AND capacitacion_id=? AND modulo_id IS NOT NULL AND estado='completado'"
    );
    $stmt3->bind_param('si', $empleado, $capacitacion_id);
    $stmt3->execute();
    $completados = $stmt3->get_result()->fetch_assoc()['completados'];

    $cursoCerrado = false;
    if ($total > 0 && $completados >= $total) {
        $stmt4 = $conn->prepare(
            "UPDATE progreso_capacitacion SET estado='completado', fecha_completado=NOW()
             WHERE empleado=? AND capacitacion_id=? AND modulo_id IS NULL"
        );
        $stmt4->bind_param('si', $empleado, $capacitacion_id);
        $stmt4->execute();
        $cursoCerrado = true;
    }

    return [
        'success'       => true,
        'curso_cerrado' => $cursoCerrado,
        'progreso'      => $total > 0 ? round(($completados / $total) * 100) : 0
    ];
}


function progresoEmpleado(mysqli $conn, string $empleado): array
{
    $sql = "SELECT p.*, c.titulo, c.tipo, c.nivel, c.duracion_min
            FROM progreso_capacitacion p
            JOIN capacitaciones c ON c.id = p.capacitacion_id
            WHERE p.empleado = ? AND p.modulo_id IS NULL
            ORDER BY p.fecha_inicio DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $empleado);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return ['success' => true, 'data' => $data];
}


// ============================================================
// FUNCIONES — TESTS / EVALUACIONES
// ============================================================

function obtenerPreguntas(mysqli $conn, int $capacitacion_id): array
{
    $stmt = $conn->prepare(
        "SELECT * FROM preguntas_evaluacion WHERE capacitacion_id=? AND activa=1 ORDER BY RAND()"
    );
    $stmt->bind_param('i', $capacitacion_id);
    $stmt->execute();
    $preguntas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Ocultamos respuesta correcta
    foreach ($preguntas as &$p) {
        unset($p['respuesta_correcta']);
    }

    return ['success' => true, 'data' => $preguntas];
}


function enviarTest(mysqli $conn, array $data): array
{
    $empleado        = $_SESSION['empleado'];
    $capacitacion_id = (int)($data['capacitacion_id'] ?? 0);
    $respuestas      = json_decode($data['respuestas'] ?? '[]', true);

    if (empty($respuestas) || $capacitacion_id <= 0) {
        return ['success' => false, 'error' => 'Datos incompletos'];
    }

    $correctas = 0;
    $total     = 0;

    foreach ($respuestas as $item) {
        $pregunta_id = (int)($item['pregunta_id'] ?? 0);
        $respuesta   = strtolower(trim($item['respuesta'] ?? ''));

        // Obtener respuesta correcta
        $stmt = $conn->prepare(
            "SELECT respuesta_correcta, peso FROM preguntas_evaluacion WHERE id=?"
        );
        $stmt->bind_param('i', $pregunta_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) continue;

        $es_correcta = ($respuesta === $row['respuesta_correcta']) ? 1 : 0;
        $correctas  += $es_correcta * $row['peso'];
        $total      += $row['peso'];

        // Guardar respuesta
        $stmt2 = $conn->prepare(
            "INSERT INTO respuestas_test (empleado, capacitacion_id, pregunta_id, respuesta, es_correcta)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('siisi', $empleado, $capacitacion_id, $pregunta_id, $respuesta, $es_correcta);
        $stmt2->execute();
    }

    $puntaje  = $total > 0 ? round(($correctas / $total) * 100, 2) : 0;
    $aprobado = $puntaje >= 70;

    // Actualizar progreso
    $estado = $aprobado ? 'completado' : 'en_progreso';
    $stmt3  = $conn->prepare(
        "INSERT INTO progreso_capacitacion (empleado, capacitacion_id, estado, puntaje, fecha_completado, intentos)
         VALUES (?, ?, ?, ?, NOW(), 1)
         ON DUPLICATE KEY UPDATE estado=?, puntaje=GREATEST(puntaje,?), fecha_completado=NOW(), intentos=intentos+1"
    );
    $stmt3->bind_param('sisdsd', $empleado, $capacitacion_id, $estado, $puntaje, $estado, $puntaje);
    $stmt3->execute();

    // Generar certificado si aprobó
    $folio = null;
    if ($aprobado) {
        $folio = generarFolio($empleado, $capacitacion_id);
        $stmt4 = $conn->prepare(
            "INSERT IGNORE INTO certificados (empleado, capacitacion_id, folio, fecha_emision)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt4->bind_param('sis', $empleado, $capacitacion_id, $folio);
        $stmt4->execute();
    }

    return [
        'success'  => true,
        'puntaje'  => $puntaje,
        'aprobado' => $aprobado,
        'folio'    => $folio,
        'mensaje'  => $aprobado
            ? "¡Felicidades! Obtuviste {$puntaje}% y aprobaste el test."
            : "Obtuviste {$puntaje}%. Se requiere 70% para aprobar. Puedes volver a intentarlo."
    ];
}


function historialTests(mysqli $conn, string $empleado): array
{
    $sql = "SELECT p.*, c.titulo, c.tipo
            FROM progreso_capacitacion p
            JOIN capacitaciones c ON c.id = p.capacitacion_id
            WHERE p.empleado = ? AND p.modulo_id IS NULL
            ORDER BY p.fecha_completado DESC
            LIMIT 20";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $empleado);
    $stmt->execute();
    return ['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
}


// ============================================================
// FUNCIONES — EVALUACIÓN TAO PQCDSIM
// ============================================================

function obtenerPreguntasTAO(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT id, categoria, pregunta, tipo_pregunta, opcion_a, opcion_b, opcion_c, opcion_d
         FROM preguntas_evaluacion
         WHERE capacitacion_id = 2 AND activa = 1
         ORDER BY categoria, id"
    );
    $stmt->execute();
    return ['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
}


function enviarEvaluacionTAO(mysqli $conn, array $data): array
{
    $empleado  = $_SESSION['empleado'];
    $area      = trim($data['area'] ?? '');
    $respuestas = json_decode($data['respuestas'] ?? '[]', true);

    if (empty($respuestas)) {
        return ['success' => false, 'error' => 'No se recibieron respuestas'];
    }

    // Mapa de dimensión → puntaje
    $dimensiones = [
        'Productividad' => 0, 'Calidad'       => 0, 'Costo'          => 0,
        'Entrega'       => 0, 'Seguridad'      => 0, 'Información'    => 0,
        'Moral'         => 0, 'Comunicación'   => 0, 'Liderazgo'      => 0,
        'Trabajo en Equipo' => 0,
    ];
    $totales     = array_fill_keys(array_keys($dimensiones), 0);

    foreach ($respuestas as $item) {
        $pregunta_id = (int)($item['pregunta_id'] ?? 0);
        $respuesta   = strtolower(trim($item['respuesta'] ?? ''));

        $stmt = $conn->prepare(
            "SELECT categoria, respuesta_correcta, peso FROM preguntas_evaluacion WHERE id=?"
        );
        $stmt->bind_param('i', $pregunta_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) continue;

        $cat = $row['categoria'];
        // Escala según opción: a=1, b=2, c=3, d=4 (madurez)
        $escala = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        $valor  = $escala[$respuesta] ?? 0;

        if (isset($dimensiones[$cat])) {
            $dimensiones[$cat] += $valor * $row['peso'];
            $totales[$cat]     += 4 * $row['peso']; // máximo posible
        }
    }

    // Convertir a porcentaje (0-100)
    foreach ($dimensiones as $cat => &$val) {
        $val = $totales[$cat] > 0 ? round(($val / $totales[$cat]) * 100, 2) : 0;
    }

    $promedio = count($dimensiones) > 0 ? round(array_sum($dimensiones) / count($dimensiones), 2) : 0;

    // Nivel de alineación
    $nivel = match (true) {
        $promedio >= 85 => 'Excelente',
        $promedio >= 70 => 'Alto',
        $promedio >= 50 => 'Medio',
        $promedio >= 30 => 'Bajo',
        default         => 'Critico',
    };

    // Recomendaciones automáticas
    $recomendaciones = generarRecomendaciones($dimensiones, $promedio);

    $stmt = $conn->prepare(
        "INSERT INTO resultados_tao
         (empleado, area, puntaje_total, puntaje_maximo,
          dim_productividad, dim_calidad, dim_costo, dim_entrega,
          dim_seguridad, dim_informacion, dim_moral,
          comp_liderazgo, comp_comunicacion, comp_trabajo_en_equipo,
          comp_alineacion_organizacional, recomendaciones, nivel_alineacion)
         VALUES (?,?,?,100,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    $stmt->bind_param(
        'ssiidddddddddddss',
        $empleado, $area, $promedio,
        $dimensiones['Productividad'], $dimensiones['Calidad'],
        $dimensiones['Costo'],         $dimensiones['Entrega'],
        $dimensiones['Seguridad'],     $dimensiones['Información'],
        $dimensiones['Moral'],         $dimensiones['Liderazgo'],
        $dimensiones['Comunicación'],  $dimensiones['Trabajo en Equipo'],
        $promedio, $recomendaciones, $nivel
    );
    $stmt->execute();
    $insertId = $conn->insert_id;

    return [
        'success'          => true,
        'id'               => $insertId,
        'promedio'         => $promedio,
        'nivel'            => $nivel,
        'dimensiones'      => $dimensiones,
        'recomendaciones'  => $recomendaciones,
    ];
}


function resultadoTAO(mysqli $conn, int $id): array
{
    $stmt = $conn->prepare("SELECT * FROM resultados_tao WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return ['success' => false, 'error' => 'Resultado no encontrado'];
    return ['success' => true, 'data' => $row];
}


function historialTAOEmpleado(mysqli $conn, string $empleado): array
{
    $stmt = $conn->prepare(
        "SELECT id, fecha_evaluacion, puntaje_total, nivel_alineacion, area
         FROM resultados_tao WHERE empleado=? ORDER BY fecha_evaluacion DESC LIMIT 10"
    );
    $stmt->bind_param('s', $empleado);
    $stmt->execute();
    return ['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
}


// ============================================================
// FUNCIONES — SEDAC
// ============================================================

function listarTicketsSEDAC(mysqli $conn): array
{
    $empleado = $_SESSION['empleado'];
    $rol      = $_SESSION['rol'] ?? '';

    if ($rol === 'administrador') {
        $result = $conn->query("SELECT * FROM sedac_tickets ORDER BY fecha_creacion DESC LIMIT 50");
    } else {
        $stmt = $conn->prepare(
            "SELECT * FROM sedac_tickets
             WHERE creado_por=? OR asignado_a=?
             ORDER BY fecha_creacion DESC LIMIT 50"
        );
        $stmt->bind_param('ss', $empleado, $empleado);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    return ['success' => true, 'data' => $result->fetch_all(MYSQLI_ASSOC)];
}


function crearTicketSEDAC(mysqli $conn, array $data): array
{
    $empleado  = $_SESSION['empleado'];
    $titulo    = trim($data['titulo']    ?? '');
    $problema  = trim($data['problema']  ?? '');
    $area      = trim($data['area']      ?? '');
    $prioridad = $data['prioridad']       ?? 'media';

    if (empty($titulo) || empty($problema)) {
        return ['success' => false, 'error' => 'Título y problema son obligatorios'];
    }

    $stmt = $conn->prepare(
        "INSERT INTO sedac_tickets (titulo, problema, area, creado_por, prioridad)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sssss', $titulo, $problema, $area, $empleado, $prioridad);
    $stmt->execute();

    return ['success' => true, 'id' => $conn->insert_id, 'message' => 'Ticket SEDAC creado'];
}


function actualizarTicketSEDAC(mysqli $conn, array $data): array
{
    $id          = (int)($data['id'] ?? 0);
    $estado      = $data['estado']      ?? '';
    $solucion    = trim($data['solucion']    ?? '');
    $causa_raiz  = trim($data['causa_raiz']  ?? '');
    $comentarios = trim($data['comentarios'] ?? '');

    if ($id <= 0) return ['success' => false, 'error' => 'ID inválido'];

    $cierre = $estado === 'cerrado' ? ', fecha_cierre=NOW()' : '';

    $stmt = $conn->prepare(
        "UPDATE sedac_tickets
         SET estado=?, solucion=?, causa_raiz=?, comentarios=? $cierre
         WHERE id=?"
    );
    $stmt->bind_param('ssssi', $estado, $solucion, $causa_raiz, $comentarios, $id);
    $stmt->execute();

    return ['success' => true, 'message' => 'Ticket actualizado'];
}


// ============================================================
// FUNCIONES — CUE CARDS
// ============================================================

function preguntasCue(mysqli $conn): array
{
    $result = $conn->query("SELECT * FROM cue_preguntas WHERE activa=1 ORDER BY RAND() LIMIT 3");
    return ['success' => true, 'data' => $result->fetch_all(MYSQLI_ASSOC)];
}


function enviarCue(mysqli $conn, array $data): array
{
    $empleado   = $_SESSION['empleado'];
    $respuestas = json_decode($data['respuestas'] ?? '[]', true);

    foreach ($respuestas as $item) {
        $pregunta_id = (int)($item['pregunta_id'] ?? 0);
        $respuesta   = trim($item['respuesta'] ?? '');
        $comentario  = trim($item['comentario'] ?? '');

        $stmt = $conn->prepare(
            "INSERT INTO cue_respuestas (empleado, pregunta_id, respuesta, comentario)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('siss', $empleado, $pregunta_id, $respuesta, $comentario);
        $stmt->execute();
    }

    return ['success' => true, 'message' => 'Feedback registrado. ¡Gracias!'];
}


// ============================================================
// FUNCIONES — DASHBOARD ADMIN
// ============================================================

function statsDashboard(mysqli $conn): array
{
    // Total empleados capacitados (con al menos 1 curso completado)
    $r1 = $conn->query(
        "SELECT COUNT(DISTINCT empleado) AS total
         FROM progreso_capacitacion WHERE estado IN ('completado','certificado') AND modulo_id IS NULL"
    )->fetch_assoc();

    // Total certificados emitidos
    $r2 = $conn->query("SELECT COUNT(*) AS total FROM certificados")->fetch_assoc();

    // Promedio TAO global
    $r3 = $conn->query(
        "SELECT ROUND(AVG(puntaje_total),1) AS promedio,
                MAX(puntaje_total) AS maximo,
                MIN(puntaje_total) AS minimo
         FROM resultados_tao WHERE DATE(fecha_evaluacion) >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
    )->fetch_assoc();

    // Cursos activos
    $r4 = $conn->query("SELECT COUNT(*) AS total FROM capacitaciones WHERE activo=1")->fetch_assoc();

    // Tickets SEDAC por estado
    $r5 = $conn->query(
        "SELECT estado, COUNT(*) AS total FROM sedac_tickets GROUP BY estado"
    )->fetch_all(MYSQLI_ASSOC);

    // Evolución TAO últimos 30 días
    $r6 = $conn->query(
        "SELECT DATE_FORMAT(fecha_evaluacion,'%d/%m') AS fecha,
                ROUND(AVG(puntaje_total),1)            AS promedio
         FROM resultados_tao
         WHERE fecha_evaluacion >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(fecha_evaluacion)
         ORDER BY fecha_evaluacion"
    )->fetch_all(MYSQLI_ASSOC);

    // Top 5 áreas por puntaje TAO
    $r7 = $conn->query(
        "SELECT area, ROUND(AVG(puntaje_total),1) AS prom
         FROM resultados_tao
         WHERE area != ''
         GROUP BY area
         ORDER BY prom DESC LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);

    return [
        'success'              => true,
        'empleados_capacitados' => (int)$r1['total'],
        'certificados'          => (int)$r2['total'],
        'tao_promedio'          => $r3['promedio'] ?? 0,
        'tao_max'               => $r3['maximo'] ?? 0,
        'tao_min'               => $r3['minimo'] ?? 0,
        'cursos_activos'        => (int)$r4['total'],
        'sedac_estados'         => $r5,
        'evolucion_tao'         => $r6,
        'top_areas'             => $r7,
    ];
}


function rankingEmpleados(mysqli $conn): array
{
    $stmt = $conn->query(
        "SELECT empleado,
                ROUND(AVG(puntaje_total),1) AS prom_tao,
                COUNT(*)                    AS evaluaciones,
                MAX(nivel_alineacion)       AS mejor_nivel
         FROM resultados_tao
         GROUP BY empleado
         ORDER BY prom_tao DESC
         LIMIT 20"
    );
    return ['success' => true, 'data' => $stmt->fetch_all(MYSQLI_ASSOC)];
}


function reporteArea(mysqli $conn, string $area): array
{
    $stmt = $conn->prepare(
        "SELECT empleado,
                ROUND(AVG(dim_productividad),1) AS productividad,
                ROUND(AVG(dim_calidad),1)        AS calidad,
                ROUND(AVG(dim_costo),1)          AS costo,
                ROUND(AVG(dim_entrega),1)        AS entrega,
                ROUND(AVG(dim_seguridad),1)      AS seguridad,
                ROUND(AVG(dim_informacion),1)    AS informacion,
                ROUND(AVG(dim_moral),1)          AS moral
         FROM resultados_tao WHERE area=?
         GROUP BY empleado"
    );
    $stmt->bind_param('s', $area);
    $stmt->execute();
    return ['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
}


// ============================================================
// HELPERS INTERNOS
// ============================================================

function generarFolio(string $empleado, int $capacitacion_id): string
{
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $empleado), 0, 3));
    return $prefix . '-CAP' . $capacitacion_id . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}


function generarRecomendaciones(array $dimensiones, float $promedio): string
{
    $bajas = array_filter($dimensiones, fn($v) => $v < 50);
    arsort($bajas);

    $rec = [];
    foreach ($bajas as $dim => $val) {
        $rec[] = match ($dim) {
            'Productividad'     => "Implementar tableros visuales diarios para monitorear metas de producción.",
            'Calidad'           => "Establecer un sistema de reporte y análisis de defectos con acciones correctivas.",
            'Costo'             => "Capacitar al equipo en consciencia de costos operativos y su impacto en resultados.",
            'Entrega'           => "Fortalecer el seguimiento de compromisos con alertas tempranas de retraso.",
            'Seguridad'         => "Reforzar la cultura de reporte proactivo de riesgos y condiciones inseguras.",
            'Información'       => "Implementar tableros de indicadores accesibles y actualizados para todos.",
            'Moral'             => "Desarrollar actividades de integración y canales abiertos de comunicación.",
            'Liderazgo'         => "Invertir en formación de liderazgo situacional y coaching para mandos medios.",
            'Comunicación'      => "Establecer reuniones estructuradas y canales claros de información.",
            'Trabajo en Equipo' => "Fomentar proyectos colaborativos interdepartamentales con metas compartidas.",
            default             => "Revisar y fortalecer los procesos en el área de {$dim}.",
        };
    }

    if (empty($rec)) {
        return "Excelente nivel de alineación organizacional. Continúa fortaleciendo las mejores prácticas y comparte el conocimiento con otras áreas.";
    }

    return implode(' | ', array_slice($rec, 0, 3));
}
