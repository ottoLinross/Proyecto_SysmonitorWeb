<?php

namespace App\Http\Controllers;

use App\Models\ManagedProcess;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class TestProcessController extends Controller
{
    public function signal(Request $request, ManagedProcess $managedProcess, ProcessSignalService $service): RedirectResponse
    {
        $signal = $request->input('signal');
        if (! is_string($signal) || ! array_key_exists($signal, ProcessSignalService::SIGNALS)) {
            return redirect()->route('processes.index')->with('test_process_error', 'Señal no permitida.');
        }

        try {
            $result = $service->send($managedProcess, $signal);
        } catch (Throwable) {
            return redirect()->route('processes.index')->with('test_process_error', 'No se pudo completar la acción del proceso de prueba.');
        }

        return redirect()->route('processes.index')
            ->with($result['success'] ? 'test_process_success' : 'test_process_error', $result['message']);
    }

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
