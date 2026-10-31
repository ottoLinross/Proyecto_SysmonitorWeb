# Autenticación y autorización M6

## Flujo de sesión

`GET /login` presenta el formulario. `POST /login` valida correo y contraseña con el guard Laravel `web`; las credenciales incorrectas reciben un mensaje genérico. Una autenticación correcta limpia el contador de fallos y regenera la sesión. La URL de retorno se limita a una ruta GET local registrada y usa `/procesos` como alternativa segura.

Los intentos se limitan a cinco por combinación de correo normalizado e IP en una ventana de 60 segundos. Una petición posterior recibe HTTP 429 hasta que expire la ventana. El almacén de caché configurado en el despliegue debe ser adecuado para todos los procesos web.

`POST /logout` cierra el guard, invalida la sesión y regenera el token CSRF. Los formularios de escritura pertenecen al grupo web de Laravel y deben conservar CSRF. No hay registro ni recuperación pública de contraseñas.

## Roles y permisos

El modelo `User` define `admin` (Administrador) y `observer` (Observador). La asignación de roles está validada y no es mass assignable. El backend aplica autorización aunque un usuario manipule parámetros o controles del navegador.

| Tipo de acceso | Administrador | Observador | Rol desconocido autenticado |
| --- | --- | --- | --- |
| Dashboard y consultas GET de M1–M5 | Sí | Sí | Sí, en rutas protegidas solo por `auth:web` |
| Acciones POST de M1, M3 y M4 | Sí | No | No |
| Gestión de usuarios | Sí | No | No |
| Lectura M5 | Sesión Laravel **y** HTTP Basic | Sesión Laravel **y** HTTP Basic | Sesión Laravel **y** HTTP Basic |
| PUT de archivo firmado del framework | Sí, con firma válida | No | No |

El middleware `EnsureUserIsAdmin` exige el rol exacto de Administrador. El proveedor de sesión vuelve a resolver el usuario en solicitudes HTTP siguientes; por ello, un cambio persistido de rol se refleja en la siguiente solicitud autenticada. El test de regresión verifica ese caso. Un rol desconocido no se interpreta como Administrador.

## Protección de rutas

Las rutas de aplicación se agrupan en middleware `web` y `auth:web`, excepto login, logout y health check. Las ocho rutas `users.*` requieren `EnsureUserIsAdmin`. Las rutas POST/PUT/PATCH funcionales también requieren dicho middleware. Las consultas GET quedan disponibles para usuarios autenticados, salvo que exista una protección adicional como HTTP Basic en M5. El inventario método por método está en [ROUTES.md](ROUTES.md).

## CSRF y controles de interfaz

Los formularios que escriben datos incluyen `@csrf`; las acciones de modificación usan los métodos HTTP correspondientes y el spoofing de Laravel cuando procede. El middleware backend es la frontera de seguridad; ocultar enlaces o botones para Observadores es una ayuda de interfaz y no sustituye autorización.

## HTTP Basic de M5

`GET /almacenamiento` requiere primero la sesión Laravel y luego credenciales HTTP Basic separadas. Se verifican contra configuración externa; respuesta no autorizada usa 401, `WWW-Authenticate` y `Cache-Control: no-store`. M5 no debe publicarse por HTTP porque Basic solo codifica, no cifra, las credenciales. HTTPS es obligatorio. Véase [M5](../M5_ALMACENAMIENTO.md).

## Límites

La política de sesión segura depende de configuración del entorno: establecer y verificar `SESSION_SECURE_COOKIE=true` detrás de HTTPS. La aplicación no implementa MFA ni recuperación de contraseña. HTTP Basic de M5 no identifica individualmente a cada usuario.
