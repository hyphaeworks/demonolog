<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

/**
 * @internal
 */
final class OpenRecord
{
    /**
     * @var list<string>
     */
    private array $lines;
    private int $bytes;

    public function __construct(
        public readonly int $offset,
        string $firstLine,
    ) {
        $this->lines = [$firstLine];
        $this->bytes = strlen($firstLine);
    }

    public function append(string $line): void
    {
        $this->lines[] = $line;
        $this->bytes += 1 + strlen($line);
    }

    public function bytesWith(string $line): int
    {
        return $this->bytes + 1 + strlen($line);
    }

    public function toRecord(): RawRecord
    {
        return new RawRecord($this->offset, implode("\n", $this->lines));
    }
}
