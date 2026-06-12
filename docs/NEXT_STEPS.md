# NEXT_STEPS — Estado actual y próximos pasos

> Complementa `INSCRIPCION_CONTEXT.md`. Actualizado: 2026-06-11.

---

## Estado Actual del Proyecto

- Rama activa de desarrollo: `feature/editar-usuarios-firma` (basada en `main` `7718ca3`)
- Último commit en main: `7718ca3` — "Merge pull request #5 (opciones híbridas + edición de eventos)"
- **Nuevo (2026-06-11): edición de usuarios — Fase 1** (`admin/editar_usuario.php` + botón en `admin/usuarios.php`):
  - Editables: nombre, email, whatsapp, rol, activo y firma (subida opcional)
  - `usuario` (login) **solo lectura**; contraseña sin cambios aquí (se mantiene "Reset clave")
  - Firma nueva opcional (png/jpg/jpeg/webp, MIME real, 2MB); la anterior **no se borra** (fase 2)
  - Protecciones de último admin replicadas; email validado y sin duplicados
  - **No requirió SQL**; **no toca `correo/enviar_correo.php`** (ya consume `usuarios.firma_path`)
  - `webp` también habilitado en la creación de usuarios
- **Edición de eventos — Fase 1** (`admin/editar_evento.php` + botón en `admin/eventos.php`), ya en `main`:
  - URL pública/slug **inmutable** (cambiar el nombre no cambia la URL)
  - Modalidad y banderas `congreso_*` en solo lectura (fase 1)
  - Módulos virtuales: update por id / insert / soft-delete `activo=0` — nunca DELETE (protege `inscritos.modulos_seleccionados`)
  - Fechas: reemplazo solo si hubo cambios reales; con inscritos exige confirmación explícita
  - Adjuntos: solo agregar; eliminar adjuntos existentes queda para **fase 2**
  - **No requirió SQL**
- El sistema está funcional y en producción (`https://inscripcion.fycconsultores.com/public/`)
- El formulario de inscripción maneja **5 modalidades**:
  - `Presencial` — con tipo Congreso/Seminario
  - `Virtual` — COMPLETO o por MODULOS
  - `Hibrida` — con tipo Congreso/Seminario + **selección del inscrito: HIBRIDO (default) o HIB_VIRTUAL**
  - `Curso_Especial` — hasta 6 opciones: 4 presenciales + 2 virtuales (según `congreso_virtual`)
  - `Públicamente Hablando (Virtual)` — modalidad gratuita, sin soporte de pago
- Los correos al inscrito y al comercial se envían correctamente vía PHPMailer + SMTP Gmail Relay
- **Schema real verificado en BD local** (ver sección de BD más abajo)

## SQL — Estado de migraciones

- ✅ `docs/sql/2026_05_22_add_url_conexion_eventos.sql` — aplicada en LOCAL y PRODUCCIÓN
- ✅ `docs/sql/2026_06_03_congreso_virtual_curso_especial.sql` — **aplicada en LOCAL y PRODUCCIÓN** (ya no está pendiente)
- ✅ `feature/opciones-inscripcion-hibrida` — **NO requiere SQL**: los códigos `HIBRIDO` (7) y `HIB_VIRTUAL` (11) caben en `asistencia_tipo VARCHAR(25)` ya existente en ambas BD

> Nota: existe una rama local pausada `feature/diplomado-curso-especial` (commit `9242e4e`)
> con su propia migración (`2026_06_11_diplomado_tipo_evento_presencial.sql`, aplicada solo
> en BD LOCAL). Si esa rama se retoma, su SQL deberá ejecutarse en producción antes de su deploy.

---

## Qué se Revisó en Este Escaneo (2026-06-03)

- `public/registro.php` — flujo completo de inscripción, validaciones, correos
- `admin/crear_evento.php` — creación de eventos con módulos y tipo presencial
- `correo/enviar_correo.php` — clase CorreoDenuncia, ambos métodos de envío
- `db/conexion.php` + `db/conexion.local.php` — estrategia de conexión dual
- `config/url.php` — helper base_url
- `admin/_auth.php` + `admin/helpers/audit.php` — autenticación, roles, auditoría
- `admin/inscritos.php` — estado_contacto, exportación, filtros
- `.gitignore`, `composer.json`, `package.json`
- Estructura BD verificada con `DESCRIBE` sobre todas las tablas
- Valores de `modalidad` en BD: `Presencial`, `Virtual`, `Hibrida`, `Curso_Especial`

## Schema Real Verificado en BD (2026-06-03)

| Tabla.Columna | Tipo Real | Nota |
|---|---|---|
| `eventos.modalidad` | `VARCHAR(50)` | Case mixto en datos actuales |
| `eventos.tipo_evento_presencial` | `ENUM('Congreso','Seminario')` NULL | En BD LOCAL puede aparecer también 'Diplomado' (rama pausada, solo local) |
| `eventos.congreso_presencial` | `TINYINT(1) NOT NULL DEFAULT 1` | Aplicado en LOCAL y PRODUCCIÓN ✅ |
| `eventos.congreso_virtual` | `TINYINT(1) NOT NULL DEFAULT 0` | Aplicado en LOCAL y PRODUCCIÓN ✅ |
| `eventos.url_conexion` | `VARCHAR(500)` NULL | Migración `2026_05_22` aplicada |
| `inscritos.asistencia_tipo` | `VARCHAR(25)` NULL | Aplicado en LOCAL y PRODUCCIÓN ✅. HIBRIDO/HIB_VIRTUAL caben sin cambios |
| `inscritos.whatsapp_consent` | `ENUM('SI','NO')` NULL | No VARCHAR |
| `inscritos.estado_contacto` | `VARCHAR(30)` NOT NULL DEFAULT `pendiente_confirmacion` | Gestionado desde `admin/inscritos.php` |
| `usuarios.rol` | `ENUM('admin','editor')` | No VARCHAR genérico |

---

## Qué NO se Modificó

**Ningún archivo fue modificado.** Este escaneo fue exclusivamente de lectura y análisis.
Solo se crearon dos archivos nuevos de documentación:
- `docs/INSCRIPCION_CONTEXT.md`
- `docs/NEXT_STEPS.md`

---

## Próximos Cambios Recomendados (en Orden Seguro)

### Prioridad Alta — Seguridad

#### 1. Mover credenciales de producción fuera del código commiteado
- **Problema**: `db/conexion.php` tiene credenciales de BD de producción hardcodeadas. El archivo NO está en `.gitignore`
- **Problema**: `correo/enviar_correo.php` tiene App Password SMTP hardcodeado (aparece en dos métodos)
- **Solución sugerida**:
  - Crear un archivo `config/secrets.php` (ignorado en `.gitignore`)
  - O usar variables de entorno del servidor (`.htaccess` / PHP-FPM pool)
  - Referenciar desde los archivos PHP sin exponer valores
- **Rama sugerida**: `fix/credenciales-seguras`

#### 2. `.gitignore` para uploads y storage — PARCIALMENTE APLICADO ✅
- **Aplicado en `chore/preparacion-segura-docs-gitignore`**:
  - `/uploads/soportes/`, `/uploads/firmas/`, `/storage/mail_debug/`, `/storage/*.html`
  - `/uploads/eventos/*.png`, `*.jpg`, `*.jpeg`, `*.webp`
- **⚠️ Archivos ya trackeados NO se eliminaron** (21 imágenes de eventos + 5 soportes de pago + 1 HTML de debug)
- **Pendiente**: usar `git rm --cached` para desrastrear archivos sensibles ya commiteados (soportes de pago, HTML debug) — hacer con cuidado en rama separada

#### 3. Deshabilitar `display_errors` en producción
- `registro.php` e `inscritos.php` tienen `ini_set('display_errors', 1)` activo
- En producción: cambiar a `ini_set('display_errors', 0)` o controlar vía `php.ini`/`.htaccess`

#### 4. Eliminar o proteger el endpoint de debug de login
- `admin/login.php?debug=1` expone información de la BD
- Eliminar el bloque `if (isset($_GET['debug']))` o restringirlo a IPs de desarrollo

---

### Prioridad Media — Funcionalidad / UX

#### 5. Revisar consistencia del campo `modalidad` en BD
- Los valores guardados tienen case mixto: "Presencial", "Virtual", "Hibrida", "Curso_Especial"
- Las comparaciones en PHP usan `norm_modalidad()` (normaliza a minúsculas+underscore) ✅
- Las consultas SQL directas (ej. `eventos.php`) no normalizan → riesgo si se agregan filtros
- **Acción**: normalizar los valores en BD a minúsculas desde el INSERT, o agregar índice funcional

#### 6. Implementar CSRF tokens en formularios sensibles
- `registro.php` y formularios del admin no tienen protección CSRF
- Agregar token de sesión en formularios POST con validación server-side

#### 7. Revisar el archivo `public/assets/js/registro.js`
- Existe pero no se carga en `registro.php` (el script está inline)
- Determinar si está en uso, es un backup, o puede eliminarse

---

### Prioridad Baja — Mantenimiento

#### 8. Limpiar archivos backup y temporales
- `admin/crear_evento_FUNCIONA.php` y `public/registro_FUNCIONA.php` — copias de seguridad trackeadas en git
- `test_mail.php` en la raíz — archivo de prueba expuesto y trackeado
- Antes de eliminar cualquiera: confirmar que el código activo es más reciente y funcional
- También existe `public/assets/js/registro.js` — verificar si está en uso o es backup

#### 9. Agregar validación de MIME type real en subida de archivos
- Actualmente solo se valida por extensión
- Agregar `mime_content_type()` o Fileinfo para validación real

#### 10. Documentar el SQL de creación de tablas
- No se encontró ningún archivo `.sql` de estructura completa en el repositorio
- Existe `docs/sql/2026_05_22_add_url_conexion_eventos.sql` (migración puntual)
- Crear `docs/schema.sql` con el DDL completo de todas las tablas

#### 11. Revisar `inscritos.asistencia_tipo VARCHAR(20)`
- El valor `CONGRESO_MAS_MODULOS` ocupa exactamente 20 caracteres — al límite
- Considerar `ALTER TABLE inscritos MODIFY asistencia_tipo VARCHAR(25)` para dar margen
- Verificar modo SQL del servidor antes: en modo `STRICT_ALL_TABLES`, MySQL rechazaría; en modo no-estricto, truncaría silenciosamente

---

## Checklist Antes de Cualquier Deploy a Producción

```
[ ] git status -- limpio, sin cambios sin commitear
[ ] git pull -- rama local sincronizada con origin
[ ] Prueba local en Laragon: formulario de inscripción funciona end-to-end
[ ] Prueba local: correo en modo MAIL_DEBUG = true genera HTML correcto
[ ] No se commitearon: uploads/, storage/, vendor/, node_modules/, conexion.local.php, .env
[ ] No se exponen secretos en ningún archivo trackeado por git
[ ] display_errors = 0 en archivos PHP para producción
[ ] Verificar que el slug del evento de prueba no colisione con eventos reales
[ ] Backup de BD antes de cambios que involucren ALTER TABLE o nuevos campos
[ ] Notificar al equipo antes de modificar tabla `inscritos` o `eventos` (datos críticos)
[ ] Hacer commit descriptivo (qué cambió y por qué, no solo qué)
[ ] NO hacer push --force a main
[ ] Verificar en producción con un registro de prueba antes de dar por terminado
```

---

## Notas para Claude / ChatGPT en Futuras Sesiones

- Leer `INSCRIPCION_CONTEXT.md` al inicio de cada sesión para reconstruir contexto
- La modalidad `curso_especial` es la más compleja: tiene lógica propia en registro, creación y correos
- La modalidad `Públicamente Hablando (Virtual)` es gratuita: sin soporte de pago, sin "Tenga en cuenta"
- `norm_modalidad()` es el punto de verdad para comparar modalidades: siempre usarlo
- Los tipos ENUM reales en BD: `tipo_evento_presencial ENUM('Congreso','Seminario')`, `whatsapp_consent ENUM('SI','NO')`, `usuarios.rol ENUM('admin','editor')`
- `inscritos.estado_contacto` existe y se gestiona desde `admin/inscritos.php`: valores `inscrito`, `no_asiste`, `pendiente_confirmacion`, `no_contesta`
- Los correos tienen un modo debug (`$MAIL_DEBUG = false` en `registro.php` línea 638) — cambiar a `true` para probar localmente
- La firma del comercial viene de `usuarios.firma_path` y se intenta embeber como CID antes de usar URL
- El lugar por defecto es "Centro de Convenciones Cafam Floresta / Av. Cra. 68 No. 90-88" — se incluye la guía hotelera PDF si se usa este lugar
- Credenciales de producción hardcodeadas en `db/conexion.php` y `correo/enviar_correo.php` — riesgo activo, pendiente resolver
- `display_errors = 1` activo en `crear_evento.php` y `registro.php` — deshabilitar antes de deploy en producción
