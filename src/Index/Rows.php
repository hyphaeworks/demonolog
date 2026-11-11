<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Countable;
use OutOfRangeException;

/**
 * @internal
 */
final class Rows implements Countable
{
    // Offset, length, level and channel id. 16 bytes per row (count() shifts by 4), so millions of rows fit.
    private const LAYOUT = 'JNnn';
    private const UNPACK = 'Joffset/Nlength/nlevel/nchannel';
    private const ROW_BYTES = 16;

    private string $packed = '';

    public function count(): int
    {
        return strlen($this->packed) >> 4;
    }

    /**
     * @return array{offset: int, length: int, level: int, channel: int}
     */
    public function at(int $position): array
    {
        if ($position < 0 || $position >= $this->count()) {
            throw new OutOfRangeException(sprintf('Position %d is outside the index of %d entries', $position, $this->count()));
        }

        /**
         * @var array{offset: int, length: int, level: int, channel: int} $row
         */
        $row = unpack(self::UNPACK, $this->packed, $position * self::ROW_BYTES);

        return $row;
    }

    /**
     * @param array{offset: int, length: int, level: int, channel: int} $row
     */
    public function add(array $row): void
    {
        $this->packed .= pack(self::LAYOUT, $row['offset'], $row['length'], $row['level'], $row['channel']);
    }

    public function removeLastIfAt(int $offset): void
    {
        if ($this->count() > 0 && $this->at($this->count() - 1)['offset'] === $offset) {
            $this->packed = substr($this->packed, 0, -self::ROW_BYTES);
        }
    }
}
