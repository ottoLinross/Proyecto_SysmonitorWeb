# M6 — Integración y bitácora de SysMonitor Web

## Objetivo

M6 integra autenticación web, roles, autorización, auditoría administrativa, gestión de usuarios, un dashboard de lectura y un layout común para M1–M5. Mantiene las operaciones y rutas funcionales existentes. M7 no forma parte de este alcance.

## Arquitectura

| Componente | Responsabilidad |
| --- | --- |
| `AuthenticatedSessionController` | Inicio y cierre de sesión Laravel, limitación de intentos y redirección posterior segura. |
| `EnsureUserIsAdmin` | Bloquea operaciones de escritura y gestión de usuarios a cuentas que no tengan el rol persistido de Administrador. |
| `AuthenticateStorage` | Añade el HTTP Basic independiente que ya usa M5. |
| `DashboardController` y `DashboardMetricsService` | Compone métricas de lectura para CPU, memoria y procesos; un fallo de una fuente no oculta las otras. |
| `AuditLogger` y `AuditLog` | Crea eventos pendientes, aplica una allowlist de metadatos y finaliza cada evento una sola vez. |
| `UserManagementController` | Administra usuarios y roles, protege el último Administrador y audita cambios. |
| `CreateInitialAdmin` | Inicialización local interactiva del primer Administrador. |
| `layouts.sysmonitor` y `partials.sysmonitor-navigation` | Identidad, rol, navegación, salida POST/CSRF y estructura común de vistas. |

La raíz (`/`) muestra un dashboard autenticado de lectura. La navegación enlaza Inicio y los cinco módulos. Observadores y Administradores pueden consultar módulos; las rutas de escritura requieren además `EnsureUserIsAdmin`. El acceso a `/almacenamiento` también conserva el reto HTTP Basic de M5.

## Relación con M1–M5

- **M1 — Procesos:** conserva lectura y controles existentes. Lanzamiento, señal y prioridad requieren Administrador y se registran en la bitácora. Las operaciones reales permanecen en servicios M1.
- **M2 — CPU y Memoria:** conserva sus dos endpoints GET. El dashboard reutiliza servicios de lectura existentes.
- **M3 — Planificación:** conserva simulaciones, comparación e historial; las acciones POST son administrativas y las vistas GET quedan disponibles a usuarios autenticados.
- **M4 — Interbloqueos:** conserva análisis, recuperación e historial; operaciones POST requieren Administrador y la consulta GET es de lectura.
- **M5 — Almacenamiento:** conserva HTTP Basic independiente además de la sesión Laravel. La ruta de lectura usa `AuthenticateStorage`; carga de archivos framework está autenticada y su PUT requiere Administrador y URL firmada.

## Autenticación, roles y navegación

El login es público y usa el guard `web` con proveedor Eloquent. Login rota la sesión, logout es POST, invalida la sesión y rota CSRF. Se limitan intentos fallidos. No hay registro ni recuperación pública. Los roles válidos son `admin` y `observer`; un valor desconocido no obtiene autorización administrativa. Ver [AUTH_ROLES.md](AUTH_ROLES.md).

## Dashboard

El dashboard muestra los indicadores que pueden leerse de las fuentes actuales. CPU, memoria y número de procesos se consultan independientemente. Los datos no disponibles se representan como no disponibles; el dashboard no hace operaciones de escritura. El indicador de almacenamiento aún se devuelve como no disponible por el servicio del dashboard y no debe interpretarse como una medición real.

## Bitácora y cuentas

Los cambios administrativos de usuarios y las acciones M1 de procesos usan `AuditLogger`. La bitácora almacena estados y metadatos acotados, nunca contraseñas, hashes, tokens ni cuerpos completos de solicitudes. La inicialización local del primer Administrador es la excepción documentada: no hay actor autenticado todavía. Ver [AUDIT_LOG.md](AUDIT_LOG.md), [USER_MANAGEMENT.md](USER_MANAGEMENT.md) y [el procedimiento del primer Administrador](USER_MANAGEMENT.md#primer-administrador).

## Limitaciones conocidas

- No se implementan registro, recuperación pública, eliminación ni desactivación de cuentas.
- La medición de almacenamiento del dashboard está pendiente; M5 tiene su propia vista.
- La cobertura automatizada no sustituye la prueba visual manual en navegadores y tamaños de pantalla reales.
- `SESSION_SECURE_COOKIE` se obtiene del entorno. Antes de publicar por HTTPS hay que confirmar que esté en `true`.
- M5 Basic es una credencial adicional compartida por instalación; no equivale a identidad individual ni MFA.
- Las cuentas reales del equipo y sus correos no están configurados por esta entrega.

## Documentos

- [Autenticación y roles](AUTH_ROLES.md)
- [Bitácora](AUDIT_LOG.md)
- [Gestión de usuarios y primer Administrador](USER_MANAGEMENT.md)
- [Preparación de despliegue](DEPLOYMENT.md)
- [Validación](VALIDATION.md)
- [Inventario de rutas](ROUTES.md)

## Handoff para integración final

- **Rama de trabajo:** `feature/m6-integracion-bitacora` (contexto reportado; no se consultó Git).
- **Estado de módulos:** M1–M5 conservan sus rutas funcionales; M6 integra autenticación, roles, auditoría, dashboard, layout y gestión de usuarios. M7 está excluido.
- **Dependencias:** Laravel 12, PHP 8.2+, SQLite según la configuración de la aplicación, Blade y dependencias ya presentes en `vendor/`. M1 y M2 comparten servicios de lectura de sistema; M6 no incorpora paquetes.
- **Validación automatizada (2026-10-30):** SecurityHardeningTest 12/166; suite M6 156/2,035 en 9.90 s; suite completa 1,238/15,977 en 31.08 s; Pint PASS; 36 rutas. Detalle en [VALIDATION.md](VALIDATION.md).
- **Pendientes de configuración:** revisar variables de producción fuera del repositorio; verificar `APP_KEY`, HTTPS, `SESSION_SECURE_COOKIE=true`, driver de sesión y credenciales de HTTP Basic para M5. La configuración real de cuentas del equipo está pendiente.
- **Verificaciones manuales:** iniciar sesión y salir en navegador; revisar roles, menú responsive, errores de formularios, indicadores del dashboard, navegación completa, M5 Basic y gráficas/JavaScript M2 en navegador real.
- **Riesgos operativos:** respaldar base y almacenamiento antes de migrar; no incluir secretos en repositorio ni historial de shell; el proceso de prueba M1 puede continuar hasta su límite si falla el registro posterior; HTTP Basic de M5 requiere HTTPS.
- **Criterios antes de integrar con `main`:** revisión de diferencias por Otto, suite y Pint en el estado que se integrará, confirmación de las 36 rutas, validación de HTTPS/cookie segura, configuración M5 fuera del repositorio y revisión manual de los cinco módulos.

Esta documentación prepara una revisión. No afirma que exista un Pull Request ni que M6 esté integrado en `main`.
