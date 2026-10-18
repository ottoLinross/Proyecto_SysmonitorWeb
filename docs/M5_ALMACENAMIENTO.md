# M5 — Almacenamiento: documentación oficial y cierre

## 1. Objetivo y alcance

M5 permite consultar el almacenamiento y parte del contexto de usuarios del servidor Debian desde una página Laravel 12. Reúne dispositivos de bloques, ocupación de sistemas de archivos, montajes activos y configurados, cuentas locales, sesiones y un explorador de metadatos del proyecto.

Es un módulo de consulta: no administra particiones, no monta ni desmonta sistemas de archivos, no cambia permisos ni usuarios y no abre contenidos del explorador. No implementa subida, edición, borrado, descarga, terminal ni exploración recursiva del sistema de archivos. No consulta `/etc/shadow`. El campo de contraseña de `/etc/passwd` se ignora.

Las consultas reflejan el entorno donde se ejecuta PHP, que puede ser un contenedor y diferir del host físico. Los ejemplos de esta guía proceden del código y de fixtures automatizados; no son un inventario real del equipo de despliegue.

## 2. Arquitectura y archivos de referencia

El flujo es: petición GET → `StorageController@index` → servicios independientes → normalización de datos → `StorageSummaryService` → vistas Blade. No se añade JavaScript, AJAX ni persistencia propia de M5. La configuración general de sesiones y middleware de Laravel sigue siendo aplicable.

| Componente | Archivo, relativo a la raíz del proyecto | Responsabilidad |
| --- | --- | --- |
| Ruta | `routes/web.php` | Registrar `/almacenamiento` y el nombre `storage.index` |
| Controlador | `app/Http/Controllers/StorageController.php` | Consultar cada fuente una vez y entregar los resultados a Blade |
| Dispositivos | `app/Services/Storage/StorageOverviewService.php` | Validar JSON de lsblk, jerarquía y PTTYPE; definir secciones |
| Ocupación | `app/Services/Storage/FilesystemUsageService.php` | Interpretar df, validar porcentajes |
| Montajes | `app/Services/Storage/MountService.php` | Separar findmnt de fstab |
| Usuarios | `app/Services/Storage/SystemUsersService.php` | Validar UID/GID y clasificar cuentas |
| Sesiones | `app/Services/Storage/ActiveSessionsService.php` | Interpretar who |
| Explorador | `app/Services/Storage/FileExplorerService.php` | Resolver rutas autorizadas y listar metadatos |
| Resumen | `app/Services/Storage/StorageSummaryService.php` | Contar sin sumar capacidades ni confundir fallos con cero |
| Estados | `app/Services/Storage/StorageSourceStatus.php` | Traducir fallos de comandos a estados públicos |
| Runners | `app/Services/Storage/{LsblkRunner,DfRunner,FindmntRunner,WhoRunner}.php` | Crear procesos con argv fijo |
| Control de salida | `app/Services/Storage/StorageCommandOutput.php` | Limitar stdout/stderr y detener procesos |
| Lectores | `app/Services/Storage/{FstabReader,SystemUsersReader,StorageFileRead}.php` | Lectura binaria acotada de archivos fijos |
| Interfaz | `resources/views/storage/index.blade.php` | Resumen, estados y paneles adaptables |
| Parciales | `resources/views/storage/partials/` | `device`, `usage`, `mounts`, `users`, `sessions`, `explorer` y `summary` |

El controlador construye `$sources` con las claves `devices`, `usage`, `mounts`, `configured`, `users`, `sessions` y `explorer`. Después llama a `$summary->summarize($sources)`. Los servicios devuelven sus propios resultados de error, de modo que el fallo controlado de uno no interrumpe las demás fuentes.

## 3. Ruta HTTP y actualización

La definición existente es:

```php
Route::get('/almacenamiento', [StorageController::class, 'index'])->name('storage.index');
```

Laravel registra GET y HEAD. La ruta pertenece al grupo `web`; no declara middleware de autenticación ni autorización específico. No debe confundirse el confinamiento del explorador con control de acceso a la página. El entorno de despliegue debe decidir quién puede consultar nombres de archivos, cuentas, rutas de montaje y metadatos del servidor.

Ejemplos:

```text
GET /almacenamiento
GET /almacenamiento?path=docs
GET /almacenamiento?path=
```

`path` es el único parámetro usado para seleccionar un directorio. No selecciona comandos, binarios, opciones ni archivos de configuración. Un array en `path` se rechaza. Parámetros como `command`, `args`, `source`, `root` o `action` no conceden operaciones adicionales.

«Actualizar información» es un enlace GET: vuelve a consultar todas las fuentes y conserva el directorio actual canónico relativo si es válido; ante error vuelve a la raíz. No existe refresco automático ni historial de lecturas.

## 4. Command Runner y allowlist

La allowlist está expresada en los cuatro runners concretos; no existe un endpoint de ejecución general ni una allowlist editable por HTTP. Cada `run()` carece de parámetros y crea Symfony Process con un array de argumentos fijo.

| Runner | Binario y argumentos exactos | Entorno explícito | Timeout |
| --- | --- | --- | --- |
| `LsblkRunner` | `/usr/bin/lsblk -J -b -o NAME,PATH,TYPE,SIZE,FSTYPE,MOUNTPOINTS,PTTYPE,MODEL` | Sin sobrescritura explícita | 5 s |
| `DfRunner` | `/usr/bin/df -hP` | `LC_ALL=C` | 5 s |
| `FindmntRunner` | `/usr/bin/findmnt --json --list --output SOURCE,TARGET,FSTYPE` | Sin sobrescritura explícita | 5 s |
| `WhoRunner` | `/usr/bin/who` | `LC_ALL=C` | 5 s |

Por ejemplo, `DfRunner::createProcess()` contiene:

```php
return new Process(['/usr/bin/df', '-hP'], null, ['LC_ALL' => 'C'], null, 5);
```

No hay concatenación de entrada HTTP en comandos ni uso de `Process::fromShellCommandline()`. La revisión de M5 no encuentra llamadas directas a `shell_exec`, `exec`, `system`, `passthru` o `proc_open`. Symfony Process puede utilizar `proc_open` internamente; eso no equivale a ejecutar una orden arbitraria recibida del usuario.

`StorageCommandOutput::run()` usa `mustRun()`: un código distinto de cero no devuelve éxito parcial. Cuenta conjuntamente stdout y stderr con `MAX_BYTES = 1048576` (1 MiB). Al superar el límite lanza un fallo, detiene el proceso aún activo en `finally` y limpia buffers. También los limpia al finalizar normalmente. Solo stdout se devuelve al parser; stderr no forma parte de los resultados públicos. Los servicios ocultan las excepciones de ejecución.

El límite se comprueba al recibir los bloques del callback; no es una cuota estricta de toda la memoria interna de Symfony ni del proceso hijo. Las cuatro consultas se realizan secuencialmente, por lo que los timeouts pueden acumularse; 5 s no es un timeout global de la página.

## 5. Dispositivos: lsblk, particiones y PTTYPE

`StorageOverviewService::devices()` decodifica JSON con profundidad máxima 64 y `JSON_THROW_ON_ERROR`. Exige un objeto con `blockdevices` como array; valida los campos y normaliza `children` recursivamente. Esta recursión procesa el árbol JSON de dispositivos, no recorre directorios del explorador.

Se muestran NAME, PATH, TYPE, SIZE en bytes, FSTYPE, MODEL, MOUNTPOINTS y tabla de particiones. Los valores nulos se muestran como `—` cuando corresponde. La vista conserva la jerarquía padre/hijo y no elimina dispositivos de tipo `loop` u otros tipos reportados por lsblk.

En el resumen, únicamente TYPE `disk` cuenta como disco y `part` como partición. Los tipos restantes siguen visibles en el detalle. Se deduplican discos y particiones por PATH, con NAME como alternativa; los registros sin ambas identidades reciben una clave de conteo provisional. Esto no es detección por UUID o número de serie.

PTTYPE se traduce así:

| Valor | Presentación |
| --- | --- |
| `gpt` | `GPT` |
| `dos` | `MBR (dos)` |
| Ausente, nulo o vacío | `No reportado` |
| Otro texto válido | Se conserva escapado |

GPT y MBR son esquemas de tabla de particiones; no son tipos de sistema de archivos como ext4. M5 presenta la información informada por lsblk, sin inspeccionar ni modificar sectores ni validar integridad de particiones.

## 6. Sistemas de archivos: df -hP y barras

El comando real es `df -hP`: `-h` solicita capacidades legibles y `-P` el formato de salida portable. El parser espera la cabecera inglesa `Filesystem Size Used Avail Use% Mounted on`, con locale C.

Cada fila normalizada contiene:

```php
['filesystem', 'size', 'used', 'available', 'use_percent', 'mountpoint']
```

Las capacidades se conservan como texto reportado por df; no se convierten a bytes para crear un total global. El parser permite espacios en el nombre de sistema de archivos y punto de montaje, y valida las columnas numéricas con sus sufijos. Una fila inválida hace fallar la fuente completa; no se publica una tabla parcial como si estuviera completa.

Fixture de `StorageDfTest`:

```text
Filesystem Size Used Avail Use% Mounted on
/dev/example 10G 1G 9G 10% /
```

La tabla muestra tamaño `10G`, usado `1G`, disponible `9G`, porcentaje `10%` y destino `/`. El porcentaje se valida como entero entre 0 y 100 y se normaliza antes de usarse. La barra de `usage.blade.php` convierte de nuevo el valor a entero:

```blade
<div class="usage-bar-fill" style="width: {{ (int) $filesystem['use_percent'] }}%" aria-hidden="true"></div>
```

La barra externa usa `role="progressbar"`, etiqueta descriptiva, `aria-valuenow`, mínimo 0 y máximo 100; el porcentaje también permanece como texto. No se usa color como única información. Los valores superiores a 100 se rechazan como error de lectura, aun si una situación particular del sistema pudiera producirlos; no se recortan ni se inventa un porcentaje.

La cabecera sin filas significa consulta disponible sin registros. Una salida vacía o ilegible no equivale a ese caso.

## 7. Montajes activos y configurados

### findmnt

`MountService::active()` valida JSON con profundidad máxima 64 y exige `source`, `target` y `fstype` como cadenas no vacías en cada registro. La vista muestra origen, destino y tipo. Una salida inválida o un fallo del proceso afecta solo a esta fuente.

El resumen cuenta destinos `target` distintos. Un bind mount puede sumar un destino sin aportar nueva capacidad: el rótulo «Sistemas de archivos montados» debe interpretarse con la aclaración visible de destinos activos, no como cantidad de sistemas de archivos físicos independientes.

### /etc/fstab

`FstabReader::read()` lee únicamente `/etc/fstab`. `MountService::configured()` elimina comentarios desde `#`, omite líneas vacías y malformadas, admite entre cuatro y seis campos y exige valores numéricos para dump/pass cuando están presentes. Muestra origen, destino, tipo y opciones; no ejecuta el montaje ni verifica que la configuración esté actualmente activa.

Se decodifican las secuencias `\040`, `\011`, `\012` y `\134`. Ejemplo de fixture:

```text
server:/share /mnt/My\040disk nfs ro
```

Se presenta el destino `/mnt/My disk`. Un destino `none` puede corresponder a swap. La existencia de la entrada no demuestra que el dispositivo esté disponible o montado. El parser es deliberadamente acotado y no implementa una auditoría completa de la semántica de fstab.

## 8. Usuarios: passwd, UID y UID_MIN

`SystemUsersReader` tiene métodos sin argumentos para `/etc/passwd` y `/etc/login.defs`. `StorageFileRead` abre en modo `rb`, lee como máximo 1 MiB más un byte de detección, rechaza exceso o lectura incompleta y cierra el recurso. Un archivo vacío devuelve `''`; no se crea ni modifica el archivo consultado. Los fallos se traducen en resultados controlados del servicio.

El parser de passwd exige siete campos separados por `:`, nombre no vacío y UID/GID enteros no negativos dentro de `PHP_INT_MAX`. Ignora contraseña y GECOS. Presenta nombre, UID, GID, directorio personal, shell y clasificación. No presenta el archivo bruto.

UID es el identificador numérico de la cuenta, no un indicador infalible de persona física. `UID_MIN` se toma de una definición positiva válida en login.defs; si no puede leerse o no hay una válida, se usa 1000 y la interfaz informa el fallback. Si hay varias definiciones válidas, el recorrido conserva la última válida.

La clasificación existente aplica este orden:

1. Nombre `root` o UID 0 → `root`.
2. Nombre `nobody` o UID 65534 → `Usuario del sistema`.
3. UID mayor o igual a UID_MIN → `Usuario humano`.
4. Resto → `Usuario del sistema`.

Las cuentas se ordenan por UID y nombre, con GID, home y shell como desempates. «Usuarios del sistema» en el resumen cuenta todas las cuentas válidas de passwd, incluidas las clasificadas como humanas y root; no cuenta únicamente la categoría homónima. No incluye necesariamente cuentas remotas de NSS/LDAP que no estén en ese archivo.

Los parsers de passwd, fstab y who omiten filas con UTF-8 inválido, NUL o determinados controles. Mantienen texto Unicode válido. Una fuente textual formada solo por filas descartadas puede aparecer disponible con cero registros; no existe un contador público de filas descartadas. El fallback de login.defs no convierte por sí solo la fuente passwd en fallida.

## 9. Sesiones activas: who

`ActiveSessionsService::sessions()` interpreta líneas con usuario, terminal, fecha `YYYY-MM-DD`, hora `HH:MM` y origen opcional entre paréntesis. Verifica fecha y hora; el origen vacío se representa como no reportado. Ejemplo equivalente a los fixtures:

```text
álvaro pts/2 2026-10-18 10:00 (local)
```

Cada fila válida cuenta como sesión, no como usuario único. Una cuenta puede tener varias sesiones. Una salida vacía de who es válida y produce «No hay sesiones activas reportadas.». Un fallo del comando produce un estado de error, sin convertirlo en cero sesiones.

La fuente refleja las sesiones registradas por who, no todos los procesos, conexiones de red ni todas las formas de autenticación del sistema.

## 10. Explorador de solo lectura

### Raíz, navegación y validación

La raíz por defecto es `base_path()`. El constructor admite una raíz alternativa para pruebas; el controlador no acepta cambiarla mediante HTTP.

El flujo de `browse()` es:

1. Exigir `path` como string y validar que sea relativa.
2. Resolver la raíz con `realpath()`.
3. Resolver el directorio solicitado con `realpath($root.DIRECTORY_SEPARATOR.$path)`.
4. Exigir raíz exacta o prefijo de raíz seguido de separador, directorio y permiso de lectura.
5. Ejecutar `scandir()` solo sobre ese directorio, omitir `.` y `..`, obtener metadatos y ordenar con `strcmp()`.
6. Devolver directorio actual y padre como rutas relativas. En la raíz, `parent` es `null` y no aparece enlace a un padre externo.

La comparación con separador impide confundir `/root-neighbor` con un descendiente de `/root`. Se rechazan NUL, barras invertidas, `/` inicial, prefijos de unidad y componentes `..`. También se bloquean patrones porcentuales `%2e`, `%2f`, `%5c`, `%00` y `%25`, sin distinguir mayúsculas. No se intenta decodificar repetidamente la ruta. Laravel ya puede haber decodificado una capa al recibir la petición.

Casos cubiertos por las pruebas:

```text
../root-neighbor
../../
/etc
C:\Windows
%2e%2e/root-neighbor
%252e%252e%252froot-neighbor
```

Son rechazados o resultan inaccesibles sin listar datos externos. El bloqueo de patrones es conservador: algunos nombres literales que contengan esas secuencias o barras invertidas no son navegables, aunque el sistema permita crearlos.

### Enlaces simbólicos y permisos de acceso

Se resuelve el destino para decidir si un enlace a directorio puede navegarse. Los enlaces internos pueden abrir el directorio autorizado; el directorio actual se presenta como ruta canónica relativa. Los enlaces externos, rotos o cíclicos se muestran como enlaces sin navegación. No se muestra el texto de su destino. Un enlace a la raíz produce raíz actual sin padre externo.

`lstat()` obtiene metadatos del propio enlace, sin seguir su destino para consultar propietario, tamaño o permisos. Los errores de metadatos muestran `—` y no impiden listar las demás entradas. Un fallo de acceso al directorio produce «No se pudo acceder al directorio solicitado.». Los archivos regulares no tienen enlace para abrirse; ni siquiera un archivo legible se devuelve como contenido HTTP.

Los permisos efectivos son los del proceso PHP. Las pruebas de chmod pueden omitirse si la cuenta ejecutora tiene privilegios que eludan los permisos Unix. La implementación no es una operación atómica de confinamiento: un actor con escritura concurrente en el árbol puede cambiar rutas entre comprobación y acceso. No implementa openat/fstatat, sandbox del kernel ni una garantía frente a todas las carreras TOCTOU.

### Metadatos Unix

La tabla presenta nombre, tipo, permisos Unix, propietario y tamaño. Ejemplos verificados por `StorageExplorerTest`:

| Modo o caso | Permisos o presentación |
| --- | --- |
| Archivo 0644 | `-rw-r--r--` |
| Directorio 0755 | `drwxr-xr-x` |
| Enlace 0777 | `lrwxrwxrwx` |
| Archivo 07755 | `-rwsr-sr-t` |
| Archivo 07644 | `-rwSr-Sr-T` |

El primer carácter de permisos identifica archivo, directorio, enlace, FIFO, dispositivo de caracteres/bloques o socket; un tipo desconocido usa `?`. La columna descriptiva Tipo distingue enlace, directorio y, para las demás entradas, Archivo: no ofrece etiquetas específicas para todos esos tipos especiales.

Los tres grupos de `rwx` corresponden a propietario, grupo y otros. `r` permite lectura, `w` escritura y `x` ejecución o búsqueda en directorios. Los bits setuid/setgid se representan como `s` si coincide ejecución y `S` si no; sticky usa `t` o `T`. M5 solo los representa, nunca los cambia. Esta cadena no incluye ACL extendidas, atributos especiales ni políticas SELinux/AppArmor.

El propietario parte de UID numérico. Se intenta `posix_getpwuid()` solo si existe; se usa el nombre únicamente cuando la respuesta contiene un nombre string no vacío. Si no hay función, usuario válido o hay excepción, permanece el UID. El propietario procede de la resolución de cuentas del entorno PHP, que no tiene por qué coincidir exactamente con el listado local de passwd.

Solo los archivos regulares tienen tamaño legible:

| Bytes de fixture | Salida |
| --- | --- |
| 0 | `0 bytes` |
| 9 | `9 bytes` |
| 1023 | `1023 bytes` |
| 1024 | `1.00 KiB` |
| 1536 | `1.50 KiB` |
| 1048576 | `1.00 MiB` |
| 1572864 | `1.50 MiB` |

1 KiB = 1024 bytes y 1 MiB = 1048576 bytes. Los tamaños superiores siguen expresándose en MiB, no en GiB. Directorios, enlaces y otros tipos muestran `—`: no se calcula uso recursivo ni espacio físico ocupado por bloques o archivos dispersos.

## 11. Resumen, errores y presentación

El resumen utiliza las mismas lecturas del detalle, sin ejecutar de nuevo los comandos. Muestra discos, particiones, destinos montados, cuentas y sesiones. Cero es un resultado válido; un contador cuya fuente falla es `null` y Blade muestra `—`.

No se suman discos, particiones ni capacidades de montajes. Eso contaría varias veces el mismo almacenamiento; además df -hP devuelve cantidades redondeadas. La tarjeta de capacidad global dice «No disponible como total global fiable.» y remite al detalle por sistema de archivos. No se ha implementado cálculo exacto global.

| Estado | Condición real |
| --- | --- |
| `Disponible` | Consulta completada; puede no haber registros |
| `No disponible` | Fallo de arranque de proceso o salida 126/127; también fallback de estado para explorador inaccesible |
| `Error de lectura` | Otros fallos de comandos, timeout, exceso, JSON o formato inválido; fallo de lectura de fstab/passwd |

`StorageSourceStatus` clasifica los procesos por sus excepciones y códigos, no por analizar mensajes de stderr. Los errores de archivos de configuración no tienen una clasificación diferenciada de archivo ausente: aparecen como Error de lectura. El explorador mantiene su contrato con `available` y mensaje genérico, sin campo `status`; el resumen deriva su estado.

Una caída de lsblk no oculta df, montajes, usuarios, sesiones o explorador. Los bloques de mensaje no imprimen excepciones, stack traces ni diagnósticos internos. Esto se refiere a los errores controlados de M5; la configuración global de depuración de Laravel es responsabilidad del despliegue.

La interfaz usa Blade escapado `{{ ... }}`, encabezados de tabla `scope="col"`, captions, secciones etiquetadas, estados textuales y rejillas CSS adaptables. Las tablas tienen desplazamiento horizontal; la de ocupación añade una región enfocable. Nombres HTML se muestran como texto; no se insertan como HTML ejecutable. Nombres, home, shell, dispositivos y destinos de montaje son datos presentados deliberadamente, no deben confundirse con mensajes internos ocultos.

## 12. Matriz de verificación funcional y de seguridad

| Área de los Prompts 1–11 | Evidencia existente |
| --- | --- |
| Ruta y estructura M5 | `StorageTest`: controlador dedicado, GET/HEAD y vista sin procesos reales en fixtures |
| lsblk y jerarquía | `StorageLsblkTest`: tipos, nulos, hijos, JSON inválido, argumentos fijos y escape |
| df y ocupación | `StorageDfTest`, `StorageOccupancyTest`: formatos, porcentajes, barras accesibles y ausencia de estilos inyectados |
| PTTYPE y montajes | `StorageMountTest`: GPT/MBR, findmnt, fstab, escapes, fallos independientes |
| Cuentas y clasificación | `StorageUsersTest`: UID_MIN, overflow de UID/GID, root/nobody, orden, fallback y XSS |
| Sesiones | `StorageSessionsTest`: vacío válido, fechas, orígenes, filas inválidas y errores |
| Explorador | `StorageExplorerTest`: traversal, codificación, raíz, enlaces externos/rotos/cíclicos, permisos y Unicode |
| Metadatos | `StorageExplorerTest`: rwx, bits especiales, propietario/fallback, bytes/KiB/MiB y error por entrada |
| Resumen y estados | `StorageSummaryTest`: conteos deduplicados, cero discos/sesiones, fallos por fuente y entrada HTTP no ejecutable |
| Límites de procesos | `StorageCommandOutputTest`: stdout/stderr, exceso individual y combinado, timeout, código no cero y límite exacto |
| Límites y formato de archivos | `StorageFileReadTest`, `StorageSourceHardeningTest`: vacío de 0 bytes, lectura sin modificación, ausente/inaccesible, exceso, Unicode, NUL y JSON profundo |

Las pruebas del explorador verifican 405 para POST, PUT, PATCH y DELETE, rechazo de uploads, ausencia de contenido descargable y conservación de archivos. Las pruebas de procesos emplean PHP con scripts fijos como productor de salida; esto es infraestructura de pruebas, no una ampliación de la allowlist de producción.

`php artisan test --filter=Storage` selecciona nombres de clases/métodos que contienen Storage; también puede seleccionar pruebas de otros módulos cuyo nombre de método contenga esa palabra. La suite completa comprueba regresiones más amplias, sin demostrar por sí sola ausencia absoluta de vulnerabilidades.

## 13. Validación reproducible

Desde la raíz del proyecto, con PHP y las dependencias ya disponibles:

```bash
find app/Services/Storage -name '*.php' -exec php -l {} \;
find tests -name '*Storage*.php' -exec php -l {} \;
php -l app/Http/Controllers/StorageController.php
php -l routes/web.php
php artisan route:list --path=almacenamiento -v
php artisan test --filter=Storage
php vendor/bin/pint --test
php artisan test
```

No se necesita ejecutar npm para la interfaz Blade de M5. Estos comandos no instalan dependencias, no crean commits ni hacen push. Pint se ejecuta en comprobación, sin `--dirty`. Los tests usan archivos temporales y mocks; algunos tests de procesos lanzan subprocesos locales controlados. Las duraciones dependen del entorno. El registro concreto de cierre se consigna al final de este documento.

## 14. Ejemplos de funcionamiento y revisión manual

1. Abrir `/almacenamiento`: revisar resumen, siete estados de fuente y paneles. Si who devuelve vacío, esperar cero sesiones y estado Disponible.
2. Abrir `/almacenamiento?path=docs`: ver nombres y metadatos del directorio, sin contenido de documentos. «Directorio padre» vuelve a la raíz autorizada.
3. Abrir `/almacenamiento?path=../../`: esperar mensaje genérico, enlace para volver a la raíz y funcionamiento independiente de las demás secciones.
4. Con los fixtures de `StorageSummaryTest`, dos discos con la misma partición repetida y un dispositivo loop producen dos discos y una partición. Dos destinos distintos del mismo origen producen dos montajes, sin sumar su capacidad.
5. En las pruebas de proceso, stdout y stderr emiten 576 KiB cada uno: cada flujo está bajo 1 MiB, pero el combinado de 1152 KiB se rechaza y el proceso termina.
6. En el fixture de archivo vacío, la lectura devuelve `''`, el archivo sigue existiendo y su tamaño permanece en cero.

Los fixtures se crean en temporales durante los tests; no es necesario alterar dispositivos, permisos del proyecto ni archivos de `/etc` para validar estos casos. La revisión visual real en distintos tamaños de pantalla y la comprobación de datos del servidor siguen siendo pasos manuales; el cierre automatizado no los sustituye.

## 15. Preguntas para la defensa oral

**¿Por qué lsblk y df muestran capacidades distintas?** lsblk describe dispositivos de bloques y su jerarquía; df reporta sistemas de archivos montados, con capacidades legibles redondeadas. No representan exactamente la misma entidad.

**¿Por qué no sumar todas las capacidades?** Un disco contiene particiones y un sistema de archivos puede tener varios destinos. Sumarlos duplicaría datos. M5 no dispone de una agregación global fiable y lo comunica.

**¿Qué diferencia hay entre GPT, MBR y ext4?** GPT/MBR describen la tabla de particiones; ext4 es un sistema de archivos. M5 muestra PTTYPE y FSTYPE en campos distintos.

**¿Qué distingue findmnt de fstab?** findmnt informa montajes activos. fstab contiene configuración, incluyendo entradas que podrían no estar activas.

**¿Se ejecuta lo que llega en `command` o `args`?** No. Los runners no reciben argumentos HTTP y contienen argv fijo. El controlador solo usa `path` para el explorador.

**¿Por qué realpath y una validación de prefijo?** realpath resuelve la ruta y los enlaces; después se comprueba la raíz exacta o raíz seguida de separador para excluir destinos externos y colisiones de prefijo.

**¿lstat y realpath cumplen la misma función?** No. realpath decide el confinamiento y la navegación; lstat obtiene metadatos del propio enlace, incluso si su destino está roto.

**¿Por qué un enlace roto puede mostrar permisos?** Sus metadatos existen aunque su destino no exista. El enlace aparece sin navegación y sin revelar el destino.

**¿Qué significan s/S y t/T?** Son setuid/setgid y sticky, distinguiendo si también está activo el bit de ejecución correspondiente. M5 los muestra sin cambiarlos.

**¿UID alto implica necesariamente una persona?** No. Es una clasificación por UID_MIN, con excepciones root/nobody; no es una verificación de identidad ni del uso real de la cuenta.

**¿Una fuente vacía está fallando?** No necesariamente. who sin sesiones o lsblk con un array vacío son consultas disponibles. Un fallo de lectura se presenta por separado.

**¿Qué protege el límite de 1 MiB?** Reduce el riesgo de procesar salidas o archivos de configuración excesivos. En procesos incluye ambos flujos, no 1 MiB para cada uno. No es una cuota de todo el sistema ni evita todas las condiciones de agotamiento.

**¿La página está autenticada por M5?** No hay middleware de autenticación específico en la ruta actual. El acceso debe resolverse en el despliegue o en trabajo futuro autorizado.

**¿Qué datos sensibles evita mostrar el explorador?** No devuelve contenidos de archivos, incluidos `.env` u otros archivos del proyecto. Sí muestra sus nombres y metadatos si son visibles en el directorio; no existe un filtro que oculte todos los nombres sensibles.

**¿Se modificaron los módulos anteriores en el cierre?** El alcance de este prompt es documentación y validación: solo se crea esta guía y se añade su enlace al README. No se cambian implementaciones ni pruebas de M1–M4.

## 16. Límites conocidos y consideraciones para M6

- No hay autenticación, autorización ni rate limiting específicos de M5. No se incorporan en este cierre; deben definirse antes de ampliar acceso o capacidades.
- Los permisos son los de PHP; no se utiliza sudo ni elevación. La extensión POSIX es opcional para resolver propietarios.
- Rutas `/usr/bin/*` y formatos GNU están fijados para el entorno Debian previsto. Otras plataformas/versiones pueden producir una fuente no disponible o un error de lectura.
- Las lecturas son instantáneas independientes, no una transacción coherente del estado del sistema. Pueden cambiar entre comandos.
- Hay límites de 1 MiB para comandos y archivos de configuración, pero el explorador usa scandir sin paginación ni límite explícito de entradas. Un directorio enorme puede consumir recursos; el orden es binario con strcmp, no una ordenación lingüística.
- Los timeouts no cubren globalmente toda la petición, scandir ni la resolución de propietarios. No hay caché, monitorización continua, historial ni alertas.
- realpath y las verificaciones posteriores no eliminan todas las carreras con escritores concurrentes. El explorador tampoco implementa ocultación de nombres sensibles ni validación UTF-8 específica para nombres arbitrarios del sistema de archivos; los nombres Unicode válidos están probados y Blade escapa la presentación.
- Los parsers tolerantes pueden omitir filas textuales malformadas sin un estado de degradación. df rechaza toda la fuente ante una fila inválida o un porcentaje fuera de 0–100.
- No se calculan capacidad global, tamaños recursivos, espacio físico por archivo, ACL, cuotas, salud SMART ni integridad de tablas de particiones.
- El cierre no inspecciona historial Git ni certifica un despliegue remoto. Las fuentes reales de verificación son los archivos de código, Blade, rutas y tests de este checkout, más las ejecuciones locales registradas abajo. No se requiere documentación web para describir este contrato local.

Para M6, definir primero su alcance oficial y sus permisos. Si reutiliza consultas M5, conservar separación de fuentes, argv fijo, límites y contratos de error. Cualquier acción de escritura, cambio de raíz, agregación de capacidad, autenticación o exploración ampliada requiere diseño y pruebas propios; no está autorizada por la condición de solo lectura actual. Revisar exposición de metadatos, concurrencia, volumen de directorios y formatos antes de reutilizar el explorador como componente general.

## 17. Registro de cierre del Prompt 12/12

Validación local ejecutada durante el cierre, el 18 de octubre de 2026. Estos resultados corresponden a esta ejecución; no reemplazan la revisión manual ni garantizan idénticas duraciones en otro equipo.

| Validación | Resultado observado |
| --- | --- |
| `php -l` en servicios M5, pruebas cuyo archivo contiene Storage, controlador y rutas | Sin errores de sintaxis en los 31 archivos revisados |
| `php artisan route:list --path=almacenamiento -v` | Una ruta, `GET\|HEAD almacenamiento`, nombre `storage.index`, acción `StorageController@index`, middleware `web` |
| `php artisan test --filter=Storage` | 147 pruebas aprobadas, 984 aserciones, 3.58 s; código de salida 0 |
| `php vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}`; código de salida 0 |
| `php artisan test` | 1076 pruebas aprobadas, 13832 aserciones, 17.09 s; código de salida 0 |

Las dos ejecuciones de tests no reportaron errores, advertencias ni pruebas omitidas. Las duraciones de tabla son las publicadas por Artisan, no tiempos estimados. El filtro Storage incluye los casos añadidos en el hardening y su ampliación: límite combinado de ambos flujos y lectura explícita de archivo vacío.

La auditoría de cierre revisó los servicios enumerados, el controlador, la ruta, los parciales Blade y las pruebas existentes. No encontró un defecto indispensable que exigiera modificar funcionalidad en este prompt. No se crearon ni modificaron pruebas; tampoco implementaciones de M5 o de M1–M4. Los únicos cambios del cierre son este documento y un enlace breve en README.md. No se ejecutaron comandos Git, no se consultó ni modificó .git, no se modificó .env, no se instalaron dependencias y no se usó sudo. No se creó commit ni se hizo push. Queda pendiente revisión manual antes de cualquier publicación posterior.
