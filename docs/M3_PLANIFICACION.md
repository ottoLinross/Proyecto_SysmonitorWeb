# M3 — Planificación de CPU

Documentación oficial del módulo de SysMonitor Web. Cierre del paso 12,
15 de octubre de 2026. Describe la implementación existente y sus comprobaciones;
no incorpora funcionalidades de módulos posteriores.

## 1. Objetivo

Simular la planificación de una única CPU con procesos ingresados manualmente
o generados aleatoriamente. Permite ejecutar cinco algoritmos, consultar Gantt
y métricas, comparar el mismo lote y guardar sus resultados en SQLite para
consultarlos posteriormente sin recalcular.

Los procesos son datos simulados. M3 no consulta procesos del equipo ni modifica
su prioridad, señales o estado.

## 2. Arquitectura

La lógica de planificación reside en `app/Services/Scheduling/`, separada de
`app/Services/System/` y de M1/M2. Las vistas están en `resources/views/scheduling/`.

```text
Formulario Blade → validación del servidor → SchedulingProcess[]
                 → SchedulingSimulation → un scheduler PHP → segmentos
                                        → SchedulingMetrics → métricas
                 → SchedulingComparison → cinco ejecuciones independientes
                 → SchedulingRunStore → SchedulingRun → SQLite
Historial/detalle → SchedulingHistoryController → instantánea guardada → Blade
```

Los cinco schedulers, las métricas y la coordinación de simulaciones no dependen
de HTTP, Eloquent ni comandos del sistema. La validación Laravel y el guardado
son responsabilidades separadas. JavaScript solo agrega/quita filas del formulario.

## 3. Rutas

Hay **7 rutas M3**, todas con middleware `web`. GET admite también HEAD.

| Método | URI | Nombre | Acción |
| --- | --- | --- | --- |
| GET | `/planificacion` | `scheduling.index` | `SchedulingController@index` |
| POST | `/planificacion/manual` | `scheduling.manual` | `SchedulingController@manual` |
| POST | `/planificacion/aleatoria` | `scheduling.random` | `SchedulingController@random` |
| POST | `/planificacion/simular` | `scheduling.simulate` | `SchedulingController@simulate` |
| POST | `/planificacion/comparar` | `scheduling.compare` | `SchedulingController@compare` |
| GET | `/planificacion/historial` | `scheduling.history` | `SchedulingHistoryController@index` |
| GET | `/planificacion/historial/{id}` | `scheduling.history.show` | `SchedulingHistoryController@show` |

Declaración: [routes/web.php](../routes/web.php). El ID de detalle debe contener
solo dígitos; la consulta usa Eloquent parametrizado y devuelve 404 si no existe.
No hay rutas de actualización o eliminación de simulaciones.

## 4. Controladores

- [SchedulingController](../app/Http/Controllers/SchedulingController.php):
  muestra el formulario, valida/genera el lote, ejecuta o compara y guarda los
  resultados antes de mostrarlos. Validar o generar no crea un registro.
- [SchedulingHistoryController](../app/Http/Controllers/SchedulingHistoryController.php):
  lista metadatos paginados y carga el detalle almacenado. No invoca schedulers.

## 5. Clases de dominio y servicios

| Clase | Responsabilidad |
| --- | --- |
| `SchedulingProcess` | DTO `final readonly` con ID, llegada, ráfaga y prioridad |
| `ProcessInput` | Reglas y límites de entrada; conversión posterior a enteros |
| `RandomProcessGenerator` | Generación acotada y nuevamente validada |
| `FcfsScheduler` | FCFS |
| `SjfScheduler` | SJF no expropiativo |
| `SrtfScheduler` | SRTF preemptivo |
| `RoundRobinScheduler` | Round Robin con cola FIFO `SplQueue` |
| `PriorityScheduler` | Prioridades no expropiativo |
| `SchedulingSimulation` | Selección de un motor y cálculo compartido de métricas |
| `SchedulingComparison` | Cinco ejecuciones con copias independientes y orden fijo |
| `SchedulingMetrics` | Validación de segmentos y fórmulas centralizadas |
| `SchedulingRunStore` | Persistencia transaccional de resultados del servidor |

Todas están bajo [app/Services/Scheduling](../app/Services/Scheduling/).
Los schedulers reciben DTO previamente validados y mantienen estado local a
cada llamada; no modifican el lote del llamador.

## 6. Estructura de un proceso simulado

Ejemplo de entrada, ilustrativo y no procedente del sistema operativo:

```json
{
  "id": "P1",
  "arrival_time": 0,
  "burst_time": 5,
  "priority": 3
}
```

En `SchedulingProcess` los campos se llaman `id`, `arrivalTime`, `burstTime` y
`priority`. En formularios y JSON almacenado se usan los nombres con guion bajo
del ejemplo. La posición en la lista conserva el orden original de entrada.
No existen PID reales, ráfagas de E/S ni estados del sistema operativo en el DTO.

## 7. Validación de entrada

Fuente: [ProcessInput.php](../app/Services/Scheduling/ProcessInput.php).

| Campo | Regla del servidor |
| --- | --- |
| `processes` | Lista consecutiva de 1 a 50 procesos |
| `id` | Cadena obligatoria, única, de hasta 32 caracteres |
| `arrival_time` | Entero de 0 a 10 000 |
| `burst_time` | Entero de 1 a 1 000 |
| `priority` | Entero de 1 a 10 |
| Campos de cada proceso | Solo `id`, `arrival_time`, `burst_time`, `priority` |

Los identificadores distinguen mayúsculas de minúsculas. El validador no admite
espacios en los extremos ni saltos de línea; el middleware habitual de Laravel
recorta cadenas HTTP antes de validarlas. No se permite repetir un identificador
en el lote, incluso si sus otros campos son diferentes.

Los números pueden ser enteros PHP o cadenas decimales enteras aceptadas por las
reglas Laravel y la expresión de dígitos. Se rechazan flotantes, booleanos,
arreglos, notación decimal/exponencial y valores fuera de rango. Los casts a
`int` se realizan después de validar. Los atributos HTML son ayudas adicionales.

El límite del lote se comprueba antes de expandir las reglas por fila. Se
descartan campos superiores ajenos al lote para ese cálculo. Las rutas de
simulación y comparación vuelven a validar los campos ocultos del formulario;
no confían en una validación anterior realizada por el navegador.

`SchedulingSimulationRequest` valida algoritmo y quantum cuando corresponde.
`SchedulingComparisonRequest` exige quantum válido porque la comparación siempre
incluye Round Robin. Ambos comparten sus reglas de quantum.

## 8. Generación aleatoria

`RandomProcessGenerator::generate(int $count, ?Randomizer $randomizer = null)`
acepta entre 1 y 50 procesos y utiliza los mismos rangos de entrada. Genera IDs
`P1` a `Pn`, sin duplicados, y emplea `Randomizer::getInt` para llegada, ráfaga y
prioridad. Revalida el lote generado mediante `ProcessInput`.

Puede recibir un `Randomizer` con motor y semilla controlados en pruebas. La
interfaz no permite configurar una semilla ni conserva automáticamente la del
generador. Para consultas posteriores se guardan los datos concretos del lote.

## 9. FCFS

Ordena por llegada ascendente. A igual llegada conserva la posición original.
Ejecuta cada ráfaga completa antes de atender el siguiente proceso. Si no hay
procesos disponibles, la CPU permanece ociosa hasta la próxima llegada.

Pruebas: [FcfsSchedulerTest](../tests/Unit/FcfsSchedulerTest.php), con entradas
ordenadas/desordenadas, llegadas simultáneas, empates, ocio y ausencia de estado.

## 10. SJF no expropiativo

Cada vez que la CPU queda libre, selecciona entre los procesos ya llegados el de
menor ráfaga total. Desempata por llegada y posición original. Una ráfaga iniciada
termina aunque llegue un proceso más corto; no es SRTF.

Pruebas: [SjfSchedulerTest](../tests/Unit/SjfSchedulerTest.php), incluidos procesos
futuros, ausencia de interrupción y llegada exactamente en una finalización.

## 11. SRTF preemptivo

Selecciona el menor tiempo restante entre procesos disponibles. Reevalúa en
eventos de llegada o finalización, sin iterar unidad por unidad. Un proceso nuevo
con menor tiempo restante expropia al actual; el restante se conserva al reanudar.
Desempata por llegada y posición original. En igualdad con una nueva llegada
continúa el proceso que llegó antes.

Pruebas: [SrtfSchedulerTest](../tests/Unit/SrtfSchedulerTest.php), con ninguna,
una o varias expropiaciones, empates y conservación exacta de ráfagas.

## 12. Round Robin

Usa una cola FIFO real `SplQueue`. Ordena las llegadas por tiempo y posición
original, y ejecuta cada turno durante `min(quantum, remaining)`. Una llegada no
interrumpe un turno en curso. Si el proceso termina antes, no reserva el tiempo
sobrante; si conserva ráfaga pendiente, vuelve al final de la cola.

**Frontera determinista:** al terminar el turno se incorporan todas las llegadas
con `arrival_time <= fin`, incluidas las de ese instante, antes de reencolar al
proceso actual. Quienes ya esperaban conservan su lugar delante de las llegadas.

Los turnos contiguos del mismo proceso se combinan en un intervalo. Su duración
visible puede superar un quantum si nadie más ejecutó entre esos turnos.

Pruebas: [RoundRobinSchedulerTest](../tests/Unit/RoundRobinSchedulerTest.php), con
quantum 1, grande, fronteras, FIFO, reencolados y finalización anticipada.

## 13. Quantum configurable

El formulario permite configurarlo para una ejecución Round Robin o comparación.
La HTTP request exige entero positivo; acepta cadenas enteras válidas del formulario
y luego las convierte a `int`. La API PHP del scheduler exige estrictamente un
entero PHP positivo y lanza `InvalidArgumentException` para otros tipos.

No hay un máximo de negocio adicional: el límite de representación es el entero
PHP de la plataforma. Valores superiores no pasan la regla `integer`. Incluso
con `PHP_INT_MAX`, se limita primero la duración al restante antes de sumar el
tiempo. No afecta a los otros cuatro algoritmos y se guarda nulo cuando una
simulación individual no utiliza Round Robin.

## 14. Prioridades no expropiativo

Cuando la CPU queda libre, selecciona entre los procesos disponibles por prioridad
numérica ascendente, después llegada y posición original. La ejecución iniciada
no se interrumpe por llegadas posteriores, aunque tengan prioridad superior.

Pruebas: [PrioritySchedulerTest](../tests/Unit/PrioritySchedulerTest.php), con
prioridades distintas, ambos desempates, ocio y ausencia de expropiación.

## 15. Convención de prioridad

**Menor número = mayor prioridad.** Un proceso con prioridad 1 se elige antes que
otro con prioridad 10 si ambos están disponibles. La longitud de la ráfaga y el
nombre no sustituyen ese criterio. No hay aging ni prioridades expropiativas.

## 16. Criterios deterministas de desempate

| Algoritmo | Orden de selección entre candidatos disponibles |
| --- | --- |
| FCFS | Llegada → posición original |
| SJF | Ráfaga total → llegada → posición original |
| SRTF | Tiempo restante → llegada → posición original |
| Round Robin | Cola FIFO; incorporación por llegada → posición original |
| Prioridades | Número de prioridad → llegada → posición original |

No se ordena alfabéticamente por ID. Preservar el orden del lote al simular,
comparar y guardar es parte del contrato reproducible.

## 17. CPU ociosa e intervalos

Formato compartido por los cinco motores:

```php
[
    ['type' => 'idle', 'process_id' => null, 'start_time' => 0, 'end_time' => 3],
    ['type' => 'process', 'process_id' => 'P1', 'start_time' => 3, 'end_time' => 5],
]
```

Son intervalos semiabiertos `[inicio, fin)` con duración positiva. La línea de
tiempo comienza en cero, incluye ocio inicial/intermedio cuando corresponde y
no añade ocio después de terminar el lote. Un lote vacío produce `[]` en los
motores; la entrada HTTP requiere al menos un proceso.

## 18. Diagrama de Gantt

[results.blade.php](../resources/views/scheduling/partials/results.blade.php)
construye las barras únicamente desde los segmentos PHP. Sus pesos son
proporcionales a la duración, distingue `idle` y muestra proceso e inicio/fin.
Hay desplazamiento horizontal y una tabla con los tiempos completos para
segmentos cortos o nombres recortados. Los Gantt de una comparación guardada
están en paneles desplegables con identificadores HTML distintos.

No se vuelve a planificar ni a calcular métricas en JavaScript. Las pruebas
Feature verifican cada segmento y sus tiempos frente al resultado del backend.

## 19. Tiempo de finalización (`completion_time`)

Final del último segmento ejecutado por ese proceso. En algoritmos expropiativos
no corresponde necesariamente al final de su primer turno.

## 20. Tiempo de espera (`waiting_time`)

`waiting_time = turnaround_time - burst_time`.
La espera anterior a la llegada no se cuenta. Puede ser cero. No se devuelven
valores negativos: un resultado inconsistente provoca una excepción de dominio.

## 21. Tiempo de retorno (`turnaround_time`)

`turnaround_time = completion_time - arrival_time`.
Incluye ejecución y espera desde la llegada hasta la finalización.

## 22. Promedios y exactitud

`average_waiting_time = total_waiting_time / process_count`.
`average_turnaround_time = total_turnaround_time / process_count`.

[SchedulingMetrics](../app/Services/Scheduling/SchedulingMetrics.php) concentra
las fórmulas. Los tiempos y sumas permanecen enteros y la división se realiza
solo al final. No se redondean resultados individuales ni se reutilizan decimales
para otras fórmulas. Los cocientes PHP y su presentación decimal tienen la
precisión finita propia de la plataforma; sumas y cantidad conservan la fracción
exacta, que también se muestra en las vistas. Para un lote vacío, promedios cero.

Antes de devolver métricas se comprueba continuidad desde cero, duraciones
positivas, proceso conocido, ejecución posterior a la llegada y conservación
exacta del burst de cada proceso. Los errores no se ocultan ajustando tiempos.

## 23. Comparación de los cinco algoritmos

`SchedulingComparison` utiliza `SchedulingSimulation` y `SchedulingMetrics`.
Cada motor recibe clones independientes de los mismos DTO, con idénticos datos
y orden. La presentación es fija: FCFS, SJF, SRTF, Round Robin y Prioridades.
Round Robin utiliza el quantum del usuario. No se regeneran procesos ni se
comparten contadores de ejecución entre motores.

[comparison.blade.php](../resources/views/scheduling/partials/comparison.blade.php)
muestra nombre, espera promedio, retorno promedio y fracciones exactas. No ordena
automáticamente por rendimiento ni declara un ganador.

Caso conocido de `SchedulingMetricsTest` y `SchedulingComparisonTest`:
`A=(llegada 0, burst 5, prioridad 5)`, `B=(1,2,1)`, `C=(2,1,3)`, quantum 2.

| Algoritmo | Espera promedio exacta | Retorno promedio exacto |
| --- | --- | --- |
| FCFS | 9/3 | 17/3 |
| SJF | 8/3 | 16/3 |
| SRTF | 4/3 | 12/3 |
| Round Robin | 6/3 | 14/3 |
| Prioridades | 9/3 | 17/3 |

## 24. Persistencia SQLite

Cada ejecución individual o comparación válida se guarda automáticamente en
`scheduling_runs`, en la conexión `sqlite`. `SchedulingRunStore` realiza una
transacción de Eloquent con una instantánea completa calculada por el servidor.
No usa `serialize` PHP ni recibe resultados arbitrarios del formulario.

Se conservan procesos en su orden original, algoritmo o comparación, quantum,
segmentos, métricas, promedios, sumas exactas y fecha. Volver a ejecutar un lote
crea otro registro; no modifica el anterior. Ante un fallo de guardado se informa
que los resultados visibles no quedaron guardados.

## 25. Migraciones

[2026_10_15_000000_create_scheduling_runs_table.php](../database/migrations/2026_10_15_000000_create_scheduling_runs_table.php)
crea exclusivamente la tabla de M3 mediante Schema de Laravel sobre SQLite.
Incluye índice por `created_at` e `id`. Su método `down` revierte esa tabla;
su reversión no forma parte de las instrucciones de uso local ni del cierre.

Para una instalación previamente configurada que aún no tenga esta migración,
el comando dirigido es:

```sh
php artisan migrate --database=sqlite --path=database/migrations/2026_10_15_000000_create_scheduling_runs_table.php --force
```

Este comando se documenta para preparación local; **no se ejecutó sobre la base
real durante el paso 12**. No se edita SQLite directamente ni se usan migraciones
destructivas para validar M3.

## 26. Modelo de simulaciones guardadas

[SchedulingRun](../app/Models/SchedulingRun.php) declara conexión SQLite, campos
permitidos y casts. El servicio construye explícitamente los atributos; no pasa
`$request->all()` a Eloquent.

| Campo | Contenido |
| --- | --- |
| `id` | Identificador generado por la base |
| `schema_version` | Versión del formato, actualmente 1 |
| `mode` | `single` o `comparison` |
| `algorithm` | Clave individual; nulo en comparación |
| `quantum` | Entero para Round Robin/comparación; nulo cuando no corresponde |
| `input_processes` | JSON con la lista original; cast `array` |
| `results` | JSON de uno o cinco resultados completos; cast `array` |
| `created_at`, `updated_at` | Timestamps de Eloquent |

Cada resultado contiene `algorithm`, `label`, `quantum`, `segments` y `metrics`.
Las comparaciones también conservan los segmentos de los cinco motores.

## 27. Historial y consulta posterior

El enlace «Historial de simulaciones» abre un listado de 20 registros por página,
ordenado por fecha descendente e ID descendente. Solo carga metadatos del listado,
sin todos los JSON. Las vistas muestran fecha y hora en `America/Guatemala`.

El detalle reconstruye los DTO de entrada a partir del JSON y muestra los
resultados originales, sin invocar algoritmos ni métricas y sin volver a validar
con reglas que pudieran cambiar. Una comparación permite desplegar cada Gantt.
Simular o comparar desde ese detalle crea un registro nuevo. Los parámetros GET
no actualizan el registro consultado.

## 28. Manejo de errores

| Situación | Comportamiento |
| --- | --- |
| Entrada inválida en formulario | Redirección con errores y recuperación de entrada mediante sesión |
| Entrada inválida solicitando JSON | HTTP 422 con errores de validación |
| Falta de CSRF en POST | HTTP 419 |
| ID no numérico o inexistente | HTTP 404 |
| Intento PUT al detalle | HTTP 405; no hay ruta de modificación |
| Error SQLite al guardar | HTTP 503, mensaje genérico y resultados visibles sin afirmar guardado |
| Error SQLite al consultar | HTTP 503 y aviso genérico de indisponibilidad |
| Segmentos inconsistentes en la API interna de métricas | `InvalidArgumentException` |

Las excepciones SQL capturadas no se muestran en Blade. Las pruebas verifican
rollback y ausencia de detalles internos en esos casos controlados. No se afirma
que el módulo repare automáticamente archivos SQLite corruptos o formatos de
instantánea alterados por herramientas externas.

## 29. Seguridad y separación de módulos

- Validación obligatoria del servidor; lotes, rangos y tipos acotados.
- Formularios con `@csrf` y middleware `web`; POST para las ejecuciones que guardan.
- Interpolación Blade escapada para nombres, atributos, Gantt y datos guardados.
- Consultas Eloquent parametrizadas e ID numérico; sin actualización por GET.
- Solo se persisten atributos explícitos y resultados calculados en el backend.
- JSON/casts Laravel, sin serialización PHP de objetos.
- Motores sin comandos del sistema, acceso a `/proc` o administración real de procesos.
- Lógica M3 separada de `Services/System`; sin cambios de lógica de M1/M2 en este cierre.

Las rutas actuales no implementan autenticación, propiedad por usuario ni filtros
de autorización del historial. Eso describe el alcance existente y debe decidirse
en la integración antes de un despliegue con múltiples usuarios.

## 30. Pruebas y matriz de requisitos oficiales

Todas las filas siguientes tienen implementación y pruebas automatizadas. Los
nombres de clases corresponden a archivos existentes en `tests/Unit` o
`tests/Feature`; los providers verifican varios casos por método.

| Requisito | Implementación | Evidencia automatizada |
| --- | --- | --- |
| Entrada manual | `ProcessInput`, formulario manual | `SchedulingInputTest::test_manual_input_returns_typed_processes_without_database_access` |
| Generación aleatoria | `RandomProcessGenerator` | `SchedulingInputTest::test_random_generation_is_bounded_unique_and_reproducible` |
| Llegada, ráfaga y prioridad | DTO y reglas de `ProcessInput` | `SchedulingInputTest::invalidProcesses`, `test_upper_bounds_and_html_integer_strings_are_accepted` |
| Identificadores únicos y cantidad máxima | `ProcessInput` | `SchedulingInputTest::test_duplicate_ids_are_rejected`, `SchedulingAuditTest` |
| FCFS | `FcfsScheduler` | `FcfsSchedulerTest` |
| SJF no expropiativo | `SjfScheduler` | `SjfSchedulerTest` |
| SRTF preemptivo | `SrtfScheduler` | `SrtfSchedulerTest` |
| Round Robin y quantum configurable | `RoundRobinScheduler`, requests | `RoundRobinSchedulerTest`, `SchedulingComparisonTest::test_configured_quantum_changes_only_round_robin` (Unit) |
| Prioridades no expropiativo y convención numérica | `PriorityScheduler` | `PrioritySchedulerTest` |
| Gantt e identificación de segmentos | Partial `results` | `SchedulingSimulationTest::test_blade_renders_backend_metrics_and_every_gantt_segment` |
| CPU ociosa | Segmentos `idle` y Blade | Tests de los cinco motores; `SchedulingMetricsTest::test_idle_is_not_counted_as_waiting_before_arrival` |
| Finalización, espera y retorno por proceso | `SchedulingMetrics` | `SchedulingMetricsTest::test_shared_metrics_for_all_five_algorithms` |
| Promedios de espera y retorno | `SchedulingMetrics` y partials | `SchedulingMetricsTest`, `SchedulingComparisonTest` (Feature) |
| Comparación de los cinco con la misma entrada | `SchedulingComparison` | `SchedulingComparisonTest::test_every_algorithm_receives_independent_copies_of_exactly_the_same_input` (Unit) |
| Ausencia de contaminación | DTO readonly, copias y estado local | `SchedulingComparisonTest::test_repeated_comparisons_and_reverse_execution_order_do_not_contaminate_results` (Unit), `SchedulingAuditTest` |
| Conservación del burst y límites máximos | Motores y métricas | `SrtfSchedulerTest`, `RoundRobinSchedulerTest`, `SchedulingAlgorithmAuditTest` |
| Guardado automático SQLite | `SchedulingRunStore`, modelo, controladores | `SchedulingHistoryTest::test_each_algorithm_is_saved_with_unchanged_results_and_recovers_from_json` |
| Migración y casts JSON | Migración propia y `SchedulingRun` | `SchedulingHistoryTest::test_migration_creates_the_m3_table_and_json_casts` |
| Historial y paginación | `SchedulingHistoryController@index` | `SchedulingHistoryTest::test_history_is_empty_then_paginated_with_stable_newest_first_order` |
| Consulta posterior y datos originales | Modelo y controlador de detalle | `SchedulingHistoryTest::test_snapshot_survives_reopening_an_isolated_sqlite_file` |
| Consulta sin recalcular | Carga de `results` persistidos | `SchedulingHistoryTest::test_comparison_persists_all_five_complete_results_and_detail_gantts_without_recalculation` |
| Quantum nullable | Modelo y guardado individual | Provider de cinco algoritmos en `SchedulingHistoryTest` |
| Detalle inexistente y sin modificaciones arbitrarias | Restricción de ruta y Eloquent | `SchedulingHistoryTest::test_nonexistent_and_nonnumeric_identifiers_return_404_and_detail_cannot_be_updated` |
| Campos permitidos y rollback | `SchedulingRunStore` | `SchedulingHistoryTest::test_untrusted_results_are_ignored_and_invalid_input_is_not_saved`, `test_failed_save_rolls_back_and_preserves_results_without_claiming_success` |
| CSRF y escape de datos | Middleware y Blade | `SchedulingInputTest`, `SchedulingSimulationTest`, `SchedulingComparisonTest`, `SchedulingHistoryTest` |
| Aislamiento SQLite de testing | Bootstrap y helper M3 | `SchedulingBootstrapAuditTest` |

Referencias de las pruebas integrales:
[entrada](../tests/Feature/SchedulingInputTest.php),
[simulación](../tests/Feature/SchedulingSimulationTest.php),
[comparación](../tests/Feature/SchedulingComparisonTest.php),
[historial](../tests/Feature/SchedulingHistoryTest.php),
[auditoría](../tests/Feature/SchedulingAuditTest.php),
[métricas](../tests/Unit/SchedulingMetricsTest.php),
[comparación PHP](../tests/Unit/SchedulingComparisonTest.php),
[lote máximo](../tests/Unit/SchedulingAlgorithmAuditTest.php),
[bootstrap](../tests/Unit/SchedulingBootstrapAuditTest.php).

### Aislamiento y ejecución de pruebas

[SchedulingSqliteBootstrap.php](../tests/Support/SchedulingSqliteBootstrap.php)
exige entorno `testing`, conexión predeterminada SQLite y base `:memory:` tanto
en la configuración inicial como en la conexión efectiva, incluida la resolución
de `DB_URL`. Comprueba eso antes de migrar, prepara el esquema con `migrate`,
comparte PDO con `RefreshDatabaseState` y restablece los manejadores de PHPUnit
en `finally`. No captura ni silencia las aserciones fallidas.

Las pruebas M3 migran su tabla en memoria. Una prueba adicional utiliza un archivo
SQLite temporal propio para verificar la reapertura; no usa la base real. No se
debe sustituir el comando siguiente por una regeneración destructiva del esquema.

```sh
php artisan route:list --path=planificacion -v
php artisan test --bootstrap=tests/Support/SchedulingSqliteBootstrap.php --filter='Scheduling|Fcfs|Sjf|Srtf|RoundRobin|PriorityScheduler'
php vendor/bin/pint --test
php artisan test --bootstrap=tests/Support/SchedulingSqliteBootstrap.php
```

El paso 12 solo añade Markdown: `php -l` sobre PHP creado/modificado no aplica.
Como comprobación adicional se revisó la sintaxis del PHP y Blade de M3 sin editarlo.

### Resultados del cierre

Validaciones ejecutadas el 15 de octubre de 2026:

| Comprobación | Resultado exacto |
| --- | --- |
| Rutas de M3 | 7 rutas; comando finalizado correctamente |
| Pruebas específicas M3 | 201 passed; 3453 assertions; 3.65 s |
| Suite completa, incluida regresión M1/M2 | 682 passed; 10387 assertions; 12.42 s; salida 0 |
| Laravel Pint | `{"tool":"pint","result":"passed"}` |
| Sintaxis adicional | 23 archivos PHP/Blade de M3 sin errores |
| Documentación | 33 apartados; 25 enlaces locales válidos; enlace único en README |

No se crearon ni modificaron pruebas o código funcional en el paso 12. Los cinco
algoritmos, SQLite, JSON, historial y recuperación sin recalcular pasan las
pruebas existentes. No se detectaron bugs nuevos ni requisitos funcionales de la
solicitud sin implementación/pruebas. La validación de SQLite se hizo en memoria
y en los archivos temporales propios de las pruebas, sin modificar bases reales.

## 31. Ejecución local

Requiere una instancia del proyecto ya configurada, dependencias de `vendor`
existentes, PHP compatible con Laravel 12 (el proyecto declara PHP `^8.2`) y PDO
SQLite. No se necesitan librerías de Gantt, paquetes frontend ni un build de npm
para estas vistas Blade y su JavaScript nativo.

Sin editar `.env`, y con la migración M3 ya aplicada en la instancia de uso:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir `http://127.0.0.1:8000/planificacion`. Ingresar procesos y validar, o generar
un lote aleatorio; después elegir algoritmo/quantum y simular, o comparar los
cinco. Consultar el enlace de detalle guardado o el historial. La generación por
sí sola no persiste. Estos pasos de uso crean registros en la instancia elegida;
las verificaciones de cierre usan exclusivamente datos de testing aislados.

No usar los scripts generales de instalación para este cierre: implican acciones
de configuración e instalación ajenas al alcance de documentación.

## 32. Limitaciones conocidas y revisión visual

- Una CPU, una ráfaga de CPU por proceso y tiempos enteros en unidades abstractas.
  Sin E/S, coste de cambio de contexto, afinidad, aging ni prioridades expropiativas.
- La entrada está acotada a 50 procesos y 1 000 unidades de burst por proceso.
  Con quantum 1, Round Robin puede producir 50 000 segmentos de proceso y uno
  inicial de ocio: **50 001 segmentos**. El motor y la conservación de ejecución
  están probados en ese límite. El HTML de barras y tablas puede ser voluminoso;
  no hay virtualización o paginación de segmentos del Gantt.
- Se combinan intervalos consecutivos del mismo proceso; no todos los límites de
  quantum quedan representados por una barra separada.
- El formato decimal visible tiene precisión finita; las fracciones conservan
  los promedios exactos. La semilla aleatoria no se expone en la interfaz.
- No hay edición, borrado, exportación, filtrado o separación por propietario
  del historial. No se afirma despliegue multiusuario ni endurecimiento operativo.
- La integridad probada es la de entradas y resultados creados por la aplicación,
  sus transacciones y su recuperación JSON; no es una firma criptográfica contra
  edición externa del archivo SQLite.

### Revisión visual del paso 12

Se abrió en el navegador integrado una muestra estática de la vista Blade real,
renderizada por los servicios PHP con tres procesos de prueba y Round Robin,
quantum 2. Se observaron barras de procesos, ocio inicial 0–2, tiempos y tabla de
segmentos legible; la línea de tiempo permite desplazamiento horizontal en una
ventana estrecha. La muestra se sirvió solo en localhost desde un directorio
temporal, sin enviar formularios ni consultar/escribir la SQLite real.

También se inspeccionó visualmente la tabla comparativa de esa muestra: cinco
algoritmos en orden fijo, quantum 2, espera/retorno promedio y fracciones exactas.
El servidor temporal se detuvo al terminar. La apertura inicial del puerto requirió
permiso fuera del sandbox; el servidor solo escuchó en `127.0.0.1`.

La verificación visual no equivale a una prueba end-to-end de formularios,
guardado e historial. Esos comportamientos están cubiertos por Feature tests.
El caso máximo de 50 001 segmentos no se inspeccionó visualmente ni se midió
como carga del navegador. No se introduce una optimización nueva en este cierre.

## 33. Pendientes para M6 e integración final

No se implementan durante este paso. Deben acordarse con los requisitos de M6,
que no se proporcionaron aquí:

- Integrar navegación y presentación global sin acoplar los motores a M1/M2.
- Decidir acceso al historial y separación por usuario si el despliegue lo exige.
- Validar el flujo completo en la instancia integrada y dispositivos de destino.
- Medir el Gantt máximo antes de decidir optimizaciones; preservar segmentos y
  métricas originales si se cambia únicamente su presentación.
- Definir, si corresponde, retención, respaldo y evolución de `schema_version`.

Los requisitos oficiales de M3 incluidos en la solicitud están cubiertos por
implementación y pruebas. La revisión visual del volumen máximo y la integración
M6 quedan explícitamente fuera de lo verificado en este cierre.
