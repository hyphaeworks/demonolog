<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Countable;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\ReadsHeaders;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use Hyphaeworks\Demonolog\Reading\RecordFramer;
use OutOfRangeException;

final class EntryIndex implements Countable
{
    // Offset, length, level and channel id. 16 bytes per entry (count() shifts by 4), so millions of entries fit.
    private const LAYOUT = 'JNnn';
    private const UNPACK = 'Joffset/Nlength/nlevel/nchannel';
    private const ENTRY_BYTES = 16;
    // Channel ids have 16 bits. Channels beyond that share this id, which no channel filter matches.
    private const OVERFLOW_CHANNEL = 0xFFFF;
    // A record holds at most 1 MiB of text plus one carriage return per line. Bytes skipped from a cut line are not searched.
    private const MAXIMUM_LENGTH = 2 * 1048576;

    private readonly RecordFramer $framer;
    private string $all = '';
    // The entries that pass the filter, or null when the index is not filtered and they are all of them.
    private ?string $matching = null;
    private int $resumeAt = 0;
    private ?int $lastRecordAt = null;
    private Filter $filter;

    /**
     * @var array<string, int>
     */
    private array $channelIds = [];

    /**
     * @var array<string, true>
     */
    private array $wantedChannels = [];

    public function __construct(
        private readonly ByteSource $source,
        private readonly Format $format,
    ) {
        $this->framer = new RecordFramer($format->startsRecord(...));
        $this->filter = new Filter();
    }

    public function count(): int
    {
        return strlen($this->matching ?? $this->all) >> 4;
    }

    public function offsetAt(int $position): int
    {
        if ($position < 0 || $position >= $this->count()) {
            throw new OutOfRangeException(sprintf('Position %d is outside the index of %d entries', $position, $this->count()));
        }

        return $this->unpacked($this->matching ?? $this->all, $position * self::ENTRY_BYTES)['offset'];
    }

    /**
     * Reads up to the given number of bytes and returns whether it reached the end of the file.
     * The last record read may go on after the limit, so it is read again on the next call.
     * So is the last record of the file, which may still be being written or get lines without a header appended.
     */
    public function grow(int $maximumBytes = PHP_INT_MAX): bool
    {
        $size = $this->source->size();
        if ($size < $this->resumeAt) {
            $this->all = '';
            $this->matching = $this->matching === null ? null : '';
            $this->resumeAt = 0;
            $this->lastRecordAt = null;
        }
        if ($this->lastRecordAt !== null) {
            $this->all = substr($this->all, 0, -self::ENTRY_BYTES);
            if ($this->matching !== null && $this->count() > 0 && $this->offsetAt($this->count() - 1) === $this->lastRecordAt) {
                $this->matching = substr($this->matching, 0, -self::ENTRY_BYTES);
            }
            $this->resumeAt = $this->lastRecordAt;
            $this->lastRecordAt = null;
        }
        for ($window = max(1, $maximumBytes);; $window *= 2) {
            $atEnd = $window >= $size - $this->resumeAt;
            $until = $atEnd ? $size : $this->resumeAt + $window;
            $records = $this->framer->records($this->source->chunks($this->resumeAt, toOffset: $until), $this->resumeAt, holdPartialTail: !$atEnd);

            $last = null;
            foreach ($records as $record) {
                if ($last !== null) {
                    $this->add($last, $record->offset);
                }
                $last = $record;
            }

            if ($atEnd) {
                if ($last !== null) {
                    $this->add($last, $records->getReturn());
                    $this->lastRecordAt = $last->offset;
                }
                $this->resumeAt = $records->getReturn();

                return true;
            }
            if ($last !== null && $last->offset > $this->resumeAt) {
                $this->resumeAt = $last->offset;

                return false;
            }
        }
    }

    /**
     * The filter replaces the one this index may have, and stays when the filtered index grows.
     */
    public function filter(Filter $filter): self
    {
        $filtered = clone $this;
        $filtered->filter = $filter;
        $filtered->wantedChannels = array_fill_keys($filter->channels, true);
        $filtered->matching = '';
        $channelNames = array_flip($this->channelIds);
        for ($at = 0, $end = strlen($this->all); $at < $end; $at += self::ENTRY_BYTES) {
            $entry = $this->unpacked($this->all, $at);
            if ($filtered->matches($entry['offset'], $entry['length'], $entry['level'], $channelNames[$entry['channel']] ?? null)) {
                $filtered->matching .= substr($this->all, $at, self::ENTRY_BYTES);
            }
        }

        return $filtered;
    }

    private function add(RawRecord $record, int $end): void
    {
        $header = $this->format instanceof ReadsHeaders ? $this->format->header($record) : $this->format->parse($record);
        $level = $header->level->value ?? 0;
        $length = min($end - $record->offset, self::MAXIMUM_LENGTH);
        $channelId = $this->channelIds[$header->channel] ?? self::OVERFLOW_CHANNEL;
        if ($channelId === self::OVERFLOW_CHANNEL && count($this->channelIds) < self::OVERFLOW_CHANNEL) {
            $channelId = $this->channelIds[$header->channel] = count($this->channelIds);
        }
        $entry = pack(self::LAYOUT, $record->offset, $length, $level, $channelId);
        $this->all .= $entry;
        if ($this->matching !== null && $this->matches($record->offset, $length, $level, $header->channel)) {
            $this->matching .= $entry;
        }
    }

    private function matches(int $offset, int $length, int $level, ?string $channel): bool
    {
        return $level >= ($this->filter->minimumLevel->value ?? 0)
            && ($this->wantedChannels === [] || ($channel !== null && isset($this->wantedChannels[$channel])))
            && ($this->filter->text === '' || stripos($this->source->read($offset, $length), $this->filter->text) !== false);
    }

    /**
     * @return array{offset: int, length: int, level: int, channel: int}
     */
    private function unpacked(string $entries, int $at): array
    {
        /**
         * @var array{offset: int, length: int, level: int, channel: int} $entry
         */
        $entry = unpack(self::UNPACK, $entries, $at);

        return $entry;
    }
}
