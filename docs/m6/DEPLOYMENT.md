# Preparación de despliegue M6

Esta guía describe una secuencia de preparación, no ejecutada por esta entrega. Ajustar los pasos al procedimiento aprobado del servidor. No copiar credenciales al repositorio, tickets o historial de terminal.

## Requisitos y preparación

1. Usar un servidor Linux soportado, PHP 8.2 o posterior compatible con Laravel 12, extensiones PHP requeridas por `composer.lock`, Composer y las dependencias existentes del proyecto. Para las lecturas de módulos se requieren las fuentes/binarios Linux que especifican sus servicios.
2. Revisar compatibilidad de base de datos y espacio. La aplicación utiliza SQLite según el contexto del proyecto; la base del despliegue debe estar en una ruta persistente y escribible únicamente por el usuario de aplicación.
3. Preparar una ventana, un plan de reversión y un respaldo verificable de la base y archivos persistentes antes de actualizar.
4. Configurar variables del entorno en el gestor de secretos o archivo local no versionado. No incluir `.env` en artefactos públicos ni registrar variables sensibles.
5. Generar `APP_KEY` de forma segura para el entorno de despliegue mediante el procedimiento de Laravel. No reutilizar una clave de otro entorno ni publicar su valor. Esta tarea no ejecutó ni documentó un valor real.

## Base, archivos y permisos

6. Confirmar el archivo SQLite, respaldarlo y comprobar propietario/permisos. El usuario web debe escribir solo en los directorios que Laravel requiere, como `storage/` y `bootstrap/cache/`; no conceder escritura general al código.
7. Revisar migraciones pendientes y compatibilidad con una copia de la base. Aplicar migraciones solo en la ventana aprobada y después del respaldo. `php artisan migrate --force` es una acción de despliegue, no se ejecutó aquí.
8. Si se usa caché de configuración, limpiar o regenerar la caché después de introducir variables seguras, evitando incluir secretos en logs o salida pública.

## HTTPS y sesiones

9. Publicar exclusivamente mediante HTTPS con certificado válido y redirección/denegación de HTTP en el borde de despliegue. Configurar correctamente proxy TLS de confianza si aplica.
10. Verificar explícitamente `SESSION_SECURE_COOKIE=true` para producción HTTPS. Mantener `SESSION_HTTP_ONLY=true` y `SESSION_SAME_SITE=lax` salvo una decisión documentada. Elegir un driver de sesión persistente y operativo; rate limiting también necesita un backend de caché adecuado.
11. Confirmar que el cookie de sesión se emite con `Secure`, `HttpOnly` y `SameSite` esperado al inspeccionar la respuesta HTTPS, sin guardar ni compartir su valor.

## Acceso y primer Administrador

12. Provisionar fuera del repositorio las variables de M5 `M5_BASIC_USERNAME` y `M5_BASIC_PASSWORD_HASH`; usar un hash compatible, limitar su acceso y verificar que M5 sigue solicitando HTTP Basic además de Laravel. No documentar contraseñas ni hashes.
13. Después de aplicar el esquema de `users` y `role`, un operador autorizado puede ejecutar manualmente `php artisan sysmonitor:create-admin` en el servidor/entorno elegido. El comando solicita la contraseña de forma oculta, crea solo el primero y modifica la base configurada. No automatizarlo ni pasar contraseña por argumentos.
14. Administrar las cuentas restantes desde la UI autenticada y asignar roles tras autorización. Confirmar previamente correos de las siete personas del equipo; no se inventan aquí.

## Verificación posterior

15. Probar login/logout, rotación e invalidación de sesión, CSRF y limitación de intentos; no usar contraseñas reales en tickets o capturas.
16. Probar con cuentas controladas de Administrador, Observador y rol inválido: lecturas permitidas, escrituras denegadas a no administradores y protección del último Administrador.
17. Validar visualmente Inicio, menú responsive y M1–M5. Ejecutar flujos inocuos o simulados; las acciones de proceso de M1 pueden actuar sobre procesos de prueba del host y deben probarse solo con autorización.
18. Verificar HTTP Basic de M5 con credenciales temporales controladas, HTTPS y `Cache-Control: no-store`.
19. Confirmar eventos `pending` y resultado en auditoría para operaciones administrativas de prueba, y verificar que no incluyan secretos.

## Reversión conceptual

Ante fallo, detener nuevas escrituras, conservar logs operativos protegidos, volver al artefacto anterior y restaurar la copia de base solo según un plan revisado y con control de pérdida de datos. No ejecutar rollback de migración a ciegas: algunas migraciones pueden eliminar datos. Si ya se aceptaron operaciones después del despliegue, preferir una corrección hacia adelante o reconciliación respaldada antes que restaurar datos antiguos.

No se modificó infraestructura ni se ejecutó despliegue alguno para preparar esta guía.
