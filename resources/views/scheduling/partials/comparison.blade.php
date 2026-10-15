<section aria-labelledby="comparison-title">
    <h2 id="comparison-title">Comparación de algoritmos</h2>
    <p>Los cinco algoritmos utilizan el mismo lote y orden de entrada. Round Robin utiliza quantum {{ $comparison['quantum'] }}.</p>
    <p>Prioridades: menor número = mayor prioridad, sin expropiación. Promedios en unidades de tiempo; las fracciones conservan los valores exactos.</p>
    <div class="table-container">
        <table id="scheduling-comparison">
            <caption>Promedios de espera y retorno del mismo conjunto de procesos</caption>
            <thead>
                <tr><th scope="col">Algoritmo</th><th scope="col">Espera promedio</th><th scope="col">Retorno promedio</th><th scope="col">Valores exactos (espera; retorno)</th></tr>
            </thead>
            <tbody>
                @foreach ($comparison['results'] as $result)
                    <tr data-algorithm="{{ $result['algorithm'] }}">
                        <th scope="row">{{ $result['label'] }}</th>
                        <td>{{ $result['metrics']['average_waiting_time'] }}</td>
                        <td>{{ $result['metrics']['average_turnaround_time'] }}</td>
                        <td>
                            @if ($result['metrics']['process_count'] > 0)
                                {{ $result['metrics']['total_waiting_time'] }}/{{ $result['metrics']['process_count'] }};
                                {{ $result['metrics']['total_turnaround_time'] }}/{{ $result['metrics']['process_count'] }}
                            @else
                                0; 0
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
