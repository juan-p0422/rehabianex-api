# RehabiAnex — estado backend de notificaciones

Fecha de auditoría inicial: 2026-08-01.  
Revalidación documental y de código: 2026-08-05.  
Estado: **FCM no implementado**.

> El prototipo implementa configuración remota de preferencias y recordatorios
> locales en Android.
>
> FCM queda preparado como extensión futura.

## Declaración contractual vigente

En la versión actual:

1. el backend no integra Firebase Cloud Messaging;
2. no recibe, almacena, rota ni elimina tokens FCM de dispositivos;
3. no envía notificaciones push remotas;
4. no existen jobs, listeners ni scheduler de entrega push;
5. `notification-settings` sólo sincroniza preferencias del paciente;
6. Android programa y muestra las notificaciones locales;
7. Android solicita y administra el permiso de notificaciones del sistema.

Los campos `craving_alerts_enabled`, `supervisor_alerts_enabled` y similares no
implican que exista un canal remoto. Son preferencias preparatorias y sólo
tienen efecto cuando Android implementa el recordatorio local correspondiente.

## Evidencia revisada

- `FirebaseService` crea únicamente clientes de Firebase Auth y Firestore; no
  crea ni expone un cliente de Firebase Cloud Messaging.
- La dependencia `kreait/firebase-php` podría soportar Messaging, pero tener la
  librería instalada no constituye una integración FCM.
- No existen modelos, colecciones ni campos para tokens FCM de dispositivos.
- No existe endpoint para registrar, rotar, desactivar o eliminar tokens FCM.
- No existen Jobs, Notifications, Events o Listeners para envío push.
- `php artisan schedule:list` informa que no hay tareas programadas.
- `QUEUE_CONNECTION=database` aparece como configuración general de Laravel,
  pero no hay jobs de notificaciones que la utilicen.
- No hay variables `FCM_*` o `FIREBASE_MESSAGING_*` en el contrato de entorno.
- No hay pruebas de entrega push, invalidación de tokens ni reintentos FCM.

## Estado por capacidad

| Capacidad | Estado real |
|---|---|
| Firebase Auth | Implementado |
| Firestore | Implementado |
| Cliente Firebase Messaging en backend | No implementado |
| Almacenamiento de tokens FCM | No implementado |
| Registro/actualización de token | No existe |
| Eliminación de token al cerrar sesión | No existe |
| Jobs de envío push | No existen |
| Scheduler de recordatorios | No existe |
| Reintentos y dead-letter de push | No existen |
| Preferencias remotas del paciente | Implementadas |
| Preferencias remotas del supervisor | No implementadas; locales en Android |
| Recordatorios locales Android | Responsabilidad del cliente Android |

## Endpoints existentes

Los únicos endpoints backend relacionados son:

```http
GET    /api/notification-settings
POST   /api/notification-settings
GET    /api/notification-settings/{id}
PUT    /api/notification-settings/{id}
PATCH  /api/notification-settings/{id}
DELETE /api/notification-settings/{id}
```

Todos requieren token Firebase y están restringidos al paciente propietario.
El contrato recomendado para Android utiliza principalmente GET, POST
idempotente y PATCH.

`notification-settings` almacena preferencias y horarios. No registra una
notificación, no crea un job y no invoca FCM.

## Permiso de notificaciones Android

El permiso de ejecución para mostrar notificaciones es responsabilidad exclusiva
del cliente Android. En Android 13 o superior esto incluye solicitar
`POST_NOTIFICATIONS` en runtime; en versiones anteriores incluye crear y
administrar los canales de notificación según las APIs disponibles.

El backend:

- no puede conceder, revocar ni consultar ese permiso del dispositivo;
- no debe recibir como requisito el resultado del diálogo de permiso;
- no necesita un endpoint nuevo cuando cambia este permiso;
- puede conservar preferencias funcionales en `notification-settings`, pero una
  preferencia activa no significa que Android tenga permiso del sistema;
- no garantiza que un recordatorio local sea mostrado.

Si el usuario rechaza o revoca el permiso, Android debe mantener la aplicación
funcional, reflejar el estado localmente y evitar programar o mostrar avisos no
permitidos. Este cambio no modifica autenticación, Firestore ni el contrato de
sincronización de preferencias.

No existen actualmente:

```http
POST   /api/device-tokens
PATCH  /api/device-tokens/{id}
DELETE /api/device-tokens/{id}
POST   /api/notifications/test
```

Esos nombres son únicamente una propuesta futura, no un contrato disponible.

## Preferencias implementadas

El paciente puede almacenar:

```text
daily_check_in_enabled / daily_note_enabled
daily_check_in_time / daily_note_time
sober_day_enabled
sober_day_time
achievement_enabled
achievement_time
event_reminders_enabled
motivational_enabled
motivational_time
craving_alerts_enabled
supervisor_alerts_enabled
support_contact_alerts_enabled
timezone
```

Estas propiedades permiten a Android programar recordatorios locales. Los
campos con nombre `*_alerts_enabled` expresan intención/preferencia, pero el
backend todavía no tiene un emisor remoto que los haga efectivos.

El supervisor no tiene configuración remota en esta versión. Android debe
mantener sus preferencias localmente y no crear un documento
`notification_settings` usando el UID del supervisor.

## Eventos potenciales

| Evento | Implementación actual | Preparación futura recomendada |
|---|---|---|
| Recordatorio diario | Android local según preferencias | Scheduler por zona horaria solo si se requiere push remoto |
| Evento de agenda | Android local | Job diferido, reprogramable al editar/cancelar evento |
| Recaída | Se registra en nota; no notifica | Evento de dominio con consentimiento y scope específico |
| Craving alto | Se calcula/guarda; no notifica | Umbral explícito, consentimiento y deduplicación |
| Solicitud pendiente | Se guarda en Firestore; no notifica | Evento al crear solicitud para destinatario autorizado |
| Intervención | Se guarda; no notifica | Evento al crear/cambiar estado, sin contenido clínico en push |

Que un evento exista en Firestore no significa que dispare una notificación.

## Riesgos de privacidad

Una notificación push puede mostrarse en pantalla bloqueada, almacenarse por el
sistema operativo o atravesar infraestructura de terceros. Por ello un futuro
payload FCM no debe contener:

- nombres reales o UID;
- texto de notas;
- recaída, craving, diagnóstico o nivel de riesgo;
- teléfonos o contactos de apoyo;
- consentimiento o scopes;
- contenido del Chat IA;
- dirección o ubicación sensible;
- tokens de autenticación o token FCM de otro dispositivo.

El contenido recomendado debe ser genérico:

```json
{
  "notification": {
    "title": "RehabiAnex",
    "body": "Tienes una actualización pendiente. Abre la aplicación para verla."
  },
  "data": {
    "event_type": "agenda_updated",
    "resource_id": "identificador_opaco"
  }
}
```

La aplicación debe autenticarse y consultar el recurso para mostrar el detalle.
Incluso `event_type` debe minimizarse cuando pueda revelar información en la
pantalla bloqueada o en telemetría del dispositivo.

Otros riesgos pendientes:

- múltiples usuarios pueden compartir un dispositivo;
- tokens rotados o restaurados pueden quedar obsoletos;
- logout no elimina ningún token porque aún no existe registro de tokens;
- revocación de consentimiento debe cancelar jobs pendientes;
- un supervisor suspendido no debe seguir recibiendo pushes;
- reintentos sin idempotencia pueden producir notificaciones duplicadas;
- logs nunca deben incluir tokens FCM ni payloads completos.

## Extensión futura propuesta — no implementada

Antes de implementar FCM se recomienda aprobar un contrato separado que cubra:

1. colección privada `device_tokens` con propietario, plataforma, estado,
   timestamps y token cifrado en reposo;
2. endpoint idempotente de registro/rotación y endpoint de baja en logout;
3. cliente Messaging creado explícitamente desde `FirebaseService`;
4. jobs en cola con identificador de idempotencia, reintentos limitados y
   eliminación de tokens declarados inválidos por FCM;
5. scheduler con timezone para recordatorios remotos;
6. eventos de dominio después de confirmar la escritura Firestore;
7. reevaluación de rol, vinculación, autorización y consentimiento justo antes
   de enviar;
8. payload genérico y recuperación autenticada del detalle;
9. métricas sin PII y pruebas contra un proyecto Firebase controlado;
10. política de retención y borrado de tokens.

No se agregaron endpoints, colecciones, variables de entorno ni lógica de envío
en esta auditoría.
