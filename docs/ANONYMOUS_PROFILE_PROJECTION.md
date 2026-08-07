# Proyección de identidad anónima del paciente

Fecha: 2026-08-04.

## Incidente corregido

Después de activar anonimato, Android podía mostrar correctamente `Paciente
anónimo` en Perfil y volver a saludar con el nombre registrado después de un
login. La causa era que `auth.display_name` provenía directamente de Firebase
Auth y no respetaba los flags almacenados en el perfil Firestore.

## Regla final

Se considera identidad privada cuando cualquiera de estas condiciones es
verdadera:

- `is_anonymous=true`;
- `privacy_mode=true`.

Para el propietario, el backend conserva `full_name` real en Firestore y en el
campo de edición, pero proyecta:

```json
{
  "display_name": "Paciente anónimo",
  "safe_display_name": "Paciente anónimo"
}
```

Con ambos flags desactivados, los campos visibles usan `full_name`, luego
`nickname` como fallback. Android debe usar `safe_display_name`; puede usar
`display_name` como alias para versiones que aún no migran.

Para supervisores, `full_name` se devuelve como `null` si la identidad es
privada. `display_name` y `safe_display_name` contienen el apodo permitido o una
referencia no identificable. La proyección no agrega correo, teléfono ni campos
administrativos.

## Endpoints alineados

- `POST /api/auth/register`;
- `POST /api/auth/login`;
- `GET /api/auth/me`;
- autenticación Firebase/Google que reutiliza la misma normalización;
- `GET /api/patients/{patient_uid}`;
- `PATCH /api/patients/{patient_uid}`;
- listados y detalle de paciente vistos por supervisor mediante la proyección
  segura compartida.

## Compatibilidad

- No se elimina ni renombra ningún campo.
- `full_name` real permanece disponible al propietario.
- Se corrige `auth.display_name` para que deje de filtrar el nombre registrado
  cuando hay privacidad.
- Se agrega `safe_display_name` como campo explícito y estable.
- No se modifica ningún documento Firestore existente; la proyección se calcula
  al responder.

## Hallazgo de integración Android (solo lectura)

La revisión del cliente vigente encontró que `AuthRepository` construye la
sesión priorizando `profile.fullName` antes de `auth.displayName`, y que
`SessionProfileDto` todavía no declara `display_name` ni `safe_display_name`.
Por ello, el backend ya entrega la proyección correcta, pero el Home actual aún
puede mostrar el nombre real hasta que el agente Android cambie ese orden y
mapee el campo seguro.

Cambio mínimo requerido en Android: resolver el saludo con
`profile.safeDisplayName`, luego `profile.displayName`, luego
`auth.safeDisplayName`/`auth.displayName`; usar `fullName` solo cuando ambos
flags privados sean `false`. No se modificó código Android en esta tarea.

## Archivos de implementación

- `app/Support/PatientDisplayName.php` centraliza la regla.
- `app/Http/Controllers/Api/AuthController.php` la aplica a auth y perfil.
- `app/Http/Controllers/Api/FirestoreCrudController.php` la aplica al snapshot
  propio.
- `app/Services/FirestoreAccessService.php` la aplica a la proyección de
  supervisor.

## Cobertura automatizada

- paciente normal muestra nombre normal;
- `is_anonymous=true` produce `Paciente anónimo`;
- `privacy_mode=true` también activa la proyección;
- dos logins consecutivos conservan el nombre seguro;
- `auth/me` coincide con login;
- el perfil propio conserva `full_name` real;
- supervisor recibe `full_name=null` y solo un alias/referencia segura.
