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

        $phpUid = $this->parseUid($this->readFile('/proc/self/status'), true);

        // nohup puede tardar brevemente en sustituirse por sleep después del fork.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $before = $this->parseStat($this->readFile('/proc/'.$pid.'/stat'), $pid);
            $status = $this->readFile('/proc/'.$pid.'/status');
            $uid = $this->parseUid($status);
            $effectiveUid = $this->parseUid($status, true);
            $command = $this->readFile('/proc/'.$pid.'/cmdline');
            $executable = $this->readExecutable($pid);
            $after = $this->parseStat($this->readFile('/proc/'.$pid.'/stat'), $pid);

            if ($phpUid !== null && $uid === $phpUid && $effectiveUid === $phpUid && $before !== null && $before === $after
                && $command === "/usr/bin/sleep\0"."300\0" && $executable === '/usr/bin/sleep') {
                return ['pid' => $pid, 'owner_uid' => $uid, 'start_time_ticks' => $before];
            }

            if ($attempt < 19) {
                usleep(10000);
            }
        }

        throw new RuntimeException('No se pudo verificar el proceso de prueba.');
    }

    /** @return array{pid: int, owner_uid: int, start_time_ticks: int, nice: int} */
    public function readWithNice(int $pid): array
    {
        $before = $this->read($pid);
        $stat = $this->readFile('/proc/'.$pid.'/stat');
        $fields = $this->statFields($stat, $pid);
        $nice = $fields === null ? false : filter_var($fields[16], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => -20, 'max_range' => 19]]);
        $after = $this->read($pid);

        if ($nice === false || $before !== $after || $this->parseStat($stat, $pid) !== $after['start_time_ticks']) {
            throw new RuntimeException('No se pudo verificar el proceso de prueba.');
        }

        return [...$after, 'nice' => $nice];
    }

    protected function readFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    protected function readExecutable(int $pid): ?string
    {
        $executable = @readlink('/proc/'.$pid.'/exe');

        return $executable === false ? null : $executable;
    }

    public function exists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $path = '/proc/'.$pid;
        clearstatcache(true, $path);

        return @is_dir($path);
    }

    public function hasExited(int $pid, int $startTimeTicks): bool
    {
        if ($pid <= 0 || $startTimeTicks <= 0) {
            return false;
        }
        if (! $this->exists($pid)) {
            return true;
        }

        $stat = $this->readFile('/proc/'.$pid.'/stat');
        $end = $stat === null ? false : strrpos($stat, ')');
        $state = $end === false ? '' : substr(ltrim(substr($stat, $end + 1)), 0, 1);

        return in_array($state, ['Z', 'X', 'x'], true)
            && $this->parseStat($stat, $pid, true) === $startTimeTicks;
    }

    private function parseUid(?string $status, bool $effective = false): ?int
    {
        if ($status === null || ! preg_match('/^Uid:\s+([0-9]+)\s+([0-9]+)\s+[0-9]+\s+[0-9]+\s*$/m', $status, $matches)) {
            return null;
        }

        $uid = filter_var($matches[$effective ? 2 : 1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);

        return $uid === false ? null : $uid;
    }

    private function parseStat(?string $stat, int $pid, bool $allowExited = false): ?int
    {
        $fields = $this->statFields($stat, $pid, $allowExited);
        if ($fields === null) {
            return null;
        }

        // fields[0] es el campo 3; fields[19] es starttime, campo 22.
        $ticks = filter_var($fields[19], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $ticks === false ? null : $ticks;
    }

    private function statFields(?string $stat, int $pid, bool $allowExited = false): ?array
    {
        if ($stat === null || ! preg_match('/^([1-9][0-9]*) \(/', $stat, $matches)
            || (int) $matches[1] !== $pid || ($end = strrpos($stat, ')')) === false) {
            return null;
        }

        // comm (campo 2) puede contener espacios y paréntesis; los campos empiezan tras su último cierre.
        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));

        $states = $allowExited ? ['R', 'S', 'D', 'T', 't', 'I', 'Z', 'X', 'x'] : ['R', 'S', 'D', 'T', 't', 'I'];
        if ($fields === false || count($fields) < 20 || ! in_array($fields[0], $states, true)) {
            return null;
        }

        return $fields;
    }
}
