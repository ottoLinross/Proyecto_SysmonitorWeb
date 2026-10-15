<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de planificación — SysMonitor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        a { color: #1d4ed8; }
        .table-container { margin-top: 24px; overflow-x: auto; background: #fff; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
        .errors { color: #991b1b; }
    </style>
</head>
<body>
    <main>
        <h1>Historial de simulaciones</h1>
        <a href="{{ route('scheduling.index') }}">Nueva simulación</a>
        @if ($historyUnavailable ?? false)
            <p class="errors" role="alert">El historial no está disponible temporalmente.</p>
        @elseif ($runs->isEmpty())
            <p>No hay simulaciones guardadas.</p>
        @else
            <div class="table-container">
                <table>
                    <caption>Simulaciones guardadas, más recientes primero</caption>
                    <thead><tr><th scope="col">ID</th><th scope="col">Algoritmo / comparación</th><th scope="col">Quantum</th><th scope="col">Fecha (Guatemala)</th><th scope="col">Consulta</th></tr></thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td>{{ $run->id }}</td>
                                <td>{{ $run->mode === 'comparison' ? 'Comparación de cinco algoritmos' : (\App\Services\Scheduling\SchedulingSimulation::ALGORITHMS[$run->algorithm] ?? $run->algorithm) }}</td>
                                <td>{{ $run->quantum ?? 'No corresponde' }}</td>
                                <td><time datetime="{{ $run->created_at->toIso8601String() }}">{{ $run->created_at->copy()->timezone('America/Guatemala')->format('Y-m-d H:i:s') }}</time></td>
                                <td><a href="{{ route('scheduling.history.show', $run->id) }}">Ver detalle #{{ $run->id }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <nav aria-label="Páginas del historial">
                @if ($runs->previousPageUrl())
                    <a href="{{ $runs->previousPageUrl() }}">Anterior</a>
                @endif
                <span>Página {{ $runs->currentPage() }} de {{ $runs->lastPage() }}</span>
                @if ($runs->nextPageUrl())
                    <a href="{{ $runs->nextPageUrl() }}">Siguiente</a>
                @endif
            </nav>
        @endif
    </main>
</body>
</html>
