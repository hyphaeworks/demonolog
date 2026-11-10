<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

use Generator;
use RuntimeException;

/**
 * @internal
 */
final readonly class ByteSource
{
    /**
     * @param resource $stream
     */
    private function __construct(
        private mixed $stream,
        public ?string $path = null,
    ) {
    }

    public static function open(string $path): self
    {
        $stream = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($stream === false) {
            throw new RuntimeException(sprintf('Cannot read %s', $path));
        }

        return new self($stream, $path);
    }

    public static function fromString(string $bytes): self
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open a memory stream');
        }
        fwrite($stream, $bytes);

        return new self($stream);
    }

    public function size(): int
    {
        $status = fstat($this->stream);

        return $status === false ? 0 : $status['size'];
    }

    public function isReplaced(): bool
    {
        if ($this->path === null) {
            return false;
        }
        clearstatcache(true, $this->path);
        $file = is_file($this->path) ? stat($this->path) : false;
        $stream = fstat($this->stream);
        if ($file === false || $stream === false) {
            return false;
        }

        return $file['dev'] !== $stream['dev'] || $file['ino'] !== $stream['ino'];
    }

    public function read(int $offset, int $length): string
    {
        fseek($this->stream, $offset);

        return (string)fread($this->stream, max(1, $length));
    }

    /**
     * @param positive-int $chunkSize
     *
     * @return Generator<int, string>
     */
    public function chunks(int $fromOffset = 0, int $chunkSize = 65536, int $toOffset = PHP_INT_MAX): Generator
    {
        $position = $fromOffset;
        while ($position < $toOffset
            && fseek($this->stream, $position) === 0
            && ($chunk = fread($this->stream, max(1, min($chunkSize, $toOffset - $position)))) !== false
            && $chunk !== '') {
            $position += strlen($chunk);
            yield $chunk;
        }
    }
}
