<?php
/**
 * DASHBOARD LABORATORIO PRO - v3.0
 * Sistema integral de análisis de quiebras por equipo, turno y responsable
 * 
 * CONEXIÓN DIRECTA a tu base de datos existente
 * Respeta toda la estructura de tus tablas: registro_quiebras, produccion, registros_antiguos
 * 
 * @author Integrado con tu backend.php
 * @version 3.0
 */

session_start();
date_default_timezone_set('America/Costa_Rica');

// ============================================
// CONEXIÓN A TU BASE DE DATOS EXISTENTE
// ============================================
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'produccion_quiebras'; 

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Configurar charset
$conn->set_charset("utf8mb4");

// ============================================
// FUNCIONES COMPATIBLES CON TU SISTEMA
// ============================================

function format_number($n) {
    return number_format($n, 0, '.', ',');
}

function tiempo24a12($hora24) {
    if (empty($hora24) || $hora24 === '00:00:00') return '12:00 AM';
    $timestamp = strtotime($hora24);
    return date('g:i A', $timestamp);
}

function getIconoEquipo($equipo) {
    $iconos = [
        'CB Bond' => '🔷', 'Smart XP' => '⚙️', 'CCP Switch' => '🔧',
        'CCL Mark' => '🏷️', 'Schneider' => '⚡', 'XTS' => '🔄',
        'DBA' => '💎', 'Bloqueadora' => '🟦', 'Pulidora' => '✨',
        'Empaque' => '📦', 'Bodega' => '🏪'
    ];
    foreach ($iconos as $key => $icon) {
        if (stripos($equipo, $key) !== false) return $icon;
    }
    return '🛠️';
}

// ============================================
// OBTENER DATOS DEL DASHBOARD
// ============================================

// 1. Resumen general (últimos 30 días)
$sqlResumen = "SELECT 
    COUNT(*) as total_quiebras,
    COUNT(DISTINCT equipo) as equipos_afectados,
    COUNT(DISTINCT empleado) as empleados_con_quiebras,
    COUNT(DISTINCT turno) as turnos_afectados,
    COUNT(DISTINCT responsable) as responsables_involucrados
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$resumen = $conn->query($sqlResumen)->fetch_assoc();

// 2. Quiebras por equipo (top 15)
$sqlEquipos = "SELECT 
    equipo, 
    COUNT(*) as total,
    COUNT(DISTINCT empleado) as empleados,
    COUNT(DISTINCT motivo) as motivos_distintos,
    COUNT(DISTINCT orden) as ordenes_afectadas
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND equipo IS NOT NULL AND equipo != ''
GROUP BY equipo 
ORDER BY total DESC
LIMIT 15";
$equiposData = $conn->query($sqlEquipos)->fetch_all(MYSQLI_ASSOC);

// 3. Quiebras por turno
$sqlTurnos = "SELECT 
    turno, 
    COUNT(*) as total,
    COUNT(DISTINCT equipo) as equipos,
    COUNT(DISTINCT empleado) as empleados
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND turno IS NOT NULL AND turno != ''
GROUP BY turno
ORDER BY FIELD(turno, 'A', 'B', 'C')";
$turnosData = $conn->query($sqlTurnos)->fetch_all(MYSQLI_ASSOC);

// 4. Top motivos de quiebra
$sqlMotivos = "SELECT 
    motivo, 
    COUNT(*) as total,
    GROUP_CONCAT(DISTINCT equipo SEPARATOR ', ') as equipos
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND motivo IS NOT NULL AND motivo != ''
GROUP BY motivo 
ORDER BY total DESC
LIMIT 10";
$motivosData = $conn->query($sqlMotivos)->fetch_all(MYSQLI_ASSOC);

// 5. Quiebras por hora del día
$sqlPorHora = "SELECT 
    HOUR(hora) as hora,
    COUNT(*) as total
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY HOUR(hora)
ORDER BY hora ASC";
$horaData = $conn->query($sqlPorHora)->fetch_all(MYSQLI_ASSOC);

// 6. Top empleados con más quiebras
$sqlEmpleados = "SELECT 
    empleado, 
    COUNT(*) as total,
    GROUP_CONCAT(DISTINCT equipo SEPARATOR ', ') as equipos,
    GROUP_CONCAT(DISTINCT motivo SEPARATOR ', ') as motivos
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND empleado IS NOT NULL AND empleado != '' AND empleado != 'N/A'
GROUP BY empleado 
ORDER BY total DESC
LIMIT 15";
$empleadosData = $conn->query($sqlEmpleados)->fetch_all(MYSQLI_ASSOC);

// 7. Top responsables con más quiebras
$sqlResponsables = "SELECT 
    responsable, 
    COUNT(*) as total,
    COUNT(DISTINCT empleado) as empleados_afectados,
    COUNT(DISTINCT equipo) as equipos_afectados
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND responsable IS NOT NULL AND responsable != '' AND responsable != 'N/A'
GROUP BY responsable 
ORDER BY total DESC
LIMIT 15";
$responsablesData = $conn->query($sqlResponsables)->fetch_all(MYSQLI_ASSOC);

// 8. Tendencia diaria (últimos 30 días)
$sqlTendencia = "SELECT 
    DATE(fecha) as dia,
    COUNT(*) as quiebras
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(fecha)
ORDER BY dia ASC";
$tendenciaData = $conn->query($sqlTendencia)->fetch_all(MYSQLI_ASSOC);

// 9. Quiebras por tipo de lente
$sqlLentes = "SELECT 
    tipo_lente,
    COUNT(*) as quiebras
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND tipo_lente IS NOT NULL AND tipo_lente != ''
GROUP BY tipo_lente
ORDER BY quiebras DESC
LIMIT 8";
$lentesData = $conn->query($sqlLentes)->fetch_all(MYSQLI_ASSOC);

// 10. Quiebras por área
$sqlAreas = "SELECT 
    area,
    COUNT(*) as quiebras
FROM registro_quiebras 
WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
AND area IS NOT NULL AND area != ''
GROUP BY area
ORDER BY quiebras DESC";
$areasData = $conn->query($sqlAreas)->fetch_all(MYSQLI_ASSOC);

// ============================================
// MATRIZ DE CAUSAS Y SOLUCIONES
// ============================================
$causasSoluciones = [
    'CB Bond' => [
        'causas' => [
            'Bloqueo descentrado' => 'Bloqueo mal alineado, suciedad en chucks',
            'Lente desprendido' => 'Adhesivo insuficiente, tiempo de curado bajo',
            'Marca en lente' => 'Chuck sucio o dañado, presión excesiva'
        ],
        'soluciones' => [
            '✅ Calibrar centrado semanalmente',
            '✅ Usar adhesivo de calidad y respetar tiempo de curado (15-20 seg)',
            '✅ Limpiar chucks con alcohol isopropílico cada turno'
        ]
    ],
    'Smart XP' => [
        'causas' => [
            'Generación incorrecta' => 'Parámetros de lente mal ingresados',
            'Astigmatismo residual' => 'Eje desalineado, herramienta desgastada',
            'Superficie rayada' => 'Partículas en el coolant, herramienta dañada'
        ],
        'soluciones' => [
            '✅ Verificar Rx antes de generar',
            '✅ Cambiar herramienta cada 300 lentes o al primer signo de desgaste',
            '✅ Filtrar coolant diariamente, cambiar semanalmente'
        ]
    ],
    'CCP Switch' => [
        'causas' => [
            'Borde irregular' => 'Velocidad incorrecta, herramienta desgastada',
            'Medida fuera de tolerancia' => 'Calibración perdida, suciedad en encoder',
            'Marca de pinza' => 'Gomas de sujeción viejas o dañadas'
        ],
        'soluciones' => [
            '✅ Revisar parámetros de velocidad por material',
            '✅ Re-calibrar mensualmente',
            '✅ Cambiar gomas de sujeción cada 2 meses'
        ]
    ],
    'CCL Mark' => [
        'causas' => [
            'Marcado borroso' => 'Láser desenfocado, lente sucio',
            'Marcado descentrado' => 'Alineación perdida',
            'Sin marcado' => 'Fusible o fuente dañada'
        ],
        'soluciones' => [
            '✅ Limpiar óptica del láser diariamente',
            '✅ Realinear con patrón de prueba semanal',
            '✅ Verificar conexiones eléctricas'
        ]
    ],
    'Schneider' => [
        'causas' => [
            'Error de bloqueo' => 'Sincronización perdida',
            'Medida incorrecta' => 'Probe descalibrado',
            'Lente dañado' => 'Velocidad de avance excesiva'
        ],
        'soluciones' => [
            '✅ Reiniciar módulo y recalibrar eje',
            '✅ Calibrar probe con patrón patrón cada 15 días',
            '✅ Reducir velocidad para materiales frágiles'
        ]
    ],
    'XTS' => [
        'causas' => [
            'Tiempo excesivo' => 'Parámetros de ciclo subóptimos',
            'Errores de lectura' => 'Código de barras dañado',
            'Lente roto' => 'Mordazas mal ajustadas'
        ],
        'soluciones' => [
            '✅ Optimizar secuencia de procesos',
            '✅ Imprimir códigos de barras con alta calidad',
            '✅ Ajustar presión de mordazas según tipo de lente'
        ]
    ],
    'DBA' => [
        'causas' => [
            'Medición inexacta' => 'Base de datos desactualizada',
            'Lente no reconocido' => 'Curva base fuera de rango',
            'Error de cálculo' => 'Parámetros de material incorrectos'
        ],
        'soluciones' => [
            '✅ Actualizar base de datos mensualmente',
            '✅ Ingresar curva base manualmente si es necesario',
            '✅ Verificar tipo de material antes de procesar'
        ]
    ]
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard PRO | Laboratorio Óptico</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com/3.3.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e8f0fe 100%);
            min-height: 100vh;
        }
        .card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(2px);
            border-radius: 1.5rem;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 30px -12px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-left: 5px solid;
        }
        .badge-equipo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .solucion-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-left: 4px solid #22c55e;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        .causa-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-left: 4px solid #ef4444;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        .chart-container {
            height: 280px;
            position: relative;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeInUp 0.5s ease-out;
        }
        .gradient-text {
            background: linear-gradient(135deg, #1e293b, #3b82f6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .scroll-custom::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .scroll-custom::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 10px;
        }
        .scroll-custom::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 10px;
        }
        @media print {
            body { background: white; }
            .card { break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
            .no-print { display: none; }
        }
    </style>
</head>
<body class="p-4 md:p-6">

<div class="max-w-7xl mx-auto">

    <!-- HEADER -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4 animate-fade-in">
        <div>
            <h1 class="text-3xl md:text-4xl font-bold flex items-center gap-3">
                <i class="bi bi-eyeglasses text-4xl text-indigo-600"></i>
                <span class="gradient-text">Dashboard Laboratorio PRO</span>
            </h1>
            <p class="text-gray-500 mt-1 flex items-center gap-2 flex-wrap">
                <i class="bi bi-calendar3"></i> Datos últimos 30 días
                <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                <i class="bi bi-arrow-repeat"></i> Actualización en tiempo real
                <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                <i class="bi bi-database"></i> Conectado a tu base de datos
            </p>
        </div>
        <div class="flex gap-3">
            <button onclick="location.reload()" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-5 py-2.5 rounded-xl transition flex items-center gap-2 font-medium">
                <i class="bi bi-arrow-repeat"></i> Actualizar
            </button>
            <button onclick="window.print()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2.5 rounded-xl transition flex items-center gap-2 font-medium">
                <i class="bi bi-printer"></i> Exportar PDF
            </button>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 animate-fade-in">
        <div class="card p-5 stat-card border-l-emerald-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Total Quiebras</p>
                    <p class="text-3xl font-bold text-emerald-600"><?= format_number($resumen['total_quiebras'] ?? 0) ?></p>
                </div>
                <div class="bg-emerald-100 p-3 rounded-2xl"><i class="bi bi-exclamation-triangle-fill text-emerald-600 text-xl"></i></div>
            </div>
        </div>
        <div class="card p-5 stat-card border-l-purple-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Equipos Afectados</p>
                    <p class="text-3xl font-bold text-purple-600"><?= $resumen['equipos_afectados'] ?? 0 ?></p>
                </div>
                <div class="bg-purple-100 p-3 rounded-2xl"><i class="bi bi-cpu-fill text-purple-600 text-xl"></i></div>
            </div>
        </div>
        <div class="card p-5 stat-card border-l-amber-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Empleados</p>
                    <p class="text-3xl font-bold text-amber-600"><?= $resumen['empleados_con_quiebras'] ?? 0 ?></p>
                </div>
                <div class="bg-amber-100 p-3 rounded-2xl"><i class="bi bi-people-fill text-amber-600 text-xl"></i></div>
            </div>
        </div>
        <div class="card p-5 stat-card border-l-blue-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Turnos / Responsables</p>
                    <p class="text-3xl font-bold text-blue-600"><?= ($resumen['turnos_afectados'] ?? 0) . ' / ' . ($resumen['responsables_involucrados'] ?? 0) ?></p>
                </div>
                <div class="bg-blue-100 p-3 rounded-2xl"><i class="bi bi-clock-history text-blue-600 text-xl"></i></div>
            </div>
        </div>
    </div>

    <!-- GRÁFICOS PRINCIPALES -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 animate-fade-in">
        <!-- Tendencia -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-graph-up text-indigo-500"></i> Tendencia de Quiebras (30 días)</h3>
            <div class="chart-container"><canvas id="trendChart"></canvas></div>
        </div>
        <!-- Por Turno -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-clock text-amber-500"></i> Distribución por Turno</h3>
            <div class="chart-container"><canvas id="turnoChart"></canvas></div>
            <div class="mt-4 text-center text-sm text-gray-500">
                <span class="inline-block w-3 h-3 rounded-full bg-blue-500 mr-1"></span> Turno A (6am-2pm)
                <span class="inline-block w-3 h-3 rounded-full bg-amber-500 mr-1 ml-3"></span> Turno B (2pm-9pm)
                <span class="inline-block w-3 h-3 rounded-full bg-purple-500 mr-1 ml-3"></span> Turno C (9pm-3am)
            </div>
        </div>
    </div>

    <!-- EQUIPOS + MOTIVOS -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 animate-fade-in">
        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-cpu-fill text-purple-600"></i> Equipos con más Quiebras</h3>
            <?php if (empty($equiposData)): ?>
                <div class="text-center py-8 text-gray-400"><i class="bi bi-inbox text-4xl"></i><p>Sin datos</p></div>
            <?php else: ?>
                <div class="space-y-3 max-h-[450px] overflow-y-auto scroll-custom pr-2">
                    <?php 
                    $maxTotal = max(array_column($equiposData, 'total'));
                    foreach ($equiposData as $eq): 
                        $porcentaje = ($eq['total'] / $maxTotal) * 100;
                    ?>
                    <div class="bg-gray-50 rounded-xl p-3 hover:bg-gray-100 transition cursor-pointer" onclick="mostrarInfoEquipo('<?= addslashes($eq['equipo']) ?>')">
                        <div class="flex justify-between items-center mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-xl"><?= getIconoEquipo($eq['equipo']) ?></span>
                                <span class="font-semibold text-gray-700"><?= htmlspecialchars(mb_strimwidth($eq['equipo'], 0, 30, '...')) ?></span>
                            </div>
                            <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-sm font-bold"><?= $eq['total'] ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mb-2"><div class="bg-red-500 h-2 rounded-full" style="width: <?= $porcentaje ?>%"></div></div>
                        <div class="flex gap-3 text-xs text-gray-500"><span><i class="bi bi-people"></i> <?= $eq['empleados'] ?> empleados</span><span><i class="bi bi-tags"></i> <?= $eq['motivos_distintos'] ?> motivos</span><span><i class="bi bi-clipboard"></i> <?= $eq['ordenes_afectadas'] ?> órdenes</span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-bar-chart-steps text-amber-600"></i> Principales Motivos de Quiebra</h3>
            <?php if (empty($motivosData)): ?>
                <div class="text-center py-8 text-gray-400"><i class="bi bi-inbox text-4xl"></i><p>Sin datos</p></div>
            <?php else: ?>
                <div class="space-y-3 max-h-[450px] overflow-y-auto scroll-custom pr-2">
                    <?php 
                    $maxMotivo = $motivosData[0]['total'];
                    foreach ($motivosData as $m): 
                        $porc = ($m['total'] / $maxMotivo) * 100;
                    ?>
                    <div class="border-l-4 border-amber-400 bg-amber-50/50 p-3 rounded-r-xl">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <p class="font-medium text-gray-700 text-sm"><?= htmlspecialchars(mb_strimwidth($m['motivo'], 0, 50, '...')) ?></p>
                                <p class="text-xs text-gray-400 mt-1"><i class="bi bi-cpu"></i> Equipos: <?= htmlspecialchars(mb_strimwidth($m['equipos'], 0, 40, '...')) ?></p>
                            </div>
                            <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full text-sm font-bold"><?= $m['total'] ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="bg-amber-500 h-1.5 rounded-full" style="width: <?= $porc ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MATRIZ CAUSAS Y SOLUCIONES -->
    <div class="card p-5 mb-6 animate-fade-in">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-lightbulb-fill text-yellow-500"></i> Matriz de Causas y Soluciones por Equipo</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 max-h-[500px] overflow-y-auto scroll-custom">
            <?php foreach ($causasSoluciones as $equipo => $data): ?>
            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm hover:shadow-md transition">
                <div class="bg-gradient-to-r from-slate-800 to-slate-700 text-white px-4 py-2.5 font-semibold text-sm flex items-center gap-2">
                    <span class="text-lg"><?= getIconoEquipo($equipo) ?></span> <?= $equipo ?>
                </div>
                <div class="p-3 space-y-3">
                    <div>
                        <p class="text-xs font-semibold text-red-600 uppercase tracking-wide mb-2">⚠️ Causas comunes</p>
                        <?php foreach ($data['causas'] as $causa => $desc): ?>
                        <div class="causa-card text-xs"><span class="font-medium text-red-700"><?= $causa ?>:</span> <span class="text-gray-600"><?= $desc ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-green-600 uppercase tracking-wide mb-2">✅ Soluciones directas</p>
                        <?php foreach ($data['soluciones'] as $sol): ?>
                        <div class="solucion-card text-xs"><i class="bi bi-check-circle-fill text-green-500 text-xs mr-1"></i> <span class="text-gray-700"><?= $sol ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- QUIEBRAS POR HORA + TOP EMPLEADOS + RESPONSABLES -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 animate-fade-in">
        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-clock-fill text-indigo-500"></i> Quiebras por Hora del Día</h3>
            <div class="chart-container"><canvas id="horaChart"></canvas></div>
            <p class="text-xs text-center text-gray-400 mt-3"><i class="bi bi-info-circle"></i> Identifica picos de quiebras para ajustar supervisión</p>
        </div>

        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-person-badge text-cyan-600"></i> Empleados con más Quiebras</h3>
            <?php if (empty($empleadosData)): ?>
                <div class="text-center py-8 text-gray-400"><i class="bi bi-inbox text-4xl"></i><p>Sin datos</p></div>
            <?php else: ?>
                <div class="space-y-2 max-h-[350px] overflow-y-auto scroll-custom">
                    <?php 
                    $maxEmp = $empleadosData[0]['total'];
                    foreach ($empleadosData as $e): 
                        $ancho = ($e['total'] / $maxEmp) * 100;
                    ?>
                    <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded-lg">
                        <div class="flex-1">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-medium text-gray-700 text-sm truncate max-w-[150px]"><?= htmlspecialchars($e['empleado']) ?></span>
                                <span class="text-red-600 font-bold text-sm"><?= $e['total'] ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-1.5"><div class="bg-red-500 h-1.5 rounded-full" style="width: <?= $ancho ?>%"></div></div>
                            <p class="text-xs text-gray-400 mt-1 truncate"><i class="bi bi-cpu"></i> <?= htmlspecialchars(mb_strimwidth($e['equipos'], 0, 40, '...')) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RESPONSABLES + TIPO LENTE + ÁREAS -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6 animate-fade-in">
        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-person-gear text-purple-600"></i> Responsables</h3>
            <?php if (empty($responsablesData)): ?>
                <div class="text-center py-6 text-gray-400"><i class="bi bi-inbox text-3xl"></i><p>Sin datos</p></div>
            <?php else: ?>
                <div class="space-y-2 max-h-[280px] overflow-y-auto scroll-custom">
                    <?php foreach ($responsablesData as $r): ?>
                    <div class="bg-gray-50 p-3 rounded-xl flex justify-between items-center">
                        <div><span class="font-medium"><?= htmlspecialchars($r['responsable']) ?></span><div class="text-xs text-gray-400"><?= $r['empleados_afectados'] ?> empleados, <?= $r['equipos_afectados'] ?> equipos</div></div>
                        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-sm font-bold"><?= $r['total'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-layers text-blue-500"></i> Tipo de Lente</h3>
            <div class="chart-container"><canvas id="lenteChart"></canvas></div>
        </div>

        <div class="card p-5">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-building text-emerald-500"></i> Áreas</h3>
            <div class="chart-container"><canvas id="areaChart"></canvas></div>
        </div>
    </div>

    <!-- PLAN DE ACCIÓN -->
    <div class="card p-5 mb-6 bg-gradient-to-r from-slate-800 to-slate-900 text-white animate-fade-in">
        <h3 class="font-bold text-xl mb-4 flex items-center gap-2"><i class="bi bi-clipboard-check text-green-400"></i> Plan de Acción - Decisiones Estratégicas</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white/10 rounded-xl p-4"><div class="flex items-center gap-2 mb-2"><i class="bi bi-calendar-week text-cyan-400 text-xl"></i><span class="font-semibold">Corto Plazo (1-7 días)</span></div><ul class="text-sm space-y-2 text-slate-200"><li>✓ Limpieza de chucks CB Bond</li><li>✓ Verificar coolant Smart XP</li><li>✓ Reunión con turno con más quiebras</li></ul></div>
            <div class="bg-white/10 rounded-xl p-4"><div class="flex items-center gap-2 mb-2"><i class="bi bi-calendar-month text-amber-400 text-xl"></i><span class="font-semibold">Mediano Plazo (2-4 semanas)</span></div><ul class="text-sm space-y-2 text-slate-200"><li>✓ Capacitación específica por equipo</li><li>✓ Implementar checklist inicio de turno</li><li>✓ Rotación de operadores</li></ul></div>
            <div class="bg-white/10 rounded-xl p-4"><div class="flex items-center gap-2 mb-2"><i class="bi bi-calendar3 text-purple-400 text-xl"></i><span class="font-semibold">Largo Plazo (1-3 meses)</span></div><ul class="text-sm space-y-2 text-slate-200"><li>✓ Mantenimiento preventivo programado</li><li>✓ Dashboard KPIs en tiempo real</li><li>✓ Certificación interna de operadores</li></ul></div>
        </div>
    </div>

    <div class="text-center text-gray-400 text-xs py-4 border-t border-gray-200">
        <p>Dashboard Laboratorio PRO | Datos actualizados: <?= date('d/m/Y H:i:s') ?> | Basado en tus tablas: registro_quiebras, produccion, registros_antiguos</p>
    </div>
</div>

<script>
// Datos PHP a JavaScript
const tendenciaQuiebras = <?= json_encode(array_column($tendenciaData, 'quiebras')) ?>;
const fechasTendencia = <?= json_encode(array_map(function($d) { return date('d/m', strtotime($d['dia'])); }, $tendenciaData)) ?>;
const turnosLabels = <?= json_encode(array_column($turnosData, 'turno')) ?>;
const turnosValues = <?= json_encode(array_column($turnosData, 'total')) ?>;
const horasData = <?= json_encode($horaData) ?>;
const lentesData = <?= json_encode($lentesData) ?>;
const areasData = <?= json_encode($areasData) ?>;

// Gráfico de tendencia
const trendCtx = document.getElementById('trendChart');
if (trendCtx && tendenciaQuiebras.length) {
    new Chart(trendCtx, {
        type: 'line',
        data: { labels: fechasTendencia, datasets: [{ label: 'Quiebras por día', data: tendenciaQuiebras, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3, fill: true, pointBackgroundColor: '#ef4444', pointBorderColor: '#fff', pointRadius: 3 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'Número de quiebras' } } } }
    });
}

// Gráfico de turnos
const turnoCtx = document.getElementById('turnoChart');
if (turnoCtx && turnosLabels.length) {
    new Chart(turnoCtx, { type: 'doughnut', data: { labels: turnosLabels.map(t => `Turno ${t}`), datasets: [{ data: turnosValues, backgroundColor: ['#3b82f6', '#f59e0b', '#8b5cf6'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } });
}

// Gráfico por hora
const horaCtx = document.getElementById('horaChart');
if (horaCtx) {
    const mapa = {}; horasData.forEach(h => { mapa[h.hora] = h.total; });
    const valores = Array.from({length: 24}, (_, i) => mapa[i] || 0);
    new Chart(horaCtx, { type: 'bar', data: { labels: Array.from({length: 24}, (_, i) => `${i.toString().padStart(2,'0')}:00`), datasets: [{ label: 'Quiebras', data: valores, backgroundColor: '#f59e0b', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxRotation: 45, minRotation: 45 } }, y: { beginAtZero: true } } } });
}

// Gráfico tipo lente
const lenteCtx = document.getElementById('lenteChart');
if (lenteCtx && lentesData.length) {
    new Chart(lenteCtx, { type: 'bar', data: { labels: lentesData.map(l => l.tipo_lente), datasets: [{ label: 'Quiebras', data: lentesData.map(l => l.quiebras), backgroundColor: '#8b5cf6', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } } });
}

// Gráfico áreas
const areaCtx = document.getElementById('areaChart');
if (areaCtx && areasData.length) {
    new Chart(areaCtx, { type: 'bar', data: { labels: areasData.map(a => a.area), datasets: [{ label: 'Quiebras', data: areasData.map(a => a.quiebras), backgroundColor: '#10b981', borderRadius: 6 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
}

function mostrarInfoEquipo(equipo) {
    alert("🔧 " + equipo + "\n\nRevisa la matriz de causas y soluciones para este equipo. Contacta al supervisor de mantenimiento.");
}
</script>

</body>
</html>