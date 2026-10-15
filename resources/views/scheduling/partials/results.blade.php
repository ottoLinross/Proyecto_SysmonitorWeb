@php($resultPrefix = isset($resultKey) ? 'saved-'.$resultKey.'-' : '')
<section aria-labelledby="{{ $resultPrefix }}metrics-title">
    <h2 id="{{ $resultPrefix }}metrics-title">Métricas — {{ $simulation['label'] }}</h2>
    @if ($simulation['quantum'] !== null)
        <p>Quantum: {{ $simulation['quantum'] }}</p>
    @endif
    <div class="table-container">
        <table>
            <caption>Métricas por proceso (unidades de tiempo)</caption>
            <thead><tr><th>Proceso</th><th>Finalización</th><th>Retorno</th><th>Espera</th></tr></thead>
            <tbody>
                @foreach ($simulation['metrics']['processes'] as $metric)
                    <tr data-process-id="{{ $metric['id'] }}"><td>{{ $metric['id'] }}</td><td>{{ $metric['completion_time'] }}</td><td>{{ $metric['turnaround_time'] }}</td><td>{{ $metric['waiting_time'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p>Espera promedio: {{ $simulation['metrics']['average_waiting_time'] }}</p>
    <p>Retorno promedio: {{ $simulation['metrics']['average_turnaround_time'] }}</p>
    @if ($simulation['metrics']['process_count'] > 0)
        <p>Valores exactos: espera {{ $simulation['metrics']['total_waiting_time'] }}/{{ $simulation['metrics']['process_count'] }};
            retorno {{ $simulation['metrics']['total_turnaround_time'] }}/{{ $simulation['metrics']['process_count'] }}.</p>
    @endif
</section>

<section aria-labelledby="{{ $resultPrefix }}gantt-title">
    <h2 id="{{ $resultPrefix }}gantt-title">Diagrama de Gantt — {{ $simulation['label'] }}</h2>
    <p>Intervalos [inicio, fin). CPU ociosa se identifica como idle. Los detalles completos de cada segmento aparecen en la tabla.</p>
    <div class="table-container" role="region" aria-label="Línea de tiempo de CPU" tabindex="0">
        <ol class="gantt-track">
            @foreach ($simulation['segments'] as $segment)
                <li class="gantt-segment {{ $segment['type'] === 'idle' ? 'gantt-idle' : '' }}"
                    style="flex-grow: {{ $segment['end_time'] - $segment['start_time'] }}"
                    title="{{ $segment['type'] === 'idle' ? 'CPU ociosa (idle)' : $segment['process_id'] }}: {{ $segment['start_time'] }}–{{ $segment['end_time'] }}">
                    <span>{{ $segment['type'] === 'idle' ? 'idle' : $segment['process_id'] }}</span>
                    <span>{{ $segment['start_time'] }}–{{ $segment['end_time'] }}</span>
                </li>
            @endforeach
        </ol>
    </div>
    <div class="table-container">
        <table id="{{ $resultPrefix }}gantt-segments">
            <caption>Segmentos de ejecución (unidades de tiempo)</caption>
            <thead><tr><th>Proceso / CPU ociosa</th><th>Inicio</th><th>Fin</th></tr></thead>
            <tbody>
                @foreach ($simulation['segments'] as $segment)
                    <tr data-segment-type="{{ $segment['type'] }}" data-start="{{ $segment['start_time'] }}" data-end="{{ $segment['end_time'] }}">
                        <td>{{ $segment['type'] === 'idle' ? 'CPU ociosa (idle)' : $segment['process_id'] }}</td><td>{{ $segment['start_time'] }}</td><td>{{ $segment['end_time'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
