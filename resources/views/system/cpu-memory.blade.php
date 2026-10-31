@extends('layouts.sysmonitor')

@section('title', 'CPU y Memoria')
@section('page-title', 'CPU y Memoria — SysMonitor')

@section('styles')
    <style>.module-cpu-memory *{ box-sizing: border-box; }.module-cpu-memory{ margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }.module-cpu-memory{ max-width: 1440px; margin: 0 auto; padding: 32px 20px; }.module-cpu-memory a{ color: #1d4ed8; }.module-cpu-memory a:focus-visible, .module-cpu-memory .table-container:focus-visible{ outline: 3px solid #2563eb; outline-offset: 3px; }.module-cpu-memory .page-header{ display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }.module-cpu-memory .brand{ margin: 0 0 8px; color: #526173; font-weight: 600; }.module-cpu-memory h1{ margin: 0 0 12px; font-size: 1.8rem; }.module-cpu-memory h2{ margin: 0 0 16px; font-size: 1.2rem; }.module-cpu-memory .description{ margin: 0; color: #526173; line-height: 1.5; }.module-cpu-memory .refresh-state{ margin-bottom: 24px; color: #526173; font-size: 0.9rem; }.module-cpu-memory .refresh-state p{ margin: 0 0 8px; }.module-cpu-memory #metrics-status[data-state="error"]{ color: #991b1b; }.module-cpu-memory section{ min-width: 0; margin-bottom: 24px; }.module-cpu-memory .metric-grid{ display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: 12px; margin: 0; }.module-cpu-memory .metric-card{ min-width: 0; padding: 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }.module-cpu-memory dt{ color: #526173; line-height: 1.4; }.module-cpu-memory dd{ margin: 8px 0 0; font-size: 1.25rem; font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }.module-cpu-memory .cpu-usage{ border-top: 4px solid #1d4ed8; }.module-cpu-memory .cpu-usage dd{ font-size: 1.8rem; }.module-cpu-memory .panel-grid{ display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }.module-cpu-memory .table-container{ min-width: 0; overflow-x: auto; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }.module-cpu-memory table{ width: 100%; min-width: 360px; border-spacing: 0; font-size: 0.9rem; }.module-cpu-memory caption{ padding: 16px; text-align: left; font-weight: 600; font-size: 1rem; }.module-cpu-memory th, .module-cpu-memory td{ padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: top; }.module-cpu-memory th{ background: #eaf0f6; }.module-cpu-memory tbody tr:nth-child(even){ background: #f8fafc; }.module-cpu-memory tbody tr:hover{ background: #eff6ff; }.module-cpu-memory tbody tr:last-child td{ border-bottom: 0; }.module-cpu-memory .numeric{ text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }.module-cpu-memory .command{ overflow-wrap: anywhere; white-space: pre-wrap; font-family: ui-monospace, monospace; }.module-cpu-memory .empty{ padding: 24px 16px; text-align: center; color: #526173; }.module-cpu-memory .chart-card{ min-width: 0; margin: 0; padding: 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }.module-cpu-memory .chart-card figcaption{ margin-bottom: 8px; font-weight: 600; }.module-cpu-memory .chart-card svg{ display: block; width: 100%; height: auto; }.module-cpu-memory .chart-grid{ stroke: #e2e8f0; stroke-width: 1; }.module-cpu-memory .chart-axis{ fill: #526173; font-size: 14px; }.module-cpu-memory .chart-line{ fill: none; stroke: #1d4ed8; stroke-width: 2; }.module-cpu-memory .chart-point{ fill: #1d4ed8; }.module-cpu-memory .chart-memory .chart-line{ stroke: #166534; }.module-cpu-memory .chart-memory .chart-point{ fill: #166534; }.module-cpu-memory .chart-recent{ margin: 12px 0 0; color: #526173; font-size: 0.9rem; line-height: 1.5; overflow-wrap: anywhere; }
        @media (max-width: 800px) {.module-cpu-memory .panel-grid{ grid-template-columns: minmax(0, 1fr); }.module-cpu-memory{ padding: 24px 16px; }
        }
        </style>
@endsection

@section('content')
    <div class="module-page module-cpu-memory" id="cpu-memory-panel" data-metrics-url="{{ route('cpu-memory.metrics', [], false) }}">

            <header class="page-header">
            <div>
                <p class="brand">SysMonitor</p>
                <h1>CPU y Memoria</h1>
                <p class="description">Estado de CPU, memoria y procesos del sistema.</p>
            </div>
            <nav aria-label="Navegación de módulos">
                <a href="{{ route('processes.index') }}">Ver procesos</a>
            </nav>
        </header>

        <div class="refresh-state">
            <p id="metrics-status" role="status" aria-live="polite" aria-atomic="true">{{ $metricsUnavailable ? 'Algunas métricas no están disponibles temporalmente.' : 'Datos iniciales del servidor.' }}</p>
            <p>Última lectura correcta: <time id="metrics-last-read">No disponible</time></p>
            <noscript>JavaScript está deshabilitado. Se muestran los valores al cargar la página.</noscript>
        </div>

        <section aria-labelledby="cpu-info-title">
            <h2 id="cpu-info-title">Información de CPU y uptime</h2>
            <dl class="metric-grid">
                <div class="metric-card">
                    <dt>Modelo de CPU</dt>
                    <dd data-metric="cpu.model" data-format="text">{{ $cpuInfo['model'] ?? 'No disponible' }}</dd>
                </div>
                <div class="metric-card">
                    <dt>Procesadores lógicos / vCPU</dt>
                    <dd data-metric="cpu.logical_processors" data-format="integer">{{ $cpuInfo['logical_processors'] ?? 'No disponible' }}</dd>
                </div>
                <div class="metric-card">
                    <dt>Tiempo de actividad</dt>
                    <dd data-metric="uptime.formatted" data-format="text">{{ $cpuInfo['uptime_formatted'] ?? 'No disponible' }}</dd>
                </div>
                <div class="metric-card">
                    <dt>Uptime en segundos</dt>
                    <dd data-metric="uptime.seconds" data-format="number">{{ $cpuInfo['uptime_seconds'] ?? 'No disponible' }}</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="cpu-load-title">
            <h2 id="cpu-load-title">Uso de CPU y carga promedio</h2>
            <dl class="metric-grid">
                <div class="metric-card cpu-usage">
                    <dt>Uso de CPU</dt>
                    <dd data-metric="cpu.usage_percent" data-format="percent">{{ $cpuUsagePercent === null ? 'No disponible' : number_format($cpuUsagePercent, 1, '.', '').' %' }}</dd>
                </div>
                @foreach (['one_minute' => 'Carga promedio (1 minuto)', 'five_minutes' => 'Carga promedio (5 minutos)', 'fifteen_minutes' => 'Carga promedio (15 minutos)'] as $key => $label)
                    <div class="metric-card">
                        <dt>{{ $label }}</dt>
                        <dd data-metric="load_average.{{ $key }}" data-format="load">{{ isset($cpuInfo['load_average'][$key]) ? number_format($cpuInfo['load_average'][$key], 2, '.', '') : 'No disponible' }}</dd>
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
                            <dd data-metric="{{ str_replace(['memory_', 'swap_'], ['memory.', 'swap.'], $key) }}" data-format="bytes">{{ isset($memoryInfo[$key]) ? number_format($memoryInfo[$key] / 1048576, 2, '.', '').' MiB' : 'No disponible' }}</dd>
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
                            <dd data-metric="{{ str_replace(['memory_', 'swap_'], ['memory.', 'swap.'], $key) }}" data-format="bytes">{{ isset($memoryInfo[$key]) ? number_format($memoryInfo[$key] / 1048576, 2, '.', '').' MiB' : 'No disponible' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>

        <section aria-labelledby="charts-title">
            <h2 id="charts-title">CPU y memoria en el tiempo</h2>
            <p class="description">Hasta 60 lecturas recientes. Escala de 0 a 100 %, con horas locales.</p>
            <div class="panel-grid">
                @foreach (['cpu' => 'Uso de CPU', 'memory' => 'Memoria utilizada'] as $kind => $title)
                    <figure class="chart-card chart-{{ $kind }}">
                        <figcaption>{{ $title }} (%)</figcaption>
                        <svg viewBox="0 0 640 235" role="img" aria-labelledby="{{ $kind }}-chart-title {{ $kind }}-chart-description" aria-describedby="{{ $kind }}-chart-recent">
                            <title id="{{ $kind }}-chart-title">{{ $title }} en el tiempo</title>
                            <desc id="{{ $kind }}-chart-description">Lecturas correctas del panel. Eje vertical de 0 a 100 %. Eje horizontal con horas locales; los datos no disponibles dejan huecos.</desc>
                            <g class="chart-grid" aria-hidden="true">
                                <line x1="50" y1="20" x2="590" y2="20" />
                                <line x1="50" y1="100" x2="590" y2="100" />
                                <line x1="50" y1="180" x2="590" y2="180" />
                            </g>
                            <g class="chart-axis" aria-hidden="true">
                                <text x="44" y="25" text-anchor="end">100 %</text>
                                <text x="44" y="105" text-anchor="end">50 %</text>
                                <text x="44" y="185" text-anchor="end">0 %</text>
                                <text id="{{ $kind }}-chart-start" x="50" y="210">—</text>
                                <text id="{{ $kind }}-chart-end" x="590" y="210" text-anchor="end">—</text>
                            </g>
                            <g id="{{ $kind }}-chart-plot" aria-hidden="true"></g>
                        </svg>
                        <p id="{{ $kind }}-chart-recent" class="chart-recent">Sin muestras todavía. Las lecturas aparecerán al actualizar el panel.</p>
                    </figure>
                @endforeach
            </div>
            <noscript>Las gráficas requieren JavaScript; las métricas actuales se muestran en las tarjetas.</noscript>
        </section>

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
                            <tbody data-ranking="{{ $ranking['metric'] === 'cpu_percent' ? 'cpu' : 'memory' }}">
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
    
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/cpu-memory.js') }}" defer></script>
@endsection
