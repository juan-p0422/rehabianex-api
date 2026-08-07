# QA backend local exhaustivo - 2026-08-05

## Alcance y aislamiento

La validacion se ejecuto exclusivamente contra Laravel en
`http://127.0.0.1:8011/api`, Firebase Auth Emulator en `127.0.0.1:9199` y
Firestore Emulator en `127.0.0.1:8180`, proyecto sintetico
`demo-rehabianex`. No se uso Render, Firebase real ni el proveedor IA real. No
se hizo push y no se leyeron, cambiaron o imprimieron secretos de `.env`.

El proceso IA uso un proveedor local deliberadamente inaccesible para validar
`fallback_local` sin trafico externo. Todos los usuarios, documentos y
credenciales de la prueba fueron aleatorios, sinteticos y efimeros.

## Comandos ejecutados

```powershell
php artisan optimize:clear
php artisan test
php artisan route:list --json
php artisan test --testsuite=Unit --compact
php artisan test --testsuite=Feature --compact
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local_qa_checklist.ps1 -BaseUrl http://127.0.0.1:8011/api
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local_qa_exhaustive.ps1 -BaseUrl http://127.0.0.1:8011/api
```

`route:list` registro 114 rutas; 71 pertenecen a auth, pacientes, notas,
supervision, consentimientos, admin, IA y notification-settings.

## Resultado automatizado

| Suite | Resultado | Evidencia |
|---|---:|---|
| Unit | OK | 92 pruebas, 388 aserciones, exit 0 |
| Feature | OK | 84 pruebas, 630 aserciones, exit 0 |
| Completa | Pruebas OK / proceso anomalo | 176 pasaron, 1018 aserciones, 1 smoke real omitido; Windows devolvio `-1073741819` al terminar |
| Firebase real | No ejecutada | `FirebaseRealSmokeTest` requiere `RUN_FIREBASE_SMOKE=true`; se omitio para cumplir "no usar datos reales" |

Las suites Unit y Feature separadas terminan correctamente. La salida anomala
de la suite agregada ocurre despues de imprimir todos los resultados verdes y
debe investigarse como problema del runner PHP/Windows antes de usar ese unico
exit code como puerta de CI.

## Matriz manual/API

| Flujo | Endpoint | Resultado esperado | Resultado real | OK/Falla | Evidencia sanitizada |
|---|---|---:|---:|---|---|
| Health | `GET /health` | 200 | 200 | OK | `ok=true`; respuesta minima |
| Auth | `POST /auth/register` paciente | 201 | 201 | OK | perfil y tokens presentes |
| Auth | `POST /auth/register` supervisor | 201 | 201 | OK | queda pendiente inicialmente |
| Auth | registro publico `role=admin` | 422 | 422 | OK | `ok=false` |
| Auth | login paciente/supervisor/admin local | 200 | 200 | OK | envolvente estable |
| Auth | `POST /auth/refresh` | 200 | 200 | OK | token renovado en `tokens.id_token` |
| Auth | `GET /auth/me` | 200 | 200 | OK | perfil del propietario |
| Auth | `POST /auth/logout` | 200 | 200 | OK | sesion cerrada |
| Auth | token usado despues de logout | 401 | 401 | OK | `ok=false` |
| Errores | JSON truncado en `POST /auth/login` | 400 | 422 | **Falla** | envolvente segura; codigo incorrecto |
| Errores | ruta protegida sin token | 401 | 401 | OK | `ok=false` |
| Errores | paciente en `/admin/ping` | 403 | 403 | OK | `ok=false` |
| Errores | documento inexistente | 404 | 404 | OK | `ok=false` |
| Errores | link duplicado | 409 | 409 | OK | conflicto estable |
| Errores | nota invalida | 422 | 422 | OK | validacion estable |
| Errores | limite de `/health` | 429 | 429 | OK | observado en la ventana compartida |
| Paciente | activar modo anonimo | 200 | 200 | OK | display seguro |
| Paciente | login posterior anonimo | 200 | 200 | OK | display anonimo persiste |
| Notas | primer POST con `client_mutation_id` | 201 | 201 | OK | documento creado |
| Notas | segundo POST con mismo mutation id | 200 | 200 | OK | devuelve el mismo ID |
| Offline | listar notas | 200 | 200 | OK | lista estable |
| Offline | `DELETE /patient-notes/{id}` | 200 | 200 | OK | soft delete |
| Offline | `include_deleted=true` | 200 | 200 | OK | tombstone sin cuerpo clinico |
| Contactos | `POST /support-contacts` | 201 | 201 | OK | checklist base 33/33 |
| Agenda | `POST /agenda-events` | 201 | 201 | OK | checklist base 33/33 |
| Notificaciones | POST sin `patient_uid` | 200/201 | 201 | OK | UID inferido del token |
| Notificaciones | alias `daily_note` / `daily_check_in` | iguales | iguales | OK | enabled y time sincronizados |
| Supervision | supervisor pendiente consulta pacientes | 403 | 403 | OK | acceso bloqueado |
| Admin | ping/listar/autorizar | 200 | 200 | OK | admin local activo |
| Admin | suspender supervisor | 200 y luego 403 | 200 y luego 403 | OK | bloqueo sensible |
| Admin | reactivar supervisor | 200 | 200 | OK | vuelve a activo |
| Supervision | resolver codigo corto | 200 | 200 | OK | respuesta segura |
| Supervision | crear/aceptar link | 201/200 | 201/200 | OK | relacion activa |
| Consentimiento | crear activo | 201 | 201 | OK | scopes persistidos |
| Consentimiento | pausar y consultar notas | 200/403 | 200/403 | OK | bloqueo inmediato |
| Consentimiento | reactivar y consultar notas | 200/200 | 200/200 | OK | acceso restaurado |
| Consentimiento | revocar y consultar notas | 200/403 | 200/403 | OK | bloqueo inmediato |
| Supervision | solicitar/aceptar unlink | 201/200 | 201/200 | OK | relacion limpiada |
| Supervision | limpieza tras unlink | campos limpios | campos limpios | OK | UID nulo, wants=false, status reset, ended_at presente |
| Supervision | acceso posterior | 403 | 403 | OK | supervisor bloqueado |
| Supervision | unlink directo | 200 | 200 | OK | endpoint operativo |
| Consentimiento | revocacion por unlink directo | revoked | revoked | OK | consentimiento relacionado revocado |
| IA aislada | `mode=local` | 200/local | 200/local | OK | checklist base aislado |
| IA aislada | `mode=ai`, proveedor inaccesible | 200/fallback_local | 200/fallback_local | OK | fallback estable |
| IA bajo saturacion | solicitud 11 de la ventana | 429 | 429 | OK | throttle `10/min` activo |

El checklist base termino 33/33. La pasada extensa registro 110/115: un fallo
contractual real y cuatro aserciones derivadas de dos respuestas 429 de Chat IA
cuando la ventana ya estaba saturada. Los mismos dos modos IA pasaron en la
corrida aislada; por ello se clasifican como evidencia del rate limit, no como
fallos del controlador.

## Estado Firestore antes/despues de desvincular

La evidencia se verifico por respuesta API y lectura posterior desde el
emulador, sin imprimir IDs:

| Campo/recurso | Antes | Despues |
|---|---|---|
| `patients.supervisor_uid` | UID sintetico | `null` |
| `patients.wants_supervision` | `true` | `false` |
| `patients.supervision_status` | `accepted` | `not_requested` |
| `patients.supervision_ended_at` | `null` | timestamp presente |
| consentimiento relacionado | activo/revocado por paciente | `revoked` |
| acceso del supervisor | permitido segun scope | HTTP 403 |

## Logs sanitizados

El segmento nuevo de `storage/logs/laravel.log` se comparo contra los valores
efimeros usados:

- token completo presente: no;
- contrasena presente: no;
- correo sintetico presente: no;
- marcador de private key presente: no.

No se adjuntan logs crudos porque las URLs del servidor de desarrollo pueden
contener IDs sinteticos. La matriz usa rutas normalizadas.

## Bugs y riesgos

### QA-LOCAL-001 - JSON malformado devuelve 422

- Severidad: media.
- Esperado: HTTP 400 con `ok=false`.
- Real: HTTP 422 con `ok=false`.
- Impacto: Android puede confundir un error de parser con validacion de campos.
- Correccion sugerida: detectar el error JSON antes de validar y responder 400
  mediante `ApiErrorResponse`; agregar prueba contra servidor HTTP real.

### QA-RUNNER-002 - exit anomalo de la suite agregada en Windows

- Severidad: media para CI local; sin impacto directo en Android/API.
- Evidencia: 176 pruebas pasan, pero el proceso agregado devuelve
  `-1073741819`. Unit y Feature por separado devuelven exit 0.
- Correccion sugerida: revisar PHP/extensiones y ejecutar CI en Linux.

### QA-ENV-003 - throttles compartidos entre corridas

- Severidad: baja; comportamiento esperado.
- Impacto: repetir el checklist en menos de un minuto puede producir 429.
- Recomendacion: entorno local limpio por corrida o esperar la ventana; no
  elevar limites de produccion para acomodar QA.

### QA-COVERAGE-004 - proveedor IA y Firebase reales no probados

- Severidad: alta antes de produccion, fuera del alcance seguro de esta corrida.
- Recomendacion: smoke test en staging aislado, con cuentas de prueba,
  autorizacion explicita y secretos gestionados por la plataforma.

## Cambios de esta sesion

- `scripts/start-local-qa-api.ps1`: arranque fail-closed contra emuladores.
- `scripts/local_qa_exhaustive.ps1`: arnes con evidencia sanitizada.
- `docs/LOCAL_QA_EMULATOR.md`: instrucciones reproducibles.
- `docs/LOCAL_QA_EXHAUSTIVE_2026-08-05.md`: este informe.

No se modifico logica de negocio, no se hizo commit y no se hizo push.
