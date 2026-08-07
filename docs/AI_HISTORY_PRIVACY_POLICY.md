# Política de privacidad del historial IA

Fecha de decisión: 2026-08-03.

## Decisión vigente

Android no consume historial IA persistente. Por minimización de datos, el
backend no guarda nuevas sesiones ni mensajes generados por
`POST /api/ai/supervisor-chat`.

La política aplica por igual a `mode=local`, `mode=ai` y
`mode=fallback_local`:

- no se escribe en `ai_chat_sessions`;
- no se escribe en `ai_chat_messages`;
- no se guarda la pregunta;
- no se guarda la respuesta;
- no se guardan UID, nombres, correos, teléfonos o contactos del paciente;
- no se genera identificador de sesión persistente.

La respuesta informa `stored=false`, `session_id=null` y
`history_persistence=disabled`.

## Datos procesados sin persistencia

Para modo IA, el proveedor recibe durante la solicitud:

- pregunta sanitizada;
- referencias efímeras `P-001`, `P-002`;
- días de recuperación;
- puntuaciones estructuradas de estado y riesgo;
- fechas relativas en días;
- prompt de seguridad.

La sanitización elimina patrones de correo, teléfono, dirección,
identificadores largos, UID/nombre/nickname de pacientes autorizados conocidos y
declaraciones básicas como `se llama` o `nombre del paciente`.

El filtrado por patrones reduce riesgo, pero no garantiza detectar cualquier
nombre o dato personal escrito de forma libre. Android debe advertir al
supervisor que no escriba identificadores personales en la pregunta.

## Historial legado

Las rutas de lectura de sesiones y mensajes responden `403`, incluso para el
supervisor propietario. Esto evita que contenido histórico posiblemente
identificable siga circulando por la API.

Este cambio no borra documentos reales. Antes de producción debe inventariarse
el contenido existente en un entorno autorizado y elegir una operación
controlada de exportación legal, redacción o eliminación. No se define TTL para
nuevos mensajes porque ya no se crean.

## Reintroducción futura

Persistir historial requerirá una decisión legal explícita, base jurídica,
retención/TTL, borrado verificable, cifrado, esquema sin contenido libre,
auditoría de acceso y pruebas de aislamiento por propietario. No debe
reactivarse solamente habilitando las rutas legacy.

## Rollback

El rollback técnico consiste en revertir `disabledHistoryState` y el bloqueo en
`FirestoreAccessService`. No se recomienda sin aprobar previamente la política
de retención y privacidad descrita arriba.
