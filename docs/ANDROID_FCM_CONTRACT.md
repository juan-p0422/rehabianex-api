# Contrato Android <-> FCM

Estado: contrato backend implementado localmente; pendiente de validacion Android/E2E.
Este documento no autoriza push, despliegue ni cambios en Render.

## Transporte y privacidad

- Mensajes `data-only`, enviados individualmente a cada token activo.
- Sin bloque `notification`, topics ni multicast.
- Titulo canonico: `Rehabianex`. Android usa el mismo catalogo local de cuerpos.
- Payload limitado a `type`, `title`, `body`, `route`, `entity_id`,
  `notification_id`, `created_at` y `priority`.
- No se transportan nombres, alias, correo, telefono, UID visible, sustancia,
  diagnostico, nota o texto libre, detalles clinicos ni ubicacion.
- `entity_id` y `notification_id` son referencias opacas. El detalle solo se
  obtiene dentro de una pantalla autenticada y autorizada.

## Allowlist y navegacion

| Tipo | Rol receptor | Ruta oficial | Estado backend |
|---|---|---|---|
| `appointment_reminder` | Paciente | `/agenda-events/{id}` | Funcional mediante `notifications:dispatch-due` |
| `progress_checkin` | Paciente | `/checkins/new` | Funcional mediante `notifications:dispatch-due` |
| `sober_day_update` | Paciente | `/home` | Reservado; sin hito persistido |
| `achievement_unlocked` | Paciente | `/achievements/{id}` | Pendiente de creacion real del logro |
| `supervision_request` | Supervisor | `/supervision-requests/{id}` | Funcional |
| `supervision_response` | Paciente | `/supervision-requests/{id}` | Funcional |
| `unlink_request` | Supervisor | `/unlink-requests/{id}` | Funcional |
| `consent_suspended` | Supervisor | `/consents/{id}` | Funcional para `active -> paused/suspended` |
| `intervention_update` | Paciente | `/interventions/{id}` | Funcional |
| `relapse_alert` | Supervisor | `/patients/priority` | Funcional bajo autorizacion y flag |
| `risk_alert` | Supervisor | `/patients/priority` | Funcional bajo autorizacion y flag |
| `vulnerable_user_priority` | Supervisor | `/patients/priority` | Reservado; sin evento propio |
| `supervisor_validation_approved` | Supervisor | `/home` | Funcional |
| `supervisor_validation_required` | Admin | `/admin/supervisors/pending/{id}` | Funcional bajo flag admin |
| `user_validation_required` | Admin | `/admin/users/pending/{id}` | Reservado; sin flujo real |
| `supervision_request_conflict` | Admin | `/admin/supervision-conflicts/{id}` | Reservado; sin entidad real |
| `system_notice` | Segun contexto autorizado | Rutas seguras compatibles | Solo compatibilidad |
| `test_notification` | Usuario QA autenticado | `/home` o vacia | Solo QA/demo |

Android debe incluir exactamente estos 18 tipos en su allowlist, aplicar el
texto canonico local, validar tipo/ruta/rol y usar un fallback seguro si falta
sesion, permiso o pantalla. En particular, `/consents/{id}` debe mapearse a una
pantalla segura antes de la prueba E2E; nunca debe abrir un detalle sin volver a
validar autorizacion.

## Ciclo del token y app cerrada

1. Tras login se registra con `POST /api/notifications/fcm-token`.
2. `onNewToken` solo registra con sesion vigente.
3. Cerrar o terminar normalmente la app no revoca el token.
4. Antes de logout se revoca con `DELETE /api/notifications/fcm-token`.
5. El backend envia a tokens activos e invalida los que Firebase reporte como
   no registrados.

Firebase/Android decide la entrega en background o terminated. Un force-stop
puede suspenderla hasta que el usuario vuelva a abrir la app.

## Dedupe y limites

El backend reserva `sha256(dedupe_key)` en Firestore
`notification_dispatches` antes de enviar. Android tambien deduplica por
`notification_id`. El limite `throttle:3,1` pertenece exclusivamente a
`POST /api/notifications/test`; los eventos reales no usan ese endpoint.
