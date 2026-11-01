# Etapa 8: control de datos

Fecha de revisión: 2026-10-28. Este documento describe el contrato técnico
implementado; no constituye una certificación de cumplimiento legal integral.

## Inventario comprobado

| Almacén | Datos | Autoridad | Tratamiento |
|---|---|---|---|
| Android local | Room cifrado, preferencias de sesión seguras, outbox WorkManager y archivos privados de caché | Android | Se purga al cerrar/eliminar la cuenta. La caché no equivale a borrar datos remotos. |
| Firestore | Perfiles, notas, agenda, contactos, ajustes, logros, consentimientos, solicitudes e intervenciones | API Laravel | Consulta propia; borrado lógico por registro o proceso manual de cuenta. La exportación de autoservicio está retirada de la beta. |
| Firebase Authentication | Identidad, proveedores, correo, teléfono y estado de revocación | Firebase/API | La solicitud bloquea sincronización, revoca sesiones e intenta deshabilitar la identidad; el borrado físico queda en revisión manual. |
| Operación y seguridad | Tokens FCM, hashes de tokens revocados, bitácora de notificaciones, caché de rate limit y logs saneados | API/proveedores | No se exportan; aplican retención y revisión operativa. |
| Proveedor de IA | Contexto minimizado transitorio cuando se usa modo IA | Proveedor externo | No hay historial de chat persistido por esta API; aplican límites del proveedor. |

Los catálogos compartidos (`achievements`, `local_resources`) no son datos
propios del titular y no se incluyen en la exportación. Los datos legacy o una
duda de retención se atienden por el canal institucional.

## Consulta e inventario

`GET /api/data-control/summary` requiere token Firebase y admite 20 solicitudes
por minuto. Devuelve `role`, `account_role`, `deletion_status`, `categories`,
`inventory`, `editable_fields`, `rectification`, `account_deletion`, `actions`,
`retention_notice` y `manual_request_notice`.

`self_service_export_available` es `false` durante la beta cerrada. Android debe
ocultar la acción y no prometer que una categoría está incluida en una
exportación. `actions.manual_attention` conserva el canal telefónico para
acceso, rectificación no disponible y dudas de retención. La eliminación
definitiva usa `actions.request_permanent_deletion` con el correo específico.

`rectification.profile.offline_queue_allowed` y
`rectification.email.offline_queue_allowed` son `false`: ni perfiles ni
credenciales se modifican mediante una cola offline.

## Exportación de autoservicio retirada

`GET /api/data-control/export` permanece temporalmente registrado para clientes
anteriores. Conserva token Firebase, confirmación de lectura y límite de 3
solicitudes por minuto. Con `DATA_CONTROL_SELF_SERVICE_EXPORT_ENABLED=false`
responde `410` con `code=self_service_export_retired` y no consulta Firestore ni
genera un archivo.

La lógica interna de listas permitidas se conserva únicamente para rollback
controlado y pruebas; no forma parte de la beta cerrada. No se elimina la
rectificación, la eliminación ni el procedimiento manual para atender derechos
sobre datos.

El canal general configurado es teléfono `3325356657`, responsable ubicado en
Tonalá, Jalisco, México. La eliminación definitiva se solicita a
`rehabianex@gmail.com`; el correo no reemplaza el teléfono general ni prueba por
sí solo la identidad. Antes de actuar debe definirse y aplicar verificación de
identidad, alcance, plazo, formato y entrega segura. No se ha proporcionado
domicilio aplicable.

## Rectificación

El perfil usa los contratos existentes:

- paciente: `PATCH /api/patients/{authenticated_uid}`;
- supervisor: `PATCH /api/supervisors/{authenticated_uid}`;
- correo: Firebase `verifyBeforeUpdateEmail` y, después de verificar y renovar
  el ID token, `POST /api/auth/email/sync` sin enviar el correo solicitado.

El servidor es autoritativo. La interfaz confirma el cambio después del PATCH
y de volver a consultar el perfil. Un error de red conserva la entrada local,
pero no afirma que el cambio remoto haya ocurrido ni lo encola. Los campos
editables se publican en `summary`; los demás permanecen fuera del formulario.

## Eliminación de registros y de cuenta

El borrado de un registro mantiene los endpoints existentes. Notas, agenda,
contactos, ajustes e intervenciones usan borrado lógico; un consentimiento se
revoca y una solicitud pendiente se cancela. Estas acciones no eliminan la
cuenta.

`POST /api/data-control/account-deactivation-requests` desactiva la cuenta y
crea una solicitud de borrado suave solamente para paciente o supervisor.
Admite 3 solicitudes por minuto y requiere autenticación con antigüedad máxima
de 300 segundos.

```json
{
  "scope": "account",
  "confirmation": "DESACTIVAR MI CUENTA",
  "acknowledge_retention_limits": true
}
```

La operación crea en una transacción la solicitud, deshabilita el perfil y lo
marca `pending_manual_review`, bloqueando accesos y sincronizaciones antes de
intentar revocar FCM, refresh tokens y deshabilitar Firebase Authentication.
También inactiva vínculos, revoca consentimientos y cancela solicitudes
pendientes. Devuelve HTTP 202 con `request`, `sync_blocked`,
`local_cleanup_required`, `cleanup_status`, `manual_attention_required` y
`retention_notice`.

Android reautentica primero mediante el flujo existente y verifica que UID y
rol no hayan cambiado. La contraseña nunca se envía a este endpoint. Tras 202,
debe cancelar el trabajo del UID, vaciar outbox/Room/preferencias/exportaciones
temporales y cerrar la sesión. De este modo una reconexión no vuelve a crear
datos mediante trabajo pendiente.

`POST /api/data-control/account-deletion-requests` permanece como alias legacy
autenticado para clientes anteriores y ejecuta la misma desactivación suave. No
certifica purga física.

La eliminación definitiva se inicia por correo a `rehabianex@gmail.com` con el
asunto `Solicitud de eliminación de cuenta RehabiAnex`. Abrir el cliente no
significa que el mensaje fue enviado y el correo no demuestra identidad.

La respuesta no promete borrado físico inmediato de Firestore, respaldos,
logs o datos bajo control de proveedores. Una identidad que no pueda
deshabilitarse queda marcada para atención manual, pero el perfil continúa
bloqueando la API. Los administradores usan exclusivamente el procedimiento
manual.

Errores: `401` token inválido o autenticación no reciente; `403` rol/perfil no
compatible; `422` cuerpo inválido; `423` eliminación en curso; `429` límite de
frecuencia; `500` solo si no fue posible crear atómicamente el bloqueo y la
solicitud.

## Compatibilidad y pruebas mínimas

No se modifican las formas de los endpoints anteriores ni se migran cuentas.
Perfiles sin `data_deletion_status` se interpretan como `none`. Deben probarse:
exportación deshabilitada sin consulta ni archivo, autenticación de la ruta
legacy, frase incorrecta,
reauthenticación vencida, rol administrador, fallo parcial del proveedor,
renovación posterior al bloqueo, limpieza local y outbox, cambio de usuario y
regresión de rectificación existente.
