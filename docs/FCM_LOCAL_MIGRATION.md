# FCM Total Privada: cierre local backend y contrato de migración

## Estado y alcance

La fase local está aprobada para un commit acotado al repositorio backend
Laravel. El backend es el único componente versionado y el que posteriormente
se desplegará en Render. Android no tiene repositorio Git oficial en esta fase:
se usa como cliente local de validación y no requiere commit, push ni entrega
de artefactos dentro del repositorio backend.

Este cierre no acredita producción. Render, el cambio de `baseUrl` y las
pruebas remotas quedan para una fase posterior al commit y al push autorizados.

## Contrato de transporte

FCM es la fuente principal con conectividad. Todos los mensajes son
`data-only`; no se usa bloque `notification`, topics ni multicast. Cada entrega
se dirige individualmente a un token Android activo.

El payload contractual contiene únicamente:

```json
{
  "type": "appointment_reminder",
  "title": "Rehabianex",
  "body": "Tienes un recordatorio pendiente.",
  "route": "/agenda-events/id-opaco",
  "entity_id": "id-opaco",
  "notification_id": "id-notificacion-opaco",
  "created_at": "ISO-8601",
  "priority": "normal"
}
```

No se incluyen nombres, alias, correos, teléfonos, UID visibles, notas
clínicas, diagnóstico, ubicación, texto libre ni detalles de recaída, riesgo,
craving o ansiedad. El detalle se obtiene dentro de una pantalla autenticada,
después de validar sesión, rol y permisos.

## Ciclo de vida del token

1. Android obtiene el token de Firebase y solo intenta registrarlo cuando hay
   una sesión válida.
2. `POST /api/notifications/fcm-token` asocia el token al UID autenticado. El
   cliente no envía rol, privilegios ni UID en el payload.
3. Un login o cambio de rol vuelve a registrar el token, sin requerir reinicio
   de la aplicación. Un mismo dispositivo cambia de propietario activo de
   forma controlada.
4. `onNewToken` respeta la sesión vigente, el estado suspendido de registro y
   el backoff; no debe reactivar un token después del logout.
5. Antes de limpiar la sesión, Android invoca
   `DELETE /api/notifications/fcm-token`. La revocación queda limitada al
   usuario autenticado.
6. El backend marca inactivos los tokens que Firebase reporta como no
   registrados. Los tokens se ocultan en serialización y no se escriben en
   logs.

## Tipos, rutas y estado de dominio

| Tipo | Clasificación | Ruta oficial | Estado backend |
|---|---|---|---|
| `appointment_reminder` | `domain_trigger` | `/agenda-events/{id}` | Disparador real |
| `progress_checkin` | `transport_only` | `/checkins/new` o `/emotional-checkin` | Transporte preparado; pendiente de evento de dominio |
| `sober_day_update` | `transport_only` | `/home` | Transporte preparado; pendiente de evento de dominio |
| `achievement_unlocked` | `transport_only` | `/achievements/{id}` | Transporte preparado; pendiente de evento de dominio |
| `supervision_request` | `domain_trigger` | `/supervision-requests/{id}` | Disparador real |
| `supervision_response` | `domain_trigger` | `/supervision-requests/{id}` | Disparador real |
| `unlink_request` | `domain_trigger` | `/unlink-requests/{id}` | Disparador real |
| `intervention_update` | `domain_trigger` | `/interventions/{id}` | Disparador real; destinatario actual: paciente propietario |
| `relapse_alert` | `domain_trigger` | `/patients/priority` | Disparador real sujeto a autorización, consentimiento y flag |
| `risk_alert` | `domain_trigger` | `/patients/priority` | Disparador real sujeto a autorización, consentimiento y flag |
| `vulnerable_user_priority` | `transport_only` | `/patients/priority` | Transporte preparado; pendiente de evento de dominio |
| `user_validation_required` | `transport_only` | `/admin/users/pending/{id}` | Transporte preparado; pendiente de flujo de usuario pendiente |
| `supervisor_validation_required` | `domain_trigger` | `/admin/supervisors/pending/{id}` | Disparador real |
| `supervision_request_conflict` | `transport_only` | `/admin/supervision-conflicts/{id}` | Transporte preparado; pendiente de evento de dominio |
| `system_notice` | `transport_only` | Supervisión o intervención permitida | Compatibilidad/transporte genérico; no usar si existe tipo específico |
| `test_notification` | `transport_only` | Vacía o `/home` | QA autenticado; no es evento funcional del producto |

Los tipos marcados como pendientes tienen contrato y transporte, pero no deben
habilitarse funcionalmente ni simularse como eventos reales. Una eventual
notificación de intervención a un supervisor queda fuera del alcance actual.

## Privacidad y fallback local

Los textos visibles se obtienen de una lista canónica genérica. Android ignora
campos ajenos al contrato y sustituye `title` y `body` remotos por los textos
permitidos. Una ruta inválida o un rol incompatible llevan a un destino seguro.

Android conserva fallback local únicamente para agenda cacheada, check-in
básico y recordatorios simples o motivacionales no sensibles. No existe
fallback local para recaída, riesgo, desvinculación, validaciones,
intervenciones clínicas ni prioridad de vulnerabilidad.

## Evidencia local backend

- `git diff --check`: correcto.
- `php artisan config:clear`: correcto.
- `php artisan route:list --path=api/notifications -v`: tres rutas protegidas
  por autenticación Firebase y throttling.
- `php artisan test --filter=Fcm`: 36 pruebas, 172 aserciones.
- `php artisan test`: 220 pruebas aprobadas, 1252 aserciones y un smoke real
  omitido intencionalmente.
- `composer validate`: válido, con advertencias no bloqueantes por restricciones
  exactas de versiones.
- `composer audit --locked`: sin avisos de vulnerabilidad.
- Configuración efectiva al cierre: FCM deshabilitado, dry-run activo, cola
  `sync` y flags sensibles deshabilitados.

## Evidencia local Android

- `google-services.json` local y correspondencia del cliente debug verificadas
  sin exponer el archivo.
- Obtención, registro, revocación y reasignación del token entre sesiones de
  prueba verificadas sin imprimirlo completo.
- Recepción `data-only`, textos canónicos, rutas permitidas, fallback seguro y
  descarte de tipos desconocidos verificados.
- Casos de paciente, supervisor y administrador probados localmente con cuentas
  técnicas.
- Existen artefactos locales anteriores con APK debug, 172 pruebas unitarias
  aprobadas y lint sin errores. La repetición final de Gradle quedó bloqueada
  antes de cargar el proyecto por `Unable to establish loopback connection`.
- La prueba offline confirmó que la aplicación conserva contenido cacheado y
  que no genera alertas locales sensibles. El disparo temporal del recordatorio
  local básico no quedó demostrado.
- El replay de un mismo recordatorio de agenda no duplicó la notificación. No
  quedó demostrada una alarma real de AlarmManager que permita confirmar la
  cancelación exclusiva del evento coincidente y la conservación de otros.

Android no se versiona ni se incluye en el commit backend.

## Pendientes no bloqueantes para el commit backend

- Repetir en Android el disparo temporal real de un recordatorio sin internet.
- Revalidar AlarmManager/FCM con el backend desplegado y confirmar que solo se
  cancela la alarma equivalente.
- Resolver o aislar el bloqueo ambiental de Gradle y repetir build, pruebas
  unitarias y lint del cliente.
- Repetir foreground, background, navegación y ciclo del token contra Render.
- Ejecutar el smoke real Firebase únicamente en un servicio de staging
  controlado y con usuario técnico.
- Habilitar tipos pendientes solo cuando exista su evento de dominio real.

## Siguiente fase: Render

La siguiente fase comienza únicamente después de autorizar commit y push del
backend. Debe desplegarse primero un entorno Render de staging con configuración
segura, ejecutar el checklist de `FCM_BACKEND_RENDER_DEPLOYMENT.md` y después
apuntar temporalmente una variante Android de QA a ese entorno. El cambio de
`baseUrl` no pertenece al commit backend ni debe hacerse antes de que el health
check de Render sea satisfactorio.

No se considera finalizada producción hasta completar satisfactoriamente las
pruebas Render y restaurar o confirmar los flags apropiados.
