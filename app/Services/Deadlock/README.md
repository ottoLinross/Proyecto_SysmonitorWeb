# M4 — Interbloqueos: simulador educativo

M4 permite validar escenarios de recursos, calcular Need, ejecutar el algoritmo
de seguridad del banquero, evaluar una solicitud explícita, explorar un grafo
dirigido y sus ciclos, simular recuperación y guardar escenarios en SQLite.
Está separado de sistema y planificación: no ejecuta comandos, señales ni
acciones sobre procesos, servicios o recursos reales. Solo el almacenamiento
educativo accede a la base de datos; los cálculos se realizan en memoria.

## Guía de uso

Con la aplicación local ya iniciada, abrir `/interbloqueos`:

1. Indicar `n` procesos y `m` tipos de recursos. Introducir Allocation y Max como
   listas JSON de filas y Available como una lista JSON. Usar **Validar escenario**.
2. Revisar matrices, Need, seguridad y pasos. P1…Pn son las filas originales;
   R1…Rm son las columnas. El orden no se cambia para encontrar una secuencia.
3. En **Evaluar solicitud de recursos**, elegir un proceso e introducir Request.
   La decisión se muestra aparte; el grafo distingue Request de necesidad potencial.
4. Consultar la detección de ciclos. No se inventan solicitudes desde Need y un
   ciclo no confirma un interbloqueo con múltiples instancias de recursos.
5. En **Recuperación conceptual**, elegir finalización manual, víctima automática
   o explicación de apropiación. La finalización muestra recursos liberados,
   matrices temporales y una nueva evaluación del banquero.
6. Usar **Guardar una nueva simulación** para insertar el escenario original
   validado. En **Simulaciones guardadas**, abrir un detalle para recalcular sus
   resultados y realizar otros ensayos. Guardar de nuevo crea otro registro.

Cada solicitud y cada ensayo de recuperación parte del escenario original
mostrado: no encadena concesiones ni recuperaciones anteriores. Las matrices,
seguridad y pasos principales siguen describiendo ese original. Las copias
simuladas tienen sus propios bloques y no reemplazan los datos del formulario.

## Conceptos e interpretación

| Concepto | Significado y límite de la conclusión |
| --- | --- |
| Allocation | Unidades asignadas actualmente a cada proceso por tipo de recurso. |
| Max | Demanda máxima declarada; no es inventario de recursos. |
| Available | Unidades disponibles actualmente por tipo de recurso. |
| Need | `Max - Allocation`: demanda restante máxima o necesidad potencial, no necesariamente solicitud activa. |
| Request | Vector explícito enviado por un proceso para evaluación independiente. |
| Estado seguro | El banquero encontró una secuencia en la que todos pueden finalizar bajo el modelo declarado. |
| Estado inseguro | No se encontró una secuencia segura completa; no prueba que exista interbloqueo. |
| Ciclo | Recorrido dirigido cerrado de Allocation y Request; con múltiples instancias no basta para demostrar interbloqueo. |
| Interbloqueo real | Bloqueo efectivo de procesos que esperan recursos entre sí; M4 no observa ni confirma esa situación en el sistema operativo. |

La ausencia de ciclos tampoco demuestra seguridad según el banquero. Una
recuperación es una exploración voluntaria, no una acción obligatoria ni una
solución garantizada. Las conclusiones dependen de los datos declarados.

## Contrato de entrada y límites

`ScenarioInput::validate(array $input)` devuelve un `DeadlockScenario` inmutable
con `n`, `m`, `allocation`, `max` y `available`:

- `n` y `m`: entre 1 y 20; cantidades de entrada: entre 0 y 1,000,000.
- Allocation y Max: listas consecutivas de exactamente n filas de m valores.
  Available y Request: listas consecutivas de exactamente m valores.
- Cada celda Allocation debe ser menor o igual a la correspondiente Max.
- Se aceptan enteros PHP o cadenas decimales canónicas (`0` o dígitos sin cero
  inicial). Se rechazan negativos, signos, decimales, flotantes, booleanos, null,
  notación exponencial, ceros iniciales y espacios dentro de cadenas numéricas.
  Los espacios de formato entre elementos JSON sí se permiten.
- Las matrices/vectores pueden llegar como arreglos o texto JSON. Objetos JSON,
  incluso con claves numéricas, no son listas. No se reindexan ni ordenan entradas
  irregulares; se rechazan dimensiones y anidación incorrectas.
- Límite: 32 KiB (32,768 bytes) para el cuerpo HTTP y para la representación JSON
  de la entrada del servicio, incluidos campos adicionales. La codificación de
  formularios puede ocupar más que el texto visible. Se rechaza con HTTP 413,
  sin guardar old input, antes de decodificar las matrices.
- La decodificación JSON tiene profundidad limitada a 8. Los formularios que
  exceden los límites de campos/anidación del parser PHP se rechazan con 422,
  sin aceptar su versión truncada. El límite HTTP no impide que el servidor/PHP
  reciban inicialmente el cuerpo ni sustituye sus propios límites.

`DeadlockScenarioRequest` recupera el cuerpo original para no aceptar números
ambiguos recortados por middleware. Admite URL-encoded, `application/json` y
`application/*+json`, sin distinguir mayúsculas del tipo MIME; no mezcla datos
de la URL con el escenario. Multipart, archivos y otros formatos no se admiten.
Los índices internos y de errores son desde cero; la interfaz presenta P1, R1,
etc. Las validaciones informan el primer error para limitar el trabajo.

## Rutas y respuestas

| Método y ruta | Nombre | Uso |
| --- | --- | --- |
| GET `/interbloqueos` | `deadlocks.index` | Formulario inicial, sin cálculos ni guardado. |
| POST `/interbloqueos` | `deadlocks.validate` | Validar y analizar sin persistir. |
| POST `/interbloqueos/solicitudes` | `deadlocks.request` | Escenario original más `process` (1…n) y `request`. |
| POST `/interbloqueos/recuperacion` | `deadlocks.recovery` | Escenario original más `strategy` y, si aplica, `recovery_process` (1…n). |
| POST `/interbloqueos/historial` | `deadlocks.history.store` | Guardar únicamente el escenario original validado. |
| GET `/interbloqueos/historial` | `deadlocks.history.index` | Listado de 20 registros por página, id descendente. |
| GET `/interbloqueos/historial/{id}` | `deadlocks.history.show` | Reconstruir, validar y recalcular un registro. |

Las cuatro rutas POST mantienen middleware web y CSRF. Los formularios incluyen
_token y vuelven a validar sus campos ocultos. En errores del escenario, HTML
redirige con mensajes/valores anteriores; solicitudes que esperan JSON reciben
422. Errores de Request o elección de recuperación en HTML conservan el análisis
original con respuesta 422. Las decisiones del evaluador de Request devuelven
200, incluso cuando se deniega. Guardar correctamente redirige al detalle con
mensaje de éxito. Los resultados se presentan en HTML, también tras enviar un
escenario JSON válido; no se ofrece una API JSON de resultados.

Los identificadores del historial deben ser positivos canónicos y no superar
9,223,372,036,854,775,807. Ceros iniciales, signos, decimales, texto y desbordamientos
se rechazan antes de consultar. Identificadores inválidos o inexistentes reciben
404 con mensaje claro. Un escenario guardado inválido recibe 422 (413 si excede
el tamaño); no se analiza ni reescribe. SQLite o tabla no disponibles producen
503 sin SQL ni rutas internas. CSRF inválido produce 419 y formato no admitido,
415; archivos/cargas excesivas producen 413.

## Servicios, algoritmos y objetos de resultado

### Need, seguridad y pasos

`NeedCalculator::calculate` recibe un escenario validado y calcula
`Need[i][j] = Max[i][j] - Allocation[i][j]`, en O(n*m) tiempo/espacio. No repite
la validación ni modifica el escenario.

`BankerSafetyAnalyzer::analyze` reutiliza ese calculador, inicia Work con
Available y Finish con false. Selecciona el primer proceso pendiente que cumple
Need <= Work en todos los recursos; lo marca terminado y suma Allocation a Work.
Reinicia la búsqueda desde la primera fila y se detiene al terminar todos o no
poder avanzar. No enumera todas las secuencias. Coste máximo: O(n²*m) tiempo y
O(n*m + n²) espacio, incluidas las instantáneas de los pasos.

`SafetyResult` conserva Need, `isSafe`, `safeSequence`, `completed`, `unfinished`,
Work final, Finish y `steps`. La secuencia es null en estado inseguro, aunque
haya progreso parcial. Cada `SafetyStep` registra número desde 1, proceso desde
0, Work antes/después, Need, Allocation y Finish actualizado. Se capturan dentro
del mismo algoritmo; no se reconstruyen ni se ejecuta un segundo algoritmo.
La tabla accesible usa caption, encabezados y región desplazable con teclado.
Si no hubo avance no se inventan filas; una ejecución parcial no se presenta
como secuencia segura completa.

### Evaluación de Request

`ResourceRequestInput` reutiliza `ScenarioInput` y devuelve un `ResourceRequest`
inmutable. `ResourceRequestEvaluator` recibe objetos ya validados, comprueba
primero Request <= Need y después Request <= Available:

| Decisión de `ResourceRequestResult` | Resultado |
| --- | --- |
| `invalid` | Request supera Need; no se intenta asignación temporal. |
| `wait` | Request supera Available; debe esperar, sin análisis temporal. |
| `granted` | La copia temporal es segura; concesión solo en simulación. |
| `unsafe` | La copia es insegura; se deniega sin reemplazar el original. |

La copia usa Available' = Available - Request y Allocation'[i] = Allocation[i]
+ Request; Max no cambia. El banquero obtiene Need' = Need - Request. Un vector
cero también se evalúa, y no se presupone que el original sea seguro. El resultado
concedido expone la copia y su seguridad; el denegado por inseguridad conserva
su análisis temporal pero no lo muestra como escenario concedido.

### Grafo y ciclos

`ResourceGraphBuilder::build($scenario, ?$request)` reutiliza NeedCalculator y
devuelve `ResourceGraph` inmutable. Nodos: P1…Pn y luego R1…Rm, numéricamente.
Cada R es un tipo de recurso, con atributos `available` y `total` (Available más
la suma de Allocation de su columna). No se crean instancias individuales.

Las aristas tienen `source`, `target`, `type` y `quantity`. Siguen el orden
proceso/recurso y, dentro de cada par, Allocation, Need y Request. Se omiten ceros:

- `allocation`: R → P, unidades asignadas.
- `need`: P → R, necesidad potencial, excluida del análisis de ciclos activos.
- `request`: P → R, vector explícito, separado de Need y de la concesión.

La presentación es una representación accesible equivalente mediante tablas de
nodos y aristas dirigidas con cantidades y descripción textual. No depende de
JavaScript ni de bibliotecas externas. El constructor cuesta O(n*m) y los
escenarios públicos tienen hasta 40 nodos y 820 aristas agregadas.

`ResourceCycleDetector::detect` analiza solo Allocation y Request positivas.
Ejecuta DFS con estados no visitado, activo y terminado. Una arista hacia un nodo
activo cierra un ciclo; la pila reconstruye el recorrido repitiendo al final su
primer nodo. Respeta el orden de nodos y de aristas salientes, visita componentes
desconectadas y devuelve el primer ciclo determinista, no todos. Coste O(V+E)
en tiempo y espacio. `ResourceCycleResult` contiene `status` y `path`:

- `no_request`: no hay Request positiva que analizar; recorrido vacío.
- `acyclic`: hay Request positiva y no se detectaron ciclos; recorrido vacío.
- `cycle`: se detectó un ciclo; recorrido dirigido cerrado.

El grafo/ciclo usa el escenario original y el vector enviado como **instantánea
educativa anterior a evaluar Request**, incluso si luego se concede o rechaza
por superar Need. No afirma que Request permanezca activa después de la decisión.
Una Request mal formada no se incorpora; una Request cero no aporta aristas.
La interfaz recibe un proceso solicitante por ensayo; el detector interno admite
solicitudes de varios procesos. No se inventan aristas para cerrar recorridos.

### Recuperación conceptual

`RecoveryInput` valida la elección y devuelve `RecoveryChoice`; la lógica reside
en `RecoverySimulator` y devuelve `RecoveryResult`, separado del controlador:

| Estrategia | Efecto y limitaciones |
| --- | --- |
| `terminate` | Retira el proceso seleccionado y libera su Allocation en una copia. Puede perder trabajo y requerir reinicio o compensación. |
| `victim` | Elige la mayor suma de Allocation; en empate, menor posición original. Ignora el selector manual. No mide prioridades, costos ni utilidad de distintos tipos de recursos. |
| `preemption` | Solo explica apropiación o retiro: requiere recursos apropiables, puntos de control, rollback o compensación. E/S o cambios parciales pueden no deshacerse; retiros repetidos pueden causar inanición. No libera unidades ni genera una copia o análisis ficticio. |

Para finalizar, elimina las filas Allocation y Max de la víctima y suma sus
unidades a Available. Conserva los recursos totales por tipo y reevalúa únicamente
los supervivientes con `BankerSafetyAnalyzer`; puede seguir siendo inseguro.
`activeProcesses` traduce índices temporales a posiciones originales: retirar P1
no renombra P2 como P1 ni deja a la víctima pendiente. Si se retira el último
proceso, solo internamente se admite n=0: matrices, pasos, secuencia y pendientes
vacíos, estado seguro sin procesos activos. La entrada pública sigue exigiendo n>=1.
Las sumas internas de Available/Work pueden superar 1,000,000 sin crear recursos;
el límite numérico se aplica a la entrada original.

La recuperación parte siempre del original, no de una concesión simulada.
Conserva el resultado de Request, el grafo y el ciclo originales cuando existe
contexto explícito válido. No actúa sobre el sistema operativo ni guarda el
resultado temporal. No garantiza seguridad ni confirma un interbloqueo previo.

## Ejemplos y resultados esperados

### Escenario con secuencia y pasos

Introducir n=3, m=2:

```text
Allocation = [[1,2],[2,0],[0,1]]
Max        = [[3,2],[2,1],[1,2]]
Available  = [0,1]
Need       = [[2,0],[0,1],[1,1]]
```

Resultado: estado seguro, secuencia **P2 → P1 → P3**.

| Paso | Proceso | Work antes | Need | Allocation devuelta | Work después | Finish (P1,P2,P3) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | P2 | [0,1] | [0,1] | [2,0] | [2,1] | [false,true,false] |
| 2 | P1 | [2,1] | [2,0] | [1,2] | [3,3] | [true,true,false] |
| 3 | P3 | [3,3] | [1,1] | [0,1] | [3,4] | [true,true,true] |

Sin Request explícita, el resultado de ciclos es `no_request`, aunque haya
aristas Need. Por ejemplo Allocation `[[2]]` con Max `[[1]]` se rechaza: no se
calcula una Need negativa ni se ejecuta el banquero.

### Cuatro decisiones de Request y un ciclo compatible con seguridad

Introducir n=2, m=1, Allocation `[[1],[1]]`, Max `[[3],[2]]`, Available `[1]`.
Need es `[[2],[1]]`, hay tres unidades de R1 y la secuencia es **P2 → P1**.
Evaluar cada solicitud por separado sobre este mismo original:

| Proceso | Request | Decisión esperada |
| --- | --- | --- |
| P2 | [1] | `granted`: Allocation'=[[1],[2]], Available'=[0], Need'=[[2],[0]]. |
| P1 | [1] | `unsafe`: ambos necesitan una unidad y Work temporal es [0]. |
| P1 | [2] | `wait`: supera Available, aunque no supera Need. |
| P2 | [2] | `invalid`: supera Need de P2. |

Con Request de P2 `[1]`, el recorrido es **P2 → R1 → P2**, usando Allocation y
Request del original. El banquero concede en la simulación: el ciclo agregado
con múltiples instancias no demuestra interbloqueo. Tras esa evaluación,
finalizar conceptualmente P2 libera `[1]` del original: Available temporal `[2]`,
solo P1 activo con Allocation `[[1]]`, Max `[[3]]` y Need `[[2]]`; secuencia temporal
**P1**. Guardar desde esa pantalla conserva el original `[[1],[1]]` y `[1]`,
no las copias de solicitud o recuperación.

### Inseguridad y recuperación sin garantías

Con n=2, m=1, Allocation `[[1],[1]]`, Max `[[2],[2]]` y Available `[0]`, Need es
`[[1],[1]]`: no hay pasos, el estado es inseguro y no hay solicitudes activas
para ciclos. Retirar P1 libera `[1]` y permite terminar P2 en la copia.
En cambio, Allocation `[[0],[0]]`, Max `[[1],[1]]`, Available `[0]` sigue inseguro
al retirar P1: libera `[0]` y P2 continúa pendiente.

## SQLite: datos, reconstrucción y disponibilidad

Modelo dedicado: `DeadlockSimulation`, conexión Laravel `sqlite` configurada en
el proyecto, asignación masiva bloqueada. Migración nueva de M4:
`database/migrations/2026_10_16_000000_create_deadlock_simulations_table.php`.
Crea solo `deadlock_simulations` con id, n, m, allocation, max, available y timestamps.
Las matrices/vector se almacenan como JSON ordenado (texto en SQLite), con casts
Eloquent de arreglo. La fecha del listado se muestra como texto escapado en UTC,
sin interpretar fechas corruptas.

`DeadlockSimulationStore::save` valida con ScenarioInput y asigna exclusivamente
los cinco campos del escenario mediante Eloquent. No guarda credenciales,
sesiones, datos reales de procesos, campos extra, Need, seguridad, pasos, Request,
ciclos ni recuperación. Cada guardado es una inserción; no hay rutas de borrado
ni actualización. Consultar o crear otro registro no modifica los anteriores.

`reconstruct` revalida el JSON original sin casts para distinguir objetos y
listas. El detalle recalcula Need, seguridad, pasos, grafo y ciclos con los
servicios existentes. No confía en resultados persistidos como definitivos ni
reconstruye solicitudes activas desde Need. Un registro corrupto permanece
intacto y no se analiza. Las consultas son parametrizadas y los errores de
almacenamiento devuelven mensajes genéricos, también con debug activo.

**La migración de desarrollo queda pendiente de revisión y aplicación manual
por el responsable.** Ninguna ruta aplica migraciones. No se cambian `.env`,
conexiones manuales, migraciones históricas ni esquemas de otros módulos.
Si el archivo o la tabla aún no están disponibles, el simulador en memoria sigue
operativo y las rutas del historial responden 503.

## Seguridad verificada

Se conservan las medidas y pruebas de la auditoría del Prompt 11:

- Validación estricta de escenarios, Request y selección de recuperación antes
  de calcular o guardar; límites de cuerpo, dimensiones, valores y profundidad.
- CSRF en las cuatro rutas POST, incluidos tokens ausentes/incorrectos; las
  pruebas finales también ejercitan un flujo completo con token válido.
- Lectura del cuerpo original, formatos explícitos, rechazo de mezcla con URL
  y de formularios truncados. El manejador del parser se restaura en `finally`.
- Escape Blade en entradas, matrices, errores, recorridos y fechas corruptas;
  tablas y textos accesibles, sin contenido HTML crudo procedente de la entrada.
- Identificadores canónicos antes de consultar, asignación masiva bloqueada,
  revalidación de registros corruptos y errores SQLite sin SQL ni rutas internas.
- Grafo acotado, cantidades agregadas, DFS determinista y Need excluida de ciclos;
  ninguna llamada a controles reales de procesos ni cambio en decisiones del banquero.

## Pruebas y validación reproducibles

Desde la raíz del proyecto, con las dependencias existentes y PHP/SQLite del
entorno de pruebas disponibles:

```bash
# Todo M4, incluidas persistencia y seguridad
php artisan test --filter=Deadlock

# Solo persistencia y recorrido integral de formularios
php artisan test --filter=DeadlockHistoryTest

# Suite completa con el bootstrap SQLite aislado existente
php artisan test --bootstrap=tests/Support/SchedulingSqliteBootstrap.php

# Formato de todo el proyecto
./vendor/bin/pint --test
```

Se espera cero fallos y Pint aprobado. No se requiere aplicar la migración a
desarrollo para ejecutar estas pruebas. El historial usa `testing` y SQLite
`:memory:`, verifica la conexión efectiva y aplica únicamente su migración nueva
con `migrate`, sin refrescar bases existentes. El bootstrap de la suite completa
verifica el entorno y prepara SQLite en memoria con migraciones no destructivas
para evitar que los tests antiguos con RefreshDatabase soliciten `migrate:fresh`.
Las pruebas de fallos de archivo no crean ni abren una base real de desarrollo.

| Cobertura | Pruebas existentes y regresión final |
| --- | --- |
| Entrada y Need | `DeadlockScenarioInputTest`, `DeadlockInputTest`, `DeadlockNeedCalculatorTest`, `DeadlockNeedTest`: dimensiones, orden, ceros, máximos, números ambiguos, objetos y errores. |
| Seguridad y pasos | `DeadlockBankerSafetyTest`, `DeadlockSafetyTest`, `DeadlockSafetyStepsTest`: secuencia determinista, conservación, progreso parcial, ausencia de avance e instantáneas. |
| Request | `DeadlockResourceRequestTest` unitario/funcional: cuatro decisiones, copias, validación y conservación del original. |
| Grafo y ciclos | `DeadlockResourceGraphTest`, `DeadlockResourceCycleTest` unitarios/funcionales: dirección, cantidades, ceros, múltiples instancias, componentes, orden y máximo público; recorridos reales y Need excluida. |
| Recuperación | `DeadlockRecoveryTest` unitario/funcional: víctima y desempate, liberación, conservación, identidad de supervivientes, último proceso, inseguridad persistente y apropiación solo conceptual. |
| Historial | `DeadlockHistoryTest`: guardado validado, paginación, reconstrucción, límites, inmutabilidad, ids inválidos/inexistentes, JSON/fechas corruptos y SQLite no disponible. |
| Seguridad HTTP | `DeadlockSecurityAuditTest`: formatos, cuerpo frente a URL, CSRF, cargas excesivas, límites del parser, XSS y procesos inexistentes; `DeadlockTest` comprueba la entrada del módulo. |
| Recorrido integral final | `DeadlockHistoryTest::test_complete_workflow_uses_rendered_forms_and_persists_only_original_scenarios`: token válido, campos ocultos reales, validar → guardar → abrir → Request → ciclo → recuperación → guardar otro original → listar/reabrir; las copias y solicitudes no persisten. |

No se añadieron duplicados de los casos ya cubiertos ni se cambiaron algoritmos
para el cierre documental. Las cantidades exactas de pruebas/aserciones se
obtienen al ejecutar los comandos anteriores y se informan en el cierre de M4.

## Limitaciones y cierre de alcance

M4 es una simulación educativa, no un monitor de bloqueos reales. No confirma
interbloqueos, enumera todos los ciclos ni garantiza que una recuperación sea
suficiente. Solo admite una Request explícita por ensayo desde la interfaz y
no conserva solicitudes o recuperaciones en el historial. La apropiación es
explicativa porque no se modelan propiedades de recursos ni rollback real.
El grafo usa tablas accesibles, no una librería gráfica externa. La migración de
desarrollo permanece pendiente de autorización. El cierre no incluye despliegue,
operaciones de Git, integración con main ni publicación de pull requests.
