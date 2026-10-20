<?php

namespace App\Services\Storage;

use UnexpectedValueException;

class FilesystemUsageService
{
    public function __construct(private DfRunner $runner) {}

    /** @return array{available: bool, filesystems: array} */
    public function usage(): array
    {
        try {
            $lines = preg_split('/\r?\n/', trim($this->runner->run()));
            $header = array_shift($lines);
            if (! preg_match('/^Filesystem\s+Size\s+Used\s+Avail\s+Use%\s+Mounted on$/', $header)) {
                throw new UnexpectedValueException;
            }
            $filesystems = [];
            foreach ($lines as $line) {
                // Anchor numeric columns; keep spaces within filesystem and mountpoint.
                if (! preg_match('/^(.+?)\s+([0-9]+(?:\.[0-9]+)?[KMGTPEZYRQ]?)\s+([0-9]+(?:\.[0-9]+)?[KMGTPEZYRQ]?)\s+([0-9]+(?:\.[0-9]+)?[KMGTPEZYRQ]?)\s+([0-9]+%)\s+(\/.*)$/D', $line, $matches)) {
                    throw new UnexpectedValueException;
                }
                $percentage = ltrim(substr($matches[5], 0, -1), '0');
                if (strlen($percentage) > 3 || (int) $percentage > 100) {
                    throw new UnexpectedValueException;
                }
                $matches[5] = ((int) $percentage).'%';
                $filesystems[] = array_combine(
                    ['filesystem', 'size', 'used', 'available', 'use_percent', 'mountpoint'],
                    array_slice($matches, 1),
                );
            }

            return ['available' => true, 'status' => 'Disponible', 'filesystems' => $filesystems];
        } catch (\Throwable $exception) {
            return ['available' => false, 'status' => StorageSourceStatus::failure($exception), 'filesystems' => []];
        }
    }
}
