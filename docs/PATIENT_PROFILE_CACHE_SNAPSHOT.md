# Snapshot de perfil propio para Android cache-first

Fecha de contrato: 2026-08-04.

## Fuentes equivalentes

Android puede construir o reemplazar su snapshot propio desde:

- `GET /api/auth/me` usando `profile`;
- `GET /api/patients/{patient_uid}` usando `data`;
- `PATCH /api/patients/{patient_uid}` usando `patient` o su alias `data`.

## Forma mínima estable

```json
{
  "uid": "firebase_uid",
  "full_name": "Nombre",
  "display_name": "Nombre",
  "safe_display_name": "Nombre",
  "nickname": "Apodo",
  "privacy_mode": false,
  "is_anonymous": false,
  "sobriety_start_date": null,
  "photo_url": null,
  "primary_risks": [],
  "updated_at": "2026-08-04T10:30:00-06:00",
  "collection": "patients",
  "document_id": "firebase_uid"
}
```

Los campos opcionales permanecen presentes con `null`, `false` o `[]` para
evitar que Room confunda ausencia de clave con un valor que debe conservarse.

## Nombre visible y modo privado

`full_name` conserva el nombre real para el perfil propio y compatibilidad del
formulario de edición. No es el campo que Android debe usar para saludos.

Android debe mostrar `safe_display_name` y usar `display_name` como alias de
compatibilidad. Si `is_anonymous=true` **o** `privacy_mode=true`, ambos campos
valen `Paciente anónimo`; esta regla también se aplica después de iniciar una
nueva sesión. Si ambos flags son `false`, ambos contienen el nombre normal del
paciente (con fallback al apodo).

La proyección de supervisor nunca incluye el nombre real cuando cualquiera de
los flags privados está activo. Puede mostrar el apodo autorizado o una
referencia no identificable y expone el mismo valor en `display_name` y
`safe_display_name`.

## Semántica temporal

- `PATCH` siempre genera un nuevo `updated_at` en el servidor.
- `GET` devuelve el `updated_at` persistido.
- Un perfil legacy usa `created_at` como fallback.
- Si el documento no tiene ninguna fecha, `updated_at=null`; el backend no
  fabrica la hora de lectura porque eso produciría falsos cambios de versión.

Android puede usar `updated_at` para decidir un upsert local, pero no como
precondición optimista: el backend todavía no ofrece ETag ni `version`.

## Privacidad por rol

La forma anterior sólo se garantiza al propietario. La consulta de supervisor
continúa pasando por una allowlist independiente, anonimato, privacidad,
vinculación y consentimiento. No se agregaron `photo_url`, `updated_at`,
`collection`, correo, teléfono ni campos administrativos a esa proyección.

Los perfiles vistos como supervisor son memory-only/network-only y no deben
persistirse en Room.
