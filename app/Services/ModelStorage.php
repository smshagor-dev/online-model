<?php

namespace App\Services;

class ModelStorage
{
    public function used(string $root): int
    {
        if (! is_dir($root)) {
            return 0;
        }
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }
}
