# 🎓 TAO Pro — Versión Profesional
## Sistema de Capacitaciones · Base de datos: `produccion_quiebras`

---

## ¿Qué cambió vs la versión anterior?

| Aspecto | Versión anterior | Esta versión Pro |
|---|---|---|
| **BD** | CREATE TABLE genérico | Usa las tablas reales de `produccion_quiebras` |
| **Auth** | Sesión básica | Integrado con `empleados` real + `login_tao.php` |
| **Rutas** | `../config/database.php` relativa | `../../../config/database.php` (ruta correcta del proyecto) |
| **Auditoría** | No | Escribe en `auditoria_cambios` (tabla existente) |
| **Vistas SQL** | No | `v_resumen_capacitacion` y `v_tao_por_area` |
| **UI** | Tailwind CDN básico | Sidebar + topbar + diseño profesional con Orbitron/Rajdhani |
| **Admin** | Panel básico | Dashboard con 5 gráficos, tabla de empleados con TAO, filtros SEDAC |
| **Login** | Parcial | `login_tao.php` completo (incluido en tus archivos) |
| **Roles** | Solo admin | `administrador`, `supervisor`, `empleado` — con guards reales |

---

## 📁 Estructura de archivos

```
control_produccion/
├── login.php                                    ← Tu login principal existente
│
└── modules/
    └── capacitaciones/
        ├── login_tao.php                        ← LOGIN TAO (tu archivo, ya funciona)
        ├── capacitaciones.php                   ← ★ NUEVO PRO: Portal del empleado
        ├── backend_capacitaciones.php           ← ★ NUEVO PRO: API REST completa
        └── admin/
            └── admin_capacitaciones.php         ← ★ NUEVO PRO: Panel admin
```

---

## 🚀 Instalación — Solo 2 pasos

### Paso 1 — Ejecutar el parche SQL

```sql
-- En phpMyAdmin, abrir base de datos produccion_quiebras y ejecutar:
SOURCE tao_patch.sql;
```

Esto hace:
- Crea tabla `logs_actividad` (usada por `login_tao.php`)
- Agrega índices de rendimiento a las tablas TAO existentes
- Inserta 7 cursos de ejemplo con módulos
- Inserta 13 preguntas para la evaluación PQCDSIM
- Inserta 8 preguntas Cue Card
- Crea 2 vistas: `v_resumen_capacitacion` y `v_tao_por_area`

### Paso 2 — Copiar los 3 archivos PHP

Reemplaza los archivos anteriores con los nuevos:
```
modules/capacitaciones/capacitaciones.php          → Portal empleado (nuevo)
modules/capacitaciones/backend_capacitaciones.php  → API (nuevo)
modules/capacitaciones/admin/admin_capacitaciones.php → Panel admin (nuevo)
```

Tu `login_tao.php` ya está bien — no requiere cambios.

---

## 🔑 Sistema de permisos

| Rol (en `empleados.rol`) | Puede hacer |
|---|---|
| `empleado` | Ver cursos, tomar tests, evaluación TAO, SEDAC básico, Cue Cards |
| `supervisor` | Todo lo anterior + Ver dashboard admin, gestionar SEDAC, ver historial de empleados |
| `administrador` | Todo + CRUD de cursos, activar/desactivar, auditoría completa |

---

## 🗄️ Tablas usadas (todas ya existen en tu BD)

```sql
empleados              → Auth + datos del empleado (nombre, codigo, rol)
areas                  → Áreas de la empresa (usadas en filtros)
capacitaciones         → Catálogo de cursos
modulos_capacitacion   → Módulos por curso
preguntas_evaluacion   → Preguntas de tests y evaluación TAO
progreso_capacitacion  → Avance del empleado por curso/módulo
respuestas_test        → Respuestas individuales por intento
resultados_tao         → Resultados PQCDSIM con 10 dimensiones
certificados           → Folios de certificación emitidos
sedac_tickets          → Tickets de resolución de problemas
cue_preguntas          → Preguntas de feedback diario
cue_respuestas         → Respuestas de Cue Cards
auditoria_cambios      → Log de acciones admin (ya usada en tu sistema)
logs_actividad         → Log de accesos TAO (nueva, creada por el parche)
```

---

## 📊 Vistas SQL creadas por el parche

```sql
-- Resumen de capacitación por empleado (útil para reportes)
SELECT * FROM v_resumen_capacitacion;

-- Promedio PQCDSIM por área (para análisis organizacional)
SELECT * FROM v_tao_por_area;
```

---

## ⚡ Próximos pasos opcionales

1. **Certificados PDF** → Conectar con tu `pdf_generator.php` existente
2. **Notificaciones WhatsApp** → Llamar a `whatsapp.php` al aprobar un test
3. **Integración al dashboard principal** → Copiar el fragmento de `INTEGRACION_FRONTEND.php`
4. **Videos en módulos** → Agregar embed YouTube/Vimeo al campo `contenido` de `modulos_capacitacion`

---

**BD:** `produccion_quiebras` · **PHP:** 8.2 · **MariaDB:** 10.4 · **TZ:** America/Costa_Rica
