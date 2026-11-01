# Entrega backend para preparación de beta cerrada

> Actualización 28 de octubre de 2026: el contrato de Control de datos y el
> aviso `2026-10-28` reemplazan las referencias de esta entrega a exportación y
> eliminación. Consulta `docs/DATA_CONTROL_ANDROID_HANDOFF_2026-10-28.md` y
> `docs/ACCOUNT_DEACTIVATION_AND_PERMANENT_DELETION_PROCEDURE.md`. Se conserva
> este documento como evidencia histórica; no se sobrescriben sus resultados.

Fecha de revisión: 27 de octubre de 2026.

## Estado

Los cambios están preparados y probados localmente en la rama
`codex/closed-beta-backend-prep`. No se desplegaron. El commit base revisado es
`0f6b61227674de7a894df01f4e3fba4fe61eb398`; existen cambios locales previos y
los cambios de esta preparación todavía no tienen commit propio.

La beta no debe abrirse hasta publicar primero un APK compatible y después
activar el backend coordinadamente. Las pruebas backend no autorizan por sí
solas la distribución.

## Aviso de privacidad

Versión requerida: `2026-10-27`, publicada el 27 de octubre de 2026.

- `GET /api/legal/privacy-notice` conserva `title`, `version`, `published_on` y
  `content`. Agrega `closed_beta_minimum_age=18`,
  `closed_beta_age_policy_status=pending_author_confirmation`,
  `sensitive_data_consent_version=2026-10-27`,
  `responsible_status=identified`, `contact_status=phone_confirmed` y
  `pending_legal_fields=[email, applicable_address]`.
- `POST /api/legal/privacy-notice/read` conserva el body
  `{version, confirmed_read}`. La confirmación de la versión nueva no cambia
  `legal_acceptance`. Si el perfil contiene una confirmación anterior, se
  conserva en `privacy_notice_read_previous` al guardar la nueva.
- Las rutas de lectura/confirmación, autenticación, renovación, cierre y
  `/auth/me` permanecen fuera del grupo bloqueado por
  `privacy.notice.read`; una cuenta pendiente puede completar el gate.
- Una confirmación anterior no satisface la versión nueva. Las rutas protegidas
  responden `428`, `code=privacy_notice_read_required` y
  `privacy_notice_version=2026-10-27`.

Responsable informado: Juan Oswaldo Emilio Olivares Pantoja. Canal informado:
teléfono `3325356657`. Ubicación general: Tonalá, Jalisco, México. No se ha
proporcionado correo ni domicilio aplicable; la ubicación general no se presenta
como domicilio. No se atribuye responsabilidad al CETI.

## Registro de la beta

`POST /api/auth/register` conserva autenticación Firebase y rollback de fallos
parciales. Para `patient` agrega requisitos:

```json
{
  "age": 18,
  "privacy_notice_accepted": true,
  "privacy_notice_version": "2026-10-27",
  "sensitive_data_consent_accepted": true,
  "sensitive_data_consent_version": "2026-10-27"
}
```

`age` debe estar entre 18 y 120 como bloqueo preventivo. La propuesta 18+
continúa pendiente de confirmación expresa del autor; este control no autoriza
la incorporación de participantes. El consentimiento sensible se persiste como
`sensitive_data_consent` separado de `legal_acceptance`,
`privacy_notice_read`, los consentimientos de supervisión y los permisos
Android. La respuesta de perfil expone solamente `version`, `accepted_at` y
`status`.

Los campos sensibles no son requisito para `supervisor`. Las cuentas existentes
no se bloquean ni migran automáticamente por edad o por ausencia de este bloque;
su elegibilidad y un flujo de consentimiento sensible para continuar la beta
siguen pendientes de una decisión funcional.

## Exportación de autoservicio

- `GET /api/data-control/summary` agrega
  `self_service_export_available=false`.
- `actions.export.available=false`; se conserva la ruta legacy en la respuesta
  para compatibilidad y se indica `legacy_route_status_when_unavailable=410`.
- `categories[].exported=false` evita prometer inclusión en una exportación.
- `GET /api/data-control/export` permanece autenticado, sujeto a lectura vigente
  y limitado. Cuando la función está deshabilitada responde `410` con
  `code=self_service_export_retired`; no llama al servicio, no consulta datos y
  no genera archivo.
- La lógica interna de exportación se conserva para rollback; no se eliminan
  rectificación, eliminación, respaldos ni listas de campos compartidas.

Android debe ocultar la sección de exportación cuando la bandera no sea `true`,
retirar etiquetas que prometan exportación y manejar `410` sin crear archivo ni
abrir el selector de compartición.

## Procedimiento manual pendiente de confirmación

La beta conserva atención manual por teléfono para solicitudes de acceso a
datos, rectificaciones no disponibles, conservación y eliminación. Antes de
atender una solicitud, el responsable debe confirmar:

1. cómo verificará la identidad sin solicitar contraseñas ni tokens;
2. qué información y periodo comprende la solicitud;
3. plazo y estado de seguimiento;
4. formato y canal seguro de entrega;
5. registro mínimo de recepción, decisión y cierre;
6. criterios de conservación o rechazo y mecanismo de inconformidad.

No se implementó un portal, un sistema de informes ni un envío automático.

## Revisión identificable

`GET /api/health` agrega `release`, leído de `RENDER_GIT_COMMIT` o
`APP_RELEASE`. Un despliegue no debe considerarse identificado si devuelve
`unidentified`. El valor no contiene secretos.

Comprobación pública de solo lectura del 27 de octubre de 2026: Render respondió
`ok=true` y `status=healthy`, pero todavía no devolvió `release`; el aviso
desplegado continuó en `2026-10-18` con responsable y contacto pendientes. Esto
confirma que los cambios locales de esta preparación no están desplegados.

## Verificaciones de backend

Regresión local ejecutada el 27 de octubre de 2026: `330` pruebas aprobadas,
`2213` aserciones y `1` prueba no ejecutada. La única omitida fue
`FirebaseRealSmokeTest`, que exige `RUN_FIREBASE_SMOKE=true` y credenciales de un
entorno real autorizado.

- Registro y rollback Firebase/Laravel: contrato existente conservado; pruebas
  sintéticas locales pasan. No se ejecutó un registro real en Firebase en esta
  preparación.
- Aceptación por UID y versión: probada con mocks; una versión anterior no abre
  rutas protegidas y la evidencia anterior se preserva al confirmar la nueva.
- Roles, vínculo, consentimiento y alcances: lógica existente conservada; las
  pruebas de regresión deben ejecutarse antes del despliegue.
- Cambio de cuenta y token FCM: repositorio por UID y revocación existentes; no
  se hizo una prueba de dos dispositivos en esta preparación.
- Eliminación: solo pruebas sintéticas; no se eliminó ninguna cuenta real.
- Logs: los puntos revisados registran clase de excepción, no tokens, prompts ni
  cuerpos sensibles.
- Demostración FCM: permanece autenticada, por rol, limitada y deshabilitada por
  defecto (`FCM_DEMO_ENABLED=false`).
- Chatbot: conserva límite por UID, máximo de pregunta/contexto/consumo,
  `connect_timeout` y `timeout`; no reintenta automáticamente llamadas
  facturables.

## Orden coordinado de liberación

1. Android incorpora aviso/asset `2026-10-27`, consentimiento sensible separado,
   manejo de `428`, bandera de exportación y pruebas offline/cambio de cuenta.
2. Se genera y verifica un APK nuevo; el APK anterior no puede registrar
   pacientes contra el contrato nuevo.
3. Se configuran en el backend `PRIVACY_NOTICE_VERSION=2026-10-27`,
   `SENSITIVE_DATA_CONSENT_VERSION=2026-10-27` y
   `DATA_CONTROL_SELF_SERVICE_EXPORT_ENABLED=false`.
4. Se despliega únicamente con autorización y se comprueba `/api/health` con un
   `release` distinto de `unidentified`.
5. Se ejecuta la regresión autenticada con cuentas sintéticas y se actualiza la
   guía antes de invitar participantes.

## Bloqueantes restantes

- APK Android compatible aún no generado ni verificado.
- Restricción 18+ pendiente de confirmación expresa del autor; menores continúan
  fuera de alcance y no deben incorporarse.
- Correo, domicilio aplicable y procedimiento operativo de derechos pendientes.
- Flujo de consentimiento sensible para cuentas existentes pendiente.
- Prueba real controlada de registro y fallo parcial contra Firebase/Render
  pendiente.
- Eliminación end to end con cuenta sintética desechable pendiente.
- El despliegue y su commit no se han verificado porque no fueron autorizados.
