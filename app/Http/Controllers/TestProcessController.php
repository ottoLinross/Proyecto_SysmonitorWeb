<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ManagedProcess;
use App\Services\M6\AuditLogger;
use App\Services\System\ProcessPriorityService;
use App\Services\System\ProcessSignalService;
use App\Services\System\TestProcessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class TestProcessController extends Controller
{
    public function priority(Request $request, ManagedProcess $managedProcess, ProcessPriorityService $service, AuditLogger $auditLogger): RedirectResponse
    {
        $nice = ProcessPriorityService::normalizeNice($request->input('nice'));
        if ($nice === null) {
            return redirect()->route('processes.index')
                ->with('test_process_error', 'El valor nice debe estar entre -20 y 19.');
        }

        try {
            $audit = $auditLogger->begin('process.priority', 'managed_process', (string) $managedProcess->getKey(), null, ['nice' => $nice]);
        } catch (Throwable) {
            return redirect()->route('processes.index')
                ->with('test_process_error', 'No se pudo registrar la operación en la bitácora.');
        }

        try {
            $result = $service->change($managedProcess, $nice);
        } catch (Throwable) {
            return $this->finishFailedOperation($auditLogger, $audit, 'No fue posible cambiar la prioridad del proceso.');
        }

        if (! $this->finishAudit($auditLogger, $audit, (bool) ($result['success'] ?? false), (int) $managedProcess->getKey(), ($result['success'] ?? false) ? ($result['pid'] ?? null) : null)) {
            return $this->auditConfirmationFailure();
        }

        return redirect()->route('processes.index')
            ->with($result['success'] ? 'test_process_success' : 'test_process_error', $result['message']);
    }

    public function signal(Request $request, ManagedProcess $managedProcess, ProcessSignalService $service, AuditLogger $auditLogger): RedirectResponse
    {
        $signal = $request->input('signal');
        if (! is_string($signal) || ! array_key_exists($signal, ProcessSignalService::SIGNALS)) {
            return redirect()->route('processes.index')->with('test_process_error', 'Señal no permitida.');
        }

        try {
            $audit = $auditLogger->begin('process.signal', 'managed_process', (string) $managedProcess->getKey(), null, ['signal' => $signal]);
        } catch (Throwable) {
            return redirect()->route('processes.index')
                ->with('test_process_error', 'No se pudo registrar la operación en la bitácora.');
        }

        try {
            $result = $service->send($managedProcess, $signal);
        } catch (Throwable) {
            return $this->finishFailedOperation($auditLogger, $audit, 'No se pudo completar la acción del proceso de prueba.');
        }

        if (! $this->finishAudit($auditLogger, $audit, (bool) ($result['success'] ?? false), (int) $managedProcess->getKey(), ($result['success'] ?? false) ? ($result['pid'] ?? null) : null)) {
            return $this->auditConfirmationFailure();
        }

        return redirect()->route('processes.index')
            ->with($result['success'] ? 'test_process_success' : 'test_process_error', $result['message']);
    }

    public function store(TestProcessService $service, AuditLogger $auditLogger): RedirectResponse
    {
        try {
            $audit = $auditLogger->begin('process.start', 'test_process');
        } catch (Throwable) {
            return redirect()->route('processes.index')
                ->with('test_process_error', 'No se pudo registrar la operación en la bitácora.');
        }

        try {
            $process = $service->launch();
        } catch (Throwable) {
            return $this->finishFailedOperation($auditLogger, $audit, 'No se pudo crear el proceso de prueba. Inténtalo nuevamente.');
        }

        if (! $this->finishAudit($auditLogger, $audit, true, (string) $process->getKey(), $process->pid)) {
            return $this->auditConfirmationFailure();
        }

        return redirect()->route('processes.index')
            ->with('test_process_success', 'Proceso de prueba creado correctamente. PID: '.$process->pid.'.');
    }

    private function finishFailedOperation(AuditLogger $auditLogger, AuditLog $audit, string $operationError): RedirectResponse
    {
        if (! $this->finishAudit($auditLogger, $audit, false)) {
            return $this->auditConfirmationFailure();
        }

        return redirect()->route('processes.index')->with('test_process_error', $operationError);
    }

    private function finishAudit(AuditLogger $auditLogger, AuditLog $audit, bool $success, ?string $targetId = null, ?int $pid = null): bool
    {
        try {
            $auditLogger->finish($audit, $success ? AuditLog::STATUS_SUCCESS : AuditLog::STATUS_FAILURE,
                $success ? 'completed' : 'operation_failed', $targetId, $pid);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function auditConfirmationFailure(): RedirectResponse
    {
        return redirect()->route('processes.index')
            ->with('test_process_error', 'No se pudo confirmar el resultado de la operación en la bitácora.');
    }
}
