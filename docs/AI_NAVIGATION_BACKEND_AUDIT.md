# Auditoría backend del bug de navegación del Chat IA

Fecha: 2026-08-04.

## Conclusión

El endpoint backend completa correctamente los tres caminos de respuesta y no
envía instrucciones ni estado de navegación. El bug de navegación reportado es
exclusivamente Android; **no requiere un cambio de lógica backend**.

La verificación se ejecutó contra una instancia QA en
`http://127.0.0.1:8010/api`. El puerto 8000 estaba ocupado por otra instancia
local y no se detuvo ni se reutilizó. Firebase operó únicamente con Auth y
Firestore Emulator bajo `demo-rehabianex`.

Para evitar llamadas externas, `mode=ai` se probó con un proveedor HTTP
sintético en `127.0.0.1:8099`. El fallback se provocó deteniendo ese stub. No se
leyó ni utilizó la API key configurada en `.env`.

## Resultados

| Caso | Request | HTTP | `mode` | Contrato | Resultado |
|---|---|---:|---|---|---|
| Respuesta local | `POST /api/ai/supervisor-chat`, `mode=local` | 200 | `local` | estable | PASS |
| Proveedor disponible | `POST /api/ai/supervisor-chat`, `mode=ai` | 200 | `ai` | estable | PASS |
| Proveedor no disponible | `POST /api/ai/supervisor-chat`, `mode=ai` | 200 | `fallback_local` | estable | PASS |

La corrida aislada verificó además registro/autorización/login del supervisor y
logout, sin incluir pacientes reales. Resultado del runner: 13/13 verificaciones
aprobadas.

## Respuestas relevantes

### `mode=local`

Incluye:

```json
{
  "ok": true,
  "mode": "local",
  "answer": "...",
  "context": {
    "authorized_patients_count": 0,
    "notes_count": 0
  },
  "stored": false,
  "session_id": null,
  "history_persistence": "disabled"
}
```

### `mode=ai`

Incluye `ok=true`, `mode=ai`, `answer`, `context`, `model` y `usage` segura.
`provider_error` no aparece cuando el proveedor responde correctamente.

### Fallback

```json
{
  "ok": true,
  "mode": "fallback_local",
  "message": "El proveedor de IA no está disponible. Se usó una respuesta local segura.",
  "answer": "...",
  "provider_error": {
    "code": "provider_timeout",
    "retryable": true
  },
  "stored": false,
  "session_id": null,
  "history_persistence": "disabled"
}
```

La clasificación exacta puede ser `configuration_missing`, `provider_timeout`,
`provider_rate_limited`, `provider_unavailable` o `empty_response`. Android no
debe mostrar detalles internos adicionales.

## Relación con navegación Android

La respuesta no contiene ni necesita:

- `route`;
- `destination`;
- `screen`;
- `deeplink`;
- comandos de navegación;
- back-stack o identificadores de destino Android.

El backend solo resuelve autenticación, autorización, contexto permitido y
generación de la respuesta. Android controla NavController, back-stack, estado
de pantalla, carga y errores visuales. Por tanto, una navegación incorrecta al
enviar o recibir un mensaje debe corregirse en la capa Android.

## Riesgos y consideraciones

1. Fallback devuelve HTTP 200 intencionalmente. Android debe revisar `mode` y no
   asumir que `200` siempre significa proveedor externo exitoso.
2. `provider_error` es opcional y solo aparece en fallback; el parser Android
   debe admitir su ausencia.
3. `message` puede faltar en `local` y `ai` porque `answer` es el campo funcional
   estable. Android debe aceptar `answer` o `message` según el contrato.
4. `session_id` es `null` y el historial está desactivado. Android no debe usar
   una sesión persistente como requisito de navegación.
5. El backend devuelve la pregunta ya sanitizada por compatibilidad. No es un
   dato de navegación; Android debería conservar su propio estado de UI.

## Evidencia reproducible

- `scripts/local_ai_endpoint_audit.ps1`: prepara identidades sintéticas y valida
  los tres modos y la ausencia de claves de navegación.
- `scripts/ai-provider-stub.php`: proveedor local sin registro de request body.
- `scripts/start-local-ai-audit-server.ps1`: arranque QA aislado en puerto 8010.

No se modificaron `SupervisorAIController`, `AIService`, rutas ni respuestas del
backend como resultado de esta auditoría.
