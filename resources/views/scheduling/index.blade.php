@extends('layouts.sysmonitor')

@section('title', 'Planificación de CPU')

@section('styles')
    <style>.module-scheduling *{ box-sizing: border-box; }.module-scheduling{ margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }.module-scheduling{ max-width: 1440px; margin: 0 auto; padding: 32px 20px; }.module-scheduling h1{ margin: 0 0 12px; font-size: 1.8rem; }.module-scheduling p{ color: #526173; line-height: 1.5; }.module-scheduling section{ margin-top: 24px; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }.module-scheduling .table-container{ overflow-x: auto; }.module-scheduling table{ width: 100%; border-collapse: collapse; text-align: left; }.module-scheduling th, .module-scheduling td{ padding: 8px; border-bottom: 1px solid #e2e8f0; }.module-scheduling input, .module-scheduling button{ padding: 8px; font: inherit; }.module-scheduling input[type="number"]{ width: 110px; }.module-scheduling button{ margin-top: 12px; cursor: pointer; }.module-scheduling .errors{ color: #991b1b; }.module-scheduling .gantt-track{ display: flex; list-style: none; padding: 0; margin: 16px 0; min-width: 800px; }.module-scheduling .gantt-segment{ flex-basis: 0; min-width: 0; padding: 12px 0; border: 1px solid #fff; background: #dbeafe; overflow: hidden; text-align: center; }.module-scheduling .gantt-segment span{ display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.module-scheduling .gantt-idle{ background: #e2e8f0; }
        </style>
@endsection

@section('content')
    <div class="module-page module-scheduling">

            <header>
            <p>SysMonitor · M3</p>
            <h1>Planificación de CPU</h1>
            <p>Estructura inicial del módulo de planificación de CPU.</p>
            <nav aria-label="Navegación de planificación">
                <a href="{{ route('scheduling.index') }}">Nueva simulación</a> ·
                <a href="{{ route('scheduling.history') }}">Historial de simulaciones</a>
            </nav>
        </header>
        @isset($savedRun)
            <section aria-labelledby="saved-title">
                <h2 id="saved-title">Simulación guardada #{{ $savedRun->id }}</h2>
                <p>Fecha: <time datetime="{{ $savedRun->created_at->toIso8601String() }}">{{ $savedRun->created_at->copy()->timezone('America/Guatemala')->format('Y-m-d H:i:s') }}</time> (Guatemala).</p>
                <a href="{{ route('scheduling.history.show', $savedRun->id) }}">Consultar detalle guardado</a>
                @if ($isHistoryDetail ?? false)
                    <p>Se muestran los resultados almacenados, sin recalcular. Simular o comparar este lote crea un registro nuevo.</p>
                @endif
            </section>
        @endisset
        @isset($saveError)
            <p class="errors" role="alert">{{ $saveError }}</p>
        @endisset
        @php
            $limits = \App\Services\Scheduling\ProcessInput::class;
            $rows = old('processes', [['id' => '', 'arrival_time' => '', 'burst_time' => '', 'priority' => '']]);
            $rows = is_array($rows) ? array_slice(array_values($rows), 0, $limits::MAX_PROCESSES) : [];
            $rows = $rows ?: [['id' => '', 'arrival_time' => '', 'burst_time' => '', 'priority' => '']];
        @endphp

        @if ($errors->any())
            <div class="errors" role="alert">
                <p>Revisa los datos ingresados.</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-labelledby="manual-title">
            <h2 id="manual-title">Entrada manual</h2>
            <p>De 1 a {{ $limits::MAX_PROCESSES }} procesos. Identificador único de hasta {{ $limits::MAX_ID_LENGTH }} caracteres.
                Llegada: 0–{{ $limits::MAX_ARRIVAL }}. Ráfaga: 1–{{ $limits::MAX_BURST }}. Prioridad: {{ $limits::MIN_PRIORITY }}–{{ $limits::MAX_PRIORITY }}. Todos los valores numéricos deben ser enteros.</p>
            <form method="POST" action="{{ route('scheduling.manual') }}">
                @csrf
                <div class="table-container">
                    <table>
                        <thead><tr><th>Identificador/nombre</th><th>Llegada</th><th>Ráfaga</th><th>Prioridad</th><th>Acciones</th></tr></thead>
                        <tbody id="process-inputs">
                            @foreach ($rows as $index => $row)
                                <tr>
                                    <td><input aria-label="Identificador del proceso {{ $index + 1 }}" name="processes[{{ $index }}][id]" maxlength="{{ $limits::MAX_ID_LENGTH }}" value="{{ is_string($row['id'] ?? null) ? $row['id'] : '' }}" required></td>
                                    @foreach (['arrival_time' => [0, $limits::MAX_ARRIVAL, 'Llegada'], 'burst_time' => [1, $limits::MAX_BURST, 'Ráfaga'], 'priority' => [$limits::MIN_PRIORITY, $limits::MAX_PRIORITY, 'Prioridad']] as $field => [$min, $max, $label])
                                        <td><input type="number" aria-label="{{ $label }} del proceso {{ $index + 1 }}" name="processes[{{ $index }}][{{ $field }}]" min="{{ $min }}" max="{{ $max }}" step="1" value="{{ is_scalar($row[$field] ?? null) ? $row[$field] : '' }}" required></td>
                                    @endforeach
                                    <td><button type="button" data-remove>Quitar</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="button" id="add-process">Agregar proceso</button>
                <button type="submit">Validar procesos</button>
                <noscript>Activa JavaScript para agregar o quitar filas. Puedes validar la fila inicial.</noscript>
            </form>
        </section>

        <section aria-labelledby="random-title">
            <h2 id="random-title">Generación aleatoria</h2>
            <form method="POST" action="{{ route('scheduling.random') }}">
                @csrf
                <label for="count">Cantidad de procesos (1–{{ $limits::MAX_PROCESSES }})</label>
                <input id="count" name="count" type="number" min="1" max="{{ $limits::MAX_PROCESSES }}" step="1" value="{{ is_scalar(old('count', 1)) ? old('count', 1) : 1 }}" required>
                <button type="submit">Generar procesos</button>
            </form>
        </section>

        @isset($processes)
            <section aria-labelledby="results-title">
                <h2 id="results-title">Procesos de entrada validados</h2>
                <p>La simulación o comparación se guarda al ejecutarla. Selecciona un algoritmo para simular el lote.</p>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Identificador/nombre</th><th>Llegada</th><th>Ráfaga</th><th>Prioridad</th></tr></thead>
                        <tbody>
                            @foreach ($processes as $process)
                                <tr><td>{{ $process->id }}</td><td>{{ $process->arrivalTime }}</td><td>{{ $process->burstTime }}</td><td>{{ $process->priority }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="{{ route('scheduling.simulate') }}">
                    @csrf
                    @foreach ($processes as $index => $process)
                        <input type="hidden" name="processes[{{ $index }}][id]" value="{{ $process->id }}">
                        <input type="hidden" name="processes[{{ $index }}][arrival_time]" value="{{ $process->arrivalTime }}">
                        <input type="hidden" name="processes[{{ $index }}][burst_time]" value="{{ $process->burstTime }}">
                        <input type="hidden" name="processes[{{ $index }}][priority]" value="{{ $process->priority }}">
                    @endforeach
                    <label for="algorithm">Algoritmo</label>
                    <select id="algorithm" name="algorithm" required>
                        @foreach (\App\Services\Scheduling\SchedulingSimulation::ALGORITHMS as $key => $label)
                            <option value="{{ $key }}" @selected(($simulation['algorithm'] ?? 'fcfs') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label for="quantum">Quantum entero positivo (solo Round Robin)</label>
                    <input id="quantum" name="quantum" type="number" min="1" step="1" value="{{ $comparison['quantum'] ?? $simulation['quantum'] ?? 2 }}">
                    <p>Prioridades: menor número = mayor prioridad. Variante no expropiativa.</p>
                    <button type="submit">Simular lote</button>
                    <button type="submit" formaction="{{ route('scheduling.compare') }}">Comparar cinco algoritmos</button>
                </form>
            </section>
        @endisset
        @isset($simulation)
            @include('scheduling.partials.results')
        @endisset
        @isset($comparison)
            @include('scheduling.partials.comparison')
            @if ($isHistoryDetail ?? false)
                @foreach ($comparison['results'] as $result)
                    <details>
                        <summary>Resultados y Gantt — {{ $result['label'] }}</summary>
                        @include('scheduling.partials.results', ['simulation' => $result, 'resultKey' => $result['algorithm']])
                    </details>
                @endforeach
            @endif
        @endisset
    
    </div>
@endsection

@section('scripts')
    <script>
        const body = document.getElementById('process-inputs');
        const add = document.getElementById('add-process');
        const template = body.firstElementChild?.cloneNode(true);
        function updateRows() {
            [...body.rows].forEach((row, index) => {
                row.querySelectorAll('input').forEach(input => {
                    input.name = input.name.replace(/processes\[\d+\]/, `processes[${index}]`);
                    input.setAttribute('aria-label', input.getAttribute('aria-label').replace(/\d+$/, index + 1));
                });
                row.querySelector('[data-remove]').disabled = body.rows.length === 1;
            });
            add.disabled = body.rows.length >= {{ $limits::MAX_PROCESSES }} || !template;
        }
        add.addEventListener('click', () => {
            if (body.rows.length >= {{ $limits::MAX_PROCESSES }} || !template) return;
            const row = template.cloneNode(true);
            row.querySelectorAll('input').forEach(input => input.value = '');
            body.appendChild(row);
            updateRows();
            row.querySelector('input').focus();
        });
        body.addEventListener('click', event => {
            if (event.target.matches('[data-remove]') && body.rows.length > 1) {
                event.target.closest('tr').remove();
                updateRows();
            }
        });
        updateRows();
    </script>
@endsection
