# Servicios de interbloqueos (M4)

`ScenarioInput::validate(array $input)` valida el escenario y devuelve un
`DeadlockScenario` inmutable con `n`, `m`, `allocation`, `max` y `available`.
Los servicios están separados de sistema y planificación y no acceden a BD.

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

No se implementan grafos, ciclos, recuperación ni persistencia de simulaciones.
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
