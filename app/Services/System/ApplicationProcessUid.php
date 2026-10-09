<?php

namespace App\Services\System;

class ApplicationProcessUid
{
    public function effectiveUid(): ?int
    {
        if (function_exists('posix_geteuid')) {
            $uid = posix_geteuid();

            return is_int($uid) && $uid >= 0 ? $uid : null;
        }

        $status = @file_get_contents('/proc/self/status');
        if ($status === false || ! preg_match('/^Uid:\s+[0-9]+\s+([0-9]+)\s+[0-9]+\s+[0-9]+\s*$/m', $status, $matches)) {
            return null;
        }
        $uid = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);

        return $uid === false ? null : $uid;
    }
}
