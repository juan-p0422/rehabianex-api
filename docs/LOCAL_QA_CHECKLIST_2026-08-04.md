# Checklist QA local — 2026-08-04

## Alcance y aislamiento

La ejecución se hizo contra `http://127.0.0.1:8000/api` con Firebase Auth y
Firestore Emulator, proyecto `demo-rehabianex`. No se consultó Render ni se
escribieron datos en Firebase real. Todas las identidades, teléfonos, mensajes
y eventos fueron sintéticos y se generaron en memoria.

El proveedor IA quedó deshabilitado en la ejecución final mediante una URL de
loopback no disponible. `mode=ai` ejercitó el fallback local con HTTP 200.

## Resultado final

| Caso | Endpoint | Esperado | Resultado |
|---|---|---:|---:|
| Health previo | `GET /health` | 200 | PASS |
| Registrar paciente | `POST /auth/register` | 201 | PASS |
| Registrar supervisor pendiente | `POST /auth/register` | 201 | PASS |
| Crear/login admin sintético | comando + `POST /auth/login` | 0/200 | PASS |
| Autorizar supervisor | `PATCH /admin/supervisors/{uid}/authorize` | 200 | PASS |
| Login paciente | `POST /auth/login` | 200 | PASS |
| Login supervisor | `POST /auth/login` | 200 | PASS |
| Perfil paciente | `GET /auth/me` | 200 | PASS |
| Perfil supervisor | `GET /auth/me` | 200 | PASS |
| Crear nota con mutation ID | `POST /patients/{patient_uid}/notes` | 201 | PASS |
| Repetir mutation ID | `POST /patients/{patient_uid}/notes` | 200, mismo ID | PASS |
| Crear contacto | `POST /support-contacts` | 201 | PASS |
| Crear evento | `POST /agenda-events` | 201 | PASS |
| Preferencias sin `patient_uid` | `POST /notification-settings` | 201/200 | PASS |
| Inferencia y alias `daily_note` | aserción de response | ambos presentes | PASS |
| Resolver código corto | `GET /supervisors/resolve?code={short_code}` | 200 | PASS |
| Crear solicitud link | `POST /supervision-requests` | 201 | PASS |
| Aceptar link | `PATCH /supervision-requests/{id}/respond` | 200 | PASS |
| Crear consentimiento activo | `POST /consents` | 201 | PASS |
| Pausar consentimiento | `PATCH /consents/{id}` | 200 | PASS |
| Reactivar consentimiento | `PATCH /consents/{id}` | 200 | PASS |
| Revocar consentimiento | `PATCH /consents/{id}` | 200 | PASS |
| Crear solicitud unlink | `POST /supervision-requests` | 201 | PASS |
| Aceptar unlink | `PATCH /supervision-requests/{id}/respond` | 200 | PASS |
| Acceso posterior del supervisor | `GET /patients/{patient_uid}` | 403 | PASS |
| Chat local | `POST /ai/supervisor-chat`, `mode=local` | 200/local | PASS |
| Chat IA aislado | `POST /ai/supervisor-chat`, `mode=ai` | 200/fallback_local | PASS |
| Logout paciente | `POST /auth/logout` | 200 | PASS |
| Logout supervisor | `POST /auth/logout` | 200 | PASS |
| Logout admin | `POST /auth/logout` | 200 | PASS |

Resultado de la herramienta reproducible: **33/33 verificaciones PASS**. La
diferencia entre los 30 renglones funcionales y las 33 verificaciones son las
aserciones explícitas de idempotencia, alias de notificaciones y modos de Chat.

## Comandos ejecutados

```powershell
# Firebase Emulator, con Java 21 de Android Studio y firebase.qa.json
pnpm dlx firebase-tools emulators:start `
  --only auth,firestore `
  --project demo-rehabianex `
  --config firebase.qa.json

# Laravel recibió solo variables del proceso. La URL IA fuerza fallback local.
php artisan serve --host=127.0.0.1 --port=8000

powershell -NoProfile -ExecutionPolicy Bypass `
  -File scripts/local_qa_checklist.ps1

php artisan test --compact tests/Unit/FirebaseEmulatorIsolationTest.php `
  tests/Unit/AuthEmulatorRoutingTest.php `
  tests/Unit/AuthResponseContractTest.php

php artisan test --compact
```

Resultados automatizados:

- pruebas focalizadas: 10 PASS, 39 assertions;
- suite completa: 159 PASS, 877 assertions, 1 skipped;
- sintaxis PHP de los archivos tocados: válida.

## Logs sanitizados

El script toma el offset del log antes del flujo y revisa solo el tramo nuevo.
Resultado final:

| Patrón sensible | Detectado |
|---|---:|
| ID token completo | No |
| Contraseña sintética | No |
| Correo sintético | No |
| Marcador de private key | No |

El servidor de desarrollo de Laravel sí muestra rutas solicitadas en su consola,
incluidos identificadores sintéticos usados como parámetros. No hubo valores
reales; para producción corresponde mantener la política de logs del proxy sin
query strings sensibles y con retención limitada.

## Incidencias y correcciones locales

1. El primer intento mostró que `FirebaseService` podía heredar la service
   account aun con emuladores. Se añadió un modo local fail-closed que exige los
   dos emuladores en loopback y proyecto `demo-*`, elimina
   `GOOGLE_APPLICATION_CREDENTIALS` en ese proceso y usa la credencial sintética
   `owner`.
2. Las reglas reales deniegan acceso directo, por diseño. Se creó
   `firestore.qa.rules`, abierta únicamente para el emulador local y nunca para
   despliegue; la autorización funcional continúa en la API.
3. Las llamadas REST de password/refresh de `AuthController` ahora reconocen el
   emulador solo en loopback. Sin la variable conservan las URLs oficiales.
4. Se corrigió el runner QA: generación de contraseña, lectura de
   `note.note_id`, anonimización de rutas impresas y verificación estricta de
   `fallback_local`.
5. En una ejecución intermedia, una variable IA vacía no anuló la configuración
   local y el proveedor configurado respondió. El payload contenía solo una
   pregunta genérica sintética y cero pacientes después del unlink; no contenía
   datos reales. La ejecución final usa URL IA de loopback y no contacta al
   proveedor.

## Riesgos restantes

- `FirebaseService::emulatorFactory()` inyecta la credencial sintética mediante
  una propiedad interna de la versión fijada de Kreait. La prueba unitaria
  detectará una incompatibilidad al actualizar esa dependencia.
- `firestore.qa.rules` es deliberadamente abierta: solo debe usarse en loopback,
  con `firebase.qa.json` y proyecto `demo-*`; nunca debe desplegarse.
- El checklist valida emuladores locales, no sustituye un smoke test separado en
  staging/Render con un proyecto Firebase de pruebas autorizado.
- El caso de proveedor IA exitoso no forma parte de esta corrida aislada. Aquí
  se validaron `local` y `fallback_local` sin salida externa.

## Reproducción en Thunder Client

Los bodies y el orden están codificados sin secretos en
`scripts/local_qa_checklist.ps1`. En Thunder Client se usa la misma secuencia,
`Content-Type: application/json` y `Authorization: Bearer {{token}}`; se guardan
como variables los UID/ID devueltos, sin exportar tokens al repositorio.
