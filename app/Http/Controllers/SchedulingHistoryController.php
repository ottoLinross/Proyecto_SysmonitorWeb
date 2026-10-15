<?php

namespace App\Http\Controllers;

use App\Models\SchedulingRun;
use App\Services\Scheduling\SchedulingProcess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SchedulingHistoryController extends Controller
{
    public function index(): View|Response
    {
        try {
            $runs = SchedulingRun::query()->select(['id', 'mode', 'algorithm', 'quantum', 'created_at'])
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

            return view('scheduling.history', ['runs' => $runs]);
        } catch (QueryException) {
            return $this->unavailable();
        }
    }

    public function show(string $id): View|Response
    {
        // The route only accepts digits; this endpoint never updates a record.
        try {
            $savedRun = SchedulingRun::query()->findOrFail($id);
        } catch (QueryException) {
            return $this->unavailable();
        }

        $data = [
            'savedRun' => $savedRun,
            'isHistoryDetail' => true,
            'processes' => array_map(fn (array $process): SchedulingProcess => new SchedulingProcess(
                $process['id'], $process['arrival_time'], $process['burst_time'], $process['priority'],
            ), $savedRun->input_processes),
        ];
        if ($savedRun->mode === 'comparison') {
            $data['comparison'] = ['quantum' => $savedRun->quantum, 'results' => $savedRun->results];
        } else {
            $data['simulation'] = $savedRun->results[0];
        }

        return view('scheduling.index', $data);
    }

    private function unavailable(): Response
    {
        return response()->view('scheduling.history', ['runs' => null, 'historyUnavailable' => true], 503);
    }
}
