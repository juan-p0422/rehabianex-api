# RehabiAnex — estado backend de notificaciones

Actualizado: 2026-09-27.

Estado: **consolidación conjunta local aprobada para hardening pre-commit;
validación remota Render queda pendiente para fase posterior**.

Este documento reemplaza la auditoría histórica de agosto de 2026 que describía
FCM como no implementado. El contrato detallado y la clasificación de
disparadores están en `docs/FCM_LOCAL_MIGRATION.md`.

## Estado vigente

- Laravel registra y revoca tokens Android autenticados mediante
  `POST` y `DELETE /api/notifications/fcm-token`.
- Los mensajes FCM son exclusivamente `data-only`, individuales y con texto
  visible genérico.
- No se usan bloque `notification`, topics ni multicast.
- Los jobs y dispatchers reevalúan destinatario, token activo, autorización,
  consentimiento y flags aplicables.
- Android controla permiso, ciclo de token, navegación segura, deduplicación y
  fallback local básico no sensible.
- FCM es la fuente principal con conectividad; los recordatorios locales no
  sustituyen alertas clínicas, administrativas o de supervisión.

## Tipos con disparador real

- `appointment_reminder`
- `supervision_request`
- `supervision_response`
- `unlink_request`
- `intervention_update`, dirigido actualmente al paciente propietario
- `relapse_alert`
- `risk_alert`
- `supervisor_validation_required`

Una notificación de intervención para el supervisor queda fuera del alcance
actual salvo que se defina una regla futura específica.

## Contrato preparado sin evento de dominio

Los siguientes tipos tienen contrato y transporte preparados, pero **no deben
habilitarse funcionalmente hasta que exista un evento de dominio real**:

- `progress_checkin`
- `sober_day_update`
- `achievement_unlocked`
- `vulnerable_user_priority`
- `user_validation_required`
- `supervision_request_conflict`

`system_notice` es compatibilidad y transporte genérico. No debe actuar como
emisor principal de un flujo nuevo cuando exista un tipo específico.
`test_notification` es exclusivamente una herramienta local de QA.

## Privacidad e invariantes

Los payloads y textos visibles no incluyen nombre, alias, correo, teléfono,
UID, notas, diagnóstico, ubicación, texto libre ni datos clínicos. El
`entity_id` es opaco y el detalle se obtiene únicamente en una pantalla
autenticada después de validar sesión, rol y permisos.

Los tokens completos y las credenciales no se imprimen ni se devuelven en
respuestas. Los tokens inválidos pueden desactivarse sin exponer su valor.

## Estado seguro y alcance local

Los valores documentales seguros son `FCM_ENABLED=false`, `FCM_DRY_RUN=true` y
los flags clínicos, administrativos y de vulnerabilidad desactivados. El
fallback offline permitido se limita a recordatorios básicos no sensibles.

Render queda como fase futura. En este cierre no se usa Render, no se cambia la
`baseUrl`, no se hace push, no se despliega y no se habilita producción.

## Límite con el resumen local del supervisor

El resumen local del asistente para supervisores no forma parte de FCM. Está
marcado para revisión porque sus agregados pueden interpretarse como seguimiento
clínico. No se utiliza como notificación, no sustituye las alertas clínicas
tipadas y no puede incorporarse al payload visible o `data` de FCM.
