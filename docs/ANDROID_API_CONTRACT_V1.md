# RehabiAnex - Contrato mínimo de API para Android v1

Estado: **congelado para Fase 3**

La estrategia mínima de caché, tombstones e invalidación por consentimiento se
documenta en `docs/OFFLINE_FIRST_BACKEND.md`. La sincronización incremental por
`updated_since` todavía no forma parte del contrato congelado.

El backend no controla el cifrado local de Android. Los recursos propios que se
persistan en Room deben cifrarse en el dispositivo. Datos obtenidos como
supervisor, contexto/respuestas IA y detalles sujetos a consentimiento son
network-only/memory-only y no deben escribirse en caché persistente.

El estado real de notificaciones se documenta en
`docs/NOTIFICATIONS_BACKEND_STATUS.md`: el prototipo guarda preferencias remotas
del paciente y Android programa recordatorios locales; FCM no está implementado
y queda como extensión futura.

Este documento define el contrato mínimo obligatorio que el backend debe mantener
para el cliente Android. Las rutas se expresan relativas a la URL base:

```text
https://{host}/api
```

No se deben renombrar rutas, eliminar campos de respuesta ni cambiar los tipos de
datos aquí descritos durante la Fase 3. Se permiten campos adicionales siempre que
Android pueda ignorarlos.

## Convenciones comunes

### Headers

Endpoints públicos:

```http
Accept: application/json
Content-Type: application/json
```

Endpoints protegidos:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {firebase_id_token}
```

### Envolventes

Error estándar:

```json
{
  "ok": false,
  "message": "Descripción del error.",
  "errors": {}
}
```

Error de validación:

```json
{
  "ok": false,
  "message": "Datos invalidos.",
  "errors": {
    "campo": ["Descripción del error."]
  }
}
```

Creación CRUD:

```json
{
  "ok": true,
  "message": "Documento creado correctamente.",
  "collection": "collection_name",
  "id": "document_id",
  "data": {}
}
```

Consulta individual CRUD:

```json
{
  "ok": true,
  "collection": "collection_name",
  "id": "document_id",
  "data": {}
}
```

Listado CRUD:

```json
{
  "ok": true,
  "collection": "collection_name",
  "count": 0,
  "limit": 50,
  "data": []
}
```

Actualización CRUD:

```json
{
  "ok": true,
  "message": "Documento actualizado correctamente.",
  "collection": "collection_name",
  "id": "document_id",
  "data": {}
}
```

Eliminación CRUD:

```json
{
  "ok": true,
  "message": "Documento eliminado correctamente.",
  "collection": "collection_name",
  "id": "document_id"
}
```

### Códigos comunes

| Código | Significado |
|---:|---|
| 200 | Consulta, actualización o eliminación correcta |
| 201 | Recurso creado |
| 400 | Payload mal formado o solicitud ilegible |
| 401 | Token ausente, inválido, expirado o revocado |
| 403 | Sesión válida sin permiso para la operación |
| 404 | Documento o relación no encontrada |
| 409 | Conflicto con el estado actual |
| 422 | Payload inválido |
| 429 | Límite de solicitudes excedido |
| 500 | Error interno |
| 502 | Error de Firebase o proveedor externo |

### Catálogo oficial de errores

Todos los errores API utilizan exactamente estas propiedades:

```json
{
  "ok": false,
  "message": "Mensaje legible para usuario",
  "errors": {}
}
```

`errors` siempre existe. Permanece como objeto vacío cuando no hay errores por
campo y contiene arreglos por campo en errores de validación.

#### 400 — solicitud inválida

Los detalles técnicos del parser o del servidor nunca se incluyen.

```json
{
  "ok": false,
  "message": "La solicitud no es valida.",
  "errors": {}
}
```

#### 401 — autenticación

```json
{
  "ok": false,
  "message": "Token invalido, expirado o revocado.",
  "errors": {}
}
```

#### 403 — autorización

```json
{
  "ok": false,
  "message": "No tienes permiso para realizar esta accion.",
  "errors": {}
}
```

#### 404 — recurso inexistente

```json
{
  "ok": false,
  "message": "Documento no encontrado.",
  "errors": {}
}
```

#### 409 — conflicto de estado

Se utiliza para solicitudes duplicadas, pacientes ya vinculados o transiciones
incompatibles con el estado actual.

```json
{
  "ok": false,
  "message": "La operacion entra en conflicto con el estado actual.",
  "errors": {}
}
```

#### 422 — datos inválidos

Se utiliza exclusivamente para payloads inválidos, campos no permitidos o
valores que incumplen validaciones.

```json
{
  "ok": false,
  "message": "Los datos enviados no son validos.",
  "errors": {
    "age": ["El campo age no debe ser mayor que 120."]
  }
}
```

#### 429 — límite de solicitudes

```json
{
  "ok": false,
  "message": "Demasiadas solicitudes. Intenta nuevamente mas tarde.",
  "errors": {}
}
```

#### 500 — error interno

```json
{
  "ok": false,
  "message": "Ocurrio un error interno. Intenta nuevamente mas tarde.",
  "errors": {}
}
```

#### 502 — Firebase o proveedor externo

```json
{
  "ok": false,
  "message": "Un proveedor externo no pudo procesar la solicitud.",
  "errors": {}
}
```

Las respuestas `500` y `502` nunca incluyen excepciones, stack traces,
credenciales, respuestas privadas del proveedor ni detalles de infraestructura.
Los logs de estos flujos conservan solo la clase de excepción y, cuando aplica,
el código HTTP. No registran el mensaje crudo de Firebase/OpenRouter, tokens,
credenciales, rutas locales ni el contenido del payload.

### Implementación central del contrato de errores

- `bootstrap/app.php` dirige toda excepción de rutas `/api/*` al normalizador.
- `ApiErrorResponse` genera siempre `ok`, `message` y `errors`.
- `AuthenticateFirebase` usa la misma envolvente para token ausente, inválido,
  expirado, revocado o cuenta deshabilitada.
- Las validaciones CRUD generan `422` con arreglos dentro de `errors` por campo.
- Los errores `400`, `429`, `500` y `502` sustituyen siempre el mensaje interno
  por el mensaje oficial seguro.

No se incluyen en respuestas ni logs API: stack traces, cuerpos privados de
Firebase/OpenRouter, tokens, service accounts, rutas locales o UID innecesarios.

## Autenticación oficial de Android

El flujo obligatorio de Android utiliza exclusivamente correo y contraseña:

```text
POST /auth/register
POST /auth/login
POST /auth/refresh
GET  /auth/me
POST /auth/logout
```

Eliminar Google Sign-In del cliente no afecta ninguno de estos endpoints. El ID
token y refresh token obtenidos por login de contraseña son suficientes para
usar toda la API protegida.

Las rutas `POST /auth/google` y `POST /auth/firebase` siguen registradas como
compatibilidad legacy/experimental, pero no forman parte del contrato Android
de producción ni son requeridas por ningún flujo paciente, supervisor o admin.
No deben integrarse en nuevas pantallas.

Antes de habilitar autenticación federada en una versión futura se debe exigir
la misma aceptación legal versionada de `/auth/register`, definir vinculación de
cuentas y probar duplicidad de correo/proveedor. Hasta entonces, estas rutas no
se consideran aprobadas para onboarding de producción.

## 1. Registro

**Método y ruta:** `POST /auth/register`

**Acceso:** público. Límite: 5 solicitudes por minuto.

Roles públicos permitidos: `patient` y `supervisor`. El valor `admin` está
reservado y produce `422`; una cuenta administrativa solo se crea desde el
servidor mediante `php artisan rehabianex:create-admin`.

**Body:**

```json
{
  "email": "usuario@example.com",
  "password": "UnaClaveSegura123!",
  "full_name": "Nombre del usuario",
  "role": "patient",
  "phone": "+524491234567",
  "gender": "female",
  "age": 29,
  "supervisor_type": null,
  "privacy_notice_accepted": true,
  "privacy_notice_version": "2026-08-01"
}
```

Obligatorios: `email`, `password` (mínimo 6 caracteres), `full_name`, `role`,
`privacy_notice_accepted=true` y `privacy_notice_version` con la versión vigente.
`role` admite `patient` o `supervisor`.

Opcionales: `phone`, `gender`, `age`, `supervisor_type`.

**Respuesta 201:**

```json
{
  "ok": true,
  "message": "Usuario creado correctamente en Firebase Authentication.",
  "auth": {
    "uid": "firebase_uid",
    "email": "usuario@example.com",
    "role": "patient",
    "display_name": "Nombre del usuario",
    "full_name": "Nombre del usuario",
    "id_token": "token",
    "refresh_token": "refresh_token",
    "expires_in": 3600,
    "token_type": "Bearer"
  },
  "profile": {
    "uid": "firebase_uid",
    "full_name": "Nombre del usuario",
    "nickname": null,
    "phone": "+524491234567",
    "role": "patient",
    "status": "active",
    "authorized": true,
    "verified": true,
    "supervisor_type": null,
    "supervisor_code": null,
    "is_anonymous": false,
    "supervisor_uid": null,
    "supervision_status": "not_requested",
    "sobriety_start_date": null,
    "primary_risks": [],
    "legal_acceptance": {
      "privacy_notice_version": "2026-08-01",
      "accepted_at": "2026-08-03T12:00:00-06:00",
      "status": "accepted"
    }
  },
  "tokens": {
    "id_token": "token",
    "refresh_token": "refresh_token",
    "expires_in": 3600,
    "token_type": "Bearer",
    "uid": "firebase_uid",
    "is_new_user": false
  }
}
```

Errores: `422`, `429`, `500`, `502`.

La falta de aceptación, un valor distinto de `true` o una versión diferente a
la vigente producen `422`. La evidencia se almacena dentro del perfil, separada
de `consents`, con `uid`, `role`, versión, fecha, `source=android` y
`explicit_acceptance=true`. No se almacena el texto completo del aviso.

Envolvente: respuesta especializada de autenticación.

Android producción: **sí**. El registro devuelve tokens tanto dentro de `auth`
como en la envolvente histórica `tokens`. Android nuevo debe leerlos desde
`auth`; clientes anteriores pueden continuar leyendo `tokens`.

Cuando `role` es `supervisor`, el perfil inicial siempre se entrega pendiente:

```json
{
  "authorized": false,
  "verified": false,
  "status": "pending_review",
  "supervisor_type": "support_sponsor",
  "supervisor_code": null
}
```

El registro, login, `auth/me` y la sincronización de proveedores nunca cambian
estos valores a autorizados.

## 2. Login

**Método y ruta:** `POST /auth/login`

**Acceso:** público. Límite: 10 solicitudes por minuto.

**Body:**

```json
{
  "email": "usuario@example.com",
  "password": "UnaClaveSegura123!"
}
```

Obligatorios: `email`, `password`. Opcionales: ninguno.

**Respuesta 200:**

```json
{
  "ok": true,
  "message": "Login correcto.",
  "auth": {
    "uid": "firebase_uid",
    "email": "usuario@example.com",
    "role": "patient",
    "display_name": "Nombre del usuario",
    "full_name": "Nombre del usuario",
    "id_token": "token",
    "refresh_token": "refresh_token",
    "expires_in": 3600,
    "token_type": "Bearer"
  },
  "profile": {
    "uid": "firebase_uid",
    "full_name": "Nombre del usuario",
    "nickname": null,
    "phone": null,
    "photo_url": null,
    "role": "patient",
    "status": "active",
    "authorized": true,
    "verified": true,
    "supervisor_type": null,
    "supervisor_code": null,
    "is_anonymous": false,
    "privacy_mode": false,
    "supervisor_uid": null,
    "supervision_status": "not_requested",
    "sobriety_start_date": null,
    "primary_risks": []
  },
  "tokens": {
    "id_token": "token",
    "refresh_token": "refresh_token",
    "expires_in": 3600,
    "token_type": "Bearer",
    "uid": "firebase_uid",
    "is_new_user": false
  }
}
```

Errores: `401`, `422`, `429`, `500`, `502`.

Envolvente: respuesta especializada de autenticación.

Android producción: **sí**. Debe persistir ambos tokens y reemplazarlos al hacer
refresh. Android nuevo puede leer los tokens desde `auth`; la propiedad `tokens`
se conserva para compatibilidad.

## 3. Renovar sesión

**Método y ruta:** `POST /auth/refresh`

**Acceso:** público. Límite: 10 solicitudes por minuto.

**Body:**

```json
{
  "refresh_token": "refresh_token"
}
```

Obligatorio: `refresh_token`. Opcionales: ninguno.

**Respuesta 200:**

```json
{
  "ok": true,
  "message": "Token refrescado correctamente.",
  "tokens": {
    "id_token": "nuevo_token",
    "refresh_token": "nuevo_refresh_token",
    "expires_in": 3600,
    "token_type": "Bearer",
    "uid": "firebase_uid",
    "project_id": "firebase_project"
  }
}
```

Errores: `401`, `422`, `429`, `502`.

Envolvente: respuesta especializada de autenticación.

Android producción: **sí**.

## 3.1 Cerrar sesión

```http
POST /auth/logout
Authorization: Bearer {firebase_id_token}
```

Body opcional vacío:

```json
{}
```

Respuesta `200` estable:

```json
{
  "ok": true,
  "message": "Sesión cerrada correctamente."
}
```

Sin token, con token inválido, expirado o previamente revocado, responde `401`
con la envolvente de error común. Android debe eliminar localmente tanto el ID
token como el refresh token al recibir la respuesta exitosa.

El servidor revoca los refresh tokens en Firebase y registra en
`revoked_tokens` sólo el SHA-256 del ID token presentado. `expires_at` es un
timestamp nativo que coincide con la expiración original del token. La política TTL de Firestore sobre
`expires_at` es una configuración operativa pendiente; hasta configurarla, debe
realizarse limpieza administrativa de documentos expirados.

## 4. Sesión y perfil actual

**Método y ruta:** `GET /auth/me`

**Acceso:** protegido. Body: ninguno.

Obligatorios y opcionales: no aplica.

**Respuesta 200:**

```json
{
  "ok": true,
  "auth": {
    "uid": "firebase_uid",
    "email": "usuario@example.com",
    "role": "patient",
    "display_name": "Nombre del usuario",
    "full_name": "Nombre del usuario"
  },
  "profile": {
    "uid": "firebase_uid",
    "email": "usuario@example.com",
    "full_name": "Nombre del usuario",
    "nickname": null,
    "phone": null,
    "age": 29,
    "gender": "female",
    "role": "patient",
    "status": "active",
    "authorized": true,
    "verified": true,
    "supervisor_type": null,
    "supervisor_code": null,
    "is_anonymous": false,
    "supervisor_uid": "supervisor_uid",
    "supervision_status": "accepted",
    "sobriety_start_date": "2026-06-01T08:00:00-06:00",
    "primary_risks": ["ansiedad nocturna"],
    "collection": "patients",
    "document_id": "firebase_uid",
    "updated_at": "2026-08-04T10:30:00-06:00",
    "consent_summary": {
      "has_active_consent": true,
      "active_count": 1,
      "supervisor_uids": ["supervisor_uid"],
      "scopes": ["patient_notes", "ai_chat_summary"]
    }
  }
}
```

Errores: `401`, `403`, `500`.

Envolvente: respuesta especializada de autenticación.

Android producción: **sí**.

Para perfil propio, `profile` mantiene siempre las claves `collection`,
`document_id` y `updated_at`. Perfiles legacy usan `created_at` como fallback;
si ambas fechas faltan, `updated_at` es `null` y Android debe forzar refresh en
vez de interpretar la hora de lectura como versión del documento.

`consent_summary` solo aparece para pacientes que tengan al menos un
consentimiento activo. No expone `consent_text`, notas ni información de otros
usuarios.

### Variante de perfil administrador

Una cuenta cuyo custom claim sea `role=admin` se resuelve exclusivamente desde
`admins/{uid}`. `GET /auth/me` devuelve:

```json
{
  "ok": true,
  "auth": {
    "uid": "firebase_uid",
    "email": "admin@example.com",
    "role": "admin",
    "display_name": "Administrador",
    "full_name": "Administrador"
  },
  "profile": {
    "uid": "firebase_uid",
    "role": "admin",
    "collection": "admins",
    "full_name": "Administrador",
    "email": "admin@example.com",
    "status": "active",
    "created_at": "2026-07-24T10:00:00-06:00",
    "updated_at": "2026-07-24T10:00:00-06:00"
  }
}
```

El perfil público de sesión no incluye campos distintos a los enumerados.

### Protección de rutas administrativas

Todas las rutas administrativas deben declararse dentro del grupo
`/admin` protegido, en este orden, por `firebase.auth` y `admin`. La primera
guarda valida el token, carga el custom claim y obtiene `admins/{uid}`; la
segunda exige `role=admin` y `profile.status=active`.

Endpoint de comprobación:

```http
GET /admin/ping
Authorization: Bearer {admin_id_token}
Accept: application/json
```

Respuesta `200`:

```json
{
  "ok": true,
  "message": "Acceso administrativo autorizado."
}
```

Sin token responde `401`. Un paciente, supervisor o administrador inactivo
responde `403`. Esta guarda no se aplica a rutas existentes fuera de `/admin`.

Rutas administrativas de supervisores:

```text
GET   /admin/supervisors
GET   /admin/supervisors/{uid}
PATCH /admin/supervisors/{uid}/authorize
PATCH /admin/supervisors/{uid}/reject
PATCH /admin/supervisors/{uid}/suspend
PATCH /admin/supervisors/{uid}/reactivate
```

Estas rutas proyectan una lista cerrada de datos del perfil supervisor. No
devuelven pacientes, notas emocionales ni consentimientos y nunca eliminan
físicamente el perfil.

### Guarda de funciones sensibles del supervisor

Un supervisor solo supera la guarda sensible cuando su perfil cumple
simultáneamente:

```text
role=supervisor
authorized=true
verified=true
status=active
```

La guarda se aplica a pacientes supervisados, consulta y respuesta de
solicitudes, detalle de paciente, notas autorizadas, Chatbot IA, intervenciones
y acceso de supervisor a agenda/contactos según el scope del consentimiento.
El código corto no sustituye ni forma parte de esta autorización.

Un supervisor pendiente, rechazado, suspendido o no verificado recibe:

```json
{
  "ok": false,
  "message": "Tu cuenta de supervisor aún no ha sido autorizada por administración.",
  "errors": {}
}
```

Código HTTP: `403`.

### Ejemplo de paciente sin supervisor

```json
{
  "ok": true,
  "auth": {
    "uid": "patient_001",
    "email": "ana.lopez@example.com",
    "role": "patient",
    "display_name": "Ana López",
    "full_name": "Ana López"
  },
  "profile": {
    "uid": "patient_001",
    "email": "ana.lopez@example.com",
    "full_name": "Ana López",
    "nickname": "Ana",
    "phone": "+524491112233",
    "age": 29,
    "gender": "female",
    "role": "patient",
    "status": "active",
    "authorized": true,
    "verified": true,
    "supervisor_type": null,
    "supervisor_code": null,
    "is_anonymous": false,
    "supervisor_uid": null,
    "supervision_status": "not_requested",
    "sobriety_start_date": null,
    "primary_risks": [],
    "collection": "patients"
  }
}
```

### Ejemplo de supervisor

```json
{
  "ok": true,
  "auth": {
    "uid": "supervisor_001",
    "email": "laura.martinez@example.com",
    "role": "supervisor",
    "display_name": "Dra. Laura Martínez",
    "full_name": "Dra. Laura Martínez"
  },
  "profile": {
    "uid": "supervisor_001",
    "email": "laura.martinez@example.com",
    "full_name": "Dra. Laura Martínez",
    "nickname": null,
    "phone": "+524494445566",
    "age": null,
    "gender": null,
    "role": "supervisor",
    "status": "active",
    "authorized": true,
    "verified": true,
    "supervisor_type": "clinical_psychologist",
    "supervisor_code": "RA-12AB34CD",
    "is_anonymous": false,
    "supervisor_uid": null,
    "supervision_status": null,
    "sobriety_start_date": null,
    "primary_risks": [],
    "collection": "supervisors"
  }
}
```

### Autorización administrativa de supervisores

Un supervisor nuevo puede iniciar sesión y consultar `GET /auth/me` para conocer
su estado, pero no puede acceder a operaciones sensibles hasta ser autorizado
administrativamente.

Perfil pendiente:

```json
{
  "authorized": false,
  "verified": false,
  "status": "pending_review",
  "supervisor_code": null
}
```

Un administrador autoriza la cuenta desde el servidor:

```powershell
php artisan rehabianex:authorize-supervisor {firebase_uid}
```

El comando verifica que Firebase Authentication tenga el claim
`role=supervisor` y que exista el perfil Firestore. Después establece:

```text
authorized = true
verified = true
status = "active"
authorized_at = fecha actual
verified_at = fecha actual
supervisor_code = "RA-XXXXXXXX"
```

También agrega el claim administrativo `supervisor_authorized=true`. El
supervisor debe volver a iniciar sesión para renovar sus tokens y claims.

Para pruebas:

1. Registrar una cuenta con `role: "supervisor"`.
2. Confirmar mediante `GET /auth/me` que permanece pendiente.
3. Confirmar que un endpoint sensible responde `403`.
4. Ejecutar el comando administrativo con su UID.
5. Volver a iniciar sesión.
6. Confirmar mediante `GET /auth/me` que aparece activa y autorizada.

Los supervisores incluidos explícitamente por el seeder de demostración se
crean con `authorized=true`, `verified=true`, `status=active` y código corto.
Esto conserva los escenarios demo sin autorizar cuentas generales.

Las siguientes operaciones exigen autorización administrativa:

- pacientes supervisados;
- listado, lectura y respuesta de solicitudes para supervisor;
- Chatbot IA;
- listado, creación y actualización de intervenciones;
- acceso supervisor a notas, agenda, contactos y otros datos autorizados.

Un supervisor pendiente recibe:

```json
{
  "ok": false,
  "message": "El supervisor esta pendiente de autorizacion administrativa.",
  "errors": {}
}
```

Código HTTP: `403`.

## 5. Actualizar perfil de paciente

**Método y ruta:** `PATCH /patients/{patient_uid}`

**Acceso:** protegido; únicamente el paciente propietario. El UID de la ruta
debe coincidir con el UID de la sesión. Un supervisor no puede modificar
directamente el perfil mediante este endpoint.

**Body:** al menos uno de:

```json
{
  "full_name": "Nombre",
  "nickname": "Apodo",
  "phone": "+524491234567",
  "gender": "female",
  "age": 29,
  "photo_url": "https://example.com/photo.jpg",
  "sobriety_start_date": "2026-06-01T08:00:00-06:00",
  "is_anonymous": false,
  "privacy_mode": true,
  "primary_risks": ["ansiedad nocturna"]
}
```

Obligatorio: al menos un campo modificable.

### Campos permitidos

| Campo | Tipo | Validación |
|---|---|---|
| `full_name` | string | Máximo 120 |
| `nickname` | string o null | Máximo 80 |
| `phone` | string o null | Máximo 30 |
| `age` | integer | 1 a 120 |
| `gender` | string o null | Máximo 40 |
| `photo_url` | URL o null | Máximo 2048 |
| `is_anonymous` | boolean | |
| `privacy_mode` | boolean | |
| `sobriety_start_date` | fecha ISO 8601 o null | |
| `primary_risks` | array de strings | Máximo 20; cada valor máximo 120 |

`sobriety_start_date` y `primary_risks` forman parte de la lógica actual y se
mantienen disponibles para el paciente propietario.

### Campos protegidos

Android no puede modificar mediante este endpoint:

```text
risk_level
ai_risk_level
supervisor_uid
supervision_status
wants_supervision
role
authorized
verified
uid
```

También se rechaza cualquier otro campo no incluido en la lista permitida. Los
campos protegidos o desconocidos producen `422`; no se ignoran silenciosamente.

**Respuesta 200:**

```json
{
  "ok": true,
  "message": "Paciente actualizado correctamente.",
  "collection": "patients",
  "id": "patient_uid",
  "patient": {
    "uid": "patient_uid",
    "full_name": "Nombre",
    "nickname": "Apodo",
    "phone": "+524491234567",
    "age": 29,
    "gender": "female",
    "is_anonymous": false,
    "privacy_mode": true,
    "sobriety_start_date": "2026-06-01T08:00:00-06:00",
    "photo_url": "https://example.com/photo.jpg",
    "primary_risks": ["ansiedad nocturna"],
    "updated_at": "2026-08-04T10:30:00-06:00",
    "collection": "patients",
    "document_id": "patient_uid"
  },
  "data": {
    "uid": "patient_uid",
    "full_name": "Nombre",
    "nickname": "Apodo",
    "privacy_mode": true,
    "is_anonymous": false,
    "sobriety_start_date": "2026-06-01T08:00:00-06:00",
    "photo_url": "https://example.com/photo.jpg",
    "primary_risks": ["ansiedad nocturna"],
    "updated_at": "2026-08-04T10:30:00-06:00",
    "collection": "patients",
    "document_id": "patient_uid"
  }
}
```

`patient` es el campo oficial para Android. `data` se conserva como alias
compatible con la envolvente CRUD previa.

**Error 403 — paciente ajeno o supervisor:**

```json
{
  "ok": false,
  "message": "No tienes permiso para modificar este recurso."
}
```

**Error 404:**

```json
{
  "ok": false,
  "message": "Documento no encontrado.",
  "collection": "patients",
  "id": "patient_uid"
}
```

**Error 422 — campo protegido:**

```json
{
  "ok": false,
  "message": "Campos no permitidos para actualizar el paciente: supervisor_uid, role."
}
```

**Error 422 — validación:**

```json
{
  "ok": false,
  "message": "El campo age no debe ser mayor que 120."
}
```

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

## 6. Detalle de paciente

**Método y ruta:** `GET /patients/{patient_uid}`

**Acceso:** paciente propietario o supervisor vinculado con consentimiento
vigente.

Body: ninguno.

**Respuesta 200:** envolvente de consulta individual CRUD:

```json
{
  "ok": true,
  "collection": "patients",
  "id": "patient_uid",
  "data": {
    "uid": "patient_uid",
    "full_name": "Paciente",
    "nickname": null,
    "privacy_mode": false,
    "is_anonymous": false,
    "sobriety_start_date": null,
    "photo_url": null,
    "primary_risks": [],
    "updated_at": "2026-08-04T10:30:00-06:00",
    "collection": "patients",
    "document_id": "patient_uid"
  }
}
```

Errores: `401`, `403`, `404`, `500`.

Android producción: **sí**. El perfil está dentro de `data`.

La forma ampliada anterior corresponde exclusivamente al paciente propietario.
Un supervisor recibe la proyección minimizada por privacidad y consentimiento;
este cambio no agrega `photo_url`, `updated_at`, `collection` ni campos internos
a su allowlist.

## 7. Listar notas

**Método y ruta:** `GET /patients/{patient_uid}/notes`

**Acceso:** paciente propietario o supervisor con scope `patient_notes`.

Body: ninguno.

Esta es la ruta oficial y recomendada para los listados de notas en Android.
Las rutas CRUD bajo `/patient-notes` se conservan para compatibilidad y
operaciones individuales, pero Android no debe utilizarlas como listado
principal.

El resultado se ordena por `created_at` en orden descendente: la nota más
reciente aparece primero. Los documentos sin una fecha válida quedan sujetos al
orden lexicográfico del valor almacenado.

**Respuesta 200:**

```json
{
  "ok": true,
  "patient_uid": "patient_uid",
  "count": 1,
  "notes": [
    {
      "note_id": "note_id",
      "patient_uid": "patient_uid",
      "mood": "ansioso",
      "anxiety_level": 8,
      "craving_level": 7,
      "ai_risk_score": 78,
      "ai_risk_level": "high"
    }
  ],
  "data": [
    {
      "note_id": "note_id",
      "patient_uid": "patient_uid",
      "mood": "ansioso",
      "anxiety_level": 8,
      "craving_level": 7,
      "ai_risk_score": 78,
      "ai_risk_level": "high"
    }
  ]
}
```

`data` es un alias exacto de `notes`. Ambos campos deben contener los mismos
elementos en el mismo orden.

Respuesta sin notas:

```json
{
  "ok": true,
  "patient_uid": "patient_uid",
  "count": 0,
  "notes": [],
  "data": []
}
```

**Error 403 — acceso no autorizado:**

```json
{
  "message": "El paciente no pertenece al supervisor o no existe consentimiento vigente."
}
```

También puede utilizar la envolvente:

```json
{
  "ok": false,
  "message": "No tienes permiso para acceder a este paciente."
}
```

**Error 404 — paciente inexistente:**

```json
{
  "message": "Paciente no encontrado."
}
```

Errores: `401`, `403`, `404`, `500`.

Envolvente: respuesta especializada de notas.

Android producción: **sí**.

## 8. Crear nota

**Método y ruta:** `POST /patients/{patient_uid}/notes`

**Acceso:** únicamente el paciente propietario.

**Body:**

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
  "triggers": ["soledad"],
  "coping_actions": ["respiración"],
  "note_text": "Registro del día."
}
```

Obligatorios: `mood`, `mood_score`, `anxiety_level`, `craving_level`,
`had_relapse`.

Opcionales: `energy_level`, `sleep_quality`, `triggers`, `coping_actions`,
`note_text`, `client_mutation_id`.

Para una nota creada desde la outbox offline, Android debe generar una clave
estable y enviarla preferentemente en `client_mutation_id`. También se acepta el
header `Idempotency-Key`. Si llegan ambos deben ser idénticos. El valor admite
8 a 128 caracteres alfanuméricos y `._:-`; se recomienda UUID.

Los niveles numéricos admiten valores de 0 a 10. Android no debe enviar
`patient_uid`, `note_id`, `ai_risk_score`, `ai_risk_level`, `created_at` ni
`updated_at`.

**Respuesta 201:**

```json
{
  "ok": true,
  "message": "Nota creada correctamente.",
  "idempotent_replay": false,
  "note": {
    "note_id": "note_id",
    "patient_uid": "patient_uid",
    "client_mutation_id": "550e8400-e29b-41d4-a716-446655440000",
    "ai_risk_score": 78,
    "ai_risk_level": "high"
  },
  "data": {}
}
```

Al repetir la misma clave para el mismo paciente, no se crea otro documento y
se devuelve `200` con exactamente la misma nota, `data` como alias de `note` e
`idempotent_replay=true`. La misma clave usada por pacientes diferentes queda
en espacios de nombres distintos y no colisiona.

Sin `client_mutation_id` ni header, se conserva el comportamiento legacy: cada
POST válido crea una nota nueva.

El mutation ID se conserva mientras exista la nota, incluido su tombstone de
soft delete. No tiene TTL independiente. Un borrado físico futuro perdería la
protección contra reintentos antiguos y debe respetar como mínimo la ventana
máxima de outbox offline definida por Android.

Errores: `401`, `403`, `409`, `422`, `500`.

Envolvente: respuesta especializada de notas.

Android producción: **sí**.

## 9. Listar consentimientos

**Método y ruta:** `GET /consents`

**Acceso:** protegido.

Body: ninguno.

**Respuesta 200:** listado CRUD con `collection: "consents"` y los documentos en
`data`.

Errores: `401`, `403`, `500`.

Android producción: **sí**.

## 10. Crear consentimiento

**Método y ruta:** `POST /consents`

**Acceso:** paciente.

**Body:**

```json
{
  "supervisor_uid": "RA-12AB34CD",
  "type": "supervision_and_alerts",
  "explicit_consent": true,
  "consent_text": "Autorizo el acceso seleccionado.",
  "scope": [
    "patient_notes",
    "agenda_events",
    "support_contacts",
    "ai_chat_summary"
  ]
}
```

Obligatorios: `supervisor_uid`, `explicit_consent` con valor `true`,
`consent_text`, `scope` con al menos un elemento.

Opcional: `type`, aunque Android debe enviar
`type: "supervision_and_alerts"` para mantener semántica estable.

`supervisor_uid` puede contener UID Firebase o código corto `RA-XXXXXXXX`.

**Respuesta 201:** envolvente de creación CRUD con
`collection: "consents"`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

## 11. Actualizar o revocar consentimiento

**Método y ruta:** `PATCH /consents/{id}`

**Acceso:** paciente propietario.

**Body:** uno o más campos:

```json
{
  "explicit_consent": false,
  "consent_text": "Texto actualizado.",
  "scope": ["patient_notes"]
}
```

Obligatorio: al menos uno de `explicit_consent`, `consent_text`, `scope`.

Opcionales: los tres campos anteriores.

**Respuesta 200:** envolvente de actualización CRUD con
`collection: "consents"`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**. Para revocar debe enviar
`explicit_consent: false`.

## 12. Listar solicitudes de supervisión

**Método y ruta:** `GET /supervision-requests`

**Acceso:** protegido. Paciente ve las propias; supervisor ve las dirigidas a él.

Body: ninguno.

**Respuesta 200:** listado CRUD con
`collection: "supervision_requests"`.

Errores: `401`, `403`, `500`.

Android producción: **sí**.

## 13. Crear solicitud de supervisión

**Método y ruta:** `POST /supervision-requests`

**Acceso:** paciente.

**Body:**

```json
{
  "supervisor_uid": "RA-12AB34CD",
  "message": "Solicito acompañamiento."
}
```

Obligatorio: `supervisor_uid`, como UID Firebase o código corto.

Opcional: `message`.

No forman parte del contrato `type` ni `request_type`.

**Respuesta 201:** envolvente de creación CRUD con
`collection: "supervision_requests"` y `status: "pending"` dentro de `data`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

## 14. Cancelar solicitud pendiente

**Método y ruta:** `PATCH /supervision-requests/{id}`

Esta es la ruta oficial y recomendada para Android.

**Acceso:** paciente propietario que creó la solicitud. Conforme a las reglas
actuales, el supervisor responde mediante
`PATCH /supervision-requests/{id}/respond`; no cancela solicitudes mediante esta
ruta.

**Body:**

```json
{
  "status": "cancelled"
}
```

Obligatorio: `status` con valor exacto `cancelled`.

Opcionales: ninguno.

La cancelación es lógica. El documento no se elimina y se actualizan:

```text
status = "cancelled"
cancelled_at = fecha actual
updated_at = fecha actual
```

**Respuesta 200 estable:**

```json
{
  "ok": true,
  "message": "Solicitud cancelada correctamente.",
  "request": {
    "request_id": "request_id",
    "patient_uid": "patient_uid",
    "supervisor_uid": "supervisor_uid",
    "status": "cancelled",
    "cancelled_at": "2026-07-24T20:30:00-06:00",
    "updated_at": "2026-07-24T20:30:00-06:00",
    "document_id": "request_id"
  },
  "collection": "supervision_requests",
  "id": "request_id",
  "data": {
    "request_id": "request_id",
    "status": "cancelled",
    "cancelled_at": "2026-07-24T20:30:00-06:00",
    "updated_at": "2026-07-24T20:30:00-06:00",
    "document_id": "request_id"
  }
}
```

`request` es el campo oficial para Android. `collection`, `id` y `data` se
conservan para no romper clientes que consumen la envolvente CRUD anterior.

**Error 403 — solicitud de otro paciente:**

```json
{
  "ok": false,
  "message": "No tienes permiso para acceder a este documento."
}
```

**Error 404 — solicitud inexistente:**

```json
{
  "ok": false,
  "message": "Documento no encontrado.",
  "collection": "supervision_requests",
  "id": "request_id"
}
```

**Error 409 — solicitud no pendiente:**

```json
{
  "ok": false,
  "message": "Solo puedes cancelar una solicitud pendiente."
}
```

**Error 422 — estado diferente de cancelled:**

```json
{
  "ok": false,
  "message": "El paciente solo puede cambiar el estado a cancelled."
}
```

Errores: `401`, `403`, `404`, `409` si ya fue respondida, `422`, `500`.

Android producción: **sí**.

### DELETE legado

La ruta `DELETE /supervision-requests/{id}` existe por el CRUD dinámico y solo
permite eliminar solicitudes propias que continúan en estado `pending`.

Esta ruta queda marcada como **legada y no recomendada** porque elimina
físicamente el documento. Se conserva temporalmente para no romper versiones de
Android que todavía no hayan migrado. Todo desarrollo nuevo debe utilizar el
`PATCH` de cancelación lógica. Su bloqueo o eliminación requiere confirmar
primero que todas las versiones activas de Android hayan migrado.

## 15. Aceptar o rechazar solicitud

**Método y ruta:** `PATCH /supervision-requests/{id}/respond`

**Acceso:** supervisor destinatario.

**Body:**

```json
{
  "status": "accepted"
}
```

Obligatorio: `status`, con `accepted` o `rejected`.

Opcionales: ninguno.

**Respuesta 200:**

```json
{
  "ok": true,
  "message": "Solicitud aceptada. El paciente debe otorgar consentimiento explicito antes de compartir informacion.",
  "data": {
    "request_id": "request_id",
    "status": "accepted",
    "responded_at": "2026-07-24T20:30:00-06:00"
  }
}
```

Errores: `401`, `403`, `404`, `409`, `422`, `500`.

Envolvente: respuesta especializada de supervisión.

Android producción: **sí**.

## 16. Listar pacientes supervisados

**Método y ruta:** `GET /supervisors/{uid}/patients`

**Acceso:** supervisor; `{uid}` debe coincidir con la sesión.

Body: ninguno.

**Respuesta 200:**

```json
{
  "ok": true,
  "supervisor_uid": "supervisor_uid",
  "count": 1,
  "patients": []
}
```

Errores: `401`, `403`, `500`.

Envolvente: respuesta especializada de pacientes supervisados.

Android producción: **sí**.

## 16.1 Resolver código corto de supervisor

**Método y ruta:** `GET /supervisors/resolve?code=RA-XXXXXXXX`

**Acceso:** protegido. Límite: 20 solicitudes por minuto.

Body: ninguno. El código se envía como query parameter.

Formato admitido:

```text
RA-XXXXXXXX
```

`XXXXXXXX` contiene exactamente ocho caracteres hexadecimales. El backend
acepta letras minúsculas por compatibilidad y normaliza el código a mayúsculas.

El endpoint:

1. valida el formato;
2. busca el supervisor por `supervisor_code`;
3. exige `authorized=true`, `verified=true`, `status=active` y disponibilidad;
4. devuelve únicamente la proyección pública del supervisor.

**Respuesta 200:**

```json
{
  "ok": true,
  "uid": "supervisor_uid",
  "full_name": "Dra. Laura Martinez",
  "supervisor_type": "clinical_psychologist",
  "available": true
}
```

No se devuelven email, teléfono, licencia, consentimientos, pacientes ni campos
administrativos.

**Error 422 — formato inválido:**

```json
{
  "ok": false,
  "message": "Los datos enviados no son validos.",
  "errors": {
    "code": ["El codigo debe usar el formato RA-XXXXXXXX."]
  }
}
```

**Error 404 — inexistente, pendiente o no disponible:**

```json
{
  "ok": false,
  "message": "Supervisor no disponible.",
  "errors": {}
}
```

La misma respuesta `404` se utiliza para códigos inexistentes y supervisores no
autorizados o no disponibles, evitando revelar el estado interno de una cuenta.

Errores adicionales: `401`, `429`, `500`.

Android producción: **sí**.

`POST /supervision-requests` continúa aceptando el código corto directamente en
`supervisor_uid`; resolverlo primero es opcional y sirve únicamente para mostrar
al paciente la identidad pública antes de confirmar la solicitud.

## 17. Listar recursos locales

**Método y ruta:** `GET /local-resources`

**Acceso:** protegido.

Body: ninguno.

**Respuesta 200:** listado CRUD con
`collection: "local_resources"`.

Errores: `401`, `500`.

Android producción: **sí**.

## 18. Listar agenda

**Método y ruta:** `GET /agenda-events`

**Acceso:** protegido.

Body: ninguno.

**Respuesta 200:** listado CRUD con `collection: "agenda_events"`.

Errores: `401`, `403`, `500`.

Android producción: **sí**.

## 19. Crear evento de agenda

**Método y ruta:** `POST /agenda-events`

**Acceso:** paciente o supervisor autorizado.

**Body:**

```json
{
  "patient_uid": "patient_uid",
  "type": "support_meeting",
  "title": "Reunión semanal",
  "starts_at": "2026-07-25T19:00:00-06:00",
  "ends_at": "2026-07-25T20:00:00-06:00",
  "location": "Centro comunitario",
  "status": "scheduled",
  "notes": "Llevar bitácora"
}
```

Obligatorios: `title`, `starts_at`. Para supervisor también `patient_uid`.

Opcionales: `type`, `ends_at`, `location`, `status`, `notes`. Un paciente no
necesita enviar `patient_uid`.

Estados admitidos: `scheduled`, `completed`, `cancelled`.

**Respuesta 201:** creación CRUD con `collection: "agenda_events"`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

## 20. Actualizar evento

**Método y ruta:** `PATCH /agenda-events/{id}`

**Acceso:** propietario o supervisor autorizado.

**Body:** uno o más de:

```json
{
  "type": "support_meeting",
  "title": "Título actualizado",
  "starts_at": "2026-07-25T19:00:00-06:00",
  "ends_at": "2026-07-25T20:00:00-06:00",
  "location": "Ubicación",
  "status": "completed",
  "notes": "Notas"
}
```

Obligatorio: al menos un campo modificable.

Opcionales: todos los campos mostrados.

**Respuesta 200:** actualización CRUD con `collection: "agenda_events"`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

## 21. Eliminar evento

**Método y ruta:** `DELETE /agenda-events/{id}`

**Acceso:** propietario o supervisor autorizado.

Body: ninguno.

**Respuesta 200:** eliminación CRUD con `collection: "agenda_events"`.

Errores: `401`, `403`, `404`, `500`.

Android producción: **sí**.

## 22. Listar contactos

**Método y ruta:** `GET /support-contacts`

**Acceso:** paciente; supervisor con `patient_uid` como query y scope
`support_contacts`.

Body: ninguno.

**Respuesta 200:** listado CRUD con `collection: "support_contacts"`.

Errores: `401`, `403`, `422`, `500`.

Android producción: **sí**.

## 23. Crear contacto

**Método y ruta:** `POST /support-contacts`

**Acceso:** paciente.

**Body:**

```json
{
  "name": "Marta López",
  "phone": "+524491112233",
  "relationship": "hermana",
  "priority": 1,
  "can_receive_alerts": true,
  "notes": "Contacto principal"
}
```

Obligatorios: `name`, `phone`.

Opcionales: `relationship`, `priority` de 1 a 10, `can_receive_alerts`, `notes`.

**Respuesta 201:** creación CRUD con `collection: "support_contacts"`.

Errores: `401`, `403`, `422`, `500`.

Android producción: **sí**.

## 24. Actualizar contacto

**Método y ruta:** `PATCH /support-contacts/{id}`

**Acceso:** paciente propietario.

**Body:** uno o más de:

```json
{
  "name": "Marta López",
  "phone": "+524491112233",
  "relationship": "hermana",
  "priority": 1,
  "can_receive_alerts": false,
  "notes": "Actualizado"
}
```

Obligatorio: al menos un campo modificable.

Opcionales: todos los campos mostrados.

**Respuesta 200:** actualización CRUD con `collection: "support_contacts"`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

## 25. Eliminar contacto

**Método y ruta:** `DELETE /support-contacts/{id}`

**Acceso:** paciente propietario.

Body: ninguno.

**Respuesta 200:** eliminación CRUD con `collection: "support_contacts"`.

Errores: `401`, `403`, `404`, `500`.

Android producción: **sí; el endpoint existe**.

## 26. Obtener configuración de notificaciones

> Estado de entrega: FCM y push remoto no están implementados. El backend no
> registra tokens de dispositivo y estos endpoints no envían notificaciones.
> Android consume las preferencias, administra el permiso del sistema
> (`POST_NOTIFICATIONS` cuando aplica) y programa notificaciones locales. El
> permiso no se consulta ni modifica mediante la API.

**Método y ruta:** `GET /notification-settings`

**Acceso:** paciente.

Body: ninguno.

### Esquema oficial

```json
{
  "settings_id": "patient_uid",
  "notification_setting_id": "patient_uid",
  "user_uid": "patient_uid",
  "patient_uid": "patient_uid",
  "daily_check_in_enabled": true,
  "daily_check_in_time": "21:00",
  "daily_note_enabled": true,
  "daily_note_time": "21:00",
  "sober_day_enabled": true,
  "sober_day_time": "08:00",
  "achievement_enabled": true,
  "achievement_time": "09:00",
  "event_reminders_enabled": true,
  "motivational_enabled": true,
  "motivational_time": "20:00",
  "craving_alerts_enabled": true,
  "supervisor_alerts_enabled": true,
  "support_contact_alerts_enabled": true,
  "timezone": "America/Mexico_City"
}
```

Aliases garantizados:

- `settings_id` y `notification_setting_id` contienen el mismo identificador.
- `user_uid` y `patient_uid` contienen el mismo UID.
- `daily_note_enabled` y `daily_check_in_enabled` contienen el mismo valor.
- `daily_note_time` y `daily_check_in_time` contienen la misma hora.

**Respuesta 200:**

```json
{
  "ok": true,
  "collection": "notification_settings",
  "count": 1,
  "limit": 50,
  "data": [
    {
      "settings_id": "patient_uid",
      "notification_setting_id": "patient_uid",
      "user_uid": "patient_uid",
      "patient_uid": "patient_uid",
      "daily_check_in_enabled": true,
      "daily_check_in_time": "21:00",
      "daily_note_enabled": true,
      "daily_note_time": "21:00",
      "sober_day_enabled": true,
      "sober_day_time": "08:00",
      "achievement_enabled": true,
      "achievement_time": "09:00",
      "event_reminders_enabled": true,
      "motivational_enabled": true,
      "motivational_time": "20:00",
      "craving_alerts_enabled": true,
      "supervisor_alerts_enabled": true,
      "support_contact_alerts_enabled": true,
      "timezone": "America/Mexico_City",
      "document_id": "patient_uid"
    }
  ]
}
```

Errores: `401`, `403`, `500`.

Android producción: **sí**.

## 27. Crear configuración de notificaciones

**Método y ruta:** `POST /notification-settings`

**Acceso:** paciente.

**Body:**

```json
{
  "daily_check_in_enabled": true,
  "daily_check_in_time": "20:30",
  "daily_note_enabled": true,
  "daily_note_time": "20:30",
  "sober_day_enabled": true,
  "sober_day_time": "08:00",
  "achievement_enabled": true,
  "achievement_time": "09:00",
  "event_reminders_enabled": true,
  "motivational_enabled": true,
  "motivational_time": "20:00",
  "craving_alerts_enabled": true,
  "supervisor_alerts_enabled": true,
  "support_contact_alerts_enabled": false,
  "timezone": "America/Mexico_City"
}
```

Obligatorios: ninguno técnicamente. El backend asigna
`timezone: "America/Mexico_City"` cuando Android no la envía.

Opcionales: todos los campos mostrados. Todas las horas usan `HH:mm`.
También se acepta `user_uid`, pero debe coincidir con la sesión. Android no debe
enviar IDs; el backend los asigna.

**Respuesta cuando no existía configuración — 201:**

```json
{
  "ok": true,
  "message": "Documento creado correctamente.",
  "collection": "notification_settings",
  "id": "patient_uid",
  "data": {
    "settings_id": "patient_uid",
    "notification_setting_id": "patient_uid",
    "user_uid": "patient_uid",
    "patient_uid": "patient_uid",
    "daily_check_in_enabled": true,
    "daily_check_in_time": "21:00",
    "daily_note_enabled": true,
    "daily_note_time": "21:00",
    "timezone": "America/Mexico_City",
    "document_id": "patient_uid"
  }
}
```

**Respuesta cuando ya existía configuración — 200:**

```json
{
  "ok": true,
  "message": "Configuracion de notificaciones actualizada correctamente.",
  "collection": "notification_settings",
  "id": "settings_id_existente",
  "data": {
    "settings_id": "settings_id_existente",
    "notification_setting_id": "settings_id_existente",
    "user_uid": "patient_uid",
    "patient_uid": "patient_uid",
    "document_id": "settings_id_existente"
  }
}
```

Errores: `401`, `403`, `422`, `500`.

Android producción: **sí**. `POST` funciona como creación o actualización
idempotente; Android no necesita comprobar primero si existe.

## 28. Actualizar configuración de notificaciones

**Método y ruta:** `PATCH /notification-settings/{id}`

**Acceso:** paciente propietario.

**Body:** uno o más de:

```json
{
  "daily_check_in_enabled": true,
  "daily_check_in_time": "20:30",
  "daily_note_enabled": true,
  "daily_note_time": "20:30",
  "sober_day_enabled": true,
  "sober_day_time": "08:00",
  "achievement_enabled": true,
  "achievement_time": "09:00",
  "event_reminders_enabled": true,
  "motivational_enabled": true,
  "motivational_time": "20:00",
  "craving_alerts_enabled": true,
  "supervisor_alerts_enabled": true,
  "support_contact_alerts_enabled": false,
  "timezone": "America/Mexico_City"
}
```

Obligatorio: al menos un campo modificable.

Opcionales: todos los campos mostrados.

El backend acepta tanto `daily_note_*` como `daily_check_in_*`. Si Android envía
solo una variante, el backend sincroniza y devuelve ambas.

**Respuesta 200:** actualización CRUD con `collection:
"notification_settings"` y el esquema oficial dentro de `data`.

Errores: `401`, `403`, `404`, `422`, `500`.

Android producción: **sí**.

### Regla anti-duplicados

Existe una sola configuración remota por paciente:

1. En nuevas configuraciones, el ID del documento es el `patient_uid`.
2. `POST /notification-settings` busca primero una configuración existente.
3. Si existe, actualiza ese documento y responde `200`.
4. Si no existe, crea el documento determinista y responde `201`.
5. Solicitudes simultáneas para un paciente escriben sobre el mismo ID.

Los posibles duplicados históricos deben revisarse antes del despliegue; el
backend no los elimina automáticamente para evitar pérdida de preferencias.

### Configuración de supervisor

El supervisor **no tiene configuración remota de notificaciones** en esta
versión. Los endpoints `notification-settings` están restringidos al rol
`patient`.

Android debe mantener las preferencias de notificación del supervisor
localmente hasta que se defina un esquema remoto separado. No debe intentar
crear una configuración de paciente usando el UID del supervisor.

`notification-settings` almacena exclusivamente preferencias y horarios. Una
operación exitosa no crea un job, no llama a Firebase Cloud Messaging y no
garantiza entrega remota. No existe endpoint de registro de token FCM en este
contrato. Cualquier integración futura requerirá contrato, almacenamiento de
tokens, retención y política de privacidad independientes.

## 29. Chatbot IA para supervisor

**Método y ruta:** `POST /ai/supervisor-chat`

**Acceso:** supervisor. Límite: 10 solicitudes por minuto.

**Body:**

```json
{
  "supervisor_uid": "firebase_supervisor_uid",
  "question": "¿Qué pacientes presentan ansiedad elevada?",
  "mode": "ai"
}
```

Obligatorios: `supervisor_uid`, que debe coincidir con la sesión, y `question`.

Opcional: `mode`, con `ai` o `local`. El valor predeterminado es `ai`.

**Respuesta 200:**

```json
{
  "ok": true,
  "mode": "ai",
  "supervisor_uid": "supervisor_uid",
  "question": "Pregunta",
  "answer": "Respuesta",
  "model": "modelo",
  "usage": null,
  "context": {
    "authorized_patients_count": 1,
    "notes_count": 5
  },
  "stored": false,
  "session_id": null,
  "history_persistence": "disabled"
}
```

Si el proveedor falla, el endpoint conserva código `200` y devuelve
`mode: "fallback_local"`. `model` y `usage` pueden estar ausentes.

Errores: `401`, `403`, `422`, `429`, `500`.

Envolvente: respuesta especializada de IA.

Android producción: **sí**. Android debe mostrar `answer` para `ai`, `local` y
`fallback_local`. Android no debe esperar ni consultar historial persistente.

## Garantías de compatibilidad

Durante Fase 3:

1. Los endpoints de este documento deben permanecer registrados.
2. Los endpoints protegidos deben continuar usando autenticación Firebase.
3. `register`, `login` y `refresh` deben permanecer públicos con throttling.
4. Los campos existentes no deben cambiar de nombre ni de tipo.
5. Se pueden añadir campos opcionales sin romper el contrato.
6. Android debe ignorar campos desconocidos.
7. Un cambio incompatible requiere una nueva versión del contrato.

## Anexo: ciclo de vinculación y desvinculación

El campo oficial de las solicitudes es `type`, con valores `link` y `unlink`.
`request_type` se acepta como alias de entrada y una solicitud histórica sin
tipo se interpreta como `link`.

Una solicitud `unlink` solo puede crearse cuando existe una relación aceptada.
Al aceptarla, o al ejecutar:

```http
POST /supervisors/{supervisor_uid}/patients/{patient_uid}/unlink
```

el backend deja al paciente con:

```json
{
  "supervisor_uid": null,
  "wants_supervision": false,
  "supervision_status": "not_requested",
  "supervision_ended_at": "timestamp"
}
```

Los consentimientos de esa pareja quedan con `explicit_consent=false`,
`status=revoked`, `revoked_at`, `updated_at` y
`revocation_reason=supervision_unlinked`. La operación no elimina documentos y
el acceso posterior del supervisor queda bloqueado.

Los consentimientos admiten `status=active|paused|revoked`. El estado `paused`
bloquea acceso sin borrar el consentimiento.

Cuando una operación requiere más de un scope, el consentimiento debe incluirlos
todos. Una intersección parcial no autoriza la operación.

## Anexo: privacidad del Chatbot IA

`POST /ai/supervisor-chat` exige supervisor administrativamente autorizado. Solo
considera pacientes vinculados con consentimiento activo y scope
`ai_chat_summary`.

El contexto enviado al proveedor contiene exclusivamente:

```text
app
privacy_rule
authorized_patients_count
notes_count
patients[].patient_ref
patients[].recovery_days
recent_notes[].patient_ref
recent_notes[].mood_score
recent_notes[].anxiety_level
recent_notes[].craving_level
recent_notes[].energy_level
recent_notes[].sleep_quality
recent_notes[].had_relapse
recent_notes[].ai_risk_score
recent_notes[].ai_risk_level
recent_notes[].days_ago
```

No se envían UID, nombres, edad, género, fechas exactas de sobriedad, correo,
teléfono, contactos de apoyo, detonantes, IDs de notas ni texto libre. UID,
nombre, apodo, correo y teléfono escritos en la pregunta también se sustituyen
o redactan antes de llamar al proveedor.

El prompt prohíbe diagnóstico, prescripción, terapia, sustitución profesional y
reidentificación. Los fallos externos usan fallback local seguro con códigos
estables y sin incluir respuestas internas del proveedor.

## Adenda de estabilizacion previa a Fase 3

Esta adenda forma parte obligatoria del contrato v1 y prevalece sobre cualquier
ejemplo anterior que resulte ambiguo.

### Solicitudes de supervision

El campo oficial es `type` y sus unicos valores son `link` y `unlink`.
`request_type` se mantiene como alias de entrada. Cuando ambos campos se omiten,
el backend interpreta la solicitud como `type: "link"` por compatibilidad.

Estados validos: `pending`, `accepted`, `rejected`, `cancelled`.

- Aceptar `link` crea la relacion paciente-supervisor.
- Rechazar `link` no crea relacion.
- Aceptar `unlink` termina la relacion y revoca sus consentimientos.
- Rechazar `unlink` conserva sin cambios la relacion y el consentimiento.
- Cancelar solo es posible mientras la solicitud esta `pending`.

### Actualizacion de consentimientos

`PATCH /consents/{id}` acepta:

```json
{"explicit_consent": false}
```

La variante anterior equivale a `status: "revoked"`.

```json
{"status": "paused"}
```

Pausa inmediatamente todos los accesos relacionados.

```json
{"status": "active"}
```

Reactiva solo si el paciente sigue vinculado al mismo supervisor y existe al
menos un scope valido.

```json
{"status": "revoked"}
```

Revoca inmediatamente todos los accesos relacionados.

Scopes reconocidos:

```text
patient_notes
agenda_events
support_contacts
ai_chat_summary
patient_achievements
patient_phone
```

Solo el paciente propietario puede pausar, reactivar, revocar o modificar los
scopes. Un supervisor recibe `403`.

### Proyeccion para supervisores

Los endpoints consultados por supervisor usan allowlists por recurso.

`GET /supervisors/{supervisor_uid}/patients` y
`GET /patients/{patient_uid}` pueden incluir:

```text
uid, patient_uid, display_name, full_name, nickname, age, gender,
is_anonymous, privacy_mode, sobriety_start_date, primary_risks,
supervision_status
```

`email`, rol, flags administrativos y campos internos nunca se entregan. Cuando
`privacy_mode=true` o `is_anonymous=true`, `full_name` es `null` y
`display_name` usa nickname o una referencia segura. `phone` solo aparece con
scope explicito `patient_phone`.

Las notas, contactos, agenda y logros usan proyecciones propias y requieren,
respectivamente, `patient_notes`, `support_contacts`, `agenda_events` y
`patient_achievements`.

### Historial IA deshabilitado

Revocar o pausar el consentimiento impide incorporar nuevos datos del paciente
al Chatbot IA. Una consulta posterior puede responder, pero el contexto reporta
cero pacientes autorizados cuando no existe otro consentimiento vigente.

En esta versión no se crean documentos en `ai_chat_sessions` ni
`ai_chat_messages`: no se persisten preguntas, respuestas ni metadatos de la
consulta. La respuesta conserva `stored=false`, `session_id=null` y
`history_persistence=disabled` para que el cliente conozca la política.

`GET /ai-chat-sessions`, `GET /ai-chat-sessions/{id}` y
`GET /ai-chat-messages?session_id={id}` permanecen registrados por compatibilidad
de rutas, pero responden `403`. Esto también bloquea documentos legacy mientras
se realiza una depuración controlada. Android no consume estos endpoints.

### DELETE y cancelacion logica

Los DELETE autorizados sobre recursos sincronizables son logicos. El documento
se conserva con:

```json
{
  "deleted_at": "timestamp",
  "deleted_by": "firebase_uid",
  "updated_at": "timestamp"
}
```

Los documentos eliminados no aparecen en listados ni consultas individuales.
Esto aplica a notas, contactos, agenda, configuracion de notificaciones e
intervenciones cuando el actor tiene permiso de eliminacion.

`DELETE /supervision-requests/{id}` permanece solo como compatibilidad legacy.
No elimina el documento: lo cambia a `status: "cancelled"`, agrega
`cancelled_at`, devuelve `deprecated: true` y anuncia como reemplazo:

```http
PATCH /supervision-requests/{id}
Content-Type: application/json

{"status":"cancelled"}
```

### Notification settings

Un paciente autenticado no necesita enviar `patient_uid` ni `user_uid`; ambos se
infieren del token. Si se envian, deben coincidir con el UID autenticado.

`POST /notification-settings` es idempotente, usa el UID del paciente como ID
del documento y actualiza la configuracion existente. Los alias
`daily_note_*` y `daily_check_in_*` se sincronizan en entrada y salida. Un
supervisor no puede listar, crear, consultar, modificar ni eliminar esta
configuracion remota.

### Auditoria de privacidad de POST /ai/supervisor-chat

La ruta exige `firebase.auth`, `role=supervisor`, `authorized=true`,
`verified=true`, `status=active` y `throttle:10,1`.

Antes de llamar al proveedor se filtran pacientes por relacion aceptada,
`wants_supervision=true`, consentimiento activo, `explicit_consent=true`,
ausencia de `revoked_at` y scope `ai_chat_summary`.

El JSON HTTP enviado al proveedor contiene exclusivamente:

```json
{
  "model": "modelo_configurado",
  "messages": [
    {
      "role": "system",
      "content": "Prompt de seguridad RehabiAnex"
    },
    {
      "role": "user",
      "content": "Pregunta sanitizada y contexto JSON minimizado"
    }
  ],
  "temperature": 0.2,
  "max_tokens": 700
}
```

La pregunta se sanitiza para retirar correos, telefonos, nombres/nicknames/UID
de pacientes conocidos, identificadores largos tipo UID y direcciones marcadas
como calle, avenida, boulevard, carretera, privada, domicilio, colonia o
fraccionamiento.

El contexto de usuario solo puede contener:

```text
app
privacy_rule
authorized_patients_count
notes_count
patients[].patient_ref
patients[].recovery_days
recent_notes[].patient_ref
recent_notes[].mood_score
recent_notes[].anxiety_level
recent_notes[].craving_level
recent_notes[].energy_level
recent_notes[].sleep_quality
recent_notes[].had_relapse
recent_notes[].ai_risk_score
recent_notes[].ai_risk_level
recent_notes[].days_ago
```

No se envian nombres, UID, correo, telefono, contactos, `consent_text`,
direcciones, texto libre de notas, detonantes, fechas exactas ni payloads
Firestore completos. Las referencias `P-001`, `P-002` se regeneran para cada
request y no incluyen una tabla de reidentificacion en el payload.

El backend no registra el request enviado al proveedor. Un fallo al guardar el
historial registra exclusivamente la clase de la excepcion, nunca pregunta,
contexto, respuesta, token o API key.

Timeout, rate limit del proveedor, configuracion ausente, proveedor no
disponible y respuesta vacia producen una respuesta local segura con HTTP 200,
`mode=fallback_local` y un `provider_error.code` estable sin secretos.

### Proyeccion estable de nombre de paciente

Aplica a `POST /auth/register`, `POST /auth/login`, `GET /auth/me`,
`GET /patients/{patient_uid}` y `PATCH /patients/{patient_uid}`.

El perfil propio conserva `full_name` real para edición y compatibilidad, pero
también devuelve siempre:

```json
{
  "full_name": "Nombre real",
  "display_name": "Paciente anónimo",
  "safe_display_name": "Paciente anónimo",
  "is_anonymous": true,
  "privacy_mode": true
}
```

Regla oficial de cliente: Android debe usar `safe_display_name` para saludos y
`display_name` como alias compatible. No debe usar `full_name` como texto
visible cuando `is_anonymous=true` o `privacy_mode=true`.

En `auth`, `display_name` y `safe_display_name` usan la misma proyección segura,
aunque Firebase Auth conserve el nombre registrado. `auth.full_name` se
mantiene como alias legacy del nombre real.

Si ambos flags son `false`, `display_name` y `safe_display_name` contienen el
nombre normal, con fallback al apodo. Para un supervisor, el backend establece
`full_name=null` cuando el paciente está en modo privado y solo entrega el apodo
permitido o una referencia segura en los dos campos visibles.

### Resúmenes mínimos de navegación supervisor/admin

`GET /supervisors/{uid}/dashboard-summary` requiere token Firebase, que `{uid}`
coincida con la sesión y un supervisor con `authorized=true`, `verified=true` y
`status=active`. Tiene límite `30` requests por minuto.

```json
{
  "ok": true,
  "supervisor_uid": "uid",
  "profile": {
    "display_name": "Dra. Laura",
    "supervisor_type": "clinical_psychologist",
    "supervisor_code": "RA-XXXXXXXX",
    "authorized": true,
    "verified": true,
    "status": "active",
    "updated_at": "2026-08-05T10:00:00-06:00"
  },
  "summary": {
    "patients_count": 0,
    "pending_requests_count": 0,
    "open_interventions_count": 0
  }
}
```

`patients_count` incluye solo relaciones aceptadas con consentimiento explícito
activo. `open_interventions_count` incluye estados `open` o `in_progress`, sin
soft delete, y únicamente para esos pacientes todavía autorizados. Una pausa,
revocación o desvinculación excluye inmediatamente paciente e intervenciones.

`GET /admin/dashboard-summary` requiere token Firebase, rol `admin` y perfil con
`status=active`. Tiene límite `30` requests por minuto.

```json
{
  "ok": true,
  "admin_uid": "uid",
  "summary": {
    "pending_supervisors": 0,
    "active_supervisors": 0,
    "suspended_supervisors": 0
  }
}
```

Ninguno de los resúmenes entrega pacientes, nombres de pacientes, notas,
teléfonos, correos, consentimientos, texto libre, Chat IA o tokens. Los errores
posibles son `401`, `403` y `429` con la envolvente oficial.

Los perfiles propios y conteos mínimos pueden conservarse en caché local
cifrada con vigencia corta. Son ayudas de navegación, no estado autoritativo.
Acciones administrativas, solicitudes, intervenciones y cualquier dato de
terceros son siempre online. Datos de pacientes vistos por supervisor nunca se
persisten en Room.
