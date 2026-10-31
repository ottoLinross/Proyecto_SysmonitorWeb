# Bitácora administrativa

## Modelo y almacenamiento

`App\\Models\\AuditLog` persiste eventos en `audit_logs` mediante la migración `2026_10_29_000000_create_audit_logs_table.php`. Un registro puede asociarse a un usuario; la relación nullable conserva el historial si el usuario deja de existir. Se guardan acción, tipo e identificador de objetivo, PID opcional, estado, resultado, metadatos JSON, IP y timestamps. La asignación masiva está deshabilitada y el modelo valida los estados permitidos.

## Estados y secuencia

1. Se autentica y autoriza al Administrador.
2. Se validan los datos de la petición.
3. `AuditLogger::begin()` vuelve a comprobar que el actor Administrador existe en la base y crea una entrada `pending`.
4. Se ejecuta la operación una sola vez.
5. `AuditLogger::finish()` verifica actor, propiedad y estado pendiente; cambia el evento a `success` o `failure` una sola vez.

Si `begin()` falla, el controlador no ejecuta la operación. Si la operación falla, se intenta registrar `failure`. Si `finish()` falla después de la operación, no se reintenta la operación: el registro puede permanecer `pending` y requiere investigación manual.

## Acciones cubiertas

- Gestión de usuarios: `user.create`, `user.update`, `user.role`, `user.password`.
- Acciones de procesos de prueba M1: `process.start`, `process.signal`, `process.priority`.

No se afirma que simulaciones de M3/M4 o consultas de lectura se auditen; en el código actual no llaman a `AuditLogger`.

## Datos sensibles

Los metadatos se limitan a `process_id`, `signal`, `nice`, `algorithm`, `mode`, `process_count` y `quantum`; cada valor se acepta solo si cumple su tipo/formato. No se guarda la contraseña, su hash, tokens, cabeceras ni el cuerpo completo de la solicitud. Los códigos de resultado son acotados. Excepciones internas no se devuelven al usuario como detalles.

La columna IP guarda una dirección válida cuando está disponible. Dado que puede ser dato personal, su acceso y retención deben ajustarse a las políticas operativas del despliegue.

## Inicialización del primer Administrador

La creación inicial con `php artisan sysmonitor:create-admin` es una operación local previa a la existencia de una sesión de Administrador. Por ello, es una excepción que no tiene entrada de auditoría autenticada: no hay actor legítimo que registrar. No se inventa un actor ficticio. El comando valida y crea la cuenta inicial de forma transaccional, y rechaza una segunda inicialización.

## Limitaciones

La auditoría usa la misma base de datos operativa; un fallo de persistencia puede dejar un evento pendiente. No hay interfaz de consulta de bitácora incluida en M6 actual, ni política de retención/archivo automatizada. Deben definirse permisos de lectura, retención y respaldo según el entorno antes de operar con requisitos regulatorios.
