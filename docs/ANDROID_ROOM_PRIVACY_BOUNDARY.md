# Frontera de privacidad entre backend y Android Room

Fecha de auditoría: 2026-08-03.

## Decisión

No se requieren cambios de código backend para cifrar Room. El backend no tiene
acceso al sistema de archivos, Keystore ni configuración SQLite del dispositivo.
Su responsabilidad termina en autenticar, aplicar consentimiento, minimizar la
respuesta y transportarla mediante HTTPS.

Android debe cifrar todo contenido sensible persistido en Room y proteger la
clave con Android Keystore. Tokens de autenticación no deben guardarse en Room,
sino en almacenamiento seguro diseñado para credenciales.

## Matriz de persistencia

| Datos | Estrategia Android |
|---|---|
| Perfil del paciente autenticado | Caché cifrada; refrescar con `auth/me` |
| Notas propias | Caché cifrada y outbox idempotente |
| Agenda propia | Caché cifrada |
| Contactos propios | Caché cifrada con especial cuidado por teléfonos |
| Notification settings propias | Caché cifrada |
| Logros propios y catálogos no clínicos | Caché permitida; cifrar si se mezcla con perfil |
| Consentimientos y solicitudes | Network-first; servidor autoritativo |
| Perfil de paciente visto por supervisor | Sólo memoria, nunca Room |
| Notas/contactos/agenda supervisados | Sólo memoria, nunca Room |
| Intervenciones supervisadas | Sólo memoria, nunca Room |
| Pregunta, respuesta o contexto Chat IA | No persistir; historial backend deshabilitado |
| Firebase ID/refresh tokens | Nunca Room; almacenamiento seguro de credenciales |

Los perfiles propios de supervisor/admin y los conteos mínimos de
`dashboard-summary` pueden guardarse cifrados con TTL máximo recomendado de cinco
minutos. No contienen filas clínicas ni habilitan acciones offline. Pacientes,
solicitudes, intervenciones y cualquier detalle de terceros continúan siendo
network-only y no deben reconstruirse a partir de los conteos.

## Minimización confirmada

Las vistas de supervisor pasan por allowlists por recurso y exigen relación,
consentimiento activo y scope correspondiente. No entregan correo del paciente;
el nombre real se oculta con privacidad/anonimato y el teléfono sólo puede
aparecer bajo el scope explícito `patient_phone`.

El Chat IA recibe contexto estructurado y seudonimizado; no persiste historial.
Los tombstones excluyen texto libre, teléfonos, contenido de contactos y actor
interno de borrado.

Los endpoints propios contienen los campos funcionales del recurso, su ID,
relación de propietario y timestamps necesarios para upsert. Metadatos de la
envolvente como `collection`, `limit`, `sync` y `server_time` no deben copiarse a
cada entidad Room.

## Invalidación

Android debe purgar datos sensibles cuando ocurra cualquiera de estos eventos:

1. logout o cambio de cuenta;
2. token revocado o respuesta `401`;
3. respuesta `403` por consentimiento o vinculación;
4. consentimiento pausado/revocado;
5. desvinculación;
6. borrado remoto representado por `deleted_at`.

Los datos supervisados no deben esperar esta invalidación porque no deben
persistirse originalmente. Si una versión anterior de Android los guardó, debe
eliminarlos durante la migración de base.

## Riesgos fuera del control backend

- Room, WAL o SHM sin cifrar;
- claves incluidas en preferencias, código o backups;
- capturas de pantalla y exportaciones de diagnóstico;
- logs Android con payloads;
- copias de seguridad del sistema;
- dispositivos rooteados o comprometidos;
- datos supervisados ya persistidos por versiones antiguas.

Estas condiciones requieren controles y pruebas en la capa Android; el backend
no puede verificarlas de forma remota.
