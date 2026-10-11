# Contrato de métricas de CPU y memoria

`GET /cpu-memoria/metricas` — nombre de ruta `cpu-memory.metrics`.
Endpoint de solo lectura. No acepta parámetros para seleccionar archivos ni ejecutar acciones.
Las respuestas son JSON con `Cache-Control: no-store`.

## Respuesta HTTP 200

- `status`: string `ok`.
- `cpu`: `usage_percent` (número entre 0 y 100), `model` (string),
  `logical_processors` (entero).
- `uptime`: `seconds` (número, conserva decimales), `formatted` (string).
- `load_average`: `one_minute`, `five_minutes`, `fifteen_minutes` (números).
- `memory`: `total_bytes`, `used_bytes`, `free_bytes`, `cached_bytes` (enteros).
- `swap`: `total_bytes`, `used_bytes`, `free_bytes` (enteros).
- `top_processes.cpu`: lista de hasta cinco objetos con `pid` (entero)
  y `cpu_percent` (número).
- `top_processes.memory`: lista de hasta cinco objetos con `pid` (entero)
  y `memory_percent` (número).
- `timestamp`: string ISO 8601 en UTC, generado al completar la recolección.

Una métrica no disponible se representa con `null`, conservando todas las claves.
Los rankings sin datos son listas vacías. Cero es un valor válido, incluida swap deshabilitada.
Cada ranking se ordena por consumo descendente y por PID ascendente en empates.
Ambos usan una sola lectura de ProcessService de M1. El porcentaje por proceso
conserva la semántica de M1 y puede superar 100; el porcentaje global se calcula
con los dos snapshots del servicio de CPU existente y añade una espera de 100 ms.
Los comandos y demás campos internos de procesos se excluyen del JSON.

## Respuesta HTTP 503

Una excepción durante la recolección o serialización produce únicamente:

```json
{
  "status": "error",
  "error": {
    "code": "metrics_unavailable",
    "message": "No se pudieron obtener las métricas del sistema."
  },
  "timestamp": "2026-10-10T12:00:00+00:00"
}
```

No se incluyen rutas internas, comandos, mensajes de excepción ni stack traces.
## Actualización del panel (Paso 9)

La página HTML `GET /cpu-memoria` conserva el renderizado inicial de Blade,
incluidos los comandos escapados de los rankings. Funciona sin JavaScript.
Con JavaScript, se consulta este endpoint cinco segundos después de cargar y
cinco segundos después de terminar cada petición. Nunca hay consultas concurrentes;
una petición que supera diez segundos se cancela y después se reintenta.

La interfaz valida la respuesta antes de reemplazar valores, actualiza con
`textContent` y APIs DOM y conserva la última lectura válida ante errores HTTP,
de red, de JSON o de estructura. El estado se anuncia mediante una región accesible
y la hora correcta se obtiene del timestamp del servidor, mostrada en hora local
del navegador. Los temporizadores se cancelan al salir de la página.

Los rankings dinámicos muestran PID y porcentaje; la columna Comando pasa a
“No disponible”, porque el contrato no entrega comandos. No se reutilizan comandos
antiguos para PIDs que pueden haberse reutilizado. No hay almacenamiento
persistente ni llamadas a otros endpoints.

## Gráficas del panel (Paso 10)

Dos SVG nativos usan las mismas respuestas correctas del ciclo de fetch.
El historial compartido conserva hasta 60 lecturas en memoria del navegador;
desaparece al recargar y no utiliza almacenamiento persistente. Se registra
CPU global y memoria utilizada como `100 * used_bytes / total_bytes`.
No se calcula memoria con total cero, valores ausentes, no finitos o usada
mayor que total. Las series se mantienen entre 0 y 100 %.

Solo se registran timestamps válidos y posteriores al último; sin timestamp
o sin ninguna de las dos métricas válidas no se añade una muestra. Una serie
no disponible deja un hueco cuando la otra sí tiene un dato correcto.
Los errores de consulta conservan el historial. El eje horizontal usa tiempos
reales del servidor y muestra al menos un minuto, ampliándose al intervalo
de las muestras retenidas. El eje vertical siempre es 0–100 %.

Cada SVG contiene como máximo 60 puntos y un trazado dinámicos, sin acumular
nodos. Hay títulos y descripciones accesibles, además de una alternativa
textual visible con las últimas cinco lecturas de cada serie. Sin JavaScript
permanecen las tarjetas y rankings iniciales del servidor.
