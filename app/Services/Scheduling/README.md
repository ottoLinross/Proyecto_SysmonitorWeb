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

Este paso solo añade el motor Round Robin, documentación y pruebas. No modifica
los algoritmos anteriores, formularios o rutas, ni implementa planificación por
prioridades, métricas, Gantt, persistencia o comparación global.
