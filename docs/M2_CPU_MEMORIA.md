# M2 — CPU y Memoria

## Objetivo y uso

M2 observa recursos del sistema Linux que ejecuta SysMonitor. No inicia procesos,
no envía señales ni cambia prioridades. Reutiliza ProcessService de M1 para sus
rankings, conservando las rutas y el comportamiento de ese módulo.

Abrir `/cpu-memoria` en la instancia existente. El panel presenta modelo CPU,
procesadores lógicos/vCPU (no núcleos físicos), uptime, CPU global %, load 1/5/15,
memoria total/usada/libre/caché, swap total/usada/libre y rankings Top 5 independientes.
También muestra gráficas temporales de CPU y memoria utilizada, estado de actualización
y hora local del navegador correspondiente a la última lectura correcta del servidor.
El enlace “Ver procesos” abre M1.

Las tarjetas y tablas iniciales se renderizan con Blade. Sin JavaScript permanecen
visibles, pero no se actualizan ni generan muestras para las gráficas.

## Requisitos del entorno

- Linux con `/proc/cpuinfo`, `/proc/stat`, `/proc/uptime`, `/proc/loadavg` y
  `/proc/meminfo` legibles para el usuario que ejecuta PHP.
- PHP 8.2 o posterior, Laravel 12 y dependencias existentes en `vendor/`.
  Las pruebas Feature usan SQLite según `phpunit.xml`.
- Para los rankings, el ProcessService de M1 requiere `proc_open()` y su ejecutable
  fijo de ps disponible en Linux. No se añade otro ejecutor de procesos.
- Navegador moderno con fetch, AbortController, DOM y SVG para refresco y gráficas.
  Node existente con `node:test` permite ejecutar las pruebas JavaScript.

Esta guía presupone una aplicación ya configurada. M2 no requiere npm, frameworks,
librerías externas de gráficas, tablas nuevas, servicios AWS ni cambios del servidor.
Los comandos siguientes no instalan dependencias ni modifican archivos de entorno.

## Rutas y arquitectura

| Ruta GET | Nombre | Respuesta |
| --- | --- | --- |
| `/cpu-memoria` | `cpu-memory.index` | HTML Blade con métricas iniciales y script diferido. |
| `/cpu-memoria/metricas` | `cpu-memory.metrics` | JSON de solo lectura: HTTP 200 o error controlado HTTP 503. |

Ambas usan CpuMemoryController y el middleware web existente. No aceptan parámetros
para seleccionar archivos, ejecutar comandos o administrar procesos. Los tipos y
campos públicos se describen en [el contrato JSON](cpu-memory-metrics.md).

| Componente | Responsabilidad |
| --- | --- |
| `app/Http/Controllers/CpuMemoryController.php` | Coordina servicios, entrega Blade y selecciona campos públicos del JSON. |
| `app/Services/System/CpuInfoService.php` | Lectura fija y parsing de cpuinfo, uptime y loadavg. |
| `app/Services/System/CpuUsageService.php` | Lectura de stat, parsing global y cálculo por deltas entre dos snapshots. |
| `app/Services/System/MemoryService.php` | Lectura de meminfo, conversión a bytes y cálculos de memoria/swap. |
| `app/Services/System/ProcessRankingService.php` | Filtra y ordena listas ya leídas, sin ejecutar comandos. |
| `app/Services/System/ProcessService.php` | Servicio de M1 reutilizado una vez por petición para ambos rankings. |
| `resources/views/system/cpu-memory.blade.php` | Tarjetas, tablas, SVG, estados accesibles y escape inicial. |
| `public/js/cpu-memory.js` | Un único ciclo de fetch, DOM seguro, cancelación e historial acotado. |

Las lecturas de /proc usan constantes privadas y `file_get_contents()`, sin comandos
externos. Parsers y cálculo CPU pueden probarse con strings y snapshots simulados.

## Fuentes, unidades y fórmulas

| Métrica | Fuente y criterio |
| --- | --- |
| Modelo CPU | Primer `model name` no vacío con UTF-8 válido en cpuinfo. |
| Procesadores lógicos | Identificadores `processor` enteros no negativos distintos. |
| Uptime | Primer número de uptime; float en segundos. Se valida también el segundo campo. |
| Uptime legible | Días, horas, minutos y segundos completos; decimales truncados en la presentación. |
| CPU global | Primer renglón global cpu de stat; dos lecturas separadas por 100 ms. |
| Load average | Primeros tres números finitos no negativos de loadavg; no son porcentajes. |
| Memoria libre | MemFree limitada al total. |
| Caché | Cached + SReclaimable limitada al total; la cifra mostrada no incluye Buffers. |
| Swap usada | SwapTotal menos SwapFree limitada al total; swap deshabilitada produce ceros válidos. |

CPU usa user, nice, system, idle, iowait, irq, softirq y steal. Se calcula cada delta
como contador posterior menos anterior:

```text
delta_total = suma de los ocho deltas
delta_inactivo = delta_idle + delta_iowait
CPU % = 100 * (delta_total - delta_inactivo) / delta_total
```

Campos adicionales como guest se validan sin sumarlos de nuevo. Contadores negativos,
regresiones y delta total cero producen indisponibilidad. CPU global queda en 0–100 %.

Memoria mantiene enteros en bytes: kB de meminfo × 1024. La interfaz presenta MiB
(bytes / 1048576) con dos decimales. MemTotal, MemFree, SwapTotal y SwapFree son
obligatorios. Un dato obligatorio ausente, MemTotal cero o una línea reconocida
inválida hace indisponible todo el bloque de memoria/swap. Claves opcionales
Cached, SReclaimable y Buffers ausentes aportan cero.

```text
Con MemAvailable: disponible = min(MemAvailable, MemTotal)
Sin MemAvailable: disponible estimada = min(MemFree + Buffers + Cached + SReclaimable, MemTotal)
memoria usada = MemTotal - disponible
memoria usada % = 100 * used_bytes / total_bytes
```

Las sumas se acumulan hasta el total y se valida la conversión a bytes, evitando
negativos y desbordamientos. La gráfica no divide por total cero ni acepta datos
no finitos, ausentes o usada mayor que total. Libre y disponible son conceptos
distintos: usada, libre y caché no necesariamente suman el total. El fallback es
una estimación más simple que MemAvailable.

## Rankings

Ambos usan la misma lectura de M1, con cálculo independiente. El orden es consumo
descendente y PID ascendente en empates, máximo cinco filas y una entrada por PID.
Se descartan filas inválidas, comandos vacíos y porcentajes negativos/no finitos.
Memoria por proceso está entre 0 y 100; CPU por proceso puede superar 100, conservando
la semántica de M1. Un fallo de lectura de procesos devuelve listas vacías.

Blade muestra comandos escapados al cargar. El JSON los excluye: después de refrescar,
la columna Comando muestra “No disponible”. No se asocia un PID recibido con un
comando antiguo potencialmente reutilizado.

## Actualización y gráficas

La primera consulta ocurre cinco segundos después de cargar el script. La siguiente
se programa cinco segundos después de terminar la anterior, incluso si falló. El
período real incluye la duración de consulta. Un guard evita concurrencia; se cancela
la petición a los diez segundos. Al salir se cancelan petición y temporizador; el
regreso desde la caché de navegación reinicia un único ciclo.

Las mismas respuestas correctas actualizan tarjetas, rankings y dos SVG. No hay
peticiones adicionales. El historial compartido conserva hasta 60 muestras solo en
memoria del navegador; desaparece al recargar, sin localStorage, base de datos ni
archivos. Se registran timestamps válidos y estrictamente posteriores al último,
con al menos una serie disponible. Valores nulos dejan huecos; fallos de consulta
no añaden muestras ni borran el historial.

El eje vertical es 0–100 %. El eje temporal usa horas locales a partir del timestamp
real del servidor, con ventana mínima de un minuto y extensión hasta cubrir las
muestras retenidas. Cada SVG tiene hasta 60 puntos y un trazado dinámico. Títulos,
descripciones y cinco lecturas recientes en texto ofrecen una alternativa accesible.
CSS adapta columnas y gráficas; tablas desplazables permiten uso con teclado.

## Indisponibilidad, HTTP y estados

- Archivos ausentes, inaccesibles o vacíos producen datos no disponibles.
- HTTP 200 con status ok puede contener null y rankings vacíos; no garantiza
  disponibilidad total. Las claves públicas se mantienen.
- Excepciones de recolección o serialización producen el HTTP 503 genérico del
  contrato, sin rutas internas, comandos, mensajes de excepción ni stack traces.
  Las respuestas JSON incluyen `Cache-Control: no-store`.
- HTML controla excepciones por grupo de servicio: conserva secciones disponibles
  y muestra un aviso genérico, sin romper el panel.
- JavaScript valida antes de actualizar. Fallos HTTP, de red, JSON o estructura
  mantienen los últimos datos correctos y reintentan. El mensaje de actualización,
  éxito o error usa `role=status` y `aria-live=polite`.
- Null y campos individuales ausentes muestran “No disponible”; cero sigue siendo
  válido. Sin timestamp correcto no se registra una muestra gráfica.

El DOM dinámico usa APIs DOM y textContent, sin datos del endpoint en innerHTML.
Blade escapa los valores del servidor mediante `{{ }}`.

## Pruebas sin instalaciones

Ejecutar desde la raíz del proyecto con PHP, vendor y Node ya disponibles. Si Node
no está en PATH, sustituir `node` por el ejecutable existente del entorno, sin npm
ni instalaciones. No usar scripts de instalación para estas validaciones.

```bash
node --check public/js/cpu-memory.js
node tests/JavaScript/cpu-memory.test.cjs
php artisan test --filter='CpuInfoServiceTest|CpuUsageServiceTest|MemoryServiceTest|ProcessRankingServiceTest|ProcReadFailureTest|CpuMemoryTest|CpuMemoryMetricsTest'
php artisan route:list --path=cpu-memoria -v
php vendor/bin/pint --test
php artisan test
```

`php artisan route --path=cpu-memoria -v` no es un subcomando válido en esta versión:
el comando funcional para listar rutas es `route:list`.

Para sintaxis PHP, ejecutar `php -l` sobre cada componente PHP/Blade de la tabla de
arquitectura y `routes/web.php`, además de estos archivos de prueba:

- `tests/Unit/CpuInfoServiceTest.php`
- `tests/Unit/CpuUsageServiceTest.php`
- `tests/Unit/MemoryServiceTest.php`
- `tests/Unit/ProcessRankingServiceTest.php`
- `tests/Unit/ProcReadFailureTest.php`
- `tests/Support/ProcFileReadStub.php`
- `tests/Feature/CpuMemoryTest.php`
- `tests/Feature/CpuMemoryMetricsTest.php`

Los Unit usan contenido controlado; fallos de archivos se simulan en procesos
PHPUnit aislados sin tocar /proc. Feature cubre rutas, escape, renderizado, tipos,
contrato JSON, rankings y errores con debug activo. JavaScript usa DOM, fetch y
relojes simulados para porcentajes, historial acotado, límites de nodos SVG,
errores, cancelación y ausencia de peticiones duplicadas. La suite Laravel completa
incluye la regresión de M1; no ejecuta las pruebas Node automáticamente.

## Limitaciones y revisión manual

- El ámbito observado es el que Linux expone a PHP; en VM o contenedores no se
  garantiza correspondencia con límites de cgroups o con el host.
- Otras arquitecturas pueden no ofrecer model name; en ese caso el modelo no está disponible.
- CPU global es un intervalo breve y puede fluctuar. Si iowait retrocede, esa
  medición se descarta. CPU por proceso proviene de ps y no usa ese mismo intervalo.
- Load average no equivale a CPU % ni se normaliza por vCPU.
- Archivos y procesos se leen sucesivamente; no constituyen una fotografía atómica.
- El fallback de memoria no reproduce toda la estimación del kernel y la caché
  mostrada no descuenta Shmem. Caché ausente aporta cero.
- Los tests Node no validan disposición visual, SVG real, zoom, lectores de pantalla
  ni compatibilidad de navegador. Algunas pruebas existentes de M1 usan Linux real.

Para revisión manual en la instancia existente: comprobar tarjetas y rankings sin
JavaScript; habilitarlo y observar varias actualizaciones, hora y gráficas; revisar
escritorio/móvil y teclado; simular desconexión en las herramientas del navegador
para verificar conservación y recuperación. Esta lista no afirma que dichos pasos
se hayan ejecutado. La validación automatizada no reemplaza esa revisión visual.

## Registro de validación del Paso 12

Validación ejecutada el 10 de octubre de 2026, sin cambios de código funcional:

| Comprobación | Resultado observado |
| --- | --- |
| PHP/Blade, incluidos rutas, componentes M2, ProcessService reutilizado y pruebas | 16 archivos sin errores de sintaxis. |
| JavaScript, `node --check` | Sin errores; salida 0. |
| Pruebas JavaScript con DOM simulado | 15 aprobadas, 0 fallos, 0 omitidas. El runner no enumera un total de assertions. |
| Pruebas específicas M2 | 171 aprobadas, 837 assertions, 0 fallos. |
| Suite Laravel completa, incluida M1 | 481 aprobadas, 6915 assertions, 0 fallos. |
| Pint, `--test` | passed; salida 0. |
| `route:list --path=cpu-memoria -v` | Dos rutas GET/HEAD con los nombres y controladores descritos; salida 0. |
| Comando solicitado `route --path=cpu-memoria -v` | Salida 1: requiere un subcomando; se comprobó con route:list. |
| Enlaces locales y bloques Markdown | Comprobados; sin rutas personales en la documentación actualizada. |

No se realizaron pruebas visuales en navegador real. No se detectaron pendientes
funcionales dentro del alcance de M2 durante esta revisión; la revisión visual
manual anterior queda pendiente. Estos resultados son de esta ejecución y deben
volverse a comprobar después de futuros cambios.
