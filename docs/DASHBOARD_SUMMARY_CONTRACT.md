# Resúmenes seguros de navegación supervisor/admin

Fecha: 2026-08-05.

## Auditoría previa

Antes de este cambio existían:

- `GET /auth/me`, apto para perfil propio;
- `GET /supervisors/{uid}/patients`, protegido pero devuelve proyecciones de
  terceros y no debe usarse como caché de Home;
- `GET /supervision-requests`, útil para operar solicitudes pero no para obtener
  únicamente un contador;
- `GET /admin/ping`, que solo confirma acceso;
- `GET /admin/supervisors?status=pending_review`, que devuelve correo y teléfono
  administrativo y era excesivo si Home solo necesitaba un conteo.

No existía un endpoint liviano para ninguna de las dos Homes. No se encontró una
necesidad legítima de descargar notas para construir navegación.

Las consultas nuevas usan proyección Firestore (`select`) y recuperan solo UID
de relación, estado, consentimiento y marcas de borrado necesarias para contar.
El backend no descarga campos de notas, nombres de pacientes, correo, teléfono o
texto de intervenciones para construir estos resúmenes.

## GET /api/supervisors/{uid}/dashboard-summary

Headers:

```http
Authorization: Bearer {firebase_id_token}
Accept: application/json
```

Condiciones: `{uid}` debe coincidir con el token y la cuenta debe tener
`role=supervisor`, `authorized=true`, `verified=true`, `status=active`. Rate
limit: 30 requests por minuto.

Respuesta `200`:

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
    "patients_count": 1,
    "pending_requests_count": 2,
    "open_interventions_count": 1
  }
}
```

Semántica:

- `patients_count`: relación aceptada, `wants_supervision=true` y consentimiento
  explícito activo no revocado;
- `pending_requests_count`: solicitudes propias con `status=pending` y sin
  `deleted_at`;
- `open_interventions_count`: estados `open|in_progress`, sin `deleted_at`, y
  solo pacientes incluidos en `patients_count`.

Pausar/revocar consentimiento o desvincular excluye inmediatamente al paciente
y sus intervenciones del resumen. No se devuelve ningún identificador, nombre o
contenido de esos pacientes.

## GET /api/admin/dashboard-summary

Requiere token Firebase, `role=admin` y `status=active`. Rate limit: 30 requests
por minuto.

Respuesta `200`:

```json
{
  "ok": true,
  "admin_uid": "uid",
  "summary": {
    "pending_supervisors": 2,
    "active_supervisors": 4,
    "suspended_supervisors": 1
  }
}
```

El estado legacy `pending` se contabiliza como `pending_review`. Rechazados y
deshabilitados no se mezclan con los tres conteos oficiales.

## Exclusiones y errores

No se incluyen pacientes, nombres de pacientes, notas, correos, teléfonos,
consentimientos, texto libre, payload Chat IA ni tokens. Los errores usan la
envolvente oficial:

```json
{
  "ok": false,
  "message": "Mensaje legible para cliente",
  "errors": {}
}
```

- `401`: token faltante, inválido o revocado;
- `403`: rol incorrecto, UID ajeno, supervisor pendiente/suspendido o admin
  inactivo;
- `429`: límite excedido.

## Política de caché

Se permite cachear perfil propio y conteos mínimos en almacenamiento local
cifrado, con TTL máximo recomendado de cinco minutos. Solo sirven para dibujar
la navegación mientras se refresca.

Toda acción es online. No se cachean pacientes, solicitudes, intervenciones ni
datos clínicos de terceros. Un `401`, `403`, logout, cambio de cuenta o entrada a
foreground invalida o refresca el resumen.

## Cambios y riesgo residual

Se agregó `DashboardSummaryController`, dos rutas y cobertura automatizada. Los
conteos actuales se calculan sobre documentos filtrados y pueden no representar
un snapshot atómico si hay escrituras simultáneas. Si el volumen crece, se debe
migrar a aggregation queries o contadores server-side sin ampliar el payload.
