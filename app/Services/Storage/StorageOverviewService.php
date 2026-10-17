<?php

namespace App\Services\Storage;

class StorageOverviewService
{
    /**
     * Define únicamente la presentación inicial; no obtiene datos del sistema.
     *
     * @return array<string, string>
     */
    public function sections(): array
    {
        return [
            'devices' => 'Dispositivos de almacenamiento',
            'usage' => 'Uso del espacio',
            'mounts' => 'Puntos de montaje',
        ];
    }
}
