# Despliegue y validación FCM del backend en Render

## Objetivo

Esta guía prepara una fase futura de despliegue del backend Laravel en Render y
su validación con un cliente Android técnico. No autoriza push, despliegue ni
habilitación de producción durante el cierre local.

No se crea otro servicio ni otro ambiente. Cualquier ventana de prueba real
debe seguir el procedimiento operativo ya aprobado y restaurar inmediatamente
la configuracion segura al terminar o ante un fallo.

## Variables necesarias

Configurar mediante secretos y variables del entorno de Render, nunca en Git:

- `APP_ENV`: `staging` durante la validación controlada.
- `APP_KEY`: secreto propio del servicio.
- `QUEUE_CONNECTION=sync` para el smoke inicial. Los jobs se ejecutan dentro
  del proceso que origina el disparador y no requieren la tabla `jobs`.
- `CACHE_STORE=file` para los locks de `ShouldBeUnique` en una única instancia
  de smoke, sin requerir la tabla `cache`.
- `FIREBASE_PROJECT_ID`: identificador técnico del proyecto Firebase.
- Credenciales Firebase mediante una sola estrategia segura:
  - Secret File de Render y `FIREBASE_CREDENTIALS` apuntando a su ruta; o
  - `FIREBASE_CREDENTIALS_BASE64` como secreto de entorno.
- `FCM_ENABLED`, `FCM_DRY_RUN` y los flags FCM descritos abajo.
- `FCM_DEFAULT_CHANNEL_ID=rehabianex_reminders`.
- `FCM_DEFAULT_ANDROID_PRIORITY=high`.
- `FCM_SAFE_PUBLIC_TITLE=Rehabianex`.
- `FCM_SAFE_PUBLIC_BODY="Tienes una actualización en Rehabianex."`.
- `FCM_OFFLINE_FALLBACK_ALLOWED=true`.

`FCM_OFFLINE_FALLBACK_ALLOWED` documenta la política del cliente Android; no
autoriza al backend a crear notificaciones locales ni reemplaza las reglas de
privacidad del transporte FCM.

No definir credenciales reales en `.env.example`, comandos, capturas, tickets o
logs. No usar simultáneamente dos fuentes de credenciales.

Los tokens se almacenan en la colección Firestore `fcm_tokens`; con cola
`sync` y cache `file`, FCM no requiere `DB_*`, PostgreSQL, un archivo SQLite
persistente, las tablas `jobs` o `cache`, ni ejecutar la migración legada
`user_fcm_tokens` en Render. Otros subsistemas Laravel deben evaluar sus propias
necesidades de persistencia por separado.

## Configuración segura inicial

El primer despliegue debe iniciar sin entregas reales:

```dotenv
FCM_ENABLED=false
FCM_DRY_RUN=true
QUEUE_CONNECTION=sync
CACHE_STORE=file
FCM_CRITICAL_ALERTS_ENABLED=false
FCM_ADMIN_NOTIFICATIONS_ENABLED=false
FCM_SUPERVISOR_CLINICAL_ALERTS_ENABLED=false
FCM_VULNERABLE_PRIORITY_ENABLED=false
FCM_OFFLINE_FALLBACK_ALLOWED=true
```

Después de guardar variables se debe limpiar y reconstruir la caché de
configuración. La aplicación debe responder health checks y las rutas FCM deben
seguir protegidas antes de habilitar cualquier envío.

## Configuración para smoke real en staging

Usar un solo dispositivo y una cuenta técnica sin información real:

```dotenv
FCM_ENABLED=true
FCM_DRY_RUN=false
QUEUE_CONNECTION=sync
CACHE_STORE=file
FCM_CRITICAL_ALERTS_ENABLED=false
FCM_ADMIN_NOTIFICATIONS_ENABLED=false
FCM_SUPERVISOR_CLINICAL_ALERTS_ENABLED=false
FCM_VULNERABLE_PRIORITY_ENABLED=false
```

El endpoint `POST /api/notifications/test` no acepta payload arbitrario, exige
autenticación y solo permite entrega real en entornos `local` o `staging`. El
smoke debe confirmar un único mensaje `data-only`, texto genérico, destino
seguro y ausencia de token completo en logs.

Con `QUEUE_CONNECTION=sync`, `SendFcmNotification` y los disparadores directos
se ejecutan inmediatamente en el proceso que origina el evento. Los
recordatorios futuros no dependen de `delay()`: el scheduler ejecuta cada
minuto `php artisan notifications:dispatch-due`. El comando consulta Firestore
y el ledger `notification_dispatches` bloquea replays exactos.

Los flags clínicos, administrativos o de vulnerabilidad se habilitan de uno en
uno únicamente durante casos E2E autorizados. Deben volver a `false` al terminar
la ventana de prueba.

## Comandos de validación

Antes del push, en local:

```bash
php artisan config:clear
php artisan route:list --path=api/notifications -v
php artisan schedule:list
php artisan test --filter=Fcm
php artisan test
composer validate
composer audit --locked
git diff --check
```

En el servicio de staging, sin imprimir el entorno:

```bash
php artisan config:clear
php artisan config:cache
php artisan route:list --path=api/notifications -v
```

No se deben ejecutar migraciones SQL para FCM, comandos destructivos, seeders
de demostración ni tareas que copien usuarios reales.

Render debe invocar `php artisan notifications:dispatch-due` cada minuto para
recordatorios programados. El ledger Firestore proporciona dedupe persistente y
no depende de un lock por IP ni de tablas SQL.

## Scheduler sin costo adicional para la tesina

### Restricción económica identificada

El Cron Job administrado de Render es la solución nativa preferible para una
operación productiva con garantía independiente del Web Service. Sin embargo,
al momento de esta implementación Render no ofrece un plan gratuito para Cron
Jobs. El Dashboard mostró como plan mínimo `0.5 CPU / 512 MB RAM` con una tarifa
de `$0.00016 por minuto de ejecución`. La tarifa es pequeña por invocación, pero
requiere habilitar facturación y crear un segundo servicio. Este requisito se
consideró un límite económico para un prototipo académico y no una deficiencia
funcional del módulo FCM.

Tampoco es suficiente ejecutar `php artisan schedule:work` dentro del Web
Service gratuito. Render suspende una instancia gratuita después de 15 minutos
sin tráfico HTTP o WebSocket entrante. Cuando la instancia se suspende, el
proceso del scheduler también se detiene y los recordatorios dejan de respetar
su periodicidad. La limitación está documentada por Render en:
<https://render.com/docs/free>.

### Alternativa seleccionada

Para QA, demostración y alcance de tesina se usa GitHub Actions como disparador
externo gratuito y el procesamiento continúa dentro del backend de Render:

1. `.github/workflows/dispatch-due-notifications.yml` se programa cada cinco
   minutos, el intervalo mínimo admitido por GitHub Actions.
2. El workflow despierta el Web Service mediante
   `POST /api/internal/notifications/dispatch-due`.
3. El endpoint no acepta payload y ejecuta el mismo
   `DueNotificationDispatcher` utilizado por el comando Artisan.
4. La respuesta contiene únicamente conteos de candidatos de agenda y
   check-in; no contiene UID, tokens, JWT, credenciales ni datos clínicos.
5. Cada solicitud se autentica con HMAC-SHA256 sobre timestamp, método y ruta.
   Se rechazan firmas inválidas y timestamps fuera de una ventana de cinco
   minutos para reducir replay.
6. Firebase permanece exclusivamente en Render. GitHub recibe solo un secreto
   técnico dedicado al scheduler y nunca recibe credenciales Firebase.

GitHub documenta que el intervalo mínimo de los workflows programados es cinco
minutos y que pueden sufrir retrasos en periodos de alta carga:
<https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax>.
Los minutos son gratuitos para repositorios públicos. En repositorios privados
se consumen los minutos incluidos por el plan y podría existir costo al superar
esa cuota:
<https://docs.github.com/en/billing/concepts/product-billing/github-actions>.

Por estas razones, la solución es adecuada para una tesina y una demostración,
pero no constituye una garantía de ejecución en tiempo real. Una instalación
productiva con SLA debe migrar a Render Cron Job, un worker administrado o un
scheduler equivalente con disponibilidad contratada.

### Configuración segura

Crear el mismo secreto aleatorio de al menos 32 caracteres en ambos destinos,
sin copiarlo a archivos versionados ni logs:

- Render Web Service: `FCM_SCHEDULER_SECRET`.
- GitHub Actions Secret: `FCM_SCHEDULER_SECRET`.
- GitHub Actions Variable: `NOTIFICATIONS_SCHEDULER_ENABLED=true` únicamente
  después de desplegar el endpoint y configurar el secreto en ambos lados.

El workflow queda deshabilitado por defecto mientras la variable no tenga el
valor exacto `true`; de este modo no consume minutos ni genera ejecuciones
fallidas durante la preparación.

La ruta no usa `/api/notifications/test`, no acepta tipos FCM ni destinatarios,
no está sujeta al límite `3/min` de pruebas y mantiene un límite defensivo
independiente de `12/min`. Los eventos reales se deduplican en Firestore por el
ledger `notification_dispatches`.

Con `FCM_DRY_RUN=true`, el dispatcher de vencidos no consulta candidatos ni
reserva entradas en el ledger. Esto evita que una validación sin entrega marque
un recordatorio como enviado antes de abrir una ventana real. La entrega solo
se procesa cuando `FCM_ENABLED=true` y `FCM_DRY_RUN=false`; los flags clínicos y
administrativos continúan siendo independientes y permanecen apagados.

### Activación y rollback

Orden de activación:

1. Desplegar el backend con el endpoint protegido.
2. Configurar `FCM_SCHEDULER_SECRET` en Render y reconstruir el servicio.
3. Configurar el mismo valor como Secret de GitHub Actions.
4. Crear `NOTIFICATIONS_SCHEDULER_ENABLED=true` como Variable del repositorio.
5. Ejecutar primero `workflow_dispatch` y confirmar una respuesta de conteos.
6. Mantener `APP_ENV=production` y `FCM_DRY_RUN=true` fuera de una ventana E2E.

Rollback sin borrar datos:

1. Establecer `NOTIFICATIONS_SCHEDULER_ENABLED=false` o eliminar la variable.
2. Mantener `FCM_DRY_RUN=true` y los cuatro flags sensibles en `false`.
3. Rotar `FCM_SCHEDULER_SECRET` si existe sospecha de exposición.
4. Conservar el ledger y los documentos históricos para auditoría técnica.

La validación funcional incluye registro y revocación del token, smoke en
foreground y background, rutas seguras, privacidad visible y consulta de logs
sanitizados. No se debe copiar el token a la consola para demostrar la prueba.

## Rollback

Ante fallos de transporte, privacidad, destinatario o navegación:

1. Establecer `FCM_ENABLED=false`.
2. Establecer `FCM_DRY_RUN=true`.
3. Deshabilitar los cuatro flags sensibles.
4. Limpiar/reconstruir la configuración y reiniciar web service y worker.
5. Confirmar que no se crean nuevos jobs FCM y detener el worker si fuera
   necesario.
6. Revocar los tokens técnicos usados en la prueba.
7. Revertir al último release backend conocido como estable si el problema no
   es solo de configuración.
8. Conservar evidencia sanitizada: tipo, `notification_id`, estado y hora, sin
   tokens, identidad ni contenido clínico.

El fallback local Android básico no debe modificarse durante el rollback del
backend.

## Usuarios técnicos

- Usar cuentas sintéticas separadas para paciente, supervisor y administrador.
- No reutilizar usuarios reales ni copiar sus datos.
- Registrar solamente el dispositivo Android destinado a QA.
- Mantener relaciones y consentimientos mínimos para el caso probado.
- Revocar el token antes de logout y al terminar la ventana de pruebas.
- No escribir correos, contraseñas, UID, nombres ni alias en este documento o
  en evidencias compartidas.

## Criterio de avance

El despliegue solo avanza de staging a producción cuando el backend supera sus
checks, Android confirma recepción y navegación segura, los logs permanecen
limpios y los flags quedan en el estado aprobado. Hasta entonces la producción
FCM no se considera finalizada.

El commit local del backend no constituye autorización de push o despliegue.
Cada transición —push, despliegue de staging y habilitación temporal de FCM—
requiere su propio checklist y autorización.
