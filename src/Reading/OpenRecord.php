<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

/**
 * @internal
 */
final class OpenRecord
{
    public readonly int $offset;

    /**
     * @var list<string>
     */
    private array $lines;
    private int $bytes;
    private bool $truncated;

    public function __construct(Line $firstLine)
    {
        $this->offset = $firstLine->offset;
        $this->lines = [$firstLine->text];
        $this->bytes = strlen($firstLine->text);
        $this->truncated = $firstLine->truncated;
    }

    public function append(Line $line): void
    {
        $this->lines[] = $line->text;
        $this->bytes += 1 + strlen($line->text);
        $this->truncated = $this->truncated || $line->truncated;
    }

    public function bytesWith(Line $line): int
    {
        return $this->bytes + 1 + strlen($line->text);
    }

    public function toRecord(): RawRecord
    {
        return new RawRecord($this->offset, implode("\n", $this->lines), $this->truncated);
    }
}
