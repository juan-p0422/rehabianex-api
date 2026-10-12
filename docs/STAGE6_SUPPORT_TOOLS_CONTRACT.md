# Etapa 6 — contrato de herramientas de apoyo

Fecha de contraste: 2026-10-12.

## Agenda

El contrato CRUD de `/api/agenda-events` no cambia. Android usa selectores
accesibles de fecha y hora y conserva creación, edición y eliminación. Los
recordatorios programados en el dispositivo se rotulan como locales y no como
FCM o push.

## Contactos

- `POST /api/support-contacts` y `PATCH /api/support-contacts/{id}` mantienen
  teléfono de México normalizado como `+52` y diez dígitos nacionales.
- `priority` es un entero de 1 a 5; 5 significa mayor prioridad.
- Registros históricos fuera de ese rango continúan legibles. Si el usuario no
  modifica la prioridad, editar otro campo no debe reemplazarla; para cambiarla
  debe seleccionar un valor válido de 1 a 5.
- La API no inicia llamadas ni envía alertas. Android abre `ACTION_DIAL` para el
  contacto elegido y explica que la confirmación ocurre en la aplicación de
  teléfono y que no garantiza atención de emergencia.

## Directorio

`GET /api/local-resources` conserva su respuesta y admite tres campos aditivos
y anulables: `source_name`, `source_url` y `reviewed_at`. Android muestra
`No disponible` cuando falten. `updated_at` solo representa actualización
técnica del documento; la última sincronización procede de la caché Android.
La revisión es informativa y no acredita calidad clínica.

## Notificaciones y diagnóstico

- `notification-settings` almacena preferencias; no demuestra entrega.
- Los recordatorios locales y FCM son canales distintos.
- `POST /api/notifications/test` requiere autenticación y solo funciona en
  `local`, `testing` o `staging`; producción responde `403` incluso en dry-run.
- `POST /api/notifications/demo` permanece como diagnóstico autenticado,
  limitado por rol, catálogo, configuración y tasa. Está deshabilitado por
  defecto y no forma parte de la navegación normal de Android.
- Los payloads visibles permanecen genéricos y sin datos sensibles; las rutas
  se validan contra tipo y rol.

Esta etapa no incorpora alertas automáticas ni reglas JITAI.
