# M1 — Procesos

## Objetivo

M1 permite consultar procesos reales de Linux, buscar y ordenar su información,
ver un resumen global por estados y explorar las relaciones padre-hijo.
También permite crear un proceso de prueba controlado y administrarlo mediante
cuatro señales o un cambio de nice. Las acciones se limitan a registros de
procesos creados y verificados por SysMonitor.

## Arquitectura

| Componente | Responsabilidad |
| --- | --- |
| `ProcessController` | Obtiene una sola lista del sistema por petición; calcula resumen y árbol globales, filtra/ordena la tabla y entrega registros administrables a Blade. |
| `TestProcessController` | Coordina las acciones POST de lanzamiento, señales y prioridad; devuelve redirecciones y mensajes seguros. |
| `ProcessService` | Ejecuta la lectura fija con `ps`, transforma sus columnas y construye el árbol mediante una función pura. |
| `TestProcessService` | Valida la salida del launcher y la identidad real; persiste y firma el registro dentro de una transacción. |
| `TestProcessLauncher` | Invoca exclusivamente el script interno fijo y recoge su PID. |
| `TestProcessIdentityReader` | Lee identidad, ejecutable, argumentos y nice desde `/proc`, con parsing robusto de `stat`. |
| `ApplicationProcessUid` | Obtiene el UID efectivo de PHP mediante POSIX o `/proc/self/status`. |
| `ManagedProcessIdentityGuard` | Centraliza tipos, metadatos, procedencia, contexto UID, estados e identidad necesarios para administrar un registro. |
| `ManagedProcessProvenance` | Emite y verifica el sello HMAC de procedencia e integridad del registro. |
| `ProcessSignalService` | Aplica la whitelist, consulta el guard, envía y verifica la señal, actualizando el estado cuando corresponde. |
| `ProcessSignalSender` | Encapsula `posix_kill()` para permitir su simulación en pruebas. |
| `ProcessPriorityService` | Valida nice, consulta el mismo guard, llama al runner y comprueba identidad y nice posteriores. |
| `ProcessReniceRunner` | Ejecuta el binario fijo de renice con argumentos en array y captura el resultado internamente. |
| `ManagedProcess` | Modelo Eloquent de los procesos de prueba registrados; oculta la firma y define sus casts. |

`resources/views/processes/index.blade.php` presenta los datos. El partial
`resources/views/processes/partials/tree-node.blade.php` recorre el árbol
iterativamente. Las vistas no ejecutan comandos ni calculan relaciones PID/PPID.

## Lectura de procesos

`ProcessService::getProcesses()` utiliza `proc_open()` con el array fijo:

```php
['/usr/bin/ps', '-e', '-ww', '-o', 'pid=,ppid=,user=,stat=,ni=,pcpu=,pmem=,rss=,args=']
```

No interviene una shell ni hay argumentos procedentes del usuario. `LC_ALL=C`
mantiene un formato numérico consistente; `-ww` evita limitar el ancho del comando.
Se descartan líneas vacías o inválidas. Un fallo de lectura devuelve una lista
vacía, sin exponer la salida de error del sistema.

| Información | Campo PHP | Representación |
| --- | --- | --- |
| PID | `pid` | Entero positivo; identificador del proceso. |
| PPID | `ppid` | Entero no negativo; identificador del padre. |
| Usuario | `user` | Texto informado por `ps`. |
| Estado | `state` | Estado Linux completo, incluidos sus modificadores. |
| Prioridad nice | `nice` | Entero entre -20 y 19. |
| CPU % | `cpu_percent` | Float informado por `ps`. |
| Memoria % | `memory_percent` | Float informado por `ps`. |
| Memoria RSS | `memory_kb` | Entero no negativo, en KB, procedente de RSS. |
| Comando | `command` | Argumentos del proceso, preservando espacios interiores. |

Los porcentajes se presentan con un decimal. El módulo no implementa un muestreo
propio por intervalos ni actualización AJAX; cada GET obtiene una nueva fotografía.
El listado representa los procesos que el entorno permite observar y cuyo formato
puede transformar el servicio; no realiza cambios sobre ellos.

## Búsqueda y ordenamiento

Los parámetros GET de `/procesos` afectan exclusivamente a la tabla general:

- `q`: texto normalizado, coincidencia parcial sin distinguir mayúsculas. Busca
  en las nueve columnas; convierte números a texto y porcentajes al formato visible.
  Vacío muestra todos los procesos. Valores no textuales se tratan como vacío.
- `sort`: whitelist `pid`, `ppid`, `user`, `state`, `nice`, `cpu_percent`,
  `memory_percent`, `memory_kb`, `command`. Un valor inválido restablece `pid asc`.
- `direction`: exclusivamente `asc` o `desc`; cualquier otro valor usa `asc`.

El orden predeterminado es `pid asc`. PID, PPID, nice, porcentajes y RSS se comparan
numéricamente; usuario, estado y comando se comparan como texto en minúsculas.
Los empates se resuelven por PID ascendente. Los encabezados alternan la dirección,
conservan `q` y muestran una flecha. Limpiar búsqueda conserva el orden seleccionado.

Ejemplos de uso: `/procesos?q=apache` y
`/procesos?sort=cpu_percent&direction=desc`.

Se distingue entre lista del servicio vacía y búsqueda sin coincidencias.
Ni el resumen ni el árbol se reducen al resultado de la búsqueda.

## Estados

El resumen global cuenta únicamente el primer carácter de `state`:

| Código | Descripción |
| --- | --- |
| R | Ejecutándose (Running). |
| S | Dormidos (Sleeping). |
| D | Espera no interrumpible (Uninterruptible sleep). |
| Z | Zombies (Zombie). |
| T | Detenidos (Stopped). |

`Ss` y `S+` cuentan como S, `R+` como R y `Tl` como T. Estados desconocidos,
vacíos o nulos se ignoran en estos cinco contadores. El valor original sigue
apareciendo en la tabla. Lista vacía produce cinco ceros.

## Árbol de procesos

`ProcessService::buildProcessTree()` indexa por PID y enlaza el PPID con su padre.
Cada nodo conserva sus campos originales y añade `children`. La entrada puede
tener hijos antes que padres y cualquier cantidad de niveles.

Son raíces los procesos con PPID 0, padre ausente, PPID inválido o PID igual a PPID.
Se detectan ciclos recorriendo enlaces de padres; sus miembros se convierten en
raíces, conservando los descendientes que pueden asociarse con seguridad. No se
inventan padres. Se descartan PIDs inválidos y se conserva una sola entrada por PID.

Raíces e hijos se ordenan por PID ascendente, independientemente de `q`, `sort`
y `direction`. Construcción y presentación son iterativas, sin recursión infinita.
El HTML usa listas `ul/li` y muestra PID, comando, usuario y estado. Sin procesos,
presenta un mensaje específico de árbol vacío.

## Procesos de prueba

El único proceso permitido actualmente es `/usr/bin/sleep 300`. No hay campos
para introducir comandos, rutas, duración, argumentos o PID.

`TestProcessLauncher` invoca `['/bin/sh', ruta_fija_del_script]` mediante
`proc_open()`. `scripts/launch-test-process.sh` ejecuta
`/usr/bin/nohup /usr/bin/sleep 300` en segundo plano, redirige stdin/stdout/stderr
del hijo a `/dev/null` y devuelve exclusivamente el PID mediante `$!`.
El proceso puede continuar después de terminar la petición HTTP y hereda el
usuario del proceso PHP; no se cambia de usuario ni se elevan privilegios.

El servicio valida el PID, verifica `/proc` y registra su identidad y procedencia
en una transacción. Antes de lanzar comprueba que la tabla, la columna de firma
y la clave de aplicación estén disponibles. Si falla la verificación o persistencia,
no queda un registro administrable incompleto y el navegador recibe un error genérico.
Un sleep ya lanzado puede continuar hasta cumplir sus 300 segundos si falla el
registro; el lanzamiento no incluye un mecanismo automático de señales de limpieza.

## Persistencia

`managed_processes` representa registros de pruebas de SysMonitor, no un inventario
persistente de todos los procesos Linux.

| Columna | Finalidad |
| --- | --- |
| `id` | ID interno utilizado por el model binding y las acciones web. |
| `pid` | PID validado devuelto por el launcher. |
| `process_type` | Tipo controlado; actualmente `sleep`. |
| `command_label` | Metadato fijo `/usr/bin/sleep 300`; nunca se ejecuta como comando. |
| `owner_uid` | UID propietario verificado al crear el proceso. |
| `start_time_ticks` | Campo 22 de `/proc/[pid]/stat`, ticks desde el arranque que identifican su inicio. |
| `status` | Estado registrado del proceso administrable. |
| `registration_signature` | Sello HMAC de procedencia e integridad; oculto en la serialización y no mass assignable. |
| `launched_at` | Fecha/hora del lanzamiento registrado. |
| `created_at`, `updated_at` | Timestamps Eloquent. |

La combinación `pid + start_time_ticks` es única en la tabla. Las migraciones de M1,
en orden, son `2026_10_06_000000_create_managed_processes_table.php` y
`2026_10_08_000000_add_registration_signature_to_managed_processes.php`.
La segunda es aditiva y permite firma nula para conservar registros anteriores;
estos quedan visibles, pero no administrables. Deben crearse nuevas pruebas mediante
el launcher, sin firmar retroactivamente filas cuya procedencia no puede demostrarse.

## Seguridad

La frontera común de señales y prioridad es `ManagedProcessIdentityGuard`:

1. Valida el registro y sus valores originales antes de casts: ID válido, PID mayor
   que 1, UID válido y ticks positivos dentro de sus rangos.
2. Exige la whitelist de tipos (`sleep`), etiqueta exacta, estado reconocido y firma
   válida. No basta con insertar una fila de metadatos aparentemente correctos.
3. Comprueba que el UID registrado corresponde al UID efectivo de PHP, obtenido con
   `posix_geteuid()` o con el campo efectivo de `/proc/self/status`. No hay UIDs fijos.
4. El lector verifica PID, UID real/efectivo, starttime, argumentos exactos y el enlace
   `/proc/[pid]/exe` a `/usr/bin/sleep`. Las rutas usan solo un PID previamente validado.
5. Compara identidad actual y registrada. Reutilización de PID, cambio de propietario,
   comando distinto o identidad no verificable impiden cualquier acción.
6. Solo `running` y `stopped` permiten actuar. Los botones deshabilitados son una ayuda
   visual; el backend revalida incluso ante peticiones manipuladas o registros terminales.

El sello es HMAC-SHA256 con la clave de aplicación configurada y se verifica con
`hash_equals()`. Vincula ID, PID, tipo, etiqueta, UID, ticks, estado y fecha de lanzamiento.
Copiarlo a otra fila o modificar esos campos lo invalida. Las transiciones de estado
renuevan únicamente sellos que ya eran válidos: una fila falsificada no se legitima
al marcarla como rechazada. Cambiar la clave de aplicación invalida los sellos anteriores.
Texto persistido con codificación inválida se rechaza sin propagar una excepción de
verificación a la página ni habilitar acciones.

No existe selección manual de PID ni ruta administrativa de la tabla general.
Las acciones usan model binding de `ManagedProcess` por ID interno y formularios
POST con CSRF. Los controladores solo coordinan servicios, sin comandos ni lecturas
arbitrarias de `/proc`. Se permiten cuatro señales y nice entero -20..19, sin `sudo`.

No se usan `shell_exec`, `exec`, `system` ni `passthru` en las acciones administrativas.
Los comandos son fijos; renice recibe únicamente números validados en un array.
La shell del launcher solo interpreta el script interno fijo, sin datos del usuario.
Los mensajes no muestran excepciones, stderr ni contenido bruto de `/proc`.
Datos del sistema, parámetros y mensajes se presentan mediante escape Blade `{{ ... }}`.

La revalidación utiliza fotografías de `/proc`; la comprobación y la operación por PID
son llamadas separadas, no una operación atómica. La integración debe considerar esa
ventana si su modelo de amenaza incluye reutilización deliberada de PID entre ambas.
La firma protege contra manipulación de registros, no contra compromiso de la clave
de aplicación o del usuario del sistema operativo.

## Señales

La whitelist web acepta exclusivamente estos nombres:

| Campo `signal` | Señal | Efecto esperado |
| --- | --- | --- |
| `term` | SIGTERM | Solicitar terminación. |
| `kill` | SIGKILL | Finalizar el proceso. |
| `stop` | SIGSTOP | Detener el proceso conservando su identidad. |
| `cont` | SIGCONT | Continuar un proceso válido. |

`ProcessSignalSender` utiliza `posix_kill()`; si no está disponible, falla de forma
controlada sin usar una shell alternativa. No se aceptan números o señales arbitrarias.
STOP actualiza a `stopped` y CONT a `running` cuando el envío tiene éxito.

TERM/KILL comprueban finalización con un máximo de diez intentos y nueve pausas de
20 ms; el lector de identidad tiene también reintentos limitados. Un zombie de la
misma identidad se considera finalizado aunque siga pendiente de recolección.
Si no se confirma la finalización, se informa que la señal fue enviada y se conserva
el estado anterior. No hay polling indefinido ni nuevos envíos al detectar PID reutilizado.

## Renice

`nice` debe ser un entero entre -20 y 19. Se rechazan floats, texto adicional, arrays,
notación científica y valores fuera de rango, independientemente del input HTML.

El runner usa `proc_open()` con
`['/usr/bin/renice', '-n', (string) $nice, '-p', (string) $pid]`, sin shell, con
`LC_ALL=C`. Captura stdout/stderr en temporales separados y el código de salida;
no expone esas salidas al navegador.

Linux puede permitir aumentar nice y rechazar reducirlo cuando PHP carece de permisos
suficientes. Se responde con un mensaje genérico, sin `sudo` ni elevación de privilegios.
Exit code 0 no basta: después se revalida la identidad y se lee el nice real del campo
19 de `stat`. El parsing respeta nombres `comm` con espacios y paréntesis. Solo se
informa éxito si coincide el valor solicitado. La prioridad no se guarda en una columna
nueva y un cambio no modifica el estado registrado `running`/`stopped`.

## Estados ManagedProcess

| Estado | Significado |
| --- | --- |
| `running` | Registrado al lanzar o continuar correctamente. |
| `stopped` | SIGSTOP enviada correctamente. |
| `terminated` | Finalización confirmada después de SIGTERM. |
| `killed` | Finalización confirmada después de SIGKILL. |
| `missing` | El proceso ya no existe o se confirma que ya finalizó antes de actuar. |
| `identity_mismatch` | Registro manipulado, contexto UID distinto o identidad real no coincidente/verificable. |

Son estados registrados, no una actualización automática permanente del sistema.
`terminated`, `killed`, `missing` e `identity_mismatch` no permiten nuevas acciones.

## Rutas principales

Definidas en `routes/web.php`, bajo el grupo web habitual de Laravel:

| Método | Ruta | Nombre | Acción |
| --- | --- | --- | --- |
| GET/HEAD | `/procesos` | `processes.index` | `ProcessController@index`: lectura y presentación. |
| POST | `/procesos/prueba` | `processes.test.store` | `TestProcessController@store`: lanzamiento fijo. |
| POST | `/procesos/prueba/{managedProcess}/signal` | `processes.test.signal` | `TestProcessController@signal`. |
| POST | `/procesos/prueba/{managedProcess}/priority` | `processes.test.priority` | `TestProcessController@priority`. |

`{managedProcess}` es el ID interno de la fila, no un PID. Las dos rutas que lo
incluyen exigen un parámetro numérico. GET no puede ejecutar las acciones POST.

## Ejecución local

Requisitos: Linux con `/proc`, PHP compatible con Laravel 12 (mínimo 8.2), Composer,
SQLite/PDO SQLite y las extensiones necesarias para Laravel, incluida mbstring.
`proc_open` debe estar disponible para lectura/lanzamiento/renice y POSIX para señales.
Se requieren los binarios fijos `/usr/bin/ps`, `/usr/bin/sleep`, `/usr/bin/nohup`,
`/usr/bin/renice` y `/bin/sh`. M1 usa HTML/CSS y no requiere Node.js ni nuevos paquetes.

Desde la raíz del proyecto, en una instalación nueva:

```bash
composer install
test -f .env || cp .env.example .env
php artisan key:generate
test -f database/database.sqlite || touch database/database.sqlite
php artisan migrate
php artisan serve
```

Preparar `.env` para SQLite (`DB_CONNECTION=sqlite`) y una ubicación de base adecuada
a la configuración local. Si `.env` y la clave ya existen, conservarlos y omitir
`key:generate`; no regenerar la clave para resolver errores de firma. Los archivos
de base y almacenamiento deben ser accesibles al usuario de PHP.

Abrir `/procesos` en el servidor local. Con `php artisan serve`, los procesos de
prueba pertenecen al usuario local; con Apache, al usuario efectivo del worker PHP
(habitualmente www-data, sin asumir un UID numérico).

`.env`, `database/database.sqlite` y sus archivos auxiliares **no deben subirse al
repositorio**. Las reglas de exclusión existentes cubren `.env` y los SQLite. No se
debe borrar o recrear una base local existente para validar M1; aplicar migraciones
pendientes con `php artisan migrate`, sin `migrate:fresh`.

## Pruebas

```bash
php artisan test
php vendor/bin/pint --test
/bin/sh -n scripts/launch-test-process.sh
php artisan route:list --path=procesos -v
```

`phpunit.xml` utiliza SQLite `:memory:` y sesiones/caché aisladas. Las Feature con
`RefreshDatabase` aplican las migraciones en orden en esa base de pruebas, sin
recrear la base de desarrollo. La aplicación necesita una clave válida para las
sesiones web y el sello de procedencia del entorno de pruebas.

La cobertura se organiza así:

| Grupo | Comprobaciones principales |
| --- | --- |
| `ProcessServiceTest` | Lectura Linux real, campos/tipos, parsing, comandos con espacios, filas inválidas y árbol defensivo/profundo. |
| `ProcessTest` | Tabla, nueve columnas, búsqueda, orden numérico/textual, estados globales, árbol global y escape HTML. |
| `TestProcessTest` | Registro transaccional, PID inválido, fallos de identidad/launcher/base, POST/CSRF y formulario fijo. |
| `TestProcessIdentityReaderTest` | Campos UID/starttime/nice, `comm` complejo, ejecutable/argumentos esperados y cambios de identidad. |
| `ProcessSignalServiceTest`, `ProcessSignalTest` | Whitelist, identidad, estados, finalización acotada, rutas por ID y mecanismos simulados. |
| `ProcessPriorityServiceTest`, `ProcessPriorityTest` | Rango estricto, permisos denegados, comando en array y verificación posterior. |
| `ManagedProcessIdentityGuardTest`, `ManagedProcessSecurityTest` | Procedencia HMAC, filas manuales/copias/manipulaciones, texto persistido corrupto, contexto UID, política compartida y controles solo en la sección administrable. |

Las Feature simulan `ProcessService`. El launcher, las señales y renice se simulan
cuando una prueba podría modificar el sistema; la suite normal no crea sleeps,
envía señales reales ni cambia prioridades. La prueba Unit de listado es de solo
lectura y requiere un entorno Linux con `ps` disponible.

## Pendientes de integración global

- Incorporar autenticación y autorización del módulo responsable de integración
  antes de exponer acciones administrativas a usuarios no confiables. CSRF y
  procedencia del proceso no autentican a la persona que hace la petición.
- Integrar bitácora global: los servicios de señales y prioridad ya devuelven ID,
  PID, acción, resultado y fecha, pero no implementan una auditoría persistente completa.
- Definir despliegue, acceso de PHP a `/proc`, permisos de archivos y conservación
  de la clave de aplicación. No se añaden roles, capacidades Linux ni privilegios en M1.
- Evaluar la ventana entre verificación de identidad y operación por PID conforme
  al modelo de amenaza de la integración.

La prueba real opcional debe realizarse únicamente sobre un sleep creado por el
launcher de SysMonitor, verificando su identidad y finalizándolo mediante las acciones
seguras del módulo. La validación automatizada no sustituye esa comprobación bajo el
usuario PHP del entorno final.
