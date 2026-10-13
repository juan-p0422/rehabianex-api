# Etapa 10: accesibilidad y regresión

Fecha de ejecución: 2026-10-13. Esta matriz es evidencia nueva y no sustituye
ni modifica los resultados históricos del informe de revisiones. Cada caso se
evaluó contra el contrato actual mediante pruebas automatizadas, inspección
estática o ejecución en emulador. "Aprobado" solo describe el criterio y entorno
indicados; no certifica accesibilidad universal, eficacia clínica ni producción.

## Versiones y entorno

- Backend Laravel: rama `codex/revisiones-rehabianex-stage10`, base `9574bee`.
- Android: rama `codex/revisiones-rehabianex-stage10`, commit `56d3ca7`
  basado en `30f23c7`.
- Backend: PHP 8.2.12 en Windows, entorno de prueba local y dobles sintéticos.
- Android: Pixel 4 virtual, Android 13/API 33, 1080 x 2280; las pruebas de
  accesibilidad se repitieron con escala de fuente 1.5.
- No hay imagen ni AVD API 26 instalado. `minSdk=26` se verificó de forma
  estática, pero la ejecución exacta en API 26 quedó pendiente.
- No se usaron cuentas reales, no se enviaron avisos a contactos y no se
  desplegó la aplicación.

## Evidencia ejecutada

- Backend Laravel: 307 pruebas aprobadas, 1 integración con Firebase real
  omitida deliberadamente y 2,072 aserciones.
- Android JVM: 223/223 pruebas aprobadas.
- Android lint: 0 errores y 42 advertencias.
- Compilación de producción, APK debug y APK androidTest: correctas.
- Suite instrumentada completa en API 33 y escala de fuente 1.5: 29/29.
- Suite enfocada de accesibilidad en API 33 y escala de fuente 2.0: 6/6.
- La escala del emulador se restauró a 1.0 después de las pruebas.
- Inicio mediante `am start -W`: 2,808 ms, 2,671 ms y 2,324 ms en frío.
  La llamada ya visible reportó `TotalTime=0`, `WaitTime=14 ms` y
  `LaunchState=UNKNOWN`; no se interpreta como inicio instantáneo.
- Recorrido breve de segundo plano, reapertura y Atrás: sin ANR ni fatalidad
  observados en esa ejecución. No sustituye una prueba prolongada.

## Resultados de los 75 casos

| Caso | Nombre | Resultado actual | Evidencia y límite |
|---:|---|---|---|
| 001 | Registro anónimo integral | Aprobado | Contratos de registro/proyección anónima y regresión Android. No implica anonimato absoluto. |
| 002 | Aviso de privacidad previo y consultable | Aprobado | Pruebas de aceptación legal y presentación Android. |
| 003 | Separación de consentimientos y permisos | Aprobado | Contratos de aceptación legal, consentimiento y permiso Android separados. |
| 004 | Rechazo de permiso opcional | Aprobado | Flujo Android conserva uso básico sin permiso. |
| 005 | Cambio posterior de privacidad y consentimiento | Aprobado | Persistencia, proyección privada y ciclo de consentimiento cubiertos. |
| 006 | Configuración inicial del perfil | Aprobado | Validaciones Android/backend y regresión de formularios. |
| 007 | Validación de configuración incompleta | Aprobado | Límites, campos obligatorios y mensajes 422 cubiertos. |
| 008 | Rectificación del perfil | Aprobado | Allowlist de propietario, teléfono y correo verificado cubiertos. |
| 009 | Persistencia segura del perfil | Aprobado | Caché cifrada, consulta remota y cambio de usuario cubiertos. |
| 010 | Perfil sin conexión | Fallido parcial | La consulta local funciona; la edición offline de nombre/teléfono se rechaza explícitamente y no se encola. Credenciales y correo tampoco se encolan, por diseño seguro. |
| 011 | Registro emocional completo | Aprobado | Validación y persistencia de escalas, detonantes, acciones y texto. |
| 012 | Registro sin nota libre | Aprobado | Nota opcional cubierta. |
| 013 | Selección consciente de escalas | Aprobado | Estado sin responder separado de cero y validado. |
| 014 | Craving alto y apoyo adaptativo | Aprobado | Regla experimental opt-in y recurso existente probados. |
| 015 | Registro emocional offline | Aprobado | Room, WorkManager, reconexión e idempotencia cubiertos. |
| 016 | Historial semanal | Aprobado | Periodo de siete días y rango visible cubiertos. |
| 017 | Historial mensual | Aprobado | Selector mensual, rango y datos escasos cubiertos. |
| 018 | Intervención por ansiedad alta | Aprobado | Regla experimental y respiración breve existentes. |
| 019 | Biblioteca manual de intervenciones | Aprobado | Recursos manuales permanecen accesibles sin regla. |
| 020 | Regla por patrón o inactividad | Aprobado | Recordatorio local opt-in; no interpreta inactividad como recaída. |
| 021 | Alta de reunión | Aprobado | Contrato y formulario de agenda. |
| 022 | Alta de cita con nota opcional | Aprobado | Nota opcional y validación de fecha/hora. |
| 023 | Recordatorio local de agenda | Aprobado | Programación local, reinicio y navegación segura. |
| 024 | Permiso de notificaciones desactivado | Aprobado | Banner, acceso a Ajustes y degradación segura. |
| 025 | Recordatorio adaptativo JITAI | Aprobado | Preferencias, horario, máximo por episodio y desactivación cubiertos. |
| 026 | Datos verificables del directorio | Parcial | La UI muestra fuente/fecha solo cuando existen y `No disponible` si faltan; no se revalidó externamente la calidad clínica de cada recurso. |
| 027 | Filtro por tipo de recurso | Aprobado | Filtros y lista cubiertos por regresión. |
| 028 | Directorio sin internet | Aprobado | Caché y estado offline cubiertos. |
| 029 | Recurso con datos incompletos | Aprobado | Presentación explícita de datos ausentes. |
| 030 | Actualización al recuperar conexión | Aprobado | Actualización de caché y recuperación cubiertas. |
| 031 | Alta y consulta de contacto | Aprobado | CRUD y categorías generales cubiertos. |
| 032 | Validación de contacto | Aprobado | Teléfono México, prioridad 1-5 y texto legítimo cubiertos. |
| 033 | Solicitud de apoyo discreta | Aprobado | Destinatario/canal visible y apertura segura del marcador. |
| 034 | Apoyo sin contactos | Aprobado | Estado vacío ofrece configuración y no simula llamada. |
| 035 | Alerta automática opcional | No ejecutado | Función no implementada: requiere diseño de destinatario, canal, consentimiento, contenido, cancelación y límites. |
| 036 | Cálculo de días sobrios | Aprobado | Cálculo por fecha civil y zona México cubierto. |
| 037 | Actualización temporal del contador | Aprobado | Recomputación por fecha cubierta. |
| 038 | Cambio de fecha de sobriedad | Aprobado | Confirmación, límites de fecha e impacto explicado. |
| 039 | Logros por hitos | Aprobado | Hitos existentes y próximo logro visible. |
| 040 | Métricas de eventos y asistencia | Aprobado | Estado del evento y asistencia autorreportada separados. |
| 041 | Cifrado local de datos sensibles | Aprobado | SQLCipher y apertura/migraciones instrumentadas. |
| 042 | Exportación de datos | Aprobado | Allowlist del titular, archivo privado temporal y cabeceras seguras. |
| 043 | Rectificación de datos | Aprobado | Campos editables y confirmación local/remota cubiertos. |
| 044 | Eliminación de datos | Aprobado | Reautenticación, bloqueo de sincronización y fallo parcial cubiertos; no promete borrar respaldos externos. |
| 045 | Denegación por rol y consentimiento | Aprobado | Pruebas negativas de autenticación, rol y alcance. |
| 046 | Sincronización con red | Aprobado | Lote de notas pendientes y propietario validados. |
| 047 | Conservación pendiente sin red | Aprobado | Estado pendiente persistente cubierto. |
| 048 | Reintento al recuperar red | Aprobado | Backoff y estados reintentable/ambiguo cubiertos. |
| 049 | Control de duplicados y aislamiento | Aprobado | `client_mutation_id`, propietario y conflicto cubiertos. |
| 050 | Fallo temporal del backend | Aprobado | Mensajes seguros, conservación local y reintentos delimitados. |
| 051 | Resolución de supervisor habilitado | Aprobado | Código y proyección segura cubiertos. |
| 052 | Solicitud confirmada por el paciente | Aprobado | Ciclo de solicitud y vínculo cubierto. |
| 053 | Bloqueo antes de aceptación | Aprobado | Solicitud pendiente no concede acceso. |
| 054 | Selección de datos compartidos | Aprobado | Activación exige alcances explícitos; no activa todos silenciosamente. |
| 055 | Consulta limitada a permisos vigentes | Aprobado | Cada recurso exige su propio alcance. |
| 056 | Pausa y revocación de acceso | Aprobado | Transiciones y bloqueo posterior cubiertos. |
| 057 | Bloqueo posterior a revocación | Aprobado | Revocación prevalece incluso con datos legacy inconsistentes. |
| 058 | Autorización administrativa | Aprobado | Estado pendiente/activo y guardas administrativas. |
| 059 | Suspensión y reactivación administrativa | Aprobado | Transiciones y respuestas incompatibles cubiertas. |
| 060 | Autorización sin acceso automático | Aprobado | Habilitación administrativa no crea vínculo ni consentimiento. |
| 061 | Elegibilidad del Chat IA | Aprobado | Rol, habilitación, vínculo y alcance validados antes de contexto. |
| 062 | Resumen con periodo indicado | Aprobado | Periodo calendario y datos disponibles en contexto. |
| 063 | Resumen con datos faltantes | Aprobado | Fallback local reconoce límites sin inventar valores. |
| 064 | Inactividad sin inferir recaída | Aprobado | Política adaptativa y seguridad del chat cubiertas. |
| 065 | Validación previa en backend | Aprobado | Token, rol, estado, vínculo y alcance cubiertos. |
| 066 | Intento de ampliar permisos por prompt | Aprobado | Permisos se resuelven antes del proveedor; prompt no los modifica. |
| 067 | Rechazo de diagnóstico | Aprobado | Control previo local y filtro posterior cubiertos. |
| 068 | Rechazo de medicación o prescripción | Aprobado | Solicitudes clínicas se rechazan sin consumir proveedor. |
| 069 | Demora o indisponibilidad de IA | Aprobado | Timeout, error temporal y fallback identificado cubiertos. |
| 070 | Límites de consumo y secretos | Aprobado | Cuota por supervisor, tamaño máximo y configuración externa probados; búsqueda de patrones no encontró secretos literales versionados. |
| 071 | Tiempo de inicio frío y caliente | Sin conclusión | Fríos: 2,808/2,671/2,324 ms. Llamada ya visible: `TotalTime=0`, `WaitTime=14 ms`, `LaunchState=UNKNOWN`. Sin umbral aprobado no hay veredicto. |
| 072 | Compatibilidad Android 8.0 | Parcial | `minSdk=26` y compilación son correctos; instalación/ejecución en API 26 no ejecutada por falta de imagen/AVD. |
| 073 | Accesibilidad básica | Parcial | Semántica, contraste, áreas táctiles y fuente 150% se prueban en API 33; recorrido manual completo con TalkBack no ejecutado. |
| 074 | Estabilidad y recuperación | Aprobado en entorno evaluado | Regresión, reinicio, errores de red y emulador sin ANR observado; no sustituye una prueba prolongada o monitoreo productivo. |
| 075 | Navegación y organización arquitectónica | Aprobado | Recorridos principales y separación UI/ViewModel/repositorio conservados. |

## Resumen y riesgos de liberación

- 69 casos aprobados en el alcance evaluado.
- 1 caso fallido parcialmente: 010, edición de perfil offline.
- 3 casos parciales: 026, 072 y 073.
- 1 caso no ejecutado/fuera de alcance: 035.
- 1 caso sin conclusión por falta de umbral: 071.
- No se verificó TalkBack manual completo, API 26 exacto, calidad clínica externa del
  directorio, carga sostenida ni proveedores reales.
- Una aprobación automatizada no equivale a conformidad legal, clínica o de
  accesibilidad integral.
