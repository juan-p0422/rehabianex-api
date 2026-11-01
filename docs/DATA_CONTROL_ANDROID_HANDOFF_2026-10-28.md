# Entrega de contrato de Control de datos a Android

Fecha: 28 de octubre de 2026. Cambios preparados localmente; no desplegados.

## Resumen

La exportación de autoservicio permanece retirada. Control de datos expone dos acciones: desactivar la cuenta con borrado suave y contactar para solicitar eliminación definitiva.

## Consulta

`GET /api/data-control/summary`

- conserva `self_service_export_available=false`;
- ya no publica `actions.export`;
- publica `actions.deactivate_and_request_deletion`;
- publica `actions.request_permanent_deletion`;
- conserva rectificación, edición de perfil y acceso al aviso;
- `compatibility.legacy_export_route` documenta el `410` autenticado;
- `compatibility.legacy_deactivation_route` documenta la ruta anterior.

## Acción A

`POST /api/data-control/account-deactivation-requests`

```json
{
  "scope": "account",
  "confirmation": "DESACTIVAR MI CUENTA",
  "acknowledge_retention_limits": true
}
```

Requiere token Firebase y `auth_time` no mayor de 300 segundos. Android no debe refrescar y reintentar automáticamente esta mutación: un refresh no renueva la reautenticación.

Respuesta `202`:

```json
{
  "ok": true,
  "request": {
    "request_id": "deactivation_request_...",
    "status": "pending_manual_review",
    "requested_at": "fecha ISO 8601",
    "deactivated_at": "fecha ISO 8601"
  },
  "sync_blocked": true,
  "local_cleanup_required": true,
  "cleanup_status": {
    "remote_access": "blocked",
    "identity": "completed",
    "fcm": "completed",
    "relationships": "completed"
  },
  "manual_attention_required": false,
  "retention_notice": "texto"
}
```

Valores posibles de `cleanup_status`: `blocked` para el acceso remoto; `completed` o `manual_required` para identidad, FCM y relaciones. `manual_attention_required` siempre es booleano. `retention_notice` permanece.

Solo un `202` válido con ambos indicadores `true` autoriza limpiar el UID local. Offline, timeout, `401`, `403`, `422`, `429`, `5xx`, un `2xx` distinto o una respuesta incompleta conservan sesión y datos locales y no reintentan automáticamente.

La ruta anterior `POST /api/data-control/account-deletion-requests` permanece temporalmente autenticada y acepta la frase anterior para clientes existentes; internamente ejecuta la misma desactivación suave. No significa purga física.

## Acción B

- Canal: correo.
- Destinatario: `rehabianex@gmail.com`.
- Asunto: `Solicitud de eliminación de cuenta RehabiAnex`.
- No hay endpoint de envío ni tickets.
- Abrir `mailto:` no confirma envío.
- No incluir contraseña, tokens, notas, historial o datos de terceros.

## Exportación legacy

`GET /api/data-control/export` permanece dentro de autenticación, aviso vigente y rate limit. Responde `410`, `code=self_service_export_retired`; no consulta datos ni crea archivos. Android no debe mostrar botón, navegación, selector ni textos que prometan descarga.

## Aviso

Versión vigente requerida: `2026-10-30`. El consentimiento sensible conserva su versión separada `2026-10-27`. Una lectura anterior no satisface el aviso nuevo.

## Pruebas Android necesarias

- `202` válido limpia exactamente una vez y valida el UID activo antes de purgar.
- Cambio de cuenta durante la solicitud no limpia la cuenta nueva.
- Datos de otros UID permanecen.
- Offline, timeout, respuestas parciales y errores no limpian ni cierran sesión.
- Reconexión no reencola datos del UID desactivado.
- Ausencia de exportación y `410` sin archivo ni chooser.
- `mailto:` con destinatario/asunto exactos, ausencia de cliente y apertura sin afirmar envío.

