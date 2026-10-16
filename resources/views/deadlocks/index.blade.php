<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Interbloqueos — SysMonitor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f5f8; color: #182332; font-family: system-ui, sans-serif; }
        main { max-width: 1440px; margin: 0 auto; padding: 32px 20px; }
        h1 { margin: 0 0 12px; font-size: 1.8rem; }
        p { color: #526173; line-height: 1.5; }
        section, form { margin-top: 24px; padding: 20px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        label { display: block; margin-top: 16px; font-weight: 600; }
        input, textarea, select, button { max-width: 100%; padding: 8px; font: inherit; }
        textarea { display: block; width: 100%; font-family: monospace; }
        button { margin-top: 20px; cursor: pointer; }
        .errors { color: #991b1b; }
        pre { overflow-x: auto; }
        .table-container { overflow-x: auto; }
        .table-container:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        caption { padding: 12px 0; text-align: left; font-weight: 600; }
        th, td { padding: 12px; border-bottom: 1px solid #cbd5e1; vertical-align: top; }
    </style>
</head>
<body>
    <main>
        <header>
            <p>SysMonitor · M4</p>
            <h1>Interbloqueos</h1>
            <p>Estructura inicial del módulo de interbloqueos.</p>
        </header>
        @php
            $limits = \App\Services\Deadlock\ScenarioInput::class;
            $display = static function ($value) {
                return is_scalar($value) ? (string) $value : json_encode($value);
            };
        @endphp
        @if ($errors->any())
            <section class="errors" role="alert" aria-labelledby="errors-title">
                <h2 id="errors-title">Revisa el escenario</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
        <form method="POST" action="{{ route('deadlocks.validate') }}" aria-label="Escenario de interbloqueos">
            @csrf
            <p>Ingresa matrices como listas JSON: una lista por fila. Available es una sola lista.
                Las filas representan procesos y las columnas recursos, en el orden ingresado.</p>
            <p>De 1 a {{ $limits::MAX_PROCESSES }} procesos y de 1 a {{ $limits::MAX_RESOURCES }} recursos.
                Valores enteros de 0 a {{ $limits::MAX_VALUE }}, sin signos ni ceros iniciales.
                Límite del escenario: 32 KiB.</p>
            <label for="n">n — Número de procesos</label>
            <input id="n" name="n" type="text" inputmode="numeric" value="{{ $display(old('n', $scenario->n ?? '')) }}" required>
            <label for="m">m — Número de tipos de recursos</label>
            <input id="m" name="m" type="text" inputmode="numeric" value="{{ $display(old('m', $scenario->m ?? '')) }}" required>
            <label for="allocation">Allocation — Matriz de asignación</label>
            <textarea id="allocation" name="allocation" rows="5" placeholder="[[1,0],[0,1]]" required>{{ $display(old('allocation', $scenario->allocation ?? '')) }}</textarea>
            <label for="max">Max — Matriz de recursos máximos</label>
            <textarea id="max" name="max" rows="5" placeholder="[[2,1],[1,2]]" required>{{ $display(old('max', $scenario->max ?? '')) }}</textarea>
            <label for="available">Available — Vector de recursos disponibles</label>
            <textarea id="available" name="available" rows="2" placeholder="[1,1]" required>{{ $display(old('available', $scenario->available ?? '')) }}</textarea>
            <button type="submit">Validar escenario</button>
        </form>
        @isset($scenario)
            <section aria-labelledby="scenario-title">
                <h2 id="scenario-title">Escenario válido</h2>
                <p>Procesos: {{ $scenario->n }}. Tipos de recursos: {{ $scenario->m }}.</p>
                <h3>Allocation</h3>
                <pre>{{ json_encode($scenario->allocation, JSON_PRETTY_PRINT) }}</pre>
                <h3>Max</h3>
                <pre>{{ json_encode($scenario->max, JSON_PRETTY_PRINT) }}</pre>
                <h3>Available</h3>
                <pre>{{ json_encode($scenario->available, JSON_PRETTY_PRINT) }}</pre>
                <h3>Need — Matriz de necesidad</h3>
                <p>Need[i][j] = Max[i][j] - Allocation[i][j]</p>
                <pre>{{ json_encode($need, JSON_PRETTY_PRINT) }}</pre>
                <h3>Análisis de seguridad</h3>
                <p>Los procesos se identifican por su posición original, desde P1.</p>
                @if ($safety->isSafe)
                    <p>Estado seguro</p>
                    <p>Secuencia segura:
                        {{ implode(' → ', array_map(static fn ($i) => 'P'.($i + 1), $safety->safeSequence)) }}
                    </p>
                @else
                    <p>Estado inseguro</p>
                    <p>Procesos que no pudieron finalizar:
                        {{ implode(', ', array_map(static fn ($i) => 'P'.($i + 1), $safety->unfinished)) }}
                    </p>
                    <p>Un estado inseguro no confirma por sí solo la existencia de un interbloqueo.</p>
                @endif
                <h3 id="steps-title">Ejecución paso a paso</h3>
                <p>Los vectores conservan el orden original de los recursos. Finish muestra el estado de P1 a P{{ $scenario->n }}: true significa finalizado y false, pendiente.</p>
                @if ($safety->steps === [])
                    <p>Ningún proceso pudo ejecutarse; no hay pasos registrados.</p>
                @else
                    @unless ($safety->isSafe)
                        <p>Se muestran únicamente los pasos ejecutados antes de detenerse; no constituyen una secuencia segura completa.</p>
                    @endunless
                    <div class="table-container" role="region" aria-labelledby="steps-title" tabindex="0">
                        <table>
                            <caption>Pasos reales del análisis de seguridad</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Paso</th>
                                    <th scope="col">Proceso</th>
                                    <th scope="col">Work antes</th>
                                    <th scope="col">Need del proceso</th>
                                    <th scope="col">Allocation del proceso</th>
                                    <th scope="col">Work después</th>
                                    <th scope="col">Finish actualizado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($safety->steps as $step)
                                    <tr>
                                        <th scope="row">{{ $step->number }}</th>
                                        <td>P{{ $step->process + 1 }}</td>
                                        <td><code>{{ json_encode($step->workBefore) }}</code></td>
                                        <td><code>{{ json_encode($step->need) }}</code></td>
                                        <td><code>{{ json_encode($step->allocation) }}</code></td>
                                        <td><code>{{ json_encode($step->workAfter) }}</code></td>
                                        <td><code>{{ json_encode($step->finish) }}</code></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            @include('deadlocks.partials.resource-graph')
            <form method="POST" action="{{ route('deadlocks.request') }}" aria-label="Solicitud adicional de recursos">
                @csrf
                <h2>Evaluar solicitud de recursos</h2>
                <p>Simulación educativa: no reserva ni asigna recursos reales del servidor. Cada solicitud se evalúa sobre el escenario original mostrado arriba.</p>
                <input type="hidden" name="n" value="{{ $scenario->n }}">
                <input type="hidden" name="m" value="{{ $scenario->m }}">
                <input type="hidden" name="allocation" value="{{ json_encode($scenario->allocation) }}">
                <input type="hidden" name="max" value="{{ json_encode($scenario->max) }}">
                <input type="hidden" name="available" value="{{ json_encode($scenario->available) }}">
                <label for="process">Proceso existente</label>
                <select id="process" name="process" required>
                    @for ($i = 1; $i <= $scenario->n; $i++)
                        <option value="{{ $i }}" @selected($display($requestValues['process'] ?? '1') === (string) $i)>P{{ $i }}</option>
                    @endfor
                </select>
                <label for="request">Request — Vector solicitado ({{ $scenario->m }} enteros)</label>
                <textarea id="request" name="request" rows="2" required>{{ $display($requestValues['request'] ?? '') }}</textarea>
                <p>Una lista JSON con {{ $scenario->m }} valores entre 0 y {{ $limits::MAX_VALUE }}. Se aplican los mismos límites del escenario.</p>
                <button type="submit">Evaluar solicitud</button>
            </form>
            @if ($requestInvalid ?? false)
                <section aria-label="Resultado de solicitud inválida">
                    <h2>Solicitud inválida</h2>
                    <p>Proceso recibido: {{ $display($requestValues['process'] ?? '') }}.</p>
                    <p>Request recibido: {{ $display($requestValues['request'] ?? '') }}.</p>
                    <p>Corrige los errores indicados. El escenario original se conserva.</p>
                </section>
            @endif
            @isset($requestResult)
                <section aria-labelledby="request-result-title">
                    <h2 id="request-result-title">Resultado de la solicitud</h2>
                    <p>Proceso: P{{ $requestResult->request->process + 1 }}.</p>
                    <p>Request: <code>{{ json_encode($requestResult->request->vector) }}</code></p>
                    <p>{{ $requestResult->explanation }}</p>
                    @if ($requestResult->simulated !== null)
                        <h3>Estado simulado concedido</h3>
                        <p>Estos datos son una copia temporal; no reemplazan el escenario original.</p>
                        <h4>Allocation simulada</h4>
                        <pre>{{ json_encode($requestResult->simulated->allocation, JSON_PRETTY_PRINT) }}</pre>
                        <h4>Max simulada</h4>
                        <pre>{{ json_encode($requestResult->simulated->max, JSON_PRETTY_PRINT) }}</pre>
                        <h4>Available simulado</h4>
                        <pre>{{ json_encode($requestResult->simulated->available, JSON_PRETTY_PRINT) }}</pre>
                        <h4>Need simulada</h4>
                        <pre>{{ json_encode($requestResult->safety->need, JSON_PRETTY_PRINT) }}</pre>
                    @endif
                </section>
            @endisset
            @include('deadlocks.partials.recovery')
        @endisset
    </main>
</body>
</html>
