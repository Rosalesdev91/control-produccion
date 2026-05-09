// Global variables for charts and tables
let charts = {};
let dataTables = {};

// Common configuration for DataTables
const dataTablesConfig = {
    language: {
        "processing": "Procesando...",
        "lengthMenu": "Mostrar _MENU_ registros",
        "zeroRecords": "No se encontraron resultados",
        "emptyTable": "Ningún dato disponible en esta tabla",
        "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
        "infoEmpty": "Mostrando registros del 0 al 0 de un total de 0 registros",
        "infoFiltered": "(filtrado de un total de _MAX_ registros)",
        "search": "Buscar:",
        "infoThousands": ",",
        "loadingRecords": "Cargando...",
        "paginate": {
            "first": "Primero",
            "last": "Último",
            "next": "Siguiente",
            "previous": "Anterior"
        },
        "aria": {
            "sortAscending": ": Activar para ordenar la columna de manera ascendente",
            "sortDescending": ": Activar para ordenar la columna de manera descendente"
        }
    },
    dom: '<"top"f>rt<"bottom"lip><"clear">',
    pageLength: 10,
    lengthMenu: [5, 10, 15, 20],
    responsive: true,
    autoWidth: false
};

$(document).ready(function() {
    // Configure current date and time
    function actualizarReloj() {
        const ahora = moment();
        $('#fechaActual').text(ahora.format('dddd, D [de] MMMM [de] YYYY'));
        $('#horaActual').text(ahora.format('h:mm:ss A'));
    }
    actualizarReloj();
    setInterval(actualizarReloj, 1000);

    // Configure date range picker
    $('#rangoFechas').daterangepicker({
        locale: {
            format: 'YYYY-MM-DD',
            applyLabel: 'Aplicar',
            cancelLabel: 'Cancelar',
            fromLabel: 'De',
            toLabel: 'A',
            customRangeLabel: 'Personalizado',
            daysOfWeek: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'],
            monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
            firstDay: 1
        },
        opens: 'left',
        startDate: moment().startOf('month'),
        endDate: moment(),
        ranges: {
            'Hoy': [moment(), moment()],
            'Ayer': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            'Últimos 7 Días': [moment().subtract(6, 'days'), moment()],
            'Últimos 30 Días': [moment().subtract(29, 'days'), moment()],
            'Este Mes': [moment().startOf('month'), moment().endOf('month')],
            'Mes Anterior': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    }, function(start, end) {
        $('#rangoFechas').val(start.format('DD/MM/YYYY') + ' - ' + end.format('DD/MM/YYYY'));
    });

    // Handle filter application
    $('#aplicarFiltros').click(function() {
        cargarDatosIniciales();
    });

    // Show loading spinner when changing tabs
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
        const target = $(e.target).attr('data-bs-target');
        if (target === '#produccion') {
            cargarDatosProduccion();
        } else if (target === '#quiebras') {
            cargarDatosQuiebras();
        } else if (target === '#predicciones') {
            cargarDatosPredicciones();
        } else if (target === '#analisis') {
            cargarDatosAnalisis();
        }
    });

    // Load initial data when page loads
    cargarDatosIniciales();
});

function mostrarCarga(mostrar) {
    $('#loadingSpinner').toggle(mostrar);
}

function obtenerParametrosFiltro() {
    const rangoFechas = $('#rangoFechas').data('daterangepicker');
    return {
        fecha_inicio: rangoFechas.startDate.format('YYYY-MM-DD'),
        fecha_fin: rangoFechas.endDate.format('YYYY-MM-DD'),
        area: $('#selectArea').val()
    };
}

function cargarDatosIniciales() {
    mostrarCarga(true);
    const params = obtenerParametrosFiltro();

    $.ajax({
        url: 'ia_consultas.php?ajax=1',
        type: 'POST',
        data: params,
        dataType: 'json',
        success: function(data) {
            try {
                if (data.error) {
                    mostrarError(data.error);
                    return;
                }
                mostrarResumenGeneral(data.estadisticas);
                actualizarChartProduccionArea(data.produccion_detallada);
                actualizarChartQuiebrasArea(data.quiebras_equipo);
                actualizarTablaTopEmpleados(data.top_empleados);
                actualizarTablaTopQuiebras(data.empleados_con_mas_quiebras);
                llenarOpcionesFiltro(data);
            } catch (error) {
                mostrarError('Error al procesar los datos: ' + error.message);
            } finally {
                mostrarCarga(false);
            }
        },
        error: function(xhr, status, error) {
            let errorMsg = 'Error al cargar datos iniciales';
            if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMsg += ': ' + xhr.responseJSON.error;
            } else if (xhr.responseText && xhr.responseText.startsWith('<')) {
                errorMsg += ': El servidor devolvió una página de error. Verifica la consola para más detalles.';
                console.error('Respuesta del servidor:', xhr.responseText);
            } else {
                errorMsg += ': ' + error;
            }
            mostrarError(errorMsg);
            mostrarCarga(false);
        }
    });
}

function llenarOpcionesFiltro(data) {
    const $selectArea = $('#selectArea');
    $selectArea.empty().append('<option value="">Todas las áreas</option>');
    
    // Get all unique areas from production and defects
    const areas = new Set();
    
    if (data.produccion_detallada) {
        data.produccion_detallada.forEach(item => {
            if (item.areas_produccion) {
                item.areas_produccion.forEach(area => {
                    areas.add(area);
                });
            }
        });
    }
    
    if (data.quiebras_equipo) {
        data.quiebras_equipo.forEach(item => {
            if (item.areas) {
                item.areas.forEach(area => {
                    areas.add(area);
                });
            }
        });
    }
    
    areas.forEach(area => {
        $selectArea.append(`<option value="${area}">${area}</option>`);
    });
}

function cargarDatosProduccion() {
    mostrarCarga(true);
    const params = obtenerParametrosFiltro();

    $.ajax({
        url: 'ia_consultas.php?ajax=1',
        type: 'POST',
        data: params,
        dataType: 'json',
        success: function(data) {
            try {
                actualizarChartTendenciaProduccion(data.produccion_detallada, data.tendencias_temporales);
                actualizarTablaProduccionArea(data.produccion_detallada);
            } catch (error) {
                mostrarError('Error al procesar datos de producción: ' + error.message);
            } finally {
                mostrarCarga(false);
            }
        },
        error: function(xhr, status, error) {
            mostrarError('Error al cargar datos de producción: ' + error);
            mostrarCarga(false);
        }
    });
}

function cargarDatosQuiebras() {
    mostrarCarga(true);
    const params = obtenerParametrosFiltro();

    $.ajax({
        url: 'ia_consultas.php?ajax=1',
        type: 'POST',
        data: params,
        dataType: 'json',
        success: function(data) {
            try {
                actualizarChartTendenciaQuiebras(data.tendencias_temporales);
                actualizarChartDistribucionQuiebras(data.quiebras_equipo);
                actualizarChartQuiebrasHora(data.tendencias_temporales);
                actualizarTablaQuiebrasArea(data.quiebras_equipo);
            } catch (error) {
                mostrarError('Error al procesar datos de quiebras: ' + error.message);
            } finally {
                mostrarCarga(false);
            }
        },
        error: function(xhr, status, error) {
            mostrarError('Error al cargar datos de quiebras: ' + error);
            mostrarCarga(false);
        }
    });
}

function cargarDatosPredicciones() {
    mostrarCarga(true);
    const params = obtenerParametrosFiltro();

    $.ajax({
        url: 'ia_consultas.php?ajax=1',
        type: 'POST',
        data: params,
        dataType: 'json',
        success: function(data) {
            try {
                // Example prediction data (simulated)
                const datosPrediccion = {
                    predicciones: [
                        { motivo: "Error de máquina", total_ultima_semana: 15, prediccion_semana_siguiente: 18 },
                        { motivo: "Material defectuoso", total_ultima_semana: 12, prediccion_semana_siguiente: 10 },
                        { motivo: "Error humano", total_ultima_semana: 8, prediccion_semana_siguiente: 9 },
                        { motivo: "Problema de calibración", total_ultima_semana: 5, prediccion_semana_siguiente: 6 }
                    ],
                    top_empleados: data.top_empleados.map(empleado => ({
                        ...empleado,
                        prediccion_produccion: Math.round(empleado.produccion * (1 + (Math.random() * 0.2 - 0.1)))
                    }))
                };
                
                actualizarChartPrediccionQuiebras(datosPrediccion.predicciones);
                actualizarTablaPrediccionQuiebras(datosPrediccion.predicciones);
                actualizarChartPrediccionRendimiento(datosPrediccion.top_empleados);
                actualizarTablaPrediccionRendimiento(datosPrediccion.top_empleados);
            } catch (error) {
                mostrarError('Error al procesar datos de predicciones: ' + error.message);
            } finally {
                mostrarCarga(false);
            }
        },
        error: function(xhr, status, error) {
            mostrarError('Error al cargar datos de predicciones: ' + error);
            mostrarCarga(false);
        }
    });
}

function cargarDatosAnalisis() {
    mostrarCarga(true);
    const params = obtenerParametrosFiltro();

    $.ajax({
        url: 'ia_consultas.php?ajax=1',
        type: 'POST',
        data: params,
        dataType: 'json',
        success: function(data) {
            try {
                // Example advanced analysis data (simulated)
                const datosAnalisis = {
                    produccion_por_area: data.produccion_detallada,
                    quiebras_por_area: data.quiebras_equipo,
                    patrones_horarios: Array.from({length: 24}, (_, i) => ({
                        hora: i,
                        total_produccion: Math.round(Math.random() * 100 + 50),
                        total_quiebras: Math.round(Math.random() * 10 + 2),
                        tasa_quiebra: (Math.random() * 15 + 5).toFixed(2) + '%'
                    })),
                    areas_problematicas: data.quiebras_equipo.map(area => ({
                        area: area.equipo,
                        total_quiebras: area.total_quiebras,
                        motivos: Object.keys(area.motivos || {}),
                        responsables: area.top_empleados ? area.top_empleados.map(e => e.empleado) : []
                    }))
                };
                
                actualizarChartCorrelacion(datosAnalisis.produccion_por_area, datosAnalisis.quiebras_por_area);
                actualizarChartPatronesTemporales(datosAnalisis.patrones_horarios);
                actualizarTablaCorrelacion(datosAnalisis.produccion_por_area, datosAnalisis.quiebras_por_area);
                actualizarTablaPatrones(datosAnalisis.patrones_horarios);
                actualizarTablaAreasProblematicas(datosAnalisis.areas_problematicas);
            } catch (error) {
                mostrarError('Error al procesar datos de análisis: ' + error.message);
            } finally {
                mostrarCarga(false);
            }
        },
        error: function(xhr, status, error) {
            mostrarError('Error al cargar datos de análisis: ' + error);
            mostrarCarga(false);
        }
    });
}

function mostrarResumenGeneral(data) {
    const html = `
        <div class="col-md-3">
            <div class="card stat-card stat-card-primary">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 text-muted">Total Quiebras</h6>
                    <h3 class="card-title text-primary">${data.total_quiebras}</h3>
                    <p class="card-text small">Registros de quiebras</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card stat-card-success">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 text-muted">Total Producción</h6>
                    <h3 class="card-title text-success">${data.total_produccion}</h3>
                    <p class="card-text small">Unidades producidas</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card stat-card-danger">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 text-muted">Tasa de Quiebras</h6>
                    <h3 class="card-title text-danger">${data.tasa_quiebras}%</h3>
                    <p class="card-text small">Porcentaje de defectos</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card stat-card-info">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 text-muted">Período</h6>
                    <h5 class="card-title text-info">${moment(data.fecha_inicio).format('DD/MM/YYYY')} - ${moment(data.fecha_fin).format('DD/MM/YYYY')}</h5>
                    <p class="card-text small">Rango analizado</p>
                </div>
            </div>
        </div>
    `;
    $('#resumenGeneral').html(html);
}

function actualizarChartProduccionArea(data) {
    const ctx = document.getElementById('chartProduccionArea');
    if (!ctx) return;
    
    // Destroy previous chart if exists
    if (charts['produccionArea']) {
        charts['produccionArea'].destroy();
    }
    
    // Group production by area
    const produccionPorArea = {};
    data.forEach(item => {
        if (item.areas_produccion) {
            item.areas_produccion.forEach(area => {
                if (!produccionPorArea[area]) {
                    produccionPorArea[area] = 0;
                }
                produccionPorArea[area] += item.total_produccion / item.areas_produccion.length;
            });
        }
    });
    
    const labels = Object.keys(produccionPorArea);
    const values = Object.values(produccionPorArea);
    const total = values.reduce((sum, val) => sum + val, 0);

    charts['produccionArea'] = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: [
                    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
                    '#5a5c69', '#858796', '#f8f9fc', '#e83e8c', '#fd7e14'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'right',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw || 0;
                            const percentage = ((value / total) * 100).toFixed(2);
                            return `${context.label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });
}

function actualizarChartQuiebrasArea(data) {
    const ctx = document.getElementById('chartQuiebrasArea');
    if (!ctx) return;
    
    if (charts['quiebrasArea']) {
        charts['quiebrasArea'].destroy();
    }
    
    // Group defects by area
    const quiebrasPorArea = {};
    data.forEach(item => {
        if (item.areas) {
            item.areas.forEach(area => {
                if (!quiebrasPorArea[area]) {
                    quiebrasPorArea[area] = 0;
                }
                quiebrasPorArea[area] += item.total_quiebras / item.areas.length;
            });
        }
    });
    
    const labels = Object.keys(quiebrasPorArea);
    const values = Object.values(quiebrasPorArea);

    charts['quiebrasArea'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Quiebras por Área',
                data: values,
                backgroundColor: '#e74a3b',
                borderColor: '#e74a3b',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Número de Quiebras',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Área',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartTendenciaProduccion(produccionData, tendenciasData) {
    const ctx = document.getElementById('chartTendenciaProduccion');
    if (!ctx) return;
    
    if (charts['tendenciaProduccion']) {
        charts['tendenciaProduccion'].destroy();
    }
    
    // Group production by date
    const produccionPorFecha = {};
    if (produccionData) {
        produccionData.forEach(item => {
            const fecha = moment(item.fecha).format('YYYY-MM-DD');
            if (!produccionPorFecha[fecha]) {
                produccionPorFecha[fecha] = 0;
            }
            produccionPorFecha[fecha] += item.total_produccion;
        });
    }
    
    // Sort dates
    const fechas = Object.keys(produccionPorFecha).sort();
    const produccionValues = fechas.map(fecha => produccionPorFecha[fecha]);

    charts['tendenciaProduccion'] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: fechas.map(fecha => moment(fecha).format('DD/MM/YYYY')),
            datasets: [{
                label: 'Producción Diaria',
                data: produccionValues,
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                borderColor: '#4e73df',
                borderWidth: 2,
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#4e73df',
                pointBorderColor: '#fff',
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#4e73df',
                pointHoverBorderColor: '#fff',
                pointHitRadius: 10,
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Producción',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Fecha',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartTendenciaQuiebras(data) {
    const ctx = document.getElementById('chartTendenciaQuiebras');
    if (!ctx) return;
    
    if (charts['tendenciaQuiebras']) {
        charts['tendenciaQuiebras'].destroy();
    }
    
    const labels = data.map(item => moment(item.fecha).format('DD/MM/YYYY'));
    const values = data.map(item => item.total_quiebras);

    charts['tendenciaQuiebras'] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Quiebras Diarias',
                data: values,
                backgroundColor: 'rgba(231, 74, 59, 0.05)',
                borderColor: '#e74a3b',
                borderWidth: 2,
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#e74a3b',
                pointBorderColor: '#fff',
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#e74a3b',
                pointHoverBorderColor: '#fff',
                pointHitRadius: 10,
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Número de Quiebras',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Fecha',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartDistribucionQuiebras(data) {
    const ctx = document.getElementById('chartDistribucionQuiebras');
    if (!ctx) return;
    
    if (charts['distribucionQuiebras']) {
        charts['distribucionQuiebras'].destroy();
    }
    
    // Group defects by reason
    const quiebrasPorMotivo = {};
    data.forEach(item => {
        if (item.motivos) {
            Object.keys(item.motivos).forEach(motivo => {
                if (!quiebrasPorMotivo[motivo]) {
                    quiebrasPorMotivo[motivo] = 0;
                }
                quiebrasPorMotivo[motivo] += item.motivos[motivo];
            });
        }
    });
    
    // Sort by quantity and take the main ones
    const motivosOrdenados = Object.entries(quiebrasPorMotivo)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5);
    
    const labels = motivosOrdenados.map(item => item[0]);
    const values = motivosOrdenados.map(item => item[1]);
    const total = values.reduce((sum, val) => sum + val, 0);

    charts['distribucionQuiebras'] = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: ['#e74a3b', '#f6c23e', '#36b9cc', '#1cc88a', '#4e73df'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'right',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw || 0;
                            const percentage = ((value / total) * 100).toFixed(2);
                            return `${context.label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

function actualizarChartQuiebrasHora(data) {
    const ctx = document.getElementById('chartQuiebrasHora');
    if (!ctx) return;
    
    if (charts['quiebrasHora']) {
        charts['quiebrasHora'].destroy();
    }
    
    // Group defects by hour
    const quiebrasPorHora = Array(24).fill(0);
    data.forEach(item => {
        const hora = moment(item.fecha).hour();
        quiebrasPorHora[hora] += item.total_quiebras;
    });
    
    const labels = Array.from({length: 24}, (_, i) => `${i}:00`);
    const values = quiebrasPorHora;

    charts['quiebrasHora'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Quiebras por Hora',
                data: values,
                backgroundColor: 'rgba(231, 74, 59, 0.7)',
                borderColor: '#e74a3b',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Número de Quiebras',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Hora del Día',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartPrediccionQuiebras(data) {
    const ctx = document.getElementById('chartPrediccionQuiebras');
    if (!ctx) return;
    
    if (charts['prediccionQuiebras']) {
        charts['prediccionQuiebras'].destroy();
    }
    
    const labels = data.map(item => item.motivo);
    const historico = data.map(item => item.total_ultima_semana);
    const prediccion = data.map(item => item.prediccion_semana_siguiente);

    charts['prediccionQuiebras'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Quiebras Última Semana',
                    data: historico,
                    backgroundColor: 'rgba(231, 74, 59, 0.7)',
                    borderColor: '#e74a3b',
                    borderWidth: 1
                },
                {
                    label: 'Predicción Próxima Semana',
                    data: prediccion,
                    backgroundColor: 'rgba(28, 200, 138, 0.7)',
                    borderColor: '#1cc88a',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Número de Quiebras',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Motivo',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartPrediccionRendimiento(data) {
    const ctx = document.getElementById('chartPrediccionRendimiento');
    if (!ctx) return;
    
    if (charts['prediccionRendimiento']) {
        charts['prediccionRendimiento'].destroy();
    }
    
    const labels = data.map(item => item.empleado);
    const historico = data.map(item => item.produccion);
    const prediccion = data.map(item => item.prediccion_produccion || Math.round(item.produccion * 1.1));

    charts['prediccionRendimiento'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Producción Histórica',
                    data: historico,
                    backgroundColor: 'rgba(78, 115, 223, 0.7)',
                    borderColor: '#4e73df',
                    borderWidth: 1
                },
                {
                    label: 'Predicción Próximo Mes',
                    data: prediccion,
                    backgroundColor: 'rgba(108, 117, 125, 0.7)',
                    borderColor: '#6c757d',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Producción',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Empleado',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartCorrelacion(produccionData, quiebrasData) {
    const ctx = document.getElementById('chartCorrelacion');
    if (!ctx) return;
    
    if (charts['correlacion']) {
        charts['correlacion'].destroy();
    }
    
    // Prepare correlation data
    const datosCorrelacion = [];
    const areas = new Set();
    
    // Get all unique areas
    produccionData.forEach(item => {
        if (item.areas_produccion) {
            item.areas_produccion.forEach(area => areas.add(area));
        }
    });
    
    quiebrasData.forEach(item => {
        if (item.areas) {
            item.areas.forEach(area => areas.add(area));
        }
    });
    
    // Calculate production and defects by area
    areas.forEach(area => {
        let produccion = 0;
        let quiebras = 0;
        
        produccionData.forEach(item => {
            if (item.areas_produccion && item.areas_produccion.includes(area)) {
                produccion += item.total_produccion / item.areas_produccion.length;
            }
        });
        
        quiebrasData.forEach(item => {
            if (item.areas && item.areas.includes(area)) {
                quiebras += item.total_quiebras / item.areas.length;
            }
        });
        
        if (produccion > 0 || quiebras > 0) {
            datosCorrelacion.push({
                area: area,
                produccion: produccion,
                quiebras: quiebras
            });
        }
    });

    charts['correlacion'] = new Chart(ctx, {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'Producción vs Quiebras',
                data: datosCorrelacion.map(item => ({
                    x: item.produccion,
                    y: item.quiebras,
                    r: 15,
                    area: item.area
                })),
                backgroundColor: 'rgba(78, 115, 223, 0.7)',
                borderColor: '#4e73df',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { 
                    title: { 
                        display: true, 
                        text: 'Total Producción',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                y: { 
                    title: { 
                        display: true, 
                        text: 'Total Quiebras',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const data = context.raw;
                            return [
                                `Área: ${data.area}`,
                                `Producción: ${data.x}`,
                                `Quiebras: ${data.y}`
                            ];
                        }
                    },
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarChartPatronesTemporales(data) {
    const ctx = document.getElementById('chartPatronesTemporales');
    if (!ctx) return;
    
    if (charts['patronesTemporales']) {
        charts['patronesTemporales'].destroy();
    }
    
    const labels = data.map(item => `${item.hora}:00`);
    const tasa = data.map(item => parseFloat(item.tasa_quiebra.replace('%', '')));

    charts['patronesTemporales'] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Tasa de Quiebras por Hora (%)',
                data: tasa,
                backgroundColor: 'rgba(231, 74, 59, 0.1)',
                borderColor: '#e74a3b',
                borderWidth: 2,
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#e74a3b',
                pointBorderColor: '#fff',
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#e74a3b',
                pointHoverBorderColor: '#fff',
                pointHitRadius: 10,
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true, 
                    title: { 
                        display: true, 
                        text: 'Tasa de Quiebras (%)',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: { 
                    title: { 
                        display: true, 
                        text: 'Hora del Día',
                        font: {
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                tooltip: {
                    backgroundColor: '#5a5c69',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    padding: 12,
                    usePointStyle: true
                }
            }
        }
    });
}

function actualizarTablaTopEmpleados(data) {
    const tabla = $('#tablaTopEmpleados');
    if (dataTables['topEmpleados']) {
        dataTables['topEmpleados'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();

    data.forEach((item, index) => {
        tbody.append(`
            <tr>
                <td>${index + 1}</td>
                <td>${item.empleado}</td>
                <td>${item.produccion}</td>
                <td>${item.quiebras}</td>
                <td>${item.eficiencia}</td>
            </tr>
        `);
    });

    dataTables['topEmpleados'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[2, 'desc']]
    });
}

function actualizarTablaTopQuiebras(data) {
    const tabla = $('#tablaTopQuiebras');
    if (dataTables['topQuiebras']) {
        dataTables['topQuiebras'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();

    data.forEach((item, index) => {
        const motivos = item.motivos.join(', ');
        tbody.append(`
            <tr>
                <td>${index + 1}</td>
                <td>${item.empleado}</td>
                <td>${item.total_quiebras}</td>
                <td>${item.tasa_quiebra}%</td>
                <td>${motivos}</td>
            </tr>
        `);
    });

    dataTables['topQuiebras'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[2, 'desc']]
    });
}

function actualizarTablaProduccionArea(data) {
    const tabla = $('#tablaProduccionArea');
    if (dataTables['produccionArea']) {
        dataTables['produccionArea'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();
    
    // Calculate total production
    const totalProduccion = data.reduce((sum, item) => sum + item.total_produccion, 0);

    data.forEach(item => {
        const porcentajeTotal = totalProduccion > 0 ? 
            ((item.total_produccion / totalProduccion) * 100).toFixed(2) : 0;
        
        tbody.append(`
            <tr>
                <td>${item.empleado}</td>
                <td>${item.total_produccion}</td>
                <td>${porcentajeTotal}%</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="mostrarDetalleArea('${item.empleado.replace(/'/g, "\\'")}')">
                        <i class="bi bi-zoom-in"></i> Detalle
                    </button>
                </td>
            </tr>
        `);
    });

    dataTables['produccionArea'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[1, 'desc']]
    });
}

function actualizarTablaQuiebrasArea(data) {
    const tabla = $('#tablaQuiebrasArea');
    if (dataTables['quiebrasArea']) {
        dataTables['quiebrasArea'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();
    
    // Calculate total defects
    const totalQuiebras = data.reduce((sum, item) => sum + item.total_quiebras, 0);

    data.forEach(item => {
        const porcentajeTotal = totalQuiebras > 0 ? 
            ((item.total_quiebras / totalQuiebras) * 100).toFixed(2) : 0;
        
        const tasaQuiebra = item.total_produccion > 0 ? 
            ((item.total_quiebras / item.total_produccion) * 100).toFixed(2) : 0;
        
        tbody.append(`
            <tr>
                <td>${item.equipo}</td>
                <td>${item.total_quiebras}</td>
                <td>${tasaQuiebra}%</td>
                <td>${porcentajeTotal}%</td>
            </tr>
        `);
    });

    dataTables['quiebrasArea'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[1, 'desc']]
    });
}

function actualizarTablaPrediccionQuiebras(data) {
    const tabla = $('#tablaPrediccionQuiebras');
    if (dataTables['prediccionQuiebras']) {
        dataTables['prediccionQuiebras'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();

    data.forEach(item => {
        const variacion = ((item.prediccion_semana_siguiente - item.total_ultima_semana) / item.total_ultima_semana * 100).toFixed(2);
        const variacionClass = variacion > 0 ? 'text-danger' : 'text-success';
        const variacionIcon = variacion > 0 ? 'bi-arrow-up' : 'bi-arrow-down';
        
        tbody.append(`
            <tr>
                <td>${item.motivo}</td>
                <td>${item.total_ultima_semana}</td>
                <td>${item.prediccion_semana_siguiente}</td>
                <td class="${variacionClass}"><i class="bi ${variacionIcon}"></i> ${variacion}%</td>
            </tr>
        `);
    });

    dataTables['prediccionQuiebras'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[1, 'desc']]
    });
}

function actualizarTablaPrediccionRendimiento(data) {
    const tabla = $('#tablaPrediccionRendimiento');
    if (dataTables['prediccionRendimiento']) {
        dataTables['prediccionRendimiento'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();

    data.forEach(item => {
        const prediccion = item.prediccion_produccion || Math.round(item.produccion * 1.1);
        const variacion = ((prediccion - item.produccion) / item.produccion * 100).toFixed(2);
        const variacionClass = variacion > 0 ? 'text-success' : 'text-danger';
        const variacionIcon = variacion > 0 ? 'bi-arrow-up' : 'bi-arrow-down';
        
        tbody.append(`
            <tr>
                <td>${item.empleado}</td>
                <td>${item.produccion}</td>
                <td>${prediccion} <small class="${variacionClass}">(<i class="bi ${variacionIcon}"></i> ${variacion}%)</small></td>
                <td>${item.quiebras}</td>
                <td>${item.eficiencia}</td>
            </tr>
        `);
    });

    dataTables['prediccionRendimiento'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[1, 'desc']]
    });
}

function actualizarTablaCorrelacion(produccionData, quiebrasData) {
    const tabla = $('#tablaCorrelacion');
    if (dataTables['correlacion']) {
        dataTables['correlacion'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();
    
    // Get all unique areas
    const areas = new Set();
    produccionData.forEach(item => {
        if (item.areas_produccion) {
            item.areas_produccion.forEach(area => areas.add(area));
        }
    });
    
    quiebrasData.forEach(item => {
        if (item.areas) {
            item.areas.forEach(area => areas.add(area));
        }
    });
    
    // Calculate production and defects by area
    areas.forEach(area => {
        let produccion = 0;
        let quiebras = 0;
        
        produccionData.forEach(item => {
            if (item.areas_produccion && item.areas_produccion.includes(area)) {
                produccion += item.total_produccion / item.areas_produccion.length;
            }
        });
        
        quiebrasData.forEach(item => {
            if (item.areas && item.areas.includes(area)) {
                quiebras += item.total_quiebras / item.areas.length;
            }
        });
        
        if (produccion > 0 || quiebras > 0) {
            const tasaQuiebra = produccion > 0 ? ((quiebras / produccion) * 100).toFixed(2) : 0;
            
            tbody.append(`
                <tr>
                    <td>${area}</td>
                    <td>${Math.round(produccion)}</td>
                    <td>${Math.round(quiebras)}</td>
                    <td>${tasaQuiebra}%</td>
                </tr>
            `);
        }
    });

    dataTables['correlacion'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[3, 'desc']]
    });
}

function actualizarTablaPatrones(data) {
    const tabla = $('#tablaPatrones');
    if (dataTables['patrones']) {
        dataTables['patrones'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();

    data.forEach(item => {
        tbody.append(`
            <tr>
                <td>${item.hora}:00</td>
                <td>${item.total_produccion}</td>
                <td>${item.total_quiebras}</td>
                <td>${item.tasa_quiebra}</td>
            </tr>
        `);
    });

    dataTables['patrones'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[0, 'asc']]
    });
}

function actualizarTablaAreasProblematicas(data) {
    const tabla = $('#tablaAreasProblematicas');
    if (dataTables['areasProblematicas']) {
        dataTables['areasProblematicas'].destroy();
    }
    
    const tbody = tabla.find('tbody');
    tbody.empty();

    data.forEach(item => {
        const motivos = item.motivos.slice(0, 3).join(', ');
        const responsables = item.responsables.slice(0, 3).join(', ');
        
        tbody.append(`
            <tr>
                <td>${item.area}</td>
                <td>${item.total_quiebras}</td>
                <td>${motivos}</td>
                <td>${responsables}</td>
            </tr>
        `);
    });

    dataTables['areasProblematicas'] = tabla.DataTable({
        ...dataTablesConfig,
        order: [[1, 'desc']]
    });
}

function mostrarDetalleArea(area) {
    mostrarCarga(true);
    const params = obtenerParametrosFiltro();
    params.area = area;

    $.ajax({
        url: '?ajax=1',
        type: 'POST',
        data: params,
        dataType: 'json',
        success: function(data) {
            // Find specific area data
            let produccion = 0;
            let quiebras = 0;
            let empleados = [];
            
            if (data.produccion_detallada) {
                data.produccion_detallada.forEach(item => {
                    if (item.areas_produccion && item.areas_produccion.includes(area)) {
                        produccion += item.total_produccion;
                        empleados.push({
                            nombre: item.empleado,
                            produccion: item.total_produccion,
                            eficiencia: item.eficiencia
                        });
                    }
                });
            }
            
            if (data.quiebras_equipo) {
                data.quiebras_equipo.forEach(item => {
                    if (item.areas && item.areas.includes(area)) {
                        quiebras += item.total_quiebras;
                    }
                });
            }
            
            const tasaQuiebra = produccion > 0 ? ((quiebras / produccion) * 100).toFixed(2) : 0;
            
            // Sort employees by production
            empleados.sort((a, b) => b.produccion - a.produccion);
            
            const modalBody = `
                <h5 class="mb-3">Detalle de Área: ${area}</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">Resumen</h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Total Producción
                                        <span class="badge bg-primary rounded-pill">${produccion}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Quiebras
                                        <span class="badge bg-danger rounded-pill">${quiebras}</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Tasa de Quiebra
                                        <span class="badge bg-warning text-dark rounded-pill">${tasaQuiebra}%</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">Top Empleados</h6>
                            </div>
                            <div class="card-body">
                                <ol class="list-group list-group-numbered">
                                    ${empleados.slice(0, 3).map((item, index) => `
                                        <li class="list-group-item d-flex justify-content-between align-items-start">
                                            <div class="ms-2 me-auto">
                                                <div class="fw-bold">${item.nombre}</div>
                                                ${item.produccion} unidades
                                            </div>
                                            <span class="badge bg-success rounded-pill">${item.eficiencia}</span>
                                        </li>
                                    `).join('')}
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="chartDetalleArea"></canvas>
                        </div>
                    </div>
                </div>
            `;

            $('#detalleModalLabel').text(`Detalle: ${area}`);
            $('#detalleModalBody').html(modalBody);
            const modal = new bootstrap.Modal(document.getElementById('detalleModal'));
            modal.show();

            // Configure detail chart
            setTimeout(() => {
                const ctx = document.getElementById('chartDetalleArea');
                if (!ctx) return;
                
                const labels = data.tendencias_temporales ? 
                    data.tendencias_temporales.map(t => moment(t.fecha).format('DD/MM/YYYY')) : [];
                
                const produccionValues = data.tendencias_temporales ? 
                    data.tendencias_temporales.map(t => produccion / data.tendencias_temporales.length) : [];
                
                const quiebrasValues = data.tendencias_temporales ? 
                    data.tendencias_temporales.map(t => t.total_quiebras) : [];
                
                new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Producción',
                                data: produccionValues,
                                borderColor: '#4e73df',
                                backgroundColor: 'rgba(78, 115, 223, 0.1)',
                                tension: 0.3,
                                fill: true,
                                pointBackgroundColor: '#4e73df',
                                pointBorderColor: '#fff',
                                pointHoverRadius: 5,
                                pointHoverBackgroundColor: '#4e73df',
                                pointHoverBorderColor: '#fff',
                                pointHitRadius: 10,
                                pointBorderWidth: 2
                            },
                            {
                                label: 'Quiebras',
                                data: quiebrasValues,
                                borderColor: '#e74a3b',
                                backgroundColor: 'rgba(231, 74, 59, 0.1)',
                                tension: 0.3,
                                fill: true,
                                pointBackgroundColor: '#e74a3b',
                                pointBorderColor: '#fff',
                                pointHoverRadius: 5,
                                pointHoverBackgroundColor: '#e74a3b',
                                pointHoverBorderColor: '#fff',
                                pointHitRadius: 10,
                                pointBorderWidth: 2
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                title: { 
                                    display: true, 
                                    text: 'Cantidad',
                                    font: {
                                        weight: 'bold'
                                    }
                                },
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: { 
                                title: { 
                                    display: true, 
                                    text: 'Fecha',
                                    font: {
                                        weight: 'bold'
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                backgroundColor: '#5a5c69',
                                titleFont: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 12
                                },
                                padding: 12,
                                usePointStyle: true
                            }
                        }
                    }
                });
            }, 500);

            mostrarCarga(false);
        },
        error: function(xhr, status, error) {
            mostrarError('Error al cargar detalles del área: ' + error);
            mostrarCarga(false);
        }
    });
}

function mostrarError(mensaje) {
    // Remove previous toasts
    $('.toast').toast('dispose');
    $('.toast').remove();
    
    const toastHTML = `
        <div class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> ${mensaje}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    $('body').append(toastHTML);
    $('.toast').toast('show');
    
    setTimeout(() => {
        $('.toast').toast('hide');
    }, 5000);
}

// Make functions globally accessible
window.mostrarDetalleArea = mostrarDetalleArea;