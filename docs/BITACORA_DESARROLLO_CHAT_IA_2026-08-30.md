# Bitácora de desarrollo — Chat IA supervisor-paciente

**Proyecto:** RehabiAnex API  
**Corte de bitácora:** 30 de agosto de 2026  
**Módulo:** Vinculación supervisor-paciente y Chat IA

## Objetivo de la actividad

Investigar y corregir el caso en el que un supervisor podía visualizar a un
paciente vinculado en su inicio, pero la interfaz de Chat IA mostraba que no
tenía pacientes con permiso activo.

## Actividades realizadas

1. Se revisó el flujo completo de supervisor, paciente, consentimiento y Chat
   IA en el backend Laravel/Firebase.
2. Se verificaron las rutas publicadas en Render para autenticación, consulta
   de pacientes, consentimientos y Chat IA.
3. Se validó la relación de las cuentas de prueba de forma sanitizada:
   supervisor activo, autorizado y verificado; paciente con vinculación
   aceptada; sin solicitud de desvinculación pendiente.
4. Se auditó Firestore en modo de solo lectura para revisar los documentos de
   consentimiento correspondientes a la pareja de prueba.
5. Se comprobó que el scope oficial para permitir el Chat IA es
   `ai_chat_summary` y que estaba presente en los consentimientos activos.
6. Se probó el endpoint `POST /api/ai/supervisor-chat` en los modos `local` y
   `ai`. Ambos respondieron correctamente y reconocieron un paciente elegible.
7. Se identificó que la lista de pacientes del supervisor no incluía un campo
   explícito para indicar el permiso de IA.
8. Se implementó una proyección segura de permisos en los pacientes
   supervisados, incluyendo `permissions.ai_chat_summary`.
9. Se agregó el endpoint ligero
   `GET /api/ai/supervisor-chat/eligibility`, que devuelve si el supervisor
   puede usar el Chat IA y el número de pacientes elegibles, sin exponer datos
   sensibles.
10. Se normalizó la lectura de consentimientos legacy para devolver un estado
    canónico: `active`, `paused` o `revoked`.
11. Se añadieron reglas para elegir el consentimiento canónico más reciente
    cuando existen documentos duplicados para la misma pareja. Una revocación
    o pausa reciente bloquea permisos concedidos por documentos anteriores.
12. Se incorporó compatibilidad de lectura para aliases históricos del scope
    de IA (`ai_summary`, `chat_ai` y `summary_ai`), manteniendo
    `ai_chat_summary` como nombre oficial.
13. Se actualizó el contrato de Android con la nueva respuesta de permisos y
    el endpoint de elegibilidad.
14. Se agregaron y actualizaron pruebas unitarias y de contrato para scopes,
    consentimientos activos, pausados, revocados, duplicados, falta de scope,
    elegibilidad sin notas y protección de rutas.

## Imprevistos e inconvenientes encontrados

El principal inconveniente fue la existencia de documentos legacy de
consentimiento sin el campo `status`. El backend los interpretaba como activos
por compatibilidad, pero la aplicación Android podía descartarlos al requerir
explícitamente `status = active`.

También se encontraron varios documentos de consentimiento para la misma
relación supervisor-paciente: dos activos y uno revocado. Esto podía causar
resultados ambiguos si cada capa elegía un documento distinto. Para resolverlo,
se definió una regla de selección canónica basada en el registro más reciente.

Render no cuenta con un endpoint de versión o commit desplegado, por lo que no
fue posible confirmar directamente el hash exacto de la versión en producción.
Además, el endpoint nuevo de elegibilidad permanecerá inexistente en Render
hasta que se realice el despliegue autorizado.

## Resultado obtenido

La causa del mensaje incorrecto fue un desajuste entre datos legacy y el
contrato consumido por Android, no una falla del proveedor de IA. El backend ya
permitía el Chat IA cuando encontraba `ai_chat_summary`, pero Android no tenía
una forma estable y explícita de conocer esa elegibilidad.

Con la corrección, Android puede consultar directamente la elegibilidad o usar
`permissions.ai_chat_summary` en la lista de pacientes. La decisión no depende
de que existan notas recientes ni de contar con el scope separado
`patient_notes`.

## Evidencias de prueba

| Verificación | Resultado |
|---|---|
| Health de Render | 200, servicio saludable |
| Ruta inexistente en Render | 404 con envolvente estándar |
| Paciente vinculado para supervisor | Sí |
| Consentimiento activo con `ai_chat_summary` | Sí |
| Chat IA, modo `local` | 200, paciente elegible reconocido |
| Chat IA, modo `ai` | 200, respuesta disponible |
| Pruebas backend | 183 aprobadas, 1061 aserciones |
| Prueba de integración Firebase opt-in | Omitida por configuración (`RUN_FIREBASE_SMOKE`) |

También se ejecutaron `php artisan optimize:clear`, `php artisan route:list` y
la revisión de formato con Laravel Pint. No se modificaron documentos remotos,
no se realizó push y no se incluyeron contraseñas, tokens ni secretos en los
archivos versionados.

## Archivos modificados durante el cambio

- `app/Http/Controllers/Api/FirestoreCrudController.php`
- `app/Http/Controllers/SupervisorAIController.php`
- `app/Services/FirestoreAccessService.php`
- `routes/api.php`
- `docs/ANDROID_API_CONTRACT_V1.md`
- Pruebas de contrato, permisos, pacientes supervisados y alcance del Chat IA.

## Pendientes

