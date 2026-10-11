# Proyecto_SysmonitorWeb
Proyecto Sysmonitor web del curso de sistemas operativos

## Documentación de módulos

- [M1 — Procesos: arquitectura, seguridad, ejecución local y pruebas](docs/M1_PROCESOS.md).
- [M2 — CPU y Memoria: uso, arquitectura, métricas, pruebas y limitaciones](docs/M2_CPU_MEMORIA.md).
- [Contrato JSON de métricas de M2](docs/cpu-memory-metrics.md).

## Panel de CPU y memoria

En una instancia Linux ya configurada, abrir `/cpu-memoria` para consultar CPU,
uptime, load average, memoria, caché, swap y rankings Top 5. El panel usa Blade,
actualización con JavaScript nativo y gráficas SVG con hasta 60 lecturas recientes
en memoria del navegador. No requiere npm ni librerías externas de gráficas.
El endpoint de solo lectura es `/cpu-memoria/metricas`.

La guía de M2 incluye comandos de validación con las dependencias existentes
y distingue las pruebas automatizadas de la revisión visual manual.
