# Contexto del Proyecto: Inscripción a Eventos F&C Consultores

> **Documento de contexto técnico** interno del equipo de desarrollo.
> Actualizado: 2026-07-16. Solo lectura / análisis. No ejecutar ni modificar desde este archivo.

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
│   ├── editar_evento.php    Edición de eventos existentes (slug inmutable)
│   ├── crear_evento_FUNCIONA.php  Backup/copia de seguridad del admin
│   ├── eventos.php          Listado de eventos activos
│   ├── inscritos.php        Lista de inscritos por evento (con filtros, export, estados)
│   ├── editar_inscrito.php  Edición de inscrito individual
│   ├── evento_delete.php    Eliminación de evento
│   ├── evento_export.php    Exportación CSV de inscritos
│   ├── evento_export_xls.php Exportación XLS de inscritos
│   ├── actividad.php        Log de auditoría
│   ├── usuarios.php         CRUD de usuarios (solo admin): crear, rol, activar, reset clave, eliminar
│   ├── editar_usuario.php   Edición completa de usuario + firma (solo admin)
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

### `admin/editar_usuario.php` (Fase 1 — 2026-06-11)
Edición completa de usuarios existentes (acceso `require_role('admin')`):
- Editables: `nombre`, `email`, `whatsapp`, `rol`, `activo` y `firma_path` (subida opcional)
- **`usuario` (login) es SOLO LECTURA** en fase 1; `password_hash` no se toca (se mantiene "Reset clave" en `usuarios.php`); tampoco `created_at` ni `last_login_*`
- Firma: si no se sube nueva, se conserva la actual; si se sube, archivo nuevo (`firma_<time>_<rand>.<ext>` en `uploads/firmas/`) y se actualiza `firma_path`. La firma anterior **no se borra** (fase 2)
- Validación firma: extensiones png/jpg/jpeg/**webp**, MIME real con `mime_content_type()`, máx 2MB
- Email validado con `filter_var` y verificación de duplicado contra otros usuarios
- Protecciones replicadas de `usuarios.php`: no autodesactivarse; no dejar el sistema sin al menos un administrador activo (no quitar rol ni desactivar al último admin)
- Auditoría con `log_activity('editar_usuario', 'usuario', $id, ...)`
- **No requiere SQL** (`firma_path VARCHAR(255)` ya existe). **No toca `correo/enviar_correo.php`**, que ya consume `usuarios.firma_path` como firma del comercial asignado
- `webp` también habilitado en el formulario de creación de `usuarios.php` (consistencia)

### `admin/editar_evento.php` (Fase 1 — 2026-06-11)
Edición de eventos ya creados **sin cambiar la URL pública**:
- Acceso: cualquier usuario logueado (igual que crear), con registro en `audit_log` (`editar_evento`)
- **`slug` es INMUTABLE**: no se incluye en el UPDATE ni se regenera al cambiar el nombre
- **`modalidad`, `congreso_presencial` y `congreso_virtual` son SOLO LECTURA** (fase 1)
- Editables: nombre, fecha_limite, lugar_personalizado, url_conexion, comercial, autoestudio, tipo_evento_presencial, imagen (solo si se sube nueva), fechas, módulos virtuales
- Adjuntos: solo se pueden AGREGAR nuevos; los existentes se conservan (eliminación = fase 2)
- Fechas: reemplazo delete+insert **solo si hubo cambios reales**; si el formulario llega vacío, se conservan las actuales
- Módulos virtuales: UPDATE en sitio por id / INSERT nuevos / quitados → `activo = 0` (**nunca DELETE**, los IDs referenciados por `inscritos.modulos_seleccionados` se preservan)
- Si el evento tiene inscritos y se modifican fechas/módulos, exige checkbox `confirmar_cambios_sensibles`
- No requirió SQL (el campo `activo` ya existía en `eventos_modulos_virtuales`)

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
- **Presencial + Seminario (2026-09-28)**: el inscrito elige cómo asistir (radio `asistencia_tipo`). El evento sigue siendo `Presencial` / `Seminario`; la elección es del inscrito, no del evento. Detectado en `registro.php` con `$is_seminario_pv` (`norm_modalidad() === 'presencial'` y tipo normalizado `seminario`)

| Opción | Valor `asistencia_tipo` | `incluye_presencial` | `incluye_virtual` |
|--------|------------------------|---------------------|------------------|
| 100% presencial — default | `SEM_100_PRESENCIAL` (18) | SI | NO |
| 100% virtual | `SEM_100_VIRTUAL` (15) | NO | SI |

- Fallback seguro en POST: cualquier valor distinto de `SEM_100_VIRTUAL` → `SEM_100_PRESENCIAL`. `modulos_seleccionados` siempre `''`
- `SEM_100_PRESENCIAL`: con lugar, guía hotelera si usa lugar por defecto y adjuntos de `docs/evento_presencial/{id}`
- `SEM_100_VIRTUAL`: sin lugar, sin guía hotelera y sin adjuntos de `docs/evento_presencial/{id}` (solo `docs/evento_virtual/{id}` si existiera)
- Agenda: ambas opciones reciben el bloque "📌 Fecha y horario" con las fechas del seminario (sin módulos)
- Correos: "Tipo de asistencia: 100% presencial / 100% virtual" en el correo al inscrito y en el aviso al comercial. Vista de inscritos y XLS: "100% presencial" / "100% virtual"
- El reenvío desde `admin/inscritos.php` respeta la elección: `SEM_100_VIRTUAL` se reenvía sin lugar ni guía hotelera
- Los códigos son distintos de `CONG_100_*` (Congreso_PV) a propósito: Seminario ≠ Congreso
- Presencial no Seminario (Congreso) no cambia: sin selector y `asistencia_tipo='COMPLETO'`. Seminarios inscritos antes del cambio conservan `COMPLETO`/NULL (sin backfill) y se leen como antes
- Sin SQL y sin cambios en `admin/crear_evento.php` ni `admin/editar_evento.php`

### `virtual`
- Fechas con tipo `virtual` en `eventos_fechas`
- Opción de inscripción: COMPLETO o MODULOS (selección por fechas individuales)
- No muestra lugar

### `Públicamente Hablando (Virtual)` *(normalizado como `públicamente_hablando_(virtual)`)*
- Modalidad gratuita — sin soporte de pago, sin bloque "Tenga en cuenta"
- Fechas con tipo `virtual` en `eventos_fechas`
- El formulario de registro oculta el campo de soporte de pago
- El correo de confirmación omite la sección de selección y el bloque de instrucciones de pago
- Admite URL de conexión opcional (`url_conexion`)
- Comparado en código con `$is_phv` / `$is_phv_mail` luego de pasar por `norm_modalidad()`
- Valor guardado en BD tal cual: `"Públicamente Hablando (Virtual)"`

### `hibrida`
- Fechas divididas: presenciales + virtuales en `eventos_fechas`
- Muestra bloque de ambas fechas en el formulario
- `tipo_evento_presencial`: Congreso o Seminario
- **Selección del inscrito** (2 opciones, radio `asistencia_tipo`):

| Opción | Valor `asistencia_tipo` | `incluye_presencial` | `incluye_virtual` |
|--------|------------------------|---------------------|------------------|
| Híbrido (presencial + virtual) — default | `HIBRIDO` | SI | SI |
| 100% virtual | `HIB_VIRTUAL` | NO | SI |

- Fallback seguro en POST: `HIBRIDO` (reproduce el comportamiento histórico)
- `modulos_seleccionados` siempre `''` (no hay módulos en Híbrida)
- `HIB_VIRTUAL`: sin lugar presencial, sin guía hotelera, sin adjuntos presenciales; con URL de conexión si existe
- `HIBRIDO`: con lugar (y guía hotelera si usa lugar por defecto); con URL de conexión si existe
- Correos: agenda separada en dos bloques — "📍 Agenda (Presencial)" (solo si incluye presencial) y "💻 Agenda (Virtual)" — ya no se mezclan las fechas en un solo bloque
- Inscritos híbridos históricos tienen `asistencia_tipo='COMPLETO'`: siguen tratándose como presencial+virtual (sin backfill)
- No requirió SQL ni cambios en `admin/crear_evento.php`

### `curso_especial`
- Módulos virtuales: tabla separada `eventos_modulos_virtuales` (con nombre, orden, fecha, hora)
- **Dos banderas independientes** en `eventos`:
  - `congreso_presencial TINYINT(1) DEFAULT 1`: activa el bloque de congreso presencial
  - `congreso_virtual    TINYINT(1) DEFAULT 0`: activa el bloque de congreso virtual
  - Ambas pueden estar activas simultáneamente → eventos con ambos congresos
- Fechas del congreso se guardan en `eventos_fechas`:
  - `tipo = 'presencial'` si `congreso_presencial = 1`
  - `tipo = 'virtual'`    si `congreso_virtual = 1`
  - Si ambos activos, las mismas fechas se guardan con ambos tipos
- **6 opciones totales** (la visibilidad de cada una depende de las banderas activas):

| Opción | Valor `asistencia_tipo` | Visible cuando | `incluye_presencial` | `incluye_virtual` |
|--------|------------------------|----------------|---------------------|------------------|
| 1: Congreso presencial | `CONGRESO` | `congreso_presencial=1` | SI | NO |
| 2: Curso completo | `CURSO_COMPLETO` | `congreso_presencial=1` | SI | SI |
| 3: Módulos virtuales | `MODULOS_VIRTUALES` | siempre (si hay módulos) | NO | SI |
| 4: Congreso presencial + módulos selec. | `CONGRESO_MAS_MODULOS` | `congreso_presencial=1` | SI | SI |
| 5: Congreso virtual | `CONG_VIRTUAL` | `congreso_virtual=1` | NO | SI |
| 6: Congreso virtual + todos los módulos | `CONG_VIRT_MODS` | `congreso_virtual=1` | NO | SI |

- Lugar presencial y guía hotelera: solo aparecen si `incluye_presencial = SI`
- En el correo, la agenda se determina por `asistencia_tipo`, no por las banderas del evento
- `modulos_seleccionados` en `inscritos`: CSV de IDs, `'ALL'` para todos, o `''` para solo congreso
- `inscritos.asistencia_tipo VARCHAR(25)` — CONG_VIRTUAL=12, CONG_VIRT_MODS=14, CONGRESO_MAS_MODULOS=20

### `curso_intensivo` (2026-07-16)
- Valor en BD del select de admin: `Curso_Intensivo` (visible: "Curso intensivo")
- Evento que es **solo curso** (sin congreso), con módulos virtuales con nombre
- Reutiliza la infraestructura de módulos de Curso Especial (`eventos_modulos_virtuales`, máx 10)
- En admin se muestra el bloque de módulos **sin** los checkboxes de tipo de congreso
- Las fechas generales del evento se guardan con `tipo='virtual'` en `eventos_fechas`
- **Selección del inscrito** (2 opciones, radio `curso_intensivo_opcion`):

| Opción visible | Valor `asistencia_tipo` | `modulos_seleccionados` | `incluye_presencial` | `incluye_virtual` |
|--------|------------------------|------------------------|---------------------|------------------|
| MODALIDAD HÍBRIDA — default | `INTENSIVO_HIBRIDO` | `ALL` | SI | SI |
| MODALIDAD 100% VIRTUAL | `INTENSIVO_VIRTUAL` | `ALL` | NO | SI |

- Textos de ayuda: "Sesiones presenciales más sesiones virtuales" y "Participa en toda la jornada académica virtual"
- **No hay selección parcial de módulos** (2026-07-29): ambas opciones inscriben a toda la jornada académica y guardan `modulos_seleccionados = 'ALL'`. El formulario ya no muestra checkboxes de módulos para esta modalidad ni valida una selección mínima; el listado de sesiones y módulos queda solo como agenda informativa
- Fallback seguro en POST: cualquier valor distinto de `INTENSIVO_VIRTUAL` → `INTENSIVO_HIBRIDO` + `ALL`
- **Códigos vigentes para nuevas inscripciones**: `INTENSIVO_HIBRIDO` e `INTENSIVO_VIRTUAL`. Los anteriores `INTENSIVO_COMPLETO` (18) e `INTENSIVO_MODULOS` (17) **solo se leen** por compatibilidad con registros previos y ya no se escriben
- Compatibilidad de lectura en correo, vista de inscritos y XLS: `INTENSIVO_COMPLETO` → "MODALIDAD HÍBRIDA"; `INTENSIVO_MODULOS` → "Módulos virtuales seleccionados (modalidad anterior)", porque esos registros sí tienen un CSV parcial y llamarlos "100% virtual" sería inexacto
- Correo: "📅 Sesiones del curso" + "💻 Agenda (Módulos virtuales)" completa en ambas opciones; la rama de módulos seleccionados solo aplica a registros anteriores. Con `INTENSIVO_HIBRIDO` el correo incluye lugar y guía hotelera; con `INTENSIVO_VIRTUAL` no
- Lugar: `admin/crear_evento.php` y `admin/editar_evento.php` permiten escribir `lugar_personalizado` para esta modalidad. En editar se usa `$permite_lugar` (`$tiene_presencial || $es_ci`) para no arrastrar las secciones de fechas y adjuntos presenciales
- **Lugar obligatorio** en Curso Intensivo (crear y editar): si llega vacío se muestra "Para Curso Intensivo debes indicar el lugar de las sesiones presenciales." y no se guarda. Así se evita caer al lugar por defecto (Cafam Floresta) y adjuntar una guía hotelera que puede no corresponder. En crear no se ofrece la pregunta "¿Deseas cambiar el lugar por defecto?": el campo va siempre habilitado y sin prellenar
- **Pendiente**: las fechas siguen guardándose todas con `tipo='virtual'`, así que no se puede marcar qué días son presenciales. Separar la agenda presencial/virtual de Curso Intensivo queda para una fase posterior
- Sin SQL: los códigos caben en `asistencia_tipo VARCHAR(25)` y `modalidad` es `VARCHAR(50)`

### `congreso_pv` (2026-07-16)
- Valor en BD del select de admin: `Congreso_PV` (visible: "Congreso presencial/virtual")
- Congreso donde el inscrito escoge **una sola** forma de asistir a la **misma jornada académica**
- NO existe opción "presencial + virtual" y NO hay módulos virtuales; no se usa la palabra "híbrido"
- `tipo_evento_presencial` aplica (Congreso/Seminario); lugar personalizado y adjuntos presencial+virtual disponibles
- Las fechas de la jornada se guardan duplicadas con `tipo='presencial'` y `tipo='virtual'` en `eventos_fechas` (como CE con ambas banderas)
- **Selección del inscrito** (2 opciones, radio `asistencia_tipo`):

| Opción | Valor `asistencia_tipo` | `incluye_presencial` | `incluye_virtual` |
|--------|------------------------|---------------------|------------------|
| 100% presencial — default | `CONG_100_PRESENCIAL` (19) | SI | NO |
| 100% virtual | `CONG_100_VIRTUAL` (16) | NO | SI |

- `modulos_seleccionados` siempre `''`
- `CONG_100_PRESENCIAL`: con lugar (y guía hotelera si usa lugar por defecto)
- `CONG_100_VIRTUAL`: sin lugar ni guía hotelera; con URL de conexión si existe
- Los códigos son distintos de `CONGRESO`/`CONG_VIRTUAL` (Curso Especial) a propósito, para no confundir etiquetas en correos y exportes
- Sin SQL: los códigos caben en `asistencia_tipo VARCHAR(25)`

---

## 9. Tablas de Base de Datos (Inferidas del Código)

### `eventos`
| Campo | Tipo (inferido) | Descripción |
|-------|----------------|-------------|
| id | INT PK AUTO | Identificador único |
| nombre | VARCHAR | Nombre del evento |
| slug | VARCHAR UNIQUE | Identificador URL (generado desde nombre) |
| imagen | VARCHAR | Nombre de archivo en `uploads/eventos/` |
| modalidad | VARCHAR(50) | Valores en BD: "Presencial", "Virtual", "Hibrida", "Curso_Especial", "Públicamente Hablando (Virtual)". Case mixto — siempre normalizar con `norm_modalidad()` en PHP |
| tipo_evento_presencial | **ENUM('Congreso','Seminario')** NULL | Solo aplica a modalidades con bloque presencial |
| congreso_presencial | TINYINT(1) NOT NULL DEFAULT 1 | Solo Curso Especial: 1 = congreso presencial disponible |
| congreso_virtual | TINYINT(1) NOT NULL DEFAULT 0 | Solo Curso Especial: 1 = congreso virtual disponible |
| lugar_personalizado | TEXT NULL | Lugar alternativo al por defecto |
| url_conexion | VARCHAR(500) NULL | URL opcional de acceso al evento. Si se diligencia, se muestra como botón en el correo al inscrito |
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
| asistencia_tipo | VARCHAR(25) | COMPLETO, MODULOS, CONGRESO, CURSO_COMPLETO, MODULOS_VIRTUALES, CONGRESO_MAS_MODULOS, CONG_VIRTUAL, CONG_VIRT_MODS, HIBRIDO, HIB_VIRTUAL, INTENSIVO_HIBRIDO, INTENSIVO_VIRTUAL, CONG_100_PRESENCIAL, CONG_100_VIRTUAL, SEM_100_PRESENCIAL, SEM_100_VIRTUAL (+ heredados INTENSIVO_COMPLETO, INTENSIVO_MODULOS: solo lectura) |
| modulos_seleccionados | VARCHAR NULL | CSV de IDs, "ALL", o vacío |
| whatsapp_consent | **ENUM('SI','NO')** NULL | |
| fecha_registro | DATETIME | Timestamp de registro |
| estado_contacto | VARCHAR(30) NOT NULL DEFAULT `pendiente_confirmacion` | Estado de seguimiento gestionado desde `admin/inscritos.php`. Valores: `inscrito`, `no_asiste`, `pendiente_confirmacion`, `no_contesta` |

### `usuarios`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | INT PK AUTO | |
| nombre | VARCHAR | Nombre completo |
| usuario | VARCHAR UNIQUE | Username para login |
| email | VARCHAR | |
| password_hash | VARCHAR | Hash bcrypt (`password_hash`) |
| rol | **ENUM('admin','editor')** | Solo dos valores válidos en BD |
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

### Rama `feature/seminario-presencial-virtual` (2026-09-28)
- Presencial + Seminario: el inscrito elige `SEM_100_PRESENCIAL` (default) o `SEM_100_VIRTUAL` — ver sección 8
- `public/registro.php`: selector de 2 opciones solo para Presencial + Seminario, rama POST propia con fallback seguro y flags `incluye_*` según la elección
- `correo/enviar_correo.php`: "Tipo de asistencia" en resumen, AltBody y aviso al comercial; sin línea de módulos para estos códigos
- `admin/inscritos.php` (etiquetas y reenvío) y `admin/evento_export_xls.php` (etiquetas)
- **Sin SQL**: los códigos caben en `asistencia_tipo` (18 y 15 caracteres). No se tocaron `crear_evento.php`, `editar_evento.php`, Congreso_PV, Curso_Intensivo, Curso_Especial, Híbrida ni Virtual

### Rama `feature/opciones-curso-intensivo-congreso-pv` (2026-07-16)
- Dos modalidades nuevas: `Curso_Intensivo` ("Curso intensivo") y `Congreso_PV` ("Congreso presencial/virtual") — ver sección 8
- Códigos nuevos de `asistencia_tipo`: `INTENSIVO_COMPLETO`, `INTENSIVO_MODULOS` (reemplazados el 2026-07-29 por `INTENSIVO_HIBRIDO` / `INTENSIVO_VIRTUAL`, ver sección 8), `CONG_100_PRESENCIAL`, `CONG_100_VIRTUAL` — todos caben en `VARCHAR(25)`, **sin SQL**
- `admin/crear_evento.php`: opciones nuevas en el select de modalidad; Curso Intensivo muestra módulos sin checkboxes de congreso; Congreso P/V pide tipo presencial, lugar y guarda la jornada como presencial+virtual
- `admin/editar_evento.php`: booleanos extendidos (`$es_ci`, `$es_cpv`, `$gestiona_modulos`) — Curso Intensivo gestiona módulos igual que CE; slug y modalidad siguen inmutables
- `public/registro.php`: ramas de render y POST propias para cada modalidad nueva; fallbacks seguros; las modalidades existentes no cambian
- `correo/enviar_correo.php`: etiquetas humanas para los 4 códigos nuevos en resumen, agenda, AltBody y aviso al comercial
- `admin/inscritos.php` y `admin/evento_export_xls.php`: `format_asistencia()`/`asistencia_humana()` ahora traducen también los códigos de CE, Híbrida y los nuevos (antes salían como "—")
- **No se tocaron**: Curso Especial (6 opciones intactas), Híbrida (igual), archivos backup `*_FUNCIONA.php`, `evento_export.php` (CSV sin columna de asistencia, igual que antes)

### Rama `feature/editar-usuarios-firma` (2026-06-11)
- Nuevo `admin/editar_usuario.php` — edición completa de usuario + firma (solo admin, ver sección 4)
- Botón "✏️ Editar" agregado en `admin/usuarios.php`
- Soporte `webp` añadido al formulario de creación de usuarios
- Sin SQL; sin cambios en `correo/enviar_correo.php` (ya consume `usuarios.firma_path`)

### Rama `feature/editar-eventos` (2026-06-11)
- Nuevo `admin/editar_evento.php` — edición fase 1 con slug inmutable (ver sección 4)
- Botón "✏️ Editar" agregado en `admin/eventos.php`
- Sin SQL; sin cambios en `crear_evento.php`, `registro.php` ni `enviar_correo.php`

### Rama `feature/opciones-inscripcion-hibrida` (2026-06-11)
- Modalidad **Híbrida** ahora tiene selección del inscrito: `HIBRIDO` (default) o `HIB_VIRTUAL` (ver sección 8)
- Nuevos códigos `asistencia_tipo`: `HIBRIDO` (7 chars), `HIB_VIRTUAL` (11 chars) — caben en VARCHAR(25), **sin SQL**
- `registro.php`: bloque de 2 radios para Híbrida; rama POST propia; flag `incluye_presencial` condicionado a la selección
- `enviar_correo.php`: textos de selección en correo participante y comercial; agenda Híbrida separada en bloques Presencial/Virtual
- `admin/crear_evento.php` **no fue modificado** (la creación híbrida ya capturaba todo lo necesario)

### Estado anterior: `main` en `2c79ebe` (2026-06-03)
- PR #4 — congreso presencial y/o virtual en Curso Especial
- PR #2 — modalidad Públicamente Hablando (Virtual)
- PR #1 — URL de conexión opcional + tipo evento presencial

### Campo `tipo_evento_presencial`
- **Tipo real en BD**: `ENUM('Congreso','Seminario')` NULL (no VARCHAR)
- **Dónde se guarda**: `crear_evento.php` → INSERT en `eventos`
- **Dónde se usa**: `registro.php` → label dinámico ("Congreso / Presencial" o "Seminario / Presencial"), también pasa a los datos de correo
- **Validación PHP**: si el valor no es exactamente "Congreso" o "Seminario", se usa "Congreso" como fallback
- **Cuándo aplica**: modalidades `presencial`, `hibrida`, `curso_especial`

### Campo `url_conexion`
- `VARCHAR(500)` NULL en `eventos`, migración aplicada (`docs/sql/2026_05_22_add_url_conexion_eventos.sql`)
- Si se configura, aparece como botón "Acceder al evento aquí" en el correo al inscrito
- También se reporta en el correo al comercial
- Aplica a: Virtual, Híbrida, Curso Especial, Públicamente Hablando (Virtual)

### Modalidad Públicamente Hablando (Virtual)
- Evento gratuito: sin soporte de pago, sin bloque de instrucciones de pago
- Detectado con `$is_phv` / `$is_phv_mail` tras `norm_modalidad()`
- Correo omite: bloque "Tenga en cuenta" y bloque de selección de asistencia

### Módulos virtuales en Curso Especial
- Tabla `eventos_modulos_virtuales` con nombre/tema por módulo
- El inscrito puede seleccionar módulos individuales (IDs) o todos (ALL)
- El correo de confirmación muestra agenda detallada según la selección
- `inscritos.asistencia_tipo VARCHAR(20)`: `CONGRESO_MAS_MODULOS` tiene exactamente 20 chars — en el límite

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
