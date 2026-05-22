# NEXT_STEPS — Estado actual y próximos pasos

> Complementa `INSCRIPCION_CONTEXT.md`. Actualizado: 2026-05-22.

---

## Estado Actual del Proyecto

- Rama: `main` — limpia, sin cambios pendientes
- Último commit: `809ca78` — "Creacion de espacio para evento presencial si es congreso y seminario"
- El sistema está funcional y en producción (`https://inscripcion.fycconsultores.com/public/`)
- El formulario de inscripción maneja 4 modalidades: presencial, virtual, híbrida, curso especial
- Curso especial tiene 4 opciones de inscripción con módulos virtuales seleccionables
- Los correos al inscrito y al comercial se envían correctamente vía PHPMailer + SMTP Gmail Relay

---

## Qué se Revisó en Este Escaneo

- `public/registro.php` — flujo completo de inscripción, validaciones, correos
- `admin/crear_evento.php` — creación de eventos con módulos y tipo presencial
- `correo/enviar_correo.php` — clase CorreoDenuncia, ambos métodos de envío
- `db/conexion.php` + `db/conexion.local.php` — estrategia de conexión dual
- `config/url.php` — helper base_url
- `admin/_auth.php` + `admin/helpers/audit.php` — autenticación, roles, auditoría
- `admin/login.php`, `admin/eventos.php`, `admin/inscritos.php` (parcial)
- `admin/dashboard.php` (parcial)
- `.gitignore`, `composer.json`, `package.json`
- Estructura de carpetas: `docs/`, `uploads/`, `storage/`

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
- **Problema**: `db/conexion.php` tiene credenciales de BD hardcodeadas y NO está en `.gitignore`
- **Problema**: `correo/enviar_correo.php` tiene app password SMTP hardcodeado
- **Solución sugerida**:
  - Crear un archivo `config/secrets.php` (ignorado en `.gitignore`)
  - O usar variables de entorno del servidor (`.htaccess` / PHP-FPM pool)
  - Referenciar desde los archivos PHP sin exponer valores
- **Rama sugerida**: `fix/credenciales-seguras`

#### 2. Agregar `uploads/` y `storage/` al `.gitignore`
- Actualmente no están ignorados: podrían commitearse soportes de pago o firmas
- Agregar al `.gitignore`:
  ```
  /uploads/eventos/
  /uploads/soportes/
  /uploads/firmas/
  /storage/mail_debug/
  ```
- **Rama sugerida**: `fix/gitignore-uploads`

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

#### 8. Limpiar archivos backup
- `admin/crear_evento_FUNCIONA.php` y `public/registro_FUNCIONA.php` son copias de seguridad
- No deberían estar en el repositorio principal
- Antes de eliminar: confirmar que el código activo es más reciente y funcional

#### 9. Agregar validación de MIME type real en subida de archivos
- Actualmente solo se valida por extensión
- Agregar `mime_content_type()` o Fileinfo para validación real

#### 10. Documentar el SQL de creación de tablas
- No se encontró ningún archivo `.sql` de estructura en el repositorio
- Crear `docs/schema.sql` con el DDL de todas las tablas

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
- El campo `tipo_evento_presencial` es reciente: verificar que columna exista en BD antes de asumir que funciona
- `norm_modalidad()` es el punto de verdad para comparar modalidades: siempre usarlo
- Los correos tienen un modo debug (`$MAIL_DEBUG = true` en registro.php) que guarda HTML sin enviar
- La firma del comercial viene de `usuarios.firma_path` y se intenta embeber como CID antes de usar URL
- El lugar por defecto es "Centro de Convenciones Cafam Floresta / Av. Cra. 68 No. 90-88" — se incluye la guía hotelera PDF si se usa este lugar
