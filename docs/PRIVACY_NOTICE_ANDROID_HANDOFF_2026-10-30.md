# Mensaje para el agente Android — aviso 2026-10-30

## Cambio requerido

Mostrar y confirmar el aviso `2026-10-30` sin reutilizar la aceptación legal como consentimiento de datos sensibles o supervisión. El backend está preparado localmente, pero no debe desplegarse hasta verificar un APK compatible que envíe y conserve exactamente esa versión.

## Métodos, rutas y campos

- `GET /api/legal/privacy-notice`: público; debe abrirse antes de aportar datos.
- `POST /api/legal/privacy-notice/read`: autenticado, body `{"version":"2026-10-30","confirmed_read":true}`.
- `POST /api/auth/register`: aceptación legal con `privacy_notice_accepted=true` y `privacy_notice_version=2026-10-30`.
- Solo para `role=patient`: `sensitive_data_consent_accepted=true` y `sensitive_data_consent_version=2026-10-27`.
- Supervisor omite los dos campos sensibles. No se infieren desde aceptación, lectura, permisos Android o supervisión.

## Respuestas y errores

- `GET`: `200` con versión, fecha, contenido y metadatos; `503` si el texto no está disponible.
- Confirmación: `200` solo tras persistir por UID/rol/versión; `422` para versión anterior; `401/403` para sesión inválida; `503` si no pudo guardarse.
- Rutas protegidas: `428`, `code=privacy_notice_read_required`, hasta confirmar lectura vigente.
- Un fallo no autoriza navegación ni debe guardarse como éxito local.

## Compatibilidad

- Evidencia `2026-10-28` permanece histórica y no satisface `2026-10-30`.
- `legal_acceptance` anterior no se sobrescribe ni se convierte en aceptación retroactiva.
- El consentimiento sensible conserva versión `2026-10-27`.
- Offline solo permite evidencia cuyo UID y versión coincidan exactamente con la versión disponible.
- Conservar temporalmente el asset anterior; añadir el asset aprobado `2026-10-30` y actualizar constante, carga y fallback coordinadamente.

## Archivos Android identificados

- `data/model/AuthModels.kt`: constante, DTO y `RegisterRequest`.
- `AppContainer.kt`: asset bundled.
- `PrivacyNoticeRepository.kt`: fallback de versión/fecha.
- `RegisterScreen.kt` y `AuthValidator.kt`: controles separados.
- Pruebas de repositorio, autenticación, store instrumentado, presentación e interceptor.

## Pruebas necesarias

1. Aviso antes de los campos de registro.
2. Paciente serializa ambas evidencias y versiones por separado; supervisor omite las sensibles.
3. Cuenta `2026-10-28` queda pendiente y conserva historial al confirmar `2026-10-30`.
4. `422/503` no navega ni fabrica evidencia.
5. Reapertura online/offline por el mismo UID y cambio de cuenta.
6. Relectura read-only desde los tres roles.
7. Texto real completo, scroll, rotación y texto al 150 %, alcanzando controles.
8. Regresión de lectura, aceptación, consentimiento sensible, supervisión y permisos separados.

## Orden de publicación

Preparar y verificar primero el APK compatible, o mantener temporalmente el backend anterior. Desplegar Laravel `2026-10-30` antes del APK actual bloquearía el registro y la confirmación. Este documento no autoriza despliegue ni distribución.
