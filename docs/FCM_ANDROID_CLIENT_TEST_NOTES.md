# Notas del cliente Android para pruebas FCM

## Alcance de esta fase

El proyecto Android no dispone de repositorio Git dentro de esta fase. No se
requiere commit frontend y su estado de versionado no bloquea el commit del
backend Laravel.

Android se conserva como cliente local de QA para validar el contrato FCM antes
y después del despliegue backend en Render. Los cambios Android deben
respaldarse por el mecanismo local definido por el equipo mientras no exista un
repositorio oficial.

## Configuración Firebase local

`app/google-services.json` existe únicamente en la copia local del cliente. No
se copia al backend, no se incluye en documentación y no debe compartirse como
evidencia. El archivo se administra manualmente hasta que exista una política
de versionado frontend.

La aplicación debug usa el cliente Firebase correspondiente a su
`applicationId`. Cualquier variante con suffix requiere su propio cliente
registrado antes de probarla.

## Pruebas realizadas

- Existen artefactos locales anteriores de APK debug, 172 pruebas unitarias
  aprobadas y lint sin errores. La repetición final de esos comandos quedó
  bloqueada por el problema ambiental de Gradle descrito abajo.
- Obtención de token FCM sin imprimirlo completo.
- Registro del token después de login y revocación antes de logout.
- Reasignación controlada del dispositivo entre sesiones técnicas de paciente,
  supervisor y administrador sin reiniciar la aplicación.
- Prevención del re-registro tardío después de logout.
- Recepción de mensajes `data-only` en foreground y background.
- Uso del canal `rehabianex_reminders` y solicitud de
  `POST_NOTIFICATIONS` en Android 13 o superior.
- Allowlist de los 16 tipos oficiales, rutas cerradas y fallback seguro.
- Sustitución de `title` y `body` por textos canónicos, ignorando campos
  sensibles o desconocidos.
- Navegación segura para agenda, supervisión, desvinculación, intervenciones,
  prioridad y panel administrativo.
- Prueba offline con agenda cacheada y ausencia de alertas locales sensibles.
  El disparo temporal del recordatorio local básico quedó pendiente.
- Replay de agenda sin duplicación visible. La coincidencia con una alarma real
  de AlarmManager, su cancelación selectiva y la conservación de otras alarmas
  quedaron pendientes.
- Comprobación de que no aparecen tokens completos ni datos sensibles en
  Logcat.

## Bloqueo Gradle loopback

En Windows se observó de forma intermitente:

```text
java.io.IOException: Unable to establish loopback connection
```

El bloqueo es ambiental y no corresponde a un fallo observado del backend FCM.
Se intentó una carpeta temporal corta y escribible para Unix Domain Sockets de
la JVM mediante `-Djdk.net.unixdomain.tmpdir=...`, además de detener daemons,
forzar IPv4 y ejecutar Gradle con `--no-daemon`; el bloqueo persistió en la
repetición final.

Si reaparece, se debe registrar versión de Java/JBR, versión de Gradle, comando
y stacktrace, sin incluir rutas personales. No se deben revertir correcciones
FCM para intentar resolver un problema de loopback.

## Pendientes Android no bloqueantes para el backend

- Ejecutar un recordatorio local básico con el emulador sin internet y esperar
  su disparo temporal real.
- Repetir la coincidencia AlarmManager/FCM para un mismo evento contra Render:
  debe quedar una notificación visible, cancelarse solo la alarma equivalente,
  conservarse las demás y no duplicarse un replay.
- Revalidar permiso, foreground, background, force-stop razonable, tap y ruta
  fallback contra el backend de staging.
- Repetir login, logout y cambio de rol contra Render, confirmando ausencia de
  token zombie.
- Confirmar que ningún fallback local se crea para recaída, riesgo,
  desvinculación, validaciones, intervención clínica o prioridad vulnerable.

## Plan de revalidación contra Render

1. Desplegar primero el backend en un servicio Render de staging con FCM
   deshabilitado y dry-run activo.
2. Cambiar la `baseUrl` únicamente en una variante Android de QA cuando el
   health check y las rutas protegidas estén correctos.
3. Iniciar sesión con una cuenta técnica y registrar el token.
4. Habilitar una ventana de smoke de un solo dispositivo.
5. Probar `test_notification` en foreground y background.
6. Probar tipos con disparador real y destinatario autorizado, uno por uno.
7. Ejecutar la prueba offline y la coincidencia AlarmManager/FCM.
8. Revocar el token, cerrar sesión y confirmar que no se reactiva.
9. Restaurar los flags seguros del backend y retirar la `baseUrl` temporal de
   la variante de QA si corresponde.

El cliente Android no forma parte del commit backend. Ningún paso de este plan
exige crear o simular un repositorio frontend para cerrar la fase Laravel.

La producción no queda aprobada hasta completar este plan y revisar evidencia
sanitizada del backend y del cliente.
