<?php

namespace App\Services\Storage;

class SystemUsersService
{
    public function __construct(private SystemUsersReader $reader) {}

    public function users(): array
    {
        $uidMin = 1000;
        $fallback = true;
        try {
            foreach (explode("\n", $this->reader->loginDefs()) as $line) {
                if (preg_match('/^\s*UID_MIN\s+([0-9]+)\s*(?:#.*)?$/', $line, $matches)) {
                    $value = $this->identifier($matches[1]);
                    if ($value !== null && $value > 0) {
                        $uidMin = $value;
                        $fallback = false;
                    }
                }
            }
        } catch (\Throwable) {
            // Missing or unreadable login.defs uses the documented default of 1000.
        }

        $users = [];
        $available = true;
        $status = 'Disponible';
        try {
            foreach (explode("\n", $this->reader->passwd()) as $line) {
                $fields = explode(':', rtrim($line, "\r"));
                if (count($fields) !== 7 || $fields[0] === '' || str_starts_with($fields[0], '#')) {
                    continue;
                }
                [$username, , $uid, $gid, , $home, $shell] = $fields;
                $uid = $this->identifier($uid);
                $gid = $this->identifier($gid);
                if ($uid === null || $gid === null) {
                    continue;
                }
                $users[] = [
                    'username' => $username, 'uid' => $uid, 'gid' => $gid,
                    'home' => $home, 'shell' => $shell,
                    'classification' => match (true) {
                        $username === 'root' || $uid === 0 => 'root',
                        $username === 'nobody' || $uid === 65534 => 'Usuario del sistema',
                        $uid >= $uidMin => 'Usuario humano',
                        default => 'Usuario del sistema',
                    },
                ];
            }
            usort($users, fn (array $a, array $b) => ($a['uid'] <=> $b['uid']) ?: strcmp($a['username'], $b['username']) ?: ($a['gid'] <=> $b['gid']) ?: strcmp($a['home'], $b['home']) ?: strcmp($a['shell'], $b['shell']));
        } catch (\Throwable) {
            $available = false;
            $status = 'Error de lectura';
            $users = [];
        }

        return ['available' => $available, 'status' => $status, 'users' => $users, 'uid_min' => $uidMin, 'fallback' => $fallback];
    }

    private function identifier(string $value): ?int
    {
        if (! preg_match('/^[0-9]+$/D', $value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if (strlen($normalized) > strlen((string) PHP_INT_MAX) || (strlen($normalized) === strlen((string) PHP_INT_MAX) && strcmp($normalized, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $normalized;
    }
}
