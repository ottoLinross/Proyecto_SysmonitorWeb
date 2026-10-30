@extends('layouts.sysmonitor')

@section('title', 'Historial de interbloqueos')

@section('content')
    <div class="module-page module-deadlocks">

            <h1>Simulaciones educativas guardadas de M4</h1>
        <p><a href="{{ route('deadlocks.index') }}">Volver al simulador de interbloqueos</a></p>
        <p>Los registros conservan escenarios educativos, no recursos reales. El detalle vuelve a validar y calcular sus resultados.</p>
        @if ($simulations->isEmpty())
            <p>No hay simulaciones guardadas.</p>
        @else
            <div role="region" aria-labelledby="history-caption" tabindex="0" style="overflow-x: auto">
                <table>
                    <caption id="history-caption">Escenarios por identificador descendente, 20 por página</caption>
                    <thead><tr><th scope="col">Registro</th><th scope="col">Procesos</th><th scope="col">Tipos de recursos</th><th scope="col">Fecha de guardado (UTC)</th></tr></thead>
                    <tbody>
                        @foreach ($simulations as $simulation)
                            <tr>
                                <th scope="row"><a href="{{ route('deadlocks.history.show', $simulation->id) }}">Simulación #{{ $simulation->id }}</a></th>
                                <td>{{ $simulation->n }}</td><td>{{ $simulation->m }}</td><td>{{ $simulation->getRawOriginal('created_at') ?? 'No disponible' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $simulations->links() }}
        @endif
    
    </div>
@endsection
