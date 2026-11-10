<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Countable;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\ReadsHeaders;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use Hyphaeworks\Demonolog\Reading\RecordFramer;

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
    private string $entries = '';
    private int $resumeAt = 0;

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
        private Filter $filter = new Filter(),
    ) {
        $this->framer = new RecordFramer($format->startsRecord(...));
    }

    public function count(): int
    {
        return strlen($this->entries) >> 4;
    }

    public function offsetAt(int $position): int
    {
        return $this->unpacked($position * self::ENTRY_BYTES)['offset'];
    }

    /**
     * Reads up to the given number of bytes and returns whether it reached the end of the file.
     * The last record read may go on after the limit, so it is read again on the next call.
     */
    public function grow(int $maximumBytes = PHP_INT_MAX): bool
    {
        $size = $this->source->size();
        for ($window = max(1, $maximumBytes);; $window *= 2) {
            $atEnd = $window >= $size - $this->resumeAt;
            $until = $atEnd ? $size : $this->resumeAt + $window;
            $records = $this->framer->records($this->source->chunks($this->resumeAt, toOffset: $until), $this->resumeAt, holdPartialTail: true);

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
     * The filtered index keeps the filter when it grows.
     */
    public function filter(Filter $filter): self
    {
        $filtered = clone $this;
        $filtered->filter = $filter;
        $filtered->wantedChannels = array_fill_keys($filter->channels, true);
        $filtered->entries = '';
        $channelNames = array_flip($this->channelIds);
        for ($at = 0, $end = strlen($this->entries); $at < $end; $at += self::ENTRY_BYTES) {
            $entry = $this->unpacked($at);
            if ($filtered->matches($entry['offset'], $entry['length'], $entry['level'], $channelNames[$entry['channel']] ?? null)) {
                $filtered->entries .= substr($this->entries, $at, self::ENTRY_BYTES);
            }
        }

        return $filtered;
    }

    private function add(RawRecord $record, int $end): void
    {
        $header = $this->format instanceof ReadsHeaders ? $this->format->header($record) : $this->format->parse($record);
        $level = $header->level->value ?? 0;
        $length = min($end - $record->offset, self::MAXIMUM_LENGTH);
        if (!$this->matches($record->offset, $length, $level, $header->channel)) {
            return;
        }
        $channelId = $this->channelIds[$header->channel] ?? self::OVERFLOW_CHANNEL;
        if ($channelId === self::OVERFLOW_CHANNEL && count($this->channelIds) < self::OVERFLOW_CHANNEL) {
            $channelId = $this->channelIds[$header->channel] = count($this->channelIds);
        }
        $this->entries .= pack(self::LAYOUT, $record->offset, $length, $level, $channelId);
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
    private function unpacked(int $at): array
    {
        /**
         * @var array{offset: int, length: int, level: int, channel: int} $entry
         */
        $entry = unpack(self::UNPACK, $this->entries, $at);

        return $entry;
    }
}
