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

No se implementan banquero, secuencia segura, solicitudes,
grafos, ciclos, recuperación o persistencia de simulaciones.
