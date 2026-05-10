# 🎓 Módulo TAO — Capacitaciones y Alineación Organizacional
## Sistema de Capacitaciones + Metodología FLAME TAO Knoware

---

## 📁 Archivos entregados

| Archivo | Descripción |
|---|---|
| `tao_schema.sql` | Esquema completo de BD (9 tablas + datos de ejemplo) |
| `modules/capacitaciones/backend_capacitaciones.php` | API REST del módulo (~500 líneas) |
| `modules/capacitaciones/capacitaciones.php` | Frontend completo del empleado |
| `modules/capacitaciones/admin/admin_capacitaciones.php` | Panel de administración |
| `INTEGRACION_FRONTEND.php` | Fragmento para integrar en tu frontend.php |

---

## 🚀 Instalación (paso a paso)

### Paso 1 — Base de datos
```sql
-- En phpMyAdmin o tu cliente MySQL:
SOURCE /ruta/al/proyecto/tao_schema.sql;
```
Esto crea **9 tablas nuevas** + datos de ejemplo (5 cursos, 10 preguntas TAO, 5 preguntas Cue).

### Paso 2 — Copiar archivos
```
Copia los archivos a tu proyecto en:
control_produccion/modules/capacitaciones/
control_produccion/modules/capacitaciones/admin/
```

### Paso 3 — Verificar config de BD
El archivo `backend_capacitaciones.php` usa:
```php
require_once dirname(__DIR__) . '/config/database.php';
```
Igual que tu `backend.php` existente. Si tu ruta es diferente, ajusta la línea.

### Paso 4 — Integrar en el dashboard principal
Abre `INTEGRACION_FRONTEND.php` y copia:
- El enlace de navegación → pégalo en el nav de `frontend.php`
- La tarjeta TAO → pégala en el grid de KPIs
- El `<script>` → pégalo antes del `</body>` de `frontend.php`

---

## 🗂️ Funcionalidades incluidas

### Para empleados (capacitaciones.php)
- ✅ Catálogo de cursos con filtros por nivel/tipo
- ✅ Seguimiento de progreso por curso
- ✅ Test con corrección automática y puntaje
- ✅ Generación automática de folio de certificado
- ✅ Evaluación TAO PQCDSIM (10 preguntas, 10 dimensiones)
- ✅ Radar chart con resultados por dimensión
- ✅ Historial de evaluaciones previas
- ✅ Tickets SEDAC (registro de problemas en equipo)
- ✅ Cue Cards (feedback rápido diario, 3 preguntas)

### Para administradores (admin_capacitaciones.php)
- ✅ Dashboard con gráficos (evolución TAO, top áreas, SEDAC)
- ✅ CRUD completo de cursos
- ✅ Ranking de empleados por índice TAO
- ✅ Gestión de tickets SEDAC con actualización de estado
- ✅ Formulario para agregar preguntas a evaluación TAO
- ✅ KPIs: empleados capacitados, certificados, promedio TAO, cursos activos

---

## 🧩 Tablas creadas en BD

```
capacitaciones          → Catálogo de cursos
modulos_capacitacion    → Módulos/lecciones por curso
preguntas_evaluacion    → Preguntas para tests y evaluación TAO
progreso_capacitacion   → Registro de avance por empleado
respuestas_test         → Respuestas individuales de cada intento
resultados_tao          → Resultados PQCDSIM por empleado
sedac_tickets           → Tickets de resolución de problemas
cue_preguntas           → Preguntas de feedback diario
cue_respuestas          → Respuestas de feedback diario
certificados            → Registro de certificados emitidos
```

---

## 🔗 Compatibilidad con tu sistema existente

- Usa las mismas **sesiones PHP** (`$_SESSION['empleado']`, `$_SESSION['rol']`)
- Mismo archivo de configuración (`../config/database.php`)
- Misma zona horaria (`America/Costa_Rica`)
- Mismo stack: **PHP + MySQL + Tailwind CSS + Chart.js**
- La autenticación de admin verifica `$_SESSION['rol'] === 'administrador'`

---

## 📊 Metodología TAO implementada

### Evaluación PQCDSIM (7 dimensiones)
| Dimensión | Descripción |
|---|---|
| **P** — Productividad | Gestión de metas y rendimiento diario |
| **Q** — Calidad | Manejo de defectos y conformidades |
| **C** — Costo | Consciencia y gestión de costos operativos |
| **D** — Entrega | Cumplimiento de compromisos de entrega |
| **S** — Seguridad | Cultura de prevención y reporte de riesgos |
| **I** — Información | Acceso y confiabilidad de datos e indicadores |
| **M** — Moral | Clima laboral y motivación del equipo |

### Niveles de alineación
- 🔴 **Crítico** (< 30%) → Requiere intervención urgente
- 🟡 **Bajo** (30-49%) → Hay brechas significativas
- 🟡 **Medio** (50-69%) → En desarrollo, con oportunidades claras
- 🟢 **Alto** (70-84%) → Buen nivel, mantener y mejorar
- 🟢 **Excelente** (85-100%) → Referente en la organización

---

## ⚡ Próximos pasos opcionales

1. **Certificados en PDF** → Integrar con `dompdf` (ya tienes `pdf_generator.php`)
2. **Notificaciones WhatsApp** → Conectar con tu `whatsapp.php` existente al completar cursos
3. **Módulos con video** → Embeds de YouTube/Vimeo en `tipo_contenido = 'video'`
4. **Evaluación 360°** → Nueva tabla `evaluaciones_360` con feedback entre pares
5. **Gamificación** → Tabla `puntos_empleado` con badges por logros

---

Creado para integrarse con el sistema `control_produccion` existente.
