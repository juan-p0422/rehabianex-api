# Procedimiento interno de desactivación y eliminación de cuenta

Fecha de revisión: 28 de octubre de 2026. Este procedimiento es operativo y no certifica cumplimiento legal integral. No debe ejecutarse con cuentas reales durante pruebas.

## Dos acciones diferentes

### Desactivar mi cuenta y solicitar eliminación

La aplicación inicia `POST /api/data-control/account-deactivation-requests` después de reautenticar a la misma persona. La operación es un borrado suave recuperable para el equipo técnico, no una purga física:

1. crea una solicitud identificada y marca el perfil `pending_manual_review`;
2. fija `status=disabled` y `sync_blocked_at` antes de operaciones externas;
3. inactiva el vínculo de supervisión, revoca consentimientos y cancela solicitudes pendientes;
4. revoca tokens FCM, revoca refresh tokens y deshabilita Firebase Authentication;
5. bloquea la API incluso si un ID token anterior no ha expirado;
6. devuelve `202` con el estado real de cada limpieza.

Android solo limpia Room, outbox, WorkManager, preferencias, cachés y sesión del UID después de un `202` válido con `sync_blocked=true` y `local_cleanup_required=true`. Un error, timeout, modo offline o respuesta parcial no se muestra como éxito ni autoriza limpiar otra cuenta.

No existe recuperación automática de una cuenta desactivada. No se debe permitir que una cola anterior reactive el perfil ni vuelva a crear datos.

### Solicitar eliminación definitiva

La persona escribe a `rehabianex@gmail.com` con el asunto `Solicitud de eliminación de cuenta RehabiAnex`, indica el correo asociado y formula la solicitud. Abrir el cliente de correo no prueba el envío ni la identidad.

No se solicitan contraseñas, tokens, notas emocionales, historial de consumo, copias completas del contenido ni documentos de identidad salvo que exista un procedimiento específico, justificado y aprobado.

## Inventario que debe localizarse

- Firebase Authentication: identidad, proveedores, correo, teléfono, estado disabled y revocación de sesiones.
- Perfiles Firestore: `patients`, `supervisors` o `admins`.
- Datos del paciente: `patient_notes`, `agenda_events`, `support_contacts`, `notification_settings`, `patient_achievements` e `interventions`.
- Relaciones: `consents`, `supervision_requests`, campos de vínculo en `patients` y solicitudes de desactivación.
- Notificaciones y operación: `fcm_tokens`, bitácora de despacho, hashes de tokens revocados, rate limits y logs saneados.
- Android: base Room cifrada, outbox, WorkManager, preferencias seguras, cachés privadas, estado adaptativo y archivos temporales.
- Proveedores: solicitudes transitorias minimizadas del chatbot, Firebase, Render y los respaldos realmente configurados.
- Catálogos compartidos: `achievements` y `local_resources`; no se eliminan como si pertenecieran a una sola persona.

## Verificación de identidad

1. Registrar la recepción sin copiar el contenido sensible de la cuenta.
2. Localizar el perfil por UID interno después de verificar el control de la cuenta mediante un mecanismo aprobado.
3. No usar como prueba suficiente que la persona conoce una dirección de correo.
4. Nunca solicitar contraseña o token.
5. Confirmar alcance: cuenta completa, datos concretos, rectificación o consulta de conservación.

El mecanismo final de verificación y el plazo de respuesta deben ser aprobados por el responsable; este documento no los inventa.

## Ejecución definitiva

1. Confirmar que la cuenta está desactivada, Firebase Auth está disabled y las escrituras están bloqueadas.
2. Revisar `cleanup_status`; resolver manualmente todo valor `manual_required`.
3. Cancelar vínculos activos, consentimientos y solicitudes pendientes sin borrar datos de otras personas.
4. Localizar documentos propios por UID y relaciones; preparar una lista previa a cualquier eliminación.
5. Aplicar el tratamiento aprobado a cada colección. Conservar únicamente lo exigido por seguridad, respaldo o una obligación aplicable documentada.
6. Revisar tokens FCM, trabajos, colas, archivos temporales y cachés para impedir reaparición por sincronización.
7. Tratar Firebase Authentication al final de la secuencia recuperable, una vez comprobados los datos y relaciones.
8. Verificar con token anterior, login nuevo y reconexión que no existe acceso ni recreación.
9. Registrar únicamente identificador interno, fecha, estado y operador; no copiar notas, prompts ni credenciales al registro operativo.
10. Confirmar al titular la finalización por el canal verificado sin revelar datos sensibles.

## Fallos parciales y conservación

- Si el perfil quedó bloqueado y Firebase o FCM falló, se mantiene el bloqueo y se marca `manual_required`.
- Si no pudo crearse atómicamente la solicitud y el bloqueo, el endpoint responde error y no afirma éxito.
- No se promete borrado inmediato de respaldos, logs de seguridad o datos procesados por proveedores.
- No se fija un plazo hasta que el responsable lo apruebe y lo relacione con el procedimiento real.
- No se elimina información de otros usuarios ni auditoría compartida indiscriminadamente.

## Comprobación final

- Firebase Auth disabled y refresh tokens revocados.
- ID token anterior rechazado por Firebase o por el estado del perfil.
- Login posterior imposible.
- API y nuevas sincronizaciones bloqueadas.
- FCM sin tokens activos del UID.
- Vínculos y consentimientos sin acceso activo.
- Outbox y trabajos Android cancelados para el UID correcto.
- Reconexión sin recreación de datos.
- Resultado y excepciones documentados sin datos sensibles.

