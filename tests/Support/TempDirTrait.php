<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

trait TempDirTrait
{
    private string $tmp = '';

    #[Before]
    protected function makeTmp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ai-evals-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o775, true);
    }

    #[After]
    protected function removeTmp(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->tmp);
    }
}
