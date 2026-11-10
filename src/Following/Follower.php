<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Following;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\RecordFramer;
use RuntimeException;

final class Follower
{
    // The bytes before the offset are read again on each poll. If they changed, the file was rewritten.
    private const FINGERPRINT_BYTES = 64;

    private readonly RecordFramer $framer;
    private string $fingerprint;

    public function __construct(
        private ByteSource $source,
        private readonly Format $format,
        private int $offset,
    ) {
        $this->framer = new RecordFramer($format->startsRecord(...));
        $this->fingerprint = $this->fingerprintAt($offset);
    }

    public function poll(): Update
    {
        $fromPreviousFile = [];
        $restarted = false;
        if ($this->source->isReplaced() && $this->source->path !== null) {
            try {
                $next = ByteSource::open($this->source->path);
            } catch (RuntimeException) {
                return new Update([], false);
            }
            $fromPreviousFile = $this->read(holdPartialTail: false);
            $this->source = $next;
            $this->offset = 0;
            $restarted = true;
        } elseif ($this->source->size() < $this->offset || $this->fingerprintAt($this->offset) !== $this->fingerprint) {
            $this->offset = 0;
            $restarted = true;
        }

        return new Update($this->read(holdPartialTail: true), $restarted, $fromPreviousFile);
    }

    /**
     * @return list<Entry>
     */
    private function read(bool $holdPartialTail): array
    {
        $records = $this->framer->records($this->source->chunks($this->offset), $this->offset, $holdPartialTail);
        $entries = [];
        foreach ($records as $record) {
            $entry = $this->format->parse($record);
            $entries[] = $record->truncated ? $entry->withWarning(new Warning(WarningCode::Truncated, 'A line was longer than 1 MiB, so its end is missing')) : $entry;
        }
        $this->offset = $records->getReturn();
        $this->fingerprint = $this->fingerprintAt($this->offset);

        return $entries;
    }

    private function fingerprintAt(int $offset): string
    {
        if ($offset === 0) {
            return '';
        }
        $start = max(0, $offset - self::FINGERPRINT_BYTES);

        return $this->source->read($start, $offset - $start);
    }
}
