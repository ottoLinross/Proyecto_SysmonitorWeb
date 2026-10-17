<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeadlockScenarioRequest;
use App\Models\DeadlockSimulation;
use App\Services\Deadlock\BankerSafetyAnalyzer;
use App\Services\Deadlock\DeadlockSimulationStore;
use App\Services\Deadlock\ResourceCycleDetector;
use App\Services\Deadlock\ResourceGraphBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeadlockHistoryController extends Controller
{
    public function store(DeadlockScenarioRequest $request, DeadlockSimulationStore $store): RedirectResponse|Response
    {
        try {
            $simulation = $store->save($request->scenarioInput());
        } catch (QueryException) {
            return $this->message('No se pudo guardar la simulación. El almacenamiento de M4 no está disponible.', 503);
        }

        return redirect()->route('deadlocks.history.show', $simulation->id)
            ->with('deadlockSaved', 'Simulación educativa guardada correctamente.');
    }

    public function index(): View|Response
    {
        try {
            $simulations = DeadlockSimulation::query()->select(['id', 'n', 'm', 'created_at'])
                ->orderByDesc('id')->paginate(20);
        } catch (QueryException) {
            return $this->message('El historial de simulaciones de M4 no está disponible.', 503);
        }

        return view('deadlocks.history', compact('simulations'));
    }

    public function show(string $id, DeadlockSimulationStore $store, BankerSafetyAnalyzer $analyzer, ResourceGraphBuilder $graphs, ResourceCycleDetector $cycles): View|Response
    {
        // Canonical positive SQLite signed-64-bit identifiers, checked before any query.
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/', $id)
            || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            return $this->message('La simulación solicitada no existe.', 404);
        }

        try {
            $savedSimulation = DeadlockSimulation::query()->find($id);
        } catch (QueryException) {
            return $this->message('El almacenamiento de simulaciones de M4 no está disponible.', 503);
        }
        if ($savedSimulation === null) {
            return $this->message('La simulación solicitada no existe.', 404);
        }

        try {
            $scenario = $store->reconstruct($savedSimulation);
        } catch (ValidationException) {
            return $this->message('La simulación guardada contiene un escenario inválido y no puede analizarse.', 422);
        }
        $safety = $analyzer->analyze($scenario);
        $graph = $graphs->build($scenario);

        return view('deadlocks.index', [
            'savedSimulation' => $savedSimulation,
            'scenario' => $scenario,
            'need' => $safety->need,
            'safety' => $safety,
            'graph' => $graph,
            'cycleResult' => $cycles->detect($graph),
        ]);
    }

    private function message(string $message, int $status): Response
    {
        return response()->view('deadlocks.history-message', compact('message'), $status);
    }
}
