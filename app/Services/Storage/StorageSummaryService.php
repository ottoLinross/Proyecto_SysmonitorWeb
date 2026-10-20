<?php

namespace App\Services\Storage;

class StorageSummaryService
{
    public function summarize(array $sources): array
    {
        $disks = [];
        $partitions = [];
        $walk = function (array $devices) use (&$walk, &$disks, &$partitions): void {
            foreach ($devices as $device) {
                // A device may appear beneath more than one parent (e.g. RAID).
                $identity = $device['path'] ?: $device['name'];
                if ($identity === null || $identity === '') {
                    $identity = 'unknown:'.(count($disks) + count($partitions));
                }
                if ($device['type'] === 'disk') {
                    $disks[$identity] = true;
                } elseif ($device['type'] === 'part') {
                    $partitions[$identity] = true;
                }
                $walk($device['children']);
            }
        };
        $walk($sources['devices']['devices']);
        $mountpoints = array_unique(array_column($sources['mounts']['mounts'], 'target'));
        $count = fn (string $source, int $value): ?int => $sources[$source]['available'] ? $value : null;

        return [
            'disks' => $count('devices', count($disks)),
            'partitions' => $count('devices', count($partitions)),
            'mounts' => $count('mounts', count($mountpoints)),
            'users' => $count('users', count($sources['users']['users'])),
            'sessions' => $count('sessions', count($sources['sessions']['sessions'])),
            'sources' => array_map(fn (array $source): string => $source['status'] ?? ($source['available'] ? 'Disponible' : 'No disponible'), $sources),
        ];
    }
}
