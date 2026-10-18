<?php

namespace App\Services\Storage;

class FileExplorerService
{
    public function __construct(private ?string $root = null) {}

    public function browse(mixed $path = ''): array
    {
        $failure = ['available' => false, 'path' => '', 'parent' => null, 'entries' => [], 'message' => 'No se pudo acceder al directorio solicitado.'];
        if (! is_string($path) || ! $this->validRelativePath($path)) {
            return $failure;
        }

        try {
            $root = realpath($this->root ?? base_path());
            if ($root === false || ! is_dir($root)) {
                return $failure;
            }
            $directory = realpath($root.DIRECTORY_SEPARATOR.$path);
            if ($directory === false || ! $this->withinRoot($directory, $root) || ! is_dir($directory) || ! is_readable($directory)) {
                return $failure;
            }
            $relative = $directory === $root ? '' : substr($directory, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            $entries = [];
            foreach ($this->directoryEntries($directory) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $entryPath = $directory.DIRECTORY_SEPARATOR.$name;
                $canonical = realpath($entryPath);
                $link = is_link($entryPath);
                $isDirectory = $canonical !== false && $this->withinRoot($canonical, $root) && is_dir($canonical);
                $target = $relative === '' ? $name : $relative.'/'.$name;
                $metadata = $this->metadata($entryPath);
                $entries[] = [
                    'name' => $name,
                    'type' => $link ? 'Enlace simbólico' : ($isDirectory ? 'Directorio' : 'Archivo'),
                    'path' => $isDirectory && $this->validRelativePath($target) ? $target : null,
                    ...$metadata,
                ];
            }
            usort($entries, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
            $parent = $relative === '' ? null : dirname($relative);

            return ['available' => true, 'path' => $relative, 'parent' => $parent === '.' ? '' : $parent, 'entries' => $entries, 'message' => null];
        } catch (\Throwable) {
            return $failure;
        }
    }

    private function metadata(string $path): array
    {
        $unavailable = ['permissions' => '—', 'owner' => '—', 'size' => '—'];
        try {
            $stat = $this->entryStat($path);
            if ($stat === false) {
                return $unavailable;
            }
            $mode = $stat['mode'];
            $permissions = match ($mode & 0170000) {
                0100000 => '-', 0040000 => 'd', 0120000 => 'l',
                0010000 => 'p', 0020000 => 'c', 0060000 => 'b', 0140000 => 's',
                default => '?',
            };
            foreach ([0400 => 'r', 0200 => 'w', 0100 => 'x', 0040 => 'r', 0020 => 'w', 0010 => 'x', 0004 => 'r', 0002 => 'w', 0001 => 'x'] as $bit => $letter) {
                $permissions .= ($mode & $bit) !== 0 ? $letter : '-';
            }
            foreach ([04000 => [3, 's', 'S'], 02000 => [6, 's', 'S'], 01000 => [9, 't', 'T']] as $bit => [$index, $executable, $nonExecutable]) {
                if (($mode & $bit) !== 0) {
                    $permissions[$index] = $permissions[$index] === 'x' ? $executable : $nonExecutable;
                }
            }
            $owner = (string) $stat['uid'];
            try {
                $user = $this->lookupOwner($stat['uid']);
                if (is_array($user) && isset($user['name']) && is_string($user['name']) && $user['name'] !== '') {
                    $owner = $user['name'];
                }
            } catch (\Throwable) {
                // Keep the numeric UID when the account database is unavailable.
            }
            $size = '—';
            if (($mode & 0170000) === 0100000 && $stat['size'] >= 0) {
                $bytes = $stat['size'];
                $size = match (true) {
                    $bytes < 1024 => $bytes.' bytes',
                    $bytes < 1048576 => number_format($bytes / 1024, 2, '.', '').' KiB',
                    default => number_format($bytes / 1048576, 2, '.', '').' MiB',
                };
            }

            return ['permissions' => $permissions, 'owner' => $owner, 'size' => $size];
        } catch (\Throwable) {
            return $unavailable;
        }
    }

    protected function entryStat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    protected function lookupOwner(int $uid): array|false
    {
        return function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
    }

    private function validRelativePath(string $path): bool
    {
        return ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/')
            && ! preg_match('/^[a-zA-Z]:/', $path)
            && ! preg_match('/%(?:2e|2f|5c|00|25)/i', $path)
            && ! in_array('..', explode('/', $path), true);
    }

    private function withinRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    protected function directoryEntries(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('Directory unavailable');
        }

        return $entries;
    }
}
