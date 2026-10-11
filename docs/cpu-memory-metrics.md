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
La página HTML `GET /cpu-memoria` conserva su comportamiento actual.
