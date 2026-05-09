<?php
session_start();

require_once '../config/database.php';
require_once 'registrar_actividad.php';

date_default_timezone_set('America/Guatemala');

// =============================================
// PROCESAMIENTO DEL FORMULARIO
// =============================================
$empleado       = '';
$inicio_completo = '';
$fin_completo    = '';
$horas          = [];
$totales        = [];
$total_quiebras = 0;
$total_ordenes  = 0;
$error_msg      = '';

// Datos avanzados
$hora_pico       = null;
$hora_valle      = null;
$maximo          = 0;
$minimo          = PHP_INT_MAX;
$desviacion      = 0;
$mediana         = 0;
$eficiencia_pct  = 0;
$tendencia       = [];
$acumulado       = [];
$distribucion_pct = [];
$quiebras_por_hora = [];

// Datos por empleado (para vista general)
$datos_empleados = [];
$modo_todos = false;

// Almacenar datos detallados por empleado para modales
$datos_detallados_empleados = [];

if (isset($_POST['ver_produccion_hora'])) {
    $empleado     = trim($_POST['empleado_detalle'] ?? '');
    $fecha_inicio = $_POST['fecha_detalle_inicio'] ?? '';
    $hora_inicio  = $_POST['hora_detalle_inicio'] ?? '00:00';
    $fecha_fin    = $_POST['fecha_detalle_fin'] ?? '';
    $hora_fin     = $_POST['hora_detalle_fin'] ?? '23:59';

    $inicio_completo = "$fecha_inicio $hora_inicio:00";
    $fin_completo    = "$fecha_fin $hora_fin:00";

    if ($inicio_completo > $fin_completo) {
        $error_msg = "La fecha/hora de inicio no puede ser posterior a la de fin.";
    } elseif (empty($empleado)) {
        $error_msg = "Debes seleccionar un empleado o 'Todos los empleados'.";
    } else {
        
        if ($empleado === 'todos') {
            $modo_todos = true;
            
            // =============================================
            // OPTIMIZACIÓN: Una sola consulta para todos los empleados
            // =============================================
            
            // Consulta única de producción por hora para todos los empleados
            $stmt_prod = $conn->prepare("
                SELECT 
                    empleado,
                    DATE_FORMAT(fecha, '%l %p') AS hora_label,
                    HOUR(fecha) AS hora_24,
                    COUNT(*) AS total
                FROM (
                    SELECT empleado, fecha FROM produccion WHERE fecha BETWEEN ? AND ?
                    UNION ALL
                    SELECT empleado, fecha FROM registros_antiguos WHERE fecha BETWEEN ? AND ?
                ) AS todas
                GROUP BY empleado, hora_24
                ORDER BY empleado, hora_24
            ");
            $stmt_prod->bind_param("ssss", $inicio_completo, $fin_completo, $inicio_completo, $fin_completo);
            $stmt_prod->execute();
            $res_prod = $stmt_prod->get_result();
            
            // Estructurar datos por empleado
            $produccion_por_empleado = [];
            while ($row = $res_prod->fetch_assoc()) {
                $empleado_actual = $row['empleado'];
                if (!isset($produccion_por_empleado[$empleado_actual])) {
                    $produccion_por_empleado[$empleado_actual] = [
                        'horas' => [],
                        'totales' => [],
                        'horas_24' => []
                    ];
                }
                $produccion_por_empleado[$empleado_actual]['horas'][] = $row['hora_label'];
                $produccion_por_empleado[$empleado_actual]['totales'][] = (int)$row['total'];
                $produccion_por_empleado[$empleado_actual]['horas_24'][] = $row['hora_24'];
            }
            $stmt_prod->close();
            
            // Consulta única de quiebras para todos los empleados
            $stmt_quiebras = $conn->prepare("
                SELECT 
                    empleado,
                    COUNT(*) AS total_quiebras
                FROM registro_quiebras
                WHERE fecha BETWEEN ? AND ?
                GROUP BY empleado
            ");
            $stmt_quiebras->bind_param("ss", $inicio_completo, $fin_completo);
            $stmt_quiebras->execute();
            $res_quiebras = $stmt_quiebras->get_result();
            
            $quiebras_por_empleado = [];
            while ($row = $res_quiebras->fetch_assoc()) {
                $quiebras_por_empleado[$row['empleado']] = (int)$row['total_quiebras'];
            }
            $stmt_quiebras->close();
            
            // Consulta de quiebras por hora para cada empleado (para gráficos detallados)
            $stmt_quiebras_hora = $conn->prepare("
                SELECT 
                    empleado,
                    DATE_FORMAT(fecha, '%l %p') AS hora_label,
                    HOUR(fecha) AS hora_24,
                    COUNT(*) AS total
                FROM registro_quiebras
                WHERE fecha BETWEEN ? AND ?
                GROUP BY empleado, hora_24
                ORDER BY empleado, hora_24
            ");
            $stmt_quiebras_hora->bind_param("ss", $inicio_completo, $fin_completo);
            $stmt_quiebras_hora->execute();
            $res_qh = $stmt_quiebras_hora->get_result();
            
            $quiebras_hora_por_empleado = [];
            while ($row = $res_qh->fetch_assoc()) {
                $empleado_actual = $row['empleado'];
                if (!isset($quiebras_hora_por_empleado[$empleado_actual])) {
                    $quiebras_hora_por_empleado[$empleado_actual] = [];
                }
                $quiebras_hora_por_empleado[$empleado_actual][$row['hora_label']] = (int)$row['total'];
            }
            $stmt_quiebras_hora->close();
            
            // =============================================
            // NUEVA CONSULTA: Áreas y equipos por empleado
            // =============================================
            $stmt_areas_equipos = $conn->prepare("
                SELECT 
                    empleado,
                    area,
                    equipo,
                    COUNT(*) AS total_produccion
                FROM (
                    SELECT empleado, area, equipo FROM produccion WHERE fecha BETWEEN ? AND ?
                    UNION ALL
                    SELECT empleado, area, equipo FROM registros_antiguos WHERE fecha BETWEEN ? AND ?
                ) AS todas
                GROUP BY empleado, area, equipo
                ORDER BY empleado, total_produccion DESC
            ");
            $stmt_areas_equipos->bind_param("ssss", $inicio_completo, $fin_completo, $inicio_completo, $fin_completo);
            $stmt_areas_equipos->execute();
            $res_areas = $stmt_areas_equipos->get_result();
            
            $areas_equipos_por_empleado = [];
            while ($row = $res_areas->fetch_assoc()) {
                $empleado_actual = $row['empleado'];
                if (!isset($areas_equipos_por_empleado[$empleado_actual])) {
                    $areas_equipos_por_empleado[$empleado_actual] = [
                        'areas' => [],
                        'equipos' => [],
                        'detalles' => []
                    ];
                }
                $areas_equipos_por_empleado[$empleado_actual]['detalles'][] = [
                    'area' => $row['area'],
                    'equipo' => $row['equipo'] ?? 'Sin equipo',
                    'total' => (int)$row['total_produccion']
                ];
                
                // Agregar área única
                if (!in_array($row['area'], $areas_equipos_por_empleado[$empleado_actual]['areas'])) {
                    $areas_equipos_por_empleado[$empleado_actual]['areas'][] = $row['area'];
                }
                
                // Agregar equipo único (si no es null)
                if (!empty($row['equipo']) && !in_array($row['equipo'], $areas_equipos_por_empleado[$empleado_actual]['equipos'])) {
                    $areas_equipos_por_empleado[$empleado_actual]['equipos'][] = $row['equipo'];
                }
            }
            $stmt_areas_equipos->close();
            
            // Procesar datos de cada empleado con todos los detalles para modales
            foreach ($produccion_por_empleado as $empleado_actual => $data) {
                $horas_emp = $data['horas'];
                $totales_emp = $data['totales'];
                $horas_24_emp = $data['horas_24'];
                $total_ordenes_emp = array_sum($totales_emp);
                $total_quiebras_emp = $quiebras_por_empleado[$empleado_actual] ?? 0;
                $quiebras_hora_emp = $quiebras_hora_por_empleado[$empleado_actual] ?? [];
                
                // Obtener áreas y equipos del empleado
                $areas_equipos_emp = $areas_equipos_por_empleado[$empleado_actual] ?? [
                    'areas' => [],
                    'equipos' => [],
                    'detalles' => []
                ];
                
                // Calcular métricas para este empleado
                if (!empty($totales_emp)) {
                    $max_emp = max($totales_emp);
                    $min_emp = min($totales_emp);
                    $prom_emp = $total_ordenes_emp / count($totales_emp);
                    
                    // Encontrar hora pico y valle
                    $idx_max = array_search($max_emp, $totales_emp);
                    $idx_min = array_search($min_emp, $totales_emp);
                    $hora_pico_emp = $horas_emp[$idx_max] ?? '—';
                    $hora_valle_emp = $horas_emp[$idx_min] ?? '—';
                    
                    // Calcular desviación
                    $varianza_emp = array_sum(array_map(fn($v) => pow($v - $prom_emp, 2), $totales_emp)) / count($totales_emp);
                    $desviacion_emp = sqrt($varianza_emp);
                    
                    // Calcular mediana
                    $sorted = $totales_emp;
                    sort($sorted);
                    $n = count($sorted);
                    $mid = (int)($n / 2);
                    $mediana_emp = ($n % 2 === 0) ? ($sorted[$mid - 1] + $sorted[$mid]) / 2 : $sorted[$mid];
                    
                    // Calcular eficiencia
                    $eficiencia_emp = ($max_emp > 0) ? ($prom_emp / $max_emp) * 100 : 0;
                    
                    // Calcular tendencia
                    $tendencia_emp = 0;
                    if (count($totales_emp) > 1 && $totales_emp[0] > 0) {
                        $tendencia_emp = round((($totales_emp[count($totales_emp)-1] - $totales_emp[0]) / $totales_emp[0]) * 100, 1);
                    }
                    
                    // Calcular distribución porcentual
                    $distribucion_pct_emp = [];
                    foreach ($totales_emp as $v) {
                        $distribucion_pct_emp[] = ($total_ordenes_emp > 0) ? round(($v / $total_ordenes_emp) * 100, 1) : 0;
                    }
                    
                    // Calcular acumulado
                    $acumulado_emp = [];
                    $acum = 0;
                    foreach ($totales_emp as $v) {
                        $acum += $v;
                        $acumulado_emp[] = $acum;
                    }
                    
                    // Calcular quiebras por hora formateadas
                    $quiebras_hora_formateadas = [];
                    foreach ($horas_emp as $hora) {
                        $quiebras_hora_formateadas[] = $quiebras_hora_emp[$hora] ?? 0;
                    }
                    
                    $datos_empleados[] = [
                        'nombre' => $empleado_actual,
                        'total_ordenes' => $total_ordenes_emp,
                        'total_quiebras' => $total_quiebras_emp,
                        'maximo' => $max_emp,
                        'minimo' => $min_emp,
                        'promedio' => round($prom_emp, 2),
                        'horas_activas' => count($totales_emp),
                        'eficiencia' => round($eficiencia_emp, 1),
                        'tendencia' => $tendencia_emp,
                        'hora_pico' => $hora_pico_emp,
                        'hora_valle' => $hora_valle_emp,
                        'desviacion' => round($desviacion_emp, 2),
                        'mediana' => round($mediana_emp, 2),
                        'horas_labels' => $horas_emp,
                        'totales_detalle' => $totales_emp,
                        'horas_24' => $horas_24_emp,
                        'distribucion' => $distribucion_pct_emp,
                        'acumulado' => $acumulado_emp,
                        'quiebras_por_hora' => $quiebras_hora_formateadas,
                        // Nuevos campos para áreas y equipos
                        'areas' => $areas_equipos_emp['areas'],
                        'equipos' => $areas_equipos_emp['equipos'],
                        'detalles_areas_equipos' => $areas_equipos_emp['detalles']
                    ];
                }
            }

            // Almacenar datos detallados para uso en modales (ANTES de ordenar)
            $datos_detallados_empleados = $datos_empleados;

            // Si no hay datos, mostrar mensaje
            if (empty($datos_empleados)) {
                $error_msg = "No se encontraron datos para ningún empleado en el rango seleccionado.";
            }

        } else {
            // Modo empleado individual - OBTENER DATOS PARA PRODUCCION_POR_HORA.PHP
            $modo_todos = false;
            
            // Producción por hora (para gráfico principal)
            $stmt = $conn->prepare("
                SELECT 
                    DATE_FORMAT(fecha, '%l %p') AS hora_label,
                    HOUR(fecha) AS hora_24,
                    COUNT(*) AS total
                FROM (
                    SELECT fecha FROM produccion WHERE empleado = ? AND fecha BETWEEN ? AND ?
                    UNION ALL
                    SELECT fecha FROM registros_antiguos WHERE empleado = ? AND fecha BETWEEN ? AND ?
                ) AS todas
                GROUP BY hora_24
                ORDER BY hora_24
            ");
            $stmt->bind_param("ssssss", $empleado, $inicio_completo, $fin_completo, $empleado, $inicio_completo, $fin_completo);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $horas[] = $row['hora_label'];
                $totales[] = (int)$row['total'];
            }
            $stmt->close();
            
            // Quiebras totales
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total_quiebras
                FROM registro_quiebras
                WHERE empleado = ? AND fecha BETWEEN ? AND ?
            ");
            $stmt->bind_param("sss", $empleado, $inicio_completo, $fin_completo);
            $stmt->execute();
            $total_quiebras = $stmt->get_result()->fetch_assoc()['total_quiebras'] ?? 0;
            $stmt->close();
            
            // Quiebras por hora
            $stmt = $conn->prepare("
                SELECT 
                    DATE_FORMAT(fecha, '%l %p') AS hora_label,
                    HOUR(fecha) AS hora_24,
                    COUNT(*) AS total
                FROM registro_quiebras
                WHERE empleado = ? AND fecha BETWEEN ? AND ?
                GROUP BY hora_24
                ORDER BY hora_24
            ");
            $stmt->bind_param("sss", $empleado, $inicio_completo, $fin_completo);
            $stmt->execute();
            $res_q = $stmt->get_result();
            while ($row = $res_q->fetch_assoc()) {
                $quiebras_por_hora[$row['hora_label']] = (int)$row['total'];
            }
            $stmt->close();
            
            // Áreas y equipos para empleado individual
            $stmt_areas = $conn->prepare("
                SELECT 
                    area,
                    equipo,
                    COUNT(*) AS total_produccion
                FROM (
                    SELECT area, equipo FROM produccion WHERE empleado = ? AND fecha BETWEEN ? AND ?
                    UNION ALL
                    SELECT area, equipo FROM registros_antiguos WHERE empleado = ? AND fecha BETWEEN ? AND ?
                ) AS todas
                GROUP BY area, equipo
                ORDER BY total_produccion DESC
            ");
            $stmt_areas->bind_param("ssssss", $empleado, $inicio_completo, $fin_completo, $empleado, $inicio_completo, $fin_completo);
            $stmt_areas->execute();
            $res_areas = $stmt_areas->get_result();
            $detalles_areas_equipos = [];
            $areas_list = [];
            $equipos_list = [];
            while ($row = $res_areas->fetch_assoc()) {
                $detalles_areas_equipos[] = [
                    'area' => $row['area'],
                    'equipo' => $row['equipo'] ?? 'Sin equipo',
                    'total' => (int)$row['total_produccion']
                ];
                if (!in_array($row['area'], $areas_list)) {
                    $areas_list[] = $row['area'];
                }
                if (!empty($row['equipo']) && !in_array($row['equipo'], $equipos_list)) {
                    $equipos_list[] = $row['equipo'];
                }
            }
            $stmt_areas->close();
            
            // Cálculo de métricas
            if (!empty($totales)) {
                $total_ordenes = array_sum($totales);
                $n = count($totales);
                $maximo = max($totales);
                $minimo = min($totales);
                $idx_max = array_search($maximo, $totales);
                $idx_min = array_search($minimo, $totales);
                $hora_pico = $horas[$idx_max] ?? '—';
                $hora_valle = $horas[$idx_min] ?? '—';
                $promedio = $total_ordenes / $n;
                
                $sorted = $totales;
                sort($sorted);
                $mid = (int)($n / 2);
                $mediana = ($n % 2 === 0) ? ($sorted[$mid - 1] + $sorted[$mid]) / 2 : $sorted[$mid];
                
                $varianza = array_sum(array_map(fn($v) => pow($v - $promedio, 2), $totales)) / $n;
                $desviacion = sqrt($varianza);
                $eficiencia_pct = ($maximo > 0) ? ($promedio / $maximo) * 100 : 0;
                
                for ($i = 1; $i < $n; $i++) {
                    $prev = $totales[$i - 1];
                    $tendencia[] = ($prev > 0) ? round((($totales[$i] - $prev) / $prev) * 100, 1) : 0;
                }
                
                $acum = 0;
                foreach ($totales as $v) {
                    $acum += $v;
                    $acumulado[] = $acum;
                }
                
                foreach ($totales as $v) {
                    $distribucion_pct[] = ($total_ordenes > 0) ? round(($v / $total_ordenes) * 100, 1) : 0;
                }
            }
            
            // Almacenar datos para modal individual
            $datos_detallados_empleados = [[
                'nombre' => $empleado,
                'total_ordenes' => $total_ordenes ?? 0,
                'total_quiebras' => $total_quiebras,
                'maximo' => $maximo ?? 0,
                'minimo' => $minimo ?? 0,
                'promedio' => $promedio ?? 0,
                'horas_activas' => count($horas),
                'eficiencia' => round($eficiencia_pct, 1),
                'tendencia' => !empty($tendencia) ? end($tendencia) : 0,
                'hora_pico' => $hora_pico ?? '—',
                'hora_valle' => $hora_valle ?? '—',
                'desviacion' => $desviacion ?? 0,
                'mediana' => $mediana ?? 0,
                'horas_labels' => $horas,
                'totales_detalle' => $totales,
                'distribucion' => $distribucion_pct,
                'quiebras_por_hora' => array_values($quiebras_por_hora),
                'areas' => $areas_list,
                'equipos' => $equipos_list,
                'detalles_areas_equipos' => $detalles_areas_equipos
            ]];
        }
    }
}

// Lista de empleados
$empleados_list = [];
$eq = $conn->query("SELECT DISTINCT nombre_empleado FROM empleados ORDER BY nombre_empleado");
if ($eq) {
    while ($r = $eq->fetch_assoc()) {
        $empleados_list[] = $r['nombre_empleado'];
    }
}

$promedio_js = isset($promedio) ? round($promedio, 2) : 0;
$mediana_js = isset($mediana) ? round($mediana, 2) : 0;
$desviacion_js = isset($desviacion) ? round($desviacion, 2) : 0;
$eficiencia_js = isset($eficiencia_pct) ? round($eficiencia_pct, 1) : 0;
$total_global = ($total_ordenes ?? 0) + ($total_quiebras ?? 0);
$pct_quiebras = ($total_global > 0) ? round(($total_quiebras / $total_global) * 100, 2) : 0;
$pct_produccion = ($total_global > 0) ? round(($total_ordenes / $total_global) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Producción por Hora — Análisis Avanzado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
/* ── Variables Mejoradas ─────────────────────────────────────────── */
:root {
    --bg:        #070b09;
    --surface:   #0e1612;
    --surface2:  #141f19;
    --surface3:  #1a2a21;
    --border:    #23332a;
    --accent:    #00ff88;
    --accent2:   #00cc6a;
    --accent3:   #ff5e5e;
    --accent4:   #ffcd4a;
    --text:      #f0f7f2;
    --text-dim:  #9bb5a5;
    --muted:     #4a6b5a;
    --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-mono: 'Space Grotesk', monospace;
    --radius:    16px;
    --radius-sm: 10px;
    --shadow:    0 4px 20px rgba(0,0,0,0.3);
    --shadow-lg: 0 8px 40px rgba(0,255,136,0.08);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-sans);
    line-height: 1.5;
    min-height: 100vh;
    background-image: radial-gradient(circle at 25% 0%, rgba(0,255,136,0.02) 0%, transparent 50%);
}

/* ── Header Moderno ────────────────────────────────────────────── */
.modern-header {
    background: linear-gradient(135deg, rgba(10,20,14,0.98) 0%, rgba(5,12,8,0.98) 100%);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border);
    padding: 32px 48px;
    position: relative;
    overflow: hidden;
}

.modern-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--accent), transparent);
}

.modern-header h1 {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 32px;
    letter-spacing: -0.02em;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    margin-bottom: 8px;
}

.modern-header p {
    color: var(--text-dim);
    font-size: 14px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-dim);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 20px;
    transition: var(--transition);
}

.back-link:hover {
    color: var(--accent);
    transform: translateX(-4px);
}

/* ── Layout ────────────────────────────────────────────── */
.wrap {
    max-width: 1440px;
    margin: 0 auto;
    padding: 40px 32px;
}

/* ── Form Card Moderno ─────────────────────────────────────────── */
.form-card-modern {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 32px;
    margin-bottom: 40px;
    transition: var(--transition);
}

.form-card-modern:hover {
    border-color: var(--accent2);
    box-shadow: var(--shadow-lg);
}

.form-card-modern h2 {
    font-family: var(--font-mono);
    font-weight: 600;
    font-size: 18px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.form-card-modern h2 i {
    color: var(--accent);
    font-size: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
}

.form-group select,
.form-group input {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--font-sans);
    font-size: 14px;
    padding: 12px 16px;
    width: 100%;
    outline: none;
    transition: var(--transition);
}

.form-group select:focus,
.form-group input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,255,136,0.1);
}

.btn-submit {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none;
    border-radius: var(--radius-sm);
    color: #030a06;
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 14px;
    padding: 12px 28px;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 10px;
    justify-content: center;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,255,136,0.3);
}

/* ── KPI Grid Moderno ──────────────────────────────────────────── */
.kpi-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 40px;
}

.kpi-card-modern {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.kpi-card-modern:hover {
    transform: translateY(-4px);
    border-color: var(--accent2);
}

.kpi-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    opacity: 0;
    transition: var(--transition);
}

.kpi-card-modern:hover::before {
    opacity: 1;
}

.kpi-label-modern {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.kpi-label-modern i {
    font-size: 14px;
}

.kpi-value-modern {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 32px;
    line-height: 1.2;
    color: var(--text);
}

.kpi-value-modern.accent { color: var(--accent); }
.kpi-value-modern.red { color: var(--accent3); }
.kpi-value-modern.yellow { color: var(--accent4); }

.kpi-sub-modern {
    font-size: 12px;
    color: var(--text-dim);
    margin-top: 8px;
}

/* ── Tabla de Empleados (Vista General) ─────────────────────────── */
.empleados-table {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 40px;
}

.empleados-table .table-header {
    background: var(--surface2);
    padding: 16px 24px;
    border-bottom: 1px solid var(--border);
}

.empleados-table .table-header h3 {
    font-family: var(--font-mono);
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-scroll {
    overflow-x: auto;
}

.empleados-table table {
    width: 100%;
    border-collapse: collapse;
}

.empleados-table th {
    text-align: left;
    padding: 16px 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}

.empleados-table td {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(35,51,42,0.5);
    font-size: 14px;
}

.empleados-table tr:hover {
    background: var(--surface2);
    cursor: pointer;
}

.empleado-rank {
    font-weight: 700;
    font-size: 18px;
    color: var(--accent);
}

.rank-1 { color: #ffd700; }
.rank-2 { color: #c0c0c0; }
.rank-3 { color: #cd7f32; }

.progress-bar-mini {
    width: 100%;
    height: 6px;
    background: var(--border);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 8px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    border-radius: 3px;
    transition: width 0.5s ease;
}

/* ── Botón Ver Detalles ─────────────────────────────────────────── */
.btn-detail {
    background: transparent;
    border: 1px solid var(--accent);
    border-radius: var(--radius-sm);
    color: var(--accent);
    font-size: 12px;
    padding: 6px 12px;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-detail:hover {
    background: var(--accent);
    color: var(--bg);
}

/* ── Charts Grid ─────────────────────────────────────────────────── */
.charts-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 24px;
    margin-bottom: 40px;
}

.chart-card-modern {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    transition: var(--transition);
}

.chart-card-modern:hover {
    border-color: var(--accent2);
}

.chart-card-modern h3 {
    font-family: var(--font-mono);
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* ── Insights Grid ───────────────────────────────────────────────── */
.insights-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 40px;
}

.insight-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    display: flex;
    gap: 16px;
    transition: var(--transition);
}

.insight-card:hover {
    transform: translateY(-2px);
    border-color: var(--accent2);
}

.insight-icon {
    width: 48px;
    height: 48px;
    background: rgba(0,255,136,0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.insight-content h4 {
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}

.insight-content p {
    font-size: 13px;
    color: var(--text-dim);
    line-height: 1.5;
}

/* ── Modal Styles ─────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.modal-container {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    width: 90%;
    max-width: 1200px;
    max-height: 85vh;
    overflow-y: auto;
    position: relative;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    background: var(--surface2);
    padding: 24px 32px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 10;
}

.modal-header h2 {
    font-family: var(--font-mono);
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.modal-header h2 i {
    color: var(--accent);
}

.modal-close {
    background: transparent;
    border: none;
    color: var(--text-dim);
    font-size: 28px;
    cursor: pointer;
    transition: var(--transition);
    line-height: 1;
}

.modal-close:hover {
    color: var(--accent3);
    transform: rotate(90deg);
}

.modal-body {
    padding: 32px;
}

.modal-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.modal-kpi-card {
    background: var(--surface2);
    border-radius: var(--radius-sm);
    padding: 16px;
    text-align: center;
}

.modal-kpi-card .kpi-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
}

.modal-kpi-card .kpi-value {
    font-family: var(--font-mono);
    font-size: 28px;
    font-weight: 700;
    color: var(--accent);
}

.modal-kpi-card .kpi-sub {
    font-size: 11px;
    color: var(--text-dim);
    margin-top: 4px;
}

.modal-charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.modal-chart-card {
    background: var(--surface2);
    border-radius: var(--radius-sm);
    padding: 20px;
}

.modal-chart-card h4 {
    font-family: var(--font-mono);
    font-size: 14px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 24px;
}

.stat-item {
    background: var(--surface2);
    border-radius: var(--radius-sm);
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stat-label {
    font-size: 12px;
    color: var(--text-dim);
}

.stat-value {
    font-family: var(--font-mono);
    font-weight: 600;
    font-size: 16px;
}

/* ── Sección de Áreas y Equipos ───────────────────────────────────── */
.areas-equipos-section {
    background: var(--surface2);
    border-radius: var(--radius-sm);
    padding: 20px;
    margin-top: 24px;
}

.areas-equipos-section h4 {
    font-family: var(--font-mono);
    font-size: 14px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.areas-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

.area-badge {
    background: rgba(0,255,136,0.15);
    border: 1px solid var(--accent);
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 500;
    color: var(--accent);
}

.equipo-badge {
    background: rgba(255,205,74,0.15);
    border: 1px solid var(--accent4);
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 500;
    color: var(--accent4);
}

.detalle-produccion-table {
    width: 100%;
    margin-top: 16px;
    border-collapse: collapse;
}

.detalle-produccion-table th {
    text-align: left;
    padding: 10px 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
}

.detalle-produccion-table td {
    padding: 10px 12px;
    font-size: 13px;
    border-bottom: 1px solid rgba(35,51,42,0.5);
}

.detalle-produccion-table tr:hover {
    background: var(--surface3);
}

/* ── Empty State ─────────────────────────────────────────────────── */
.empty-state-modern {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 60px 40px;
    text-align: center;
}

.empty-state-modern i {
    font-size: 64px;
    color: var(--muted);
    margin-bottom: 20px;
}

.empty-state-modern h3 {
    font-family: var(--font-mono);
    font-size: 20px;
    margin-bottom: 12px;
}

/* ── Footer ─────────────────────────────────────────────────────── */
.modern-footer {
    text-align: center;
    padding: 24px;
    border-top: 1px solid var(--border);
    color: var(--muted);
    font-size: 12px;
}

/* ── Animaciones ─────────────────────────────────────────────────── */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-in {
    animation: fadeInUp 0.5s ease forwards;
}

/* ── Responsive ─────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .modern-header {
        padding: 24px 20px;
    }
    
    .wrap {
        padding: 24px 16px;
    }
    
    .charts-grid-modern {
        grid-template-columns: 1fr;
    }
    
    .kpi-value-modern {
        font-size: 26px;
    }
    
    .modal-charts-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-body {
        padding: 20px;
    }
}
</style>
</head>
<body>

<div class="modern-header">
    <a href="dashboard_admin_produccion.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Volver al Dashboard
    </a>
    <h1><i class="fas fa-chart-line"></i> Producción por Hora</h1>
    <p>Análisis detallado · Estadísticas avanzadas · Métricas de rendimiento</p>
</div>

<div class="wrap">

    <div class="form-card-modern fade-in">
        <h2><i class="fas fa-sliders-h"></i> Filtros de Consulta</h2>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Empleado</label>
                    <select name="empleado_detalle" required>
                        <option value="">Seleccione...</option>
                        <option value="todos" <?= (isset($_POST['empleado_detalle']) && $_POST['empleado_detalle'] === 'todos') ? 'selected' : '' ?>>
                            👥 Todos los empleados
                        </option>
                        <?php foreach ($empleados_list as $emp):
                            $sel = (isset($_POST['empleado_detalle']) && $_POST['empleado_detalle'] === $emp) ? 'selected' : '';
                        ?>
                            <option value="<?= htmlspecialchars($emp) ?>" <?= $sel ?>><?= htmlspecialchars($emp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Fecha Inicio</label>
                    <input type="date" name="fecha_detalle_inicio" required value="<?= htmlspecialchars($_POST['fecha_detalle_inicio'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Hora Inicio</label>
                    <input type="time" name="hora_detalle_inicio" required value="<?= htmlspecialchars($_POST['hora_detalle_inicio'] ?? '00:00') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-check"></i> Fecha Fin</label>
                    <input type="date" name="fecha_detalle_fin" required value="<?= htmlspecialchars($_POST['fecha_detalle_fin'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Hora Fin</label>
                    <input type="time" name="hora_detalle_fin" required value="<?= htmlspecialchars($_POST['hora_detalle_fin'] ?? '23:59') ?>">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" name="ver_produccion_hora" class="btn-submit">
                        <i class="fas fa-chart-simple"></i> Analizar
                    </button>
                </div>
            </div>
        </form>
    </div>

<?php if (!empty($error_msg)): ?>
    <div class="error-box" style="background: rgba(255,94,94,0.1); border: 1px solid rgba(255,94,94,0.3); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 30px;">
        <i class="fas fa-exclamation-triangle" style="color: var(--accent3); margin-right: 12px;"></i>
        <?= htmlspecialchars($error_msg) ?>
    </div>
<?php endif; ?>

<?php if (isset($_POST['ver_produccion_hora']) && empty($error_msg)): ?>
    
    <?php if ($modo_todos && !empty($datos_empleados)): ?>
        <!-- VISTA DE TODOS LOS EMPLEADOS -->
        <div class="empleados-table fade-in">
            <div class="table-header">
                <h3><i class="fas fa-users"></i> Rendimiento por Empleado <span style="font-size: 12px; color: var(--muted);">(Click en fila para ver detalles)</span></h3>
            </div>
            <div class="table-scroll">
                <table id="empleados-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Empleado</th>
                            <th><i class="fas fa-box"></i> Órdenes</th>
                            <th><i class="fas fa-exclamation-triangle"></i> Quiebras</th>
                            <th><i class="fas fa-chart-line"></i> Promedio/Hora</th>
                            <th><i class="fas fa-bolt"></i> Eficiencia</th>
                            <th><i class="fas fa-chart-simple"></i> Tendencia</th>
                            <th><i class="fas fa-hourglass-half"></i> Horas Activas</th>
                            <th><i class="fas fa-chart-pie"></i> Detalles</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Ordenar los datos para la tabla (producción descendente)
                        usort($datos_empleados, fn($a, $b) => $b['total_ordenes'] - $a['total_ordenes']);
                        $rank = 1;
                        foreach ($datos_empleados as $emp): 
                            $rank_class = '';
                            if ($rank == 1) $rank_class = 'rank-1';
                            elseif ($rank == 2) $rank_class = 'rank-2';
                            elseif ($rank == 3) $rank_class = 'rank-3';
                            $total_emp = $emp['total_ordenes'] + $emp['total_quiebras'];
                            $pct_prod = $total_emp > 0 ? round(($emp['total_ordenes'] / $total_emp) * 100, 1) : 0;
                            $tendencia_color = $emp['tendencia'] >= 0 ? 'var(--accent)' : 'var(--accent3)';
                            
                            // Codificar el nombre para usarlo como identificador
                            $nombre_codificado = htmlspecialchars($emp['nombre'], ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr data-empleado-nombre="<?= $nombre_codificado ?>" style="cursor: pointer;">
                                <td class="empleado-rank <?= $rank_class ?>">#<?= $rank++ ?></td>
                                <td><strong><?= htmlspecialchars($emp['nombre']) ?></strong></td>
                                <td><?= number_format($emp['total_ordenes']) ?></td>
                                <td style="color: <?= $emp['total_quiebras'] > 0 ? 'var(--accent3)' : 'var(--muted)' ?>">
                                    <?= number_format($emp['total_quiebras']) ?>
                                    <div class="progress-bar-mini">
                                        <div class="progress-fill" style="width: <?= 100 - $pct_prod ?>%; background: var(--accent3);"></div>
                                    </div>
                                </td>
                                <td><?= number_format($emp['promedio'], 1) ?></td>
                                <td>
                                    <?= $emp['eficiencia'] ?>%
                                    <div class="progress-bar-mini">
                                        <div class="progress-fill" style="width: <?= $emp['eficiencia'] ?>%"></div>
                                    </div>
                                </td>
                                <td style="color: <?= $tendencia_color ?>">
                                    <?= $emp['tendencia'] >= 0 ? '+' : '' ?><?= $emp['tendencia'] ?>%
                                </td>
                                <td><?= $emp['horas_activas'] ?></td>
                                <td>
                                    <button class="btn-detail" onclick="event.stopPropagation(); showEmployeeDetails('<?= $nombre_codificado ?>')">
                                        <i class="fas fa-chart-line"></i> Ver
                                    </button>
                                </td>
                            </tr>
                        <?php 
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- KPIs Globales para vista de todos -->
        <div class="kpi-grid-modern">
            <?php 
            $total_ordenes_global = array_sum(array_column($datos_empleados, 'total_ordenes'));
            $total_quiebras_global = array_sum(array_column($datos_empleados, 'total_quiebras'));
            $total_global = $total_ordenes_global + $total_quiebras_global;
            $promedio_global = count($datos_empleados) > 0 ? round($total_ordenes_global / count($datos_empleados), 1) : 0;
            $mejor_empleado = !empty($datos_empleados) ? $datos_empleados[0]['nombre'] : '—';
            $mejor_produccion = !empty($datos_empleados) ? $datos_empleados[0]['total_ordenes'] : 0;
            ?>
            <div class="kpi-card-modern">
                <div class="kpi-label-modern"><i class="fas fa-chart-simple"></i> Total Producción</div>
                <div class="kpi-value-modern accent"><?= number_format($total_ordenes_global) ?></div>
                <div class="kpi-sub-modern">Órdenes totales</div>
            </div>
            <div class="kpi-card-modern">
                <div class="kpi-label-modern"><i class="fas fa-users"></i> Empleados Activos</div>
                <div class="kpi-value-modern"><?= count($datos_empleados) ?></div>
                <div class="kpi-sub-modern">Con producción registrada</div>
            </div>
            <div class="kpi-card-modern">
                <div class="kpi-label-modern"><i class="fas fa-trophy"></i> Mejor Empleado</div>
                <div class="kpi-value-modern" style="font-size: 20px;"><?= htmlspecialchars($mejor_empleado) ?></div>
                <div class="kpi-sub-modern"><?= number_format($mejor_produccion) ?> órdenes</div>
            </div>
            <div class="kpi-card-modern">
                <div class="kpi-label-modern"><i class="fas fa-chart-line"></i> Promedio x Empleado</div>
                <div class="kpi-value-modern yellow"><?= number_format($promedio_global, 1) ?></div>
                <div class="kpi-sub-modern">Órdenes por empleado</div>
            </div>
        </div>
        
        <div class="insights-grid-modern">
            <div class="insight-card">
                <div class="insight-icon"><i class="fas fa-chart-line"></i></div>
                <div class="insight-content">
                    <h4>Top Performers</h4>
                    <p>El empleado con mayor producción es <strong><?= htmlspecialchars($mejor_empleado) ?></strong> con <?= number_format($mejor_produccion) ?> órdenes.</p>
                </div>
            </div>
            <div class="insight-card">
                <div class="insight-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="insight-content">
                    <h4>Calidad General</h4>
                    <p>Tasa de quiebras: <?= $total_global > 0 ? round(($total_quiebras_global / $total_global) * 100, 1) : 0 ?>% del total global.</p>
                </div>
            </div>
            <div class="insight-card">
                <div class="insight-icon"><i class="fas fa-chart-simple"></i></div>
                <div class="insight-content">
                    <h4>Visión General</h4>
                    <p>Se analizaron <strong><?= count($datos_empleados) ?></strong> empleados en el período seleccionado.</p>
                </div>
            </div>
        </div>
            
        <?php elseif (!empty($totales)): ?>
            <!-- ============================================= -->
            <!-- VISTA DE EMPLEADO INDIVIDUAL                 -->
            <!-- ============================================= -->
            
            <!-- Mostrar el gráfico de producción por hora usando el fragmento incluido -->
            <?php 
            // Preparar variables para el fragmento produccion_por_hora.php
            // Establecemos las mismas variables que espera el fragmento
            $empleado_detalle = $empleado;
            $fecha_detalle_inicio = $_POST['fecha_detalle_inicio'] ?? '';
            $fecha_detalle_fin = $_POST['fecha_detalle_fin'] ?? '';
            
            // Incluir el fragmento que genera el gráfico
            include 'produccion_por_hora.php';
            ?>
            
            <!-- KPI adicionales para empleado individual -->
            <div class="kpi-grid-modern" style="margin-top: 20px;">
                <div class="kpi-card-modern">
                    <div class="kpi-label-modern"><i class="fas fa-box"></i> Total Órdenes</div>
                    <div class="kpi-value-modern accent"><?= number_format($total_ordenes) ?></div>
                    <div class="kpi-sub-modern"><?= count($horas) ?> horas registradas</div>
                </div>
                <div class="kpi-card-modern">
                    <div class="kpi-label-modern"><i class="fas fa-exclamation-triangle"></i> Total Quiebras</div>
                    <div class="kpi-value-modern red"><?= number_format($total_quiebras) ?></div>
                    <div class="kpi-sub-modern"><?= $pct_quiebras ?>% del total</div>
                </div>
                <div class="kpi-card-modern">
                    <div class="kpi-label-modern"><i class="fas fa-chart-line"></i> Promedio / Hora</div>
                    <div class="kpi-value-modern yellow"><?= number_format($promedio_js, 1) ?></div>
                    <div class="kpi-sub-modern">Mediana: <?= number_format($mediana_js, 1) ?></div>
                </div>
                <div class="kpi-card-modern">
                    <div class="kpi-label-modern"><i class="fas fa-fire"></i> Hora Pico</div>
                    <div class="kpi-value-modern" style="font-size: 20px; color: var(--accent);"><?= htmlspecialchars($hora_pico ?? '—') ?></div>
                    <div class="kpi-sub-modern"><?= number_format($maximo) ?> órdenes</div>
                </div>
            </div>
            
            <div class="charts-grid-modern">
                <div class="chart-card-modern">
                    <h3><i class="fas fa-chart-line" style="color: var(--accent);"></i> Producción por Hora (Detalle)</h3>
                    <canvas id="chart_main_detail" height="200"></canvas>
                </div>
                <div class="chart-card-modern">
                    <h3><i class="fas fa-chart-bar" style="color: var(--accent4);"></i> Comparativa Horaria</h3>
                    <canvas id="chart_bars_detail" height="200"></canvas>
                </div>
            </div>
            
            <script>
            // Datos para gráficos adicionales
            const horasDetail = <?= json_encode($horas) ?>;
            const totalesDetail = <?= json_encode($totales) ?>;
            const maxValDetail = <?= $maximo ?>;
            const avgValDetail = <?= round($promedio_js, 2) ?>;
            const ACCENT = '#00ff88';
            const ACCENT4 = '#ffcd4a';
            
            // Gráfico de línea detallado
            const chartMainDetail = document.getElementById('chart_main_detail');
            if (chartMainDetail) {
                new Chart(chartMainDetail, {
                    type: 'line',
                    data: {
                        labels: horasDetail,
                        datasets: [{
                            label: 'Órdenes',
                            data: totalesDetail,
                            borderColor: ACCENT,
                            backgroundColor: 'rgba(0,255,136,0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 5,
                            pointBackgroundColor: ACCENT
                        }, {
                            label: 'Promedio',
                            data: Array(horasDetail.length).fill(avgValDetail),
                            borderColor: ACCENT4,
                            borderDash: [5, 5],
                            borderWidth: 1.5,
                            pointRadius: 0,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { 
                            legend: { labels: { color: '#9bb5a5' } },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        let value = context.raw;
                                        if (context.datasetIndex === 0) {
                                            let porcentaje = (value / maxValDetail * 100).toFixed(1);
                                            return `${label}: ${value} órdenes (${porcentaje}% del pico)`;
                                        }
                                        return `${label}: ${value.toFixed(1)} órdenes`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { color: '#9bb5a5', stepSize: 1 },
                                grid: { color: 'rgba(255,255,255,0.1)' }
                            },
                            x: {
                                ticks: { color: '#9bb5a5', rotation: 45, maxRotation: 45, minRotation: 45 },
                                grid: { color: 'rgba(255,255,255,0.1)' }
                            }
                        }
                    }
                });
            }
            
            // Gráfico de barras detallado
            const chartBarsDetail = document.getElementById('chart_bars_detail');
            if (chartBarsDetail) {
                new Chart(chartBarsDetail, {
                    type: 'bar',
                    data: {
                        labels: horasDetail,
                        datasets: [{
                            label: 'Órdenes',
                            data: totalesDetail,
                            backgroundColor: totalesDetail.map(v => v === maxValDetail ? ACCENT : 'rgba(0,255,136,0.3)'),
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { 
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let value = context.raw;
                                        let porcentaje = (value / maxValDetail * 100).toFixed(1);
                                        return `${value} órdenes (${porcentaje}% del máximo)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { color: '#9bb5a5', stepSize: 1 },
                                grid: { color: 'rgba(255,255,255,0.1)' }
                            },
                            x: {
                                ticks: { color: '#9bb5a5', rotation: 45, maxRotation: 45, minRotation: 45 },
                                grid: { color: 'rgba(255,255,255,0.1)' }
                            }
                        }
                    }
                });
            }
            </script>
            
            <div class="insights-grid-modern">
                <div class="insight-card">
                    <div class="insight-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="insight-content">
                        <h4>Consistencia</h4>
                        <p>Coeficiente de variación: <?= ($promedio_js > 0) ? round(($desviacion_js / $promedio_js) * 100, 1) : 0 ?>%</p>
                    </div>
                </div>
                <div class="insight-card">
                    <div class="insight-icon"><i class="fas fa-bolt"></i></div>
                    <div class="insight-content">
                        <h4>Eficiencia</h4>
                        <p><?= $eficiencia_js ?>% vs hora pico</p>
                    </div>
                </div>
                <div class="insight-card">
                    <div class="insight-icon"><i class="fas fa-chart-pie"></i></div>
                    <div class="insight-content">
                        <h4>Calidad</h4>
                        <p><?= $pct_produccion ?>% producción / <?= $pct_quiebras ?>% quiebras</p>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-chart-line"></i>
                <h3>Sin datos disponibles</h3>
                <p>No se encontraron registros en el período seleccionado.</p>
            </div>
        <?php endif; ?>
        
    <?php endif; ?>
</div>

<div class="modern-footer">
    <i class="fas fa-chart-line"></i> Sistema de Control de Producción &nbsp;·&nbsp; © <?= date("Y") ?>
</div>

<!-- Modal para detalles de empleado -->
<div id="employeeModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h2><i class="fas fa-user-circle"></i> <span id="modalEmployeeName">Empleado</span></h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Contenido dinámico cargado via JavaScript -->
        </div>
    </div>
</div>

<script>
// Datos de empleados desde PHP - Convertir a objeto indexado por nombre
const empleadosDataArray = <?= json_encode($datos_detallados_empleados) ?>;

// Crear un objeto donde la clave es el nombre del empleado
const empleadosData = {};
empleadosDataArray.forEach(empleado => {
    empleadosData[empleado.nombre] = empleado;
});

// Variable para almacenar instancias de gráficos del modal
let modalChartProduction = null;
let modalChartQuiebras = null;

function showEmployeeDetails(empleadoNombre) {
    const empleado = empleadosData[empleadoNombre];
    if (!empleado) {
        console.error('Empleado no encontrado:', empleadoNombre);
        return;
    }
    
    // Construir contenido del modal
    const modalBody = document.getElementById('modalBody');
    document.getElementById('modalEmployeeName').textContent = empleado.nombre;
    
    // Calcular métricas adicionales
    const totalActividad = empleado.total_ordenes + empleado.total_quiebras;
    const tasaCalidad = totalActividad > 0 ? ((empleado.total_ordenes / totalActividad) * 100).toFixed(1) : 0;
    const coeficienteVariacion = empleado.promedio > 0 ? ((empleado.desviacion / empleado.promedio) * 100).toFixed(1) : 0;
    const rangoProduccion = empleado.maximo - empleado.minimo;
    
    // Generar HTML para áreas y equipos
    let areasEquiposHTML = '';
    if (empleado.areas && empleado.areas.length > 0) {
        const areasBadges = empleado.areas.map(area => `<span class="area-badge"><i class="fas fa-building"></i> ${escapeHtml(area)}</span>`).join('');
        const equiposBadges = empleado.equipos && empleado.equipos.length > 0 
            ? empleado.equipos.map(equipo => `<span class="equipo-badge"><i class="fas fa-microchip"></i> ${escapeHtml(equipo)}</span>`).join('')
            : '<span class="equipo-badge" style="opacity:0.6;"><i class="fas fa-microchip"></i> Sin equipo asignado</span>';
        
        // Tabla detallada de producción por área/equipo
        let detallesTableHTML = '';
        if (empleado.detalles_areas_equipos && empleado.detalles_areas_equipos.length > 0) {
            detallesTableHTML = `
                <table class="detalle-produccion-table">
                    <thead>
                        <tr>
                            <th>Área</th>
                            <th>Equipo</th>
                            <th>Total Producción</th>
                            <th>% del Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${empleado.detalles_areas_equipos.map(det => {
                            const porcentaje = empleado.total_ordenes > 0 ? ((det.total / empleado.total_ordenes) * 100).toFixed(1) : 0;
                            return `
                                <tr>
                                    <td><i class="fas fa-building"></i> ${escapeHtml(det.area)}</td>
                                    <td><i class="fas fa-microchip"></i> ${escapeHtml(det.equipo)}</td>
                                    <td><strong style="color: var(--accent);">${formatNumber(det.total)}</strong></td>
                                    <td>${porcentaje}%</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
        }
        
        areasEquiposHTML = `
            <div class="areas-equipos-section">
                <h4><i class="fas fa-industry"></i> Áreas y Equipos</h4>
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; color: var(--muted); margin-bottom: 8px;">📌 Áreas de trabajo:</div>
                    <div class="areas-badges">${areasBadges}</div>
                </div>
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; color: var(--muted); margin-bottom: 8px;">🔧 Equipos utilizados:</div>
                    <div class="areas-badges">${equiposBadges}</div>
                </div>
                ${detallesTableHTML}
            </div>
        `;
    } else {
        areasEquiposHTML = `
            <div class="areas-equipos-section">
                <h4><i class="fas fa-industry"></i> Áreas y Equipos</h4>
                <p style="color: var(--text-dim); text-align: center; padding: 20px;">
                    <i class="fas fa-info-circle"></i> No hay información de áreas o equipos para este empleado en el período seleccionado.
                </p>
            </div>
        `;
    }
    
    modalBody.innerHTML = `
        <div class="modal-kpi-grid">
            <div class="modal-kpi-card">
                <div class="kpi-label">Total Órdenes</div>
                <div class="kpi-value">${formatNumber(empleado.total_ordenes)}</div>
                <div class="kpi-sub">Producción total</div>
            </div>
            <div class="modal-kpi-card">
                <div class="kpi-label">Total Quiebras</div>
                <div class="kpi-value" style="color: var(--accent3);">${formatNumber(empleado.total_quiebras)}</div>
                <div class="kpi-sub">${((empleado.total_quiebras / totalActividad) * 100).toFixed(1)}% del total</div>
            </div>
            <div class="modal-kpi-card">
                <div class="kpi-label">Tasa de Calidad</div>
                <div class="kpi-value" style="color: var(--accent4);">${tasaCalidad}%</div>
                <div class="kpi-sub">Producción / Actividad</div>
            </div>
            <div class="modal-kpi-card">
                <div class="kpi-label">Horas Activas</div>
                <div class="kpi-value">${empleado.horas_activas}</div>
                <div class="kpi-sub">Rango horario</div>
            </div>
        </div>
        
        <div class="modal-charts-grid">
            <div class="modal-chart-card">
                <h4><i class="fas fa-chart-line" style="color: var(--accent);"></i> Producción por Hora</h4>
                <canvas id="modalChartProduction" height="200"></canvas>
            </div>
            <div class="modal-chart-card">
                <h4><i class="fas fa-chart-bar" style="color: var(--accent3);"></i> Quiebras por Hora</h4>
                <canvas id="modalChartQuiebras" height="200"></canvas>
            </div>
        </div>
        
        <div class="modal-stats-grid">
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-chart-line"></i> Promedio por Hora</span>
                <span class="stat-value" style="color: var(--accent4);">${empleado.promedio.toFixed(1)}</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-tachometer-alt"></i> Mediana</span>
                <span class="stat-value">${empleado.mediana.toFixed(1)}</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-chart-line"></i> Desviación</span>
                <span class="stat-value">±${empleado.desviacion.toFixed(1)}</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-waveform"></i> Coef. Variación</span>
                <span class="stat-value">${coeficienteVariacion}%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-fire"></i> Hora Pico</span>
                <span class="stat-value" style="color: var(--accent);">${escapeHtml(empleado.hora_pico)} (${empleado.maximo})</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-snowflake"></i> Hora Valle</span>
                <span class="stat-value" style="color: var(--accent3);">${escapeHtml(empleado.hora_valle)} (${empleado.minimo})</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-chart-simple"></i> Rango Producción</span>
                <span class="stat-value">${rangoProduccion}</span>
            </div>
            <div class="stat-item">
                <span class="stat-label"><i class="fas fa-chart-line"></i> Tendencia</span>
                <span class="stat-value" style="color: ${empleado.tendencia >= 0 ? 'var(--accent)' : 'var(--accent3)'};">${empleado.tendencia >= 0 ? '+' : ''}${empleado.tendencia}%</span>
            </div>
        </div>
        
        ${areasEquiposHTML}
        
        <div style="margin-top: 24px; padding: 16px; background: var(--surface2); border-radius: var(--radius-sm);">
            <h4 style="font-family: var(--font-mono); font-size: 12px; margin-bottom: 12px;"><i class="fas fa-chart-pie"></i> Distribución Porcentual por Hora</h4>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                ${empleado.horas_labels.map((hora, idx) => `
                    <span style="background: var(--surface3); padding: 4px 12px; border-radius: 20px; font-size: 11px;">
                        ${escapeHtml(hora)}: <strong style="color: var(--accent);">${empleado.distribucion[idx]}%</strong>
                    </span>
                `).join('')}
            </div>
        </div>
    `;
    
    // Mostrar modal
    const modal = document.getElementById('employeeModal');
    modal.classList.add('active');
    
    // Crear gráficos después de que el DOM esté listo
    setTimeout(() => {
        // Destruir gráficos anteriores si existen
        if (modalChartProduction) modalChartProduction.destroy();
        if (modalChartQuiebras) modalChartQuiebras.destroy();
        
        // Gráfico de producción
        const prodCtx = document.getElementById('modalChartProduction')?.getContext('2d');
        if (prodCtx) {
            modalChartProduction = new Chart(prodCtx, {
                type: 'line',
                data: {
                    labels: empleado.horas_labels,
                    datasets: [{
                        label: 'Órdenes',
                        data: empleado.totales_detalle,
                        borderColor: '#00ff88',
                        backgroundColor: 'rgba(0,255,136,0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#00ff88'
                    }, {
                        label: 'Promedio',
                        data: Array(empleado.horas_labels.length).fill(empleado.promedio),
                        borderColor: '#ffcd4a',
                        borderDash: [5, 5],
                        borderWidth: 1.5,
                        pointRadius: 0,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { labels: { color: '#9bb5a5', font: { size: 10 } } }
                    },
                    scales: {
                        y: {
                            ticks: { color: '#9bb5a5' },
                            grid: { color: 'rgba(255,255,255,0.1)' }
                        },
                        x: {
                            ticks: { color: '#9bb5a5', rotation: 45, maxRotation: 45, minRotation: 45 }
                        }
                    }
                }
            });
        }
        
        // Gráfico de quiebras
        const quiebrasCtx = document.getElementById('modalChartQuiebras')?.getContext('2d');
        if (quiebrasCtx) {
            modalChartQuiebras = new Chart(quiebrasCtx, {
                type: 'bar',
                data: {
                    labels: empleado.horas_labels,
                    datasets: [{
                        label: 'Quiebras',
                        data: empleado.quiebras_por_hora,
                        backgroundColor: 'rgba(255,94,94,0.7)',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { labels: { color: '#9bb5a5', font: { size: 10 } } }
                    },
                    scales: {
                        y: {
                            ticks: { color: '#9bb5a5' },
                            grid: { color: 'rgba(255,255,255,0.1)' }
                        },
                        x: {
                            ticks: { color: '#9bb5a5', rotation: 45, maxRotation: 45, minRotation: 45 }
                        }
                    }
                }
            });
        }
    }, 100);
}

function closeModal() {
    const modal = document.getElementById('employeeModal');
    modal.classList.remove('active');
    // Destruir gráficos al cerrar para liberar memoria
    if (modalChartProduction) {
        modalChartProduction.destroy();
        modalChartProduction = null;
    }
    if (modalChartQuiebras) {
        modalChartQuiebras.destroy();
        modalChartQuiebras = null;
    }
}

function formatNumber(num) {
    return new Intl.NumberFormat('es-ES').format(num);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Cerrar modal al hacer click fuera
document.getElementById('employeeModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Cerrar con tecla ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Agregar evento click a las filas de la tabla para mostrar detalles
document.querySelectorAll('#empleados-table tbody tr').forEach(row => {
    row.addEventListener('click', function(e) {
        // Evitar que el botón dispare dos veces
        if (e.target.classList.contains('btn-detail') || e.target.closest('.btn-detail')) {
            return;
        }
        const nombre = this.getAttribute('data-empleado-nombre');
        if (nombre !== null) {
            showEmployeeDetails(nombre);
        }
    });
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