# Aceptación legal del aviso de privacidad

Fecha de revisión: 2026-10-30.

## Alcance

La aceptación del aviso de privacidad es un requisito de registro y permanece
separada de los consentimientos de supervisión almacenados en `consents`. No
otorga a un supervisor acceso a información del paciente.

La versión vigente se configura en `config/legal.php`. El valor predeterminado
para la beta cerrada es `2026-10-30`; un despliegue puede definir
`PRIVACY_NOTICE_VERSION` sin cambiar código.

## Evidencia persistida

El perfil de `patients` o `supervisors` conserva internamente:

```json
{
  "legal_acceptance": {
    "uid": "firebase_uid",
    "role": "patient",
    "privacy_notice_version": "2026-10-30",
    "accepted_at": "timestamp ISO-8601",
    "source": "android",
    "explicit_acceptance": true,
    "status": "accepted"
  }
}
```

No se almacena el texto completo del aviso. La fecha `accepted_at` se genera en
el servidor; Android no puede proporcionarla ni modificarla durante el registro.

Para pacientes nuevos, el perfil conserva por separado:

```json
{
  "sensitive_data_consent": {
    "uid": "firebase_uid",
    "version": "2026-10-27",
    "accepted_at": "timestamp ISO-8601",
    "source": "android",
    "explicit_consent": true,
    "status": "active",
    "scope": "self_tracking_sensitive_data"
  }
}
```

Este bloque no concede supervisión, no sustituye la lectura del aviso y no
equivale al permiso Android de notificaciones.

La existencia de esta evidencia no demuestra por sí sola que la interfaz haya
mostrado el aviso completo antes de solicitar datos. Esa comprobación requiere el
texto o URL oficial de la misma versión y una prueba del flujo Android. Una casilla
con un resumen no sustituye la presentación del aviso.

## Respuesta pública

Registro y `GET /auth/me` proyectan sólo versión, fecha y estado. Se omiten del
resumen `uid`, `role`, `source` y el indicador interno porque ya existen en el
perfil o no son necesarios para la interfaz.

Las cuentas creadas antes de esta implementación pueden devolver
`legal_acceptance: null`. Deben considerarse pendientes de una futura estrategia
de reaceptación; no se infiere ni fabrica aceptación retroactiva.

## Operación y cambio de versión

Cuando cambie materialmente el aviso:

1. publicar primero un APK que incluya el texto, la versión y los campos de
   registro nuevos;
2. actualizar `PRIVACY_NOTICE_VERSION` en el entorno correspondiente; cambiar
   `SENSITIVE_DATA_CONSENT_VERSION` solamente si también cambió ese
   consentimiento separado;
3. desplegar coordinadamente backend y Android;
4. las cuentas existentes confirman la lectura nueva mediante
   `POST /legal/privacy-notice/read`; la aceptación legal anterior se conserva;
5. no habilitar cuentas existentes para datos sensibles sin definir su flujo
   de consentimiento separado.

## Rollback

Revertir la validación y persistencia en `AuthController` restaura el contrato
anterior. Los bloques ya almacenados son campos adicionales inocuos y no deben
borrarse, pues constituyen evidencia histórica.
