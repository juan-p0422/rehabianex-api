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
