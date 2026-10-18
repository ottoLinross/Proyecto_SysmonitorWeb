<?php

namespace App\Services\Storage;

class StorageOverviewService
{
    public function __construct(private LsblkRunner $runner) {}

    public function devices(): array
    {
        try {
            $data = json_decode($this->runner->run(), false, 64, JSON_THROW_ON_ERROR);
            if (! $data instanceof \stdClass || ! isset($data->blockdevices) || ! is_array($data->blockdevices)) {
                throw new \UnexpectedValueException;
            }

            return ['available' => true, 'devices' => $this->normalize($data->blockdevices)];
        } catch (\Throwable) {
            return ['available' => false, 'devices' => []];
        }
    }

    private function normalize(array $devices): array
    {
        foreach ($devices as &$device) {
            if (! $device instanceof \stdClass) {
                throw new \UnexpectedValueException;
            }
            $device = (array) $device;
            foreach (['name', 'path', 'type', 'size', 'fstype', 'mountpoints', 'model'] as $field) {
                if (! array_key_exists($field, $device)) {
                    throw new \UnexpectedValueException;
                }
                $value = $device[$field];
                $valid = match ($field) {
                    'size' => $value === null || (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value)),
                    'mountpoints' => $value === null || (is_array($value) && array_is_list($value) && count(array_filter($value, fn ($item) => $item !== null && ! is_string($item))) === 0),
                    default => $value === null || is_string($value),
                };
                if (! $valid) {
                    throw new \UnexpectedValueException;
                }
            }
            $pttype = $device['pttype'] ?? null;
            if ($pttype !== null && ! is_string($pttype)) {
                throw new \UnexpectedValueException;
            }
            $device['partition_table'] = match ($pttype) {
                'gpt' => 'GPT',
                'dos' => 'MBR (dos)',
                null, '' => 'No reportado',
                default => $pttype,
            };
            $children = $device['children'] ?? [];
            if (! is_array($children) || ! array_is_list($children)) {
                throw new \UnexpectedValueException;
            }
            $device['children'] = $this->normalize($children);
        }

        return $devices;
    }

    /**
     * Conserva las secciones del módulo M5.
     *
     * @return array<string, string>
     */
    public function sections(): array
    {
        return [
            'devices' => 'Dispositivos de almacenamiento',
            'usage' => 'Uso del espacio',
            'mounts' => 'Puntos de montaje',
            'users' => 'Usuarios del sistema',
        ];
    }
}
