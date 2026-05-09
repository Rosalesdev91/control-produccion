-- ============================================================
-- tao_patch.sql
-- PARCHE para base de datos: produccion_quiebras
-- Las tablas TAO YA EXISTEN. Este archivo solo agrega:
--   1. Índices de rendimiento que faltan
--   2. Datos semilla (cursos + preguntas + cue_cards)
--   3. Tabla auxiliar logs_actividad (usada por login_tao.php)
--   4. SOLUCIÓN DEFINITIVA: Unifica collation de tabla empleados
-- Ejecutar UNA sola vez en phpMyAdmin o CLI
-- ============================================================

USE `produccion_quiebras`;

-- -------------------------------------------------------
-- 0. SOLUCIÓN DEFINITIVA: Unificar collation de la tabla empleados
--    Esto resuelve el error #1267 de mezcla de collations
-- -------------------------------------------------------
ALTER TABLE `empleados` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 1. TABLA AUXILIAR: logs_actividad (referenciada en login_tao.php)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs_actividad` (
  `id`       INT          NOT NULL AUTO_INCREMENT,
  `empleado` VARCHAR(100) NOT NULL,
  `accion`   VARCHAR(100) NOT NULL,
  `ip`       VARCHAR(45)  DEFAULT NULL,
  `fecha`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_empleado` (`empleado`),
  KEY `idx_accion`   (`accion`),
  KEY `idx_fecha`    (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de accesos y acciones en el módulo TAO';

-- -------------------------------------------------------
-- 2. ÍNDICES DE RENDIMIENTO (solo si no existen)
-- -------------------------------------------------------
-- capacitaciones
ALTER TABLE `capacitaciones`
  ADD INDEX IF NOT EXISTS `idx_cap_activo`  (`activo`),
  ADD INDEX IF NOT EXISTS `idx_cap_tipo`    (`tipo`),
  ADD INDEX IF NOT EXISTS `idx_cap_nivel`   (`nivel`),
  ADD INDEX IF NOT EXISTS `idx_cap_area`    (`area`);

-- progreso_capacitacion
ALTER TABLE `progreso_capacitacion`
  ADD INDEX IF NOT EXISTS `idx_prog_empleado`  (`empleado`),
  ADD INDEX IF NOT EXISTS `idx_prog_estado`    (`estado`),
  ADD INDEX IF NOT EXISTS `idx_prog_cap_emp`   (`capacitacion_id`, `empleado`);

-- resultados_tao
ALTER TABLE `resultados_tao`
  ADD INDEX IF NOT EXISTS `idx_tao_empleado`  (`empleado`),
  ADD INDEX IF NOT EXISTS `idx_tao_fecha`     (`fecha_evaluacion`),
  ADD INDEX IF NOT EXISTS `idx_tao_area`      (`area`),
  ADD INDEX IF NOT EXISTS `idx_tao_nivel`     (`nivel_alineacion`);

-- sedac_tickets
ALTER TABLE `sedac_tickets`
  ADD INDEX IF NOT EXISTS `idx_sedac_estado`    (`estado`),
  ADD INDEX IF NOT EXISTS `idx_sedac_creado`    (`creado_por`),
  ADD INDEX IF NOT EXISTS `idx_sedac_prioridad` (`prioridad`);

-- respuestas_test
ALTER TABLE `respuestas_test`
  ADD INDEX IF NOT EXISTS `idx_rt_empleado` (`empleado`),
  ADD INDEX IF NOT EXISTS `idx_rt_cap`      (`capacitacion_id`);

-- -------------------------------------------------------
-- 3. DATOS SEMILLA: Capacitaciones TAO
--    Usamos INSERT IGNORE para no duplicar si ya existen
-- -------------------------------------------------------
INSERT IGNORE INTO `capacitaciones`
  (id, titulo, descripcion, tipo, duracion_min, nivel, area, activo, creado_por)
VALUES
(1,
 'Alineación Organizacional TAO — Nivel Básico',
 'Introducción a la metodología TAO (Totally Aligned Organization). Principios de alineación, productividad y cultura organizacional. Obligatorio para todo el personal.',
 'curso', 90, 'basico', 'General', 1, 'admin'),

(2,
 'Diagnóstico PQCDSIM — Madurez Operacional',
 'Evaluación de madurez en las 7 dimensiones: Productividad, Calidad, Costo, Entrega, Seguridad, Información y Moral. Genera reporte personalizado con recomendaciones.',
 'test', 30, 'intermedio', 'General', 1, 'admin'),

(3,
 'SEDAC — Resolución Creativa de Problemas',
 'Metodología Structure to Enhance Daily Activity through Creativity. Aprende a estructurar y resolver problemas del día a día en equipo usando datos y creatividad.',
 'taller', 120, 'intermedio', 'Producción', 1, 'admin'),

(4,
 'Liderazgo y Autoconocimiento (AIF)',
 'Programa de coaching ejecutivo basado en Archetypal Inner Figures y ciencias del comportamiento. Desarrolla tu liderazgo auténtico desde adentro hacia afuera.',
 'curso', 180, 'avanzado', 'Liderazgo', 1, 'admin'),

(5,
 'Seguridad Industrial y Prevención de Riesgos',
 'Módulo obligatorio de seguridad en planta. Normas, procedimientos y mejores prácticas para un ambiente de trabajo seguro. Requiere aprobación de test al final.',
 'certificacion', 60, 'basico', 'Seguridad', 1, 'admin'),

(6,
 'Calidad y Control de Procesos Ópticos',
 'Estándares de calidad aplicados al laboratorio óptico. Manejo de rectificaciones, check pruebas, y control de quiebras. Específico para producción.',
 'curso', 75, 'intermedio', 'Producción', 1, 'admin'),

(7,
 'Cue Cards — Feedback Diario y Cultura TAO',
 'Herramienta de comunicación masiva para obtener retroalimentación rápida. Aprende a dar y recibir feedback constructivo para mejorar el clima organizacional.',
 'taller', 45, 'basico', 'General', 1, 'admin');

-- -------------------------------------------------------
-- 4. MÓDULOS para el curso #1 (Alineación Básica)
-- -------------------------------------------------------
INSERT IGNORE INTO `modulos_capacitacion`
  (capacitacion_id, orden, titulo, tipo_contenido, duracion_min, obligatorio)
VALUES
(1, 1, '¿Qué es una Organización Totalmente Alineada?', 'texto',   20, 1),
(1, 2, 'Los 7 Desperdicios y cómo eliminarlos',          'texto',   25, 1),
(1, 3, 'Comunicación efectiva en equipos de trabajo',    'video',   20, 1),
(1, 4, 'Test de comprensión — Módulo TAO Básico',        'test',    25, 1),
(5, 1, 'Identificación de riesgos en planta',            'texto',   20, 1),
(5, 2, 'Procedimientos de emergencia y evacuación',      'video',   20, 1),
(5, 3, 'Test de Certificación en Seguridad Industrial',  'test',    20, 1);

-- -------------------------------------------------------
-- 5. PREGUNTAS para la Evaluación TAO (capacitacion_id=2)
--    Escala de madurez: A=nivel 1 (peor) → D=nivel 4 (excelente)
-- -------------------------------------------------------
INSERT IGNORE INTO `preguntas_evaluacion`
  (capacitacion_id, categoria, pregunta, tipo_pregunta,
   opcion_a, opcion_b, opcion_c, opcion_d,
   respuesta_correcta, peso, activa)
VALUES

-- Productividad
(2, 'Productividad',
 '¿Con qué frecuencia se revisan los indicadores de productividad en su área?',
 'opcion_multiple',
 'Nunca o muy raramente',
 'Solo cuando hay problemas graves',
 'Mensualmente de forma planificada',
 'Diariamente con tableros visuales visibles para todos',
 'd', 2, 1),

(2, 'Productividad',
 '¿Las metas de producción están claramente definidas y son conocidas por todos los operarios?',
 'opcion_multiple',
 'No existen metas formales definidas',
 'Existen pero solo las conoce la jefatura',
 'Están publicadas pero pocas personas las conocen',
 'Todos las conocen y trabajan activamente para alcanzarlas',
 'd', 2, 1),

-- Calidad
(2, 'Calidad',
 '¿Cómo se gestionan los defectos o no conformidades en su área?',
 'opcion_multiple',
 'No existe un proceso definido para manejarlos',
 'Se reportan al supervisor esporádicamente',
 'Existe un registro pero sin seguimiento sistemático',
 'Se registran, analizan causas raíz y hay acciones correctivas documentadas',
 'd', 2, 1),

(2, 'Calidad',
 '¿Existe un proceso de verificación de calidad antes de que el producto llegue al cliente?',
 'opcion_multiple',
 'No hay verificación formal, se descubre cuando el cliente reclama',
 'Se hace verificación ocasional según disponibilidad',
 'Hay un proceso pero no siempre se aplica consistentemente',
 'Existe un proceso robusto, documentado y aplicado en cada unidad',
 'd', 2, 1),

-- Seguridad
(2, 'Seguridad',
 '¿Los empleados participan activamente en la identificación de riesgos de seguridad?',
 'opcion_multiple',
 'No, la seguridad es responsabilidad exclusiva del departamento de seguridad',
 'Ocasionalmente, solo cuando ocurre un incidente',
 'Existe un comité de seguridad pero con baja participación general',
 'Existe cultura proactiva de reporte y prevención a todos los niveles',
 'd', 2, 1),

-- Entrega
(2, 'Entrega',
 '¿Los compromisos de entrega con clientes internos o externos se cumplen de forma confiable?',
 'opcion_multiple',
 'Con frecuencia hay retrasos significativos sin comunicación previa',
 'Se cumple aproximadamente el 60-70% de los compromisos',
 'Se cumple 80-90% con algunas excepciones justificadas',
 'Se cumple de forma consistente y hay mecanismos de alerta temprana ante riesgos',
 'd', 2, 1),

-- Moral / Clima
(2, 'Moral',
 '¿Cómo describiría el clima laboral en su área de trabajo?',
 'opcion_multiple',
 'Existe mucha tensión, desconfianza y conflictos frecuentes',
 'Es tolerable pero hay poca motivación y compromiso',
 'En general positivo, con algunos roces que se resuelven',
 'Existe confianza genuina, respeto mutuo y colaboración activa',
 'd', 2, 1),

(2, 'Moral',
 '¿Los empleados sienten que sus ideas y sugerencias son valoradas y tomadas en cuenta?',
 'opcion_multiple',
 'Las sugerencias no se reciben o son ignoradas sistemáticamente',
 'Hay un buzón pero nadie revisa ni da retroalimentación',
 'Algunas ideas se implementan pero el proceso no es consistente',
 'Existe un sistema claro donde las ideas se reciben, evalúan e implementan con reconocimiento',
 'd', 2, 1),

-- Comunicación
(2, 'Comunicación',
 '¿La información importante sobre cambios y decisiones llega a tiempo a todos los involucrados?',
 'opcion_multiple',
 'Generalmente nos enteramos tarde o por rumores informales',
 'A veces sí, a veces no — depende del supervisor',
 'Hay comunicación formal pero no siempre oportuna ni completa',
 'Existe un sistema claro, oportuno y bidireccional de comunicación',
 'd', 2, 1),

-- Liderazgo
(2, 'Liderazgo',
 '¿Los líderes de su área predican con el ejemplo en cuanto a valores y comportamientos esperados?',
 'opcion_multiple',
 'Rara vez, existe mucha incongruencia entre lo que dicen y hacen',
 'A veces, depende del líder y del momento',
 'La mayoría lo intenta pero los resultados son irregulares',
 'Consistentemente — los líderes son referentes visibles de la cultura organizacional',
 'd', 2, 1),

-- Trabajo en Equipo
(2, 'Trabajo en Equipo',
 '¿Cómo es la colaboración entre departamentos o áreas en su organización?',
 'opcion_multiple',
 'Cada área trabaja completamente en silos, sin coordinación',
 'Hay colaboración forzada solo cuando surge una urgencia',
 'Existe buena voluntad pero sin procesos formales de coordinación',
 'Colaboración fluida con procesos claros, interfaces definidas y metas compartidas',
 'd', 2, 1),

-- Información
(2, 'Información',
 '¿Los datos e indicadores del negocio son accesibles y confiables para quienes los necesitan?',
 'opcion_multiple',
 'Los datos son difíciles de obtener y frecuentemente incorrectos o desactualizados',
 'Existen pero solo los directivos o jefaturas tienen acceso',
 'Están disponibles pero no siempre actualizados o fáciles de interpretar',
 'Existen tableros visuales accesibles, actualizados en tiempo real y confiables para todos',
 'd', 2, 1),

-- Costo
(2, 'Costo',
 '¿Existe consciencia de costos a nivel operativo en su área?',
 'opcion_multiple',
 'Los costos son tema exclusivo de finanzas o dirección, no del operario',
 'Hay conciencia básica pero sin impacto real en las decisiones diarias',
 'Existen metas de costo pero sin seguimiento frecuente ni visible',
 'Todos conocen su impacto en costos y toman decisiones conscientes para reducirlos',
 'd', 2, 1);

-- -------------------------------------------------------
-- 6. PREGUNTAS Cue Cards (feedback diario)
-- -------------------------------------------------------
INSERT IGNORE INTO `cue_preguntas` (pregunta, tipo, activa) VALUES
('¿Cómo calificarías tu nivel de satisfacción laboral hoy?',          'escala_1_5',        1),
('¿Tienes claros los objetivos de tu área para este mes?',            'si_no',             1),
('¿Recibiste retroalimentación de tu supervisor esta semana?',        'si_no',             1),
('¿Qué tan bien colaboró tu equipo para resolver problemas hoy?',     'escala_1_5',        1),
('¿Encontraste obstáculos que impidieron hacer tu trabajo hoy?',      'si_no',             1),
('¿Cuentas con las herramientas y recursos necesarios para trabajar?','si_no',             1),
('¿Cómo calificarías la comunicación con tu jefe directo hoy?',       'escala_1_5',        1),
('¿Hubo algún problema de seguridad en tu área hoy?',                 'si_no',             1);

-- -------------------------------------------------------
-- 7. VISTA ÚTIL: Resumen de capacitación por empleado (CORREGIDA)
-- -------------------------------------------------------
DROP VIEW IF EXISTS `v_resumen_capacitacion`;

CREATE OR REPLACE VIEW `v_resumen_capacitacion` AS
SELECT
  e.nombre_empleado,
  e.codigo_empleado,
  e.rol,
  COUNT(DISTINCT pc.capacitacion_id) AS cursos_iniciados,
  SUM(pc.estado IN ('completado','certificado')) AS cursos_completados,
  ROUND(AVG(CASE WHEN pc.puntaje > 0 THEN pc.puntaje END), 1) AS promedio_puntaje,
  COUNT(DISTINCT cert.id) AS certificados,
  MAX(rt.puntaje_total) AS mejor_indice_tao,
  (SELECT rt2.nivel_alineacion 
   FROM resultados_tao rt2
   WHERE rt2.empleado = e.nombre_empleado
   ORDER BY rt2.fecha_evaluacion DESC 
   LIMIT 1) AS ultimo_nivel_tao
FROM empleados e
LEFT JOIN progreso_capacitacion pc ON pc.empleado = e.nombre_empleado AND pc.modulo_id IS NULL
LEFT JOIN certificados cert ON cert.empleado = e.nombre_empleado
LEFT JOIN resultados_tao rt ON rt.empleado = e.nombre_empleado
GROUP BY e.id, e.nombre_empleado, e.codigo_empleado, e.rol;

-- -------------------------------------------------------
-- 8. VISTA: Estadísticas de dimensiones TAO por área
-- -------------------------------------------------------
CREATE OR REPLACE VIEW `v_tao_por_area` AS
SELECT
  area,
  COUNT(*) AS evaluaciones,
  ROUND(AVG(puntaje_total), 1) AS promedio_total,
  ROUND(AVG(dim_productividad), 1) AS prom_productividad,
  ROUND(AVG(dim_calidad), 1) AS prom_calidad,
  ROUND(AVG(dim_costo), 1) AS prom_costo,
  ROUND(AVG(dim_entrega), 1) AS prom_entrega,
  ROUND(AVG(dim_seguridad), 1) AS prom_seguridad,
  ROUND(AVG(dim_informacion), 1) AS prom_informacion,
  ROUND(AVG(dim_moral), 1) AS prom_moral,
  MAX(fecha_evaluacion) AS ultima_evaluacion
FROM resultados_tao
WHERE area IS NOT NULL AND area != ''
GROUP BY area
ORDER BY promedio_total DESC;

-- ============================================================
-- FIN DEL PARCHE
-- ============================================================
COMMIT;