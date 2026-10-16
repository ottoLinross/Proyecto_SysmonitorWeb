# Servicios de interbloqueos (M4)

Directorio reservado para los futuros servicios del módulo Interbloqueos,
bajo el namespace `App\Services\Deadlock`, independiente de los servicios
de sistema y planificación.

Este paso solo establece la estructura inicial. No incluye entrada de matrices,
matriz Need, algoritmo del banquero, solicitudes de recursos, grafo de asignación,
detección de ciclos, recuperación ni persistencia SQLite.

El controlador inicial únicamente devuelve la vista `deadlocks.index` y no
requiere servicios ni acceso a base de datos.
