# RehabiAnex API - flujo seguro para Thunder Client

## Configuracion

Inicia Laravel:

```powershell
php artisan serve
```

Variables recomendadas en Thunder Client:

```text
base_url=http://127.0.0.1:8000/api
patient_uid=
patient_token=
supervisor_uid=
supervisor_token=
request_id=
consent_id=
note_id=
contact_id=
event_id=
intervention_id=
session_id=
admin_token=
```

En todos los endpoints protegidos agrega:

```text
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{patient_token}}
```

Para endpoints del supervisor cambia el token por:

```text
Authorization: Bearer {{supervisor_token}}
```

## Crear y comprobar el primer administrador

El alta administrativa no está expuesta por HTTP. Desde una terminal segura
del servidor ejecuta:

```powershell
php artisan rehabianex:create-admin admin@example.com "Administrador"
```

Si el correo no existe en Firebase, el comando pide la contraseña y su
confirmación mediante prompt oculto. Nunca imprime tokens ni contraseñas.

Para automatización no interactiva configura temporalmente una variable de
entorno de al menos 12 caracteres:

```powershell
$env:REHABIANEX_ADMIN_PASSWORD = "ClaveTemporalSegura123!"
php artisan rehabianex:create-admin admin@example.com "Administrador" --no-interaction
Remove-Item Env:REHABIANEX_ADMIN_PASSWORD
```

No pases la contraseña como argumento: el comando deliberadamente no ofrece
`--password`.

Si la cuenta ya existe en Firebase sin custom claim, la vinculación debe ser
explícita:

```powershell
php artisan rehabianex:create-admin admin@example.com "Administrador" --link-existing
```

Una cuenta que ya tenga rol o perfil de paciente/supervisor se rechaza. Si el
admin y `admins/{uid}` ya existen, el comando termina correctamente sin crear
duplicados.

Comprueba primero que el registro público rechace el rol reservado:

`POST {{base_url}}/auth/register`

```json
{
  "email": "otro-admin@example.com",
  "password": "ClaveTemporalSegura123!",
  "full_name": "Admin no permitido",
  "role": "admin",
  "privacy_notice_accepted": true,
  "privacy_notice_version": "2026-08-01"
}
```

Resultado esperado: `422` con `ok=false` y un error de validación para `role`.

Inicia sesión con `POST {{base_url}}/auth/login`, guarda `auth.id_token` como
`admin_token` y consulta:

`GET {{base_url}}/auth/me`

```text
Accept: application/json
Authorization: Bearer {{admin_token}}
```

La respuesta `200` debe indicar `auth.role=admin`,
`profile.collection=admins` y `profile.status=active`.

Comprueba finalmente el grupo administrativo:

`GET {{base_url}}/admin/ping`

```text
Accept: application/json
Authorization: Bearer {{admin_token}}
```

Resultado esperado:

```json
{
  "ok": true,
  "message": "Acceso administrativo autorizado."
}
```

Repite la petición sin token para comprobar `401`, y con `patient_token` o
`supervisor_token` para comprobar `403`.

## Administración de supervisores

Todos los requests usan:

```text
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{admin_token}}
```

### Listar

```http
GET {{base_url}}/admin/supervisors?status=pending_review&authorized=false&verified=false&limit=50
```

Los filtros son opcionales. `limit` admite de 1 a 100 y usa 50 por defecto.
El estado histórico `pending` se entrega y filtra como `pending_review`.

### Detalle

```http
GET {{base_url}}/admin/supervisors/{{supervisor_uid}}
```

### Autorizar

```http
PATCH {{base_url}}/admin/supervisors/{{supervisor_uid}}/authorize
```

```json
{
  "notes": "Supervisor validado por administración."
}
```

`notes` es opcional. Si todavía no existe, se genera el código corto estable
`RA-XXXXXXXX`.

### Rechazar

```http
PATCH {{base_url}}/admin/supervisors/{{supervisor_uid}}/reject
```

```json
{
  "reason": "No se pudo validar la información."
}
```

### Suspender

```http
PATCH {{base_url}}/admin/supervisors/{{supervisor_uid}}/suspend
```

```json
{
  "reason": "Suspensión administrativa."
}
```

### Reactivar

```http
PATCH {{base_url}}/admin/supervisors/{{supervisor_uid}}/reactivate
```

Sin body.

Las transiciones incompatibles responden `409`, los payloads inválidos `422`
y un UID inexistente `404`. Ninguna operación elimina documentos ni consulta
pacientes, notas o consentimientos.

Después de registrar un supervisor pendiente, usa su token para intentar:

```http
GET {{base_url}}/supervisors/{{supervisor_uid}}/patients
Authorization: Bearer {{supervisor_token}}
```

Antes de autorizarlo debe responder `403` con
`Tu cuenta de supervisor aún no ha sido autorizada por administración.`.
Después de autorizarlo, inicia sesión nuevamente y repite la petición; la
guarda administrativa permitirá continuar y se aplicarán entonces las reglas
de vinculación y consentimiento.

## 1. Registrar y autorizar supervisor

`POST {{base_url}}/auth/register`

```json
{
  "email": "doctor.prueba@example.com",
  "password": "UnaClaveSegura123!",
  "full_name": "Dra. Laura Martinez",
  "role": "supervisor",
  "phone": "+524491234567",
  "supervisor_type": "clinical_psychologist",
  "privacy_notice_accepted": true,
  "privacy_notice_version": "2026-08-01"
}
```

Guarda `auth.uid` como `supervisor_uid`. La cuenta nace con
`authorized=false`, `verified=false`, `status=pending_review` y sin código corto. Un
administrador debe aprobarla desde el servidor:

```powershell
php artisan rehabianex:authorize-supervisor {{supervisor_uid}}
```

Despues de autorizar, inicia sesion:

`POST {{base_url}}/auth/login`

```json
{
  "email": "doctor.prueba@example.com",
  "password": "UnaClaveSegura123!"
}
```

Guarda `tokens.id_token` como `supervisor_token`.

## 2. Registrar e iniciar sesion como paciente

`POST {{base_url}}/auth/register`

```json
{
  "email": "paciente.prueba@example.com",
  "password": "UnaClaveSegura123!",
  "full_name": "Paciente Prueba",
  "role": "patient",
  "phone": "+524497654321",
  "gender": "female",
  "age": 29,
  "privacy_notice_accepted": true,
  "privacy_notice_version": "2026-08-01"
}
```

Guarda `auth.uid` como `patient_uid`.

`POST {{base_url}}/auth/login`

```json
{
  "email": "paciente.prueba@example.com",
  "password": "UnaClaveSegura123!"
}
```

Guarda `tokens.id_token` como `patient_token`.

Verifica cada sesion:

```text
GET {{base_url}}/auth/me
```

## 3. Solicitar supervision

El paciente puede resolver primero el codigo corto:

```text
GET {{base_url}}/supervisors/resolve?code=RA-XXXXXXXX
```

La respuesta solo incluye UID, nombre visible, tipo de supervisor y
disponibilidad. Este paso es opcional: `POST /supervision-requests` tambien
acepta directamente el codigo corto dentro de `supervisor_uid`.

```json
{
  "ok": true,
  "uid": "supervisor_uid",
  "full_name": "Nombre visible",
  "supervisor_type": "padrino",
  "available": true
}
```

El paciente consulta solo supervisores autorizados. Telefonos, correos y licencia
no se exponen en este listado:

```text
GET {{base_url}}/supervisors
```

El paciente crea la solicitud:

`POST {{base_url}}/supervision-requests`

```json
{
  "supervisor_uid": "{{supervisor_uid}}",
  "message": "Solicito apoyo para manejar ansiedad y craving nocturno."
}
```

Guarda `id` como `request_id`. El servidor asigna `patient_uid`, `status` y fechas.

El supervisor consulta sus solicitudes:

```text
GET {{base_url}}/supervision-requests
```

El supervisor responde:

`PATCH {{base_url}}/supervision-requests/{{request_id}}/respond`

```json
{
  "status": "accepted"
}
```

Tambien puede usar `"status": "rejected"`.

## 4. Consentimiento explicito

El paciente autoriza los alcances que desea compartir:

`POST {{base_url}}/consents`

```json
{
  "supervisor_uid": "{{supervisor_uid}}",
  "type": "supervision_and_alerts",
  "explicit_consent": true,
  "consent_text": "Autorizo que mi supervisor consulte mis notas, agenda, contactos de apoyo y resumenes para IA.",
  "scope": [
    "patient_notes",
    "agenda_events",
    "support_contacts",
    "ai_chat_summary"
  ]
}
```

Guarda `id` como `consent_id`.

Para revocarlo:

`PATCH {{base_url}}/consents/{{consent_id}}`

```json
{
  "explicit_consent": false
}
```

La revocacion corta inmediatamente el acceso del supervisor.

También puede pausarse sin eliminarse:

```json
{
  "status": "paused"
}
```

Para reactivarlo usa `{"status":"active"}`.

### Ciclo de desvinculación

Las solicitudes nuevas usan `type=link|unlink`. Si se omite, se interpreta como
`link` para conservar compatibilidad.

Solicitud de desvinculación del paciente:

```http
POST {{base_url}}/supervision-requests
Authorization: Bearer {{patient_token}}
```

```json
{
  "type": "unlink",
  "message": "Deseo finalizar la supervisión."
}
```

El supervisor responde con el endpoint existente y
`{"status":"accepted"}` o `{"status":"rejected"}`.

Desvinculación directa:

```http
POST {{base_url}}/supervisors/{{supervisor_uid}}/patients/{{patient_uid}}/unlink
Authorization: Bearer {{supervisor_token}}
```

No requiere body. Revoca los consentimientos de la pareja y limpia la relación
del paciente.

## Auditoría manual de permisos y scopes

Paciente consultando su perfil:

```http
GET {{base_url}}/patients/{{patient_uid}}
Authorization: Bearer {{patient_token}}
```

Repite con el UID de otro paciente: debe devolver `403`. Lo mismo aplica a
`PATCH /patients/{uid}`.

Supervisor vinculado sin consentimiento:

```http
GET {{base_url}}/patients/{{patient_uid}}/notes
Authorization: Bearer {{supervisor_token}}
```

Debe devolver `403`. Después crea un consentimiento parcial:

```json
{
  "supervisor_uid": "{{supervisor_uid}}",
  "explicit_consent": true,
  "consent_text": "Autorizo únicamente notas.",
  "scope": ["patient_notes"]
}
```

Con ese consentimiento, notas quedan permitidas pero:

```http
GET {{base_url}}/support-contacts?patient_uid={{patient_uid}}
GET {{base_url}}/agenda-events?patient_uid={{patient_uid}}
```

continúan bloqueados al no existir `support_contacts` y `agenda_events`.
Cuando una operación solicita varios scopes, todos deben estar incluidos; una
coincidencia parcial no es suficiente.

Revoca mediante `PATCH /consents/{id}` con
`{"status":"revoked"}` y repite notas: el bloqueo debe ser inmediato.

Intervención válida:

```http
POST {{base_url}}/interventions
Authorization: Bearer {{supervisor_token}}
Content-Type: application/json
```

```json
{
  "patient_uid": "{{patient_uid}}",
  "reason": "Riesgo elevado.",
  "actions": ["Contactar al paciente"],
  "status": "open"
}
```

El mismo payload con token de paciente, supervisor pendiente, no vinculado o
distinto debe devolver `403`.

## Chatbot IA endurecido

```http
POST {{base_url}}/ai/supervisor-chat
Authorization: Bearer {{supervisor_token}}
Content-Type: application/json
```

```json
{
  "supervisor_uid": "{{supervisor_uid}}",
  "question": "Resume señales recientes de ansiedad y craving.",
  "mode": "ai"
}
```

Solo se incluyen pacientes vinculados cuyo consentimiento esté activo e incluya
`ai_chat_summary`. El proveedor recibe referencias efímeras (`P-001`), días de
recuperación y señales numéricas resumidas. No recibe UID, nombres, edad,
género, correo, teléfono, contactos, detonantes ni texto libre de notas.

Si el proveedor falla, responde `200` en modo degradado:

```json
{
  "ok": true,
  "mode": "fallback_local",
  "message": "El proveedor de IA no está disponible. Se usó una respuesta local segura.",
  "provider_error": {
    "code": "provider_timeout",
    "retryable": true
  }
}
```

Códigos posibles: `configuration_missing`, `provider_timeout`,
`provider_rate_limited`, `provider_unavailable` y `empty_response`. El límite
del endpoint continúa siendo 10 solicitudes por minuto; al superarlo responde
`429`.

## 5. Flujo del paciente

Actualizar perfil propio:

`PATCH {{base_url}}/patients/{{patient_uid}}`

```json
{
  "sobriety_start_date": "2026-06-01T08:00:00-06:00",
  "is_anonymous": false,
  "primary_risks": ["ansiedad nocturna", "estres laboral", "craving"]
}
```

Crear nota:

`POST {{base_url}}/patients/{{patient_uid}}/notes`

```json
{
  "client_mutation_id": "550e8400-e29b-41d4-a716-446655440000",
  "mood": "ansioso",
  "mood_score": 5,
  "anxiety_level": 8,
  "craving_level": 7,
  "energy_level": 4,
  "sleep_quality": 3,
  "had_relapse": false,
  "triggers": ["soledad", "discusion familiar"],
  "coping_actions": ["respiracion", "llamar a contacto de apoyo"],
  "note_text": "Tuve craving por la noche, pero pedi apoyo antes de actuar."
}
```

Guarda `note.note_id` como `note_id`. El servidor calcula el riesgo; el cliente no
puede enviar ni alterar `ai_risk_score` o `ai_risk_level`.

Repite el mismo POST con el mismo `client_mutation_id`: debe responder `200`,
`idempotent_replay=true` y el mismo `note.note_id`, sin crear otra nota. Como
alternativa, elimina el campo y envía:

```http
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

Consultar, modificar y eliminar una nota propia:

```text
GET    {{base_url}}/patients/{{patient_uid}}/notes
GET    {{base_url}}/patient-notes/{{note_id}}
PATCH  {{base_url}}/patient-notes/{{note_id}}
DELETE {{base_url}}/patient-notes/{{note_id}}
```

JSON para modificar:

```json
{
  "anxiety_level": 6,
  "craving_level": 4,
  "note_text": "Me senti mejor despues de hablar con mi red de apoyo."
}
```

Crear contacto de apoyo:

`POST {{base_url}}/support-contacts`

```json
{
  "name": "Marta Lopez",
  "relationship": "hermana",
  "phone": "+524491112233",
  "priority": 1,
  "can_receive_alerts": true,
  "notes": "Contacto principal."
}
```

Guarda `id` como `contact_id`.

```text
GET    {{base_url}}/support-contacts
GET    {{base_url}}/support-contacts/{{contact_id}}
PATCH  {{base_url}}/support-contacts/{{contact_id}}
DELETE {{base_url}}/support-contacts/{{contact_id}}
```

Crear configuracion de notificaciones:

`POST {{base_url}}/notification-settings`

```json
{
  "daily_check_in_enabled": true,
  "daily_check_in_time": "20:30",
  "craving_alerts_enabled": true,
  "supervisor_alerts_enabled": true,
  "support_contact_alerts_enabled": true,
  "timezone": "America/Mexico_City"
}
```

Crear evento:

`POST {{base_url}}/agenda-events`

```json
{
  "type": "support_meeting",
  "title": "Reunion semanal de apoyo",
  "starts_at": "2026-07-10T19:00:00-06:00",
  "ends_at": "2026-07-10T20:00:00-06:00",
  "location": "Centro Comunitario Norte",
  "status": "scheduled"
}
```

Lecturas del paciente:

```text
GET {{base_url}}/achievements
GET {{base_url}}/patient-achievements
GET {{base_url}}/local-resources
GET {{base_url}}/agenda-events
GET {{base_url}}/interventions
GET {{base_url}}/consents
GET {{base_url}}/notification-settings
```

## 6. Flujo del supervisor

Pacientes asignados con consentimiento vigente:

```text
GET {{base_url}}/supervisors/{{supervisor_uid}}/patients
```

Perfil y notas de un paciente autorizado:

```text
GET {{base_url}}/patients/{{patient_uid}}
GET {{base_url}}/patients/{{patient_uid}}/notes
GET {{base_url}}/patient-achievements?patient_uid={{patient_uid}}
GET {{base_url}}/support-contacts?patient_uid={{patient_uid}}
```

El contacto de apoyo solo funciona si el consentimiento incluye
`support_contacts`. La configuracion de notificaciones siempre es privada.

Crear intervencion:

`POST {{base_url}}/interventions`

```json
{
  "patient_uid": "{{patient_uid}}",
  "trigger_note_id": "{{note_id}}",
  "reason": "Craving alto con ansiedad nocturna",
  "actions": ["llamada breve", "plan de seguridad", "reunion recomendada"],
  "status": "open"
}
```

Guarda `id` como `intervention_id`.

`PATCH {{base_url}}/interventions/{{intervention_id}}`

```json
{
  "status": "in_progress",
  "notes": "Se realizo llamada y se acordo seguimiento en 24 horas."
}
```

Uso de IA, conservando el endpoint original:

`POST {{base_url}}/ai/supervisor-chat`

```json
{
  "supervisor_uid": "{{supervisor_uid}}",
  "question": "Analiza el estado general de mis pacientes y dame recomendaciones.",
  "mode": "ai"
}
```

Para probar sin consumir al proveedor usa `"mode": "local"`. Por privacidad,
ningún modo persiste pregunta o respuesta. La respuesta devuelve:

```json
{
  "stored": false,
  "session_id": null,
  "history_persistence": "disabled"
}
```

Historial de IA:

```text
GET {{base_url}}/ai-chat-sessions
GET {{base_url}}/ai-chat-sessions/{{session_id}}
GET {{base_url}}/ai-chat-messages?session_id={{session_id}}
```

Las rutas permanecen registradas por compatibilidad, pero devuelven `403` porque
el historial IA persistente está deshabilitado. Android no debe consumirlas.

## 7. Renovar y cerrar sesion

`POST {{base_url}}/auth/refresh`

```json
{
  "refresh_token": "REFRESH_TOKEN_DEVUELTO_POR_LOGIN"
}
```

`POST {{base_url}}/auth/logout`

```json
{}
```

Headers obligatorios:

```http
Accept: application/json
Authorization: Bearer {{id_token}}
```

Respuesta `200`:

```json
{
  "ok": true,
  "message": "Sesión cerrada correctamente."
}
```

Tras `logout`, Android debe descartar localmente `id_token` y `refresh_token`.
Reutilizar el mismo `id_token` devuelve `401` con la envolvente estándar de
error. El backend guarda exclusivamente el SHA-256 del ID token en
`revoked_tokens`; nunca guarda el token plano. El documento conserva
`expires_at` como timestamp nativo con la expiración original del token, que
define su vigencia máxima.
Actualmente el repositorio no instala una política TTL en Firestore: producción
debe habilitar TTL sobre `expires_at` o ejecutar una limpieza administrativa
equivalente. La ausencia de esa limpieza no permite reutilizar tokens, pero sí
puede hacer crecer la colección.

## Matriz de permisos

| Recurso | Paciente | Supervisor |
|---|---|---|
| patients | Leer y editar perfil propio | Leer pacientes asignados con consentimiento |
| supervisors | Leer directorio autorizado | Leer y editar perfil propio |
| patient-notes | CRUD propio | Solo lectura con alcance `patient_notes` |
| support-contacts | CRUD propio | Solo lectura con alcance `support_contacts` |
| agenda-events | CRUD propio | CRUD de eventos de pacientes autorizados |
| achievements | Solo lectura | Solo lectura |
| patient-achievements | Solo lectura propia | Solo lectura de pacientes autorizados |
| local-resources | Solo lectura | Solo lectura |
| consents | Crear, leer y revocar propios | Solo lectura de consentimientos dirigidos a el |
| notification-settings | CRUD propio | Sin acceso |
| interventions | Solo lectura propia | CRUD para pacientes autorizados |
| supervision-requests | Crear, leer y cancelar pendientes | Leer y responder solicitudes propias |
| ai-chat-sessions | Sin acceso | Historial deshabilitado (`403`) |
| ai-chat-messages | Sin acceso | Historial deshabilitado (`403`) |

Los intentos de usar otro `patient_uid` o `supervisor_uid` devuelven `403`. Todos
los endpoints fuera de autenticacion requieren un Firebase ID token.

## Matriz Thunder Client: permisos, consentimiento y desvinculacion

Esta seccion documenta los casos de seguridad automatizados antes de Fase 3.
Todos los errores deben mantener `{ok:false,message,errors:{}}`.

### Autenticacion

1. Sin token:

```http
GET {{base_url}}/auth/me
```

Resultado: `401`.

2. Token invalido:

```http
GET {{base_url}}/ping
Authorization: Bearer invalid.firebase.token
```

Resultado: `401` con `Token invalido, expirado o revocado.`.

### Aislamiento entre pacientes

Con `patient_token` perteneciente al Paciente A:

```http
GET {{base_url}}/patients/{{patient_b_uid}}
Authorization: Bearer {{patient_token}}
```

```http
PATCH {{base_url}}/patients/{{patient_b_uid}}
Authorization: Bearer {{patient_token}}
Content-Type: application/json

{"nickname":"Cambio no autorizado"}
```

Ambas peticiones deben responder `403`.

### Estado administrativo del supervisor

Supervisor pendiente:

```http
GET {{base_url}}/supervisors/{{supervisor_uid}}/patients
Authorization: Bearer {{pending_supervisor_token}}
```

Resultado: `403`.

Un supervisor autorizado pero no vinculado, o vinculado sin consentimiento,
tambien recibe `403` al consultar perfil, notas, contactos, agenda o al crear
intervenciones.

### Separacion de scopes

Con consentimiento `scope:["patient_notes"]`:

```http
GET {{base_url}}/patients/{{patient_uid}}/notes
Authorization: Bearer {{supervisor_token}}
```

Resultado: `200`.

```http
GET {{base_url}}/support-contacts?patient_uid={{patient_uid}}
Authorization: Bearer {{supervisor_token}}
```

Resultado: `403`.

Con consentimiento `scope:["support_contacts"]`, la segunda consulta responde
`200`, pero notas responde `403`.

Con consentimiento `scope:["agenda_events"]`:

```http
GET {{base_url}}/agenda-events?patient_uid={{patient_uid}}
Authorization: Bearer {{supervisor_token}}
```

Resultado: `200`.

### Pausa y revocacion

```http
PATCH {{base_url}}/consents/{{consent_id}}
Authorization: Bearer {{patient_token}}
Content-Type: application/json

{"status":"paused"}
```

Despues de la respuesta `200`, todos los accesos del supervisor cubiertos por
ese consentimiento deben responder `403`.

Repite con:

```json
{"status":"revoked"}
```

El bloqueo tambien debe ser inmediato.

### Supervisor ajeno

Usa el token de Supervisor A con un paciente asignado a Supervisor B:

```http
GET {{base_url}}/patients/{{patient_b_uid}}
Authorization: Bearer {{supervisor_a_token}}
```

Resultado: `403`. La misma regla aplica a notas, contactos, agenda, logros e
intervenciones.

### Intervenciones

```http
POST {{base_url}}/interventions
Authorization: Bearer {{supervisor_token}}
Content-Type: application/json

{
  "patient_uid":"{{patient_uid}}",
  "reason":"Riesgo elevado",
  "actions":["Contactar al paciente"]
}
```

Responde `201` solo si el supervisor esta activo, autorizado, verificado,
vinculado y existe consentimiento activo. Sin cualquiera de esas condiciones
responde `403`. PATCH y DELETE exigen ademas que la intervencion pertenezca al
supervisor autenticado.

### Chat IA

Crea dos pacientes vinculados; solo uno debe tener `ai_chat_summary`. Ejecuta:

```http
POST {{base_url}}/ai/supervisor-chat
Authorization: Bearer {{supervisor_token}}
Content-Type: application/json

{
  "supervisor_uid":"{{supervisor_uid}}",
  "question":"Resume alertas recientes",
  "mode":"local"
}
```

`context.authorized_patients_count` debe contar exclusivamente pacientes con
consentimiento activo y scope `ai_chat_summary`.

### Evidencia Firestore de desvinculacion

Antes:

```json
{
  "patients/patient_uid": {
    "supervisor_uid": "supervisor_uid",
    "wants_supervision": true,
    "supervision_status": "accepted",
    "supervision_ended_at": null
  },
  "consents/consent_id": {
    "supervisor_uid": "supervisor_uid",
    "explicit_consent": true,
    "status": "active",
    "revoked_at": null
  }
}
```

Ejecuta:

```http
POST {{base_url}}/supervisors/{{supervisor_uid}}/patients/{{patient_uid}}/unlink
Authorization: Bearer {{patient_token}}
```

Despues:

```json
{
  "patients/patient_uid": {
    "supervisor_uid": null,
    "wants_supervision": false,
    "supervision_status": "not_requested",
    "supervision_ended_at": "timestamp"
  },
  "consents/consent_id": {
    "explicit_consent": false,
    "status": "revoked",
    "revoked_at": "timestamp",
    "revocation_reason": "supervision_unlinked"
  }
}
```

Una consulta posterior del antiguo supervisor a perfil, notas, contactos,
agenda o pacientes supervisados debe responder `403` o excluir al paciente del
listado.
