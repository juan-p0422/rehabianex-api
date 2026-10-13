# Etapa 7: contrato de chatbot y consumo

## Alcance acordado

- Ruta estable: `POST /api/ai/supervisor-chat`.
- Autenticación Firebase y rol `supervisor`; el UID del body debe coincidir con
  el UID autenticado.
- `question`: texto no vacío, máximo 800 caracteres. No se eliminan espacios,
  acentos ni caracteres legítimos.
- `mode`: `ai` o `local`; por omisión `ai`.
- El acceso a cada paciente se valida antes de recuperar notas y exige vínculo,
  consentimiento activo y scope `ai_chat_summary`.

## Periodo y límites de datos

El resumen cubre los últimos siete días naturales, incluida la fecha actual, en
`America/Mexico_City`. La respuesta incorpora:

```json
{
  "context": {
    "authorized_patients_count": 1,
    "notes_count": 5,
    "period": {
      "start_date": "YYYY-MM-DD",
      "end_date": "YYYY-MM-DD",
      "timezone": "America/Mexico_City",
      "label": "Últimos 7 días naturales"
    },
    "data_limits": ["texto explicativo"]
  }
}
```

Como máximo se envían 20 registros autorreportados recientes. La ausencia de
registros no se interpreta como recaída ni como estado clínico. Las referencias
`P-001` son efímeras y seudonimizadas; no se presentan como anonimización.

## Consumo y errores

- 10 solicitudes por minuto por UID Firebase autenticado.
- 50 llamadas facturables al proveedor por supervisor y ventana móvil de 24
  horas; ambos valores son configurables.
- `mode=local`, validaciones y respuestas locales de seguridad clínica no
  consumen cuota del proveedor.
- Cada intento enviado al proveedor consume una unidad, incluso si el proveedor
  devuelve timeout o error. No hay reintentos automáticos.
- `usage` conserva el uso de tokens informado por el proveedor.
- `quota` es adicional y compatible: `limit`, `remaining`, `reset_at`, `window`.
- La cuota agotada devuelve `429`, `retry_after_seconds`, `quota` y
  `Retry-After`.
- Un fallo real del proveedor devuelve HTTP 200 con `mode=fallback_local` y un
  `provider_error.code` estable; Android debe identificarlo como respuesta
  local, no como respuesta del proveedor.

## Seguridad clínica

El backend rechaza localmente solicitudes explícitas de diagnóstico,
prescripción, dosis o decisiones terapéuticas antes de invocar al proveedor.
También inspecciona la salida y descarta instrucciones clínicas prohibidas. El
prompt sigue siendo una defensa adicional, no el único control.

## Compatibilidad Android

Los campos previos no cambian de nombre ni tipo. `quota`, `context.period`,
`context.data_limits`, `retry_after_seconds` y `safety_intervention` son campos
aditivos. Android debe tolerar su ausencia al comunicarse con versiones
anteriores del backend y debe bloquear doble envío mientras una petición está
en curso.
