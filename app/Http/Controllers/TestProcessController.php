<?php

namespace App\Http\Controllers;

use App\Services\System\TestProcessService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class TestProcessController extends Controller
{
    public function store(TestProcessService $service): RedirectResponse
    {
        try {
            $process = $service->launch();
        } catch (Throwable) {
            return redirect()->route('processes.index')
                ->with('test_process_error', 'No se pudo crear el proceso de prueba. Inténtalo nuevamente.');
        }

        return redirect()->route('processes.index')
            ->with('test_process_success', 'Proceso de prueba creado correctamente. PID: '.$process->pid.'.');
    }
}
