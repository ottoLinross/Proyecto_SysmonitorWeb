<div aria-labelledby="cycle-title">
    <h3 id="cycle-title">Detección de ciclos dirigidos</h3>
    <p>Se consideran únicamente aristas Allocation y Request con cantidad positiva. Need es necesidad potencial y queda excluida.</p>
    <p>El análisis utiliza el escenario original y el vector explícito enviado, como instantánea educativa anterior a su evaluación. No afirma que la solicitud siga activa o pendiente después de evaluarla, ni modifica la decisión del banquero.</p>
    @if ($cycleResult->status === \App\Services\Deadlock\ResourceCycleResult::NO_REQUEST)
        <p>No existe una solicitud explícita activa para analizar en esta instantánea.</p>
    @elseif ($cycleResult->status === \App\Services\Deadlock\ResourceCycleResult::CYCLE)
        <p>Se detectó al menos un ciclo.</p>
        <p>Recorrido cerrado del ciclo: {{ implode(' → ', $cycleResult->path) }}</p>
    @else
        <p>No se detectaron ciclos.</p>
    @endif
    <p>Un ciclo con múltiples instancias de recursos no demuestra por sí solo un interbloqueo. La ausencia de ciclos en esta instantánea tampoco sustituye el análisis de seguridad del banquero.</p>
</div>
