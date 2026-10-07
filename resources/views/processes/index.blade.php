<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Módulo de Procesos</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        h1 { margin: 0 0 12px; font-size: 1.8rem; }
        .description { margin: 0 0 24px; color: #526173; }
        .table-container { max-height: 70vh; overflow: auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; }
        .table-container:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        table { width: 100%; min-width: 1050px; border-spacing: 0; font-size: 0.9rem; }
        caption { padding: 16px; text-align: left; font-weight: 600; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { position: sticky; top: 0; background: #eaf0f6; white-space: nowrap; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:hover { background: #eff6ff; }
        tbody tr:last-child td { border-bottom: 0; }
        .numeric { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .command { min-width: 320px; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, monospace; }
        .empty { padding: 28px 16px; text-align: center; color: #526173; }
    </style>
</head>
<body>
    <main>
        <h1 id="processes-title">Módulo de Procesos</h1>
        <p class="description">Información actual de los procesos del sistema.</p>
        <div class="table-container" role="region" aria-labelledby="processes-title" tabindex="0">
            <table>
                <caption>Procesos del sistema</caption>
                <thead>
                    <tr>
                        <th scope="col" class="numeric">PID</th>
                        <th scope="col" class="numeric">PPID</th>
                        <th scope="col">Usuario</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="numeric">Nice</th>
                        <th scope="col" class="numeric">CPU %</th>
                        <th scope="col" class="numeric">Memoria %</th>
                        <th scope="col" class="numeric">Memoria RSS (KB)</th>
                        <th scope="col">Comando</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($processes as $process)
                        <tr>
                            <td class="numeric">{{ $process['pid'] }}</td>
                            <td class="numeric">{{ $process['ppid'] }}</td>
                            <td>{{ $process['user'] }}</td>
                            <td>{{ $process['state'] }}</td>
                            <td class="numeric">{{ $process['nice'] }}</td>
                            <td class="numeric">{{ number_format($process['cpu_percent'], 1, '.', '') }}</td>
                            <td class="numeric">{{ number_format($process['memory_percent'], 1, '.', '') }}</td>
                            <td class="numeric">{{ $process['memory_kb'] }}</td>
                            <td class="command">{{ $process['command'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="empty">No hay procesos disponibles para mostrar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
