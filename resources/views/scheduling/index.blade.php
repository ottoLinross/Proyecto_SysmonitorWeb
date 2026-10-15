<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planificación de CPU — SysMonitor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        h1 { margin: 0 0 12px; font-size: 1.8rem; }
        p { color: #526173; line-height: 1.5; }
        section { margin-top: 24px; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
        input, button { padding: 8px; font: inherit; }
        input[type="number"] { width: 110px; }
        button { margin-top: 12px; cursor: pointer; }
        .errors { color: #991b1b; }
        .gantt-track { display: flex; list-style: none; padding: 0; margin: 16px 0; min-width: 800px; }
        .gantt-segment { flex-basis: 0; min-width: 0; padding: 12px 0; border: 1px solid #fff; background: #dbeafe; overflow: hidden; text-align: center; }
        .gantt-segment span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .gantt-idle { background: #e2e8f0; }
    </style>
</head>
<body>
    <main>
        <header>
            <p>SysMonitor · M3</p>
            <h1>Planificación de CPU</h1>
            <p>Estructura inicial del módulo de planificación de CPU.</p>
        </header>
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
                <p>Estos datos no se guardan. Selecciona un algoritmo para simular el lote.</p>
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
        @endisset
    </main>
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
</body>
</html>
