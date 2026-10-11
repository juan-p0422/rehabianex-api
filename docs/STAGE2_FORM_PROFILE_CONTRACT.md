# Contrato de formularios y perfil etapa 2

Este documento fija el contrato Laravel que Android debe usar para la etapa 2.
El alcance de teléfono es México. Los cambios no modifican documentos ya
almacenados: la normalización se aplica únicamente a escrituras nuevas.

## Teléfono

Android muestra México con código de país `+52` y captura exactamente 10
dígitos nacionales. Puede enviar `4491234567`, `(449) 123-4567` o
`+524491234567`; Laravel almacena el valor canónico `+524491234567`.

La regla aplica a:

- `POST /api/auth/register` en `phone`, opcional;
- `PATCH /api/patients/{uid}` en `phone`, opcional y anulable;
- `PATCH /api/supervisors/{uid}` en `phone`, opcional y anulable;
- `POST` y `PATCH /api/support-contacts[/{id}]` en `phone`; es obligatorio al
  crear el contacto.

Un valor de otro país, con letras o con una cantidad distinta de 10 dígitos
nacionales responde `422`. Los teléfonos históricos se devuelven tal como
están guardados, aunque no cumplan el formato nuevo. No se reescriben durante
una lectura ni durante una edición de otro campo.

## Perfil de paciente

`PATCH /api/patients/{uid}` conserva el contrato existente. Permite editar
`full_name`, `nickname`, `phone`, `gender`, `age`, `photo_url`,
`sobriety_start_date`, `is_anonymous`, `privacy_mode` y `primary_risks`.
Nombre y apodo aceptan espacios internos, acentos y texto Unicode legítimo;
los límites continúan en 120 y 80 caracteres respectivamente. Un nombre
presente no puede estar compuesto únicamente por espacios.

El mismo `PATCH` puede repetirse después de un error de red: reemplaza los
mismos campos y no crea un segundo recurso. La interfaz debe deshabilitar el
botón mientras la petición esté en curso.

## Tipos de supervisor

Los valores admitidos en `supervisor_type` son:

```text
doctor
psychiatrist
psychologist
clinical_psychologist
social_worker
support_sponsor
other
```

`padrino` continúa aceptándose únicamente para clientes y datos históricos.
Una interfaz nueva debe enviar `support_sponsor`.

Cuando `supervisor_type` sea `other`, Android envía además
`supervisor_type_other`, texto obligatorio de 1 a 80 caracteres significativos.
El backend permite editar ambos campos con `PATCH /api/supervisors/{uid}` y
devuelve `422` ante un tipo desconocido o una descripción vacía. Al cambiar a
un tipo distinto de `other`, la descripción anterior se limpia.

## Relaciones de contactos

`relationship` se conserva como texto libre opcional para compatibilidad. La
nueva selección general se envía en `relationship_category` con uno de estos
valores:

```text
family
friend
partner
health_professional
support_peer
work_or_school
other
```

Cuando la categoría sea `other`, `relationship_other` es obligatorio y admite
hasta 80 caracteres. Las tres propiedades se aceptan en creación y edición.
Los registros históricos que sólo contengan `relationship` siguen siendo
válidos y legibles.

## Cambio de correo

El correo no se acepta en los `PATCH` de pacientes o supervisores. Android debe
usar el flujo seguro de Firebase Authentication:

1. Solicitar reautenticación reciente cuando Firebase la requiera.
2. Invocar `verifyBeforeUpdateEmail` con el nuevo correo.
3. Esperar a que la persona confirme el enlace.
4. Renovar el ID token de Firebase.
5. Invocar `POST /api/auth/email/sync` con `Authorization: Bearer {id_token}` y
   body vacío.

Laravel toma el correo exclusivamente del usuario autenticado de Firebase. No
acepta un correo en el body. Responde `200` con `profile` cuando Firebase marca
el correo como verificado; responde `409` si falta la verificación o el token no
refleja todavía el cambio. Los errores de autenticación responden `401`.

Firebase Authentication y Firestore no ofrecen una transacción conjunta. Si
Firebase completa el cambio y falla la escritura del perfil, Android no debe
intentar revertir el correo ni guardar el correo pendiente como una edición de
perfil. Debe conservar la sesión, renovar el ID token si hace falta y reintentar
`POST /api/auth/email/sync`. El endpoint es idempotente.

## Renovación de sesión acordada

`POST /api/auth/refresh` recibe `refresh_token` obligatorio, string de hasta
16 384 caracteres. Un token inválido, expirado, revocado o de una cuenta
deshabilitada responde `401`; un fallo temporal o de red del proveedor responde
`502`; una entrada inválida responde `422`. La respuesta `200` mantiene
`tokens.id_token`, `tokens.refresh_token`, `tokens.expires_in` entero y
`tokens.token_type`.

Android comparte una sola renovación concurrente, reintenta la operación
original una vez y no elimina la sesión por `502`.

## Responsabilidades exclusivas de Android

Android implementa el selector visual `+52`, mostrar u ocultar contraseña,
mensajes junto al campo, estado de carga y bloqueo del botón durante el envío.
Las ediciones ordinarias de perfil pueden reutilizar la lectura local y, si la
cola existente conserva autorización, orden y resolución de conflictos, pueden
reintentarse. Los cambios de correo, contraseña y demás credenciales nunca se
encolan como un `PATCH` de perfil.

La creación de contactos todavía no define una clave de idempotencia entre
Android y Laravel. Hasta acordar ese contrato, Android debe impedir el doble
toque y no repetir automáticamente un `POST /api/support-contacts` cuyo resultado
sea desconocido; debe refrescar la lista antes de ofrecer un reintento.
