# Auditoría de proveedores de autenticación

Fecha: 2026-08-05.

## Conclusión

Google Sign-In no es dependencia del backend ni de los flujos funcionales de
paciente, supervisor o administrador. Android puede retirar su botón, SDK y
navegación Google sin modificar el contrato obligatorio de la API.

El flujo oficial es:

1. `POST /api/auth/register` crea una cuenta Firebase Email/Password, asigna
   `role=patient|supervisor`, persiste perfil y aceptación legal, y devuelve ID
   token/refresh token;
2. `POST /api/auth/login` intercambia correo/contraseña por tokens y perfil;
3. `POST /api/auth/refresh` renueva tokens usando Firebase Secure Token;
4. `GET /api/auth/me` obtiene perfil con un ID token válido;
5. `POST /api/auth/logout` revoca refresh tokens y registra solamente el hash
   del ID token actual.

Ninguno llama a `google()`, `finishFederatedLogin()` o `signInWithIdp`.

## Matriz de endpoints

| Endpoint | Estado | Google requerido | Protección |
|---|---|---:|---|
| `POST /api/auth/register` | Oficial Android | No | Público, 5/minuto, privacidad versionada obligatoria |
| `POST /api/auth/login` | Oficial Android | No | Público, 10/minuto |
| `POST /api/auth/refresh` | Oficial Android | No | Público, 10/minuto, requiere refresh token |
| `GET /api/auth/me` | Oficial Android | No | Token Firebase válido |
| `POST /api/auth/logout` | Oficial Android | No | Token Firebase válido |
| `POST /api/auth/google` | Legacy/experimental | Sí | Público, 10/minuto; fuera del contrato Android |
| `POST /api/auth/firebase` | Legacy/experimental | No, acepta ID token externo | Público, 10/minuto; fuera del contrato Android |

## Rutas federadas existentes

`POST /api/auth/google` acepta `id_token` o `access_token` de Google, llama a
Firebase `signInWithIdp` y sincroniza un perfil. `POST /api/auth/firebase`
verifica un ID token ya emitido por Firebase y también puede sincronizar perfil.

Se conservaron para no romper posibles clientes legacy desconocidos. No se
eliminaron ni renombraron en esta auditoría y no aparecen entre los endpoints
obligatorios probados para Android.

## Riesgos

1. Las rutas federadas pueden crear/sincronizar un perfil sin pasar por la
   aceptación versionada de aviso de privacidad que exige `/auth/register`.
2. No existe una prueba funcional aislada de vinculación entre una cuenta
   Email/Password existente y una identidad Google con el mismo correo.
3. Mantener rutas públicas no utilizadas aumenta superficie de validación y
   dependencia del proveedor, aunque ambas tienen rate limit de 10/minuto.
4. `refresh` tiene cobertura contractual y smoke test real condicionado, pero
   no una prueba feature aislada con proveedor simulado.

Antes de producción se recomienda elegir explícitamente una opción:

- retirar las dos rutas federadas después de confirmar que ningún cliente
  legacy las consume; o
- protegerlas con feature flag desactivado por defecto y exigir aceptación
  legal/vinculación de cuenta antes de crear perfiles.

No se recomienda presentar estas rutas como alternativa de login mientras esa
decisión siga pendiente.

## Evidencia de pruebas

Se ejecutaron las suites focalizadas de contrato Android, registro legal,
login, `auth/me`, logout, correo duplicado, respuestas estables y routing del
emulador. Resultado: 52 pruebas aprobadas, 249 assertions, cero fallos.

El smoke test contra Firebase real incluye register, login, `auth/me` y refresh,
pero solo se ejecuta cuando `RUN_FIREBASE_SMOKE=true` y el proyecto se confirma
explícitamente. No se utilizó Firebase real en esta auditoría.

## Cambios realizados

Solo documentación. No se modificaron rutas, controladores, Firebase, claims,
credenciales ni Android.
