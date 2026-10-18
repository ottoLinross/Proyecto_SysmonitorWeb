<?php

namespace App\Services\Storage;

class MountService
{
    public function __construct(private FindmntRunner $runner, private FstabReader $fstab) {}

    public function active(): array
    {
        try {
            $data = json_decode($this->runner->run(), false, 64, JSON_THROW_ON_ERROR);
            if (! $data instanceof \stdClass || ! isset($data->filesystems) || ! is_array($data->filesystems)) {
                throw new \UnexpectedValueException;
            }
            $mounts = [];
            foreach ($data->filesystems as $entry) {
                if (! $entry instanceof \stdClass) {
                    throw new \UnexpectedValueException;
                }
                $row = [];
                foreach (['source', 'target', 'fstype'] as $field) {
                    if (! isset($entry->$field) || ! is_string($entry->$field) || $entry->$field === '') {
                        throw new \UnexpectedValueException;
                    }
                    $row[$field] = $entry->$field;
                }
                $mounts[] = $row;
            }

            return ['available' => true, 'status' => 'Disponible', 'mounts' => $mounts];
        } catch (\Throwable $exception) {
            return ['available' => false, 'status' => StorageSourceStatus::failure($exception), 'mounts' => []];
        }
    }

    public function configured(): array
    {
        try {
            $mounts = [];
            foreach (preg_split('/\R/', $this->fstab->read()) as $line) {
                if (! preg_match('//u', $line) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line)) {
                    continue;
                }
                $line = trim(explode('#', $line, 2)[0]);
                if ($line === '') {
                    continue;
                }
                $fields = preg_split('/\s+/', $line);
                $count = count($fields);
                if ($count < 4 || $count > 6 || ($count >= 5 && ! ctype_digit($fields[4])) || ($count === 6 && ! ctype_digit($fields[5]))) {
                    continue;
                }
                $decode = fn (string $value): string => strtr($value, ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
                $mounts[] = [
                    'source' => $decode($fields[0]),
                    'target' => $decode($fields[1]),
                    'fstype' => $decode($fields[2]),
                    'options' => $decode($fields[3]),
                ];
            }

            return ['available' => true, 'status' => 'Disponible', 'mounts' => $mounts];
        } catch (\Throwable $exception) {
            return ['available' => false, 'status' => StorageSourceStatus::failure($exception), 'mounts' => []];
        }
    }
}
