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

Este paso solo añade SJF no expropiativo y sus pruebas: sin SRTF, cambios en
formularios o rutas, métricas, Gantt ni comparación global.
