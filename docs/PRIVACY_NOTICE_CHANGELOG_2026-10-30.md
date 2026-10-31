# Aviso de privacidad 2026-10-30 — incorporación y cambios

## Identificación

- Versión vigente preparada: `2026-10-30`.
- Fecha de publicación indicada: 30 de octubre de 2026.
- Fuente editable: `resources/legal/privacy-notice-2026-10-30.md`.
- Versión anterior conservada: `resources/legal/privacy-notice-2026-10-28.md`.
- Consentimiento separado sobre datos sensibles: conserva versión `2026-10-27`.

Esta preparación es local. No demuestra despliegue, publicación del APK ni autorización de la beta.

## Cambios respecto de 2026-10-28

1. El correo `rehabianex@gmail.com` se identifica específicamente como canal para solicitar la eliminación definitiva, separado de la desactivación con borrado suave iniciada en la aplicación; responsable, teléfono y ubicación no cambiaron.
2. Se mantiene explícitamente que no existe exportación de autoservicio en la beta y se conserva la atención de acceso/rectificación por los canales informados.
3. Se separan la desactivación con borrado suave desde la aplicación y la solicitud de eliminación definitiva por correo con verificación pendiente.
4. Se aclara que desactivar bloquea acceso y sincronización, revoca sesiones/tokens e inactiva vínculos/consentimientos, pero no acredita purga física inmediata.
5. Se incorporan los límites comprobados sobre colas, limpieza local parcial, cambio de cuenta y registros remotos.
6. Se detallan límites de respaldos Android, logs, conservación y procesamiento por terceros sin inventar plazos.
7. Se prohíbe solicitar por correo contraseñas, tokens, notas emocionales, historial, datos de terceros o copias completas.
8. Se describe TLS, SQLCipher, Android Keystore y AES-GCM según la auditoría; se señala que la migración de instalaciones antiguas sigue pendiente.
9. Se aclara que no existe cifrado de extremo a extremo y que UID/seudonimización no equivalen a cifrado o anonimato.
10. Se explica que las cuentas existentes confirman lectura de la nueva versión sin fabricar aceptación legal retroactiva ni alterar consentimientos de datos sensibles o supervisión.

## Incorporación técnica Laravel

- `config/legal.php` apunta al archivo y fecha `2026-10-30`.
- `.env.example` declara `PRIVACY_NOTICE_VERSION=2026-10-30`.
- Los fallbacks de controlador, middleware y autenticación usan la misma versión.
- `GET /api/legal/privacy-notice` entrega texto, versión y fecha sin autenticación para permitir lectura previa al registro.
- `POST /api/legal/privacy-notice/read` acepta únicamente la versión vigente y conserva la evidencia anterior en `privacy_notice_read_previous`.
- `privacy.notice.read` responde `428` a cuentas con confirmación ausente o anterior.
- La aceptación legal de registro, la confirmación de lectura, el consentimiento sensible, la supervisión y permisos Android permanecen separados.

## Orden coordinado de publicación

1. Android debe reconocer y poder mostrar dinámicamente la versión `2026-10-30`, incluido texto largo y relectura desde perfil.
2. Verificar gate online/offline, cuenta nueva y cuenta con `2026-10-28` usando datos sintéticos.
3. Publicar un APK compatible antes o junto con el backend; no cambiar solamente la variable remota si el cliente queda bloqueado.
4. Desplegar backend con `PRIVACY_NOTICE_VERSION=2026-10-30` y `APP_RELEASE` identificable.
5. Ejecutar smoke contra el entorno desplegado. Conservar evidencia de `2026-10-28`; no migrarla como si confirmara `2026-10-30`.

## Datos indispensables pendientes

- Domicilio aplicable del responsable.
- Confirmación expresa del responsable de que la beta será exclusivamente para mayores de 18 años.
- Procedimiento aprobado de verificación de identidad para eliminación definitiva, responsables operativos y canal de confirmación; no hay plazo aprobado.
- Periodos generales de conservación y criterios aplicables a respaldos y logs.
- Regiones, condiciones de transferencia y conservación confirmadas para los proveedores utilizados.
- Migración preservadora Room plaintext→SQLCipher para instalaciones antiguas.
- Política de conservación o purga de notas offline ante expiración de sesión/cambio de cuenta.
- Validación de backup/restore OEM, limpieza parcial y configuración real del APK release y del backend desplegado.

## Reversión

Si Android o el despliegue no están listos, no se debe activar `2026-10-30` en el entorno remoto. Se conserva el archivo anterior y puede mantenerse temporalmente `2026-10-28` como versión vigente sin borrar evidencias. Una vez que usuarios hayan confirmado `2026-10-30`, esas evidencias no deben eliminarse ni sobrescribirse durante una reversión operativa.
