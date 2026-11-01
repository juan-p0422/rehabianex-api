# RehabiAnex: evaluación del candidato final de beta

Fecha de verificación: 2026-11-01 (America/Mexico_City).

## Conclusión

**B. Solo QA interna: bloqueantes pendientes.**

No se generó un APK final ni un SHA-256. Crear un binario para distribución en este estado ocultaría dos incompatibilidades confirmadas: el registro Android de paciente no envía el consentimiento separado que exige el backend y una instalación release con una base Room antigua sin cifrar no dispone de migración conservadora a SQLCipher.

No se desplegó, publicó ni distribuyó ningún artefacto.

## Versiones revisadas

### Backend candidato

- Repositorio: `rehabianex-api`.
- Rama: `codex/privacy-notice-2026-10-30-backend`.
- Commit verificado y publicado: `c53480b089f62278874ab61e80fef7a797ee6df5`.
- Commits funcionales de esta consolidación:
  - `aeb60bf` — consentimiento sensible separado en registro beta;
  - `3d3e6a6` — retiro de exportación y desactivación de cuenta;
  - `c53480b` — identificación del backend y endurecimiento de rutas compatibles.
- Estado Git al cierre: árbol limpio y rama remota verificada con el mismo hash.

### Android revisado

- Repositorio: `rehabianex`.
- Rama: `codex/privacy-notice-2026-10-30`.
- HEAD: `8c3bd5e`.
- Estado: contiene cambios locales de varias tareas; no se sobrescribieron ni consolidaron en esta revisión.
- Configuración observada: `applicationId=com.example.rehabianex`, `minSdk=26`, `targetSdk=36`, `versionCode=1`, `versionName=1.0`.
- No existe una configuración de firma beta verificable en el archivo de compilación revisado.

### Backend desplegado

- URL: `https://rehabianex-api.onrender.com/api/`.
- Salud pública observada: `ok=true`, `status=healthy`.
- Aviso servido el 2026-11-01: versión `2026-10-18`.
- La respuesta de salud no identifica commit o release.
- Por lo anterior, el despliegue no puede atribuirse al commit candidato y además conserva un aviso anterior. No se sumaron pruebas de ese entorno a las del candidato.

## Bloqueantes confirmados

1. **Registro Android incompatible con el contrato actual.** El backend exige para paciente `sensitive_data_consent_accepted=true` y `sensitive_data_consent_version=2026-10-27`. El DTO y la pantalla Android revisados solo envían la aceptación/versionado del aviso. Resultado previsto y cubierto por pruebas backend: HTTP 422 para el registro de paciente actual.
2. **Actualización con base local anterior no es segura.** Android detecta una base Room SQLite sin cifrar; en debug la elimina y en release rechaza abrirla. No existe una migración `sqlcipher_export` o equivalente con verificación, intercambio atómico y rollback. Esto puede bloquear el arranque de una instalación existente o perder datos en QA debug.
3. **Backend candidato no desplegado e identificable.** Render mantiene el aviso `2026-10-18` y `/api/health` no expone un identificador de release. No puede validarse el flujo móvil completo contra el código candidato.

## Hallazgos importantes no bloqueantes por sí solos

- La limpieza local posterior a la desactivación Android agrupa operaciones tolerantes a fallo y luego borra la sesión; no hay marcador persistente de reintento para una limpieza parcial.
- Falta una prueba extremo a extremo que confirme que una cuenta sintética desactivada no vuelve a iniciar sesión y que su cola no recrea datos.
- No se verificó una firma que permita actualizar una instalación previa. Una release no debe asumirse compatible con una instalación debug.
- El repositorio Android tiene cambios locales concurrentes y artefactos sin seguimiento. Requiere revisión y commits separados antes de producir el binario.

## Pruebas ejecutadas

### Backend candidato

- Suite completa: **339 aprobadas, 2 287 aserciones, 1 omitida**.
- Omitida: integración contra Firebase real; requiere `RUN_FIREBASE_SMOKE=true` y un entorno sintético autorizado.
- Registro/privacidad: **34 aprobadas, 220 aserciones**.
- Control de datos: **12 aprobadas, 93 aserciones**.
- Contratos Android, salud y resolución de supervisor: **54 aprobadas, 197 aserciones**.
- `git diff --check`: aprobado antes de los commits finales.
- Escaneo de patrones de secretos de alta confianza: sin coincidencias. `.env` no está rastreado; `.env.example` no contiene valores secretos.

### Android

- Dispositivo detectado: emulador `sdk_gphone64_x86_64`, API 33.
- Dispositivo físico: **no disponible** durante esta verificación.
- Suite unitaria solicitada dos veces: **no ejecutada**; Gradle falló antes de iniciar pruebas con `java.io.IOException: Unable to establish loopback connection`.
- Evidencia previa del mismo HEAD para presentación del aviso: 4/4 instrumentadas en API 33. Se considera evidencia enfocada, no regresión completa ni prueba del futuro APK.

## Casos no ejecutados sobre un APK exacto

Registro Firebase + Laravel, perfil, diario offline/reconexión, cambio de cuenta, vinculación y consentimiento, chatbot, agenda/FCM, desactivación sintética real, bloqueo posterior, cola posterior a desactivación, migración desde datos existentes, instalación/actualización, firma, dispositivo físico y API 26 exacta.

Estos casos son **no ejecutados**, no aprobados.

## Condiciones para producir el APK beta

1. Alinear Android con el consentimiento sensible separado y probar registro convencional de paciente y registro de supervisor.
2. Implementar y probar una migración conservadora de Room sin cifrar a SQLCipher, o definir formalmente que no se admite actualización conservando datos. No borrar datos para simular una migración exitosa.
3. Hacer persistente y reintentable la limpieza local posterior a desactivación.
4. Desplegar el commit backend acordado en un entorno beta identificable y verificar aviso `2026-10-30` y contratos.
5. Definir `versionCode`/`versionName` de beta y firma privada compatible. Mantener claves y mapas fuera del repositorio.
6. Ejecutar las pruebas prioritarias sobre el APK exacto en API 26, una versión reciente y un dispositivo físico autorizado.
7. Solo entonces generar el APK, comprobar `android:debuggable=false`, validar ausencia de herramientas peligrosas, instalar/actualizar y calcular SHA-256.

## Instalación y actualización

No hay instrucciones de instalación para participantes porque no existe un candidato apto. No desinstalar la aplicación ni borrar almacenamiento si hay registros pendientes. Una futura actualización solo conservará datos si coincide `applicationId`, la firma es compatible y las migraciones resultan aprobadas.

## Datos para la futura guía

- Estado: borrador interno; no distribuir.
- Aviso esperado del candidato: `2026-10-30`.
- Consentimiento sensible separado esperado: `2026-10-27`.
- Exportación de autoservicio: retirada; la ruta legacy autenticada responde 410 cuando está deshabilitada.
- Control de cuenta: desactivación/borrado suave desde la app y solicitud de eliminación definitiva por el canal documentado, sin envío automático.
- APK, SHA-256 y firma de referencia: pendientes.

