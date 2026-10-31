# RehabiAnex — protección en tránsito y almacenamiento local

Fecha de verificación: 2026-10-30 (America/Mexico_City).

## Alcance y versiones

- Backend local: rama `codex/closed-beta-backend-prep`, cambio de transporte IA `e092a98`, sobre base `0f6b61227674`; permanecen cambios previos no consolidados.
- Android inspeccionado: rama `codex/transit-local-protection`, cambios de esta tarea `8ffeff2` y consolidación documental `4d70d30`; permanecen cambios previos no atribuibles a esta auditoría.
- Backend público observado: `https://rehabianex-api.onrender.com`; `/api/health` respondió correctamente, pero no identificó la versión desplegada.
- No hubo despliegue, captura de tráfico personal, lectura de claves, borrado de bases ni uso de cuentas reales.

TLS protege el contenido completo de una conexión mientras viaja entre extremos TLS. No convierte el sistema en cifrado de extremo a extremo: Laravel, Firebase y el proveedor IA autorizado pueden acceder al contenido que necesitan procesar. Un UID seudónimo reduce identificación directa, pero no equivale a cifrado ni anonimato.

## Matriz de verificación

| Dato o flujo | Protección existente | Brecha o límite | Cambio o decisión | Evidencia |
|---|---|---|---|---|
| Android → Laravel beta | Base URL HTTPS; release rechaza HTTP, query/fragment y credenciales embebidas. Sin `TrustManager`, `HostnameVerifier` o socket permisivo. Cleartext deshabilitado. | La redirección HTTP pública no debe considerarse protección: el APK debe iniciar directamente con HTTPS. | Conservar. No añadir pinning sin plan de rotación y recuperación. | `ApiClient.kt`, `network_security_config.xml`, `ApiTransportPolicyTest` 2/2. Endpoint público validó certificado y hostname con TLS 1.3. |
| Laravel público | Render/Cloudflare redirige HTTP a HTTPS; HTTPS respondió 200 y la validación de certificado devolvió éxito. | La versión desplegada no es identificable desde `/api/health`; no se verificaron variables privadas de Render. | Desplegar solo después de consolidar commit y configurar `APP_RELEASE`. | Comprobación pública 2026-10-29/30; TLS 1.3, `TLS_AES_256_GCM_SHA384`. |
| Laravel → Firebase Auth/Firestore/FCM | SDK oficial y URLs HTTPS; Auth solo permite HTTP cuando el emulador es loopback. Validación TLS normal, sin `verify=false`. | El emulador HTTP no representa la beta. No se ejecutó integración real por falta de ventana autorizada. | Conservar separación local/beta. | `FirebaseService.php`, `AuthController::firebaseAuthUrl`, `AuthEmulatorRoutingTest`. |
| Laravel → proveedor IA | URL predeterminada HTTPS, timeout y contexto minimizado. No se registran preguntas ni cuerpos. | Antes aceptaba una URL HTTP externa por configuración. | **Corregido:** HTTPS obligatorio; HTTP solo loopback en `local/testing`. Configuración insegura falla antes de enviar el payload, usando el error compatible `configuration_missing`. | `AIService.php`; `SupervisorAISecurityTest` prueba que no se envía ninguna petición. |
| Código de supervisor | Autenticación, validación de formato, respuesta mínima y rate limit. El código solo previsualiza; no crea vínculo ni consentimiento. | El GET anterior colocaba el código en query y podía quedar en CDN/proxy. | **Corregido de forma compatible:** nuevo `POST /api/supervisors/resolve` con JSON, `no-store` y límites por UID/IP. GET permanece obsoleto durante una versión. Android debe migrar antes de retirarlo. | `SupervisionController.php`, `routes/api.php`, `AppServiceProvider.php`, pruebas de contrato. |
| Notas, perfil, consentimientos y agenda en tránsito | Cuerpo JSON sobre HTTPS y bearer token en encabezado. | UID y IDs REST pueden aparecer en rutas; son seudónimos, no cifrado. | Conservar contratos; no añadir cifrado redundante de payload. | Rutas y controladores Laravel; política de transporte Android. |
| Pregunta y contexto IA | Pregunta saneada; contexto con referencias `P-xxx`, métricas y límites. Enviado por HTTPS al proveedor configurado. | El servidor y el proveedor ven el contenido procesado; seudonimización no garantiza anonimato. | Mantener minimización, límites y no persistencia de historial. | `SupervisorAIController.php`, `AIService.php`. |
| FCM | Token asociado a UID; cliente valida sesión/rol/ruta y muestra texto genérico; backend evita payload clínico visible. | Entrega y almacenamiento dependen también de Firebase; no se comprobó configuración release/CI real. | Mantener textos genéricos, revocación en logout/desactivación y pruebas E2E controladas. | `FcmNotificationPolicy`, repositorio de tokens y pruebas backend FCM. |
| Room, notas y outbox nuevos | SQLCipher activo; passphrase aleatoria de 32 bytes, protegida por AES-256-GCM y Android Keystore; envelope en `noBackupFilesDir`. | **Bloqueante:** no existe migración preservadora desde Room plaintext. Debug elimina la DB; release lanza error y puede impedir arranque. | No distribuir actualización sobre instalaciones existentes hasta implementar y probar migración atómica. Nunca borrar silenciosamente. | `RehabiAnexDatabase.kt`, `LegacyDatabaseSecurityPolicy.kt`, `EncryptedRoomDatabaseInstrumentedTest`. |
| Pérdida/corrupción de clave Room | Falla cerrada y no regenera silenciosamente una clave como si abriera datos antiguos. | Falta UX/procedimiento de recuperación y prueba física. | Diseñar recuperación explícita, preservando evidencia de outbox pendiente. | `DatabasePassphraseProvider.kt`. |
| Tokens y sesión Android | AES-GCM con AAD y clave no exportable de Keystore; preferencias privadas. | Un fallo de descifrado elimina el campo afectado; falta regresión completa sobre dispositivo. | Conservar y ejecutar pruebas de actualización/reapertura. | `SecureSessionManager.kt`. |
| Identidad paciente local | AES-GCM/Keystore, clave de preferencia derivada por hash de UID y migración lazy de valores anteriores. | Depende de la disponibilidad de la clave Keystore; una pérdida/corrupción falla explícitamente. | Conservar el fallo cerrado; no regenerar ni reemplazar silenciosamente datos existentes. | `PatientIdentityStoreInstrumentedTest` 2/2 en Pixel 4 API 33. |
| Aislamiento local por cuenta | DAOs, outbox y WorkManager usan `ownerUid`; worker verifica UID/rol y `clientMutationId`. | El aislamiento impide mostrar/sincronizar bajo otra cuenta, pero no elimina datos antiguos. | Conservar y probar dos cuentas en el mismo dispositivo. | `OfflineDaos.kt`, `PatientSyncWorker.kt`, `PatientRepository.kt`. |
| Expiración 401/cambio de cuenta | Sesión se invalida; owner scoping evita mezcla visible. | **Importante:** invalidadores no pasan por limpieza central; Room, outbox, workers y preferencias pueden permanecer. Al borrar primero la sesión se pierde el UID previo para la siguiente limpieza. | Decisión pendiente: conservar outbox cifrado para reingreso del mismo UID o purgar. Implementar marcador cifrado del propietario o callback central antes de borrar sesión. | `PatientRepository.invalidateSession`, repositorios supervisor/admin, `OfflineDataCleanupCoordinator`. |
| Logout/desactivación | Coordinador cancela workers/recordatorios y limpia por UID; desactivación exige 202 autoritativo y flags. | `runCatching` puede ocultar limpieza parcial y no existe marcador duradero de reintento. | Registrar estado local pendiente y reintentar limpieza antes de aceptar otra cuenta. | `OfflineDataCleanupCoordinator.kt`, `DataControlRepository.kt`. |
| Preferences/notificaciones | Aviso por hash UID+versión; FCM y supervisor con propietario/fingerprint; preferencias excluidas de backup. | Contador global de logro en `rehabianex_notifications` no está aislado por UID. | Cambiar a clave por hash UID o limpiarlo en cambio/logout. | Stores de privacidad/notificaciones y reglas de backup. |
| Backups Android | DB/WAL/SHM/journal, sesión, identidad, privacidad, FCM y settings están excluidos; clave DB en `noBackupFilesDir`. | `allowBackup=true` deja riesgo para futuros archivos y comportamiento OEM no probado. | Mantener reglas exhaustivas o decidir `allowBackup=false`; probar backup/restore real antes de prometer cobertura. | `AndroidManifest.xml`, `backup_rules.xml`, `data_extraction_rules.xml`. |
| Logs Android/backend | HTTP Android BASIC solo debug y sin body/headers; queries redactadas. Backend registra clases/códigos, no prompts, tokens ni cuerpos. | Logs reales y proveedores externos tienen su propia retención; no se verificó Render de forma administrativa. | Mantener logging HTTP Firebase desactivado y `APP_DEBUG=false` en beta. | Escaneo estático; log local backend: 0 bearer, refresh token, password o correos detectados. |
| Exportaciones/temporales | Exportación de autoservicio retirada; endpoint legacy devuelve 410. Android eliminó componentes de exportación de forma concurrente. | No se hizo barrido forense del dispositivo. El repositorio backend tenía `tmp/` sin exclusión. | **Corregido:** `/tmp/` queda ignorado por Git sin borrar archivos del usuario. Verificar caché privada en dispositivo. | `.gitignore`, contrato de control de datos. |
| Credenciales backend locales | Archivos Firebase ignorados por Git; en Linux el servicio exige 0600. | En Windows el archivo hereda ACL y era legible por un grupo local adicional; no se leyó su contenido. | Restringir ACL según la identidad que ejecute Laravel o usar Secret File/variable segura; no modificar a ciegas porque puede romper el proceso. | `.gitignore`, `FirebaseService.php`, inspección ACL sin leer la clave. |

## Migración Room requerida antes de actualizar usuarios existentes

La migración debe congelar la sincronización, detectar la cabecera plaintext, generar la passphrase, crear una base SQLCipher temporal y copiar todas las tablas, índices y metadatos. Debe conservar `ownerUid`, `clientMutationId` y outbox; ejecutar `cipher_integrity_check`, verificar versión, conteos e invariantes, hacer `fsync`, cerrar y reemplazar atómicamente manteniendo rollback hasta una reapertura exitosa. Después puede reanudarse el worker.

Se requieren fixtures de todos los esquemas soportados, WAL, outbox pendiente, base grande, falta de espacio e interrupción en cada fase. El reset destructivo solo es admisible en datos sintéticos/dev o con confirmación explícita después de demostrar que no hay trabajo pendiente.

## Pruebas ejecutadas

### Backend

- Transporte/contrato enfocado: 66 pruebas, 238 aserciones, aprobadas.
- Contrato Android posterior: 48 pruebas, 176 aserciones, aprobadas.
- Regresión completa: 339 aprobadas, 2277 aserciones, 1 omitida.
- Omitida: integración Firebase real, que exige credenciales y confirmación autorizada.
- Pint sobre PHP modificado y `git diff --check`: aprobados.

### Android

- `ApiTransportPolicyTest`: 2/2 aprobadas.
- `PatientIdentityStoreInstrumentedTest`: 2/2 aprobadas en Pixel 4 API 33.
- `EncryptedRoomDatabaseInstrumentedTest`: 3/3 aprobadas en Pixel 4 API 33; verificó cabecera no plaintext, persistencia y purga por cambio de usuario.
- Evidencia conectada previa `PrivacyNoticePresentationInstrumentedTest`: 2/2 en Pixel 4 API 33; anterior a cambios concurrentes y no valida el estado final.
- El intento de Gradle directo desde PowerShell no ejecutó por `Unable to establish loopback connection`; las pruebas anteriores se ejecutaron desde Android Studio.
- No ejecutados: migración Room plaintext→SQLCipher (no existe), suite total, release firmado, backup/restore OEM, dispositivo físico y recorridos autenticados E2E.

## Texto verificable para el aviso

> RehabiAnex utiliza conexiones HTTPS/TLS para proteger la información mientras se transmite entre la aplicación, el servidor y los proveedores autorizados. En instalaciones nuevas, la base local utiliza SQLCipher y las claves y sesiones se protegen mediante Android Keystore y AES-GCM. Estas medidas no constituyen cifrado de extremo a extremo: el servidor y los proveedores autorizados pueden acceder a la información necesaria para prestar sus funciones. Un identificador seudónimo no equivale a anonimato. La seguridad no es absoluta y la actualización desde versiones antiguas requiere completar y verificar una migración local que conserve los datos pendientes.

## Conclusión

La protección en tránsito de la ruta beta es adecuada en el código revisado y se cerraron dos brechas mínimas sin cifrado redundante. La versión **no es apta todavía para actualizar instalaciones existentes** por la ausencia de migración Room plaintext→SQLCipher. También deben resolverse la política de conservación tras 401/cambio de cuenta, la limpieza parcial duradera, el versionado/firma release y la regresión en emulador y dispositivo físico.
