# Gestión de usuarios

## Acceso y capacidades

Solo Administradores pueden listar, crear y editar usuarios. La administración está protegida por `auth:web` y `EnsureUserIsAdmin`; la autorización del servidor se aplica aunque se invoque una URL directamente. Observadores pueden consultar los módulos permitidos, pero no pueden gestionar usuarios ni ejecutar acciones administrativas.

La interfaz permite:

- Crear usuario con nombre, correo único, contraseña confirmada de al menos 12 caracteres y uno de los dos roles válidos.
- Cambiar nombre y correo sin aceptar cambios de rol o contraseña mediante asignación masiva.
- Cambiar el rol con validación explícita.
- Cambiar la contraseña de otra cuenta mediante entrada confirmada.

El modelo aplica hashing de contraseñas y oculta `password` y `remember_token` en serialización. Las consultas de listado seleccionan explícitamente campos de perfil y no incluyen hashes. Las operaciones se auditan; consulta [AUDIT_LOG.md](AUDIT_LOG.md).

## Protección del último Administrador

Una transacción cuenta los Administradores y rechaza degradar al último. En SQLite se obtiene el bloqueo de escritura antes del recuento porque SQLite no implementa `SELECT FOR UPDATE`. El cambio de rol persistido afecta las autorizaciones de solicitudes posteriores.

No hay eliminación ni desactivación de cuentas en la implementación actual. No hay registro ni recuperación pública de contraseña. La asignación de cuentas del equipo sigue pendiente: no se han creado usuarios ni inventado direcciones.

## Primer Administrador

Ejecutar manualmente, por un operador autorizado, en el entorno correcto y solo después de disponer del esquema requerido:

```text
php artisan sysmonitor:create-admin
```

El comando solicita nombre, correo, contraseña y confirmación de forma interactiva; la contraseña se pide con entrada oculta. Valida formato y unicidad del correo, longitud mínima y confirmación; Laravel aplica hashing. Solo crea el primer Administrador y rechaza la segunda inicialización. Modifica la base de datos configurada en la aplicación al ejecutarse.

No se ejecuta automáticamente, no debe usarse con contraseñas como argumentos y no se ejecutó durante esta tarea. La base usada debe tener la tabla `users` y la columna `role` ya migradas. La creación local no tiene auditoría autenticada porque aún no existe un actor Administrador.

## Cuentas de desarrollo del equipo

Se solicita configurar cuentas para Fernando, Gabriel, Yefry, Wilson, Otto, Kevin e Ing. Josué Jiménez. No se proporcionaron correos verificados ni autorizaciones de rol; por tanto, esos datos y cuentas siguen pendientes. Después de crear el primer Administrador, las cuentas adicionales se pueden crear desde la interfaz administrativa si la base de destino tiene el esquema aplicado. Asignar roles individualmente según autorización, sin contraseñas compartidas.
