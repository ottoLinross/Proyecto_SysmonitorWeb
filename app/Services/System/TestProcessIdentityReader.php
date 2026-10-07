<?php

namespace App\Services\System;

use RuntimeException;

class TestProcessIdentityReader
{
    /** @return array{pid: int, owner_uid: int, start_time_ticks: int} */
    public function read(int $pid): array
    {
        if ($pid <= 0) {
            throw new RuntimeException('No se pudo verificar el proceso de prueba.');
        }

        $phpUid = $this->parseUid($this->readFile('/proc/self/status'));

        // nohup puede tardar brevemente en sustituirse por sleep después del fork.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $before = $this->parseStat($this->readFile('/proc/'.$pid.'/stat'), $pid);
            $uid = $this->parseUid($this->readFile('/proc/'.$pid.'/status'));
            $command = $this->readFile('/proc/'.$pid.'/cmdline');
            $after = $this->parseStat($this->readFile('/proc/'.$pid.'/stat'), $pid);

            if ($phpUid !== null && $uid === $phpUid && $before !== null && $before === $after
                && $command === "/usr/bin/sleep\0"."300\0") {
                return ['pid' => $pid, 'owner_uid' => $uid, 'start_time_ticks' => $before];
            }

            if ($attempt < 19) {
                usleep(10000);
            }
        }

        throw new RuntimeException('No se pudo verificar el proceso de prueba.');
    }

    protected function readFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function parseUid(?string $status): ?int
    {
        if ($status === null || ! preg_match('/^Uid:\s+([0-9]+)\s+[0-9]+\s+[0-9]+\s+[0-9]+\s*$/m', $status, $matches)) {
            return null;
        }

        $uid = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $uid === false ? null : $uid;
    }

    private function parseStat(?string $stat, int $pid): ?int
    {
        if ($stat === null || ! preg_match('/^([1-9][0-9]*) \(/', $stat, $matches)
            || (int) $matches[1] !== $pid || ($end = strrpos($stat, ')')) === false) {
            return null;
        }

        // comm (campo 2) puede contener espacios y paréntesis; los campos empiezan tras su último cierre.
        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));

        if ($fields === false || count($fields) < 20 || ! in_array($fields[0], ['R', 'S', 'D', 'T', 't', 'I'], true)) {
            return null;
        }

        // fields[0] es el campo 3; fields[19] es starttime, campo 22.
        $ticks = filter_var($fields[19], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $ticks === false ? null : $ticks;
    }
}
