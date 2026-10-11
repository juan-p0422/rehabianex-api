# Etapa 3: privacidad y registro sin nombre/correo

Fecha de revisión: 2026-10-11.

## Estado comprobado

- El registro convencional exige aceptación expresa y la versión vigente del
  aviso (`2026-08-01` por defecto). La fecha se genera en el servidor.
- Registro y `GET /api/auth/me` proyectan versión, fecha y estado de la
  aceptación. Una cuenta heredada puede devolver `legal_acceptance: null`.
- La aceptación legal se almacena en el perfil y permanece separada de los
  consentimientos de supervisión (`consents`) y del permiso Android para enviar
  notificaciones.
- `privacy_mode` e `is_anonymous` son controles de presentación visual. No crean
  anonimato técnico: Firebase conserva un UID y el backend conserva actividad.
- El repositorio backend no contiene el texto legal íntegro ni una URL oficial
  del aviso. Tampoco existe un endpoint autenticado de reaceptación.
- No existe hoy un alta de Firebase anónima en la API.

## Decisiones funcionales pendientes

Antes de afirmar que el aviso se presenta correctamente se necesita una fuente
oficial y versionada para su texto o URL. También debe definirse si las cuentas
heredadas con `legal_acceptance: null` continúan sin bloqueo o deben aceptar la
versión vigente mediante un flujo autenticado nuevo.

No se debe inventar texto legal, fabricar aceptaciones retroactivas ni reutilizar
una casilla sin mostrar previamente el aviso. Por la misma razón, el alta sin
nombre/correo descrita a continuació queda acordada pero no implementada.

## Contrato acordado para alta sin nombre/correo

### Solicitud

`POST /api/auth/anonymous/register`, pública, con `throttle:5,1`.

```json
{
  "id_token": "Firebase ID token",
  "privacy_notice_accepted": true,
  "privacy_notice_version": "2026-08-01"
}
```

- `id_token`: texto obligatorio, máximo 16 384 caracteres.
- La aceptación debe ser expresa y la versión debe coincidir con la vigente.
- No se aceptan nombre, correo, teléfono, contraseña, rol ni `is_anonymous`.
- El token debe declarar proveedor `anonymous`; el usuario Firebase debe carecer
  de correo y de proveedores vinculados.
- El único rol permitido es `patient`.

### Respuesta e idempotencia

La primera alta responde `201`; repetirla para el mismo UID y perfil compatible
responde `200` y repara el claim faltante. Un perfil previo registrado, con otro
rol o incompatible responde `409` y nunca se fusiona ni sobrescribe.

La respuesta conserva `{ok, message, auth, profile}`. `auth` mantiene su forma
actual, con correo nulo, proveedores vacíos y rol `patient`. `profile` conserva
la forma estable y agrega:

```json
{
  "account_type": "anonymous",
  "recovery_available": false,
  "full_name": null,
  "email": null,
  "phone": null,
  "privacy_mode": true,
  "is_anonymous": false
}
```

`is_anonymous` sigue siendo exclusivamente el flag visual heredado y no se
deriva de `account_type`. Android conserva los tokens Firebase obtenidos al crear
la identidad y fuerza una sola renovación de ID token después del alta para recibir
el claim de rol.

Errores acordados: `401` para token inválido, expirado o identidad no anónima;
`409` para UID incompatible; `422` para validación o versión; `502` para fallo
temporal del proveedor o persistencia.

### Consistencia y recuperación

En un alta nueva se verifica todo antes de escribir, se asigna el claim
`role=patient` y después se crea el perfil. Si falla el claim no se crea perfil.
Si falla Firestore se restauran los claims anteriores como compensación. Incluso
si esa compensación falla, un claim aislado no permite usar la API porque el
middleware exige también un perfil; un reintento idempotente repara el estado.

Cerrar sesión, borrar datos o desinstalar puede hacer irrecuperable una identidad
anónima no vinculada. Android debe advertirlo y separar datos locales por UID al
cambiar de usuario. La vinculación a credenciales queda fuera de esta etapa hasta
acordar recuperación y fallos parciales. Las cuentas existentes no se convierten
ni migran.

## Pruebas de aceptación pendientes del alta

- identidad Firebase realmente anónima y rechazo de tokens vinculados;
- alta inicial e idempotencia del mismo UID;
- renovación acotada del token y aparición del rol;
- fallos de claims, Firestore y compensación;
- persistencia local/remota, reinstalación y cambio de usuario;
- advertencia de pérdida de acceso y Atrás sin cerrar sesión;
- compatibilidad de cuentas existentes y regresión del registro convencional.

Estas pruebas solo pueden ejecutarse cuando exista el aviso oficial y se habilite
la implementación coordinada.
