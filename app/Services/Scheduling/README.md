# Servicios de planificación de CPU (M3)

Entrada de procesos para la futura lógica de planificación de CPU bajo el
namespace `App\Services\Scheduling`, separado de `App\Services\System`.

`ProcessInput` centraliza validación y límites: lotes de 1 a 50 procesos,
identificadores únicos de hasta 32 caracteres, llegada de 0 a 10000,
ráfaga de 1 a 1000 y prioridad de 1 a 10. Solo admite enteros o cadenas
de dígitos decimales para los campos numéricos. Los identificadores distinguen
mayúsculas y minúsculas y no admiten espacios al inicio o al final.

`SchedulingProcess` representa los datos validados con propiedades inmutables.
`RandomProcessGenerator` valida la cantidad antes de generar, utiliza los mismos
límites y valida el lote resultante. Su método `generate` acepta un `Randomizer`
opcional para pruebas reproducibles con un motor de semilla fija.

La validación manual y la generación solo preparan el lote. Ejecutar una
simulación o comparación guarda sus resultados como se describe más abajo.
La sesión maneja los errores y la recuperación de entrada de Laravel.

## FCFS

`FcfsScheduler::schedule(array $processes)` es lógica PHP pura, sin Laravel,
base de datos, reloj ni aleatoriedad. Recibe procesos `SchedulingProcess` ya
validados por `ProcessInput` y devuelve una lista cronológica de intervalos:

```php
[
    ['type' => 'idle', 'process_id' => null, 'start_time' => 0, 'end_time' => 3],
    ['type' => 'process', 'process_id' => 'P1', 'start_time' => 3, 'end_time' => 5],
]
```

Ordena por llegada ascendente y desempata por la posición original de entrada.
No utiliza nombre, prioridad ni duración para desempatar. Cada proceso ejecuta
su ráfaga completa sin interrupción. Los intervalos son `[inicio, fin)`, con
ocio explícito desde cero o entre procesos cuando corresponda, sin ocio final
ni intervalos de duración cero. Una entrada vacía produce una lista vacía.
No modifica la entrada ni conserva estado entre ejecuciones.

El identificador permite relacionar cada intervalo con su proceso original
para calcular métricas y representar Gantt posteriormente. El motor FCFS no
modifica formularios ni rutas, no calcula métricas ni compara algoritmos.

## SJF no expropiativo

`SjfScheduler::schedule(array $processes)` recibe el mismo lote previamente
validado y devuelve el mismo formato de intervalos que FCFS. Es PHP puro,
sin dependencias de Laravel, y no modifica la entrada ni conserva estado.

Cada vez que la CPU queda libre, considera solo procesos con llegada menor
o igual al tiempo actual. Selecciona por ráfaga ascendente, después llegada
ascendente y finalmente posición original de entrada. El nombre y la prioridad
no intervienen en el desempate. El proceso seleccionado termina su ráfaga
completa aunque durante su ejecución lleguen procesos más cortos.

Si no hay procesos disponibles, emite un intervalo `idle` hasta la llegada más
próxima y vuelve a seleccionar. No añade ocio final ni intervalos de duración
cero. El lote vacío devuelve una lista vacía. La selección cuesta O(n²) para
el lote completo, adecuado al límite de 50 procesos de entrada.

El motor SJF no modifica formularios ni rutas, no calcula métricas ni compara
algoritmos.

## SRTF preemptivo

`SrtfScheduler::schedule(array $processes)` recibe procesos previamente validados
por `ProcessInput` y usa el mismo formato de intervalos que FCFS y SJF. Es PHP
puro y no modifica los procesos originales ni conserva estado entre ejecuciones.

Selecciona entre los procesos disponibles por tiempo restante ascendente,
después llegada ascendente y finalmente posición original de entrada. Ni el
identificador ni la prioridad intervienen. Una nueva llegada con menor tiempo
restante expropia al proceso actual; en igualdad continúa el que llegó antes.
Las llegadas simultáneas se consideran juntas antes de seleccionar.

La simulación avanza por eventos de llegada o finalización, sin iterar por cada
unidad de tiempo. El tiempo restante se conserva al expropiar y reanudar. Si no
hay procesos listos, añade `idle` hasta la próxima llegada. Combina intervalos
contiguos del mismo proceso, sin ocultar interrupciones reales, sin segmentos de
duración cero ni ocio final. Un lote vacío devuelve una lista vacía.

El motor SRTF no modifica formularios ni rutas, no calcula métricas ni compara
algoritmos.

## Round Robin

`RoundRobinScheduler::schedule(array $processes, mixed $quantum)` es PHP puro.
Recibe procesos previamente validados por `ProcessInput` y un quantum configurable
que debe ser un entero PHP mayor que cero. Rechaza cero, negativos, flotantes,
booleanos, cadenas y otros tipos con `InvalidArgumentException`, incluso si el
lote está vacío. No limita superiormente el quantum: ejecuta como máximo el
burst restante antes de sumar el tiempo, también con `PHP_INT_MAX`.

Utiliza la cola FIFO nativa `SplQueue`, sin dependencias nuevas. Las llegadas se
ordenan por tiempo y, en empate, por posición original. Ni ID ni prioridad
alteran el orden. Si no hay procesos listos, emite `idle` hasta la próxima
llegada. Cada turno ejecuta `min(quantum, remaining)` sin interrupciones por
nuevas llegadas; al terminar un proceso no consume el quantum sobrante.

Regla de frontera: al terminar cada turno, incorpora todas las llegadas con
`arrival_time <= fin`, incluidas las llegadas exactamente en ese instante,
antes de reencolar al proceso actual si aún tiene tiempo restante. Los procesos
que ya esperaban conservan su lugar delante de las nuevas llegadas.

Devuelve el mismo formato de intervalos `[inicio, fin)` de los otros motores.
Combina turnos contiguos del mismo proceso sin ocultar alternancias reales;
por eso un intervalo combinado puede durar más que un quantum. No genera
intervalos de duración cero ni ocio final. El lote vacío devuelve `[]` con
quantum válido. No modifica la entrada ni conserva estado entre simulaciones.

El motor Round Robin no modifica formularios ni rutas, no calcula métricas ni
compara algoritmos.

## Prioridades no expropiativo

Convención: **menor número = mayor prioridad**. Por ejemplo, prioridad 1 se
selecciona antes que prioridad 10 cuando ambos procesos están disponibles.

`PriorityScheduler::schedule(array $processes)` recibe procesos previamente
validados por `ProcessInput`. Es PHP puro y devuelve el mismo formato de
intervalos `[inicio, fin)` que FCFS, SJF, SRTF y Round Robin, sin modificar la
entrada ni conservar estado entre simulaciones.

Cuando la CPU queda libre, considera únicamente procesos con llegada menor o
igual al tiempo actual. Ordena por prioridad numérica ascendente, luego llegada
ascendente y finalmente posición original de entrada. El identificador y la
duración no intervienen en los empates. Ejecuta la ráfaga completa: una nueva
llegada con mayor prioridad NO expropia al proceso actual y solo compite cuando
la CPU vuelve a quedar libre. Una llegada exactamente al finalizar sí participa
en la siguiente selección.

Si no hay procesos disponibles, emite `idle` hasta la próxima llegada, sin
esperar artificialmente a procesos futuros de mayor prioridad. No genera
intervalos de duración cero ni ocio final. El lote vacío devuelve `[]`.
La selección cuesta O(n²), adecuada al límite vigente de 50 procesos.

El motor de Prioridades no modifica los otros algoritmos ni implementa
prioridades expropiativas o aging.

## Métricas compartidas y Gantt

Tras validar o generar el lote, el formulario permite seleccionar un algoritmo
y ejecutar `POST /planificacion/simular`. El backend vuelve a validar todos los
procesos y la selección; Round Robin requiere un quantum entero positivo. Las
cadenas decimales enteras del formulario se convierten a `int` solo después de
validarlas. La simulación individual ejecuta solo el algoritmo seleccionado.

`SchedulingSimulation` invoca uno de los cinco motores sin modificarlo y pasa
sus segmentos a `SchedulingMetrics`, compartido por todos ellos. Ambos servicios
son PHP puro. Para cada proceso, `completion_time` es el final de su último
segmento; `turnaround_time = completion_time - arrival_time` y
`waiting_time = turnaround_time - burst_time`. Los segmentos de ocio no cuentan
como ejecución de procesos. La espera anterior a la llegada no se suma.

Los tiempos y sumas permanecen enteros. Los promedios se calculan una sola vez
como suma/cantidad, sin `round`, sin redondear métricas individuales ni reutilizar
decimales para otros cálculos. Se devuelven las sumas y `process_count` para
conservar la fracción exacta incluso cuando su decimal es periódico; la vista
muestra también esas fracciones. Un lote vacío devuelve métricas vacías y
promedios cero. No se devuelven tiempos negativos: ejecuciones discontinuas,
solapadas, con procesos desconocidos, anteriores a la llegada o con burst total
distinto del declarado provocan `InvalidArgumentException` en lugar de ocultar
el error ajustando el resultado a cero.

`scheduling/partials/results.blade.php` presenta las métricas de una simulación
y un Gantt construido únicamente con sus segmentos del backend. Las barras
tienen pesos proporcionales a su duración, identifican `idle` explícitamente
y muestran proceso e inicio/fin. Una tabla conserva el detalle legible de todos
los segmentos, incluso los muy cortos. Hay desplazamiento horizontal y nombres
escapados por Blade. No se ejecuta planificación ni se calculan métricas en
JavaScript. Se mantiene el formato `type`, `process_id`, `start_time`, `end_time`.

## Comparación de los cinco algoritmos

El botón «Comparar cinco algoritmos» envía el lote validado y el quantum a
`POST /planificacion/comparar`. El servidor vuelve a validar los procesos y exige
un quantum entero positivo para Round Robin. La validación del quantum se
comparte con la simulación individual; no depende del algoritmo seleccionado
en el formulario cuando se solicita la comparación.

`SchedulingComparison` invoca `SchedulingSimulation::run` en orden fijo:
FCFS, SJF no expropiativo, SRTF, Round Robin y Prioridades no expropiativo.
Cada ejecución recibe un array independiente con clones de los mismos DTO
inmutables, conservando todos los valores y el orden original. Los schedulers
mantienen su propio estado. No se regeneran procesos ni se altera el lote
entre algoritmos, y ejecuciones anteriores no afectan a la comparación.

La planificación y las fórmulas siguen exclusivamente en los motores existentes
y `SchedulingMetrics`. El comparador conserva etiquetas, quantum, métricas y
segmentos para guardar el resultado completo de los cinco algoritmos. El quantum
del usuario se usa en Round Robin y no altera los otros cuatro algoritmos.

`scheduling/partials/comparison.blade.php` presenta los cinco nombres, promedios
de espera y retorno y sus fracciones exactas. El orden no cambia según el
resultado. El formulario conserva el quantum tras comparar y permite volver
a ejecutar una simulación individual. La vista individual y su Gantt permanecen
separados y no se modifican sus cálculos ni su representación de segmentos.

## Persistencia e historial (paso 10)

Cada POST válido a `/planificacion/simular` o `/planificacion/comparar` guarda
automáticamente una instantánea en `scheduling_runs`, tabla exclusiva de M3.
Validar o generar procesos no guarda una simulación. Reejecutar un lote crea
otra entrada; no hay rutas de actualización ni borrado.

La migración `2026_10_15_000000_create_scheduling_runs_table.php` crea la tabla
mediante Laravel sobre la conexión `sqlite`. El modelo `SchedulingRun` utiliza
esa conexión y casts JSON `array`, sin serialización PHP. Almacena:

- `schema_version` (1), `mode` (`single` o `comparison`) y algoritmo individual;
- quantum, nulo si una simulación individual no usa Round Robin;
- `input_processes`, con identificador, llegada, ráfaga, prioridad y orden;
- `results`, con algoritmo, quantum, segmentos, métricas por proceso, sumas,
  cantidad de procesos y promedios de cada resultado;
- `created_at` y `updated_at`. Las vistas muestran fechas en America/Guatemala.

`SchedulingRunStore` persiste datos calculados por el backend en una transacción,
sin aceptar métricas, segmentos ni atributos del modelo enviados por el cliente.
Los motores y las fórmulas no acceden a la base de datos ni se modifican. Las
comparaciones conservan los resultados completos de los cinco motores, incluidos
los Gantt. Los errores de guardado devuelven HTTP 503, conservan los resultados
visibles y avisan que no se guardaron; no revelan detalles internos.

`GET /planificacion/historial` lista 20 registros por página, por fecha descendente
y después ID descendente. Solo selecciona los metadatos de listado, sin cargar
los JSON de todas las simulaciones. `GET /planificacion/historial/{id}` exige un
identificador numérico, consulta con Eloquent y devuelve 404 si no existe. No
admite modificaciones mediante parámetros. El detalle muestra los datos guardados
sin recalcular ni volver a validar con reglas que pudieran cambiar en el futuro.
En una comparación permite consultar métricas y Gantt de cada algoritmo, con IDs
HTML distintos. La indisponibilidad de SQLite se informa mediante HTTP 503 genérico.

Aplicación de la migración de M3 exclusivamente:

```sh
php artisan migrate --database=sqlite --path=database/migrations/2026_10_15_000000_create_scheduling_runs_table.php --force
```

Las pruebas M3 crean su tabla con `migrate` sobre SQLite `:memory:`; una prueba
adicional comprueba recuperación tras cerrar y abrir un archivo SQLite temporal.
Para ejecutar la suite completa sin solicitar migraciones destructivas desde
los traits preexistentes, usar:

```sh
php artisan test --bootstrap=tests/Support/SchedulingSqliteBootstrap.php
```

Ese bootstrap exige testing y SQLite en memoria, aplica `migrate`, comparte el
PDO con `RefreshDatabaseState` y restablece los manejadores de PHPUnit. No toca
datos reales ni cambia la configuración o archivos de M1/M2. No se implementan
funcionalidades del paso 11.

## Auditoría y correcciones del paso 11

Se detectaron y reprodujeron dos problemas:

- La validación de lotes excesivos expandía las reglas wildcard y validaba todas
  las filas antes de rechazar la cantidad. `ProcessInput` ahora comprueba primero
  la estructura y el límite de 50 procesos, descarta campos superiores ajenos al
  lote y corta la validación del identificador si su tipo o longitud son inválidos.
  No se cambiaron los límites de llegada, ráfaga, prioridad ni quantum.
- El bootstrap verificaba `database.connections.sqlite.database`, pero `DB_URL`
  puede reemplazar esa configuración al resolver la conexión. Una prueba aislada
  demostró que podía migrar un archivo temporal aunque la configuración inicial
  dijera `:memory:`. Ahora exige también driver SQLite y base `:memory:` en la
  conexión efectiva, antes de abrir PDO o ejecutar migraciones. El helper de las
  pruebas M3 realiza la misma comprobación. Los manejadores de PHPUnit se
  restablecen en `finally`, tanto con éxito como si la configuración se rechaza;
  no se capturan ni silencian los errores de migración o las aserciones.

Las regresiones comprueban lotes excesivos en las tres rutas de entrada,
enteros extremos, decimales JSON reales, conservación de registros anteriores,
rechazo de bases en archivo y entornos distintos de testing. Otra prueba verifica
el lote máximo con los cinco motores y quantum 1: se conservan las 50000 unidades
de ejecución y los tiempos no son negativos. En Round Robin esto supone hasta
50001 segmentos contando el ocio inicial; la representación puede ser extensa.

Se revisaron los desempates, expropiación, FIFO y fronteras, métricas, Gantt,
comparación, campos persistidos, casts JSON, transacciones, rutas, CSRF y escape
Blade. No se encontraron fallos que exigieran modificar los schedulers, fórmulas,
persistencia o vistas. El código de M3 no usa comandos del sistema, `/proc` ni
administración de procesos reales. Se ejecutaron las pruebas M3 y la regresión
completa mediante el bootstrap seguro. Una prueba temporal deliberadamente
fallida confirmó una salida no cero para aserciones y excepciones.

No se modificaron bases reales, simulaciones existentes ni lógica de M1/M2.
