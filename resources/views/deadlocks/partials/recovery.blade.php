<form method="POST" action="{{ route('deadlocks.recovery') }}" aria-labelledby="recovery-title">
    @csrf
    <h2 id="recovery-title">Recuperación conceptual — Simulación educativa</h2>
    <p>Opciones para explorar, no acciones ejecutadas ni recomendaciones obligatorias. No se envían señales ni se actúa sobre procesos o recursos reales.</p>
    <p>Un estado inseguro significa que el banquero no encontró una secuencia segura. Un ciclo es un recorrido cerrado; con múltiples instancias no demuestra por sí solo un interbloqueo. Este módulo no confirma interbloqueos ni exige recuperación.</p>
    <p>Cada ensayo parte del escenario original. La copia de recuperación no incorpora concesiones simuladas de Request ni modifica su evaluación, el grafo o los ciclos originales.</p>
    <input type="hidden" name="n" value="{{ $scenario->n }}">
    <input type="hidden" name="m" value="{{ $scenario->m }}">
    <input type="hidden" name="allocation" value="{{ json_encode($scenario->allocation) }}">
    <input type="hidden" name="max" value="{{ json_encode($scenario->max) }}">
    <input type="hidden" name="available" value="{{ json_encode($scenario->available) }}">
    @isset($requestResult)
        <input type="hidden" name="process" value="{{ $requestResult->request->process + 1 }}">
        <input type="hidden" name="request" value="{{ json_encode($requestResult->request->vector) }}">
    @endisset
    <label for="recovery-strategy">Estrategia educativa</label>
    <select id="recovery-strategy" name="strategy" required>
        <option value="terminate" @selected(($recoveryValues['strategy'] ?? '') === 'terminate')>Finalización simulada del proceso seleccionado y liberación de sus recursos</option>
        <option value="victim" @selected(($recoveryValues['strategy'] ?? '') === 'victim')>Selección automática de víctima y finalización simulada</option>
        <option value="preemption" @selected(($recoveryValues['strategy'] ?? '') === 'preemption')>Explorar apropiación o retiro de recursos (solo explicación)</option>
    </select>
    <label for="recovery-process">Proceso para finalización manual (solo se usa en esa estrategia)</label>
    <select id="recovery-process" name="recovery_process">
        @for ($i = 1; $i <= $scenario->n; $i++)
            <option value="{{ $i }}" @selected($display($recoveryValues['recovery_process'] ?? '1') === (string) $i)>P{{ $i }}</option>
        @endfor
    </select>
    <p>Víctima automática: mayor suma de unidades en Allocation; en empate, menor número de proceso. Se ignora el selector manual. No compara costos, prioridades ni tipos de recurso; no garantiza resolver la inseguridad y podría perjudicar un proceso útil.</p>
    <p>Finalizar libera todas las unidades asignadas y elimina la demanda del proceso retirado. Puede facilitar el avance de otros procesos, pero conceptualmente pierde su trabajo y podría requerir reinicio o compensación. No garantiza un estado seguro.</p>
    <button type="submit">Explorar estrategia en una copia temporal</button>
</form>
@isset($recoveryResult)
    <section aria-labelledby="recovery-result-title">
        <h2 id="recovery-result-title">Resultado educativo de recuperación</h2>
        @if ($recoveryResult->simulated === null)
            <h3>Apropiación o retiro de recursos: alternativa conceptual</h3>
            <p>Podría permitir que otros procesos avancen sin finalizar al propietario. Requiere recursos apropiables, puntos de control, restauración o rollback y conservación de la consistencia.</p>
            <p>No todos los recursos admiten retiro seguro: una operación de E/S o una actualización parcial podría no poder deshacerse. Puede perder trabajo, causar inanición por retiros repetidos o exigir compensaciones.</p>
            <p>Este escenario no describe esas propiedades. No se retira ninguna unidad, no se genera un estado temporal ni se afirma una mejora de seguridad.</p>
        @else
            <p>Estrategia: {{ $recoveryResult->strategy === 'victim' ? 'Selección automática de víctima' : 'Finalización manual simulada' }}.</p>
            <p>Proceso retirado conceptualmente: P{{ $recoveryResult->victim + 1 }}. No se incluye entre los procesos pendientes ni en la secuencia temporal.</p>
            <p>Unidades que se liberarían, en orden R1 a R{{ $scenario->m }}: <code>{{ json_encode($recoveryResult->released) }}</code>.</p>
            <p>Filas temporales en orden de procesos originales:
                {{ $recoveryResult->activeProcesses === [] ? 'ninguna; no quedan procesos activos' : implode(', ', array_map(static fn ($i) => 'P'.($i + 1), $recoveryResult->activeProcesses)) }}.
                Las columnas conservan el orden de recursos original.
            </p>
            <h3>Allocation temporal de procesos activos</h3>
            <pre>{{ json_encode($recoveryResult->simulated->allocation, JSON_PRETTY_PRINT) }}</pre>
            <h3>Max temporal de procesos activos</h3>
            <pre>{{ json_encode($recoveryResult->simulated->max, JSON_PRETTY_PRINT) }}</pre>
            <h3>Available temporal</h3>
            <pre>{{ json_encode($recoveryResult->simulated->available, JSON_PRETTY_PRINT) }}</pre>
            <h3>Need temporal de procesos activos</h3>
            <pre>{{ json_encode($recoveryResult->safety->need, JSON_PRETTY_PRINT) }}</pre>
            @if ($recoveryResult->safety->isSafe)
                <p>Estado temporal seguro según el banquero.</p>
                @if ($recoveryResult->activeProcesses === [])
                    <p>No quedan procesos activos; la secuencia temporal está vacía.</p>
                @else
                    <p>Secuencia segura temporal: {{ implode(' → ', array_map(static fn ($i) => 'P'.($recoveryResult->activeProcesses[$i] + 1), $recoveryResult->safety->safeSequence)) }}.</p>
                @endif
            @else
                <p>Estado temporal inseguro según el banquero. Esta estrategia no produjo un estado seguro.</p>
                <p>Pendientes temporales: {{ implode(', ', array_map(static fn ($i) => 'P'.($recoveryResult->activeProcesses[$i] + 1), $recoveryResult->safety->unfinished)) }}.</p>
            @endif
            <p>Resultado exclusivo de esta copia: no confirma un interbloqueo original ni garantiza seguridad para otros escenarios o futuras solicitudes. No se guarda ni se aplica al sistema operativo.</p>
        @endif
    </section>
@endisset
