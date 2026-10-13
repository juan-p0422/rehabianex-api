# Etapa 9: reglas adaptativas experimentales

Fecha de revisión: 2026-10-12. Este contrato implementa ayudas configurables,
no un motor clínico. Los valores iniciales son de prueba y no se presentan como
umbrales validados, diagnóstico, predicción de recaída o indicador de eficacia.

## Estado comprobado

La biblioteca Android ya contiene recursos manuales breves. Para la primera
versión adaptativa se reutilizan exclusivamente:

- `grounding_54321`: sugerencia ante craving autorreportado que alcanza el
  valor de prueba configurado;
- `breathing_446`: sugerencia ante ansiedad autorreportada que alcanza el valor
  de prueba configurado.

La consulta manual de toda la biblioteca permanece disponible aunque las reglas
estén desactivadas. Las notificaciones locales, Room y WorkManager existentes
se reutilizan para el recordatorio de inactividad. Laravel no crea
intervenciones clínicas, no envía FCM por estas reglas y no avisa a supervisores
o contactos.

## Preferencias y compatibilidad

Se amplía el recurso existente `notification-settings` sin cambiar sus rutas:

| Campo | Tipo y límites | Valor si falta |
|---|---|---|
| `adaptive_suggestions_enabled` | booleano | `false` |
| `inactivity_reminder_enabled` | booleano | `false` |
| `inactivity_days` | entero 2 a 14 | `3` |
| `inactivity_reminder_time` | hora `HH:mm` | `18:00` |
| `adaptive_craving_threshold` | entero 6 a 10 | `8` |
| `adaptive_anxiety_threshold` | entero 6 a 10 | `8` |

Los dos valores se expresan en la escala API almacenada de 0 a 10. Android
presenta una escala de 0 a 5 y la normaliza multiplicando por dos antes de
guardar; por ello el valor experimental 8 equivale a 4/5 en la interfaz.

La respuesta también contiene `adaptive_rules_available` y
`adaptive_rules_version`. Son campos de solo lectura. La disponibilidad se
controla con `ADAPTIVE_SUPPORT_AVAILABLE`; al desactivarla Android cancela el
recordatorio adaptativo y no evalúa sugerencias, sin borrar ni cambiar notas.

Clientes anteriores ignoran los campos aditivos. Perfiles y ajustes ya
almacenados no se migran: la ausencia de los interruptores siempre significa
desactivado. El paciente propietario es el único que puede modificar estas
preferencias.

## Sugerencia inmediata

Solo se evalúa dentro de Android después de que el usuario guarda de forma
consciente un registro, tanto confirmado por servidor como pendiente offline.
Requiere disponibilidad global y `adaptive_suggestions_enabled=true`.

Orden de reglas `experimental-v1`:

1. Si `craving_level >= adaptive_craving_threshold`, sugerir
   `grounding_54321`.
2. En otro caso, si `anxiety_level >= adaptive_anxiety_threshold`, sugerir
   `breathing_446`.
3. En otro caso, no sugerir automáticamente.

Se muestra como máximo un recurso por registro. La pantalla explica que la
sugerencia se debe al valor autorreportado y que no es diagnóstico ni
predicción. Si el recurso no existe en el catálogo local, no se improvisa otro:
se mantiene el acceso manual a la biblioteca.

El backend persiste las escalas y preferencias, pero no calcula ni guarda una
clasificación adaptativa. Los campos históricos `ai_risk_score` y
`ai_risk_level` no forman parte de estas reglas.

## Recordatorio por inactividad

Es una notificación local privada y separada de la sugerencia inmediata. Solo
se programa con disponibilidad global, permiso Android y
`inactivity_reminder_enabled=true`. Usa las notas propias disponibles en Room,
funciona sin red, respeta la zona horaria y la hora elegida, y emite como máximo
un aviso por episodio de inactividad. Un nuevo registro rearma el episodio;
desactivar la preferencia, cerrar sesión o cambiar de usuario cancela el trabajo.

La versión `experimental-v1` considera inactividad después de una duración
exacta de `inactivity_days * 24 horas` desde el último registro y programa el
siguiente aviso en la hora local elegida. Usa la zona configurada; para datos
legacy sin una zona válida, Android usa explícitamente la zona del dispositivo.

El texto es neutral: invita a realizar un registro y nunca afirma consumo,
recaída, riesgo ni diagnóstico. La navegación abre Registro emocional y vuelve
a validar la sesión y el rol. No se envían datos a Laravel, FCM, supervisores ni
contactos como consecuencia del recordatorio.

## Pruebas de aceptación

- límites 2/14 días y 6/10 para ambas escalas;
- regla de craving, regla de ansiedad y precedencia cuando ambas aplican;
- motivo visible y recurso existente;
- ningún resultado con reglas desactivadas o disponibilidad global falsa;
- una sola sugerencia por registro y un aviso por episodio de inactividad;
- cancelación, nuevo registro, cambio de usuario y cierre de sesión;
- cálculo de hora/zona, reinicio, modo offline y recurso ausente;
- ausencia de FCM, destinatarios de terceros y lenguaje de recaída.

Las alertas automáticas a contactos quedan expresamente fuera de esta etapa.
