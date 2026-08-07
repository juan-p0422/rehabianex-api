# Auditoría de rendimiento de navegación Android

Fecha: 2026-08-05.

## Alcance y evidencia disponible

Se revisó `storage/logs/laravel.log` sin imprimir líneas, headers, cuerpos,
identificadores ni secretos. El archivo contiene 10,581 encabezados de registros
de aplicación, pero no contiene trazas estructuradas de acceso HTTP para:

- `/api/auth/me`;
- `/api/patients/{uid}/notes`;
- `/api/consents`;
- `/api/supervision-requests`;
- `/api/supervisors/{uid}/patients`;
- `/api/ai/supervisor-chat`;
- los endpoints `dashboard-summary`.

Por ello no es posible afirmar cuál endpoint fue llamado más veces durante una
navegación Android ya realizada. El log Laravel actual registra errores y
eventos de aplicación, no métricas de requests. Una mención textual o un stack
trace tampoco debe confundirse con una llamada HTTP.

## Prioridad por costo si Android duplica llamadas

| Prioridad | Endpoint | Costo backend observado | Recomendación Android |
|---|---|---|---|
| 1 | `POST /ai/supervisor-chat` | Valida supervisor, recorre pacientes, verifica consentimiento por paciente, consulta notas por paciente y puede invocar proveedor externo | Solo al enviar pregunta; bloquear doble tap, no llamar al abrir/recomponer pantalla y no reintentar automáticamente una respuesta incierta |
| 2 | `GET /supervisors/{uid}/patients` | Lista relaciones y revalida paciente/consentimiento por elemento; patrón N+1 | Solo al abrir/refrescar lista; Home debe usar `dashboard-summary` |
| 3 | `GET /patients/{uid}/notes` | Descarga todas las notas y ordena en memoria; para supervisor repite validaciones de relación/consentimiento | Una llamada por entrada/refresh; no usar para obtener solo conteos; futura paginación si crece el historial |
| 4 | `GET /auth/me` | Middleware verifica token, revocación y perfil; el controlador vuelve a leer perfil y calcula resumen de consentimientos | Una vez al bootstrap/refresh de sesión, no por cada pantalla o recomposición |
| 5 | `GET /consents` | Refresh acotado a 50 por defecto, máximo 100; el supervisor puede repetir validación por elemento | Network-first cuando se abre o cambia consentimiento; evitar polling |
| 6 | `GET /supervision-requests` | Refresh acotado a 50 por defecto, máximo 100; puede repetir validaciones por elemento | Cargar al abrir bandeja o tras una mutación; evitar combinarlo con Home solo para contar |

Todas las rutas autenticadas pagan además la validación Firebase y las lecturas
de sesión/revocación del middleware. Una llamada duplicada no es gratuita aunque
su payload final sea pequeño.

## Resúmenes livianos

Ya existen:

- `GET /api/supervisors/{uid}/dashboard-summary`;
- `GET /api/admin/dashboard-summary`.

Estos endpoints devuelven perfil propio mínimo y conteos, usan proyección
Firestore y no retornan pacientes, notas, teléfonos, correos, consentimientos,
texto libre ni IA. Android debe utilizarlos para Home en lugar de descargar
listas completas.

No se justifica otro endpoint summary con la evidencia actual. Un resumen de
paciente solo debería evaluarse si telemetría sanitizada demuestra que Home está
descargando notas, consentimientos o solicitudes únicamente para construir
contadores.

## Rate limits reales

| Endpoint | Límite explícito | Evaluación |
|---|---:|---|
| `POST /api/ai/supervisor-chat` | 10/minuto | Suficiente para interacción humana normal; duplicados/reintentos automáticos pueden causar `429` y costo externo |
| `GET /api/supervisors/{uid}/dashboard-summary` | 30/minuto | Suficiente para entrada y refresh de Home; polling menor a dos segundos es un bug de cliente |
| `GET /api/admin/dashboard-summary` | 30/minuto | Suficiente para navegación normal |
| `GET /auth/me` | Sin límite específico | No bloqueará navegación normal, pero duplicados elevan lecturas Firebase |
| Notas, consentimientos, solicitudes y pacientes supervisados | Sin límite específico | No existe riesgo actual de falso `429`; sí de costo y carga por llamadas repetidas |

Los límites públicos de login/refresh son independientes. No se recomienda
aumentar el límite de Chat IA para ocultar llamadas duplicadas del cliente.

## Telemetría segura recomendada

Para medir una sesión futura se propone instrumentación local/staging, apagada
por defecto en producción, que registre exclusivamente:

```text
event=api_request_metric
method=GET|POST|PATCH|DELETE
route_template=api/patients/{id}/notes
status=200|4xx|5xx
duration_ms=entero
timestamp_bucket=minuto
```

Debe usarse la plantilla de ruta, nunca el URL resuelto con UID. No se deben
registrar query strings, body, response, headers, bearer token, refresh token,
cookies, correo, teléfono, IP, UID, pregunta IA, notas ni identificadores de
paciente. Las métricas útiles son conteo por ruta/minuto, p50/p95 de duración y
número de `429`.

No se implementó middleware de telemetría en esta auditoría: primero debe
aprobarse si será solo local o también staging, su retención y mecanismo de
activación. El log existente no permite reconstruir retroactivamente la
navegación.

## Riesgos y acciones recomendadas

1. Chat IA presenta el mayor costo y riesgo de doble facturación. Android debe
   usar estado `loading`, debounce y un identificador de operación si en el
   futuro se agrega idempotencia backend.
2. Notas carece de paginación en la ruta oficial y puede crecer sin límite.
   Conviene diseñar `limit` más cursor antes de grandes volúmenes, manteniendo
   compatibilidad Android.
3. Los listados de supervisor y Chat IA contienen patrones N+1 de Firestore.
   Deben optimizarse solo con medición real y pruebas de permisos para no
   debilitar consentimiento.
4. `auth/me` relee el perfil ya cargado por middleware. Es una optimización
   pequeña candidata, pero requiere conservar el resumen de consentimiento y
   probar cambios concurrentes de perfil.
5. Las rutas sin throttle no bloquean navegación, pero tampoco contienen un
   cliente en loop. Añadir límites debe hacerse después de medir frecuencia
   legítima y coordinar política de retry/backoff con Android.
6. No deben usarse Telescope, access logs con URL completa o logging de requests
   sin redacción, porque pueden capturar UIDs, tokens y contenido clínico.
