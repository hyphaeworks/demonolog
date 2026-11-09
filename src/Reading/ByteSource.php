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
    ) {
    }

    public static function open(string $path): self
    {
        $stream = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($stream === false) {
            throw new RuntimeException(sprintf('Cannot read %s', $path));
        }

        return new self($stream);
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
    public function chunks(int $fromOffset = 0, int $chunkSize = 65536): Generator
    {
        fseek($this->stream, $fromOffset);
        while (($chunk = fread($this->stream, $chunkSize)) !== false && $chunk !== '') {
            yield $chunk;
        }
    }
}
