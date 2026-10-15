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

Los formularios POST muestran el lote validado en la respuesta, sin guardarlo.
La sesión solo maneja los errores y la recuperación de entrada de Laravel.
No se implementa persistencia de simulaciones ni diagramas de Gantt.

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
validarlas. No se guarda la simulación ni se calculan otros algoritmos a la vez.

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
y `SchedulingMetrics`. El comparador devuelve solo etiquetas, quantum y métricas;
no conserva los segmentos que no se necesitan para la tabla. El quantum del
usuario se usa en Round Robin y no altera los otros cuatro algoritmos.

`scheduling/partials/comparison.blade.php` presenta los cinco nombres, promedios
de espera y retorno y sus fracciones exactas. El orden no cambia según el
resultado. El formulario conserva el quantum tras comparar y permite volver
a ejecutar una simulación individual. La vista individual y su Gantt permanecen
separados y no se modifican sus cálculos ni su representación de segmentos.

Sin persistencia SQLite, historial ni funcionalidades del paso 10.
