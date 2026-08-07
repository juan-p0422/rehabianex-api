# RehabiAnex — estrategia backend offline-first

Estado: estrategia mínima compatible con Android/Room.  
Fecha de auditoría: 2026-08-01.

## Resultado de la auditoría

El CRUD Firestore asigna el ID específico de cada recurso y genera
`created_at`/`updated_at` en formato ISO-8601 al crear o modificar. Los DELETE
permitidos son lógicos y escriben `deleted_at`, `deleted_by` y `updated_at`.
Los documentos legacy o cargados fuera de la API pueden no cumplir esas
garantías y deben migrarse antes de habilitar sincronización incremental.

No existe `version`, ETag ni precondición de escritura. Actualmente una edición
aceptada usa **last-write-wins del servidor**. La API no puede detectar todavía
que Android editó una versión antigua.

| Recurso API / colección | ID | Relación | Timestamps por CRUD | Status | Soft delete útil | Caché recomendada |
|---|---|---|---|---|---|---|
| `patient-notes` / `patient_notes` | `note_id` | `patient_uid` | Sí | No obligatorio | Sí | Persistente, solo notas propias |
| `agenda-events` / `agenda_events` | `event_id` | `patient_uid`, `supervisor_uid` si aplica | Sí | Opcional | Sí | Persistente para agenda propia |
| `support-contacts` / `support_contacts` | `contact_id` | `patient_uid` | Sí | No | Sí | Persistente, solo contactos propios |
| `notification-settings` / `notification_settings` | `settings_id` = `patient_uid` | `patient_uid` | Sí | No | Sí | Persistente, solo paciente propietario |
| `consents` | `consent_id` | `patient_uid`, `supervisor_uid` | Sí | `active/paused/revoked` | No se recomienda borrar | Estado de seguridad; refrescar, no confiar solo en caché |
| `supervision-requests` / `supervision_requests` | `request_id` | ambos UID | Sí | `pending/accepted/rejected/cancelled` | DELETE legacy cancela lógicamente | Caché operativa, servidor autoritativo |
| `interventions` | `intervention_id` | ambos UID | Sí | Sí | Sí para actores autorizados | No persistir para uso sin validación reciente |
| `local-resources` / `local_resources` | `resource_id` | No aplica | Depende del seeder/origen | Depende del catálogo | Solo administración/origen | Persistente; catálogo no clínico |
| `achievements` | `achievement_id` | No aplica | Depende del seeder/origen | Depende del catálogo | Solo administración/origen | Persistente; catálogo |
| `patient-achievements` / `patient_achievements` | `patient_achievement_id` | `patient_uid` | Depende del proceso que lo otorga | Puede existir | No hay DELETE público | Persistente para el paciente propietario |

`patient_uid` o `supervisor_uid` no se agregan a catálogos donde no aplican.
`status` tampoco se inventa en recursos cuyo dominio no lo necesita.

## Contrato mínimo implementado

Todos los listados CRUD aceptan:

```http
GET /api/{resource}?limit=50&include_deleted=true
Authorization: Bearer {firebase_id_token}
```

- `limit`: 1 a 100; el valor por defecto es 50.
- `include_deleted=false`: comportamiento legacy; omite eliminados.
- `include_deleted=true`: incluye tombstones únicamente después de verificar
  permisos actuales.
- Un tombstone nunca incluye texto de notas, teléfono, contactos, `deleted_by`
  ni contenido sensible. Solo puede contener ID del recurso, UID de relación si
  aplica, `status`, `created_at`, `updated_at` y `deleted_at`.

La respuesta añade metadatos compatibles hacia atrás:

```json
{
  "ok": true,
  "count": 1,
  "limit": 50,
  "data": [],
  "sync": {
    "mode": "bounded_refresh",
    "cache_action": "upsert",
    "include_deleted": true,
    "incremental_supported": false,
    "server_time": "2026-08-01T12:00:00-06:00"
  }
}
```

Ejemplo de tombstone seguro:

```json
{
  "note_id": "note-1",
  "document_id": "note-1",
  "patient_uid": "uid-del-paciente-autenticado",
  "updated_at": "2026-08-01T11:00:00-06:00",
  "deleted_at": "2026-08-01T11:00:00-06:00"
}
```

Para notas, agenda y contactos propios, Android debe sincronizar mediante los
listados CRUD (`/patient-notes`, `/agenda-events`, `/support-contacts`) cuando
necesite tombstones. La ruta `/patients/{uid}/notes` sigue siendo la ruta de UI
recomendada, pero no constituye todavía un feed incremental.

## `updated_since`

La sincronización incremental completa **no está habilitada**. Si se envía
`updated_since`, la API responde `422` en vez de ignorarlo ambiguamente:

```json
{
  "ok": false,
  "message": "La sincronizacion incremental por updated_since aun no esta habilitada; realiza un refresh completo.",
  "errors": {}
}
```

Antes de habilitarla se requieren conjuntamente:

1. índices compuestos Firestore por propietario + `updated_at`;
2. orden estable por `updated_at` + ID;
3. cursor opaco para empates de timestamp y más de 100 cambios;
4. migración de documentos legacy sin timestamps;
5. pruebas contra Firebase real, no solo dobles.

Agregar solo `where(updated_at > fecha)` sería incompleto: podría perder
documentos con timestamps iguales o fallar por índices faltantes.

## Estrategia de caché Android/Room

### Frontera de responsabilidad de cifrado

El backend no crea, configura, inspecciona ni puede cifrar la base Room del
dispositivo. TLS protege el transporte hasta Android, pero no protege los datos
una vez escritos localmente. El cliente Android es responsable de:

- cifrar Room mediante una solución compatible y una clave protegida por
  Android Keystore;
- no guardar claves de cifrado, ID tokens o refresh tokens dentro de Room;
- impedir respaldos no cifrados de la base y sus archivos auxiliares;
- borrar la base o las filas asociadas al cerrar sesión, cambiar de cuenta o
  detectar una invalidación de seguridad;
- proteger también WAL, SHM, exportaciones, logs y copias de diagnóstico.

El backend solamente puede limitar campos, autenticar, autorizar y responder
`401/403`; no puede verificar remotamente que el dispositivo haya cifrado Room
ni eliminar datos ya descargados.

1. Al iniciar sesión, hacer refresh acotado por recurso con
   `include_deleted=true` y aplicar la respuesta en una transacción Room.
2. Usar el ID específico como clave primaria y `updated_at` como dato de
   observación, no como control optimista.
3. Si llega `deleted_at`, eliminar el contenido local o conservar solo el
   tombstone local.
4. `cache_action=upsert` significa que Android no debe borrar registros locales
   solo porque no aparezcan en la página: todavía no existe cursor para probar
   que la lista terminó.
5. Las notas propias en `POST /patients/{patient_uid}/notes` aceptan oficialmente
   `client_mutation_id` o `Idempotency-Key`. Android puede reintentar ese POST:
   una repetición devuelve la misma nota con `idempotent_replay=true`. Los demás
   recursos POST todavía no tienen idempotencia oficial y no deben reintentarse
   automáticamente sin confirmación.
6. Tras POST/PATCH/DELETE exitoso, reemplazar la fila local con `data` o con el
   ID/timestamp devuelto por servidor.
7. Ante `409`, descartar la mutación automática, refrescar el recurso y pedir
   resolución del usuario cuando haya texto editable.
8. Ante `403`, borrar inmediatamente la caché supervisada relacionada y no
   mostrar datos stale.
9. Ante `401`, bloquear la visualización sensible hasta renovar sesión.
10. Ejecutar refresh al volver a foreground, tras reconexión y mediante gesto
   manual. `server_time` no debe sustituir un cursor futuro.

### Política de conflictos

- Perfil, notas propias, agenda y contactos: hoy **last-write-wins** si el
  servidor acepta PATCH. Android debe refrescar después de cada escritura.
- Consentimientos, solicitudes, intervenciones y desvinculación: **servidor
  autoritativo**; estados incompatibles deben producir `409`.
- Catálogos y logros: **preferir servidor**; no editar offline.
- Evolución recomendada: `version` entero o `If-Match`/ETag con `409` para
  edición optimista. No se agregó ahora para evitar una migración contractual.

## Privacidad e invalidación

Pueden persistirse offline únicamente para su propietario: notas propias,
agenda propia, contactos propios, configuración de notificaciones y logros del
propio paciente. Los catálogos `local-resources` y `achievements` también son
aptos para persistencia. Todo dato propio sensible debe permanecer cifrado en
reposo por Android.

No deben almacenarse en caché persistente ni mostrarse offline:

- notas de pacientes supervisados;
- contactos o agenda supervisados;
- detalle de un paciente supervisado;
- intervenciones supervisadas;
- respuestas, contexto o historial del Chat IA.

Esos datos pueden mantenerse sólo en memoria durante la pantalla activa y deben
descartarse al salir, ir a background, recibir `401/403`, revocar/pausar
consentimiento o desvincular. Un TTL no convierte la persistencia supervisada en
segura para esta versión.

Una revocación, pausa o desvinculación puede ocurrir mientras el dispositivo
está desconectado. El backend impide nuevas lecturas, pero no puede borrar por
sí mismo una base Room ya descargada. Android debe cifrar almacenamiento
sensible propio y purgar cualquier dato supervisado que versiones anteriores
hayan persistido ante cualquier `403`, cambio de usuario, logout, revocación
observada o desvinculación.

## Adecuación de respuestas para caché propia

La auditoría confirma que los endpoints del paciente propietario entregan los
datos mínimos funcionales para upsert sin depender de datos supervisados:

| Flujo propio | Datos necesarios para Room | Datos excluidos o no requeridos |
|---|---|---|
| Perfil (`/auth/me`, `/patients/{uid}`) | UID estable, perfil visible, estado de vinculación y `updated_at` cuando existe | No necesita perfiles completos de supervisor ni pacientes ajenos |
| Notas | `note_id`, propietario, contenido propio, escalas, riesgo calculado, `client_mutation_id`, timestamps y tombstone | La proyección de supervisor no incluye la clave de mutación |
| Agenda | `event_id`, propietario, horario, estado, contenido y timestamps | No requiere consentimiento o perfiles completos |
| Contactos | `contact_id`, propietario, datos del contacto, prioridad y timestamps | No se incorpora a Chat IA ni a respuestas de otros scopes |
| Notificaciones | ID determinista del paciente, preferencias, alias compatibles, zona horaria y timestamps | Supervisor no tiene acceso remoto |
| Logros y recursos locales | ID estable, estado/progreso o datos de catálogo | No contienen notas, contactos o contexto IA |

Los listados agregan exclusivamente metadatos de sincronización (`limit`,
`sync`, `server_time`) y, cuando se solicita, tombstones minimizados. Android no
necesita persistir la envolvente ni `server_time`; debe guardar solamente las
filas de `data` y el estado local de su sincronización.

### Snapshot canónico de perfil propio

`GET /auth/me`, `GET /patients/{patient_uid}` y la respuesta de
`PATCH /patients/{patient_uid}` garantizan para el propietario:

```text
uid, full_name, nickname, privacy_mode, is_anonymous,
sobriety_start_date, photo_url, primary_risks, updated_at,
collection, document_id
```

`document_id` coincide con `uid` y `collection` es `patients`. Las escrituras
del backend actualizan `updated_at`. Para un documento legacy sin `updated_at`,
la respuesta usa `created_at`; si tampoco existe, entrega `null`. Android debe
considerar ese caso no versionado, guardarlo sólo provisionalmente y refrescar.

Esta normalización se aplica después de confirmar que el actor es el paciente
propietario. No amplía la proyección de un supervisor y no autoriza que datos
supervisados se almacenen en Room.

## Navegación rápida de supervisor y admin

Los endpoints `GET /supervisors/{uid}/dashboard-summary` y
`GET /admin/dashboard-summary` son proyecciones mínimas de navegación. Solo
contienen perfil propio no sensible y conteos agregados; nunca contienen
entidades de pacientes, notas, contactos, consentimientos, texto libre o IA.

Android puede conservar el perfil propio y estos conteos en almacenamiento
cifrado con TTL corto (recomendado: máximo cinco minutos). La caché solo permite
dibujar la Home: no autoriza acciones, no reemplaza un refresh y no debe
interpretarse como estado clínico offline-first.

Todas las acciones y la apertura de datos de terceros siguen siendo online. Al
volver a foreground, antes de una acción, y ante `401` o `403`, Android debe
refrescar o invalidar el resumen. Los listados de pacientes, solicitudes e
intervenciones no se derivan ni restauran desde estos conteos.

## Riesgos pendientes

- No hay paginación por cursor; `limit` máximo 100 impide considerar el listado
  actual como snapshot completo para usuarios con más registros.
- La idempotencia POST sólo existe para crear notas propias; falta extenderla a
  agenda, contactos y otros recursos. Tampoco hay control optimista por versión.
- `updated_at` es confiable solo para escrituras realizadas por este backend.
- Falta smoke test de esta estrategia contra Firebase real y sus índices.
- Los conteos de dashboard se calculan leyendo documentos filtrados y no son una
  transacción consistente entre colecciones; a escala alta convendrá usar
  aggregation queries o contadores mantenidos en servidor.
- Un dispositivo totalmente offline no puede conocer una revocación reciente;
  este riesgo debe mitigarse en Android con TTL, cifrado y revalidación.
