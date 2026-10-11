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
        .table-container { overflow-x: auto; margin-bottom: 24px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        table { width: 100%; border-spacing: 0; }
        caption { padding: 16px; text-align: left; font-weight: 600; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #eaf0f6; }
        .numeric { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .command { overflow-wrap: anywhere; white-space: pre-wrap; font-family: ui-monospace, monospace; }
    </style>
</head>
<body>
    <main>
        <h1>CPU y Memoria</h1>
        <p class="description">Información básica de CPU y tiempo de actividad del sistema.</p>
        <dl>
            <dt>Uso de CPU</dt>
            <dd>{{ $cpuUsagePercent === null ? 'No disponible' : number_format($cpuUsagePercent, 1, '.', '').' %' }}</dd>
            <dt>Carga promedio (1 minuto)</dt>
            <dd>{{ isset($cpuInfo['load_average']['one_minute']) ? number_format($cpuInfo['load_average']['one_minute'], 2, '.', '') : 'No disponible' }}</dd>
            <dt>Carga promedio (5 minutos)</dt>
            <dd>{{ isset($cpuInfo['load_average']['five_minutes']) ? number_format($cpuInfo['load_average']['five_minutes'], 2, '.', '') : 'No disponible' }}</dd>
            <dt>Carga promedio (15 minutos)</dt>
            <dd>{{ isset($cpuInfo['load_average']['fifteen_minutes']) ? number_format($cpuInfo['load_average']['fifteen_minutes'], 2, '.', '') : 'No disponible' }}</dd>
            <dt>Modelo de CPU</dt>
            <dd>{{ $cpuInfo['model'] ?? 'No disponible' }}</dd>
            <dt>Procesadores lógicos / vCPU</dt>
            <dd>{{ $cpuInfo['logical_processors'] ?? 'No disponible' }}</dd>
            <dt>Uptime en segundos</dt>
            <dd>{{ $cpuInfo['uptime_seconds'] ?? 'No disponible' }}</dd>
            <dt>Tiempo de actividad</dt>
            <dd>{{ $cpuInfo['uptime_formatted'] ?? 'No disponible' }}</dd>
        </dl>
        <h2>Memoria y swap</h2>
        <dl>
            @foreach ([
                'memory_total_bytes' => 'Memoria total',
                'memory_used_bytes' => 'Memoria usada',
                'memory_free_bytes' => 'Memoria libre',
                'memory_cached_bytes' => 'Memoria en caché',
                'swap_total_bytes' => 'Swap total',
                'swap_used_bytes' => 'Swap usada',
                'swap_free_bytes' => 'Swap libre',
            ] as $key => $label)
                <dt>{{ $label }}</dt>
                <dd>{{ isset($memoryInfo[$key]) ? number_format($memoryInfo[$key] / 1048576, 2, '.', '').' MiB' : 'No disponible' }}</dd>
            @endforeach
        </dl>
        @foreach ([
            ['id' => 'top-cpu', 'title' => 'Top 5 procesos por CPU', 'metric' => 'cpu_percent', 'label' => 'CPU %', 'processes' => $topCpuProcesses],
            ['id' => 'top-memory', 'title' => 'Top 5 procesos por memoria', 'metric' => 'memory_percent', 'label' => 'Memoria %', 'processes' => $topMemoryProcesses],
        ] as $ranking)
            <div class="table-container">
                <table id="{{ $ranking['id'] }}">
                    <caption>{{ $ranking['title'] }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">PID</th>
                            <th scope="col">Comando</th>
                            <th scope="col" class="numeric">{{ $ranking['label'] }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ranking['processes'] as $process)
                            <tr>
                                <td class="numeric">{{ $process['pid'] }}</td>
                                <td class="command">{{ $process['command'] }}</td>
                                <td class="numeric">{{ number_format($process[$ranking['metric']], 1, '.', '') }} %</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">No hay procesos disponibles para mostrar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
        <a href="{{ route('processes.index') }}">Ver procesos</a>
    </main>
</body>
</html>
