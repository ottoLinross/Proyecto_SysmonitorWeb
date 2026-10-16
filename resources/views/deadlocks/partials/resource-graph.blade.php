<section aria-labelledby="graph-title">
    <h2 id="graph-title">Grafo de asignación de recursos</h2>
    <p>Simulación educativa del escenario original, presentada como tablas accesibles de nodos y aristas dirigidas.</p>
    <p>Allocation: recurso → proceso, unidades asignadas. Need: proceso → recurso, necesidad potencial (demanda restante máxima), no necesariamente una solicitud activa. Request: proceso → recurso, solicitud explícita evaluada por separado.</p>
    <p>Request muestra el vector enviado, incluso si fue denegado o supera Need; no implica concesión ni una solicitud pendiente después de la evaluación. Consulta el resultado de la solicitud.</p>
    <p>Cada R representa un tipo de recurso con múltiples instancias posibles. Las cantidades son unidades, sin inventar instancias individuales. No se muestran aristas de cantidad cero.</p>
    <p>Un ciclo con múltiples instancias no demuestra por sí solo un interbloqueo. Este grafo no confirma un interbloqueo ni realiza detección de ciclos.</p>
    <div class="table-container" role="region" aria-labelledby="graph-nodes-caption" tabindex="0">
        <table>
            <caption id="graph-nodes-caption">Nodos del grafo</caption>
            <thead><tr><th scope="col">Nodo</th><th scope="col">Tipo</th><th scope="col">Unidades totales</th><th scope="col">Disponibles</th></tr></thead>
            <tbody>
                @foreach ($graph->nodes as $node)
                    <tr><th scope="row">{{ $node['id'] }}</th><td>{{ $node['type'] === 'process' ? 'Proceso' : 'Tipo de recurso' }}</td><td>{{ $node['total'] ?? 'No aplica' }}</td><td>{{ $node['available'] ?? 'No aplica' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($graph->edges === [])
        <p>Sin aristas: todas las cantidades son cero.</p>
    @else
        <div class="table-container" role="region" aria-labelledby="graph-edges-caption" tabindex="0">
            <table>
                <caption id="graph-edges-caption">Aristas dirigidas del grafo (origen → destino)</caption>
                <thead><tr><th scope="col">Origen</th><th scope="col">Destino</th><th scope="col">Concepto</th><th scope="col">Cantidad de unidades</th></tr></thead>
                <tbody>
                    @foreach ($graph->edges as $edge)
                        <tr><td>{{ $edge['source'] }}</td><td>{{ $edge['target'] }}</td><td>{{ ['allocation' => 'Allocation — Asignación', 'need' => 'Need — Necesidad potencial', 'request' => 'Request — Solicitud explícita'][$edge['type']] }}</td><td>{{ $edge['quantity'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
