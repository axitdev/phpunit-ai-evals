<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A non-seekable body that can be read once, like a socket or generator-backed stream.
 */
class OneShotStream implements StreamInterface
{
    private bool $read = false;

    public function __construct(private readonly string $contents) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getContents(): string
    {
        if ($this->read) {
            return '';
        }
        $this->read = true;

        return $this->contents;
    }

    public function read(int $length): string
    {
        return $this->getContents();
    }

    public function eof(): bool
    {
        return $this->read;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
