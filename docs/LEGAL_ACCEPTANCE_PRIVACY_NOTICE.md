# Aceptación legal del aviso de privacidad

Fecha de implementación: 2026-08-03.

## Alcance

La aceptación del aviso de privacidad es un requisito de registro y permanece
separada de los consentimientos de supervisión almacenados en `consents`. No
otorga a un supervisor acceso a información del paciente.

La versión vigente se configura en `config/legal.php`. El valor predeterminado es
`2026-08-01`; despliegues posteriores pueden definir `PRIVACY_NOTICE_VERSION`
sin cambiar código. Este cambio no agrega ni modifica variables reales.

## Evidencia persistida

El perfil de `patients` o `supervisors` conserva internamente:

```json
{
  "legal_acceptance": {
    "uid": "firebase_uid",
    "role": "patient",
    "privacy_notice_version": "2026-08-01",
    "accepted_at": "timestamp ISO-8601",
    "source": "android",
    "explicit_acceptance": true,
    "status": "accepted"
  }
}
```

No se almacena el texto completo del aviso. La fecha `accepted_at` se genera en
el servidor; Android no puede proporcionarla ni modificarla durante el registro.

## Respuesta pública

Registro y `GET /auth/me` proyectan sólo versión, fecha y estado. Se omiten del
resumen `uid`, `role`, `source` y el indicador interno porque ya existen en el
perfil o no son necesarios para la interfaz.

Las cuentas creadas antes de esta implementación pueden devolver
`legal_acceptance: null`. Deben considerarse pendientes de una futura estrategia
de reaceptación; no se infiere ni fabrica aceptación retroactiva.

## Operación y cambio de versión

Cuando cambie materialmente el aviso:

1. publicar primero el nuevo texto en Android;
2. actualizar `PRIVACY_NOTICE_VERSION` en el entorno correspondiente;
3. desplegar coordinadamente backend y Android;
4. implementar un flujo autenticado de reaceptación antes de bloquear cuentas
   existentes, porque ese endpoint no forma parte de este cambio mínimo.

## Rollback

Revertir la validación y persistencia en `AuthController` restaura el contrato
anterior. Los bloques ya almacenados son campos adicionales inocuos y no deben
borrarse, pues constituyen evidencia histórica.
