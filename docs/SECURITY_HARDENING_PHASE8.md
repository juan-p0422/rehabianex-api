# RehabiAnex — endurecimiento backend local previo a Fase 8

Fecha: 2026-08-03.

## Alcance

Este cambio no altera valores de `.env`, credenciales Firebase, claves de IA,
APP_KEY, tokens ni configuración real de Render. Tampoco ejecuta escrituras en
Firestore. Se limita a logs, materialización segura de la credencial Base64,
health checks y configuración Apache del contenedor.

## Comportamiento anterior de FirebaseService

`FirebaseService` resolvía credenciales desde las mismas tres fuentes actuales,
materializaba Base64 directamente en el archivo final e inicializaba Auth y
Firestore. Ante una excepción registraba mensaje completo, archivo y línea. El
archivo generado no verificaba permisos `0600` ni utilizaba reemplazo atómico.

## Cambios

- Los logs de inicialización contienen solo componente, operación, código
  estable y clase de excepción.
- La excepción que puede alcanzar capas superiores usa un mensaje genérico.
- La credencial Base64 se escribe primero en un archivo del mismo directorio
  con `LOCK_EX`, se asegura y después se activa mediante `rename` atómico.
- Linux/Render exige directorio `0700` y archivo `0600`, verificando el modo
  efectivo después de `chmod`.
- Windows aplica `chmod` como mejor esfuerzo, pero PHP no puede representar ni
  verificar ACL NTFS mediante `fileperms`. El archivo hereda las ACL del
  directorio local. Si ya existe una credencial distinta, el código se niega a
  reemplazarla de forma no atómica.
- `GET /api/health` es público, limitado y devuelve únicamente
  `{"ok":true,"status":"healthy"}`.
- Los pings autenticados ya no incluyen hora, entorno ni mensajes operativos.
- Apache deshabilita listado de directorios y niega solicitudes a dotfiles.
- PHP deshabilita `expose_php`, Apache limita su firma y el front controller
  retira defensivamente `X-Powered-By` también durante desarrollo local.

## Archivos sensibles no servidos

El `DocumentRoot` del contenedor es `/var/www/html/public`. `.env` se encuentra
fuera de ese directorio y además está excluido por `.dockerignore`. Las
credenciales están bajo `storage/app/firebase`, fuera de `public` y fuera de la
raíz del disco privado servible (`storage/app/private`). Los JSON de esa carpeta
también están excluidos del contexto Docker; en Render se materializan en
runtime desde la variable ya configurada.

## Rollback

Revertir exclusivamente los cambios de `FirebaseService.php`, `routes/api.php`,
`dockerfile`, pruebas y este documento. No borrar, regenerar, rotar ni restaurar
`.env` o archivos de credenciales. Antes de cualquier despliegue se debe probar
localmente y construir una imagen separada; este cambio no modifica Render.
