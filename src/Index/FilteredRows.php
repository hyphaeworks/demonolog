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

    // The rows of the file before this position are checked. Matches are kept in file order, so later rows wait for them.
    private int $checked = 0;

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
     * @return ?int the offset of the first row left unchecked, or null when every row of the file is checked
     */
    public function check(IndexedFile $file, ByteSource $source, int $maximumBytes): ?int
    {
        $bytes = 0;
        while ($this->checked < $file->count()) {
            $row = $file->at($this->checked);
            if ($bytes >= $maximumBytes) {
                return $row['offset'];
            }
            $bytes += $row['length'];
            ++$this->checked;
            if ($this->matches($row, $file->channelName($row['channel']), $source)) {
                $this->rows->add($row);
            }
        }

        return null;
    }

    public function reopen(int $offset, int $rowsInFile): void
    {
        $this->rows->removeLastIfAt($offset);
        $this->checked = min($this->checked, $rowsInFile);
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
