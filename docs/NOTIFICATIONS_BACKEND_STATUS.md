# RehabiAnex - estado backend de notificaciones

Actualizado: 2026-09-30.

Estado: contrato FCM personalizado implementado localmente; pendiente de
validacion Android/E2E. No se ha hecho push, despliegue ni envio real.

## Implementado

- Allowlist cerrada de 18 tipos, incluidos `consent_suspended` y
  `supervisor_validation_approved`.
- Mensajes `data-only`, individuales, con textos canonicos y payload cerrado.
- Tokens activos en Firestore `fcm_tokens`; tokens invalidos se desactivan.
- Dedupe persistente en Firestore `notification_dispatches` mediante ID
  `sha256(dedupe_key)`.
- Disparadores reales de supervision, desvinculacion, consentimiento,
  intervencion, alertas y validacion de supervisor.
- Recordatorios de agenda y check-in mediante
  `php artisan notifications:dispatch-due`, sin depender de `delay()`.
- `/api/notifications/test` permanece exclusivamente para QA/demo y conserva
  `throttle:3,1`; los eventos reales no pasan por esa ruta.

## Reservados o pendientes de dominio

- `sober_day_update`: reservado hasta tener un hito real persistido.
- `achievement_unlocked`: pendiente de una creacion real de logro del paciente.
- `vulnerable_user_priority`: reservado hasta tener un evento propio.
- `user_validation_required`: reservado; no existe flujo real de usuario
  pendiente.
- `supervision_request_conflict`: reservado; no existe entidad de conflicto.
- `system_notice`: solo compatibilidad, no reemplaza tipos especificos.

## Brechas conocidas

- Agenda usa actualmente `starts_at` como slot vencido. Un recordatorio
  anticipado configurable requiere persistir la hora u offset correspondiente.
- Android debe confirmar allowlist, textos, `/consents/{id}`, foreground,
  background, terminated, tap seguro y dedupe en la prueba E2E.
- La ejecucion periodica del comando debe configurarse en la infraestructura
  existente antes de produccion real.

Cerrar la app no revoca el token; logout si lo revoca. Un force-stop de Android
puede pausar la recepcion aunque el backend conserve el token activo.
