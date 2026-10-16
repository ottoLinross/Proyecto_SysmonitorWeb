# Servicios de interbloqueos (M4)

`ScenarioInput::validate(array $input)` valida el escenario y devuelve un
`DeadlockScenario` inmutable con `n`, `m`, `allocation`, `max` y `available`.
Los servicios están separados de sistema y planificación. Solo el almacenamiento
educativo del Prompt 10 accede a SQLite; los cálculos permanecen en memoria.

## Contrato de entrada y límites

- `n`: de 1 a 20 procesos; `m`: de 1 a 20 tipos de recursos.
- Valores: de 0 a 1,000,000, incluidos ambos extremos.
- Allocation y Max: listas consecutivas de exactamente n filas, cada una con
  exactamente m valores. Available: lista consecutiva de exactamente m valores.
- Se aceptan enteros PHP o cadenas decimales canónicas (`0` o dígitos sin cero
  inicial). Se rechazan flotantes, booleanos, null, signos, espacios dentro de
  cadenas numéricas, notación exponencial y ceros iniciales. Los espacios de
  formato entre elementos JSON sí se permiten.
- Cada celda Allocation debe ser menor o igual a la celda correspondiente Max.
- No se ordenan ni reindexan listas irregulares: se rechazan. Los índices de
  mensajes de campo son de base cero; los mensajes de comparación indican
  proceso y recurso desde uno.
- Cada matriz/vector puede llegar como arreglo o como texto JSON. Los objetos
  JSON no son listas, incluso cuando tienen claves numéricas.
- Límite: 32 KiB (32,768 bytes) para el cuerpo HTTP y para la representación
  JSON de la entrada del servicio, incluidos campos adicionales. La codificación
  del formulario puede ocupar más que su texto visible. Se comprueba antes de
  decodificar las matrices; se rechaza con HTTP 413, sin guardar old input.
  Este límite complementa los límites del servidor/PHP, no evita que estos
  reciban o procesen inicialmente el cuerpo HTTP.

## Interfaz HTTP

GET `/interbloqueos` muestra el formulario. POST `/interbloqueos` valida y
muestra el escenario normalizado, sin persistirlo. El formulario usa listas
JSON en textareas, sin JavaScript. La ruta conserva el middleware web y CSRF.
Se aceptan formularios `application/x-www-form-urlencoded` y objetos
`application/json`; multipart y archivos no están soportados.

`DeadlockScenarioRequest` recupera el cuerpo original antes de entregar los
datos al servicio para que el recorte global de espacios no acepte cadenas
numéricas ambiguas. Los errores de validación regresan al formulario con
mensajes y valores anteriores; solicitudes JSON reciben errores HTTP 422.
El servicio informa el primer error encontrado para limitar el trabajo.

## Matriz de necesidad (Need)

`NeedCalculator::calculate(DeadlockScenario $scenario)` recibe un escenario ya
validado por `ScenarioInput` y devuelve una lista de n filas con m enteros por
fila, mediante `Need[i][j] = Max[i][j] - Allocation[i][j]`. Conserva el orden
de procesos y recursos, no modifica el escenario y no mantiene estado.
No repite validaciones: depende del contrato del Prompt 2, incluida la condición
Allocation <= Max. El cálculo cuesta O(n*m) en tiempo y espacio de salida.

POST `/interbloqueos` calcula Need solo después de validar y la muestra junto
a las matrices del escenario, con el mismo formato JSON de presentación.
La pantalla inicial y las entradas rechazadas no muestran resultados Need.

## Algoritmo de seguridad del banquero

`BankerSafetyAnalyzer::analyze(DeadlockScenario $scenario)` recibe el escenario
validado y utiliza `NeedCalculator` una sola vez. Inicializa Work con Available
y Finish con false. En cada iteración selecciona el primer proceso pendiente
en orden original que satisface Need <= Work en todos los recursos. Al terminar
lo marca y suma su Allocation a Work; reinicia la búsqueda desde la primera
posición. Se detiene cuando todos terminan o ningún pendiente puede terminar.

Devuelve un `SafetyResult` inmutable con Need, `isSafe`, `safeSequence`,
`completed`, `unfinished`, Work final y Finish final. Las posiciones son índices
desde cero; la vista las presenta como P1, P2, etc. `safeSequence` es null en
estado inseguro, aunque `completed` puede contener una ejecución parcial.
`unfinished` conserva el orden original. Un estado inseguro no confirma un
interbloqueo. No se enumeran todas las secuencias posibles.

El análisis no modifica el escenario ni guarda estado entre llamadas. Su coste
máximo es O(n²*m) en tiempo y O(n*m + n²) en espacio, incluidas Need y las
instantáneas de Finish de los pasos. El controlador
reutiliza Need del resultado para su presentación y muestra el estado y la
secuencia segura o los procesos pendientes después de una validación exitosa.

## Ejecución paso a paso

`SafetyResult::steps` contiene una lista de `SafetyStep` inmutables registrados
dentro del mismo bucle de `BankerSafetyAnalyzer`. No se reconstruye la ejecución
ni se ejecuta un segundo algoritmo. Cada paso incluye número desde 1, posición
del proceso desde 0 (P1, P2, etc. en la vista), Work antes, Need y Allocation del
proceso, Work después de devolver Allocation y Finish actualizado en orden
original. Los arreglos se capturan por valor y conservan las instantáneas previas.

La vista muestra los registros en una tabla con caption, encabezados y región
desplazable accesible con teclado. Los vectores siguen el orden de recursos;
Finish contiene booleanos en orden de procesos. En estado seguro hay un paso
por proceso. En estado inseguro solo se muestran los pasos realmente ejecutados,
sin presentar el progreso parcial como una secuencia segura. Si no hay progreso,
se muestra un mensaje sin filas inventadas. Se mantiene la lista de pendientes
y la aclaración de que inseguridad no confirma interbloqueo.

La recuperación conceptual y la persistencia educativa se describen en los apartados de los Prompts 9 y 10.
Las solicitudes se evalúan únicamente como simulación educativa, según el
apartado siguiente.

## Solicitudes adicionales de recursos (simulación educativa)

POST `/interbloqueos/solicitudes` (`deadlocks.request`) recibe el escenario
original más `process` (posición de 1 a n) y `request` (lista JSON o arreglo de
exactamente m enteros). El formulario solo ofrece procesos del escenario ya
validado. Los campos ocultos se consideran entrada no confiable: el backend
valida de nuevo todo el escenario. Se mantienen los límites de 32 KiB, tipos
numéricos canónicos y valores de 0 a 1,000,000. `ResourceRequestInput` reutiliza
las reglas de `ScenarioInput` y devuelve un `ResourceRequest` inmutable con
posición interna desde cero y vector normalizado, sin alterar su orden.

`ResourceRequestEvaluator` recibe ambos objetos ya validados. Calcula Need con
`NeedCalculator`, comprueba primero Request <= Need para todos los recursos y
luego Request <= Available. Las decisiones de `ResourceRequestResult` son:

- `invalid`: excede Need; no se intenta la asignación temporal.
- `wait`: excede Available; debe esperar, sin ejecutar el analizador temporal.
- `granted`: la copia temporal sigue siendo segura; solo se concede en la
  simulación y se exponen el escenario temporal y su `SafetyResult`.
- `unsafe`: la copia temporal es insegura; se deniega, sin exponer un escenario
  concedido ni modificar el original. El resultado conserva el análisis temporal.

La copia usa Available' = Available - Request y Allocation'[i] = Allocation[i]
+ Request. Max se conserva; `BankerSafetyAnalyzer` reutiliza `NeedCalculator`
para obtener Need' = Need - Request y ejecuta el único algoritmo de seguridad
existente. No se duplican sus reglas ni sus pasos. Un vector cero también se
somete al análisis de seguridad. No se exige que el estado original sea seguro:
se informa el resultado real del análisis temporal.

Los errores de formato, dimensiones o proceso inexistente son solicitudes
inválidas (HTTP 422); en HTML se conservan el análisis original, sus pasos y los
valores recibidos con los mensajes. En JSON se devuelve el formato de errores
422 de Laravel. Las cuatro decisiones de evaluación devuelven la vista con
HTTP 200. En una concesión se presentan Allocation', Max, Available' y Need'
en un bloque separado; los pasos y el análisis principal siguen correspondiendo
al original. Cada nueva evaluación parte del escenario original, sin encadenar
concesiones, guardar simulaciones ni asignar recursos reales del servidor.


## Grafo de asignación de recursos (Prompt 7)

`ResourceGraphBuilder::build(DeadlockScenario $scenario, ?ResourceRequest $request = null)`
recibe objetos previamente validados por `ScenarioInput` y `ResourceRequestInput`.
Reutiliza `NeedCalculator` y devuelve un `ResourceGraph` inmutable con listas de
nodos (`id`, `type`) y aristas (`source`, `target`, `type`, `quantity`). No modifica
el escenario ni evalúa seguridad o ciclos; la detección se delega al servicio independiente descrito abajo. Su tiempo y espacio son O(n*m).

Los nodos se ordenan P1…Pn y luego R1…Rm, numéricamente. Cada R es un tipo de
recurso, sin crear nodos para instancias individuales. Sus atributos `available`
y `total` indican unidades disponibles y unidades existentes (Available más
la suma de Allocation de la columna). Max es demanda, no inventario.
Las aristas siguen el orden proceso/recurso y, dentro de cada par, Allocation,
Need y Request. Se omiten cantidades cero:

- `allocation`: R → P, unidades asignadas en Allocation.
- `need`: P → R, necesidad potencial o demanda restante máxima; **no representa
  necesariamente una solicitud activa**.
- `request`: P → R, vector explícito enviado para evaluación, separado de Need;
  no se suma a Need ni representa una concesión.

La página ofrece una representación accesible equivalente del grafo mediante
una tabla de nodos y otra de aristas dirigidas, con cantidades, encabezados,
captions y regiones desplazables por teclado. Conserva una descripción textual,
usa escape Blade y no requiere JavaScript ni dependencias externas. Sin aristas
se muestra un mensaje explícito. Las matrices, seguridad, pasos y formulario de
solicitudes se mantienen.

El grafo siempre corresponde al escenario original. Una Request bien formada
se representa incluso si su evaluación la deniega o excede Need; la decisión
se consulta en el resultado existente. Tras una concesión simulada, la arista
Request describe el vector evaluado, no una solicitud todavía pendiente.
Una Request mal formada no crea aristas explícitas; el grafo original permanece.

Es una simulación educativa: un ciclo con múltiples instancias de recursos no
demuestra por sí solo un interbloqueo. El grafo no confirma interbloqueos.
La detección de ciclos del Prompt 8 se describe a continuación.


## Detección de ciclos dirigidos (Prompt 8)

`ResourceCycleDetector::detect(ResourceGraph $graph)` recibe un grafo producido
por `ResourceGraphBuilder` (nodos existentes y aristas válidas). Construye listas
de adyacencia solo con `allocation` y `request` de cantidad positiva: **Need
queda excluida**, pues no es una solicitud activa. Las cantidades mayores que
uno mantienen una sola arista agregada; no se crean instancias ficticias.

El servicio ejecuta DFS con estados no visitado, activo y terminado. Una arista
hacia un nodo activo cierra un ciclo y la pila permite reconstruir su recorrido,
repitiendo el primer nodo al final. Los nodos terminados no generan falsos ciclos.
Se recorren todas las componentes hasta hallar el primer ciclo, respetando el
orden de nodos y el orden original de aristas salientes. No enumera todos los
ciclos. Tiempo O(V+E), espacio O(V+E), sin mutar el grafo ni conservar estado.

`ResourceCycleResult` es inmutable y contiene `status` y `path`:

- `no_request`: no hay aristas Request positivas para analizar; recorrido vacío.
- `acyclic`: hay Request positiva y no se detectaron ciclos; recorrido vacío.
- `cycle`: se detectó al menos un ciclo; recorrido dirigido cerrado.

La interfaz muestra texto accesible y el recorrido con escape Blade. Conserva
matrices, grafo, análisis de seguridad, pasos y resultado de la solicitud.
El análisis corresponde al escenario original y al vector explícito enviado,
como instantánea educativa **anterior a evaluar la solicitud**, incluso si luego
se concede o rechaza. No convierte la Request evaluada en una solicitud que
permanezca activa después de la decisión. Un vector mal formado no se incorpora;
un vector cero no aporta aristas activas. Las aristas Need nunca cierran ciclos
en este análisis. Actualmente el formulario aporta un único proceso solicitante;
el detector también admite grafos con solicitudes de varios procesos.

Un ciclo con múltiples instancias no demuestra por sí solo un interbloqueo.
El resultado no confirma interbloqueos y no sustituye la seguridad del banquero;
la ausencia de ciclos tampoco convierte la instantánea en prueba de seguridad.
La recuperación conceptual y el guardado explícito de escenarios se describen a continuación.


## Recuperación conceptual (Prompt 9)

POST `/interbloqueos/recuperacion` (`deadlocks.recovery`) recibe el escenario
original, `strategy` y, para finalización manual, `recovery_process` (1…n).
`RecoveryInput` reutiliza los límites de `ScenarioInput`, incluidos 32 KiB y
los números canónicos. `DeadlockScenarioRequest` conserva la validación del
cuerpo original, CSRF y rechazo de archivos/multipart. Los campos ocultos se
revalidan; no se confía en ellos. Los errores JSON reciben HTTP 422; los errores
HTML de elección conservan el análisis original y muestran mensajes con escape.
No se envía ninguna señal ni se utilizan servicios de control de procesos reales.

`RecoveryChoice` y `RecoveryResult` son objetos inmutables. La lógica reside en
`RecoverySimulator`, separado del controlador, y ofrece:

- `terminate`: retira conceptualmente el proceso seleccionado y libera todas
  las unidades de su fila Allocation. Permite explorar una elección manual.
- `victim`: elige la mayor suma de unidades en Allocation, con desempate por
  menor posición original. Ignora el selector manual. Es una heurística sencilla
  para liberar más unidades, no un criterio óptimo: sumar tipos distintos no
  mide su utilidad, costo ni prioridad y puede perjudicar un proceso útil.
- `preemption`: explica la apropiación o retiro sin simularlo, pues el escenario
  no describe qué recursos son apropiables ni cómo restaurar su consistencia.
  Podría evitar finalizar un proceso, pero requiere puntos de control, rollback
  o compensaciones; E/S y cambios parciales pueden ser irreversibles. Los retiros
  repetidos pueden provocar inanición. No genera matrices, libera unidades ni
  ejecuta un análisis de seguridad ficticio.

Para las dos primeras estrategias se crea una copia `DeadlockScenario`: se
eliminan las filas Allocation y Max de la víctima y se suma su Allocation a
Available. Los demás procesos y sus demandas se conservan en orden; la cantidad
total de unidades por tipo (asignadas + disponibles) no cambia. El proceso
retirado no forma parte de los pendientes ni de la secuencia temporal. Finalizar
conceptualmente puede perder trabajo y exigir reinicio o compensación; no se
garantiza que los supervivientes queden en estado seguro.

Se reutiliza `BankerSafetyAnalyzer` sobre la copia para obtener `SafetyResult`,
Need y la secuencia o los pendientes temporales. `RecoveryResult::activeProcesses`
traduce cada índice temporal a la posición original desde cero, de modo que
retirar P1 no renombra P2 como P1. La vista identifica las filas con esas etiquetas
originales. Si se retira el último proceso se permite **solo internamente** un
escenario de n=0, matrices vacías y todos los recursos liberados: el analizador
existente devuelve seguridad con secuencia, pasos y pendientes vacíos. El
contrato público de `ScenarioInput` sigue exigiendo al menos un proceso.
Available temporal puede superar 1,000,000 al sumar unidades previamente
asignadas; el límite sigue aplicándose a la entrada original, no a esa suma.

La interfaz presenta unidades liberadas, Allocation, Max, Available y Need
simuladas, orden de las filas y seguridad temporal. Conserva las matrices,
seguridad, pasos, `ResourceGraph` y `ResourceCycleResult` **originales**.
Si hay una Request explícita válida en la página, el formulario conserva su
contexto y el servidor vuelve a validar y evaluar ese mismo vector para mostrar
su decisión, grafo y ciclo sin alterarlos. Recuperación parte siempre del
escenario original, no del estado concedido en la simulación de Request; tampoco
encadena ensayos de recuperación. No se inventan solicitudes activas ni se
reclasifica la solicitud del proceso retirado en el análisis original.

Estas opciones son educativas y voluntarias, incluso en estados seguros.
Inseguridad del banquero no equivale a interbloqueo confirmado. Un ciclo con
múltiples instancias tampoco lo confirma; su ausencia no prueba seguridad.
La recuperación no se presenta como obligatoria ni como solución garantizada.
No se ejecutan acciones sobre el sistema operativo, no se guarda el resultado de recuperación. El guardado explícito del escenario
original se describe a continuación; no se implementan los Prompts 11 o 12.


## Persistencia educativa con SQLite (Prompt 10)

Migración nueva: `2026_10_16_000000_create_deadlock_simulations_table.php`.
Crea exclusivamente `deadlock_simulations`, mediante la conexión Laravel `sqlite`
configurada en el proyecto. No modifica tablas ni migraciones históricas de otros
módulos. **La migración de desarrollo queda pendiente de aplicación manual tras
revisión y autorización**; no se aplica automáticamente desde ninguna ruta.
No es necesario cambiar `.env` ni instalar dependencias.

La tabla contiene `id`, `n`, `m`, `allocation`, `max`, `available`, `created_at`
y `updated_at`. Las matrices y el vector se guardan como JSON ordenado, con los
casts de arreglo del modelo dedicado `DeadlockSimulation`. SQLite conserva estos
campos JSON como texto. No se guardan Need, seguridad, pasos, ciclos, Request,
recuperaciones, datos reales de procesos, credenciales ni campos extra enviados.
El modelo bloquea asignación masiva; `DeadlockSimulationStore::save` valida toda
la entrada con `ScenarioInput` y asigna únicamente los cinco campos permitidos.
Cada guardado inserta un registro nuevo; no hay rutas de actualización o borrado.
Se conserva el límite de entrada de 32 KiB, dimensiones 1…20, valores 0…1,000,000,
formato numérico canónico y Allocation <= Max, también para campos ocultos.

Rutas de M4:

- POST `/interbloqueos/historial` (`deadlocks.history.store`): recibe el escenario
  original, protegido por el middleware web y CSRF; vuelve a validarlo, inserta
  mediante Eloquent y redirige al detalle con mensaje de guardado.
- GET `/interbloqueos/historial` (`deadlocks.history.index`): lista paginada de
  20 registros, en orden de identificador descendente; muestra un mensaje vacío
  cuando no hay registros. Las fechas del listado se indican explícitamente en UTC.
- GET `/interbloqueos/historial/{id}` (`deadlocks.history.show`): acepta únicamente
  identificadores positivos canónicos dentro del rango entero de SQLite de 64
  bits. Rechaza ceros iniciales, signos, decimales, texto y desbordamientos antes
  de consultar. Identificadores inválidos o inexistentes reciben HTTP 404 con
  mensaje claro. Las consultas usan Eloquent y parámetros, no SQL concatenado.

Al recuperar un registro, `DeadlockSimulationStore::reconstruct` pasa sus valores
originales a `ScenarioInput`, incluido el JSON sin casts, para seguir rechazando
objetos donde se exigen listas. El controlador reutiliza `BankerSafetyAnalyzer`
para recalcular Need, seguridad y pasos, y los servicios de grafo y ciclos sobre
el escenario reconstruido. No confía en resultados guardados ni los requiere.
Un registro que no supera la validación no se analiza ni se reescribe: se muestra
un mensaje HTTP 422; el límite de tamaño sigue produciendo HTTP 413.
La indisponibilidad de SQLite o de la tabla devuelve un mensaje HTTP 503 sin
exponer consultas. Consultar registros no actualiza matrices ni timestamps.

La página de detalle conserva formularios de solicitudes y recuperación para
realizar nuevos ensayos temporales; no reconstruye solicitudes activas a partir
de Need. El guardado explícito siempre usa el escenario original mostrado,
incluso después de una solicitud o recuperación simulada. No encadena ni guarda
esas copias. Los valores se presentan con escape Blade. Guardar de nuevo desde
un detalle crea otro registro, sin modificar el anterior.

Las pruebas de persistencia usan SQLite `:memory:` en el entorno `testing`,
verifican la conexión efectiva antes de migrar y aplican únicamente la migración
nueva de M4, sin refrescar ni limpiar bases existentes. Cubren validación,
normalización, orden, recalculado, CSRF, paginación, aislamiento, inmutabilidad,
registros inválidos/inexistentes y fallos de almacenamiento. Para ejecutar la
suite completa sin que los tests antiguos que usan RefreshDatabase soliciten
una migración fresh, se reutiliza el bootstrap de pruebas existente:
`php artisan test --bootstrap=tests/Support/SchedulingSqliteBootstrap.php`.
Este bootstrap verifica `testing` y SQLite en memoria y prepara sus migraciones
con `migrate`; no usa la base local de desarrollo.

## Auditoría de seguridad de M4 (Prompt 11)

Se corrigieron tres casos reproducidos con pruebas: el fallback HTTP podía
aceptar datos de la URL ya recortados por el middleware en lugar del cuerpo
original; `parse_str` podía truncar formularios y emitir advertencias al exceder
los límites de PHP; una fecha corrupta almacenada podía causar una excepción al
listar registros. Ahora se exige Content-Type JSON (también application/*+json)
o URL-encoded, sin distinguir mayúsculas en el tipo MIME; no se mezclan parámetros
de la URL con el escenario. Las advertencias del parser se convierten en HTTP 422
sin detalles internos, restaurando el manejador previo en `finally`. El listado
presenta la fecha almacenada como texto escapado, sin intentar interpretar datos
posiblemente corruptos. Los límites de cuerpo de 32 KiB siguen produciendo 413.

Verificaciones sin cambios innecesarios en algoritmos, modelo o esquema:

- ScenarioInput y entradas de Request/recuperación rechazan valores negativos,
  decimales, booleanos, cadenas ambiguas, listas irregulares, objetos JSON y
  anidación incorrecta. Allocation <= Max mantiene Need entre 0 y 1,000,000;
  Request se valida antes de compararla con Need y Available. Las sumas internas
  de recursos liberados pueden superar el límite de entrada sin ser entradas
  inválidas ni crear unidades nuevas.
- Las cuatro rutas POST mantienen CSRF; las pruebas fuerzan la comprobación de
  tokens ausentes e incorrectos, además de cargas excesivas y tamaños declarados.
  Se conserva el escape Blade de formularios, matrices, errores y recorridos.
- Los escenarios públicos tienen hasta 40 nodos y 820 aristas agregadas. DFS
  conserva su complejidad O(V+E), excluye Need y devuelve solo recorridos cerrados
  con aristas existentes; se prueba el máximo público sin expandir instancias.
  Estos servicios internos reciben objetos validados, no grafos enviados por HTTP.
- Eloquent mantiene la asignación masiva bloqueada y las consultas parametrizadas.
  Los identificadores inválidos no ejecutan consultas; los registros guardados
  se revalidan desde su JSON original antes de analizarlos. Las pruebas incluyen
  JSON corrupto, objetos, null, decimales, valores negativos y dimensiones fuera
  de rango, sin reescribir los registros rechazados.
- Los errores de consultas y la ausencia real del archivo SQLite producen 503
  sin SQL ni rutas internas, incluso con debug activo. No fue necesario cambiar
  el manejo de errores de almacenamiento existente.
- Solicitudes, recuperación, grafo y ciclos permanecen exclusivamente educativos;
  no se añaden llamadas a controles de procesos reales ni se cambia la decisión
  del banquero. No se encontraron razones para modificar esos algoritmos.

La auditoría y las pruebas usan exclusivamente el entorno de pruebas SQLite;
no se aplican migraciones a desarrollo ni se incorporan tareas del Prompt 12.
