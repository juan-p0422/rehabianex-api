# Etapa 5 — contrato de registro y seguimiento

Fecha de contraste: 2026-10-12. Zona de calendario del producto para la fecha
de sobriedad: `America/Mexico_City`.

## Notas emocionales

- Ruta principal Android: `POST /api/patients/{patient_uid}/notes`.
- Compatibilidad: `POST /api/patient-notes` conserva la forma existente, pero
  ahora exige `mood`, `mood_score`, `anxiety_level`, `craving_level` y
  `had_relapse` al crear.
- Las escalas aceptan enteros de 0 a 10. Cero es una respuesta; ausencia es
  `422`.
- `triggers` y `coping_actions` aceptan hasta 20 textos distintos, no vacíos,
  de máximo 120 caracteres. El texto de **Otro** se envía en esos arreglos, sin
  campo adicional y sin eliminar acentos o espacios internos.
- `client_mutation_id` o `Idempotency-Key` conservan la prevención de
  duplicados. El formulario solo debe limpiarse tras una respuesta confirmada
  `201` o un replay `200`; un error conserva los valores locales.

## Fecha de sobriedad

- Ruta: `PATCH /api/patients/{uid}`.
- Campo: `sobriety_start_date`, fecha ISO 8601 o `null`.
- El servidor rechaza con `422` una fecha posterior al día actual en
  `America/Mexico_City`.
- Android debe pedir confirmación e indicar que cambiarla recalcula progreso y
  próximo logro. No elimina notas, eventos ni hitos ya almacenados.

## Agenda y asistencia

- `status`: estado operativo `scheduled`, `completed` o `cancelled`.
- `attendance_reported`: autorreporte del paciente `true`, `false` o `null`.
- La creación de un evento inicializa el autorreporte en `null` y rechaza un
  valor anticipado con `422`.
- Solo el paciente propietario puede cambiar `attendance_reported` mediante
  `PATCH /api/agenda-events/{id}`. El servidor añade
  `attendance_reported_at` y `attendance_reported_by`; `null` los limpia.
- El supervisor puede leer el valor si mantiene el vínculo y el alcance
  `agenda_events`, pero no autorreportarlo por el paciente.
- Completar un evento no implica asistencia y reportar asistencia no completa
  el evento.

## Historial

No se añade un endpoint ni una métrica clínica nueva. Android filtra los
registros sincronizados por sus timestamps:

- semana: día visible y los seis días calendario anteriores en la zona del
  dispositivo;
- mes: mes calendario visible;
- la UI muestra inicio, fin y cantidad real de registros.

Siete registros no deben rotularse como una semana. Los promedios se presentan
como resumen personal autorreportado, no como eficacia clínica.

## Compatibilidad y errores

Las respuestas existentes mantienen sus envolventes. Los cambios son
aditivos, salvo que la ruta CRUD heredada deja de inventar ceros para escalas
omitidas. Errores relevantes: `401` sin sesión, `403` por propietario/rol o
alcance incorrecto, `404` inexistente y `422` por validación.
