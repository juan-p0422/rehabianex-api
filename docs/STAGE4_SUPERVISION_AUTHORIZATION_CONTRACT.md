# Etapa 4: supervisión y autorización

Contrato acordado entre Laravel y Android para la etapa 4. Firebase/Firestore es
la autoridad de estado; la interfaz no debe conceder acceso basándose solo en
caché ni en la visibilidad de un botón.

## Matriz de estados y actores

| Entidad | Estado actual | Acción y actor | Estado siguiente | Efecto de acceso |
| --- | --- | --- | --- | --- |
| Supervisor | `pending_review` | autorizar, admin | `active` | queda habilitado para operar relaciones ya válidas; no crea vínculo ni consentimiento |
| Supervisor | `pending_review` | rechazar con motivo, admin | `rejected` | no puede consultar pacientes |
| Supervisor | `active` | suspender con motivo, admin | `suspended` | bloquea consultas posteriores sin terminar vínculos ni revocar consentimientos |
| Supervisor | `suspended` | reactivar, admin | `active` | vuelve a aplicar el resto de controles de relación y consentimiento |
| Solicitud | `pending` | aceptar/rechazar, supervisor destinatario | `accepted`/`rejected` | una solicitud de vínculo aceptada crea la relación, pero todavía no concede datos |
| Solicitud | `pending` | cancelar, paciente propietario | `cancelled` | no crea ni modifica el vínculo |
| Consentimiento | `inactive` | activar con uno o más alcances, paciente | `active` | concede únicamente los alcances seleccionados |
| Consentimiento | `inactive` | revocar, paciente | `revoked` | no concede acceso |
| Consentimiento | `active` | pausar, paciente | `paused` | bloquea consultas posteriores |
| Consentimiento | `active`/`paused` | revocar, paciente | `revoked` | bloquea consultas posteriores; es terminal |
| Consentimiento | `paused` | reactivar, paciente | `active` | restablece únicamente sus alcances vigentes |
| Vínculo aceptado | activo | desvincular directamente o aceptar solicitud `unlink`, participante autorizado | finalizado | limpia la asignación y revoca los consentimientos asociados |

`rejected` y `disabled` se conservan para compatibilidad administrativa. La
suspensión administrativa, la finalización del vínculo y la revocación del
consentimiento son acciones distintas y no se sustituyen entre sí.

## Alcances granulares

Los únicos nombres canónicos son:

- `patient_notes`
- `agenda_events`
- `support_contacts`
- `ai_chat_summary`
- `patient_achievements`
- `patient_phone`

`POST /api/consents` exige un arreglo `scope` no vacío. Con
`explicit_consent=false` crea estado `inactive`, sin permisos efectivos; con
`true` crea estado `active`. `PATCH /api/consents/{id}` permite editar `scope`
en `inactive`, `active` o `paused`, y realizar solo las transiciones de la
matriz. No existen alcances activados por omisión. La respuesta conserva
`scope` y agrega `permissions`, cuyos valores solo pueden ser verdaderos cuando
el consentimiento está activo.

## Solicitudes y perfil

- `PATCH /api/supervision-requests/{id}` con `{"status":"cancelled"}` permite
  al paciente retirar su solicitud pendiente. El `DELETE` lógico existente se
  conserva como compatibilidad legacy.
- Una solicitud pendiente nunca concede acceso.
- Las solicitudes nuevas no persisten correo del paciente. El nombre mostrado
  al supervisor se vuelve a proyectar desde el perfil actual en cada respuesta,
  respetando `privacy_mode`/`is_anonymous` y evitando reutilizar un nombre
  histórico.
- `GET /api/auth/me`, para un paciente con vínculo aceptado, agrega
  `profile.supervisor` con `uid`, `full_name` y `supervisor_type`. Si no existe
  vínculo aceptado o el perfil asociado ya no existe, devuelve `null`.

El acceso a registros no implica permiso para revelar identidad. La privacidad
visual es una proyección de interfaz y no constituye anonimato garantizado.

## Administración y búsqueda

`GET /api/admin/supervisors` acepta `q`, `status`, `authorized`, `verified`,
`limit` (1..100) y `page` (desde 1). `q` busca sin distinguir mayúsculas en
nombre, correo, tipo, tipo libre y código. La respuesta conserva `count` y
`data`, y agrega `page`, `limit`, `total` y `has_more`.

Autorizar, rechazar, suspender y reactivar almacenan actor y fecha. Rechazar y
suspender requieren motivo. Las respuestas de detalle incluyen también
`rejected_at` y `rejected_by`. La confirmación previa a una acción irreversible
corresponde a Android; el backend valida rol, estado y campos aunque la UI se
omita.

## Concurrencia, caché y compatibilidad

Cada consulta sensible comprueba token, rol, supervisor activo/verificado,
relación aceptada, consentimiento activo y alcances requeridos. Android debe
invalidar listas y detalles al pausar, revocar, desvincular o cambiar de usuario.
Un cambio concurrente bloquea las consultas posteriores, pero no puede retirar
información ya entregada ni garantiza cancelar una respuesta que ya cruzó la
última comprobación del servidor.

Los documentos históricos sin `status` mantienen la interpretación anterior:
`explicit_consent=true` y sin marcas de pausa/revocación equivale a `active`;
`explicit_consent=false` equivale a `revoked`. Las cuentas y vínculos existentes
no se migran automáticamente.

## Reversión

Revertir el cambio de esta etapa restaura el contrato anterior de creación
activa obligatoria y listado administrativo sin búsqueda/página. No se deben
editar ni migrar documentos reales para revertir código. Los campos agregados
son aditivos, por lo que clientes anteriores pueden ignorarlos.
