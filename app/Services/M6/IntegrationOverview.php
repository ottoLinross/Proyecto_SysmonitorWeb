<?php

namespace App\Services\M6;

class IntegrationOverview
{
    /**
     * Puntos de entrada existentes para la integración de M6.
     * No consulta métricas ni determina permisos de acceso.
     *
     * @return array<string, array{label: string, route: string}>
     */
    public function modules(): array
    {
        return [
            'M1' => ['label' => 'Procesos', 'route' => 'processes.index'],
            'M2' => ['label' => 'CPU y memoria', 'route' => 'cpu-memory.index'],
            'M3' => ['label' => 'Planificación', 'route' => 'scheduling.index'],
            'M4' => ['label' => 'Interbloqueos', 'route' => 'deadlocks.index'],
            'M5' => ['label' => 'Almacenamiento', 'route' => 'storage.index'],
        ];
    }
}
