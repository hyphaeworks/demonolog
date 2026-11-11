<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class RecordParser
{
    public function __construct(
        private Format $format,
    ) {
    }

    public function parse(RawRecord $record): Entry
    {
        try {
            $entry = $this->format->parse($record);
        } catch (InvalidArgumentException $rejected) {
            $entry = Entry::unparsed($record->offset, $record->text, new Warning(WarningCode::Invalid, $rejected->getMessage()));
        }

        return $record->truncated ? $entry->withWarning(new Warning(WarningCode::Truncated, 'A line was longer than 1 MiB, so its end is missing')) : $entry;
    }

    public function header(RawRecord $record): Header
    {
        if (!$this->format instanceof ReadsHeaders) {
            $entry = $this->parse($record);

            return new Header($entry->level, $entry->channel);
        }
        try {
            return $this->format->header($record);
        } catch (InvalidArgumentException) {
            return new Header(null, null);
        }
    }
}
