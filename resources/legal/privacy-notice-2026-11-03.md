# Aviso de Privacidad de RehabiAnex

Versión 2026-11-03. Fecha de publicación: 3 de noviembre de 2026.

## Responsable y contacto

El responsable del proyecto es Juan Oswaldo Emilio Olivares Pantoja, quien radica en Tonalá, Jalisco, México. Los canales de atención informados para esta beta cerrada son el teléfono 33 2535 6657 y el correo rehabianex@gmail.com.

No se ha proporcionado un domicilio aplicable. La referencia a Tonalá, Jalisco, México indica solamente la ubicación general informada por el responsable y no sustituye un domicilio. No se utiliza el domicilio del CETI como domicilio del responsable ni se atribuye al CETI la responsabilidad del tratamiento, la operación de RehabiAnex o la atención de solicitudes. Mientras esos datos y el procedimiento de atención no estén completos, este aviso permanece como borrador operativo para beta cerrada y no constituye una certificación de cumplimiento legal integral.

La desactivación con borrado suave se inicia desde Control de datos en la aplicación. Para solicitar específicamente la eliminación definitiva de una cuenta se utiliza rehabianex@gmail.com. Ambos mecanismos son distintos y el correo no demuestra por sí solo la identidad de quien escribe.

## Participantes de la beta

La primera beta cerrada se limita a personas de 18 años o más. El backend rechaza registros nuevos de pacientes menores de 18 años. Esta restricción técnica no autoriza por sí sola la beta ni sustituye el procedimiento de selección de participantes.

La participación de menores permanece fuera de alcance hasta que se definan el fundamento, la intervención de quienes ejerzan patria potestad o tutela, el papel de los supervisores y mecanismos de verificación adecuados. Un supervisor de la aplicación no se convierte automáticamente en representante legal.

## Datos tratados

RehabiAnex trata los datos que la persona proporciona para crear y utilizar su cuenta: nombre, correo, teléfono, edad, rol y datos de perfil. Cuando la persona utiliza el seguimiento, puede tratar información sensible relacionada con ánimo, ansiedad, deseo de consumo o craving, sueño, energía, fecha de sobriedad, recaídas autorreportadas, detonantes, acciones de afrontamiento y notas personales.

También puede tratar eventos de agenda, contactos de apoyo, relaciones entre paciente y supervisor, solicitudes, estados y alcances de supervisión, preferencias de privacidad y notificaciones, consultas al chatbot y el contexto mínimo autorizado para responderlas. Para operar y proteger el servicio se procesan identificadores técnicos de cuenta y dispositivo, tokens de sesión y notificación, versión o plataforma de la aplicación, fechas de operación, estados de sincronización y registros técnicos de errores. El código revisado no incorpora analítica publicitaria ni seguimiento comercial.

## Finalidades necesarias

Los datos se utilizan para autenticar la cuenta, mostrar el perfil, conservar y sincronizar registros, operar la agenda y recordatorios, presentar recursos de apoyo, gestionar solicitudes iniciadas por la persona usuaria, proteger la sesión, prevenir duplicados y atender solicitudes sobre sus datos.

La aceptación legal del aviso y la confirmación de lectura se registran por versión. Para pacientes nuevos, el tratamiento de datos sensibles de seguimiento requiere un consentimiento expreso separado. La participación en la beta, la lectura del aviso y una casilla general no sustituyen ese consentimiento.

## Funciones opcionales y supervisión

Son opcionales la supervisión, cada alcance compartido, las notificaciones y el uso del chatbot. Aceptar o leer este aviso no activa un supervisor, no concede alcances y no otorga permisos de Android.

El código de supervisor únicamente permite crear una solicitud. El supervisor debe aceptarla y el paciente debe activar de forma explícita los alcances elegidos. El paciente puede pausar o revocar el consentimiento o solicitar la desvinculación. Estas acciones bloquean consultas futuras según su estado, pero no borran información que ya haya sido consultada. La supervisión es opcional y no sustituye atención profesional, servicios de emergencia ni representación legal.

La privacidad visual reduce la exposición de identidad en algunas pantallas, pero no implica anonimato total. Los identificadores técnicos y los datos de cuenta siguen siendo necesarios para operar el servicio.

## Proveedores y consultas de inteligencia artificial

La aplicación utiliza Firebase Authentication para identidad, Cloud Firestore para información remota y Firebase Cloud Messaging para notificaciones. El backend se aloja en Render. Cuando se utiliza el chatbot, el backend usa OpenRouter y el modelo configurado pertenece actualmente a la familia Google Gemini.

El backend envía al proveedor de inteligencia artificial la consulta y solamente el contexto autorizado y minimizado. No debe incluir alcances retirados ni ampliar permisos por instrucciones de la persona usuaria o del modelo. La API revisada no guarda un historial de las preguntas y respuestas del chatbot, pero el proveedor puede procesar temporalmente la solicitud conforme a sus propias condiciones. Los proveedores pueden procesar datos fuera de México. Las regiones contratadas, las cláusulas de transferencia y los periodos de conservación del proveedor siguen pendientes de confirmación.

## Persistencia y conservación

Los datos de cuenta y operación se conservan remotamente en Firebase y, cuando la función lo requiere, localmente en una base del dispositivo, preferencias protegidas de sesión, cachés y colas de sincronización separadas por identificador de cuenta. También existen tokens FCM, bitácoras operativas saneadas y controles de frecuencia necesarios para el funcionamiento y la seguridad.

Las instalaciones nuevas revisadas utilizan SQLCipher. La migración preservadora desde instalaciones antiguas con una base sin SQLCipher se verificó con datos sintéticos en API 26 y API 33, incluida la conservación de colas locales. La aplicación protege la clave de la base y los datos de sesión mediante Android Keystore y AES-GCM. Estas pruebas no garantizan el comportamiento de todos los fabricantes ni sustituyen la validación de cada actualización instalada.

No existe un periodo general de conservación confirmado ni un borrado automático integral verificable. Las reglas de copia de seguridad de Android excluyen la base, sesiones, claves y preferencias identificadas, pero no se ha verificado el comportamiento de todos los fabricantes o mecanismos de transferencia. Los logs revisados evitan contraseñas, tokens y cuerpos sensibles; pueden conservar datos técnicos y sus periodos operativos no están completamente definidos. Los proveedores conservan o eliminan información conforme a sus mecanismos y condiciones aplicables, que RehabiAnex no controla por completo.

## Acceso, rectificación, desactivación y eliminación

La exportación de datos por autoservicio no forma parte de esta beta cerrada. La persona puede consultar la información que la aplicación muestra y rectificar los campos habilitados. Para acceso a datos, correcciones que no estén disponibles en la aplicación, datos legacy o dudas de conservación se conserva el teléfono 33 2535 6657. Para solicitudes de cuenta y eliminación se utiliza rehabianex@gmail.com.

Control de datos ofrece dos mecanismos distintos:

1. **Desactivar mi cuenta y solicitar eliminación desde la aplicación.** Requiere autenticación reciente y confirmación reforzada. Cuando el servidor confirma la operación, bloquea el acceso y nuevas sincronizaciones, revoca sesiones y tokens de notificación, inactiva vínculos y consentimientos y marca los datos para revisión y eliminación. Es un borrado suave: no equivale necesariamente a eliminación física inmediata de todos los registros, respaldos, logs o datos ya procesados por terceros.
2. **Solicitar eliminación definitiva por correo.** La persona escribe a rehabianex@gmail.com con el asunto “Solicitud de eliminación de cuenta RehabiAnex” e indica únicamente la información mínima necesaria para identificar la cuenta. La solicitud requiere verificación de identidad antes de una eliminación definitiva. El procedimiento exacto de verificación, sus responsables operativos y sus plazos siguen pendientes de aprobación; abrir el cliente de correo o enviar un mensaje no acredita por sí solo que el proceso haya terminado.

Después de la confirmación de desactivación, Android debe cancelar trabajos pendientes y limpiar los datos locales del identificador correspondiente. Si la limpieza falla parcialmente, pueden permanecer datos cifrados, cachés, preferencias o elementos de cola hasta un nuevo intento; no deben sincronizarse bajo otra cuenta. El servidor conserva bloqueadas las nuevas sincronizaciones. En una expiración de sesión o cambio de cuenta distinto de la desactivación, la política final sobre conservar o purgar notas offline pendientes todavía debe definirse. La caché local no equivale a eliminar datos remotos.

No se deben enviar por correo contraseñas, tokens, notas emocionales, historial de consumo, datos de terceros, copias completas de registros ni documentos de identidad, salvo que exista un procedimiento justificado, seguro y previamente informado. Conocer el correo de una cuenta no basta para autorizar su eliminación.

## Protección de la información

RehabiAnex utiliza HTTPS/TLS para proteger el contenido mientras viaja entre la aplicación, Laravel, Firebase y el proveedor de inteligencia artificial configurado. Las instalaciones nuevas revisadas utilizan SQLCipher para la base local y Android Keystore con AES-GCM para proteger claves y sesión. También se aplican autenticación, controles de rol, vínculo, consentimiento y alcance, validación de entradas, límites de consumo y minimización del contexto enviado al chatbot.

Estas medidas no constituyen cifrado de extremo a extremo: Laravel, Firebase y el proveedor autorizado pueden acceder a la información necesaria para procesar sus funciones. Un UID u otra referencia seudónima no equivale a cifrado ni garantiza anonimato. No se garantiza seguridad absoluta.

## Cambios del aviso

Cada cambio material se publica con una versión y fecha nuevas. Las evidencias de versiones anteriores se conservan. Una cuenta existente debe leer la nueva versión y confirmar su lectura antes de continuar con las funciones protegidas; su aceptación legal anterior no se elimina ni se reemplaza. La nueva lectura no crea retroactivamente una aceptación de finalidades nuevas ni sustituye el consentimiento separado sobre datos sensibles, el consentimiento de supervisión o los permisos Android.

Las cuentas nuevas deben recibir el aviso vigente antes de aportar datos personales y aceptar la versión correspondiente durante el registro. Una confirmación fallida no se presenta como guardada. La aplicación puede conservar evidencia local de lectura para operar temporalmente sin conexión únicamente cuando coincide exactamente el identificador de cuenta y la versión; la confirmación remota se mantiene separada.

## Límites de RehabiAnex

RehabiAnex es una herramienta de registro y apoyo. No realiza diagnósticos, no prescribe tratamientos, no sustituye atención médica, psicológica o terapéutica y no garantiza atención de emergencias. Ante una urgencia, la persona debe utilizar los servicios de emergencia o atención profesional disponibles en su localidad.
