# Contrato local Android ↔ FCM

Estado: **consolidación conjunta local aprobada para hardening pre-commit;
validación remota Render queda pendiente para fase posterior**.

Render no se usa ni se prueba en esta fase. Este contrato no autoriza push,
merge a `main`, despliegue, cambio de `baseUrl` ni habilitación de producción.

## Transporte y privacidad

- Todos los mensajes son `data-only`; no se admite bloque `notification`.
- No se usan topics ni multicast. Cada entrega corresponde a un usuario y sus
  tokens Android activos.
- El título visible es `Rehabianex` y el cuerpo procede del catálogo seguro.
- No se muestran ni transportan nombre, alias, correo, teléfono, UID, ubicación,
  texto libre, notas, diagnóstico ni datos clínicos.
- `entity_id` es técnico u opaco. El detalle se obtiene solo en una pantalla
  autenticada después de validar sesión, rol y permisos.
- Android debe ignorar campos no permitidos aunque lleguen por error.

## Ciclo del token

1. Tras un login válido, Android registra el token con
   `POST /api/notifications/fcm-token`.
2. `onNewToken` solo registra si existe una sesión autenticada vigente.
3. Antes del logout, Android revoca el token con
   `DELETE /api/notifications/fcm-token`.
4. Tras cambiar de cuenta o rol, Android registra el token para la nueva sesión
   sin requerir reinicio de la aplicación.
5. Los reintentos usan backoff y deben cancelarse cuando desaparece la sesión,
   para impedir tokens zombie.

El backend toma `user_id` exclusivamente de la autenticación; el cliente no
puede enviar `user_id`, rol, topic, privilegios ni payload arbitrario.

## Tipos con disparador real

| Tipo | Destinatario | Ruta oficial |
|---|---|---|
| `appointment_reminder` | Paciente propietario | `/agenda-events/{id}` |
| `supervision_request` | Supervisor autorizado | `/supervision-requests/{id}` |
| `supervision_response` | Paciente propietario | `/supervision-requests/{id}` |
| `unlink_request` | Supervisor autorizado | `/unlink-requests/{id}` |
| `intervention_update` | Paciente propietario | `/interventions/{id}` |
| `relapse_alert` | Supervisor autorizado | `/patients/priority` |
| `risk_alert` | Supervisor autorizado | `/patients/priority` |
| `supervisor_validation_required` | Administrador autorizado | `/admin/supervisors/pending/{opaque}` |

`intervention_update` se dirige actualmente al paciente propietario. Una
notificación al supervisor queda fuera del alcance salvo una regla futura.

## Contrato preparado sin evento de dominio

Para los siguientes tipos aplica exactamente este estado: **Contrato y
transporte preparados; no habilitar funcionalmente hasta evento de dominio
real.**

- `progress_checkin`
- `sober_day_update`
- `achievement_unlocked`
- `vulnerable_user_priority`
- `user_validation_required`
- `supervision_request_conflict`

`system_notice` es compatibilidad y transporte genérico. No debe ser el emisor
principal de un flujo nuevo cuando exista un tipo específico.
`test_notification` se limita a QA autenticado en local o staging.

## Navegación y fallback

- Tipo, ruta y `entity_id` deben coincidir; en caso contrario Android descarta
  el mensaje o abre un fallback seguro según sesión y rol.
- Los identificadores `critical_followup` y `priority_followup` abren la lista
  segura de pacientes; nunca se interpretan como UID de paciente.
- Un tipo desconocido, rol incorrecto o ruta inválida no debe abrir detalles.
- `notification_id` se usa para deduplicar replays y coincidencias entre agenda
  local y FCM.

Con internet, FCM es la fuente principal. Sin internet, el fallback local se
limita a agenda cacheada, check-in básico y recordatorios motivacionales simples
no sensibles. No se generan localmente alertas de riesgo/recaída, solicitudes,
validaciones administrativas, intervenciones clínicas o prioridad vulnerable.

## Resumen local del supervisor

El resumen local del asistente de supervisor está fuera de FCM y marcado para
revisión de privacidad y criterio clínico. No puede mostrarse en una
notificación, no reemplaza alertas FCM clínicas y no debe exponer identidad ni
datos clínicos visibles.

## Configuración segura de cierre local

```text
FCM_ENABLED=false
FCM_DRY_RUN=true
QUEUE_CONNECTION=sync
FCM_CRITICAL_ALERTS_ENABLED=false
FCM_ADMIN_NOTIFICATIONS_ENABLED=false
FCM_SUPERVISOR_CLINICAL_ALERTS_ENABLED=false
FCM_VULNERABLE_PRIORITY_ENABLED=false
```
