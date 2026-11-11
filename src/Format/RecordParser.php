<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Reading\RawRecord;

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
        $entry = $this->format->parse($record);

        return $record->truncated ? $entry->withWarning(new Warning(WarningCode::Truncated, 'A line was longer than 1 MiB, so its end is missing')) : $entry;
    }
}
