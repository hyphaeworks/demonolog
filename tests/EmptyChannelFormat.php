<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Reading\RawRecord;

final readonly class EmptyChannelFormat implements Format
{
    public function detect(string $sample): bool
    {
        return true;
    }

    public function startsRecord(string $line): bool
    {
        return true;
    }

    public function parse(RawRecord $record): Entry
    {
        return new Entry($record->offset, $record->text, null, $record->text === 'empty channel' ? '' : 'app', null, '', $record->text);
    }
}
