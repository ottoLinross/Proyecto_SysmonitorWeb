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
                $entries[] = [
                    'name' => $name,
                    'type' => $link ? 'Enlace simbólico' : ($isDirectory ? 'Directorio' : 'Archivo'),
                    'path' => $isDirectory && $this->validRelativePath($target) ? $target : null,
                ];
            }
            usort($entries, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
            $parent = $relative === '' ? null : dirname($relative);

            return ['available' => true, 'path' => $relative, 'parent' => $parent === '.' ? '' : $parent, 'entries' => $entries, 'message' => null];
        } catch (\Throwable) {
            return $failure;
        }
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
