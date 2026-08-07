# Idempotencia de notas emocionales

Fecha de implementación: 2026-08-03.

## Alcance

La idempotencia aplica exclusivamente a:

```http
POST /api/patients/{patient_uid}/notes
```

Android debe crear una clave por mutación de su outbox y conservarla durante
todos los reintentos. `client_mutation_id` es la forma canónica; el header
`Idempotency-Key` es un alias. Si ambos aparecen y difieren, la API responde
`422` sin escribir en Firestore.

## Aislamiento y almacenamiento

El ID Firestore se deriva mediante SHA-256 de `patient_uid`, un separador y el
mutation ID. Por ello:

- dos reintentos del mismo paciente apuntan al mismo documento;
- la misma clave en dos pacientes genera documentos distintos;
- un paciente no puede obtener la nota de otro usando su mutation ID;
- el mutation ID no se usa directamente como ruta o ID de documento.

La nota conserva `client_mutation_id` para auditoría de sincronización. No se
crea una colección de claves independiente.

## Retención

La clave se conserva durante toda la vida de la nota. El DELETE actual es
lógico, por lo que el tombstone continúa reservando la clave sin TTL. Si en el
futuro existe purga física, su retención debe superar la ventana máxima en que
Android pueda conservar una mutación pendiente; de otro modo un reintento muy
antiguo podría crear nuevamente la nota.

## Semántica de respuesta

- primera creación: `201`, `idempotent_replay=false`;
- reintento reconocido: `200`, `idempotent_replay=true` y la misma nota;
- payload inválido: `422`, sin reservar clave;
- conflicto interno de clave/documento: `409`;
- sin clave: comportamiento legacy, nueva nota por cada POST válido.

## Concurrencia

El ID determinista garantiza que Firestore no pueda contener dos documentos
para la misma clave compuesta. Este cambio mínimo no implementa comparación de
hash del payload ni una transacción de contenido: dos solicitudes simultáneas
con la misma clave pero cuerpos diferentes convergen al mismo documento y el
servidor debe considerarse autoritativo tras refrescar.
