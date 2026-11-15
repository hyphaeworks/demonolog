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
use InvalidArgumentException;
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

    /**
     * The position of the entry at the offset, or of the nearest one before it. Null when no entry starts at or before it.
     */
    public function positionOf(int $offset): ?int
    {
        return ($this->filtered ?? $this->file)->positionOf($offset);
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
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException(sprintf('Growing by %d bytes reads nothing', $maximumBytes));
        }
        $restarted = $this->reopenedReplacedFile() || $this->fingerprintAt($this->file->resumeAt()) !== $this->file->fingerprint();
        if ($restarted) {
            $this->file = new IndexedFile();
            $this->filtered = $this->filtered?->emptied();
        }
        $size = $this->source->size();
        $uncheckedAt = $this->filtered?->check($this->file, $this->source, $maximumBytes);
        if ($uncheckedAt !== null) {
            return new Growth(restarted: $restarted, indexedBytes: $uncheckedAt, fileBytes: $size);
        }
        $reopenedAt = $this->file->reopenLastRecord();
        if ($reopenedAt !== null) {
            $this->filtered?->reopen($reopenedAt, $this->file->count());
        }
        $resumeAt = $this->file->resumeAt();
        for ($window = $maximumBytes;; $window *= 2) {
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
     * It checks the entries already indexed as the filtered index grows, so a large index is filtered in steps.
     */
    public function filter(Filter $filter): self
    {
        $filtered = clone $this;
        $filtered->filtered = new FilteredRows($filter);

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
        $this->file->add($record->offset, min($end - $record->offset, self::MAXIMUM_LENGTH), $header->level->value ?? 0, $header->channel);
        $this->filtered?->check($this->file, $this->source, PHP_INT_MAX);
    }

    private function stopAt(int $offset, int $size, bool $restarted, ?int $openRecordAt): Growth
    {
        $this->file->stopAt($offset, $this->fingerprintAt($offset), $openRecordAt);

        return new Growth(restarted: $restarted, indexedBytes: $offset, fileBytes: $size);
    }
}
