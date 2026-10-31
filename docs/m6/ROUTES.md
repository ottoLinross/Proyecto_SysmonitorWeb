# Inventario de rutas — verificado con Artisan

Fuente: `php artisan route:list --json`, inspeccionado el **2026-10-30**. Laravel muestra `GET|HEAD` para rutas GET. Total observado: **36**.

`Admin` significa middleware `App\Http\Middleware\EnsureUserIsAdmin`; las rutas funcionales también requieren la sesión `auth:web`. Todas las rutas de aplicación, excepto health check, pasan por `web`.

| Nombre | Método | URI | Middleware relevante | Módulo / propósito |
| --- | --- | --- | --- | --- |
| — | GET\|HEAD | `/` | `web`, `auth:web` | M6 dashboard de lectura |
| `login` | GET\|HEAD | `/login` | `web` | M6 formulario de acceso |
| `login.store` | POST | `/login` | `web`, CSRF | M6 iniciar sesión |
| `logout` | POST | `/logout` | `web`, CSRF | M6 cerrar sesión |
| `processes.index` | GET\|HEAD | `/procesos` | `web`, `auth:web` | M1 consulta |
| `processes.test.store` | POST | `/procesos/prueba` | `web`, `auth:web`, Admin, CSRF | M1 iniciar proceso de prueba |
| `processes.test.signal` | POST | `/procesos/prueba/{managedProcess}/signal` | `web`, `auth:web`, Admin, CSRF | M1 señal de proceso administrado |
| `processes.test.priority` | POST | `/procesos/prueba/{managedProcess}/priority` | `web`, `auth:web`, Admin, CSRF | M1 prioridad de proceso administrado |
| `cpu-memory.index` | GET\|HEAD | `/cpu-memoria` | `web`, `auth:web` | M2 consulta |
| `cpu-memory.metrics` | GET\|HEAD | `/cpu-memoria/metricas` | `web`, `auth:web` | M2 métricas JSON de lectura |
| `scheduling.index` | GET\|HEAD | `/planificacion` | `web`, `auth:web` | M3 consulta |
| `scheduling.manual` | POST | `/planificacion/manual` | `web`, `auth:web`, Admin, CSRF | M3 simulación manual |
| `scheduling.random` | POST | `/planificacion/aleatoria` | `web`, `auth:web`, Admin, CSRF | M3 generación aleatoria |
| `scheduling.simulate` | POST | `/planificacion/simular` | `web`, `auth:web`, Admin, CSRF | M3 simulación |
| `scheduling.compare` | POST | `/planificacion/comparar` | `web`, `auth:web`, Admin, CSRF | M3 comparación |
| `scheduling.history` | GET\|HEAD | `/planificacion/historial` | `web`, `auth:web` | M3 historial de lectura |
| `scheduling.history.show` | GET\|HEAD | `/planificacion/historial/{id}` | `web`, `auth:web` | M3 detalle de historial |
| `deadlocks.index` | GET\|HEAD | `/interbloqueos` | `web`, `auth:web` | M4 consulta |
| `deadlocks.validate` | POST | `/interbloqueos` | `web`, `auth:web`, Admin, CSRF | M4 validar escenario |
| `deadlocks.request` | POST | `/interbloqueos/solicitudes` | `web`, `auth:web`, Admin, CSRF | M4 solicitud de recursos |
| `deadlocks.recovery` | POST | `/interbloqueos/recuperacion` | `web`, `auth:web`, Admin, CSRF | M4 simulación de recuperación |
| `deadlocks.history.store` | POST | `/interbloqueos/historial` | `web`, `auth:web`, Admin, CSRF | M4 guardar historial |
| `deadlocks.history.index` | GET\|HEAD | `/interbloqueos/historial` | `web`, `auth:web` | M4 historial de lectura |
| `deadlocks.history.show` | GET\|HEAD | `/interbloqueos/historial/{id}` | `web`, `auth:web` | M4 detalle de historial |
| `storage.index` | GET\|HEAD | `/almacenamiento` | `web`, `auth:web`, `AuthenticateStorage` (HTTP Basic) | M5 lectura con segundo factor de acceso independiente |
| `storage.local` | GET\|HEAD | `/storage/{path}` | `web`, `auth:web`, firma temporal requerida por controlador | Archivo local firmado de Laravel |
| `storage.local.upload` | PUT | `/storage/{path}` | `web`, `auth:web`, Admin, firma requerida, CSRF | Carga local firmada de Laravel |
| `users.index` | GET\|HEAD | `/usuarios` | `web`, `auth:web`, Admin | M6 lista de usuarios |
| `users.create` | GET\|HEAD | `/usuarios/crear` | `web`, `auth:web`, Admin | M6 formulario crear |
| `users.store` | POST | `/usuarios` | `web`, `auth:web`, Admin, CSRF | M6 crear usuario |
| `users.edit` | GET\|HEAD | `/usuarios/{user}/editar` | `web`, `auth:web`, Admin | M6 editar perfil/rol |
| `users.update` | PATCH | `/usuarios/{user}` | `web`, `auth:web`, Admin, CSRF | M6 actualizar perfil |
| `users.role` | PATCH | `/usuarios/{user}/rol` | `web`, `auth:web`, Admin, CSRF | M6 cambiar rol |
| `users.password.edit` | GET\|HEAD | `/usuarios/{user}/contrasena` | `web`, `auth:web`, Admin | M6 formulario contraseña |
| `users.password.update` | PUT | `/usuarios/{user}/contrasena` | `web`, `auth:web`, Admin, CSRF | M6 cambiar contraseña |
| — | GET\|HEAD | `/up` | ninguno | Health check Laravel, público |

## Conteo y frontera

Las 28 rutas previas a gestión de usuarios continúan presentes; ocho rutas `users.*` aportan la gestión administrativa. El total 36 incluye login/logout, health check y las rutas firmadas de almacenamiento local que registra Laravel. GET privados requieren sesión, salvo login y health check. Los Observadores tienen acceso de lectura donde la ruta solo exige `auth:web`; operaciones POST/PUT/PATCH/DELETE requieren Administrador. M5 añade HTTP Basic sobre la sesión web.

Verificar el inventario otra vez con Artisan en el estado que se pretenda integrar; las rutas pueden cambiar en futuras versiones.
