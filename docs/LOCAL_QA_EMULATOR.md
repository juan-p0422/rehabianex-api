# QA local aislado con Firebase Emulator

Fecha: 2026-08-04.

## Objetivo

Ejecutar el flujo HTTP completo sin escribir usuarios o documentos en Firebase
real. `firebase.qa.json` usa Auth en `127.0.0.1:9199` y Firestore en
`127.0.0.1:8180`; no modifica `firebase.json` de desarrollo.

`firestore.qa.rules` permite operaciones únicamente dentro del emulador local
para que el SDK de servidor pueda ejecutar el checklist. No sustituye
`firestore.rules`, no debe desplegarse y queda ligado al archivo
`firebase.qa.json` y al proyecto `demo-rehabianex`.

## Enrutamiento seguro

Cuando el proceso PHP define `FIREBASE_AUTH_EMULATOR_HOST`, las llamadas REST de
login, registro con sesión inicial, refresh e IdP se dirigen al emulador. Sin esa
variable se conservan las URLs HTTPS oficiales, por lo que Render no cambia.

El host aceptado por esta ruta local se restringe a `localhost` o `127.0.0.1`
con puerto. `FIRESTORE_EMULATOR_HOST` es interpretado por el SDK oficial de
Firestore.

Antes de este aislamiento, `FirebaseService` siempre entregaba la service
account configurada al SDK, aun con hosts de emulador activos. El cliente Auth
podía obtener un token OAuth real para autenticar una llamada dirigida al
emulador.

El comportamiento final separa ambos modos:

- producción, sin hosts de emulador: conserva la resolución y validación de
  credenciales existente;
- QA local: exige Auth y Firestore Emulator simultáneamente, ambos en loopback,
  y un proyecto cuyo identificador comience con `demo-`;
- QA local usa solo la credencial convencional `owner` del emulador y elimina
  `GOOGLE_APPLICATION_CREDENTIALS` dentro del proceso PHP antes de crear los
  clientes. No lee, materializa ni intercambia la service account real.

La credencial sintética se encapsula en `FirebaseService::emulatorFactory()` y
solo se activa tras esas validaciones. Una configuración parcial o no local
falla de forma cerrada.

Variables exclusivas del proceso QA:

```text
FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9199
FIRESTORE_EMULATOR_HOST=127.0.0.1:8180
FIREBASE_PROJECT_ID=demo-rehabianex
GCLOUD_PROJECT=demo-rehabianex
AI_API_KEY=qa-disabled-not-a-secret
AI_BASE_URL=http://127.0.0.1:9
```

Las dos variables IA son sintéticas y fuerzan el caso `fallback_local`; evitan
que el checklist contacte al proveedor configurado en `.env`.

No deben agregarse al `.env` usado para Render. No se imprimen service accounts,
API keys, contraseñas ni tokens del flujo.

## Ejecucion reproducible del API aislado

El helper `scripts/start-local-qa-api.ps1` inicia Laravel solo si Auth Emulator
y Firestore Emulator escuchan en loopback. No modifica `.env`; las variables
sinteticas existen unicamente dentro del proceso hijo.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File scripts/start-local-qa-api.ps1 -Port 8011

powershell -NoProfile -ExecutionPolicy Bypass `
  -File scripts/local_qa_checklist.ps1 `
  -BaseUrl http://127.0.0.1:8011/api

powershell -NoProfile -ExecutionPolicy Bypass `
  -File scripts/local_qa_exhaustive.ps1 `
  -BaseUrl http://127.0.0.1:8011/api
```

Los scripts solo muestran rutas normalizadas, codigos HTTP y aserciones. No
imprimen tokens, contrasenas, correos sinteticos, UIDs ni cuerpos sensibles.
Los throttles se comparten por IP local; para una corrida determinista debe
usarse una ventana limpia o dejar expirar los limites anteriores.

## Rollback

La rama de emulador de `FirebaseService` es local y reversible. En producción,
al no existir hosts de emulador, continúa el camino de credenciales anterior.
Para revertir el soporte QA se retiran `emulatorFactory()`, su validación
loopback/demo y el helper REST de Auth; no hay datos reales que recuperar.

Detener los procesos de PHP y Firebase Emulator elimina el entorno efímero. El
rollback de código consiste en revertir el helper `firebaseAuthUrl`, la entrada
de configuración y `firebase.qa.json`. No hay datos reales que recuperar.
