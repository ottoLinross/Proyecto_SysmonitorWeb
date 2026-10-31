# Validación de M6

## Comandos

Desde la raíz del proyecto, con dependencias ya instaladas:

```text
php artisan test tests/Feature/M6 --compact
php artisan test --compact
./vendor/bin/pint --test
php artisan route:list
```

La suite usa las variables de prueba de `phpunit.xml`, entre ellas SQLite `:memory:`, cache `array` y sesiones `array`. Esto no ejecuta pruebas sobre la SQLite de desarrollo. Las pruebas M6 cubren login/logout, sesiones, limitación, roles y middleware, las 36 rutas, Dashboard, M5 Basic, bitácora, gestión de usuarios, comando inicial y regresiones de seguridad. Las operaciones de proceso en pruebas sustituyen los servicios por mocks o evitan acciones del sistema.

## Resultados registrados

Fecha local: **2026-10-30**.

| Verificación | Resultado |
| --- | --- |
| `tests/Feature/M6/SecurityHardeningTest.php --compact` | 12 pruebas, 166 assertions; PASS, 2.09 s. |
| `tests/Feature/M6 --compact` | 156 pruebas, 2,035 assertions; PASS, 9.90 s. |
| `php artisan test --compact` | 1,238 pruebas, 15,977 assertions; PASS, 31.08 s. |
| `./vendor/bin/pint --test` | PASS. |
| `php artisan route:list --json` | 36 rutas registradas. |

Las cifras son la salida observada al validar este estado el 2026-10-30; no se copiaron las cifras históricas del handoff anterior.

## Cobertura y límites

La cobertura automatizada comprueba contratos y casos simulados; no sustituye una revisión visual interactiva en navegadores, tamaños responsive, contraste, teclado o tecnologías de asistencia. Tampoco valida certificados, cookies reales del proxy TLS, firewall, permisos del servidor, valores de secretos, respaldo/restore ni la instalación productiva de utilidades Linux.

Verificar manualmente antes del despliegue: sesión HTTPS y bandera Secure; driver de sesión y cache/rate limiter; credenciales Basic de M5; inicio/cierre de sesión; acceso de ambos roles; rol desconocido; cinco enlaces M1–M5; dashboard y métricas; formularios CSRF; navegación móvil; bitácora; y controles de usuario. Probar operaciones que actúan sobre procesos únicamente en ambiente controlado y autorizado.

Pint verifica los archivos soportados por su configuración, no todos los problemas de estilo, accesibilidad, seguridad o formato del repositorio. Una búsqueda de espacios finales confirmó siete líneas en vistas Blade (`processes/index`, `deadlocks/index`, `deadlocks/history-message`, `deadlocks/history`, `scheduling/index`, `scheduling/history` y `system/cpu-memory`). No se limpiaron en este Prompt porque esas vistas funcionales quedan fuera del alcance documental.
