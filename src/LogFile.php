<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog;

use Generator;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Following\Follower;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Index\EntryIndex;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\RecordFramer;

final readonly class LogFile
{
    private const TAIL_CHUNK_BYTES = 65536;
    private const LINE_PREFIX_BYTES = 4096;

    private RecordFramer $framer;

    public function __construct(
        private ByteSource $source,
        private Format $format,
    ) {
        $this->framer = new RecordFramer($format->startsRecord(...));
    }

    /**
     * @return Generator<int, Entry>
     */
    public function entries(int $fromOffset = 0): Generator
    {
        foreach ($this->framer->records($this->source->chunks($fromOffset), $fromOffset) as $record) {
            $entry = $this->format->parse($record);
            yield $record->truncated ? $entry->withWarning(new Warning(WarningCode::Truncated, 'A line was longer than 1 MiB, so its end is missing')) : $entry;
        }
    }

    public function entryAt(int $offset): ?Entry
    {
        foreach ($this->entries($offset) as $entry) {
            return $entry;
        }

        return null;
    }

    /**
     * @param positive-int $count
     *
     * @return list<Entry>
     */
    public function tail(int $count): array
    {
        return array_slice(iterator_to_array($this->entries($this->startOfLast($count)), false), -$count);
    }

    public function index(): EntryIndex
    {
        return new EntryIndex($this->source, $this->format);
    }

    public function follow(?int $fromOffset = null): Follower
    {
        return new Follower($this->source, $this->format, $fromOffset ?? $this->source->size());
    }

    /**
     * Only the start of a line decides whether it starts a record, so a long line keeps just its first bytes.
     */
    private function startOfLast(int $count): int
    {
        $found = 0;
        $carry = '';
        $position = $this->source->size();
        while ($position > 0) {
            $start = max(0, $position - self::TAIL_CHUNK_BYTES);
            $lines = explode("\n", $this->source->read($start, $position - $start) . $carry);
            $position = $start;
            $lineOffset = $start;
            $carry = '';
            if ($start > 0) {
                $partial = array_shift($lines);
                $lineOffset += strlen($partial) + 1;
                $carry = substr($partial, 0, self::LINE_PREFIX_BYTES);
            }

            $startsInChunk = [];
            foreach ($lines as $line) {
                if ($this->format->startsRecord($line)) {
                    $startsInChunk[] = $lineOffset;
                }
                $lineOffset += strlen($line) + 1;
            }
            $found += count($startsInChunk);
            if ($found >= $count) {
                return $startsInChunk[$found - $count];
            }
        }

        return 0;
    }
}
