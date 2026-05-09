-- ============================================================
-- ESQUEMA TAO - Sistema de Capacitaciones y Alineación
-- Compatible con la base de datos existente de control_produccion
-- Zona horaria: America/Costa_Rica
-- ============================================================

-- -------------------------------------------------------
-- 1. CURSOS Y CAPACITACIONES
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS capacitaciones (
    id              INT             PRIMARY KEY AUTO_INCREMENT,
    titulo          VARCHAR(200)    NOT NULL,
    descripcion     TEXT,
    tipo            ENUM('curso','test','certificacion','taller') DEFAULT 'curso',
    duracion_min    INT             COMMENT 'Duración estimada en minutos',
    nivel           ENUM('basico','intermedio','avanzado')        DEFAULT 'basico',
    area            VARCHAR(100)                                   DEFAULT 'General',
    imagen          VARCHAR(300),
    activo          TINYINT(1)      DEFAULT 1,
    creado_por      VARCHAR(100),
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. MÓDULOS / LECCIONES DE CADA CURSO
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS modulos_capacitacion (
    id                  INT             PRIMARY KEY AUTO_INCREMENT,
    capacitacion_id     INT             NOT NULL,
    orden               INT             DEFAULT 1,
    titulo              VARCHAR(200)    NOT NULL,
    tipo_contenido      ENUM('video','texto','test','practica') DEFAULT 'texto',
    contenido           LONGTEXT,
    duracion_min        INT             DEFAULT 0,
    obligatorio         TINYINT(1)      DEFAULT 1,
    created_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (capacitacion_id) REFERENCES capacitaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 3. PREGUNTAS PARA TESTS / EVALUACIONES TAO
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS preguntas_evaluacion (
    id                  INT             PRIMARY KEY AUTO_INCREMENT,
    capacitacion_id     INT,            -- NULL = pregunta de evaluación TAO global
    modulo_id           INT,
    categoria           VARCHAR(100)    COMMENT 'Ej: Productividad, Liderazgo, Comunicacion',
    pregunta            TEXT            NOT NULL,
    tipo_pregunta       ENUM('opcion_multiple','verdadero_falso','escala_likert') DEFAULT 'opcion_multiple',
    opcion_a            TEXT,
    opcion_b            TEXT,
    opcion_c            TEXT,
    opcion_d            TEXT,
    respuesta_correcta  CHAR(1)         COMMENT 'a, b, c o d',
    peso                INT             DEFAULT 1,
    activa              TINYINT(1)      DEFAULT 1,
    FOREIGN KEY (capacitacion_id) REFERENCES capacitaciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 4. PROGRESO DE EMPLEADOS EN CAPACITACIONES
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS progreso_capacitacion (
    id                  INT             PRIMARY KEY AUTO_INCREMENT,
    empleado            VARCHAR(100)    NOT NULL,
    codigo_empleado     VARCHAR(50),
    capacitacion_id     INT             NOT NULL,
    modulo_id           INT,
    estado              ENUM('pendiente','en_progreso','completado','certificado') DEFAULT 'pendiente',
    puntaje             DECIMAL(5,2)    DEFAULT 0,
    fecha_inicio        DATETIME,
    fecha_completado    DATETIME,
    intentos            INT             DEFAULT 0,
    FOREIGN KEY (capacitacion_id) REFERENCES capacitaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 5. RESPUESTAS INDIVIDUALES POR INTENTO DE TEST
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS respuestas_test (
    id              INT         PRIMARY KEY AUTO_INCREMENT,
    empleado        VARCHAR(100),
    codigo_empleado VARCHAR(50),
    capacitacion_id INT,
    pregunta_id     INT,
    respuesta       CHAR(1),
    es_correcta     TINYINT(1),
    fecha           DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (capacitacion_id) REFERENCES capacitaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (pregunta_id)     REFERENCES preguntas_evaluacion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 6. RESULTADOS DE EVALUACIÓN TAO (7 DIMENSIONES PQCDSIM)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS resultados_tao (
    id                              INT             PRIMARY KEY AUTO_INCREMENT,
    empleado                        VARCHAR(100)    NOT NULL,
    codigo_empleado                 VARCHAR(50),
    area                            VARCHAR(100),
    fecha_evaluacion                DATETIME        DEFAULT CURRENT_TIMESTAMP,
    puntaje_total                   INT             DEFAULT 0,
    puntaje_maximo                  INT             DEFAULT 100,
    -- Dimensiones PQCDSIM
    dim_productividad               DECIMAL(5,2)    DEFAULT 0,
    dim_calidad                     DECIMAL(5,2)    DEFAULT 0,
    dim_costo                       DECIMAL(5,2)    DEFAULT 0,
    dim_entrega                     DECIMAL(5,2)    DEFAULT 0,
    dim_seguridad                   DECIMAL(5,2)    DEFAULT 0,
    dim_informacion                 DECIMAL(5,2)    DEFAULT 0,
    dim_moral                       DECIMAL(5,2)    DEFAULT 0,
    -- Competencias TAO
    comp_liderazgo                  DECIMAL(5,2)    DEFAULT 0,
    comp_comunicacion               DECIMAL(5,2)    DEFAULT 0,
    comp_trabajo_en_equipo          DECIMAL(5,2)    DEFAULT 0,
    comp_alineacion_organizacional  DECIMAL(5,2)    DEFAULT 0,
    recomendaciones                 TEXT,
    nivel_alineacion                ENUM('Critico','Bajo','Medio','Alto','Excelente') DEFAULT 'Bajo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 7. TICKETS SEDAC (RESOLUCIÓN DE PROBLEMAS EN EQUIPO)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS sedac_tickets (
    id              INT         PRIMARY KEY AUTO_INCREMENT,
    titulo          VARCHAR(200),
    problema        TEXT        NOT NULL,
    causa_raiz      TEXT,
    solucion        TEXT,
    area            VARCHAR(100),
    creado_por      VARCHAR(100),
    asignado_a      VARCHAR(100),
    estado          ENUM('abierto','en_analisis','en_solucion','cerrado','verificado') DEFAULT 'abierto',
    prioridad       ENUM('baja','media','alta','critica') DEFAULT 'media',
    fecha_creacion  DATETIME    DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre    DATETIME,
    comentarios     TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 8. FEEDBACK CUE CARDS (RETROALIMENTACIÓN RÁPIDA)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS cue_preguntas (
    id          INT         PRIMARY KEY AUTO_INCREMENT,
    pregunta    TEXT        NOT NULL,
    tipo        ENUM('si_no','escala_1_5','opcion_multiple') DEFAULT 'escala_1_5',
    activa      TINYINT(1)  DEFAULT 1,
    created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cue_respuestas (
    id          INT         PRIMARY KEY AUTO_INCREMENT,
    empleado    VARCHAR(100),
    pregunta_id INT,
    respuesta   VARCHAR(20),
    comentario  TEXT,
    fecha       DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pregunta_id) REFERENCES cue_preguntas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 9. CERTIFICADOS GENERADOS
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS certificados (
    id                  INT         PRIMARY KEY AUTO_INCREMENT,
    empleado            VARCHAR(100),
    codigo_empleado     VARCHAR(50),
    capacitacion_id     INT,
    folio               VARCHAR(50) UNIQUE,
    fecha_emision       DATETIME    DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento   DATE,
    archivo_pdf         VARCHAR(300),
    FOREIGN KEY (capacitacion_id) REFERENCES capacitaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- DATOS DE EJEMPLO: Capacitaciones TAO iniciales
-- -------------------------------------------------------
INSERT IGNORE INTO capacitaciones (titulo, descripcion, tipo, duracion_min, nivel, area, activo, creado_por) VALUES
('Alineación Organizacional TAO - Nivel Básico',
 'Introducción a la metodología TAO (Totally Aligned Organization). Aprende los principios fundamentales de alineación, productividad y trabajo en equipo.',
 'curso', 90, 'basico', 'General', 1, 'admin'),

('Evaluación PQCDSIM - Madurez Operacional',
 'Test de diagnóstico en las 7 dimensiones: Productividad, Calidad, Costo, Entrega, Seguridad, Información y Moral. Identifica brechas y oportunidades de mejora.',
 'test', 30, 'intermedio', 'General', 1, 'admin'),

('SEDAC - Resolución Creativa de Problemas',
 'Metodología para estructurar y resolver problemas diarios en equipo usando creatividad y datos. Taller práctico con casos reales.',
 'taller', 120, 'intermedio', 'Producción', 1, 'admin'),

('Liderazgo y Autoconocimiento (Archetypal Inner Figures)',
 'Programa de coaching ejecutivo basado en ciencias del comportamiento. Desarrolla tu liderazgo desde adentro hacia afuera.',
 'curso', 180, 'avanzado', 'Liderazgo', 1, 'admin'),

('Seguridad Industrial y Prevención de Riesgos',
 'Módulo obligatorio de seguridad en planta. Normas, procedimientos y mejores prácticas para un ambiente de trabajo seguro.',
 'certificacion', 60, 'basico', 'Seguridad', 1, 'admin');

-- Preguntas para evaluación TAO
INSERT IGNORE INTO preguntas_evaluacion (capacitacion_id, categoria, pregunta, tipo_pregunta, opcion_a, opcion_b, opcion_c, opcion_d, respuesta_correcta, peso) VALUES
(2, 'Productividad',
 '¿Con qué frecuencia se revisan los indicadores de productividad en su área?',
 'opcion_multiple',
 'Nunca o muy raramente',
 'Solo cuando hay problemas',
 'Mensualmente de forma planificada',
 'Diariamente con tableros visuales',
 'd', 2),

(2, 'Calidad',
 '¿Cómo se gestionan los defectos o no conformidades en su área?',
 'opcion_multiple',
 'No existe un proceso definido',
 'Se reportan al supervisor esporádicamente',
 'Existe un registro pero sin seguimiento sistemático',
 'Se registran, analizan y hay acciones correctivas documentadas',
 'd', 2),

(2, 'Seguridad',
 '¿Los empleados participan activamente en la identificación de riesgos de seguridad?',
 'opcion_multiple',
 'No, la seguridad es responsabilidad de otros',
 'Ocasionalmente cuando ocurre un incidente',
 'Existe un comité pero con baja participación',
 'Existe cultura proactiva de reporte y prevención',
 'd', 2),

(2, 'Moral',
 '¿Cómo describiría el clima laboral en su área de trabajo?',
 'opcion_multiple',
 'Existe mucha tensión y desconfianza',
 'Es tolerable pero hay poca motivación',
 'En general positivo con algunos roces',
 'Existe confianza, respeto y colaboración genuina',
 'd', 2),

(2, 'Comunicación',
 '¿La información importante sobre cambios o decisiones llega a tiempo a todos los involucrados?',
 'opcion_multiple',
 'Generalmente nos enteramos tarde o por rumores',
 'A veces sí, a veces no',
 'Hay comunicación formal pero no siempre oportuna',
 'Existe un sistema claro y oportuno de comunicación',
 'd', 2),

(2, 'Liderazgo',
 '¿Los líderes de su área predican con el ejemplo en cuanto a valores y comportamientos esperados?',
 'opcion_multiple',
 'Rara vez, hay mucha incongruencia',
 'A veces, depende del líder',
 'La mayoría lo intenta con resultados irregulares',
 'Consistentemente, los líderes son referentes de cultura',
 'd', 2),

(2, 'Trabajo en Equipo',
 '¿Cómo es la colaboración entre departamentos o áreas en su organización?',
 'opcion_multiple',
 'Cada área trabaja en silos sin coordinación',
 'Hay colaboración forzada solo cuando es urgente',
 'Existe buena voluntad pero sin procesos formales',
 'Colaboración fluida con procesos claros e interfaces definidas',
 'd', 2),

(2, 'Entrega',
 '¿Los compromisos de entrega con clientes internos o externos se cumplen de forma confiable?',
 'opcion_multiple',
 'Con frecuencia hay retrasos significativos',
 'Se cumple aproximadamente el 60-70% de los compromisos',
 'Se cumple 80-90% con algunas excepciones justificadas',
 'Se cumple de forma consistente con mecanismos de alerta temprana',
 'd', 2),

(2, 'Información',
 '¿Los datos e indicadores del negocio son accesibles y confiables para quienes los necesitan?',
 'opcion_multiple',
 'Los datos son difíciles de obtener y frecuentemente incorrectos',
 'Existen pero solo los directivos tienen acceso',
 'Están disponibles pero no siempre actualizados',
 'Existen tableros visuales accesibles, actualizados y confiables',
 'd', 2),

(2, 'Costo',
 '¿Existe consciencia de costos a nivel operativo en su área?',
 'opcion_multiple',
 'Los costos son tema solo de finanzas o dirección',
 'Hay conciencia básica pero sin impacto en decisiones',
 'Existen metas de costo pero sin seguimiento frecuente',
 'Todos conocen su impacto en costos y toman decisiones conscientes',
 'd', 2);

-- Preguntas iniciales Cue Cards
INSERT IGNORE INTO cue_preguntas (pregunta, tipo) VALUES
('¿Cómo calificarías tu nivel de satisfacción laboral hoy?', 'escala_1_5'),
('¿Tienes claros los objetivos de tu área para este mes?', 'si_no'),
('¿Recibiste retroalimentación de tu supervisor esta semana?', 'si_no'),
('¿Qué tan bien colaboró tu equipo para resolver problemas hoy?', 'escala_1_5'),
('¿Encontraste obstáculos para hacer tu trabajo eficientemente hoy?', 'si_no');

-- ============================================================
-- FIN DEL ESQUEMA
-- ============================================================
