<?php
session_start();

require_once '../config/database.php';

// Zona horaria consistente con registro.php
date_default_timezone_set('America/Costa_Rica');

// Parámetros de paginación
$por_pagina = 50;
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$orden_buscar = trim($_GET['orden_buscar'] ?? '');
$historial_orden = [];
$historial_agrupado = []; // Para agrupar por orden
$total_registros = 0;
$offset_global = ($pagina_actual - 1) * $por_pagina;

if ($orden_buscar !== '') {
    // Función para extraer y normalizar órdenes del texto
    function extraerOrdenes($texto) {
        // Convertir a mayúsculas y eliminar espacios innecesarios
        $texto = strtoupper(trim($texto));
        
        if (empty($texto)) {
            return [];
        }
        
        // Patrones específicos para órdenes válidas
        $patrones = [
            '/JIM\d{8}/',                    // Patrón JIM + 8 dígitos (ej: JIM00483643)
            '/JIMRECTI\d{8}/',               // Patrón JIMRECTI + 8 dígitos
            '/JIMWAR\d{8}/',                 // Patrón JIMWAR + 8 dígitos
        ];
        
        $ordenes_extraidas = [];
        
        // Primero: Intentar encontrar órdenes con patrones específicos
        foreach ($patrones as $patron) {
            if (preg_match_all($patron, $texto, $matches)) {
                foreach ($matches[0] as $match) {
                    // Verificar que no sea parte de una orden más larga
                    $es_valida = true;
                    foreach ($ordenes_extraidas as $orden_existente) {
                        if (strpos($orden_existente, $match) !== false || 
                            strpos($match, $orden_existente) !== false) {
                            $es_valida = false;
                            break;
                        }
                    }
                    if ($es_valida) {
                        $ordenes_extraidas[] = $match;
                    }
                }
            }
        }
        
        // Si no se encontraron órdenes con patrones específicos, usar el método tradicional
        if (empty($ordenes_extraidas)) {
            // Separar por cualquier combinación de separadores
            $partes = preg_split('/[\s,\n\.;\|\/]+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
            $ordenes_extraidas = array_filter($partes, function($parte) {
                // Filtrar partes que sean órdenes válidas exactas
                return preg_match('/^(JIM|JIMRECTI|JIMWAR)[0-9]{8}$/i', $parte);
            });
        }
        
        // Limpiar y normalizar
        $ordenes_extraidas = array_map('trim', $ordenes_extraidas);
        $ordenes_extraidas = array_filter($ordenes_extraidas);
        
        // Eliminar duplicados manteniendo el orden
        $ordenes_unicas = [];
        foreach ($ordenes_extraidas as $orden) {
            if (!in_array($orden, $ordenes_unicas)) {
                $ordenes_unicas[] = $orden;
            }
        }
        
        return $ordenes_unicas;
    }
    
    // Extraer órdenes del texto de búsqueda
    $ordenes = extraerOrdenes($orden_buscar);
    
    if (!empty($ordenes)) {
        // Preparar placeholders para la consulta
        $placeholders = implode(',', array_fill(0, count($ordenes), '?'));
        $tipos = str_repeat('s', count($ordenes));
        
        // 1. Conteo total para todas las órdenes
        $sql_count = "SELECT COUNT(*) AS total FROM produccion WHERE orden IN ($placeholders)";
        $stmt_count1 = $conn->prepare($sql_count);
        $stmt_count1->bind_param($tipos, ...$ordenes);
        $stmt_count1->execute();
        $count1 = $stmt_count1->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt_count1->close();

        $sql_count2 = "SELECT COUNT(*) AS total FROM registros_antiguos WHERE orden IN ($placeholders)";
        $stmt_count2 = $conn->prepare($sql_count2);
        $stmt_count2->bind_param($tipos, ...$ordenes);
        $stmt_count2->execute();
        $count2 = $stmt_count2->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt_count2->close();

        $total_registros = $count1 + $count2;

        if ($total_registros > 0) {
            // Ajustar página si excede el máximo
            $total_paginas = ceil($total_registros / $por_pagina);
            $pagina_actual = min($pagina_actual, $total_paginas);
            $offset_global = ($pagina_actual - 1) * $por_pagina;

            // Fetch con un pequeño buffer para cubrir la página actual
            $limit_fetch = $offset_global + $por_pagina + 100; // +100 de buffer por seguridad

            // Consulta en produccion para múltiples órdenes
            $sql1 = "
                SELECT empleado, area, COALESCE(equipo, 'N/A') AS equipo, turno, fecha, orden 
                FROM produccion 
                WHERE orden IN ($placeholders) 
                ORDER BY fecha DESC
            ";
            $stmt1 = $conn->prepare($sql1);
            $stmt1->bind_param($tipos, ...$ordenes);
            $stmt1->execute();
            $result1 = $stmt1->get_result();
            $rows1 = [];
            $contador1 = 0;
            
            // Obtener solo los registros necesarios manualmente
            while (($row = $result1->fetch_assoc()) !== null && $contador1 < $limit_fetch) {
                if ($row) {
                    $rows1[] = $row;
                    $contador1++;
                }
            }
            $stmt1->close();

            // Consulta en registros_antiguos para múltiples órdenes
            $sql2 = "
                SELECT empleado, area, COALESCE(equipo, 'N/A') AS equipo, turno, fecha, orden 
                FROM registros_antiguos 
                WHERE orden IN ($placeholders) 
                ORDER BY fecha DESC
            ";
            $stmt2 = $conn->prepare($sql2);
            $stmt2->bind_param($tipos, ...$ordenes);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $rows2 = [];
            $contador2 = 0;
            
            // Obtener solo los registros necesarios manualmente
            while (($row = $result2->fetch_assoc()) !== null && $contador2 < $limit_fetch) {
                if ($row) {
                    $rows2[] = $row;
                    $contador2++;
                }
            }
            $stmt2->close();

            // Merge ordenado en PHP (por fecha DESC)
            $merged = array_merge($rows1, $rows2);
            usort($merged, function($a, $b) {
                return strtotime($b['fecha']) - strtotime($a['fecha']);
            });

            // Aplicar paginación final
            $historial_orden = array_slice($merged, $offset_global, $por_pagina);
            
            // Agrupar resultados por orden para mostrar en tablas separadas
            foreach ($historial_orden as $fila) {
                $orden_actual = $fila['orden'];
                if (!isset($historial_agrupado[$orden_actual])) {
                    $historial_agrupado[$orden_actual] = [];
                }
                $historial_agrupado[$orden_actual][] = $fila;
            }
            
            // Ordenar las órdenes alfabéticamente
            ksort($historial_agrupado);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscar Orden - Sistema de Control de Producción</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
    <style>
        body { background-color: #1a1d20; color: white; font-family: Arial, sans-serif; margin: 0; padding: 0; }
        .tab-container { max-width: 1200px; margin: 30px auto; background-color: #2c2f33; padding: 20px; border-radius: 12px; }
        .alert { margin-top: 15px; padding: 10px 15px; background: #ffc107; border-radius: 4px; color: #856404; font-weight: 600; }

        #resultadoBusquedaOrden table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        #resultadoBusquedaOrden th, #resultadoBusquedaOrden td { padding: 8px; text-align: left; border-bottom: 1px solid #555; color: white; }
        #resultadoBusquedaOrden thead tr { background-color: #1e7e34; color: white; }

        #formBuscarOrden { margin-top: 10px; display: flex; gap: 8px; flex-direction: column; }
        #formBuscarOrden textarea { 
            flex-grow: 1; 
            padding: 10px; 
            font-size: 14px; 
            border: 1px solid #555; 
            border-radius: 4px; 
            background-color: #333; 
            color: white; 
            min-height: 80px; 
            resize: vertical;
            font-family: Arial, sans-serif;
        }
        #formBuscarOrden button { 
            padding: 8px 16px; 
            font-size: 14px; 
            background-color: #1e7e34; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            align-self: flex-start;
        }
        #formBuscarOrden button:hover { background-color: #155724; }
        .input-group { display: flex; gap: 10px; align-items: center; }
        .input-group label { min-width: 120px; }
        
        .search-format-hint {
            font-size: 12px;
            color: #aaa;
            margin-top: 5px;
            padding: 5px;
            background: rgba(0,0,0,0.2);
            border-radius: 4px;
        }

        .firma { color: white; margin-top: 20px; text-align: center; font-size: 13px; }

        .tabs { display: flex; border-bottom: 1px solid #555; margin-bottom: 20px; }
        .tab-btn { padding: 10px 20px; border: none; background: none; cursor: pointer; font-size: 16px; color: white; }
        .tab-btn.active { background-color: #1e7e34; color: white; border-radius: 4px 4px 0 0; }
        .tab-btn:hover { background-color: #444; }

        .whatsapp-btn { position: fixed; bottom: 20px; right: 20px; z-index: 9999; background-color: #25D366; padding: 10px 16px; border-radius: 30px; display: flex; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3); animation: breathe 2s ease-in-out infinite; text-decoration: none; color: white; font-weight: bold; }
        .whatsapp-btn i { font-size: 24px; margin-right: 8px; animation: beat 2s ease-in-out infinite; }
        .whatsapp-text { font-size: 16px; }

        @keyframes breathe { 0% { box-shadow: 0 0 0 0 rgba(37,211,102,0.5); } 70% { box-shadow: 0 0 0 15px rgba(37,211,102,0); } 100% { box-shadow: 0 0 0 0 rgba(0,0,0,0); } }
        @keyframes beat { 0% { transform: scale(1); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }

        .odoo-message-btn { position: fixed; bottom: 80px; right: 20px; z-index: 9999; background-color: rgb(27,177,19); color: white; padding: 12px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }

        .header { background: linear-gradient(135deg, rgba(0,51,0,0.95), rgba(21,87,36,0.95)); backdrop-filter: blur(10px); border-bottom: 3px solid #1e7e34; padding: 20px 0; position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        .header-content { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 25px; }
        .logo-container img { height: 65px; filter: brightness(1.2) drop-shadow(0 3px 6px rgba(0,0,0,0.4)); transition: transform 0.3s ease; }
        .logo-container img:hover { transform: scale(1.05); }
        .header-info { display: flex; align-items: center; gap: 25px; }
        .clock { font-size: 20px; font-weight: 700; color: #90ee90; text-shadow: 0 2px 4px rgba(0,0,0,0.6); padding: 8px 15px; background: rgba(0,0,0,0.2); border-radius: 8px; }
        .logout-btn { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 12px 25px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; box-shadow: 0 4px 15px rgba(220,53,69,0.4); }
        .logout-btn:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(220,53,69,0.5); color: white; }

        .info-registros { margin: 15px 0; font-size: 15px; }
        .tabla-container { max-height: 600px; overflow-y: auto; border: 1px solid #555; border-radius: 8px; padding: 8px; background-color: #333; }
        .paginacion { margin-top: 20px; text-align: center; }
        .paginacion a { color: #1e7e34; text-decoration: none; padding: 8px 12px; margin: 0 4px; border: 1px solid #555; border-radius: 4px; }
        .paginacion a:hover { background-color: #444; }
        .paginacion strong { padding: 8px 12px; margin: 0 4px; background-color: #1e7e34; border-radius: 4px; }
        
        .ordenes-buscadas {
            background: rgba(30, 126, 52, 0.2);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .ordenes-buscadas strong {
            color: #4ade80;
        }
        
        /* Estilos para tablas separadas por orden */
        .orden-table-container {
            margin-bottom: 40px;
            border: 2px solid #1e7e34;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .orden-header {
            background: linear-gradient(135deg, #1e7e34, #155724);
            padding: 12px 15px;
            font-size: 18px;
            font-weight: bold;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .orden-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .orden-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .orden-table th {
            background-color: #2d3748;
            padding: 10px;
            text-align: left;
            border-bottom: 2px solid #4a5568;
        }
        
        .orden-table td {
            padding: 10px;
            border-bottom: 1px solid #4a5568;
        }
        
        .orden-table tr:nth-child(even) {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .orden-table tr:hover {
            background-color: rgba(30, 126, 52, 0.1);
        }
        
        .resumen-ordenes {
            background: rgba(30, 126, 52, 0.1);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #1e7e34;
        }
        
        .resumen-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .no-results {
            text-align: center;
            padding: 30px;
            color: #aaa;
            font-style: italic;
        }
        
        /* Estilo para notificación de escaneo */
        .scan-notification {
            position: fixed;
            top: 100px;
            right: 20px;
            background: #1e7e34;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            z-index: 10000;
            display: none;
            animation: slideIn 0.3s ease-out;
            max-width: 300px;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .scan-notification.show {
            display: block;
        }
        
        .scan-notification .close-btn {
            background: none;
            border: none;
            color: white;
            position: absolute;
            top: 5px;
            right: 5px;
            cursor: pointer;
            font-size: 18px;
        }
        
        .scan-notification-content {
            padding-right: 20px;
        }
        
        /* Botones mejorados */
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 16px;
            font-size: 14px;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-primary {
            background-color: #1e7e34;
        }
        
        .btn-primary:hover {
            background-color: #155724;
        }
        
        .btn-secondary {
            background-color: #6c757d;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .btn-danger {
            background-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-info {
            background-color: #0dcaf0;
        }
        
        .btn-info:hover {
            background-color: #0ba5c7;
        }
        
        .btn-warning {
            background-color: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .ordenes-filtradas {
            background: rgba(255, 193, 7, 0.1);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 14px;
            border-left: 4px solid #ffc107;
        }
        
        .ordenes-filtradas .filtradas {
            color: #ffc107;
        }
        
        .ordenes-filtradas .validas {
            color: #4ade80;
        }
        
        .formato-valido {
            background: rgba(13, 202, 240, 0.1);
            padding: 8px 12px;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 12px;
            border-left: 3px solid #0dcaf0;
        }
        
        .formato-valido strong {
            color: #0dcaf0;
        }
        
        /* Indicador de primera orden */
        .first-scan-indicator {
            background: rgba(255, 193, 7, 0.1);
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            border-left: 3px solid #ffc107;
            margin-top: 5px;
            display: none;
        }
        
        /* NUEVOS ESTILOS PARA PESTAÑAS DE ÓRDENES */
        .orden-tabs {
            display: flex;
            border-bottom: 1px solid #555;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .orden-tab-btn {
            padding: 8px 15px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            color: white;
            border-radius: 4px 4px 0 0;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        
        .orden-tab-btn:hover {
            background-color: #444;
        }
        
        .orden-tab-btn.active {
            background-color: #1e7e34;
            color: white;
            font-weight: bold;
        }
        
        .orden-tab-content {
            display: none;
        }
        
        .orden-tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .single-order-view {
            /* Estilo para vista de orden única (sin pestañas) */
        }
        
        .multi-order-view {
            /* Estilo para vista de múltiples órdenes (con pestañas) */
        }
        
        .orden-tab-indicator {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-container">
                <img src="/control_produccion/public/logo.png" alt="Logo Empresa">
            </div>
            <div class="header-info">
                <div class="clock" id="reloj"></div>
                <a href="../logout.php" class="logout-btn">Cerrar Sesión</a>
            </div>
        </div>
    </header>

    <!-- Notificación de escaneo -->
    <div class="scan-notification" id="scanNotification">
        <button class="close-btn" onclick="this.parentElement.classList.remove('show')">×</button>
        <div class="scan-notification-content">
            <strong>¡Escaneo detectado!</strong>
            <p id="scannedCode"></p>
            <small id="scanDetails">Procesando órdenes...</small>
        </div>
    </div>

    <div class="tab-container">
        <div class="tabs">
            <button class="tab-btn active" data-tab="buscar-orden">Buscar Orden</button>
        </div>

        <div class="tab-content">
            <div id="buscar-orden" class="tab-panel active">
                <h3>Buscar historial por Orden</h3>
                <form id="formBuscarOrden" method="GET" action="">
                    <div class="input-group">
                        <label for="orden_buscar">Número(s) de orden:</label>
                        <textarea 
                            name="orden_buscar" 
                            id="orden_buscar" 
                            placeholder="Ingrese números de orden separados por comas, espacios o saltos de línea. Ejemplo: JIM00479719, JIMRECTI00479897, JIMWAR00479899" 
                            required 
                            rows="3"
                        ><?= htmlspecialchars($orden_buscar) ?></textarea>
                    </div>
                    
                    <div class="formato-valido">
                        <i class="bi bi-info-circle"></i> <strong>Formato válido:</strong> 
                        <ul style="margin: 5px 0 0 15px; padding: 0;">
                            <li>JIM + 8 dígitos (ej: JIM00479719)</li>
                            <li>JIMRECTI + 8 dígitos (ej: JIMRECTI00479897)</li>
                            <li>JIMWAR + 8 dígitos (ej: JIMWAR00479899)</li>
                        </ul>
                    </div>
                    
                    <div class="search-format-hint">
                        <i class="bi bi-scanner"></i> 
                        <strong>Modo escaneo automático:</strong> 
                        La primera orden buscará automáticamente. Órdenes posteriores se acumularán para búsqueda múltiple.
                    </div>
                    
                    <div id="firstScanIndicator" class="first-scan-indicator">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Primera orden:</strong> Buscará automáticamente al escanear. Para órdenes adicionales, presione "Buscar" manualmente.
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Buscar
                        </button>
                        <button type="button" onclick="copiarOrdenesAlPortapapeles()" class="btn btn-secondary">
                            <i class="bi bi-clipboard"></i> Copiar
                        </button>
                        <button type="button" onclick="limpiarBusqueda()" class="btn btn-danger">
                            <i class="bi bi-x-circle"></i> Limpiar
                        </button>
                        <button type="button" onclick="filtrarOrdenesValidas()" class="btn btn-info">
                            <i class="bi bi-funnel"></i> Filtrar Válidas
                        </button>
                        <button type="button" onclick="eliminarDuplicados()" class="btn btn-warning">
                            <i class="bi bi-eraser"></i> Eliminar Duplicados
                        </button>
                    </div>
                </form>

                <div id="resultadoBusquedaOrden">
                    <?php if ($orden_buscar !== '' && isset($ordenes)) : ?>
                        <?php if (!empty($ordenes)) : ?>
                            <?php 
                            // Calcular órdenes originales para mostrar cuáles fueron filtradas
                            $ordenes_originales = preg_split('/[\s,\n\.;\|\/]+/', strtoupper(trim($orden_buscar)), -1, PREG_SPLIT_NO_EMPTY);
                            $ordenes_originales = array_map('trim', $ordenes_originales);
                            $ordenes_originales = array_filter($ordenes_originales);
                            $ordenes_invalidas = array_diff($ordenes_originales, $ordenes);
                            ?>
                            
                            <?php if (!empty($ordenes_invalidas)) : ?>
                                <div class="ordenes-filtradas">
                                    <i class="bi bi-filter"></i> 
                                    <strong>Filtro aplicado:</strong> 
                                    <span class="filtradas"><?= count($ordenes_invalidas) ?> orden(es) filtrada(s): <?= htmlspecialchars(implode(', ', $ordenes_invalidas)) ?></span><br>
                                    <span class="validas"><?= count($ordenes) ?> orden(es) válida(s) procesada(s)</span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="ordenes-buscadas">
                                <i class="bi bi-search"></i> 
                                <strong>Búsqueda activada</strong> - 
                                Se procesaron <?= count($ordenes) ?> orden(es) válida(s): 
                                <strong><?= htmlspecialchars(implode(', ', $ordenes)) ?></strong>
                                <?php if (count($ordenes) > 1) : ?>
                                    <br><small><i class="bi bi-lightbulb"></i> Mostrando en pestañas separadas.</small>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($historial_orden)) : ?>
                                <?php
                                $inicio_mostrando = $offset_global + 1;
                                $fin_mostrando = $offset_global + count($historial_orden);
                                ?>
                                <div class="info-registros">
                                    Mostrando <?= $inicio_mostrando ?> - <?= $fin_mostrando ?> de <?= $total_registros ?> registros
                                </div>
                                
                                <!-- Resumen de órdenes encontradas -->
                                <div class="resumen-ordenes">
                                    <h4><i class="bi bi-card-checklist"></i> Resumen de búsqueda</h4>
                                    <?php foreach ($historial_agrupado as $orden_num => $registros) : ?>
                                        <div class="resumen-item">
                                            <span>Orden <strong><?= htmlspecialchars($orden_num) ?></strong>:</span>
                                            <span><?= count($registros) ?> registro<?= count($registros) > 1 ? 's' : '' ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (count($historial_agrupado) === 1) : ?>
                                    <!-- VISTA PARA UNA SOLA ORDEN (sin pestañas) -->
                                    <?php foreach ($historial_agrupado as $orden_num => $registros) : ?>
                                        <div class="orden-table-container single-order-view">
                                            <div class="orden-header">
                                                <span>Orden: <?= htmlspecialchars($orden_num) ?></span>
                                                <span class="orden-count"><?= count($registros) ?> registro<?= count($registros) > 1 ? 's' : '' ?></span>
                                            </div>
                                            <div class="tabla-container">
                                                <table class="orden-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Empleado</th>
                                                            <th>Área</th>
                                                            <th>Equipo</th>
                                                            <th>Turno</th>
                                                            <th>Fecha</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($registros as $fila) : ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($fila['empleado'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars($fila['area'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars($fila['equipo'] ?? 'N/A') ?></td>
                                                                <td><?= htmlspecialchars($fila['turno'] ?? '') ?></td>
                                                                <td>
                                                                    <?php
                                                                    if (isset($fila['fecha']) && !empty($fila['fecha'])) {
                                                                        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fila['fecha']);
                                                                        echo $dt ? $dt->format('Y-m-d h:i:s A') : htmlspecialchars($fila['fecha']);
                                                                    } else {
                                                                        echo 'Fecha no disponible';
                                                                    }
                                                                    ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <!-- VISTA PARA MÚLTIPLES ÓRDENES (con pestañas) -->
                                    <div class="multi-order-view">
                                        <div class="orden-tabs" id="ordenTabs">
                                            <?php $first = true; ?>
                                            <?php foreach ($historial_agrupado as $orden_num => $registros) : ?>
                                                <button class="orden-tab-btn <?= $first ? 'active' : '' ?>" 
                                                        data-orden="<?= htmlspecialchars($orden_num) ?>">
                                                    <?= htmlspecialchars($orden_num) ?>
                                                    <span class="orden-tab-indicator"><?= count($registros) ?></span>
                                                </button>
                                                <?php $first = false; ?>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="orden-tab-contents" id="ordenTabContents">
                                            <?php $first = true; ?>
                                            <?php foreach ($historial_agrupado as $orden_num => $registros) : ?>
                                                <div class="orden-tab-content <?= $first ? 'active' : '' ?>" 
                                                     id="tab-<?= htmlspecialchars($orden_num) ?>">
                                                    <div class="orden-table-container">
                                                        <div class="orden-header">
                                                            <span>Orden: <?= htmlspecialchars($orden_num) ?></span>
                                                            <span class="orden-count"><?= count($registros) ?> registro<?= count($registros) > 1 ? 's' : '' ?></span>
                                                        </div>
                                                        <div class="tabla-container">
                                                            <table class="orden-table">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Empleado</th>
                                                                        <th>Área</th>
                                                                        <th>Equipo</th>
                                                                        <th>Turno</th>
                                                                        <th>Fecha</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($registros as $fila) : ?>
                                                                        <tr>
                                                                            <td><?= htmlspecialchars($fila['empleado'] ?? '') ?></td>
                                                                            <td><?= htmlspecialchars($fila['area'] ?? '') ?></td>
                                                                            <td><?= htmlspecialchars($fila['equipo'] ?? 'N/A') ?></td>
                                                                            <td><?= htmlspecialchars($fila['turno'] ?? '') ?></td>
                                                                            <td>
                                                                                <?php
                                                                                if (isset($fila['fecha']) && !empty($fila['fecha'])) {
                                                                                    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fila['fecha']);
                                                                                    echo $dt ? $dt->format('Y-m-d h:i:s A') : htmlspecialchars($fila['fecha']);
                                                                                } else {
                                                                                    echo 'Fecha no disponible';
                                                                                }
                                                                                ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php $first = false; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($total_registros > $por_pagina) : ?>
                                    <?php
                                    $total_paginas = ceil($total_registros / $por_pagina);
                                    $paginas_mostrar = 9;
                                    $inicio_pag = max(1, $pagina_actual - floor($paginas_mostrar / 2));
                                    $fin_pag = min($total_paginas, $inicio_pag + $paginas_mostrar - 1);
                                    if ($fin_pag - $inicio_pag + 1 < $paginas_mostrar) {
                                        $inicio_pag = max(1, $fin_pag - $paginas_mostrar + 1);
                                    }
                                    ?>
                                    <div class="paginacion">
                                        <?php if ($pagina_actual > 1) : ?>
                                            <a href="?orden_buscar=<?= urlencode($orden_buscar) ?>&pagina=<?= $pagina_actual - 1 ?>">« Anterior</a>
                                        <?php endif; ?>

                                        <?php for ($i = $inicio_pag; $i <= $fin_pag; $i++) : ?>
                                            <?php if ($i == $pagina_actual) : ?>
                                                <strong><?= $i ?></strong>
                                            <?php else : ?>
                                                <a href="?orden_buscar=<?= urlencode($orden_buscar) ?>&pagina=<?= $i ?>"><?= $i ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>

                                        <?php if ($pagina_actual < $total_paginas) : ?>
                                            <a href="?orden_buscar=<?= urlencode($orden_buscar) ?>&pagina=<?= $pagina_actual + 1 ?>">Siguiente »</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            <?php else : ?>
                                <div class="no-results">
                                    <i class="bi bi-search" style="font-size: 48px; margin-bottom: 10px;"></i>
                                    <h4>No se encontraron registros</h4>
                                    <p>No hay registros para las órdenes válidas especificadas.</p>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <div class="alert">
                                <i class="bi bi-exclamation-triangle"></i> 
                                No se encontraron órdenes válidas en la búsqueda. 
                                <strong>Formatos válidos:</strong> JIM00479719, JIMRECTI00479897, JIMWAR00479899
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="firma">
        Sistema de control de producción | © <?php echo date("Y"); ?>
        <p>Desarrollado por: Nestor Rosales | Rosales_Dev91</p>
    </div>

    <a href="https://wa.me/50672360749?text=Hola, tengo una consulta acerca de" target="_blank" class="whatsapp-btn">
        <i class="bi bi-whatsapp"></i>
        <span class="whatsapp-text">Soporte</span>
    </a>

    <a href="https://grnoma.odoo.com/web#action=124&cids=1&menu_id=81&active_id=discuss.channel_3566" target="_blank" class="odoo-message-btn">
        💬 Soporte al usuario Odoo
    </a>

<script>
    // ============================================
    // VARIABLES GLOBALES
    // ============================================
    let scanBuffer = '';
    let scanTimeout;
    const SCAN_END_TIMEOUT = 50;
    const ORDEN_VALIDA_REGEX = /^(JIM|JIMRECTI|JIMWAR)[0-9]{8}$/i;

    // ============================================
    // FUNCIONES DE RELOJ
    // ============================================
    function actualizarReloj() {
        const dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
        const meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
        const ahora = new Date();
        const diaSemana = dias[ahora.getDay()];
        const dia = ahora.getDate();
        const mes = meses[ahora.getMonth()];
        const año = ahora.getFullYear();
        const horas = ahora.getHours().toString().padStart(2, '0');
        const minutos = ahora.getMinutes().toString().padStart(2, '0');
        const segundos = ahora.getSeconds().toString().padStart(2, '0');
        const fechaHora = `${diaSemana}, ${dia} de ${mes} de ${año} - ${horas}:${minutos}:${segundos}`;
        document.getElementById('reloj').textContent = fechaHora;
    }

    // ============================================
    // FUNCIONES DE VALIDACIÓN DE ÓRDENES
    // ============================================
    function esOrdenValida(orden) {
        if (!orden) return false;
        return ORDEN_VALIDA_REGEX.test(orden.toUpperCase());
    }

    function extraerOrdenesValidas(texto) {
        texto = texto.trim().toUpperCase();
        if (!texto) return [];
        
        // PRIMERO: Intentar extraer órdenes concatenadas (JIM00484167JIM00484165)
        const ordenesConcatenadas = texto.split(/(?=JIM|JIMRECTI|JIMWAR)/).filter(item => item.trim() !== '');
        
        let todasLasOrdenes = [];
        
        for (let parte of ordenesConcatenadas) {
            // Si la parte coincide exactamente con una orden válida
            if (esOrdenValida(parte)) {
                todasLasOrdenes.push(parte);
            } else {
                // Si no, separar por separadores normales
                const subPartes = parte.split(/[\s,\n\.;\|\/]+/).filter(item => item.trim() !== '');
                for (let subParte of subPartes) {
                    if (esOrdenValida(subParte)) {
                        todasLasOrdenes.push(subParte);
                    }
                }
            }
        }
        
        // Eliminar duplicados manteniendo el orden
        const ordenesUnicas = [];
        for (const orden of todasLasOrdenes) {
            if (!ordenesUnicas.includes(orden)) {
                ordenesUnicas.push(orden);
            }
        }
        
        return ordenesUnicas;
    }

    // ============================================
    // FUNCIONES DE NOTIFICACIÓN
    // ============================================
    function mostrarNotificacion(mensaje, tipo = 'info') {
        const notification = document.getElementById('scanNotification');
        const codeElement = document.getElementById('scannedCode');
        const detailsElement = document.getElementById('scanDetails');
        
        codeElement.textContent = mensaje;
        detailsElement.textContent = '';
        
        const colores = {
            warning: { bg: '#ffc107', text: '#212529' },
            success: { bg: '#198754', text: 'white' },
            info: { bg: '#0dcaf0', text: 'white' },
            default: { bg: '#1e7e34', text: 'white' }
        };
        
        const color = colores[tipo] || colores.default;
        notification.style.backgroundColor = color.bg;
        notification.style.color = color.text;
        
        notification.classList.add('show');
        
        setTimeout(() => {
            notification.classList.remove('show');
            notification.style.backgroundColor = colores.default.bg;
            notification.style.color = colores.default.text;
        }, 2000);
    }

    // ============================================
    // FUNCIONES DE MANEJO DE ÓRDENES
    // ============================================
    function actualizarIndicadorPrimeraOrden() {
        const indicador = document.getElementById('firstScanIndicator');
        const textarea = document.getElementById('orden_buscar');
        if (!indicador || !textarea) return;
        
        const ordenes = extraerOrdenesValidas(textarea.value);
        
        if (ordenes.length === 0) {
            indicador.style.display = 'block';
            indicador.innerHTML = '<i class="bi bi-scanner"></i> <strong>Modo escaneo activo:</strong> La primera orden buscará automáticamente.';
        } else {
            indicador.style.display = 'block';
            indicador.innerHTML = `<i class="bi bi-check-circle"></i> <strong>${ordenes.length} orden(es):</strong> Listo para buscar.`;
        }
    }

    function procesarOrdenEscaneadaInmediata(codigo) {
        if (!codigo || codigo.trim() === '') return;
        
        codigo = codigo.toUpperCase().trim();
        
        // Extraer TODAS las órdenes válidas del código escaneado
        const ordenesEscanadas = extraerOrdenesValidas(codigo);
        
        if (ordenesEscanadas.length === 0) {
            mostrarNotificacion('No se encontraron órdenes válidas', 'warning');
            return;
        }
        
        mostrarNotificacion(`${ordenesEscanadas.length} orden(es) escaneada(s). Buscando...`, 'success');
        
        const textarea = document.getElementById('orden_buscar');
        const valorActual = textarea.value.trim();
        const ordenesActuales = valorActual ? extraerOrdenesValidas(valorActual) : [];
        
        // Combinar órdenes actuales con las nuevas, evitando duplicados
        const todasLasOrdenes = [...ordenesActuales];
        
        for (const orden of ordenesEscanadas) {
            if (!todasLasOrdenes.includes(orden)) {
                todasLasOrdenes.push(orden);
            }
        }
        
        // Ordenar alfabéticamente para mejor legibilidad
        todasLasOrdenes.sort();
        
        // Actualizar el textarea
        textarea.value = todasLasOrdenes.join(', ');
        
        // Buscar inmediatamente
        document.getElementById('formBuscarOrden').submit();
    }

    function detectarEscaneo(event) {
        // Solo procesar si el campo de búsqueda tiene el foco
        if (document.activeElement.id !== 'orden_buscar') return;
        
        // Ignorar teclas especiales
        if (['Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'Tab', 'Enter'].includes(event.key)) {
            return;
        }
        
        // Acumular caracteres
        scanBuffer += event.key;
        
        // Reiniciar timeout para detectar fin de escaneo
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(() => {
            // Procesar el buffer completo
            if (scanBuffer.length >= 11) {
                procesarOrdenEscaneadaInmediata(scanBuffer);
            }
            scanBuffer = '';
        }, SCAN_END_TIMEOUT);
    }

    // ============================================
    // FUNCIONES DE ACCIONES DE BOTONES
    // ============================================
    function limpiarBusqueda() {
        const textarea = document.getElementById('orden_buscar');
        textarea.value = '';
        textarea.focus();
        
        mostrarNotificacion('Búsqueda limpiada', 'success');
        actualizarIndicadorPrimeraOrden();
        
        setTimeout(() => {
            window.location.href = window.location.pathname;
        }, 300);
    }

    function copiarOrdenesAlPortapapeles() {
        const textarea = document.getElementById('orden_buscar');
        const ordenes = textarea.value.trim();
        
        if (!ordenes) {
            mostrarNotificacion('No hay órdenes para copiar', 'warning');
            return;
        }
        
        const ordenesFormateadas = extraerOrdenesValidas(ordenes).join(', ');
        
        if (!ordenesFormateadas) {
            mostrarNotificacion('No hay órdenes válidas', 'warning');
            return;
        }
        
        navigator.clipboard.writeText(ordenesFormateadas)
            .then(() => mostrarNotificacion('Órdenes copiadas', 'success'))
            .catch(() => mostrarNotificacion('Error al copiar', 'warning'));
    }

    function filtrarOrdenesValidas() {
        const textarea = document.getElementById('orden_buscar');
        let currentValue = textarea.value.trim();
        
        if (!currentValue) {
            mostrarNotificacion('No hay texto', 'warning');
            return;
        }
        
        const ordenesValidas = extraerOrdenesValidas(currentValue);
        
        if (ordenesValidas.length === 0) {
            mostrarNotificacion('No hay órdenes válidas', 'warning');
            return;
        }
        
        textarea.value = ordenesValidas.join(', ');
        mostrarNotificacion(`${ordenesValidas.length} orden(es) válidas`, 'success');
        actualizarIndicadorPrimeraOrden();
    }

    function eliminarDuplicados() {
        const textarea = document.getElementById('orden_buscar');
        let currentValue = textarea.value.trim();
        
        if (!currentValue) {
            mostrarNotificacion('No hay órdenes', 'warning');
            return;
        }
        
        const ordenesValidas = extraerOrdenesValidas(currentValue);
        
        if (ordenesValidas.length === 0) {
            mostrarNotificacion('No hay órdenes válidas', 'warning');
            return;
        }
        
        textarea.value = ordenesValidas.join(', ');
        mostrarNotificacion(`${ordenesValidas.length} orden(es) únicas`, 'success');
        actualizarIndicadorPrimeraOrden();
    }

    // ============================================
    // FUNCIONES DE PESTAÑAS
    // ============================================
    function inicializarPestanasOrdenes() {
        const ordenTabs = document.getElementById('ordenTabs');
        if (!ordenTabs) return;
        
        const tabButtons = ordenTabs.querySelectorAll('.orden-tab-btn');
        const tabContents = document.querySelectorAll('.orden-tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const orden = button.getAttribute('data-orden');
                
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                button.classList.add('active');
                
                const tabContent = document.getElementById(`tab-${orden}`);
                if (tabContent) {
                    tabContent.classList.add('active');
                }
            });
        });
    }

    // ============================================
    // INICIALIZACIÓN PRINCIPAL
    // ============================================
    document.addEventListener('DOMContentLoaded', () => {
        // Iniciar reloj
        setInterval(actualizarReloj, 1000);
        actualizarReloj();

        // Inicializar pestañas principales
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.remove('active'));
                button.classList.add('active');
                document.getElementById(button.getAttribute('data-tab')).classList.add('active');
            });
        });

        // Inicializar pestañas de órdenes
        inicializarPestanasOrdenes();

        // Configurar campo de búsqueda
        const input = document.getElementById('orden_buscar');
        
        if (input) {
            input.placeholder = "Escriba o escanee una o múltiples órdenes. Presione Enter o Botón Buscar.";
            
            setTimeout(() => {
                input.focus();
                input.selectionStart = input.selectionEnd = input.value.length;
                actualizarIndicadorPrimeraOrden();
            }, 100);
            
            // Detectar escaneo
            input.addEventListener('keydown', detectarEscaneo);
            
            // Actualizar indicador al escribir
            input.addEventListener('input', function() {
                setTimeout(actualizarIndicadorPrimeraOrden, 50);
            });
            
            // Manejar pegado (Ctrl+V)
            input.addEventListener('keydown', function(event) {
                if ((event.ctrlKey || event.metaKey) && event.key === 'v') {
                    setTimeout(() => {
                        const ordenes = extraerOrdenesValidas(this.value);
                        if (ordenes.length > 0) {
                            mostrarNotificacion(`${ordenes.length} orden(es) pegadas. Presione "Buscar" para continuar.`, 'info');
                            actualizarIndicadorPrimeraOrden();
                        }
                    }, 100);
                }
            });
            
            // Colocar cursor al final al hacer clic
            input.addEventListener('click', function() {
                setTimeout(() => {
                    this.selectionStart = this.selectionEnd = this.value.length;
                }, 10);
            });
            
            // Actualizar indicador al enfocar
            input.addEventListener('focus', function() {
                setTimeout(actualizarIndicadorPrimeraOrden, 50);
            });
            
            // Enter hace submit
            input.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    const ordenes = extraerOrdenesValidas(this.value);
                    if (ordenes.length > 0) {
                        mostrarNotificacion(`Buscando ${ordenes.length} orden(es)...`, 'info');
                        document.getElementById('formBuscarOrden').submit();
                    } else {
                        mostrarNotificacion('No hay órdenes válidas para buscar', 'warning');
                    }
                }
            });
        }

        // Configurar textos informativos
        const searchFormatHint = document.querySelector('.search-format-hint');
        if (searchFormatHint) {
            searchFormatHint.innerHTML = '<i class="bi bi-scanner"></i> <strong>Escaneo automático:</strong> Soporta órdenes concatenadas (JIM001JIM002). Busca inmediatamente.';
        }

        // Pegado contextual SIN submit automático
        document.addEventListener('paste', function(event) {
            if (document.activeElement.id === 'orden_buscar') {
                const pastedText = (event.clipboardData || window.clipboardData).getData('text');
                if (pastedText && pastedText.trim() !== '') {
                    setTimeout(() => {
                        const ordenes = extraerOrdenesValidas(pastedText);
                        if (ordenes.length > 0) {
                            mostrarNotificacion(`${ordenes.length} orden(es) pegadas. Presione "Buscar" para continuar.`, 'info');
                            actualizarIndicadorPrimeraOrden();
                        }
                    }, 100);
                }
            }
        });

        // Botón "Buscar" manual
        const buscarBtn = document.querySelector('#formBuscarOrden button[type="submit"]');
        if (buscarBtn) {
            buscarBtn.addEventListener('click', function(e) {
                const ordenes = extraerOrdenesValidas(document.getElementById('orden_buscar').value);
                if (ordenes.length > 0) {
                    mostrarNotificacion(`Buscando ${ordenes.length} orden(es)...`, 'info');
                }
            });
        }

        // Botones de acción
        const btnLimpiar = document.querySelector('.btn-danger');
        if (btnLimpiar) btnLimpiar.addEventListener('click', limpiarBusqueda);

        const btnFiltrar = document.querySelector('.btn-info');
        if (btnFiltrar) btnFiltrar.addEventListener('click', filtrarOrdenesValidas);

        const btnEliminar = document.querySelector('.btn-warning');
        if (btnEliminar) btnEliminar.addEventListener('click', eliminarDuplicados);

        const btnCopiar = document.querySelector('.btn-secondary');
        if (btnCopiar) btnCopiar.addEventListener('click', copiarOrdenesAlPortapapeles);

        // Actualizar indicador si hay contenido
        if (input && input.value.trim() !== '') {
            setTimeout(actualizarIndicadorPrimeraOrden, 200);
        }

        // Validación del formulario
        const form = document.getElementById('formBuscarOrden');
        if (form) {
            form.addEventListener('submit', function(event) {
                const textarea = document.getElementById('orden_buscar');
                const ordenes = extraerOrdenesValidas(textarea.value);
                
                if (ordenes.length === 0) {
                    event.preventDefault();
                    mostrarNotificacion('No hay órdenes válidas para buscar', 'warning');
                }
            });
        }
    });
</script>
</body>
</html>