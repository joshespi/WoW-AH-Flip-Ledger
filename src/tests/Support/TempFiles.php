<?php

namespace Tests\Support;

/** Temp files that are deleted when the test ends. */
trait TempFiles
{
    protected function tempFile(string $contents = '', string $prefix = 'wfl-test-'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $contents);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return $path;
    }
}
