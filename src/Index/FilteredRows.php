<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Countable;
use Hyphaeworks\Demonolog\Reading\ByteSource;

/**
 * @internal
 */
final class FilteredRows implements Countable
{
    private readonly Rows $rows;

    /**
     * @var array<string, true>
     */
    private readonly array $wantedChannels;

    public function __construct(
        private readonly Filter $filter,
    ) {
        $this->rows = new Rows();
        $this->wantedChannels = array_fill_keys($filter->channels, true);
    }

    public function emptied(): self
    {
        return new self($this->filter);
    }

    public function count(): int
    {
        return $this->rows->count();
    }

    /**
     * @return array{offset: int, length: int, level: int, channel: int}
     */
    public function at(int $position): array
    {
        return $this->rows->at($position);
    }

    /**
     * @param array{offset: int, length: int, level: int, channel: int} $row
     */
    public function offer(array $row, ?string $channel, ByteSource $source): void
    {
        if ($this->matches($row, $channel, $source)) {
            $this->rows->add($row);
        }
    }

    public function removeLastIfAt(int $offset): void
    {
        $this->rows->removeLastIfAt($offset);
    }

    /**
     * @param array{offset: int, length: int, level: int, channel: int} $row
     */
    private function matches(array $row, ?string $channel, ByteSource $source): bool
    {
        return $row['level'] >= ($this->filter->minimumLevel->value ?? 0)
            && ($this->wantedChannels === [] || ($channel !== null && isset($this->wantedChannels[$channel])))
            && ($this->filter->text === '' || stripos($source->read($row['offset'], $row['length']), $this->filter->text) !== false);
    }
}
