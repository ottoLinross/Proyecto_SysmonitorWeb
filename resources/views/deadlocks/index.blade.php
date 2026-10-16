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
        input, textarea, button { max-width: 100%; padding: 8px; font: inherit; }
        textarea { display: block; width: 100%; font-family: monospace; }
        button { margin-top: 20px; cursor: pointer; }
        .errors { color: #991b1b; }
        pre { overflow-x: auto; }
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
            </section>
        @endisset
    </main>
</body>
</html>
