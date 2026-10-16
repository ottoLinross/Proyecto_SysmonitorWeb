<form method="POST" action="{{ route('deadlocks.history.store') }}" aria-labelledby="save-simulation-title">
    @csrf
    <h2 id="save-simulation-title">Guardar escenario educativo</h2>
    <p>Se guarda únicamente el escenario original: dimensiones, Allocation, Max y Available. No se guardan solicitudes, copias de recuperación ni resultados calculados. Al abrirlo se volverá a validar y analizar.</p>
    <input type="hidden" name="n" value="{{ $scenario->n }}">
    <input type="hidden" name="m" value="{{ $scenario->m }}">
    <input type="hidden" name="allocation" value="{{ json_encode($scenario->allocation) }}">
    <input type="hidden" name="max" value="{{ json_encode($scenario->max) }}">
    <input type="hidden" name="available" value="{{ json_encode($scenario->available) }}">
    <button type="submit">Guardar una nueva simulación</button>
</form>
