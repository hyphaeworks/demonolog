<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Countable;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\RecordParser;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use Hyphaeworks\Demonolog\Reading\RecordFramer;
use RuntimeException;

final class EntryIndex implements Countable
{
    // A record holds at most 1 MiB of text plus one carriage return per line. Bytes skipped from a cut line are not searched.
    private const MAXIMUM_LENGTH = 2 * 1048576;
    private const FINGERPRINT_BYTES = 64;

    private readonly RecordFramer $framer;
    private readonly RecordParser $parser;
    private IndexedFile $file;
    private ?FilteredRows $filtered = null;

    public function __construct(
        private ByteSource $source,
        Format $format,
    ) {
        $this->framer = new RecordFramer($format->startsRecord(...));
        $this->parser = new RecordParser($format);
        $this->file = new IndexedFile();
    }

    public function __clone()
    {
        $this->file = clone $this->file;
    }

    public function count(): int
    {
        return ($this->filtered ?? $this->file)->count();
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->file->channels();
    }

    public function offsetAt(int $position): int
    {
        return ($this->filtered ?? $this->file)->at($position)['offset'];
    }

    public function entryAt(int $position): ?Entry
    {
        $offset = $this->offsetAt($position);
        foreach ($this->framer->records($this->source->chunks($offset), $offset) as $record) {
            return $this->parser->parse($record);
        }

        return null;
    }

    /**
     * Reads up to the given number of bytes. A replaced or rewritten file is read again from the start.
     * The last record read may go on after the limit, so it is read again on the next call.
     * So is the last record of the file, which may still be being written or get lines without a header appended.
     */
    public function grow(int $maximumBytes = PHP_INT_MAX): Growth
    {
        $restarted = $this->reopenedReplacedFile() || $this->fingerprintAt($this->file->resumeAt()) !== $this->file->fingerprint();
        if ($restarted) {
            $this->file = new IndexedFile();
            $this->filtered = $this->filtered?->emptied();
        }
        $reopenedAt = $this->file->reopenLastRecord();
        if ($reopenedAt !== null) {
            $this->filtered?->removeLastIfAt($reopenedAt);
        }
        $resumeAt = $this->file->resumeAt();
        $size = $this->source->size();
        for ($window = max(1, $maximumBytes);; $window *= 2) {
            $atEnd = $window >= $size - $resumeAt;
            $until = $atEnd ? $size : $resumeAt + $window;
            $records = $this->framer->records($this->source->chunks($resumeAt, toOffset: $until), $resumeAt, holdPartialTail: !$atEnd);

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

                return $this->stopAt($records->getReturn(), $size, $restarted, openRecordAt: $last?->offset);
            }
            if ($last !== null && $last->offset > $resumeAt) {
                return $this->stopAt($last->offset, $size, $restarted, openRecordAt: null);
            }
        }
    }

    /**
     * The filter replaces the one this index may have, and stays when the filtered index grows.
     */
    public function filter(Filter $filter): self
    {
        $filtered = clone $this;
        $rows = new FilteredRows($filter);
        for ($position = 0, $end = $this->file->count(); $position < $end; ++$position) {
            $row = $this->file->at($position);
            $rows->offer($row, $this->file->channelName($row['channel']), $this->source);
        }
        $filtered->filtered = $rows;

        return $filtered;
    }

    private function reopenedReplacedFile(): bool
    {
        if ($this->source->path === null || !$this->source->isReplaced()) {
            return false;
        }
        try {
            $this->source = ByteSource::open($this->source->path);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function fingerprintAt(int $offset): string
    {
        $start = max(0, $offset - self::FINGERPRINT_BYTES);

        return $offset === 0 ? '' : $this->source->read($start, $offset - $start);
    }

    private function add(RawRecord $record, int $end): void
    {
        $header = $this->parser->header($record);
        $row = $this->file->add($record->offset, min($end - $record->offset, self::MAXIMUM_LENGTH), $header->level->value ?? 0, $header->channel);
        $this->filtered?->offer($row, $this->file->channelName($row['channel']), $this->source);
    }

    private function stopAt(int $offset, int $size, bool $restarted, ?int $openRecordAt): Growth
    {
        $this->file->stopAt($offset, $this->fingerprintAt($offset), $openRecordAt);

        return new Growth(restarted: $restarted, indexedBytes: $offset, fileBytes: $size);
    }
}
