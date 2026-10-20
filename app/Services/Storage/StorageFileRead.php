<?php

namespace App\Services\Storage;

class StorageFileRead
{
    public const MAX_BYTES = 1048576;

    public static function read(string $path): string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Storage source unavailable');
        }
        try {
            $contents = stream_get_contents($stream, self::MAX_BYTES + 1);
            if ($contents === false || strlen($contents) > self::MAX_BYTES || ! feof($stream)) {
                throw new \RuntimeException('Storage source read failed');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }
}
