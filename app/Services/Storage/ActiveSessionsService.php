<?php

namespace App\Services\Storage;

class ActiveSessionsService
{
    public function __construct(private WhoRunner $runner) {}

    public function sessions(): array
    {
        try {
            $sessions = [];
            foreach (preg_split('/\R/', $this->runner->run()) as $line) {
                // GNU who with LC_ALL=C reports YYYY-MM-DD HH:MM and optional (origin).
                if (! preg_match('/^\s*(\S+)\s+(\S+)\s+(\d{4}-\d{2}-\d{2})\s+([0-2]\d:[0-5]\d)\s*(?:\(([^\r\n]*)\))?\s*$/D', $line, $fields)) {
                    continue;
                }
                [$year, $month, $day] = array_map('intval', explode('-', $fields[3]));
                if (! checkdate($month, $day, $year) || (int) substr($fields[4], 0, 2) > 23) {
                    continue;
                }
                $sessions[] = [
                    'username' => $fields[1],
                    'terminal' => $fields[2],
                    'login_at' => $fields[3].' '.$fields[4],
                    'origin' => isset($fields[5]) && trim($fields[5]) !== '' ? trim($fields[5]) : null,
                ];
            }

            return ['available' => true, 'status' => 'Disponible', 'sessions' => $sessions];
        } catch (\Throwable $exception) {
            return ['available' => false, 'status' => StorageSourceStatus::failure($exception), 'sessions' => []];
        }
    }
}
