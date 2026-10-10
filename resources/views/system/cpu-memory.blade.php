<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPU y Memoria</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        h1 { margin: 0 0 12px; font-size: 1.8rem; }
        .description { margin: 0 0 24px; color: #526173; }
        dl { padding: 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        dt { font-weight: 600; }
        dd { margin: 8px 0 16px; overflow-wrap: anywhere; }
        a { color: #1d4ed8; }
    </style>
</head>
<body>
    <main>
        <h1>CPU y Memoria</h1>
        <p class="description">Información básica de CPU y tiempo de actividad del sistema.</p>
        <dl>
            <dt>Modelo de CPU</dt>
            <dd>{{ $cpuInfo['model'] ?? 'No disponible' }}</dd>
            <dt>Procesadores lógicos / vCPU</dt>
            <dd>{{ $cpuInfo['logical_processors'] ?? 'No disponible' }}</dd>
            <dt>Uptime en segundos</dt>
            <dd>{{ $cpuInfo['uptime_seconds'] ?? 'No disponible' }}</dd>
            <dt>Tiempo de actividad</dt>
            <dd>{{ $cpuInfo['uptime_formatted'] ?? 'No disponible' }}</dd>
        </dl>
        <a href="{{ route('processes.index') }}">Ver procesos</a>
    </main>
</body>
</html>
