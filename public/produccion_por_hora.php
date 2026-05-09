<?php
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($empleado_detalle)) {
    // Soporte tanto para POST como para inclusión directa
    $empleado = $_POST['empleado_detalle'] ?? $empleado_detalle ?? '';
    $inicio = $_POST['fecha_detalle_inicio'] ?? $fecha_detalle_inicio ?? '';
    $fin = $_POST['fecha_detalle_fin'] ?? $fecha_detalle_fin ?? '';
    
    // Agregar horas por defecto si no vienen
    $inicio_completo = $inicio . ' 00:00:00';
    $fin_completo = $fin . ' 23:59:59';

    // Consulta optimizada con formato de hora AM/PM
    $query = "SELECT 
                DATE_FORMAT(fecha, '%l %p') as hora_label,
                HOUR(fecha) as hora_num,
                COUNT(*) as total
              FROM produccion
              WHERE empleado = ? AND fecha BETWEEN ? AND ?
              GROUP BY hora_num
              ORDER BY hora_num";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $empleado, $inicio_completo, $fin_completo);
    $stmt->execute();
    $result = $stmt->get_result();

    $horas = [];
    $totales = [];

    while ($row = $result->fetch_assoc()) {
        $horas[] = $row['hora_label'];
        $totales[] = (int)$row['total'];
    }
    
    // Calcular métricas adicionales
    $total_ordenes = array_sum($totales);
    $promedio = !empty($totales) ? round($total_ordenes / count($totales), 2) : 0;
    $maximo = !empty($totales) ? max($totales) : 0;
    $minimo = !empty($totales) ? min($totales) : 0;
    $hora_pico = !empty($horas) && !empty($totales) ? $horas[array_search($maximo, $totales)] : '—';
    $hora_valle = !empty($horas) && !empty($totales) ? $horas[array_search($minimo, $totales)] : '—';
    
    // Calcular desviación
    $desviacion = 0;
    if (!empty($totales) && count($totales) > 1) {
        $varianza = array_sum(array_map(function($v) use ($promedio) {
            return pow($v - $promedio, 2);
        }, $totales)) / count($totales);
        $desviacion = sqrt($varianza);
    }
    
    // Calcular eficiencia
    $eficiencia = ($maximo > 0) ? round(($promedio / $maximo) * 100, 1) : 0;
}
?>

<!-- Mostrar resultados -->
<?php if (!empty($horas)): ?>
    <div style="margin-bottom: 20px;">
        <h3 style="color: white; margin-bottom: 15px;">📊 Producción por hora de <?= htmlspecialchars($empleado) ?></h3>
        
        <!-- KPIs rápidos -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px;">
            <div style="background: #0e1612; border: 1px solid #23332a; border-radius: 12px; padding: 12px 16px;">
                <div style="font-size: 11px; color: #4a6b5a; text-transform: uppercase;">Total Órdenes</div>
                <div style="font-size: 24px; font-weight: bold; color: #00ff88;"><?= number_format($total_ordenes) ?></div>
            </div>
            <div style="background: #0e1612; border: 1px solid #23332a; border-radius: 12px; padding: 12px 16px;">
                <div style="font-size: 11px; color: #4a6b5a; text-transform: uppercase;">Promedio/Hora</div>
                <div style="font-size: 24px; font-weight: bold; color: #ffcd4a;"><?= number_format($promedio, 1) ?></div>
            </div>
            <div style="background: #0e1612; border: 1px solid #23332a; border-radius: 12px; padding: 12px 16px;">
                <div style="font-size: 11px; color: #4a6b5a; text-transform: uppercase;">Hora Pico</div>
                <div style="font-size: 18px; font-weight: bold; color: #00ff88;"><?= htmlspecialchars($hora_pico) ?></div>
                <div style="font-size: 12px; color: #9bb5a5;"><?= $maximo ?> órdenes</div>
            </div>
            <div style="background: #0e1612; border: 1px solid #23332a; border-radius: 12px; padding: 12px 16px;">
                <div style="font-size: 11px; color: #4a6b5a; text-transform: uppercase;">Eficiencia</div>
                <div style="font-size: 24px; font-weight: bold; color: #00ff88;"><?= $eficiencia ?>%</div>
            </div>
        </div>
        
        <canvas id="grafico_produccion" style="background: #0e1612; border-radius: 12px; padding: 15px;"></canvas>
    </div>

    <script>
        const ctxProd = document.getElementById('grafico_produccion').getContext('2d');
        const horasData = <?= json_encode($horas) ?>;
        const totalesData = <?= json_encode($totales) ?>;
        const promedioData = <?= $promedio ?>;
        const maximoData = <?= $maximo ?>;
        
        new Chart(ctxProd, {
            type: 'line',
            data: {
                labels: horasData,
                datasets: [
                    {
                        label: 'Órdenes por hora',
                        data: totalesData,
                        backgroundColor: 'rgba(40, 167, 69, 0.2)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: '#28a745',
                        pointBorderColor: '#fff'
                    },
                    {
                        label: 'Promedio',
                        data: Array(horasData.length).fill(promedioData),
                        borderColor: '#ffcd4a',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: false,
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    title: {
                        display: true,
                        text: '📈 Producción Horaria - <?= htmlspecialchars($empleado) ?>',
                        color: 'white',
                        font: { weight: 'bold', size: 14 }
                    },
                    legend: { 
                        labels: { color: 'white' },
                        position: 'top'
                    },
                    tooltip: {
                        titleColor: 'white',
                        bodyColor: 'white',
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                let value = context.raw;
                                let porcentaje = (value / maximoData * 100).toFixed(1);
                                return `${label}: ${value} órdenes (${porcentaje}% del pico)`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { 
                            color: 'white',
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        },
                        title: {
                            display: true,
                            text: 'Cantidad de órdenes',
                            color: 'white'
                        }
                    },
                    x: {
                        ticks: { 
                            color: 'white',
                            rotation: 45,
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        },
                        title: {
                            display: true,
                            text: 'Hora del día',
                            color: 'white'
                        }
                    }
                }
            }
        });
        
        console.log('📊 Gráfico cargado para:', '<?= htmlspecialchars($empleado) ?>', { horas: horasData, totales: totalesData });
    </script>
<?php else: ?>
    <div style="background: #0e1612; border: 1px solid #23332a; border-radius: 12px; padding: 40px; text-align: center;">
        <i class="fas fa-chart-line" style="font-size: 48px; color: #4a6b5a; margin-bottom: 15px;"></i>
        <p style="color: white;">No hay datos de producción para <?= htmlspecialchars($empleado) ?> en este rango.</p>
        <p style="color: #9bb5a5; font-size: 13px;">Período: <?= htmlspecialchars($inicio) ?> al <?= htmlspecialchars($fin) ?></p>
    </div>
<?php endif; ?>