# Cuenta admin de pruebas: creación y reset seguros

Fecha de revisión: 2026-08-04.

## Estado del flujo

Laravel registra dos comandos administrativos:

```text
rehabianex:create-admin
rehabianex:reset-admin-password
```

`rehabianex:create-admin`:

- crea una cuenta nueva en Firebase Auth;
- asigna el custom claim `role=admin`;
- crea `admins/{uid}` con `role=admin` y `status=active`;
- permite vincular una cuenta Auth existente sin rol únicamente con
  `--link-existing`;
- rechaza cuentas que ya tengan rol patient/supervisor o perfiles en esas
  colecciones;
- si el admin y su perfil ya existen, termina sin sobrescribirlos;
- si Auth ya tiene claim admin pero falta `admins/{uid}`, exige
  `--link-existing` antes de reparar el perfil;
- no acepta una opción visible `--password` y no imprime UID, contraseña ni
  tokens.

`rehabianex:reset-admin-password`:

- solo opera si Auth tiene custom claim `role=admin` y existe `admins/{uid}`;
- nunca cambia claims ni el perfil;
- requiere confirmación interactiva o `--confirm` en automatización controlada;
- no acepta una opción visible `--password`;
- registra únicamente clase de excepción y operación genérica.

## Política de contraseña

La contraseña temporal debe contener, como mínimo:

- 14 caracteres;
- una mayúscula y una minúscula;
- un número;
- un símbolo;
- ningún espacio.

`Oswaldo222003` queda explícitamente descartada por la política: no tiene
símbolo, tiene menos de 14 caracteres y, además, ya fue compartida en una
solicitud. No debe usarse en local ni staging.

## Creación local recomendada

Con Firebase Auth y Firestore Emulator activos y el proyecto
`demo-rehabianex`, ejecutar en una terminal local:

```powershell
php artisan rehabianex:create-admin `
  admin.rehabianex.test@example.com `
  "Administrador QA"
```

El comando solicita la contraseña dos veces con entrada oculta. No debe
escribirse en el comando, `.env`, archivos de documentación, Thunder Client ni
el historial del chat.

Si la cuenta Auth ya existía sin rol y fue revisada expresamente:

```powershell
php artisan rehabianex:create-admin `
  admin.rehabianex.test@example.com `
  "Administrador QA" `
  --link-existing
```

No usar `--link-existing` si la identidad previa no fue verificada.

## Reset seguro

Modo interactivo, recomendado:

```powershell
php artisan rehabianex:reset-admin-password `
  admin.rehabianex.test@example.com
```

El comando pide confirmación antes de solicitar la nueva contraseña oculta.

En CI/staging controlado puede inyectarse temporalmente
`REHABIANEX_ADMIN_PASSWORD` desde el gestor de secretos del entorno y ejecutar:

```text
php artisan rehabianex:reset-admin-password admin.rehabianex.test@example.com --confirm --no-interaction
```

La variable debe existir solo durante el proceso y eliminarse después. No debe
guardarse en Git, Render Blueprint, imágenes Docker ni logs.

## Correo duplicado en registro público

Firebase Auth mantiene unicidad global por correo. `POST /api/auth/register`
ahora traduce la colisión a una respuesta estable, sin crear un segundo perfil:

```json
{
  "ok": false,
  "message": "Ya existe una cuenta registrada con este correo.",
  "errors": {
    "email": ["El correo ya está registrado."]
  }
}
```

Código HTTP: `409 Conflict`. El mensaje interno del proveedor no se expone.

## Staging

Para staging se debe usar un proyecto Firebase separado de producción y un
correo de prueba distinto. El comando solo debe ejecutarse desde una consola
autorizada con variables inyectadas por el gestor de secretos. No se modifica
Render ni Firebase de producción como parte de este procedimiento local.

## Rollback

- Crear un admin nuevo solo agrega una identidad Auth y `admins/{uid}`.
- Vincular restaura claims si falla antes de completar el flujo.
- Reset solo cambia la contraseña después de validar rol, perfil y confirmación.
- No existe borrado automático de administradores para evitar operaciones
  destructivas accidentales.
