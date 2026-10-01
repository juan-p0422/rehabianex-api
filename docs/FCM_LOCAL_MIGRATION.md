# FCM personalizado: contrato backend

## Transporte

FCM es `data-only`, sin topics ni multicast, con entrega individual a cada
token Android activo almacenado en Firestore `fcm_tokens`. El payload cerrado
es:

```json
{
  "type": "appointment_reminder",
  "title": "Rehabianex",
  "body": "Tienes un evento de agenda próximo.",
  "route": "/agenda-events/id-opaco",
  "entity_id": "id-opaco",
  "notification_id": "id-notificacion-opaco",
  "created_at": "ISO-8601",
  "priority": "normal"
}
```

Los textos exactos se definen en `FcmSafeTexts`; no se acepta texto arbitrario
del frontend. Nombres, alias, correo, telefono, UID visible, sustancia,
diagnostico, notas, texto libre, recaida detallada, ansiedad, craving y
ubicacion estan prohibidos.

| Tipo | Texto visible canonico |
|---|---|
| `appointment_reminder` | Tienes un evento de agenda próximo. |
| `progress_checkin` | Es momento de registrar tu seguimiento. |
| `achievement_unlocked` | Has alcanzado un nuevo logro. |
| `sober_day_update` | Has alcanzado un nuevo avance en tu proceso. |
| `intervention_update` | Tu plan de apoyo tiene una actualización. |
| `supervision_response` | Tu solicitud de acompañamiento fue actualizada. |
| `supervision_request` | Tienes una nueva solicitud de acompañamiento. |
| `unlink_request` | Tienes una solicitud de cambio de consentimiento. |
| `consent_suspended` | Se actualizó el estado de un consentimiento. |
| `risk_alert` | Hay una actualización importante de seguimiento. |
| `relapse_alert` | Hay una actualización prioritaria de seguimiento. |
| `vulnerable_user_priority` | Hay una actualización importante de seguimiento. |
| `supervisor_validation_approved` | Tu cuenta de supervisor fue aprobada. |
| `supervisor_validation_required` | Hay una cuenta de supervisor pendiente de validación. |
| `user_validation_required` | Hay una cuenta pendiente de validación. |
| `supervision_request_conflict` | Hay una solicitud de supervisión que requiere revisión. |
| `system_notice` | Tienes una actualización en Rehabianex. |
| `test_notification` | Tienes una actualización en Rehabianex. |

## Recordatorios programados

`php artisan notifications:dispatch-due` consulta Firestore y procesa:

- eventos de agenda programados y vencidos cuya configuracion habilita
  recordatorios;
- el slot diario de check-in/nota habilitado, usando la zona horaria guardada.

El scheduler de Laravel lo invoca cada minuto con `withoutOverlapping`. No usa
`delay()`, tabla SQL de jobs ni cache SQL. Actualmente el slot de agenda es
`starts_at`; un aviso anticipado configurable requerira un campo persistido de
hora/offset y permanece como brecha explicita.

## Ledger de deduplicacion

Cada envio reserva primero
`notification_dispatches/{sha256(dedupe_key)}` con:

- `type`;
- `notification_id` opaco;
- `status`: `claimed`, `sent` o `failed`;
- `claimed_at`, `sent_at` y `attempts`.

No guarda la clave logica, tokens, UID, identidad, texto libre ni datos
clinicos. `claimed` y `sent` bloquean un replay exacto. Un fallo se marca
`failed` y permite un reintento posterior; un evento distinto produce otro
hash. Esto hace idempotente el comando y los disparadores HTTP.

## Estado de los 18 tipos

| Tipo | Ruta | Estado |
|---|---|---|
| `appointment_reminder` | `/agenda-events/{id}` | Funcional: comando de vencidos |
| `progress_checkin` | `/checkins/new` | Funcional: comando de vencidos |
| `sober_day_update` | `/home` | Reservado |
| `achievement_unlocked` | `/achievements/{id}` | Pendiente de dominio real |
| `supervision_request` | `/supervision-requests/{id}` | Funcional |
| `supervision_response` | `/supervision-requests/{id}` | Funcional |
| `unlink_request` | `/unlink-requests/{id}` | Funcional |
| `consent_suspended` | `/consents/{id}` | Funcional: `active -> paused/suspended` |
| `intervention_update` | `/interventions/{id}` | Funcional |
| `relapse_alert` | `/patients/priority` | Funcional bajo flag clinico |
| `risk_alert` | `/patients/priority` | Funcional bajo flag clinico |
| `vulnerable_user_priority` | `/patients/priority` | Reservado |
| `supervisor_validation_approved` | `/home` | Funcional |
| `supervisor_validation_required` | `/admin/supervisors/pending/{id}` | Funcional bajo flag admin |
| `user_validation_required` | `/admin/users/pending/{id}` | Reservado |
| `supervision_request_conflict` | `/admin/supervision-conflicts/{id}` | Reservado |
| `system_notice` | Rutas seguras compatibles | Compatibilidad |
| `test_notification` | `/home` o vacia | QA/demo |

`POST /api/notifications/test` conserva `throttle:3,1` y no acepta payload
arbitrario. Ningun evento funcional pasa por ese endpoint, por lo que cuatro o
mas eventos reales distintos pueden emitirse; solo el duplicado exacto se
bloquea mediante ledger.

## Persistencia

Cerrar la app no revoca el token. Logout si lo revoca. El backend intenta
entregar a todos los tokens activos del destinatario y desactiva los tokens
invalidos reportados por Firebase. La recepcion con la app en background o
terminated depende de Firebase/Android; force-stop puede pausarla.

Este contrato no autoriza push, despliegue, cambios de variables ni envios FCM
reales.
