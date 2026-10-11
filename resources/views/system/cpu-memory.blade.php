<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPU y Memoria — SysMonitor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        a { color: #1d4ed8; }
        a:focus-visible, .table-container:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        .page-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        .brand { margin: 0 0 8px; color: #526173; font-weight: 600; }
        h1 { margin: 0 0 12px; font-size: 1.8rem; }
        h2 { margin: 0 0 16px; font-size: 1.2rem; }
        .description { margin: 0; color: #526173; line-height: 1.5; }
        section { min-width: 0; margin-bottom: 24px; }
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: 12px; margin: 0; }
        .metric-card { min-width: 0; padding: 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        dt { color: #526173; line-height: 1.4; }
        dd { margin: 8px 0 0; font-size: 1.25rem; font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .cpu-usage { border-top: 4px solid #1d4ed8; }
        .cpu-usage dd { font-size: 1.8rem; }
        .panel-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
        .table-container { min-width: 0; overflow-x: auto; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        table { width: 100%; min-width: 360px; border-spacing: 0; font-size: 0.9rem; }
        caption { padding: 16px; text-align: left; font-weight: 600; font-size: 1rem; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { background: #eaf0f6; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:hover { background: #eff6ff; }
        tbody tr:last-child td { border-bottom: 0; }
        .numeric { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .command { overflow-wrap: anywhere; white-space: pre-wrap; font-family: ui-monospace, monospace; }
        .empty { padding: 24px 16px; text-align: center; color: #526173; }
        @media (max-width: 800px) {
            .panel-grid { grid-template-columns: minmax(0, 1fr); }
            main { padding: 24px 16px; }
        }
    </style>
</head>
<body>
    <main>
        <header class="page-header">
            <div>
                <p class="brand">SysMonitor</p>
                <h1>CPU y Memoria</h1>
                <p class="description">Estado del sistema al cargar esta página.</p>
            </div>
            <nav aria-label="Navegación de módulos">
                <a href="{{ route('processes.index') }}">Ver procesos</a>
            </nav>
        </header>

        <section aria-labelledby="cpu-info-title">
            <h2 id="cpu-info-title">Información de CPU y uptime</h2>
            <dl class="metric-grid">
                <div class="metric-card">
                    <dt>Modelo de CPU</dt>
                    <dd>{{ $cpuInfo['model'] ?? 'No disponible' }}</dd>
                </div>
                <div class="metric-card">
                    <dt>Procesadores lógicos / vCPU</dt>
                    <dd>{{ $cpuInfo['logical_processors'] ?? 'No disponible' }}</dd>
                </div>
                <div class="metric-card">
                    <dt>Tiempo de actividad</dt>
                    <dd>{{ $cpuInfo['uptime_formatted'] ?? 'No disponible' }}</dd>
                </div>
                <div class="metric-card">
                    <dt>Uptime en segundos</dt>
                    <dd>{{ $cpuInfo['uptime_seconds'] ?? 'No disponible' }}</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="cpu-load-title">
            <h2 id="cpu-load-title">Uso de CPU y carga promedio</h2>
            <dl class="metric-grid">
                <div class="metric-card cpu-usage">
                    <dt>Uso de CPU</dt>
                    <dd>{{ $cpuUsagePercent === null ? 'No disponible' : number_format($cpuUsagePercent, 1, '.', '').' %' }}</dd>
                </div>
                @foreach (['one_minute' => 'Carga promedio (1 minuto)', 'five_minutes' => 'Carga promedio (5 minutos)', 'fifteen_minutes' => 'Carga promedio (15 minutos)'] as $key => $label)
                    <div class="metric-card">
                        <dt>{{ $label }}</dt>
                        <dd>{{ isset($cpuInfo['load_average'][$key]) ? number_format($cpuInfo['load_average'][$key], 2, '.', '') : 'No disponible' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <div class="panel-grid">
            <section aria-labelledby="memory-title">
                <h2 id="memory-title">Memoria</h2>
                <dl class="metric-grid">
                    @foreach (['memory_total_bytes' => 'Memoria total', 'memory_used_bytes' => 'Memoria usada', 'memory_free_bytes' => 'Memoria libre', 'memory_cached_bytes' => 'Memoria en caché'] as $key => $label)
                        <div class="metric-card">
                            <dt>{{ $label }}</dt>
                            <dd>{{ isset($memoryInfo[$key]) ? number_format($memoryInfo[$key] / 1048576, 2, '.', '').' MiB' : 'No disponible' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
            <section aria-labelledby="swap-title">
                <h2 id="swap-title">Swap</h2>
                <dl class="metric-grid">
                    @foreach (['swap_total_bytes' => 'Swap total', 'swap_used_bytes' => 'Swap usada', 'swap_free_bytes' => 'Swap libre'] as $key => $label)
                        <div class="metric-card">
                            <dt>{{ $label }}</dt>
                            <dd>{{ isset($memoryInfo[$key]) ? number_format($memoryInfo[$key] / 1048576, 2, '.', '').' MiB' : 'No disponible' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>

        <section aria-labelledby="rankings-title">
            <h2 id="rankings-title">Procesos con mayor consumo</h2>
            <div class="panel-grid">
                @foreach ([
                    ['id' => 'top-cpu', 'title' => 'Top 5 procesos por CPU', 'metric' => 'cpu_percent', 'label' => 'CPU %', 'processes' => $topCpuProcesses],
                    ['id' => 'top-memory', 'title' => 'Top 5 procesos por memoria', 'metric' => 'memory_percent', 'label' => 'Memoria %', 'processes' => $topMemoryProcesses],
                ] as $ranking)
                    <div class="table-container" role="region" aria-labelledby="{{ $ranking['id'] }}-caption" tabindex="0">
                        <table id="{{ $ranking['id'] }}">
                            <caption id="{{ $ranking['id'] }}-caption">{{ $ranking['title'] }}</caption>
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
                                    <tr><td colspan="3" class="empty">No hay procesos disponibles para mostrar.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </section>
    </main>
</body>
</html>
