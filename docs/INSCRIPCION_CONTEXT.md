# Contexto del Proyecto: Inscripción a Eventos F&C Consultores

> **Documento de contexto técnico** para trabajo coordinado entre Claude / ChatGPT y el equipo de desarrollo.
> Actualizado: 2026-05-22. Solo lectura / análisis. No ejecutar ni modificar desde este archivo.

---

## 1. Nombre del Proyecto

**Sistema de inscripción a eventos – F&C Consultores**
URL producción: `https://inscripcion.fycconsultores.com/public/`
Repositorio local: `C:\laragon\www\inscripcion`

---

## 2. Stack Tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | PHP (nativo, sin framework, compatible PHP 7.4+) |
| Base de datos | MySQL (MySQLi procedural) |
| Correo | PHPMailer `^6.10` vía Composer (`vendor/autoload.php`) |
| CSS | Tailwind CSS (compilado a `assets/css/output.css`) |
| JS | JavaScript vanilla + jQuery (`assets/js/jquery.min.js`) |
| Dependencias PHP | Composer (`composer.json` → `phpmailer/phpmailer`) |
| Node (build) | npm + PostCSS + Tailwind CLI (`package.json`, `postcss.config.js`, `tailwind.config.js`) |
| Servidor local | Laragon (Apache + PHP + MySQL) |
| Servidor producción | Plesk (Linux) |

---

## 3. Estructura Principal de Carpetas

```
inscripcion/
├── admin/                   Panel administrativo (requiere sesión)
│   ├── _auth.php            Middleware de sesión y roles
│   ├── _topbar.php          Barra superior compartida
│   ├── dashboard.php        Pantalla principal del admin
│   ├── login.php            Formulario de ingreso
│   ├── logout.php           Cierre de sesión
│   ├── crear_evento.php     Alta de nuevos eventos (con módulos, fechas, tipo)
│   ├── crear_evento_FUNCIONA.php  Backup/copia de seguridad del admin
│   ├── eventos.php          Listado de eventos activos
│   ├── inscritos.php        Lista de inscritos por evento (con filtros, export, estados)
│   ├── editar_inscrito.php  Edición de inscrito individual
│   ├── evento_delete.php    Eliminación de evento
│   ├── evento_export.php    Exportación CSV de inscritos
│   ├── evento_export_xls.php Exportación XLS de inscritos
│   ├── actividad.php        Log de auditoría
│   ├── usuarios.php         CRUD de usuarios (solo admin)
│   └── helpers/
│       └── audit.php        Funciones de log_activity y roles
│
├── assets/
│   ├── css/
│   │   ├── input.css        Fuente Tailwind (no compilar directo)
│   │   └── output.css       CSS compilado (usado por todas las vistas)
│   ├── js/
│   │   ├── jquery.min.js    jQuery
│   │   └── scripts.js       Scripts globales
│   └── img/
│       └── loader-buho.gif  Loader animado (búho)
│
├── config/
│   └── url.php              Helper base_url() — detecta HTTPS, host, y construye URLs absolutas
│
├── correo/
│   └── enviar_correo.php    Clase CorreoDenuncia — todo el envío de correos (PHPMailer)
│
├── db/
│   ├── conexion.php         Conexión MySQL — producción (carga local si existe)
│   └── conexion.local.php   Credenciales locales Laragon (en .gitignore ✅)
│
├── docs/                    Documentos adjuntos para correos y este archivo de contexto
│   ├── autoestudio/         Adjuntos de autoestudio por evento_id/
│   ├── evento_presencial/   Adjuntos para eventos presenciales por evento_id/
│   ├── evento_virtual/      Adjuntos para eventos virtuales por evento_id/
│   └── GUIA HOTELERA 2025 - Cafam.pdf  PDF adjuntado automáticamente a inscritos presenciales
│
├── public/
│   ├── registro.php         Formulario público de inscripción (principal)
│   ├── registro_FUNCIONA.php  Backup/copia de seguridad del formulario
│   └── assets/js/
│       └── registro.js      (presente, posiblemente no usado activamente)
│
├── storage/
│   └── mail_debug/          HTMLs de correos en modo debug (no se envían)
│
├── uploads/
│   ├── eventos/             Imágenes de portada de eventos
│   ├── soportes/            Comprobantes de pago subidos por inscritos
│   └── firmas/              Imágenes de firma de los comerciales
│
├── vendor/                  Dependencias Composer (en .gitignore ✅)
├── composer.json
├── package.json
├── tailwind.config.js
├── postcss.config.js
└── .gitignore
```

---

## 4. Archivos Importantes

### `public/registro.php`
Formulario público de inscripción. Responsabilidades:
- Recibe `?e=<slug>` → busca el evento en BD
- Renderiza el formulario según modalidad
- En POST: valida, guarda en `inscritos`, sube soporte de pago, envía correos
- Funciones auxiliares: `norm_modalidad()`, `titlecase_es()`, `strtoupper_utf8()`, `pintarFechasHtml()`, `pintarModulosVirtualesHtml()`

### `admin/crear_evento.php`
Panel de creación de eventos. Responsabilidades:
- Carga comerciales activos desde `usuarios`
- En POST: guarda evento en `eventos`, fechas en `eventos_fechas`, módulos en `eventos_modulos_virtuales`
- Maneja upload de imagen de portada y adjuntos por modalidad
- JS dinámico: muestra/oculta campos según modalidad elegida

### `correo/enviar_correo.php`
Clase `CorreoDenuncia` con PHPMailer. Dos métodos públicos:
- `sendConfirmacionInscripcion($nombre, $correo, $data)` → correo al inscrito con resumen, agenda, lugar, firma, adjuntos automáticos por tipo
- `sendAvisoNuevaInscripcion($correoDestino, $data)` → correo interno al comercial con datos del inscrito y soporte adjunto
- SMTP: `smtp-relay.gmail.com:25` con credenciales de cuenta institucional
- **ALERTA**: se detectó posible secreto (app password SMTP) hardcodeado en este archivo

### `db/conexion.php`
- Si existe `conexion.local.php`, lo usa (Laragon, `root` sin pass)
- Si no existe, usa credenciales de producción
- **ALERTA**: se detectó posible secreto (credenciales de BD producción) hardcodeado en este archivo
- `conexion.local.php` está en `.gitignore` ✅ pero `conexion.php` con credenciales de producción **no está ignorado** ⚠️

### `config/url.php`
Helper `base_url($path)`: detecta HTTPS/HTTP y construye URLs absolutas. `APP_BASE = 'public'`.

### `admin/_auth.php`
- `require_login()` → redirige a `login.php` si no hay `$_SESSION['uid']`
- `require_role($role)` → verifica rol exacto
- La sesión almacena: `uid`, `nombre`, `rol`, `usuario` (array)

### `admin/helpers/audit.php`
- `log_activity($conn, $action, $entity_type, $entity_id, $meta)` → inserta en `audit_log`
- `fyc_current_role()` → lee rol de múltiples variantes de sesión
- `require_any_role($roles)` → middleware de roles múltiples

---

## 5. Flujo de Creación de Eventos (Admin)

```
1. Admin autenticado accede a admin/crear_evento.php
2. Rellena formulario: nombre, imagen, comercial asignado, modalidad, tipo presencial, fechas/horarios, módulos virtuales (si Curso Especial), lugar, fecha límite, autoestudio
3. POST → servidor:
   a. Genera slug automático desde el nombre
   b. INSERT en `eventos` (con tipo_evento_presencial si aplica)
   c. INSERT en `eventos_fechas` (tipo: presencial/virtual/general según modalidad)
   d. Si Curso Especial: INSERT en `eventos_modulos_virtuales` (máx 10)
   e. Guarda adjuntos en docs/evento_presencial/{id}/ y/o docs/evento_virtual/{id}/
   f. Guarda adjuntos de autoestudio en docs/autoestudio/{id}/ si aplica
4. Redirige con ?ok=1&slug=<slug> → muestra URL pública del formulario
```

---

## 6. Flujo de Inscripción Pública

```
1. Inscrito accede a public/registro.php?e=<slug>
2. Sistema carga evento desde `eventos` y sus fechas desde `eventos_fechas`
3. Si modalidad = curso_especial → carga módulos de `eventos_modulos_virtuales`
4. Renderiza formulario con campos según modalidad:
   - Datos personales: nombres, apellidos, cédula, cargo, entidad, celular, ciudad, email personal, email corporativo
   - Tipo inscripción: Empresa / Persona Natural
   - Selección de asistencia (según modalidad)
   - Soporte de pago opcional (PDF/imagen ≤ 10MB)
   - Consentimiento WhatsApp (SI/NO)
   - ¿Por qué medio se enteró? (select + texto libre para "Otro")
5. POST → servidor:
   a. Normaliza nombres (titlecase), entidad (MAYÚSCULAS)
   b. Valida/procesa tipo de asistencia y módulos seleccionados
   c. Sube soporte de pago a uploads/soportes/
   d. INSERT en `inscritos`
   e. Determina si incluye_presencial y/o incluye_virtual para correos
   f. Envía correo de confirmación al inscrito (email_corporativo)
   g. Consulta comercial asignado al evento → envía aviso al comercial
   h. Muestra modal de éxito
```

---

## 7. Flujo de Correos

### Correo al inscrito (`sendConfirmacionInscripcion`)
- Asunto: `Confirmación de inscripción – {nombre_evento}`
- Contenido: banner del evento, encabezado (entidad/nombre), selección elegida, agenda (fechas/módulos según tipo), lugar si es presencial, botón WhatsApp del comercial, firma del comercial
- Adjuntos automáticos: archivos de `docs/evento_virtual/{id}/` y/o `docs/evento_presencial/{id}` según flags `incluye_virtual`/`incluye_presencial`
- Guía hotelera: se adjunta el PDF de `docs/` solo si es presencial y usa el lugar por defecto (Cafam Floresta)

### Correo al comercial (`sendAvisoNuevaInscripcion`)
- Asunto: `Nueva inscripción – {nombre_evento}`
- Contenido: datos completos del inscrito, selección (con badges visual), si adjuntó soporte o no
- Reply-To: email del inscrito
- Adjunto: soporte de pago si lo subió

---

## 8. Modalidades de Eventos

### `presencial`
- Fechas con tipo `presencial` en `eventos_fechas`
- Muestra lugar físico (por defecto: Cafam Floresta, o `lugar_personalizado`)
- Adjunta guía hotelera si usa lugar por defecto
- `tipo_evento_presencial`: Congreso o Seminario

### `virtual`
- Fechas con tipo `virtual` en `eventos_fechas`
- Opción de inscripción: COMPLETO o MODULOS (selección por fechas individuales)
- No muestra lugar

### `hibrida`
- Fechas divididas: presenciales + virtuales en `eventos_fechas`
- Muestra bloque de ambas fechas en el formulario
- `tipo_evento_presencial`: Congreso o Seminario

### `curso_especial`
- Bloque presencial: fechas en `eventos_fechas` con tipo `presencial`
- Módulos virtuales: tabla separada `eventos_modulos_virtuales` (con nombre, orden, fecha, hora)
- 4 opciones de inscripción:

| Opción | Valor `asistencia_tipo` | `incluye_presencial` | `incluye_virtual` |
|--------|------------------------|---------------------|------------------|
| Opción 1: Congreso/Seminario (solo presencial) | `CONGRESO` | SI | NO |
| Opción 2: Curso completo (presencial + todos los módulos) | `CURSO_COMPLETO` | SI | SI |
| Opción 3: Módulos virtuales (sin presencial) | `MODULOS_VIRTUALES` | NO | SI |
| Opción 4: Congreso/Seminario + módulos seleccionados | `CONGRESO_MAS_MODULOS` | SI | SI |

- `modulos_seleccionados` en `inscritos`: CSV de IDs de módulos, o `ALL` para curso completo, o vacío para solo congreso

---

## 9. Tablas de Base de Datos (Inferidas del Código)

### `eventos`
| Campo | Tipo (inferido) | Descripción |
|-------|----------------|-------------|
| id | INT PK AUTO | Identificador único |
| nombre | VARCHAR | Nombre del evento |
| slug | VARCHAR UNIQUE | Identificador URL (generado desde nombre) |
| imagen | VARCHAR | Nombre de archivo en `uploads/eventos/` |
| modalidad | VARCHAR | presencial, Virtual, virtual, Hibrida, Curso_Especial (case mixto posible) |
| tipo_evento_presencial | VARCHAR NULL | "Congreso" o "Seminario" (nuevo campo) |
| lugar_personalizado | TEXT NULL | Lugar alternativo al por defecto |
| fecha_limite | DATE | Fecha límite para confirmar asistencia |
| comercial_user_id | INT FK → usuarios | Comercial responsable del evento |
| autoestudio | TINYINT | 0/1 — si incluye material de autoestudio |
| firma_imagen | VARCHAR NULL | Nombre de archivo en `uploads/firmas/` |
| encargado_nombre | VARCHAR NULL | Nombre del encargado (comercial) para correos |
| whatsapp_numero | VARCHAR NULL | Número WhatsApp del comercial para el botón |

### `eventos_fechas`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT PK AUTO | |
| evento_id | INT FK → eventos | |
| tipo | VARCHAR | "presencial", "virtual", "general" |
| fecha | DATE | Fecha del día |
| hora_inicio | TIME | |
| hora_fin | TIME | |

### `eventos_modulos_virtuales`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT PK AUTO | |
| evento_id | INT FK → eventos | |
| orden | INT | Orden de presentación |
| fecha | DATE | Fecha del módulo |
| hora_inicio | TIME | |
| hora_fin | TIME | |
| nombre | VARCHAR | Nombre/tema del módulo |
| activo | TINYINT | 0/1 — si está activo |

### `inscritos`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT PK AUTO | |
| evento_id | INT FK → eventos | |
| tipo_inscripcion | VARCHAR | "EMPRESA" o "PERSONA NATURAL" |
| nombre | VARCHAR | Nombre completo normalizado (titlecase) |
| nombres | VARCHAR | Solo nombres |
| apellidos | VARCHAR | Solo apellidos |
| cedula | VARCHAR | Solo dígitos |
| cargo | VARCHAR | |
| entidad | VARCHAR | Empresa/entidad en MAYÚSCULAS |
| celular | VARCHAR | |
| ciudad | VARCHAR | |
| email_personal | VARCHAR NULL | |
| email_corporativo | VARCHAR | Destinatario principal del correo |
| medio | VARCHAR | Cómo se enteró del evento |
| soporte_pago | VARCHAR NULL | Ruta relativa en `uploads/soportes/` |
| asistencia_tipo | VARCHAR | COMPLETO, MODULOS, CONGRESO, CURSO_COMPLETO, MODULOS_VIRTUALES, CONGRESO_MAS_MODULOS |
| modulos_seleccionados | VARCHAR NULL | CSV de IDs, "ALL", o vacío |
| whatsapp_consent | VARCHAR NULL | "SI" o "NO" |
| fecha_registro | DATETIME | Timestamp de registro |

### `usuarios`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT PK AUTO | |
| nombre | VARCHAR | Nombre completo |
| usuario | VARCHAR UNIQUE | Username para login |
| email | VARCHAR | |
| password_hash | VARCHAR | Hash bcrypt (`password_hash`) |
| rol | VARCHAR | "admin" o rol de comercial |
| activo | TINYINT | 0/1 |
| whatsapp | VARCHAR NULL | Número WhatsApp del comercial |
| firma_path | VARCHAR NULL | Ruta a imagen de firma |

### `audit_log`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT PK AUTO | |
| user_id | INT NULL FK → usuarios | |
| action | VARCHAR | "login", "enter_module", etc. |
| entity_type | VARCHAR NULL | "usuario", "modulo", etc. |
| entity_id | VARCHAR NULL | ID de la entidad |
| meta | TEXT NULL | JSON con datos adicionales |
| ip | VARCHAR | IP del cliente |
| user_agent | VARCHAR | Navegador/UA (máx 255 chars) |

---

## 10. Riesgos Detectados / Puntos Delicados

### Críticos
- **Secreto en `correo/enviar_correo.php`**: se detectó posible app password SMTP hardcodeado. Mover a variable de entorno o a un archivo ignorado por git.
- **Secreto en `db/conexion.php`**: se detectaron posibles credenciales de base de datos de producción hardcodeadas. Este archivo NO está en `.gitignore`. Riesgo real de exposición en repositorio.
- **`uploads/` no está en `.gitignore`**: si hay soportes de pago o firmas con datos sensibles, podrían commitearse accidentalmente.

### Importantes
- **`error_reporting(E_ALL)` + `display_errors = 1`** activos en `registro.php` e `inscritos.php`. En producción esto expone información del servidor. Deshabilitar en producción.
- **Sin tokens CSRF** en formularios POST (registro, crear_evento, editar_inscrito). Vulnerable a CSRF.
- **Login debug endpoint** (`admin/login.php?debug=1`): expone estructura de BD y usuarios de muestra. Debe eliminarse o protegerse.
- **Normalización de modalidad**: el campo `modalidad` en BD puede tener valores con case mixto ("Presencial", "presencial", "Curso_Especial", "curso_especial"). La función `norm_modalidad()` lo maneja en PHP pero puede generar inconsistencias en consultas SQL directas.
- **Validación de subida de archivos**: se valida por extensión, no por MIME type real. Riesgo menor en contexto actual.

### Menores
- Archivos backup con sufijo `_FUNCIONA` (`crear_evento_FUNCIONA.php`, `registro_FUNCIONA.php`) en el repositorio. Riesgo de confusión o uso accidental.
- `public/assets/js/registro.js` existe pero aparentemente no se usa en el formulario activo.

---

## 11. Cambios Recientes Detectados

### Campo `tipo_evento_presencial` (último commit: `809ca78`)
- **Qué es**: campo nuevo en `eventos` con valor "Congreso" o "Seminario"
- **Dónde se guarda**: `crear_evento.php` → INSERT en `eventos`
- **Dónde se usa**: `registro.php` → label dinámico ("Congreso / Presencial" o "Seminario / Presencial"), también pasa a los datos de correo como `tipo_evento_presencial`
- **Validación**: si el valor no es exactamente "Congreso" o "Seminario", se usa "Congreso" como fallback
- **Cuándo aplica**: modalidades `presencial`, `hibrida`, `curso_especial`
- **En el formulario admin**: selector oculto que aparece dinámicamente al elegir modalidad con presencial

### Módulos virtuales en Curso Especial
- Tabla `eventos_modulos_virtuales` con nombre/tema por módulo
- El inscrito puede seleccionar módulos individuales (IDs) o todos (ALL)
- El correo de confirmación muestra agenda detallada según la selección

### Correos personalizados
- `sendConfirmacionInscripcion` usa `incluye_presencial`/`incluye_virtual` para decidir adjuntos y secciones del correo
- Firma del comercial: se intenta primero embebida (CID), luego por URL pública
- Soporte de pago adjunto en el correo al comercial

---

## 12. Reglas de Trabajo para Próximos Cambios

1. **Trabajar en ramas pequeñas**: una rama por feature o fix. Nunca directo en `main` sin revisión.
2. **Cambios mínimos**: no refactorizar por refactorizar. Cambiar solo lo necesario.
3. **Pruebas locales primero**: validar en Laragon antes de cualquier deploy a producción.
4. **No tocar producción sin confirmación explícita** del usuario/equipo.
5. **Validar `git status` antes y después** de cualquier cambio.
6. **No commitear**:
   - `node_modules/` ✅ ya ignorado
   - `vendor/` ✅ ya ignorado
   - `uploads/` ⚠️ NO está ignorado — agregar al `.gitignore`
   - `db/conexion.local.php` ✅ ya ignorado
   - `storage/mail_debug/` ⚠️ no verificado — revisar si está ignorado
   - Dumps SQL, archivos `.env`
7. **Secrets**: nunca hardcodear credenciales. Mover a `conexion.local.php` o variables de entorno.
8. **Campo `modalidad`**: siempre normalizar con `norm_modalidad()` antes de comparar. Los valores en BD pueden tener case mixto.
9. **Flujo de correos**: probar en modo `$MAIL_DEBUG = true` primero (guarda HTML en `storage/mail_debug/`).
